# 7. HTTP and view

## Request

`Campanella\Http\Request` · **Public** · `final readonly class`

| Member | Description |
|---|---|
| `$method` | Uppercase HTTP method |
| `$path` | The path relative to the installation root, normalized: starts with `/`, does not end with `/` |
| `$query`, `$post` | `$_GET`, `$_POST` |
| `$basePath` | URL prefix for an installation in a subdirectory, e.g. `/campanella`; otherwise `''` |
| `$headers` | Keyed by lowercase header names, e.g. `'accept-language'` |
| `$cookies` | `$_COOKIE` (string values only) |
| `$ip` | `REMOTE_ADDR` |
| `$secure` | Whether the request arrived over HTTPS |
| `isPost(): bool` | |
| `postString(string $name): string`, `queryString(string $name): string` | A single field as a string; if missing or not a string: `''` |
| `static fromGlobals(): self` | From the PHP superglobals |
| `static normalizePath(string $path): string` | `'/hirek/'` → `'/hirek'`; `'/index.php'` → `'/'` |
| `queryInt(string $name, int $default = 0): int` | An integer from the query string |

If the `.htaccess` in the root directs the request under `public/`,
`basePath` hides this, so `/public` does not appear in URLs.

## Response

`Campanella\Http\Response` · **Public** · `final class`

| Member | Description |
|---|---|
| `__construct(string $body = '', int $status = 200, array $headers = [...])` | Default header: `Content-Type: text/html; charset=utf-8` |
| `$body`, `$status`, `$headers` | Read-only |
| `static html(string $body, int $status = 200): self` | |
| `static redirect(string $url, int $status = 302): self` | |
| `withHeader(string $name, string $value): self` | A new response with the header added |
| `send(): void` | Sends the response; also adds the `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` and `Referrer-Policy: same-origin` headers to every response |

## HttpException

`Campanella\Http\HttpException` · **Public** · `RuntimeException`

`__construct(int $status, string $message = '')`, `$status`, and
`static notFound(string $message = 'error.not_found'): self` (the default is
a message key, see [chapter 12](12-translation.md#in-php-code)). When thrown from a controller, the `Kernel`
renders an error page with the given status.

## Router and RouteMatch

`Campanella\Http\Router` · **Public** · `final class` · container: `Router::class`

| Method | Description |
|---|---|
| `__construct(array $routes = [])` | The array from `config/routes.php`: `path => [handler, parameters]` |
| `add(string $path, string $handler, array $params = []): void` | |
| `isRouted(string $path): bool` | Whether a fixed route or a prefix route handles the path (an object cannot live there) |
| `prefix(string $prefix, string $handler, array $params = []): void` | A route for a path and everything below it (e.g. the admin); the rest of the path is passed as the `subpath` parameter (since 0.0.4) |
| `match(Request $request): RouteMatch` | A static route if one exists; else the longest matching prefix; otherwise `RouteMatch('object', ['path' => …])` |

`Campanella\Http\RouteMatch` · `final readonly class`: `$handler` (the
controller name) and `$params`.

The Router does not query the database. Whether an object exists behind a path
is decided by the `ObjectController`, together with access control.

```php
// config/routes.php
return [
    '/'      => ['query', ['query' => 'frontpage', 'title' => '']],
    '/hirek' => ['query', ['query' => 'news', 'title' => 'Hírek', 'per_page' => 5]],
];
```

## Controllers

`Campanella\Controller\Controller` · **Public** · `interface`

```php
public function handle(Request $request, RouteMatch $route, Actor $actor): Response;
```

A controller is thin: it receives the request, calls the Model or Service
layer, and passes the result to the View. The `Kernel` calls the
`controller.<handler>` container entry based on `$route->handler`.

### ObjectController

`Campanella\Controller\ObjectController` · handler: `object`

The own page of a Routable object. It normalizes the path
(`Routable::normalize`), looks it up with the `QueryEngine` (so access control
applies), loads its relations (`RelationLoader`), runs the Blueprint's `lists`,
and renders it with the `page/object.html.twig` template in `full` mode. If
there is no such object, or the `Actor` may not see it: 404.

Constructor: `__construct(QueryEngine $queries, Presentation $presentation,
BlueprintRegistry $blueprints, RelationLoader $relations)`. Template variables:
`object`, `title`, `lists` (`array<string, array{label: string, result: ResultSet}>`).

### QueryController

`Campanella\Controller\QueryController` · handler: `query`

The result of a named Query as a paginated list. Query definitions live in
`config/queries.php` (`name => Closure(): Query`).

| Route parameter | Description |
|---|---|
| `query` | The name of the Query (required) |
| `title` | The page title; if empty, the front page introduction is shown |
| `per_page` | Items per page; defaults to the Query's limit, or 10 if it has none |
| `item_mode` | The rendering mode of the items, `teaser` by default |

Constructor: `__construct(QueryEngine $queries, Presentation $presentation,
array $definitions, ?RelationLoader $relations = null)`; if given one, it also
loads the relations of the list items.

The page number comes from the `?page=` parameter. For a non-existent page
(from page 2 onward, if empty): 404.

## Presentation

`Campanella\View\Presentation` · **Public** · `final class` · container: `Presentation::class`

Decides *how* something should be displayed and picks the template for it. It
does not query the database; it only renders what it is given.

| Method | Description |
|---|---|
| `renderObject(CampanellaObject $object, string $mode = self::TEASER, array $context = []): string` | One object in one mode |
| `renderList(ResultSet $result, string $name, string $itemMode = self::TEASER, array $context = []): string` | A list |
| `render(string $template, array $context = []): string` | Any template |

Modes: `Presentation::FULL` (`'full'`) and `Presentation::TEASER` (`'teaser'`).
A new mode is added simply with a new template, e.g. `object/card.html.twig`.

### Template lookup

From the most specific to the most general:

| What | 1st attempt | 2nd attempt |
|---|---|---|
| Object | `object/<blueprint>--<mode>.html.twig` | `object/<mode>.html.twig` |
| List | `query/<query-name>.html.twig` | `query/list.html.twig` |

Template variables:

| Template | Variables |
|---|---|
| object | `object`, `mode` + the `$context` |
| list | `result` (ResultSet), `name`, `item_mode`, `path` + the `$context` |
| every template | `site` (the `site` key of `config/app.php`), `campanella_version` |

Each lookup first checks the active theme's folder, then the core templates
(see below), so a theme can override any single template.

## Look and themes

Since 0.0.4 the core templates (`templates/`) are built on **Bootstrap 5.3**,
shipped with Campanella in `public/assets/vendor/bootstrap/` (no CDN, no jQuery;
the JavaScript bundle includes Popper). On top of it, `public/assets/campanella.css`
sets the default look through Bootstrap's CSS variables. The color mode (light or
dark) follows the visitor's system setting (`public/assets/theme.js`, loaded in
`<head>`; a file rather than an inline script since 0.0.5, so a
Content-Security-Policy can forbid inline scripts).

`base.html.twig` provides the blocks `title`, `stylesheets`, `content` and
`scripts`.

### Theme

`Campanella\View\Theme` · **Public** · `final readonly class` · container: `Theme::class`

A theme is a folder in `themes/` that contains only what it changes:

```
themes/<name>/templates/   templates; one with the same path as a core template takes precedence
public/themes/<name>/      the theme's own CSS, images and scripts
```

It is chosen with the `theme` setting (`config/app.php`, or `CAMPANELLA_THEME`);
empty (the default) means the core templates alone. A theme template can extend
the core version of itself through the `@core` namespace, so it does not have
to copy it:

```twig
{# themes/mytheme/templates/base.html.twig #}
{% extends '@core/base.html.twig' %}
{% block stylesheets %}<link rel="stylesheet" href="{{ theme_asset('style.css') }}">{% endblock %}
```

A theme does not have to use Bootstrap: if it overrides `base.html.twig`
without extending it, it decides which CSS and JavaScript it loads.

| Member | Description |
|---|---|
| `static none(): self` | No theme |
| `static fromRoot(string $root, string $name): self` | The theme under `<root>/themes/<name>`; an empty name means no theme. `LogicException` for an invalid name (`^[a-z][a-z0-9_-]{0,40}$`) or a missing `templates` folder |
| `$name`, `$templateDir` | The name, and the templates folder (`null` without a theme) |
| `isActive(): bool` | Whether a theme is set |
| `assetPath(string $path): string` | `themes/<name>/<path>`; `LogicException` without a theme |

## Twig extension

`Campanella\View\CampanellaTwigExtension` · **Public** (the template functions) · the extension class is **Internal**

| In a template | PHP method | Description |
|---|---|---|
| `{{ url('/hirek') }}` | `url(string $path)` | Subdirectory-safe URL |
| `{{ asset('campanella.css') }}` | `asset(string $path)` | The URL of a file under `public/assets/`, with the version as a cache buster (`…/campanella.css?v=0.0.4`) |
| `{{ render_object(item, 'teaser') }}` | `renderObject(CampanellaObject $object, string $mode)` | One object in one mode |
| `{{ related(object, 'categories') }}` | `related(CampanellaObject $object, string $relation)` | The loaded target objects of a relation, or an empty list |
| `{{ current_user() }}` | `currentUser()` | The logged-in user or `null` ([chapter 11](11-users.md#web-interface)) |
| `{{ csrf_field() }}` | `csrfField()` | Hidden CSRF field for POST forms |
| `{{ theme_asset('style.css') }}` | `themeAsset(string $path)` | A file of the active theme (`public/themes/<name>/`), with the version as cache buster |
| `{{ admin_url('article') }}` | `adminUrl(string $subpath = '')` | The URL of an admin page ([chapter 13](13-admin.md)) |
| `{{ admin_access() }}` | `adminAccess()` | Whether the current visitor may enter the admin (e.g. to show an "Admin" link) |
| `{{ flash_messages() }}` | `flashMessages()` | The one-time messages, translated (`type`, `text`), removed from the session |
| `{{ t('auth.login', {…}) }}` | `translate(string $key, array $params = [])` | A user-facing text in the current language ([chapter 12](12-translation.md)) |
| `{{ locale() }}` | `locale()` | The current language code |
| `{{ object\|body }}` | `body(CampanellaObject $object)` | The HTML of the Textual body: escaped and split into paragraphs for the `plain` format; as stored for the `html` format, which is filtered on save ([chapter 15](15-html.md)) |

The extension's other methods (`getFunctions()`, `getFilters()`,
`getGlobals()`) are meant for Twig.

Dates rendered with the `date` filter use the `timezone` setting and the
`Y. m. d. H:i` format by default. Templates escape automatically (HTML); in
debug mode an undefined variable raises an error.
