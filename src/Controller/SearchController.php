<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\I18n\Translator;
use Campanella\Search\Search;
use Campanella\View\Presentation;

/**
 * The search's page (since 0.2.7), at `paths.search` (`/search`): `?q=` the words,
 * `?page=2` the next page. Not indexed by search engines (`noindex`).
 */
final class SearchController implements Controller
{
    /** The results per page. */
    public const int PER_PAGE = 10;

    public function __construct(
        private readonly Search $search,
        private readonly Presentation $presentation,
        private readonly Translator $translator,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $query = trim(mb_substr($request->queryString('q'), 0, Search::MAX_QUERY, 'UTF-8'));
        $page = max(1, $request->queryInt('page', 1));
        $result = $query === '' ? null : $this->search->find($query, $actor, $page, self::PER_PAGE);
        if ($result !== null && $page > 1 && $result->isEmpty()) {
            throw HttpException::notFound();
        }

        return Response::html($this->presentation->render('page/search.html.twig', [
            'title' => $this->translator->translate('search.title'),
            'query' => $query,
            'result' => $result,
            'too_short' => $query !== '' && Search::words($query) === [],
            'min_word' => Search::MIN_WORD,
            'path' => $request->path,
        ]));
    }
}
