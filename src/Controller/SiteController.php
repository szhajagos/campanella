<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Capability\Routable;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Site\SiteSettings;

/**
 * The files for search engines (since 0.1.1), made from the site's content:
 *
 *   /robots.txt    the paths not to crawl (the admin, if at its default path; logging
 *                  in; installing) and the sitemap's address (not while indexing is
 *                  turned off: then every page says noindex instead, see Kernel)
 *   /sitemap.xml   the lists (the `query` routes) and every Routable object the
 *                  anonymous visitor can see (drafts and scheduled content never),
 *                  with their last modification. Above PER_FILE addresses it is an
 *                  index of /sitemap.xml?page=1, ?page=2 …
 *
 * The sitemap needs absolute URLs, so it answers 404 while the site's address is
 * not set (the System page warns), and while indexing is turned off.
 */
final class SiteController implements Controller
{
    /** The most addresses in one sitemap file (the protocol allows 50,000; kept low for memory). */
    public const int PER_FILE = 2000;

    /**
     * @param list<string> $lists The paths of the lists (e.g. `/`, `/hirek`)
     * @param list<string> $disallowed The paths search engines should not crawl
     */
    public function __construct(
        private readonly SiteSettings $site,
        private readonly QueryEngine $queries,
        private readonly array $lists = [],
        private readonly array $disallowed = [],
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        return match ($route->params['action'] ?? null) {
            'robots' => $this->robots($request),
            'sitemap' => $this->sitemap($request),
            default => throw HttpException::notFound(),
        };
    }

    private function robots(Request $request): Response
    {
        $lines = ['# ' . $this->site->name(), 'User-agent: *'];
        foreach ($this->disallowed as $path) {
            $lines[] = 'Disallow: ' . $request->basePath . $path;
        }
        // While indexing is turned off the pages say noindex: crawling is not forbidden,
        // or search engines could not read that, and could still list the addresses.
        $sitemap = $this->site->indexing() ? $this->site->absolute('/sitemap.xml') : null;
        if ($sitemap !== null) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $sitemap;
        }

        return self::text(implode("\n", $lines) . "\n", 'text/plain; charset=utf-8');
    }

    private function sitemap(Request $request): Response
    {
        if (!$this->site->indexing() || $this->site->url() === '') {
            throw HttpException::notFound();
        }
        $page = $request->query['page'] ?? null;
        $objects = Query::objects()->having(Routable::class)->orderBy('id');

        if ($page === null) {
            $total = count($this->lists) + $this->queries->count($objects, Actor::anonymous());
            if ($total > self::PER_FILE) {
                return $this->index((int) ceil($total / self::PER_FILE));
            }
            $page = '1';
        }
        if (!is_string($page) || !ctype_digit($page) || (int) $page < 1 || strlen($page) > 6) {
            throw HttpException::notFound();
        }
        $page = (int) $page;

        // The lists come first, then the objects: the pages' windows over both.
        $entries = [];
        $offset = ($page - 1) * self::PER_FILE;
        foreach (array_slice($this->lists, $offset, self::PER_FILE) as $path) {
            $entries[] = ['loc' => (string) $this->site->absolute($path), 'lastmod' => null];
        }
        $skip = max(0, $offset - count($this->lists));
        $take = self::PER_FILE - count($entries);
        if ($take > 0) {
            $result = $this->queries->execute($objects->offset($skip)->limit($take), Actor::anonymous());
            foreach ($result as $object) {
                $entries[] = [
                    'loc' => (string) $this->site->absolute((string) $object->get('path')),
                    'lastmod' => $object->updated()->format(DATE_ATOM),
                ];
            }
        }
        if ($entries === [] && $page > 1) {
            throw HttpException::notFound();
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $xml .= '  <url><loc>' . self::xml($entry['loc']) . '</loc>'
                . ($entry['lastmod'] !== null ? '<lastmod>' . $entry['lastmod'] . '</lastmod>' : '')
                . "</url>\n";
        }

        return self::text($xml . "</urlset>\n", 'application/xml; charset=utf-8');
    }

    private function index(int $files): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        for ($i = 1; $i <= $files; $i++) {
            $xml .= '  <sitemap><loc>' . self::xml($this->site->absolute('/sitemap.xml') . '?page=' . $i) . "</loc></sitemap>\n";
        }

        return self::text($xml . "</sitemapindex>\n", 'application/xml; charset=utf-8');
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function text(string $body, string $type): Response
    {
        return new Response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
