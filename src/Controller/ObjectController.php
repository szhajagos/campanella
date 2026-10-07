<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\Routable;
use Campanella\Capability\Titled;
use Campanella\Capability\Weighted;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Model\BlueprintRegistry;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Query\ResultSet;
use Campanella\Relation\RelationLoader;
use Campanella\View\Presentation;

/**
 * The own page of a Routable object (Full rendering).
 *
 * Before rendering it loads the related objects and runs the Blueprint's
 * 'lists' (e.g. the articles on a category's page).
 */
final class ObjectController implements Controller
{
    public function __construct(
        private readonly QueryEngine $queries,
        private readonly Presentation $presentation,
        private readonly BlueprintRegistry $blueprints,
        private readonly RelationLoader $relations,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $path = Routable::normalize((string) ($route->params['path'] ?? $request->path));

        // The query is access-aware: an object that is not visible is simply not found (404).
        $object = $this->queries->first(Query::objects()->where('path', '=', $path), $actor)
            ?? throw HttpException::notFound();
        $this->relations->resolve([$object], $actor);

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
                $object->has(Weighted::class) ? $childQuery->scope('by_weight') : $childQuery->orderBy('title'),
                $actor,
            )->items;
        }

        return Response::html($this->presentation->render('page/object.html.twig', [
            'object' => $object,
            'title' => $object->has(Titled::class) ? $object->as(Titled::class)->title() : '',
            'lists' => $lists,
            'breadcrumbs' => $breadcrumbs,
            'children' => $children,
        ]));
    }
}
