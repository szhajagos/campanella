<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\RelationLoader;
use Campanella\Site\MetaBuilder;
use Campanella\View\Presentation;
use Closure;

/**
 * The result of a named Query as a list (instead of Drupal Views).
 * Query definitions live in config/queries.php. With a MetaBuilder, the page gets
 * its meta description, canonical URL and Open Graph data (`meta`).
 */
final class QueryController implements Controller
{
    /** @param array<string, Closure(): Query> $definitions */
    public function __construct(
        private readonly QueryEngine $queries,
        private readonly Presentation $presentation,
        private readonly array $definitions,
        private readonly ?RelationLoader $relations = null,
        private readonly ?MetaBuilder $meta = null,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $name = (string) ($route->params['query'] ?? '');
        $definition = $this->definitions[$name] ?? throw new \LogicException("Unknown Query: {$name}");
        $query = $definition();

        $perPage = (int) ($route->params['per_page'] ?? $query->getLimit() ?? 10);
        $page = max(1, $request->queryInt('page', 1));
        $result = $this->queries->execute($query->page($page, $perPage), $actor, withTotal: true);

        if ($page > 1 && $result->isEmpty()) {
            throw HttpException::notFound();
        }
        $this->relations?->resolve($result, $actor);

        $list = $this->presentation->renderList($result, $name, (string) ($route->params['item_mode'] ?? 'teaser'), [
            'path' => $request->path,
        ]);

        $title = (string) ($route->params['title'] ?? '');

        return Response::html($this->presentation->render('page/query.html.twig', [
            'title' => $title,
            'content' => $list,
            // The page's description, canonical URL and Open Graph data (since 0.1.1).
            'meta' => $this->meta?->forPath($request->path, $title, $page),
        ]));
    }
}
