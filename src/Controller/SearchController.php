<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Auth\AuthService;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\I18n\Translator;
use Campanella\Search\Search;
use Campanella\Security\Throttle;
use Campanella\View\Presentation;

/**
 * The search's page (since 0.2.7), at `paths.search` (`/search`): `?q=` the words,
 * `?page=2` the next page. Not indexed by search engines (`noindex`).
 *
 * Throttled (since 0.3.0): at most MAX_SEARCHES searches a minute from one network
 * (the client's address as for the login: an IPv6 /64), then 429 for the rest of the
 * minute: the only page where a visitor's input starts a query of the whole site.
 */
final class SearchController implements Controller
{
    /** The results per page. */
    public const int PER_PAGE = 10;

    /** The most searches a minute from one network (since 0.3.0). */
    public const int MAX_SEARCHES = 30;

    public function __construct(
        private readonly Search $search,
        private readonly Presentation $presentation,
        private readonly Translator $translator,
        private readonly ?Throttle $throttle = null,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $query = trim(mb_substr($request->queryString('q'), 0, Search::MAX_QUERY, 'UTF-8'));
        $page = max(1, $request->queryInt('page', 1));
        if ($query !== '' && $this->throttle !== null && Search::words($query) !== []) {
            $key = 'search:' . AuthService::clientKey($request->ip);
            if ($this->throttle->tooManyAttempts($key, self::MAX_SEARCHES) || $this->throttle->hit($key, 60) > self::MAX_SEARCHES) {
                return Response::html($this->presentation->render('page/search.html.twig', [
                    'title' => $this->translator->translate('search.title'),
                    'query' => $query,
                    'result' => null,
                    'too_short' => false,
                    'too_many' => true,
                    'min_word' => Search::MIN_WORD,
                    'path' => $request->path,
                ]), 429)->withHeader('Retry-After', (string) max(1, $this->throttle->availableIn($key)));
            }
        }
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
