# 20. The site: settings, meta tags, sitemap

Since 0.1.1 the site presents itself properly: its name, slogan and default
description are edited in the admin, every public page tells search engines
and social sites what it is (meta description, canonical URL, Open Graph), and
`/sitemap.xml` and `/robots.txt` are made from the content.

| Class | Namespace | What it does |
|---|---|---|
| `Settings` | `Campanella\Settings` | The settings saved in the admin (the `settings` table) |
| `SiteSettings` | `Campanella\Site` | The site's settings: saved values over the configuration file's, validation |
| `SiteValues` | `Campanella\Site` | The `site` variable of templates, read on first use |
| `PageMeta` | `Campanella\Site` | What one page tells about itself |
| `MetaBuilder` | `Campanella\Site` | Makes a page's `PageMeta` |
| `SiteController` | `Campanella\Controller` | `/robots.txt` and `/sitemap.xml` |
| `SettingsPage` | `Campanella\Admin` | The admin's Site settings page |
| `SiteCheck` | `Campanella\System` | The System page's *Site* lines |

## The settings (`Settings`)

A key–value store for everything edited in the admin, in the `settings` table
(schema version 7). Names are dotted, by area: `site.name`, later `mail.…`.
Values are text; the area's service decides what they mean.

```php
use Campanella\Settings\Settings;

$settings = $container->get(Settings::class);
$settings->get('site.name');                   // '…', or null if never saved
$settings->all('site.');                       // ['site.name' => '…', …]
$settings->set(['site.name' => 'Példa', 'site.slogan' => null]);  // null: removed
```

| Method | |
|---|---|
| `get(string $name): ?string` | A saved value, or null |
| `all(string $prefix = ''): array` | The saved values whose names start with the prefix |
| `set(array $values): void` | Saves several values in one transaction; null removes one. An invalid name (not `area.key`, lowercase) throws `InvalidArgumentException` |
| `isAvailable(): bool` | Whether the table could be read |
| `reset(): void` | Forgets the values read, so they are read again |

The values are read once per instance, on first use. If the table cannot be
read (before the upgrade that creates it, or with the database down), every
setting is simply missing, and the site runs on its configuration file: an
error page can still show the site's name.

## The site's settings (`SiteSettings`)

| Key | Type | |
|---|---|---|
| `name` | string | The site's name: the header, after every page title, Open Graph. Required, at most 100 characters |
| `slogan` | string | A short line under the name on the front page (at most 200) |
| `description` | string | The default meta description, for pages without their own (at most 300) |
| `url` | string | The site's address, e.g. `https://example.hu` or `https://example.hu/cms`; `''`: not set |
| `share_image` | ?int | An image object's ID, shown when a page without an image is shared |
| `indexing` | bool | Whether search engines may index the site (default: yes) |

A value saved in the admin wins; one never saved comes from the `site` section
of the configuration file:

```php
// config/app.php (or config/local.php)
'site' => [
    'name' => 'Campanella',
    'slogan' => 'Capability-vezérelt CMS',
    'description' => '',
    'url' => getenv('CAMPANELLA_SITE_URL') ?: '',
    'share_image' => null,
    'indexing' => true,
],
```

Other keys of that section (e.g. a theme's own) are passed to templates
unchanged.

| Method | |
|---|---|
| `values(): array` | Every value, typed, plus the configuration file's other keys |
| `name(): string`, `url(): string`, `shareImage(): ?int`, `indexing(): bool` | One value |
| `absolute(string $path): ?string` | A site path as an absolute URL on the site's address (`/hirek` → `https://example.hu/hirek`), or null while the address is not set. Characters outside URL syntax (accented letters, spaces, `%`) are percent-encoded |
| `save(array $input): void` | Validates and saves all keys; throws `ValidationException` (`name`, `slogan`, `description`, `url`, `share_image`) |
| `rememberUrl(string $url): void` | Saves the address unless one is saved already or set in the configuration file (the browser installer) |
| `reset(): void` | Forgets the values read |
| `SiteSettings::normalizeUrl(string $url): ?string` | An address in its stored form (lowercase scheme and host, no default port, no trailing slash); `''` for empty input; null if it is not an `http(s)` address with a valid host (a numeric one must be a valid IPv4 address) and port, or it has a user name, a query, a fragment or a broken `%` escape |
| `SiteSettings::originOf(Request $request): ?string` | The address a request was made to (scheme, `Host` header, the installation's folder), or null for an invalid `Host` |

**Why the address is a setting.** Absolute URLs could be made from the
request's `Host` header, but anyone can send any `Host`: a cached page or a
sitemap could then point to another site. So the address is set once: the
browser installer saves the address it was opened at (unless the configuration
file sets one: behind a proxy the request's `Host` may be an internal name),
and it can be changed on the Site settings page. While it is not set, canonical URLs, `og:url`,
`og:image` and the sitemap are left out, and the System page says so.

## Templates: `site` and `meta`

`{{ site.name }}`, `{{ site.slogan }}` and the other keys are read through
`SiteValues`: the settings are read only when a template first uses them, once
per request. It is read-only: a template cannot change a setting.

| Method | |
|---|---|
| `SiteValues::of(array $values): SiteValues` | Fixed values (e.g. in a test) |
| `toArray(): array` | The values; if they cannot be read, only a fallback name |
| `reset(): void` | Read again on next use (the Kernel does it for every request) |
| `offsetExists()`, `offsetGet()`, `getIterator()` | `ArrayAccess` and iteration: `site.name`, `site.x is defined`, `{% for key, value in site %}` |
| `offsetSet()`, `offsetUnset()` | Throw `LogicException`: read-only |

The pages that have one (an object's own page, a list, the front page) pass a
`meta` variable, a `PageMeta`. The base layout writes it into the head with
`page/_meta.html.twig`, which a theme can override:

```twig
{% block meta %}{% if meta is defined and meta %}{% include 'page/_meta.html.twig' %}{% endif %}{% endblock %}
```

```html
<meta name="description" content="…">
<link rel="canonical" href="https://example.hu/egy-cikk">
<meta property="og:site_name" content="…">
<meta property="og:title" content="…">
<meta property="og:type" content="article">
<meta property="og:url" content="https://example.hu/egy-cikk">
<meta property="og:description" content="…">
<meta property="og:image" content="https://example.hu/media/2026/10/….jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="article:published_time" content="2026-10-09T08:00:00+00:00">
<meta name="twitter:card" content="summary_large_image">
```

`PageMeta`'s properties: `title`, `siteName`, `description`, `canonical`,
`type` (`website` or `article`), `imageUrl`, `imageWidth`, `imageHeight`,
`imageAlt`, `publishedTime`, `modifiedTime`, `robots` (`noindex` while indexing
is turned off). An element whose value is missing is left out.

## `MetaBuilder`

| Method | |
|---|---|
| `forObject(CampanellaObject $object): PageMeta` | An object's own page (`ObjectController`) |
| `forPath(string $path, string $title = '', int $page = 1): PageMeta` | A list or another page at a path (`QueryController`); a page above 1 is in the canonical URL (`?page=2`) |
| `imageInHtml(string $html): ?CampanellaObject` | The first uploaded image of an HTML text (an image object of this site) |
| `MetaBuilder::excerpt(string $text, int $length = 160): string` | A text shortened at a word boundary, with an ellipsis |
| `MetaBuilder::textOf(string $html): string` | The visible text of an HTML fragment |

For an object's page:

- **description:** its `lead` field (e.g. an article's); else the beginning of
  its text (`Textual`), at most 160 characters; else the site's default;
- **image:** the first uploaded image in its text (`/media/…`, an image object
  the anonymous visitor can see); else the site's share image;
- **type:** `article` for a `Publishable` object, with its publication time
  (and its modification time, if later); `website` otherwise;
- **canonical URL:** its path on the site's address.

The controllers take the builder as an optional last constructor argument:
`new ObjectController($queries, $presentation, $blueprints, $relations, $meta)`,
`new QueryController($queries, $presentation, $definitions, $relations, $meta)`.

## `/robots.txt` and `/sitemap.xml` (`SiteController`)

Routes added by the Kernel (so no object can take these paths):

```
# Campanella
User-agent: *
Disallow: /admin/
Disallow: /login
Disallow: /logout
Disallow: /install

Sitemap: https://example.hu/sitemap.xml
```

The disallowed paths are the admin (only at its default path `/admin`: a path
of its own is not revealed here; the admin pages send `noindex` anyway), the
`auth` routes of `config/routes.php` (found with `Router::paths('auth')`) and
the installer.

**While indexing is turned off** every response without an `X-Robots-Tag` of
its own gets `X-Robots-Tag: noindex` (the Kernel), the pages say
`<meta name="robots" content="noindex">`, and there is no sitemap. Crawling is
deliberately **not** forbidden in `robots.txt`: a search engine that may not
fetch a page cannot read its `noindex`, and may still list its address.

The sitemap lists the lists (the `query` routes: `Router::paths('query')`) and
every `Routable` object the **anonymous** visitor can see, so a draft or a
scheduled article never appears, with its last modification. Above
`SiteController::PER_FILE` (2,000) addresses `/sitemap.xml` becomes an index of
`/sitemap.xml?page=1`, `?page=2` …. It answers 404 while the site's address is
not set or indexing is turned off. Both files may be cached for an hour.

`Router::paths(string $handler): array` lists the fixed paths of a handler, in
the order they were added.

A `robots.txt` file in `public/` is served by the web server instead of the
generated one (the System page notes it). Search engines read `robots.txt`
only at the root of a host: for a site in a folder, put the rules into the
host's own file.

## Not indexed: the admin, logging in, error pages

The admin pages already send `X-Robots-Tag: noindex, nofollow`. Since 0.1.1
the login page and every error page send `X-Robots-Tag: noindex` too.

## The Site settings page (`SettingsPage`)

`/admin/system/settings`, for the System page's roles (administrators by
default), under *System* in the menu. `SettingsPage::handle()` reads and
checks the form (CSRF), and `SiteSettings::save()` validates it; the newest
`SettingsPage::MAX_IMAGES` (100) images are offered as the share image (an
image picker comes in 0.3.0).

## The System page (`SiteCheck`)

`SiteCheck::checks(SiteSettings $site, string $publicDir)` adds the *Site*
group:

| Line | |
|---|---|
| Site address | Warning while not set (with the address the page was opened at as a suggestion), or when it differs from that address |
| Search engines | Warning while indexing is turned off |
| robots.txt | Info if a `public/robots.txt` file is served instead of the generated one |
