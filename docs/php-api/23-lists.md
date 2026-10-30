# 23. Lists

*Since 0.2.5.* A list made in the admin: which objects of a Blueprint, under
which conditions, in what order, how many per page, shown how, and at which
path. It is the counterpart of `config/queries.php` that needs no code. Lists
are made in the admin (System → Lists, see below), or in code through
`ListStore`.

```php
$store->save('kozelgo', [
    'version' => 1,
    'title' => 'Közelgő események',
    'blueprint' => 'esemeny',
    'conditions' => [
        ['field' => 'nap', 'op' => '>=', 'relative' => 'today'],
        ['field' => 'fajta', 'op' => '=', 'value' => 'eloadas'],
        ['relation' => 'kategoria', 'targets' => [12]],
    ],
    'sort' => [['field' => 'nap', 'dir' => 'asc']],
    'per_page' => 10,
    'display' => 'table',
    'columns' => ['nap', 'fajta'],
    'path' => '/esemenyek',
]);
```

```twig
{% for item in list('kozelgo') %}{{ render_object(item, 'teaser') }}{% endfor %}
```

## ListDefinition

`Campanella\Lists\ListDefinition` · **Public** · `final readonly class`

`$name`, `$title`, `$blueprint`, `$conditions`, `$sort`, `$perPage`,
`$display`, `$columns`, `$path` (null: no page of its own), `$filters` (since
0.2.9: what visitors may filter it by).

| Member | Description |
|---|---|
| `static fromArray(string $name, array $stored, BlueprintRegistry $blueprints): self` | From the stored form, checked; `InvalidArgumentException` for anything not valid |
| `toArray(): array` | The stored form |
| `query(BlueprintRegistry $blueprints, DateTimeZone $timezone, ?DateTimeImmutable $now = null): Query` | The list's Query at the moment (for `today` and `now`), limited to `perPage`; paging is the caller's (`Query::page()`). A Publishable Blueprint's list has the `published` scope; without a sort order, the newest first (`created`) |
| `static queryable(Blueprint $blueprint, string $name): ?Field` | A field a list may filter or sort by: `created`, `updated`, or a queryable (`FieldStorage::Table`) field of the Blueprint that is not hidden |
| `static filter(Blueprint $blueprint, string $name): Field\|Relation\|null` | A field or relation visitors may filter a list by (since 0.2.9): a queryable field with a filter (`FieldFilters::kindOf()`; not `status`), or a relation of the Blueprint |
| `static path(mixed $path): string` | A list's path, normalised (lowercase, a leading slash, no trailing one); not `/`; letters, digits, `_ . -` |
| `DISPLAYS`, `OPERATORS`, `RELATIVE` | `teaser`, `table`; `=`, `!=`, `<`, `<=`, `>`, `>=`, `contains`, `empty`, `not_empty`; `today`, `now` |
| `MAX_PER_PAGE`, `MAX_CONDITIONS`, `MAX_SORT`, `MAX_COLUMNS`, `MAX_TITLE`, `MAX_FILTERS` | 100, 10, 3, 8, 200, 4 |

**The checks.** The Blueprint must exist, and not be one of users or forms'
messages. A condition is on a field `queryable()` gives, or on a relation of
the Blueprint (`targets`: object IDs). `contains` only on texts; `<`, `>` …
not on texts and yes/no; the value is cast by the field's type (a choice must
be offered); `relative` (`today`, `now`) only on dates. Sorting only by
single-valued fields, at most three. Columns: any field of the Blueprint that is
not hidden.

**`today` and `now`.** For a `Date` field both are today's day in the site's
time zone; for a date and time, `today` is today's midnight there, `now` the
moment. They are worked out on every request, so "from today on" stays true.

**Access** is the Query engine's, as for any query: a visitor sees only what
they may see. A list adds nothing to it but the `published` scope.

## Visitors' filters

*Since 0.2.9.* A list's `filters` (at most 4, chosen in its editor) are offered
to visitors as a form above it, `list/_filters.html.twig` (a theme can override
it), sent to the list's own path as `?f[…]`. Each is added to the list's own
conditions, so what is not published stays hidden whatever is chosen. A filter
is by the field's type, or one of a relation's targets (those the visitor may
see, while there are at most 200), as in the admin's lists:

```
/esemenyek?f[fajta]=eloadas&f[nap][from]=2026-11-01&f[nap][to]=2026-11-30&f[helyszin]=12
```

A value that is not valid for its field is ignored, and not shown back. The
pages keep the filters (`list/_pager.html.twig`). A filtered page is
`noindex`, its canonical address the list's own (`PageMeta::notIndexed()`).

### FieldFilters

`Campanella\Query\FieldFilters` · **Public** · `final class` (since 0.2.9; the
admin's lists use it too)

| Kind | Type | Filter | In the address |
|---|---|---|---|
| `choice` | choice | one of the choices | `f[fajta]=seta` |
| `boolean` | boolean | yes or no | `f[aktiv]=1`, `f[aktiv]=0` |
| `range_date` | date, datetime | from, to (days, in the site's time zone, both included) | `f[nap][from]=…&f[nap][to]=…` |
| `range_number` | integer, decimal | at least, at most (a decimal comma and spaces read) | `f[ar][min]=…&f[ar][max]=…` |
| `relation` | a relation | one of its targets | `f[kategoria]=12` |

| Member | Description |
|---|---|
| `__construct(QueryEngine $queries, DateTimeZone $timezone)` | The time zone the days of a date and time are in |
| `static kindOf(Field $field): ?string` | A queryable, not hidden field's kind by its type; null if it has none |
| `apply(Query $query, array $filters, array $input, Actor $actor): array` | The query with the filters of `$input` (the request's `f`) for the given fields and relations; the valid values (for the form and the addresses); and the options of the choice and relation filters, by name (a relation with more than `MAX_TARGETS` targets has none) |
| `MAX_TARGETS` | 200 |

## ListStore

`Campanella\Lists\ListStore` · **Public** · container: `ListStore::class`

`load(): array<string, array>` (none before the `lists` table exists),
`find(string $name): ?array`, `save(string $name, array $definition): void`,
`delete(string $name): void`. The definitions are JSON in `cc_lists`, loaded,
never queried.

## ListRegistry

`Campanella\Lists\ListRegistry` · **Public** · container: `ListRegistry::class`

`__construct(array $stored, BlueprintRegistry $blueprints)`; `find(string $name):
?ListDefinition`, `all(): array<string, ListDefinition>`, `errors():
array<string, string>`: a stored list that does not pass (e.g. its field is not
queryable any more) is left out, with the reason. `loadStored(array $stored): void`
(since 0.3.5) adds or replaces stored definitions, e.g. a list saved in the same
run (the sample content's).

## The page: ListController

`Campanella\Controller\ListController` · handler: `list` · **Internal**

Every list with a path gets a route (`list`, parameter `list`), unless a route
of `config/routes.php` or the system already has that path: those win. An
object's path that a list has is refused like any routed path
(`validation.path_reserved`). The page: the list's title, its objects a page at
a time (`?page=2`; past the last page, 404), and `meta` like a query's page.
Lists with a path are in `sitemap.xml`.

**Templates:** the list's own, `list/<name>.html.twig`, if a theme has one, else
its display's: `list/teaser.html.twig` (the objects' `teaser` mode) or
`list/table.html.twig` (the title as a link, then the `columns`, each by
`field_value()`). Both include `list/_pager.html.twig`. They get `result`
(the `ResultSet`), `list` (the `ListDefinition`), `columns` (`name`, `label`;
the title is left out, it comes first), `path`, and since 0.2.9 `filters`
(`name`, `label`, `kind`, `options`), `filter_values` and `filter_query` (the
filters as a query string, for the pages); both include `list/_filters.html.twig`
first. `ListController::columns(ListDefinition $list, BlueprintRegistry $blueprints)`
makes the columns.

## In templates

| Function | Description |
|---|---|
| `{% for item in list('name') %}` | The list's first page for the current visitor (`CampanellaTwigExtension::list()`); nothing for an unknown list |
| `{{ field_value(item, 'nap') }}` | One field of an object shown by its type, safe to print, as `object_fields()` shows it (`fieldValue()`) |

## The editor: ListEditor

`Campanella\Admin\ListEditor` · **Internal**, for the system roles
(`admin.system_roles`), under System in the admin's menu.

| Page | What it does |
|---|---|
| `/admin/system/lists` | The lists: title, machine name, Blueprint, path (a link to the page), display; a stored list that could not be loaded, with the reason |
| `/admin/system/lists/new` | A new list: its title, machine name (as a Blueprint's; never changed later) and Blueprint (not users or forms' messages; never changed later) |
| `/admin/system/lists/<name>` | Its title, path, number per page, display; conditions: rows of a field (only those `queryable()` gives), an operator, and a value or `today` / `now` (the list's own rows and three empty ones; an empty row is left out); a relation: the targets chosen (at most 200 offered); the order (three rows); the table's columns (the fields not hidden, but the title, body, status and format). Since 0.2.9 also the visitors' filters (at most 4 of what `filter()` allows). Saving checks it with `ListDefinition::fromArray()`; what does not pass is shown with the reason |
| `/admin/system/lists/<name>/delete` | POST: deletes the list (its page goes; the objects stay) |

**The path** is refused if a route has it (`config/routes.php`, the system's
pages, another list), if it is in the admin or a public folder (`/assets`,
`/themes`, `/media`), or if an object has it (`validation.path_reserved`). An
object's path a list has is refused the same way (the list's route).
