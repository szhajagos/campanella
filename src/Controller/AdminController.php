<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Capability\PublishStatus;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintRegistry;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Operator;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\RelationLoader;
use Campanella\View\Presentation;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The admin UI (/admin…). Only users with an admin role may enter
 * (AdminAccess); what they may do with an object is decided by the AccessPolicy.
 *
 * Its templates are always the core ones (@core/admin/…), independent of the
 * public theme, so a broken theme cannot lock anyone out of the admin.
 */
final class AdminController implements Controller
{
    public const int PER_PAGE = 20;

    /** Status filters of the list (for Publishable Blueprints). */
    public const array STATUSES = ['draft', 'published', 'scheduled'];

    public function __construct(
        private readonly AdminAccess $access,
        private readonly QueryEngine $queries,
        private readonly BlueprintRegistry $blueprints,
        private readonly Presentation $presentation,
        private readonly RelationLoader $relations,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        if ($actor->isAnonymous()) {
            // Back to the requested admin page after logging in.
            $target = $request->path . ($request->query === [] ? '' : '?' . http_build_query($request->query));

            return Response::redirect($request->basePath . '/belepes?vissza=' . rawurlencode($target));
        }
        if (!$this->access->allows($actor)) {
            throw new HttpException(403, 'error.forbidden');
        }

        $segments = array_values(array_filter(explode('/', (string) ($route->params['subpath'] ?? '')), static fn (string $s): bool => $s !== ''));
        $response = match (count($segments)) {
            0 => $this->dashboard($actor),
            1 => $this->listing($request, $actor, $this->contentBlueprint($segments[0])),
            default => throw HttpException::notFound(),
        };

        // Admin pages are never indexed by search engines.
        return $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function dashboard(Actor $actor): Response
    {
        $counts = [];
        foreach ($this->blueprints->all() as $name => $blueprint) {
            $counts[] = [
                'name' => $name,
                'label' => $blueprint->label,
                'count' => $this->queries->count(Query::objects()->blueprint($name), $actor),
            ];
        }
        $recent = $this->queries->execute(Query::objects()->orderBy('updated', 'DESC')->limit(10), $actor);

        return $this->render('dashboard', 'dashboard', [
            'title' => 'admin.dashboard',
            'counts' => $counts,
            'recent' => $recent,
        ]);
    }

    /**
     * The list of a Blueprint's objects: /admin/<blueprint>?q=…&status=…&sort=…&dir=…&page=…
     */
    private function listing(Request $request, Actor $actor, Blueprint $blueprint): Response
    {
        $titled = isset($blueprint->capabilities['titled']);
        $publishable = isset($blueprint->capabilities['publishable']);

        $sortable = array_values(array_filter([
            $titled ? 'title' : null,
            'updated',
            'created',
            $publishable ? 'published_at' : null,
        ]));
        $sort = in_array($request->queryString('sort'), $sortable, true) ? $request->queryString('sort') : 'updated';
        $dir = strtolower($request->queryString('dir')) === 'asc' ? 'asc' : 'desc';
        $search = $titled ? trim($request->queryString('q')) : '';
        $status = $publishable && in_array($request->queryString('status'), self::STATUSES, true)
            ? $request->queryString('status') : '';
        $page = max(1, $request->queryInt('page', 1));

        $query = Query::objects()->blueprint($blueprint->name);
        if ($search !== '') {
            $query = $query->where('title', 'LIKE', '%' . addcslashes($search, '%_\\') . '%');
        }
        if ($status !== '') {
            $query = $query->whereCondition(self::statusCondition($status));
        }
        $result = $this->queries->execute(
            $query->orderBy($sort, $dir)->page($page, self::PER_PAGE),
            $actor,
            withTotal: true,
        );
        if (isset($blueprint->capabilities['authorable'])) {
            $this->relations->resolve($result, $actor);
        }

        return $this->render('list', $blueprint->name, [
            'title' => $blueprint->label,
            'blueprint' => $blueprint,
            'result' => $result,
            'filters' => ['q' => $search, 'status' => $status, 'sort' => $sort, 'dir' => $dir],
            'sortable' => $sortable,
            'statuses' => $publishable ? self::STATUSES : [],
            'searchable' => $titled,
            'has_author' => isset($blueprint->capabilities['authorable']),
            'path' => $this->access->path($blueprint->name),
        ]);
    }

    /** The condition of a status filter, consistent with the DefaultPolicy's notion of "published". */
    private static function statusCondition(string $status): Group
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $published = new FieldCondition('status', Operator::Equals, PublishStatus::Published);

        return match ($status) {
            'draft' => Group::all(new FieldCondition('status', Operator::Equals, PublishStatus::Draft)),
            'scheduled' => Group::all($published, new FieldCondition('published_at', Operator::GreaterThan, $now)),
            default => Group::all($published, Group::any(
                new FieldCondition('published_at', Operator::IsNull),
                new FieldCondition('published_at', Operator::LessOrEqual, $now),
            )),
        };
    }

    /** A Blueprint managed in the admin as content (users are managed from the command line for now). */
    private function contentBlueprint(string $name): Blueprint
    {
        $blueprint = $this->blueprints->find($name);
        if ($blueprint === null || isset($blueprint->capabilities['authenticatable'])) {
            throw HttpException::notFound();
        }

        return $blueprint;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, string $active, array $context): Response
    {
        $menu = [];
        foreach ($this->blueprints->all() as $name => $blueprint) {
            if (!isset($blueprint->capabilities['authenticatable'])) {
                $menu[] = ['name' => $name, 'label' => $blueprint->label];
            }
        }

        return Response::html($this->presentation->render("@core/admin/{$template}.html.twig", $context + [
            'active' => $active,
            'menu' => $menu,
            'blueprints' => $this->blueprints->all(),
        ]));
    }
}
