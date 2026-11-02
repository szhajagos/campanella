<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Capability\Hierarchical;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Model\Blueprint;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ValidationException;
use Campanella\Query\QueryEngine;
use Campanella\Security\Csrf;
use Campanella\Tree\TreeArrangement;
use Campanella\Tree\TreeNode;
use Closure;

/**
 * Arranging a tree by dragging (since 0.4.4): /admin/<blueprint>/arrange, for a Blueprint that
 * is Hierarchical and Weighted (e.g. menu items, categories); with a 'tree_scope' one scope's
 * tree (`?menu=3`). The page shows the tree as nested lists; admin-sortable.js lets each item be
 * dragged by its handle, also under another item or out of it, and each drop is saved by
 * POST /admin/<blueprint>/arrange (`scope`, `tree` = `id:parent` pairs in tree order, the CSRF
 * token), answered with JSON. TreeArrangement checks and does it, all or nothing.
 */
final class TreeArrangePages
{
    public function __construct(
        private readonly TreeArrangement $arrangement,
        private readonly QueryEngine $queries,
        private readonly AccessPolicy $policy,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    public function handle(Request $request, Actor $actor, Blueprint $blueprint, Closure $render): Response
    {
        if (!$this->arrangement->supports($blueprint->name)) {
            throw HttpException::notFound();
        }
        $scopeName = $blueprint->treeScope;
        $scopeId = $scopeName === null ? null : ($request->isPost() ? (int) $request->postString('scope') : $request->queryInt($scopeName, 0));
        $scope = $scopeId === null ? null : $this->scopeObject($actor, $scopeId);
        // Only an object the scope's relation may point to (e.g. a menu).
        $targets = $scopeName === null ? [] : ($blueprint->allRelations()[$scopeName]->targetBlueprints ?? []);
        if ($scope !== null && $targets !== [] && !in_array($scope->blueprint(), $targets, true)) {
            $scope = null;
        }
        if ($scopeName !== null && $scope === null) {
            throw HttpException::notFound();
        }

        return $request->isPost()
            ? $this->save($request, $actor, $blueprint, $scopeId)
            : $this->page($actor, $blueprint, $scopeName, $scope, $render);
    }

    /**
     * Its address, for a list or a scope object's page: null if the Blueprint's trees cannot be
     * arranged.
     */
    public function path(Blueprint $blueprint, ?CampanellaObject $scope = null): ?string
    {
        if (!$this->arrangement->supports($blueprint->name) || ($blueprint->treeScope !== null && $scope === null)) {
            return null;
        }

        return $this->access->path($blueprint->name . '/arrange') . ($scope !== null && $blueprint->treeScope !== null ? '?' . $blueprint->treeScope . '=' . $scope->id() : '');
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function page(Actor $actor, Blueprint $blueprint, ?string $scopeName, ?CampanellaObject $scope, Closure $render): Response
    {
        $roots = $this->arrangement->tree($actor, $blueprint->name, $scope === null ? null : (int) $scope->id());
        $locked = false;
        foreach ($roots === null ? [] : \Campanella\Tree\TreeBuilder::flatten($roots) as $node) {
            $locked = $locked || !$this->policy->allows($actor, Operation::Update, $node->object);
        }
        $back = $this->access->path($blueprint->name) . ($scope !== null && $scopeName !== null ? '?' . $scopeName . '=' . $scope->id() : '');

        return $render('tree_arrange', $blueprint->name, [
            'title' => 'tree.arrange_title',
            'blueprint' => $blueprint,
            'scope' => $scope === null ? null : ['id' => (int) $scope->id(), 'title' => self::titleOf($scope), 'edit' => $this->access->path($scope->blueprint() . '/' . $scope->id())],
            'roots' => $roots === null ? [] : array_map(self::view(...), $roots),
            'too_many' => $roots === null,
            'locked' => $locked,
            'max_depth' => Hierarchical::MAX_DEPTH,
            'back' => $back,
        ]);
    }

    /** POST: the tree as dragged; JSON `{"ok": …}`, the reason of an error flashed. */
    private function save(Request $request, Actor $actor, Blueprint $blueprint, ?int $scope): Response
    {
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return Response::json(['ok' => false], 400);
        }
        $tree = $request->postString('tree');
        if (preg_match('/^\d{1,10}:\d{1,10}(,\d{1,10}:\d{1,10}){0,' . (TreeArrangement::MAX_NODES - 1) . '}$/D', $tree) !== 1) {
            $this->flash->add(Flash::DANGER, 'tree.arrange_invalid');

            return Response::json(['ok' => false], 422);
        }
        $nodes = array_map(static fn (string $pair): array => array_map('intval', explode(':', $pair, 2)), explode(',', $tree));
        try {
            /** @var list<array{0: int, 1: int}> $nodes */
            $this->arrangement->arrange($actor, $blueprint->name, $nodes, $scope);
        } catch (ValidationException $e) {
            $this->flash->add(Flash::DANGER, array_values($e->errors)[0] ?? 'admin.form.invalid');

            return Response::json(['ok' => false], 422);
        } catch (AccessDeniedException) {
            $this->flash->add(Flash::DANGER, 'error.forbidden');

            return Response::json(['ok' => false], 403);
        }

        return Response::json(['ok' => true]);
    }

    /** The scope's object (e.g. the menu), if the actor may see it. */
    private function scopeObject(Actor $actor, int $id): ?CampanellaObject
    {
        if ($id <= 0) {
            return null;
        }
        $found = $this->queries->execute(\Campanella\Query\Query::objects()->where('id', '=', $id)->limit(1), $actor)->items;

        return $found[0] ?? null;
    }

    /** @return array{id: int, title: string, children: list<array<string, mixed>>} */
    private static function view(TreeNode $node): array
    {
        return ['id' => (int) $node->object->id(), 'title' => self::titleOf($node->object), 'children' => array_map(self::view(...), $node->children)];
    }

    private static function titleOf(CampanellaObject $object): string
    {
        $title = $object->hasField('title') ? (string) $object->get('title') : '';

        return $title !== '' ? $title : '#' . $object->id();
    }
}
