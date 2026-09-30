# Changelog

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

Sections: **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**,
**Security**. In 0.x versions, items under **Changed** and **Removed** may be
backward-incompatible.

## [Unreleased]

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

### Changed

- Documentation, code comments and developer-facing messages are now in
  English. User-facing texts (UI, CLI output, validation messages) are still
  Hungarian; a translation layer is planned for 0.0.4.
- The `docs/php-api` chapter files have English names (e.g.
  `11-felhasznalok.md` → `11-users.md`).

### Fixed

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
