# Campanella 0.3.0

[![CI](https://github.com/szhajagos/campanella/actions/workflows/ci.yml/badge.svg)](https://github.com/szhajagos/campanella/actions/workflows/ci.yml)

A capability-driven CMS. There are no predefined content types: an object's
behavior is determined by the capabilities attached to it.

0.1.0 was the first milestone: a real website can be run and managed from the
browser with it. 0.2.0 was the second: the site communicates (e-mail, a
forgotten password, a contact form, search engines and sharing). **0.3.0** is
the third: **content types built in the admin**, without programming, with
their fields, lists for visitors with filters, search and redirects, carried
between sites by export and import; and what 0.3.x keeps is promised (see the
[compatibility promise](docs/compatibility.md)). Plans are in the
[ROADMAP](ROADMAP.md), changes in the [CHANGELOG](CHANGELOG.md).

## What is new in 0.3.0

0.3.0 brings together what 0.2.1–0.2.9 built (below), checked, documented and
promised for the longer term:

- **A security review** of 0.2.2–0.2.9: the search is limited to 30 searches
  a minute per network, the content model's import checks choices and
  relations as strictly as the editor
  ([docs/security.md](docs/security.md#security-review-for-030-2026-10-24)).
- **Upgrade tests from 0.2.0 and 0.2.9** (with a content model made in the
  admin), besides 0.1.0 and 0.1.5, and the upgrade guide for any 0.2.x or
  0.1.x ([docs/upgrading.md](docs/upgrading.md)).
- **A performance check** with 10 000 objects: a list with filters, the
  admin's list, the search, within set limits on MariaDB and MySQL
  ([docs/testing.md](docs/testing.md#the-performance-check)).
- **The guide's new chapter**: an event calendar built step by step in the
  admin ([en](docs/guide/en/11-own-content-types.md),
  [hu](docs/guide/hu/11-sajat-tartalomtipusok.md)); **sample events** in the
  sample content, with their list and filters.
- **The compatibility promise for 0.3.x**, now also for the content model
  ([docs/compatibility.md](docs/compatibility.md)); 0.2.x gets security fixes
  until 0.4.0 is out.

The details are in the [CHANGELOG](CHANGELOG.md#030--2026-10-24).

## What 0.2.1–0.2.9 built

The steps towards 0.3.0, content types built in the admin
([ROADMAP](ROADMAP.md#030--the-content-model-in-the-admin)):

- **0.2.9 – Visitors' filters on the lists:** a list can offer a form to
  narrow it, e.g. by the kind of event or a range of days.
- **0.2.8 – The admin's list with own fields:** up to 4 of a Blueprint's
  fields and relations as columns, sortable, with filters by their type.
- **0.2.7 – Search, redirects, an image picker:** the site can be searched
  (`/search`, a field in the header), an old path leads on to the page (301,
  System → Redirects), and the editor inserts images uploaded before.
- **0.2.6 – The content model's export and import** (System → Content
  model): the Blueprints and lists made in the admin in one file, to carry
  them to another site; an import shows its plan first and never deletes.
- **0.2.5 – Lists made in the admin** (System → Lists): the objects of a
  Blueprint under conditions (also "from today on"), in an order, a page at a
  time, as cards or a table, at a path of their own and in templates
  (`list('name')`).
- **0.2.4 – Changing and deleting fields in the admin:** a field's type
  widened, its column, one or several values, required; fields and relations
  deleted; always with a backup first when data is touched.
- **0.2.3 – Blueprints made in the admin:** an administrator can make a new
  kind of content in the browser (System → Blueprints → New Blueprint), with
  its capabilities, fields and relations; the database is changed at once,
  with a backup first if there are objects already. Its objects are edited,
  listed and shown like any other.
- **0.2.2 – Fields to filter and sort on:** a Blueprint's own fields can get a
  column of their own, so lists can be filtered and ordered by them; six new
  field types (decimal, date, e-mail address, web address, a choice list,
  formatted text); the design of [translations](docs/translations.md).
- **0.2.1 – A guide for site owners**, in Hungarian and English, with
  screenshots ([docs/guide/](docs/guide/README.md)), for people who run a site
  but do not develop it.

## What was new in 0.2.0

0.2.0 brings together what 0.1.1–0.1.5 built, and makes it ready to rely on:

- **The site communicates:** search engines and sharing (0.1.1), images for
  every screen (0.1.2), e-mail and events (0.1.3), the forgotten password and
  the session list (0.1.4), the contact form (0.1.5).
- **Upgrading from any 0.1.x in one go**, with a guide
  ([docs/upgrading.md](docs/upgrading.md)) and a backup that now really holds
  the old database, so going back works. CI upgrades 0.1.0 to the current code
  on every push.
- **Tested in a real browser:** logging in and out, the lock-out, the
  sessions, the forgotten password, the contact form's protections and the
  admin, on every push; and the installation package itself, installed from
  the browser on a PHP with only the required extensions, before every
  release ([docs/testing.md](docs/testing.md)).
- **A security review of the whole 0.1.x layer together**: blocking an
  account ends its logins at once, IPv6 addresses are limited by their /48,
  a blocked account gets the same answer as a wrong password
  ([docs/security.md](docs/security.md#security-review-for-020-2026-10-11)).
- **E-mails show times in the site's time zone.**
- **The compatibility promise** for 0.2.x.

The details are in the [CHANGELOG](CHANGELOG.md#020--2026-10-12).

## What it can do

- **Content:** articles, pages, categories in a tree, images, menus. Drafts,
  publishing now or later, a hand-set order, categories with breadcrumbs and
  the articles of their subcategories.
- **Editing in the browser:** an admin with lists, search and filters, forms
  generated from the definitions, a formatted-text editor (Jodit) with image
  upload, the main menu edited as a tree.
- **Images:** checked and re-encoded on upload, with smaller copies for every
  screen (`srcset`); an image's delete page lists the texts that show it.
- **E-mail and events** (since 0.1.3): sending e-mail through SMTP or PHP's
  own settings, with templates and a log; events (published, created …) with
  actions bound to them in the configuration.
- **Users:** administrators and editors, managed in the browser; a profile
  with one's own password and the list of where one is logged in (any of them
  can be logged out); a forgotten password by e-mail (since 0.1.4); login
  protection (throttling, a honeypot, the same answer for every failure), and
  a login lasts at most 12 hours.
- **Contact form** (since 0.1.5): on its own page or on any page, protected
  against bots and floods; the messages in the admin (administrators only)
  and by e-mail, deleted after a set time.
- **For search engines and sharing** (since 0.1.1): the site's name,
  description and address edited in the admin; meta description, canonical
  URL and Open Graph tags on every page; `sitemap.xml` and `robots.txt` made
  from the content.
- **Installing and upgrading from the browser**, for web hosts without a
  command line (with a key), or from the command line; migrations with a
  built-in backup.
- **Secure by default:** HTML texts filtered on every save, uploaded images
  re-encoded, a strict Content-Security-Policy, templates in a sandbox, no
  default account. See [docs/security.md](docs/security.md).
- **Bilingual interface** (Hungarian and English), Bootstrap 5.3 and the editor
  shipped locally, replaceable themes.

**Running a site?** The [guide for site owners](docs/guide/README.md) (in
Hungarian and English) explains installing, the admin, content and the rest,
without programming (since 0.2.1). *Útmutató az oldal gazdájának:
[magyarul](docs/guide/hu/README.md).*

The developer documentation (PHP API, HTTP API) is in the [`docs/`](docs/README.md)
folder; putting a site on a public server is described in the
[deployment guide](docs/deployment.md), changes are listed in the
[CHANGELOG](CHANGELOG.md), and plans in the [ROADMAP](ROADMAP.md).

## Requirements

- PHP 8.3 or newer (`pdo_mysql`, `mbstring`, `json`, `dom`, `ctype`, `session`; `gd` with JPEG, PNG and WebP to upload images; recommended: `exif`, `fileinfo`, `opcache`). Running `composer install` yourself also needs `iconv`; the release package does not. Without `gd` the site works, but images cannot be uploaded (the System page says so). Each release package is installed on a PHP with only these extensions before it is published ([Testing](docs/testing.md#the-release-package)).
- MariaDB 10.6+ or MySQL 8.0+ (InnoDB, utf8mb4)
- Composer (to download the dependencies; you can also upload the project to your web host together with the `vendor/` folder)

PHP dependencies: Twig, symfony/html-sanitizer and symfony/mailer (with their
dependencies, all MIT or BSD). Bootstrap 5.3 and the Jodit editor (CSS and JavaScript) are
shipped in `public/assets/vendor/`. PHPStan is needed for development only.

## Installation

**In the browser** (for web hosts without a command line, since 0.1.0):

1. Upload the files: the `campanella-<version>.zip` of the
   [GitHub releases](https://github.com/szhajagos/campanella/releases) page
   includes the `vendor/` folder. Mind where the files go (see below).
2. Copy `config/local.php.dist` to `config/local.php`, enter your database
   details and an install key of at least 20 characters:

   ```php
   'install' => ['key' => '…a long random string…'],
   'database' => [/* … */],
   ```

3. Open `/install` in the browser: it checks the requirements, creates the
   tables and the first administrator (and, if you like, the sample content),
   and logs you in. Then remove the key; the page closes as soon as there is
   a user.

**From the command line.** Without Composer: every version on the [GitHub releases](https://github.com/szhajagos/campanella/releases)
page has a downloadable `campanella-<version>.zip` that includes the `vendor/`
folder. After unpacking it, continue as below, just skip the `composer install`
step.

```bash
composer install
cp config/local.php.dist config/local.php   # enter your database credentials
php bin/campanella install                  # create the tables
php bin/campanella seed                     # sample content (optional)
php bin/campanella user:create te@example.hu --name="A Neved" --role=administrator
php -S localhost:8000 -t public public/index.php   # for local testing only
```

The last line starts PHP's built-in development server on your own machine, at
<http://localhost:8000>. Do not use it on a production host, and do not infer
your web host's configuration from it (see below).

Before going live, follow the [deployment guide](docs/deployment.md) and its
checklist (web root, HTTPS, settings, permissions, backups).

### Where do the files go?

The project's folders always stay together. Only the `public/` folder is
exposed to the web server, but `index.php` looks for the `vendor/`, `src/` and
`config/` folders one level above it. Therefore **copying only the contents of
`public/` into the web root is not enough.** There are two correct setups:

1. **The web root is the `public/` folder.** This only works if PHP can also
   see folders outside the web root: on your own server via Apache's
   `DocumentRoot` setting, or by using the included `Dockerfile`.
   **Note:** many Docker-based web hosts put only the folder designated as the
   web root into the container (as `/var/www/html`). In that case, if you
   choose `public/`, the `vendor/`, `src/` and `config/` folders are invisible
   to PHP, and the system shows "The vendor folder is missing" error page.
2. **The whole project goes into the web root.** Use this when the web root
   cannot be changed, or when the web host can only see the web root folder.
   In this case the `.htaccess` in the project root routes every request under
   `public/`, and the other folders are not reachable from outside. This
   requires Apache's `mod_rewrite` module to be enabled and `.htaccess` files
   to be allowed (`AllowOverride All`).

   After installation, check that the URLs `…/composer.json` and
   `…/config/app.php` show Campanella's "Az oldal nem található" ("Page not
   found") page. If you see the contents of `composer.json`, the `.htaccess`
   is not working and the project's files are readable from outside.

Installing into a subdirectory (e.g. `example.hu/campanella/`) also works.

### With Docker

```bash
docker compose up -d --build
docker compose exec web composer install          # if vendor/ is still missing
docker compose exec web php bin/campanella install
docker compose exec web php bin/campanella seed
```

The system is then available at <http://localhost:8080>. Every e-mail it sends
is caught by Mailpit, at <http://localhost:8025> (since 0.1.3), so none reaches
anyone. The `Dockerfile` is
based on the official `php:8.3-apache` image: it installs the `pdo_mysql`,
`gd` (with JPEG, PNG and WebP) and `exif` extensions, enables the
`mod_rewrite` and `mod_headers` modules, raises PHP's upload and memory limits
for images (`docker/php.ini`), creates `var/cache` and `var/backups` for the
web server's user, and sets the web root to the `public/` folder.
After changing the `Dockerfile` or `docker/php.ini`, rebuild:
`docker compose up -d --build`. The database settings come from environment variables in
`compose.yaml` (`CAMPANELLA_DB_HOST`, `CAMPANELLA_DB_NAME`, etc.). Do not create
a `config/local.php` under Docker, because it would override them.

## Upgrading to a new version

```bash
git pull                        # or upload the new package
composer install --no-dev       # if the dependencies changed
php bin/campanella install      # new tables and the pending migrations (after a backup into var/backups/)
php bin/campanella seed         # optional: add the new sample content
```

If the code is newer than the database, the website answers 503 until the
upgrade has run, and the `status` command says what to run. Without a command
line, log in and open `/admin/upgrade`: it does the same, with a backup first
([docs/php-api/17-migrations.md](docs/php-api/17-migrations.md)).
The [CHANGELOG](CHANGELOG.md) lists the changes version by version, and the
extra steps of an upgrade at the top of the version's section. From any 0.2.x or
0.1.x to 0.3.0, follow the [upgrade guide](docs/upgrading.md). An installation
older than 0.0.6 is upgraded to 0.0.7 first, then to the current version.

## Commands

| Command | Description |
|---|---|
| `php bin/campanella install` | Create the tables (safe to run repeatedly) |
| `php bin/campanella install --sql` | Only print the DDL |
| `php bin/campanella seed` | Sample content |
| `php bin/campanella status` | Version, capabilities, Blueprints, object count, and the system checks (exits with `1` on an error) |
| `php bin/campanella user:create <e-mail>` | Creates a user (`--name=`, `--role=administrator,editor`); also `user:password`, `user:list` (`--role=editor`) |
| `php bin/campanella migrate` | Applies the changes of the definitions (new tables, columns, capabilities of Blueprints) and runs the pending migrations after a backup (`--dry-run`, `--yes`, `--no-backup`, `--prune`); `install` does it too |
| `php bin/campanella db:backup` | Saves the database tables into `var/backups/` (`.sql.gz`) |
| `php bin/campanella media:variants` | Makes the smaller copies of images uploaded before 0.1.2 (`--all`: of every image again) |
| `php bin/campanella schema:check` | Compares the table definitions with the database (`--sql`: the statements of the additive changes); changes nothing |
| `php bin/campanella html:sanitize` | Filters the stored HTML texts with the allowlist (`--dry-run`: only lists them) |
| `composer test` | Tests (on a real database, with a separate `test_` table prefix) |
| `tests/upgrade/run.sh <old source>` | The upgrade test: installs an old version (0.1.0, 0.1.5, 0.2.0, 0.2.9) with content, upgrades it to this code, checks that nothing was lost (since 0.2.0; [details](docs/php-api/17-migrations.md#the-upgrade-test)) |
| `tests/performance/run.sh` | The performance check: 10 000 objects, the busiest pages within their limits (since 0.3.0; [details](docs/testing.md#the-performance-check)) |
| `tests/package/check.sh` | The installation package: built as the release builds it, its contents checked, installed from the browser, the browser tests on it (since 0.2.0; [details](docs/testing.md#the-release-package)) |
| `tests/browser/run.sh` | The browser tests: a fresh site in headless Chromium, its logins, sessions, forgotten password, contact form and admin, protections first (since 0.2.0; [details](docs/testing.md#the-browser-tests)) |
| `composer analyse` | PHPStan, level 8, targeting PHP 8.3 |
| `composer docs:check` | Find undocumented public classes and methods |
| `composer docs:links` | Find broken relative links in the markdown files |
| `composer lang:check` | Check that every language file in `lang/` has the same keys |

### Continuous integration

On every push, GitHub runs the checks above
([`.github/workflows/ci.yml`](.github/workflows/ci.yml)): PHPStan, the
documentation checks, `composer audit` (known vulnerabilities of the
dependencies), the tests on MariaDB 10.6 and 11.4, and MySQL 8.0 and 8.4, upgrades from 0.1.0 and 0.1.5, the browser tests, and the installation package as a site owner gets it (all three since 0.2.0; see [Testing](docs/testing.md)). The results are shown next to the commits and on the Actions tab.

After a version tag (`git tag v0.2.0 && git push --tags`), GitHub builds the
installation package and, once the package check has passed on it, attaches
it to the release. For an existing tag it
can also be started manually: Actions → CI → Run workflow, entering the tag.

## Architecture (MVC + service layer)

```
HTTP request → Router → Controller → Service / QueryEngine (Model)
             → Presentation + Twig (View) → HTTP response
```

```
src/
  Core/         Kernel, Container, Config, Version
  Http/         Request, Response, Router
  Controller/   ObjectController (single object), QueryController (list)
  Service/      ObjectService: create, update, publish + access control
  Model/        CampanellaObject, Field, Blueprint, ObjectRepository
  Capability/   The Capability contract and the four core capabilities
  Query/        Query, Conditions, QueryCompiler (SQL), QueryEngine, ResultSet
  Access/       Actor, Operation, AccessPolicy, DefaultPolicy
  Database/     Connection (PDO), Schema, Installer
  View/         Presentation, Twig extension
  Cli/          bin/campanella commands
config/         app.php, blueprints.php, routes.php, queries.php, local.php
templates/      Twig templates (the core look, on Bootstrap 5.3)
themes/         Optional themes that override templates (see docs/php-api/07-http-and-view.md)
lang/           User-facing texts per language (en.php, hu.php)
public/         index.php (single entry point), assets/
```

## Core concepts in the code

**Object.** A single generic class (`CampanellaObject`), with no per-type
subclasses. Data Mapper pattern: the object does not save itself; the
`ObjectRepository` does that.

**Capability.** A class with the `#[AsCapability]` attribute. It declares its
fields and dependencies, and optionally named query filters (scopes) and
pre-save logic. It acts as an adapter on the object:

```php
$object->as(Publishable::class)->publish();
$object->as(Routable::class)->route();     // '/neumann-janos'
```

| Capability | Fields | Storage | Depends on |
|---|---|---|---|
| Titled | title | `cap_titled` | – |
| Textual | body, format | data (JSON) | – |
| Routable | path (unique) | `cap_routable` | Titled |
| Publishable | status, published_at | `cap_publishable` | – |

**Blueprint.** A named bundle of capabilities, defined in configuration
(`config/blueprints.php`). It can also add its own fields; these go into the
data column.

**Storage rule.** The JSON column (`objects.data`) is for storage only. Anything
we filter or sort by goes into the capability's own table. The QueryCompiler
does not even allow filtering on a data field.

**Relation (0.0.2).** A directed, named relation between objects, e.g.
article → categories. Like fields, relations are declared by a Blueprint or a
capability:

```php
$article->relate('categories', $science);
Query::objects()->whereRelated('categories', $science);   // the category's articles
```

Details: [docs/php-api/10-relations.md](docs/php-api/10-relations.md).

**Users (0.0.3).** A user is an object too (`user` Blueprint). Login:
`/login` (configurable: `paths`). The first administrator is created with the `user:create` command;
there is no default account or password. Details: [docs/php-api/11-users.md](docs/php-api/11-users.md).

**Admin UI (0.0.4).** Under `/admin`, for the `administrator` and `editor`
roles: lists with search and filters, forms generated from the field and
relation definitions, publishing (also scheduled), deleting. The interface is
translated (`lang/en.php`, `lang/hu.php`; the `locale` setting) and built on
Bootstrap 5.3, shipped locally. Since 0.1.0 users are managed here too, and
everyone has a profile page for their own name and password.
Details: [docs/php-api/13-admin.md](docs/php-api/13-admin.md).

**HTML editing and images (0.0.5).** Articles and pages have formatted (HTML)
bodies, edited with the Jodit editor shipped locally. Every HTML text is
filtered with an allowlist on save, at the lowest layer, so no script, style
or foreign image reaches the visitors ([docs/php-api/15-html.md](docs/php-api/15-html.md)).
Images are uploaded in the editor or on the Images list: checked by their
content, re-encoded (metadata such as GPS positions is removed) and stored
under random names ([docs/php-api/16-media.md](docs/php-api/16-media.md)).
The administrators' System page shows whether the server meets the
requirements ([docs/php-api/14-system-check.md](docs/php-api/14-system-check.md)).

**Migrations (0.0.6).** An existing database is brought up to date by
`install` (or `migrate`), or from the browser on the upgrade page: what is
additive in the definitions (new tables, columns, indexes, capabilities of a
Blueprint) is applied automatically, everything else by migrations, small PHP
steps that run once, in order, after a backup (`db:backup`, built in, no
`mysqldump` needed). Nothing is guessed: what would need it is reported.
`schema:check` compares the definitions with the database. Details:
[docs/php-api/17-migrations.md](docs/php-api/17-migrations.md).

**Trees and menus (0.0.7).** Objects can be arranged in a tree
(`Hierarchical`, with `Weighted` for a hand-set order): categories under
categories, kept consistent by the repository (no circles, at most 10
levels, a node with children is not deleted). `/kategoriak` shows the
category tree, a category page has breadcrumbs and lists the articles of its
subcategories too. The site's navigation is a menu edited in the admin
(`menu`, `menu_item`); an item leads to an object of the site or to a safe
URL, and an item whose target the visitor may not see is not shown. Details:
[docs/php-api/18-trees.md](docs/php-api/18-trees.md),
[docs/php-api/19-menus.md](docs/php-api/19-menus.md).

**Query.** Declarative and immutable:

```php
Query::objects()
    ->having('textual', 'routable', 'publishable')
    ->scope('published')
    ->orderBy('published_at', 'DESC')
    ->limit(10);
```

**Access-aware queries.** `QueryEngine::execute()` always requires the Actor,
and appends the policy's conditions to the query before it is compiled to SQL.
A draft is not "filtered out" for an anonymous visitor; it is never fetched
from the database at all.

**Scheduled publishing.** An object is visible if it is `published` and its
`published_at` is in the past, so content published with a future date appears
on its own.

## Not included yet

Content types and fields built in the admin, search, redirects and an image
picker (planned for 0.3.0); Component / Region / Layout (0.4.0); modules, the
JSON API and cache (0.5.0); multilingual content and revisions (0.6.0). The
road to 1.0 is in the [ROADMAP](ROADMAP.md#next-the-road-to-10).

## Made with AI assistance

Campanella is developed with the help of artificial intelligence: most of the
code, the tests and the documentation were written by Anthropic's Claude
models (Sonnet and Opus), in conversation with the maintainer. The maintainer
decides what is built and how, reviews and tests every change on his own
server, commits it, and is responsible for the project. The plans and the
decisions behind them are recorded in the [ROADMAP](ROADMAP.md).

## License

MIT. See [LICENSE](LICENSE).

Bundled third-party code: Bootstrap 5.3.8 (MIT,
[public/assets/vendor/bootstrap/LICENSE](public/assets/vendor/bootstrap/LICENSE)),
Jodit 4.17.1 (MIT, [public/assets/vendor/jodit/LICENSE.txt](public/assets/vendor/jodit/LICENSE.txt)).
