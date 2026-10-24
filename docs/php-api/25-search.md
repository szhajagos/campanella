# 25. Search

*Since 0.2.7.* The site's search, with the database's FULLTEXT indexes (as
decided on 2026-10-09: no separate search engine). Its page is at
`paths.search` (`/search`, a setting like the login's:
`'paths' => ['search' => '/kereses']`), and the default theme has a search
field in its header:

```twig
<form method="get" action="{{ path('search') }}" role="search">
    <input type="search" name="q" maxlength="200">
</form>
```

**What is found:** the objects with a page of their own (`Routable`), but
never users, the forms' messages or uploaded files (`SearchIndex::NEVER`);
**only what is published**, for everyone (an editor or an administrator finds
drafts in the admin, not here). The text searched is the title (a match there
counts three times as much), the body (HTML without its tags) and the
Blueprint's own text fields (`string`, `text`, `html`). Every word must be
found, as the start of a word: `kert` finds *kertben* and *Kertészet*. Upper
and lower case, and accents, do not matter: the index holds the text lowercase
and without accents (`SearchIndex::fold()`), and so is the query, so `kereses`
finds *Keresés* on MariaDB and MySQL alike. Words shorter than 3 letters are
left out (InnoDB's `innodb_ft_min_token_size`), so are InnoDB's stop words
(`Search::STOP_WORDS`, its default list, English: *the*, *with*, *www* …).

## The index: SearchIndex

`Campanella\Search\SearchIndex` · **Public** · `final class`

The `search_index` table (schema version 16): `object_id`, `blueprint`,
`title`, `content` (the plain text, folded, at most 60 000 bytes), `indexed_at`, with
the FULLTEXT indexes `ft_title (title)` and `ft_text (title, content)`. The
`ObjectRepository` writes an object's row on every save, in the same
transaction; deleting the object deletes it (`ON DELETE CASCADE`).

| Member | Description |
|---|---|
| `const NEVER` | `authenticatable`, `submitted`, `media_file`: their objects are never indexed |
| `const MAX_CONTENT` | The longest text kept for an object, in bytes |
| `static indexes(CampanellaObject $object): bool` | Whether the object is indexed |
| `static record(Connection $db, int $id, CampanellaObject $object, ?Blueprint $blueprint): void` | Writes (or deletes) its row |
| `static text(CampanellaObject $object, ?Blueprint $blueprint): array` | Its title and plain text, as indexed |
| `static fold(string $text): string` | Lowercase, without accents: `Árvíztűrő` → `arvizturo` (the Latin letters with diacritics; `ß` → `ss`, `æ` → `ae`) |
| `static fromHtml(string $html): string` | HTML's text: the tags (and scripts, styles) gone, the entities decoded, the white space made single |

## Searching: Search

`Campanella\Search\Search` · **Public** · `final class`, a service

```php
$result = $container->get(Search::class)->find('kert tavasz', $actor, page: 1, perPage: 10);
foreach ($result->items as ['object' => $object, 'excerpt' => $excerpt]) { … }
```

| Member | Description |
|---|---|
| `find(string $query, Actor $actor, int $page = 1, int $perPage = 10): SearchResult` | The page of the results, best first (at most `MAX_RESULTS` ranked, 50 per page). The page's objects are loaded through the Query engine, so the actor's access rules apply as to any query |
| `static words(string $query): array` | The words searched for: folded, letters and digits, at least `MIN_WORD` long, not a stop word, each once, at most `MAX_WORDS`. Nothing else of the query reaches the database (no operators) |
| `static excerpt(string $text, array $words): array` | A part of the object's text (`SearchIndex::text()`, with its accents) around the first word found, as pieces `{text, mark}`: the words that start with one searched for are marked. Plain text: a template escapes it |
| `const MIN_WORD`, `MAX_WORDS`, `MAX_QUERY`, `MAX_RESULTS`, `EXCERPT`, `STOP_WORDS` | 3, 8, 200 characters, 500, 220 characters; InnoDB's stop words |

`Campanella\Search\SearchResult` · **Public** · `final readonly class`:
`$query`, `$words`, `$items` (`list<array{object, excerpt}>`), `$total`,
`$page`, `$perPage`; `pages(): int`, `isEmpty(): bool`.

## The page: SearchController

`Campanella\Controller\SearchController` · **Internal**

`GET /search?q=…&page=2`: the form and the results, in `page/search.html.twig`
(a theme can override it; it gets `query`, `result`: a `SearchResult` or null
without a query, `too_short`, `min_word`, `path`). A page beyond the last is
404. The page sends `<meta name="robots" content="noindex">`, and `robots.txt`
lists it as not to crawl. Since 0.3.0 at most `SearchController::MAX_SEARCHES`
(30) searches a minute come from one network (the client's address counted
as for the login, `AuthService::clientKey()`), then 429 with `Retry-After`
and a message (`too_many` in the template).

## Building the index: SearchIndexer

`Campanella\Search\SearchIndexer` · **Public** · `final class`, a service

The pages saved before 0.2.7 are not in the index until it is built: the
System page says how many are missing, with a button that indexes them (as
many as fit into 20 seconds; press it again for the rest), and so does
`php bin/campanella search:index` (`--all`: every page again, e.g. after
changing a Blueprint's text fields).

| Member | Description |
|---|---|
| `missing(): int` | How many objects that should be in the index are not |
| `indexed(): int` | How many are in it |
| `build(float $seconds = 20.0, bool $all = false, ?Closure $progress = null): array` | Indexes the missing objects (or every one), until the time is up: `[indexed, still missing]` |

`Campanella\Search\SearchCheck` · **Internal**: the System page's line
(`static checks(SearchIndexer $indexer): Closure`, for `SystemCheck::add()`).
