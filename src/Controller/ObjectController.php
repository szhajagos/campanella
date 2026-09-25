<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Capability\Routable;
use Campanella\Capability\Titled;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\View\Presentation;

/** Egy Routable objektum saját oldala (Full megjelenítés). */
final class ObjectController implements Controller
{
    public function __construct(
        private readonly QueryEngine $queries,
        private readonly Presentation $presentation,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $path = Routable::normalize((string) ($route->params['path'] ?? $request->path));

        // A lekérdezés access-aware: a nem látható objektum egyszerűen nincs meg (404).
        $object = $this->queries->first(Query::objects()->where('path', '=', $path), $actor)
            ?? throw HttpException::notFound();

        return Response::html($this->presentation->render('page/object.html.twig', [
            'object' => $object,
            'title' => $object->has(Titled::class) ? $object->as(Titled::class)->title() : '',
        ]));
    }
}
