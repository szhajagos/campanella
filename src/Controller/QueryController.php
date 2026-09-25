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
use Campanella\View\Presentation;
use Closure;

/**
 * Egy elnevezett Query eredménye listaként (a Drupal Views helyett).
 * A Query-definíciók a config/queries.php fájlban élnek.
 */
final class QueryController implements Controller
{
    /** @param array<string, Closure(): Query> $definitions */
    public function __construct(
        private readonly QueryEngine $queries,
        private readonly Presentation $presentation,
        private readonly array $definitions,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $name = (string) ($route->params['query'] ?? '');
        $definition = $this->definitions[$name] ?? throw new \LogicException("Ismeretlen Query: {$name}");
        $query = $definition();

        $perPage = (int) ($route->params['per_page'] ?? $query->getLimit() ?? 10);
        $page = max(1, $request->queryInt('page', 1));
        $result = $this->queries->execute($query->page($page, $perPage), $actor, withTotal: true);

        if ($page > 1 && $result->isEmpty()) {
            throw HttpException::notFound();
        }

        $list = $this->presentation->renderList($result, $name, (string) ($route->params['item_mode'] ?? 'teaser'), [
            'path' => $request->path,
        ]);

        return Response::html($this->presentation->render('page/query.html.twig', [
            'title' => (string) ($route->params['title'] ?? ''),
            'content' => $list,
        ]));
    }
}
