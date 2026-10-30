<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\Routable;
use Campanella\Capability\Titled;
use Campanella\Capability\Weighted;
use Campanella\Display\CurrentPage;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Model\BlueprintRegistry;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Query\ResultSet;
use Campanella\Relation\RelationLoader;
use Campanella\Site\MetaBuilder;
use Campanella\Site\SiteSettings;
use Campanella\View\Presentation;
use Closure;

/**
 * The own page of a Routable object (Full rendering).
 *
 * Before rendering it loads the related objects and runs the Blueprint's
 * 'lists' (e.g. the articles on a category's page). With a MetaBuilder, the page
 * gets its meta description, canonical URL and Open Graph data (`meta`).
 */
final class ObjectController implements Controller
{
    /** The most children listed on a tree node's page (since 0.1.0). */
    public const int MAX_CHILDREN = 500;

    public function __construct(
        private readonly QueryEngine $queries,
        private readonly Presentation $presentation,
        private readonly BlueprintRegistry $blueprints,
        private readonly RelationLoader $relations,
        private readonly ?MetaBuilder $meta = null,
        private readonly ?CurrentPage $page = null,
        private readonly ?SiteSettings $site = null,
        /** @var (Closure(Request, Actor): Response)|null The front page when it shows no object (the latest content) */
        private readonly ?Closure $latest = null,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $front = $this->site?->frontPage();
        $atFront = ($route->params['front'] ?? false) === true;
        if ($atFront) {
            // The front page (since 0.3.5): a page assembled from components, at `/`. If it is
            // gone, or the visitor may not see it (e.g. a draft), the latest content, as before.
            $object = $front === null ? null : $this->queries->first(Query::objects()->where('id', '=', $front)->having(Routable::class), $actor);
            if ($object === null) {
                return $this->latest !== null ? ($this->latest)($request, $actor) : throw HttpException::notFound();
            }
        } else {
            $path = Routable::normalize((string) ($route->params['path'] ?? $request->path));

            // The query is access-aware: an object that is not visible is simply not found (404).
            $object = $this->queries->first(Query::objects()->where('path', '=', $path), $actor)
                ?? throw HttpException::notFound();
            // The front page's own path leads to `/`, so it has one address.
            if ($front !== null && (int) $object->id() === $front) {
                $query = http_build_query($request->query);

                return Response::redirect($request->basePath . '/' . ($query !== '' ? '?' . $query : ''), 301);
            }
        }
        $this->relations->resolve([$object], $actor);
        // The page's object, for the `current` source and the breadcrumbs (since 0.3.3).
        $this->page?->set($object);

        /** @var array<string, array{label: string, result: ResultSet}> $lists */
        $lists = [];
        foreach ($this->blueprints->find($object->blueprint())->lists ?? [] as $name => $list) {
            $result = $this->queries->execute(($list['query'])($object), $actor);
            $this->relations->resolve($result, $actor);
            $lists[$name] = ['label' => $list['label'] ?? '', 'result' => $result];
        }

        // A tree node (e.g. a category): its ancestors for the breadcrumbs, and its children.
        $breadcrumbs = [];
        $children = [];
        if ($object->has(Hierarchical::class)) {
            $breadcrumbs = $this->queries->execute(Hierarchical::ancestorsOf(Query::objects(), $object)->orderBy('depth'), $actor)->items;
            $childQuery = Hierarchical::childrenOf(Query::objects(), $object);
            $children = $this->queries->execute(
                ($object->has(Weighted::class) ? $childQuery->scope('by_weight') : $childQuery->orderBy('title'))->limit(self::MAX_CHILDREN),
                $actor,
            )->items;
        }

        return Response::html($this->presentation->render('page/object.html.twig', [
            'object' => $object,
            'title' => $object->has(Titled::class) ? $object->as(Titled::class)->title() : '',
            'lists' => $lists,
            'breadcrumbs' => $breadcrumbs,
            'children' => $children,
            // The page's description, canonical URL and Open Graph data (since 0.1.1).
            'meta' => $this->meta?->forObject($object, $atFront ? '/' : null),
            'front' => $atFront,
        ]));
    }
}
