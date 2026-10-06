# Changelog

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

Sections: **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**,
**Security**. In 0.x versions, items under **Changed** and **Removed** may be
backward-incompatible.

## [Unreleased]

Upgrading: `php bin/campanella install` (schema version 6, the new
`cc_migrations` table).

### Added

- **Reading and comparing the database schema.** `SchemaReader` reads the
  actual tables from `information_schema` (columns, indexes, foreign keys),
  evening out the differences of MariaDB and MySQL. `SchemaComparator` lists
  where they differ from the definitions: missing or extra tables, columns
  and indexes, a different type, NULL setting, default or primary key, a
  missing foreign key. Additive differences (a missing table, a column that
  may be NULL or has a default, an index) come with the statement that
  applies them; the others need a migration. `Installer::differences()`.
- `php bin/campanella schema:check [--sql]`: lists the differences, or prints
  the statements of the additive ones (those that would lose data only as
  comments). Changes nothing.
- **Migrations.** A migration is a small PHP class (`Migration`: `id()`,
  `description()`, `up(MigrationContext $m)`), forward only. The
  `MigrationContext` works on SQL and checks the state first (`addColumn()`,
  `dropColumn()`, `renameColumn()`, `addIndex()`, `dropIndex()`,
  `createTable()`, batched `eachRow()`), so a migration that failed halfway can
  run again. The `Migrator` runs the pending ones under a database lock and
  records each in the new `cc_migrations` table; a failure stops the run with
  the earlier ones recorded. A fresh installation records every migration as
  applied. Campanella's own come from `CoreMigrations`, a site's from the new
  `migrations` setting.
- `php bin/campanella migrate [--dry-run] [--yes] [--no-backup]`: lists the
  pending migrations, asks, makes a backup and runs them. `install` runs them
  too on an existing installation.
- `php bin/campanella db:backup [--plain]` and `DatabaseBackup`: the tables as
  an SQL file in `var/backups/` (`.sql.gz`), written through PDO, so no
  `mysqldump` is needed; readable by its owner only.
- System page and `status`: the migrations applied, an error while one is
  pending.
- System page: a *Database tables* group with the differences (or that the
  tables match their definitions).
- `SchemaBuilder` generates `ALTER TABLE` statements (`addColumnSql()`,
  `addIndexSql()`, `dropColumnSql()`, `dropIndexSql()`), in the forms both
  MariaDB and MySQL accept, and is now public.

## [0.0.5] – 2026-10-05

HTML editing: the System page, an HTML filter applied on every save, the Jodit
editor, and image upload.

**Upgrading from 0.0.4:**

1. Upload the new files, including the `vendor/` folder (it has new
   dependencies), or run `composer install --no-dev`. The installation
   package on the releases page contains `vendor/`.
2. `php bin/campanella install`: schema version 5 (new `cap_media_file`
   table). Until then the site shows the "needs upgrade" page.
3. `php bin/campanella html:sanitize`, once: filters the HTML texts stored by
   earlier versions (`--dry-run` lists them first).
4. For image upload: the `gd` extension with JPEG, PNG and WebP, and PHP
   limits that allow the images (`upload_max_filesize`, `post_max_size`); the
   System page shows what is missing. The `public/media/` folder must be
   writable by the web server.

### Added

- **System page** in the admin (`/admin/system`, "System" menu), for the
  `administrator` role only (`admin.system_roles` setting): versions (PHP,
  database server, schema), required and recommended PHP extensions, writable
  folders, settings (a warning for debug mode and for plain HTTP), PHP limits,
  the opcache and the template cache, each with a verdict and what to do. A
  "Clear the template cache" button. The dashboard shows a warning bar if a
  requirement is not met. Never shows secrets. New `Campanella\System`
  namespace: `SystemCheck` (other features can add their own checks),
  `CheckResult`, `CheckStatus`, `TemplateCache`; `AdminAccess::allowsSystem()`.
- `php bin/campanella status` runs the same checks (without the web server's
  settings).
- **HTML sanitizer.** A `Textual` body in `html` format is filtered with an
  allowlist on every save (`ObjectRepository::save()`, so the admin, the CLI
  and the seed cannot bypass it): paragraphs, h2–h4, emphasis, lists, quotes,
  code, links (`http`, `https`, `mailto`, relative; with
  `rel="noopener noreferrer"`), images from this site only, simple tables.
  Scripts, styles, frames, forms, `style`/`class`/`on…` attributes and
  `javascript:` links are removed. Configurable in `config/html.php`. Built on
  `symfony/html-sanitizer` 7.x (MIT). New `Campanella\Html\HtmlSanitizer`;
  a text over 1 MB or 20 000 tags, or not valid UTF-8, is rejected with a
  validation message instead of being filtered.
- **HTML editor** in the admin: a body in `html` format is edited with Jodit
  4.17.1 (MIT edition, shipped in `public/assets/vendor/jodit/`, no CDN), which
  gets the filter's allowlist and cleans pasted content (e.g. from Word) the
  same way. Toolbar profiles per field (Blueprint `editor` key: `full`,
  `basic`). Nothing is loaded from other servers. Without JavaScript a
  textarea with the raw HTML remains. Replaceable: `admin-editor.js` and the
  `html` widget template.
- New articles and pages get a formatted (HTML) body: new Blueprint key
  `defaults` (initial values of a new object). The sample content (`seed`) is
  HTML too.
- "Convert to formatted text" button for a saved plain body
  (`POST /admin/<blueprint>/<id>/convert-html`; paragraphs and line breaks
  are kept). New `Campanella\Html\PlainText::toHtml()`.
- The admin pages send a Content-Security-Policy: only this site's scripts,
  no inline scripts.
- **Images, server side.** An uploaded image becomes an object of the new
  `image` Blueprint (title, alternative text, author) with the new `MediaFile`
  capability (file path, type, size, dimensions, SHA-256 checksum; own table
  `cap_media_file`). `MediaService::uploadImage()` checks the access, the
  size, the type from the content (JPEG, PNG, WebP, GIF; no SVG) and the
  dimensions and structure before decoding (including JPEGs crafted to keep
  the decoder busy), re-encodes the image with GD (metadata such as
  GPS positions and anything hidden in the file are removed, EXIF orientation
  applied, scaled down to 2560 px), and stores it under a random name in
  `public/media/YYYY/MM/`, where a `.htaccess` forbids running code. Strict:
  a type the server cannot re-encode (e.g. gd built without WebP) is refused,
  so no image is stored with its metadata (`media.store_unprocessed` turns
  this off). The system page lists gd's formats and the uploadable types. Deleting
  the object deletes the file. New `media` settings. The admin lists and
  edits images.
- **Uploading images from the admin:** an image button in the editor's
  `full` toolbar, and pasted or dropped images are uploaded too, to
  `POST /admin/media/upload` (JSON; CSRF; errors in the user's language,
  e.g. for a file too large for the server's limits, or an expired session).
  Images are never kept in the text as data: pasted base64 images are
  uploaded; images from other sites are removed in the editor at once, and an
  `<img>` left without an address is dropped on save. New `Request::$files` / `file()` and
  `UploadedFile` (only real PHP uploads are accepted), `Response::json()`,
  `MediaService::maxUploadBytes()`. Images in texts stay within the column
  (`.body img` in `campanella.css`).
- **The Images list:** thumbnails, type, size and dimensions (sortable by
  size), a reminder where the alternative text is missing, and an upload form
  on top: several files at once, chosen or dropped (`admin-upload.js`), each
  refusal reported by file name; without JavaScript one file, and the server
  redirects back with a message. The edit page shows the image, its data and
  its address beside the form; the delete page warns that texts showing the
  image will miss it. New Twig filter `file_size` (`1,5 MB` in Hungarian).
- System page, *Images* group: whether PHP's `upload_max_filesize` and
  `post_max_size` allow `media.max_bytes`, and whether `memory_limit` allows
  `media.max_pixels` (`ImageProcessor::maxPixelsSetting()`).
- Docker image: `gd` (with JPEG, PNG and WebP) and `exif`, `mod_headers`
  (the media folder's `.htaccess` sets its `nosniff` and sandbox headers with
  it), and `docker/php.ini` with the limits images need
  (`upload_max_filesize = 16M`, `post_max_size = 20M`, `memory_limit = 256M`,
  `expose_php = Off`). Rebuild after upgrading: `docker compose up -d --build`.
- `ObjectService::addListener()` and `ObjectListener` (`afterDelete()`): a
  minimal hook until the Event / Action system.
- `php bin/campanella html:sanitize [--dry-run]`: filters the HTML texts
  stored before. **Run it once after upgrading** (texts stored by earlier
  versions were not filtered).
- "Create and publish" button on the new-object form of a `Publishable`
  Blueprint, for users who may publish.

### Changed

- The compiled templates are kept in a folder per version
  (`var/cache/twig/<version>`): uploading a new release never serves stale
  templates, even if the upload tool kept the files' old modification times.
  The old `var/cache/twig` contents can be deleted (the System page's button
  does it).
- `php bin/campanella status` exits with `1` if a check reports an error
  (e.g. a missing PHP extension, or the database is not installed yet).
- Schema version 5 (new `cap_media_file` table): after upgrading, run
  `php bin/campanella install`.
- New dependencies: `symfony/html-sanitizer` and its dependencies
  (`masterminds/html5`, `league/uri`, `league/uri-interfaces`,
  `psr/http-message`, `psr/http-factory`, all MIT), and the `dom` PHP
  extension (required; part of standard PHP builds).
- **New articles and pages are HTML by default** (Blueprint `defaults` in
  `config/blueprints.php`). Code that creates them with a plain text body must
  now say so (`'format' => 'plain'`), or convert the text with
  `PlainText::toHtml()`; otherwise the text is stored as HTML (filtered, and
  its line breaks are not shown). Existing objects keep their format.
- The light/dark color mode script is a file (`public/assets/theme.js`)
  instead of an inline script, in the admin and the public templates.
- `Cli\Output` takes an optional error stream (`$errors`, default `STDERR`).
- `system` and `media` are reserved: no Blueprint can have these names
  (`BlueprintRegistry::RESERVED_NAMES`).
- `POST /admin/media/upload` answers in JSON only to requests with
  `Accept: application/json` (as the editor sends them); other requests get a
  redirect to the Images list. `MediaService::maxUploadBytes()` keeps 64 KB of
  `post_max_size` for the rest of the form (`MediaService::FORM_MARGIN`).

## [0.0.4] – 2026-10-02

### Added

- `.gitattributes`: all text files use LF line endings, on Windows too.
- Continuous integration with GitHub Actions (`.github/workflows/ci.yml`):
  PHPStan, `docs:check`, `docs:links` and the tests on every push, on MariaDB
  10.6, 11.4, MySQL 8.0 and 8.4 (PHP 8.3; on MariaDB 11.4 also PHP 8.4).
- On a version tag (`v*`), an installation package is built including the
  `vendor/` folder (`campanella-<version>.zip` and SHA-256) and attached to the
  GitHub release; the release notes are the matching section of the CHANGELOG.
  If the tag and `Version::CAMPANELLA` do not match, the release is aborted.
- `composer docs:links`: checks that the relative links in the markdown files
  point to existing files and that their `#anchors` point to existing headings.
- **Multi-valued fields (cardinality).** `Field` has a new `cardinality`
  parameter: `1` (the default; existing fields are unchanged), an upper limit
  (e.g. `3`), or `Field::UNLIMITED`. The value of a multi-valued field is
  always a list; `required` means at least one value, and more values than the
  limit are rejected on save (`legfeljebb 3 érték adható meg`).
- Queryable multi-valued fields are stored in the new `cc_field_values` table,
  one row per value, in order; `Data` ones in the `data` JSON column as a list.
  A condition on a multi-valued field matches if any of its values matches
  (`EXISTS` subquery; `!=` and `NOT IN`: the object has the field and none of
  its values matches; `IS NULL`: no value); sorting on one is rejected with
  `QueryException`. Items of a multi-valued `String` field are checked against
  the field's `length` on save.
- A Blueprint can narrow the limit of a capability's multi-valued field:
  `'cardinality' => ['phones' => 2]`.
- `Relation` has a new `max` parameter for Many relations (at most this many
  targets; checked on save: `legfeljebb 2 kapcsolat adható meg`).
- **Translation layer, part 1.** User-facing texts are referenced by key and
  live in `lang/en.php` and `lang/hu.php` (English is the base language and
  the fallback). New `Campanella\I18n\Translator` service, `t()` and
  `locale()` Twig functions, `locale` setting (`CAMPANELLA_LOCALE`, default
  `hu`), and `composer lang:check`. The templates, the login messages and the
  error pages use it.
- **Translation layer, part 2.** Validation messages and the command-line
  output are translated too (`validation.` and `cli.` keys). New
  `Campanella\I18n\Message` (an untranslated key with parameters).
- **Bootstrap 5.3** (5.3.8, MIT), shipped in `public/assets/vendor/bootstrap/`
  (no CDN, no jQuery). The core templates are rebuilt on it: a responsive
  navigation bar that collapses on mobile, a card-style login form, Bootstrap
  pagination and alerts. The default look (`campanella.css`) is set through
  Bootstrap's CSS variables; light and dark mode follow the system setting.
  `base.html.twig` has new `stylesheets` and `scripts` blocks.
- **Themes.** A folder `themes/<name>/templates/` overrides core templates
  one by one, its assets live in `public/themes/<name>/`; chosen with the
  `theme` setting (`CAMPANELLA_THEME`). Core templates are reachable as
  `@core/…` to extend them. New `Campanella\View\Theme` class and
  `theme_asset()` Twig function.
- **Admin UI, part 1** (`/admin`): access for the `administrator` and
  `editor` roles (`admin.path`, `admin.roles` settings; others get a 403, the
  anonymous are sent to the login page), a Bootstrap layout independent of the
  public theme, and a dashboard (objects per Blueprint, recently modified). An
  "Admin" link appears in the public header for users who may enter. New
  `AdminAccess`, `AdminController`, `Flash` (one-time messages),
  `Router::prefix()`, and the `admin_url()`, `admin_access()`,
  `flash_messages()` Twig functions.
- **Admin UI, part 2:** the list of a Blueprint's objects
  (`/admin/<blueprint>`), with title search, status filter (draft,
  published, scheduled), sortable columns and pagination; the sidebar lists
  the content Blueprints. After logging in, the user returns to the requested
  admin page.
- **Admin UI, part 3:** creating and editing objects (`/admin/<blueprint>/new`,
  `/admin/<blueprint>/<id>`) with forms generated from the field and relation
  definitions: a widget per field type (separate templates), multi-valued
  fields with add/remove/reorder buttons (`admin.js`, no dependencies),
  relations as a drop-down or checkboxes, errors at their fields, a redirect
  with a one-time message after saving, and a warning instead of silently
  overwriting when someone else saved the object in the meantime. New
  Blueprint key `form_order`. HTML text stays read-only until 0.0.5.
- **Admin UI, part 4:** publishing, unpublishing and deleting. The edit page
  of a `Publishable` object has a publication panel: publish now or at a given
  time (a future time schedules it), change the time, unpublish; with the CSRF
  and version checks of saving. Deleting goes through a confirmation page that
  lists the objects referring to it (marking those whose required relation
  would be left empty). The buttons follow the `AccessPolicy`: with the
  `DefaultPolicy` an `editor` may publish but not delete. New
  `ObjectForm::parseDateTime()`, `localDateTime()` and `timezone()`.
- `ObjectService::create()` and `update()` accept the relations to set
  (`$relations`, name → target IDs).
- `Router::isRouted()`; paths used by the system (fixed routes, the admin,
  `/assets`, `/themes`) cannot be given to an object in the admin.
- A single-valued `String` value longer than the field's `length` is a
  validation error (`validation.value_too_long`) instead of a database error.
- `php bin/campanella status` marks multi-valued fields with their limit
  (`phones[3]`, `tags[*]`).

### Changed

- Documentation, code comments and developer-facing messages are now in
  English. User-facing texts (UI, CLI output, validation messages) go through
  the new translation layer: English base, full Hungarian translation, `hu` by
  default.
- The `docs/php-api` chapter files have English names (e.g.
  `11-felhasznalok.md` → `11-users.md`).
- Schema version 4 (new `cc_field_values` table): after upgrading, run
  `php bin/campanella install`.
- `AuthService::GENERIC_ERROR`, the `LoginResult` error and the
  `LoginGuard::check()` result are message keys (e.g.
  `auth.invalid_credentials`); `LoginResult` has a new `$errorParams`. A guard
  may still return a ready-made text: an unknown key is shown as it is.
- `HttpException::notFound()`'s default message is the key `error.not_found`.
- The `site.language` setting is replaced by `locale`.
- The labels of capabilities, fields, relations, Blueprints and Blueprint
  lists are message keys (e.g. `field.title`, `blueprint.article`), translated
  where shown. Custom labels that are not keys are shown as they are.
- `ValidationException::$errors` holds `Message` objects instead of Hungarian
  texts; `messages(Translator)` returns the texts, and `getMessage()` is an
  English summary with the keys. `Capability::validate()` returns `Message`
  objects (or key strings). `Command::description()` returns a message key.
- The messages shown before the system is loaded (PHP version too old,
  `vendor` folder missing) are in English.
- `CapabilityDefinition::tableFields()` returns only the single-valued `Table`
  fields; the multi-valued ones are returned by the new `valueTableFields()`.

### Fixed

- After an upgrade, the template cache (`var/cache/twig`) kept serving the
  previously compiled templates when `debug` was off, so template changes did
  not appear until the cache was cleared. Twig now always checks whether a
  template file changed (`auto_reload`).

- The hidden honeypot field ("Weboldal") of the login form was visible if the
  browser had the 0.0.2 `campanella.css` cached. The field is now hidden by an
  inline style, and `asset()` also appends the version number to the URL
  (`?v=0.0.3`), so the new CSS is loaded after an upgrade.

## [0.0.3] – 2026-09-29

Users and login. Campanella is licensed under MIT.

### Added

- `LICENSE` (MIT) and `"license": "MIT"` in `composer.json`.
- Capabilities: `Identifiable` (unique, normalized e-mail address),
  `Authenticatable` (password hash, account status, roles), `Authorable`
  (`author` relation; for new content the creator becomes the author).
- `user` Blueprint; articles now have `Authorable`.
- `AuthService` and `LoginResult`: login with a uniform error message and
  running time, login throttling, handling of blocked accounts, and automatic
  password rehashing.
- `LoginGuard` extension point for protections that run before password
  verification; built-in `HoneypotGuard`.
- `Session` with lazy start and an idle timeout; `SessionStorage`,
  `NativeSessionStorage` (HttpOnly, SameSite=Lax, Secure over HTTPS, strict mode),
  `ArraySessionStorage` (for tests).
- `Csrf` (Twig: `csrf_field()`), `Throttle` and the `cc_throttle` table.
- `AuthController`: `/belepes`, `/kilepes`; protection against open redirects.
- `DefaultPolicy`: `editor` role.
- Twig: `current_user()`; login and logout in the header.
- `Field::$hidden`: fields not accessible from templates (password hash, e-mail address).
- `FieldType::StringList`; `Capability::validate()` hook.
- `Request`: `$cookies`, `$ip`, `$secure`, `isPost()`, `postString()`,
  `queryString()`; `Response::withHeader()`; security headers
  (`X-Frame-Options`, `Referrer-Policy`).
- CLI: `user:create`, `user:password` (also blocking/unblocking), `user:list`.
- Documentation: [11. Users and login](docs/php-api/11-users.md).

### Changed

- Schema version: `3` (new table: `cc_throttle`). After upgrading, run `php bin/campanella install`.
- Responses to requests with a session get a `Cache-Control: private, no-store`
  header.
- `CampanellaTwigExtension::__construct()` has two new optional parameters.
  **Internal** class.

### Security

- There is no default account or password; the first administrator is created
  with the `user:create` command.

## [0.0.2] – 2026-09-28

Relations (relationships) between objects.

### Added

- `Relation` and `Cardinality` (`Campanella\Relation`): definition of named,
  directed relations with cardinality, target constraints (Blueprint,
  capability) and a required flag.
- Relations can be declared by a capability's `relations()` method or by a
  Blueprint's `relations` key.
- `CampanellaObject`: `relations()`, `hasRelation()`, `relatedIds()`,
  `setRelated()`, `relate()`, `unrelate()`, `relatedObjects()`, `isResolved()`.
- `cc_relationships` table; validation on save (target exists and matches,
  required relations, no self-references), cascade on delete.
- `Query::whereRelated()`, `Query::whereNotRelated()` and the `RelatedTo` condition.
- `RelationLoader`: loads related objects with a single access-aware query;
  the controllers use it automatically.
- Blueprint `lists`: lists on the object's own page (e.g. the articles of a category).
- Twig: `related(object, 'name')` function and the `object/_relations.html.twig` partial.
- Example: `category` Blueprint, the `categories` relation of articles, the
  `/kategoriak` page, and category pages with their articles.
- `Installer::needsUpgrade()`; the `status` command and the website (503)
  report when the database schema is older than the code.
- `seed` can be run repeatedly: it does not recreate existing content, and it
  adds missing examples (e.g. categories).
- Documentation: the [10. Relations](docs/php-api/10-relations.md) chapter.

### Changed

- Schema version: `2` (new table). After upgrading, run `php bin/campanella install`.
- The second, optional parameter of `QueryCompiler::__construct()` is the
  `BlueprintRegistry` (for relation conditions). **Internal** class.
- New parameters of `ObjectController::__construct()`: `BlueprintRegistry`,
  `RelationLoader`; `QueryController::__construct()` has a new optional
  `RelationLoader` parameter.
- `CampanellaObject::__construct()` and `Blueprint` have new optional
  parameters (relations, lists).

### Earlier unreleased changes (after 0.0.1)

- Developer documentation in the `docs/` folder: PHP API reference in 9
  chapters, HTTP API (the HTML interface and the planned JSON API).
- `composer docs:check`: reports undocumented public classes and methods.
- `Dockerfile` and `compose.yaml` (PHP 8.3 + Apache + MariaDB).
- Database settings can also be given as environment variables
  (`CAMPANELLA_DB_*`, `CAMPANELLA_DEBUG`); the project root via the
  `CAMPANELLA_ROOT` variable.
- A clear error page if the `vendor/` folder is missing.

#### Changed

- The second parameter of `CampanellaTwigExtension::__construct()` is now
  `Closure(): string` instead of `string` (the URL prefix of the current
  request). **Internal** class.

#### Fixed

- `Kernel::handle()` no longer rebuilds the container on every request, so
  services overridden in the container (e.g. a custom `AccessPolicy`) are kept.
- On a connection error, the `status` command reports the actual error instead
  of "not installed" (`Connection::tableExists()` throws on connection errors).
- If the Twig cache folder is not writable, the system keeps running without
  a cache.
- The built-in development server (`php -S`) serves static files.

## [0.0.1] – 2026-09-25

The first, minimal core.

### Added

- Generic object model: `CampanellaObject`, `Field`, `FieldType`,
  `FieldStorage`.
- Capability contract (`Capability`, `#[AsCapability]`,
  `CapabilityRegistry`) and four built-in capabilities: `Titled`, `Textual`,
  `Routable`, `Publishable` (with scheduled publishing).
- Blueprints from configuration (`config/blueprints.php`).
- `ObjectRepository` (Data Mapper, batch loading) and `ObjectService`.
- Declarative, immutable `Query`, condition AST, scopes,
  `QueryCompiler`, access-aware `QueryEngine`, `ResultSet`.
- Access control: `Actor`, `Operation`, `AccessPolicy`, `DefaultPolicy`.
- HTTP: `Request`, `Response`, `Router`, `ObjectController`, `QueryController`.
- Presentation: `Presentation`, Twig templates, `CampanellaTwigExtension`.
- Database: `Connection`, schema descriptors, `Installer` (MariaDB 10.6+ / MySQL 8.0+).
- CLI: `install`, `seed`, `status`.
