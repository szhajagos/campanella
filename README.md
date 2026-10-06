# Campanella 0.0.5

[![CI](https://github.com/szhajagos/campanella/actions/workflows/ci.yml/badge.svg)](https://github.com/szhajagos/campanella/actions/workflows/ci.yml)

A capability-driven CMS. There are no predefined content types: an object's
behavior is determined by the capabilities attached to it.

The developer documentation (PHP API, HTTP API) is in the [`docs/`](docs/README.md)
folder, changes are listed in the [CHANGELOG](CHANGELOG.md), and plans in the
[ROADMAP](ROADMAP.md).

## Requirements

- PHP 8.3 or newer (`pdo_mysql`, `mbstring`, `json`, `dom`; recommended: `gd` with JPEG, PNG and WebP for images, `exif`, `fileinfo`, `opcache`)
- MariaDB 10.6+ or MySQL 8.0+ (InnoDB, utf8mb4)
- Composer (to download the dependencies; you can also upload the project to your web host together with the `vendor/` folder)

PHP dependencies: Twig and symfony/html-sanitizer (with their dependencies,
all MIT or BSD). Bootstrap 5.3 and the Jodit editor (CSS and JavaScript) are
shipped in `public/assets/vendor/`. PHPStan is needed for development only.

## Installation

**Without Composer:** every version on the [GitHub releases](https://github.com/szhajagos/campanella/releases)
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

If your web host has no command line, you can run the output of
`php bin/campanella install --sql` in phpMyAdmin instead.

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

The system is then available at <http://localhost:8080>. The `Dockerfile` is
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

If the code is newer than the database schema, the website shows a 503 page,
and the `status` command reports in text that `install` needs to be run.
The [CHANGELOG](CHANGELOG.md) lists the changes version by version, and the
extra steps of an upgrade at the top of the version's section (for 0.0.5:
`php bin/campanella html:sanitize`, once).

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
| `php bin/campanella schema:check` | Compares the table definitions with the database (`--sql`: the statements of the additive changes); changes nothing |
| `php bin/campanella html:sanitize` | Filters the stored HTML texts with the allowlist (`--dry-run`: only lists them) |
| `composer test` | Tests (on a real database, with a separate `test_` table prefix) |
| `composer analyse` | PHPStan, level 8, targeting PHP 8.3 |
| `composer docs:check` | Find undocumented public classes and methods |
| `composer docs:links` | Find broken relative links in the markdown files |
| `composer lang:check` | Check that every language file in `lang/` has the same keys |

### Continuous integration

On every push, GitHub runs the checks above
([`.github/workflows/ci.yml`](.github/workflows/ci.yml)): PHPStan, the
documentation checks, and the tests on MariaDB 10.6 and 11.4, and MySQL 8.0
and 8.4. The results are shown next to the commits and on the Actions tab.

After a version tag (`git tag v0.0.5 && git push --tags`), GitHub builds the
installation package and attaches it to the release. For an existing tag it
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
`/belepes`. The first administrator is created with the `user:create` command;
there is no default account or password. Details: [docs/php-api/11-users.md](docs/php-api/11-users.md).

**Admin UI (0.0.4).** Under `/admin`, for the `administrator` and `editor`
roles: lists with search and filters, forms generated from the field and
relation definitions, publishing (also scheduled), deleting. The interface is
translated (`lang/en.php`, `lang/hu.php`; the `locale` setting) and built on
Bootstrap 5.3, shipped locally. Users are still managed from the command line.
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

User management in the browser, Hierarchical (menu, taxonomy tree),
Component / Region / Layout, Webform, Event / Action, cache, migrations
(altering existing tables), multilingual content, image variants and an image
picker.

## License

MIT. See [LICENSE](LICENSE).

Bundled third-party code: Bootstrap 5.3.8 (MIT,
[public/assets/vendor/bootstrap/LICENSE](public/assets/vendor/bootstrap/LICENSE)),
Jodit 4.17.1 (MIT, [public/assets/vendor/jodit/LICENSE.txt](public/assets/vendor/jodit/LICENSE.txt)).
