# Changelog

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

Sections: **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**,
**Security**. In 0.x versions, items under **Changed** and **Removed** may be
backward-incompatible.

## [Unreleased]

### Added

- The dashboard's content types link to their lists, and the recently
  modified objects to their forms (for those who may edit them).
- **Behind a proxy:** the `trusted_proxies` setting (IP addresses or CIDR
  ranges, empty by default). From a listed proxy, `X-Forwarded-Proto` marks
  the request HTTPS (so the login cookie gets `Secure`) and `X-Forwarded-For`
  gives the visitor's address (for login throttling); from anyone else the
  headers are ignored. `TrustedProxies`, `Request::withClient()`.
- **Security headers on the public site too:** a strict
  `Content-Security-Policy` (only the site's own scripts, styles and images,
  no inline script) and `Permissions-Policy`; HSTS as an opt-in setting
  (`security.hsts`). A theme can replace the policy with the
  `security.content_security_policy` setting, documented with its risk.
  `SecurityHeaders`.
- **System page:** a *Public server* group: the web root (`public/` or the
  whole project); a proxy in front of the site, with the connecting address
  and the names of the proxy headers that arrived, so the right
  `trusted_proxies` entry can be read off; a replaced policy; HSTS.
- **Installing from the browser** (`/install`), for web hosts without a
  command line: the requirements, the tables, the first administrator, and
  optionally the sample content. Only with an install key set in
  `config/local.php` (`install.key`, at least 20 characters), so nobody else
  can install a freshly uploaded site; wrong keys are limited
  (`FileThrottle`). Open only while there is no user (also after
  `install --sql` in phpMyAdmin), then 404; the System page warns while the
  key is set. `InstallController`, `Installer::userCount()`.
- **Users in the browser:** administrators list, create and edit users
  (name, e-mail address, roles, status) and set new passwords; everyone
  logged in has a profile page (own name, own password with the current one).
  The last active administrator cannot be blocked or lose the role, nobody
  can block themselves, and users are blocked rather than deleted. Editors
  see only their profile. `UserService`, `/admin/user`, `/admin/profile`.
- **A changed password ends the user's other sessions:** the session holds a
  stamp of the password hash, checked on every request
  (`AuthService::refresh()` keeps the current one after changing one's own).
- **Blueprints and capabilities in the admin** (read-only, under System):
  each Blueprint with its capabilities, own fields, relations, tree scope,
  lists and number of objects; each capability with a short explanation, its
  table, dependencies, scopes, fields, relations and the Blueprints that use
  it. `StructurePages`; `capability.<name>.description` in the language files.
- **Deployment guide** (`docs/deployment.md`): the web root (Apache and nginx
  examples), HTTPS and proxies, settings and security headers, file
  permissions, PHP settings, backups, upgrades, and a checklist before going
  live.

### Changed

- `profile` is a reserved Blueprint name (`/admin/profile`).
- A site that is not installed yet points to `/install` besides the
  `install` command.
- The login form's honeypot field is hidden with the `hidden` attribute
  instead of an inline style, which the new policy forbids.
- The System page breaks long values (e.g. a folder path), so the status
  stays in view.
- **Upgrading is possible from 0.0.6 or later only.** An older installation
  is upgraded to 0.0.7 first: `install`, `migrate` and the upgrade page stop
  with an explanation before changing anything (`Version::MIN_UPGRADE_SCHEMA`,
  `Installer::tooOld()`).
- `config/local.php.dist` no longer turns debug mode on: a copied file is safe
  on a public server.

### Security

The security review of 0.1.0 ([docs/security.md](docs/security.md)): no
critical or high issue; fixed:

- **IPv4-mapped addresses** (`::ffff:1.2.3.4`, on some dual-stack servers)
  are read as plain IPv4: before, all such visitors shared one login-throttle
  bucket, and a proxy listed as `127.0.0.1` was not trusted.
  `Request::normalizeIp()`.
- **Templates run in a sandbox** (`TemplatePolicy`): they cannot read hidden
  fields through `get()`, `values()` or `as()`. A user's `roles` and
  `account_status` are hidden fields.
- **Login throttling:** every attempt is counted atomically before the
  password check, also per account (30 in 15 minutes from any address,
  `max_attempts_per_account`) and per address; unknown addresses have their
  own account counter, so a locked account cannot be told from a
  non-existent one; IPv6 addresses count by /64. The throttle's count is one
  atomic statement.
- **Install and upgrade keys:** wrong keys are limited from all addresses
  together too (50 in 15 minutes); the upgrade page shows its details only to
  an allowed request.
- **Paths and links:** a site path may not contain backslashes, whitespace,
  control characters, `?` or `#` (`Routable::isSafePath()`); `url()` encodes
  backslashes and control characters; a menu item whose target path is
  unsafe is left out.
- One's own password only on the profile, with the current one.
- A huge `page` number gives an empty page (404), not an error.
- The root `.htaccess` denies everything without `mod_rewrite` and never
  serves dot files; `var/` has its own deny.
- An early error page gets the security headers too; a category page lists at
  most 500 children.
- `composer audit` runs in CI.

### Removed

- The `FieldType::StringList` type (deprecated in 0.0.6): a list of values is
  a multi-valued field. The admin's `list` widget went with it.
- The upgrade page no longer reads the users' old roles column (needed only
  while upgrading 0.0.5), nor does `Installer::legacyValue()` exist.

## [0.0.7] – 2026-10-07

Trees and menus: objects arranged in a tree with a hand-set order, the
category tree on the site, and the navigation edited in the admin.

**Upgrading from 0.0.6:**

1. Upload the new files (the dependencies did not change).
2. Run `php bin/campanella install` (or open `/admin/upgrade`). No migration
   is needed: the new capabilities and Blueprints are added automatically,
   the existing categories become roots of the category tree.
3. Create the main menu: `php bin/campanella seed` (it also adds the missing
   sample content), or in the admin: *Menu* → *New*, machine name `main`, then
   its items. Until there is a `main` menu, the site keeps its built-in links.

### Added

- **`Weighted`** is a built-in capability (until now only the documentation's
  example): a `weight` field and the `by_weight` scope (equal weights in the
  order of creation). In the admin, the list of a Blueprint with it is shown
  in this order by default, with a *Weight* column.

- **`Hierarchical`:** objects in a tree. A `parent` relation within the same
  Blueprint; the materialized path (`tree_path`, `/1/5/12/`) and the `depth`
  are kept by the `ObjectRepository` on save, and moving a node moves its
  subtree with one `UPDATE`. The repository refuses a parent of another
  Blueprint, a circle (a node under its own descendant), more than 10 levels,
  and deleting a node that has children. Queries: `roots()`, `childrenOf()`,
  `descendantsOf()`, `ancestorsOf()`; `TreeBuilder` builds the tree from a
  query's result. New `Campanella\Tree` namespace.

- **Trees in the admin:** a `Hierarchical` Blueprint is listed as a tree
  (indented, the siblings by weight), with a "+ sub-item" link that preselects
  the parent; the parent is chosen from an indented list without the object's
  own descendants; ↑ ↓ buttons move a `Weighted` object among its siblings
  (`SiblingOrder`, `POST …/move-up`, `…/move-down`); the delete page lists a
  node's children. A single relation can be preselected in the new form's
  address (`?parent=12`).
- **Categories form a tree:** the `category` Blueprint has `Hierarchical` and
  `Weighted`. After upgrading, `install` (or the upgrade page) adds them to
  the existing categories, which become roots.
- **The category tree on the site:** `/kategoriak` shows the categories as a
  nested list, in their hand-set order; a category page has breadcrumbs
  (home, the ancestors, the category) and lists its subcategories; **a
  category's articles include those of its subcategories**
  (`Hierarchical::relatedWithin()`, the new `subtree` of `RelatedTo`). A
  draft category's branch stays hidden from visitors.
- The **`tree()`** Twig function: a query's result as a tree of `TreeNode`s.
  `TreeBuilder::build()` got a `$keepOrphans` parameter.
- **Menus:** the site's navigation is edited in the admin. A `menu`
  Blueprint (with a machine name, e.g. `main`) and a `menu_item` Blueprint:
  in one menu, under another item of the same menu, in a hand-set order,
  leading to an object of the site (its path followed when it changes) or to
  a URL. An item whose target the visitor may not see (e.g. a draft) is not
  shown, nor are the items below it. In templates: `menu('main')`
  (`MenuBuilder`, `MenuEntry`). The layout shows the main menu, with the
  second level as a dropdown and the current item marked with
  `aria-current`; until a `main` menu exists, it keeps the built-in links.
  `seed` creates the main menu. New `Campanella\Menu` namespace.
- **Only safe link addresses:** a menu item's URL may be a path of the site,
  a fragment, an `http(s)://` address, `mailto:` or `tel:`; `javascript:`,
  `data:` and the like are refused on save (`Link::isSafeUrl()`).
- New capabilities: **`Keyed`** (a unique machine name) and **`Link`** (an
  object or a URL).
- **Separate trees (`tree_scope`):** a Hierarchical Blueprint can name a
  required relation that splits its objects into separate trees (each menu
  its own). The parent must be in the same scope, moving a node to another
  scope moves its subtree too, the top items are ordered within their scope,
  and a scope object with items (a menu) cannot be deleted. In the admin the
  list is grouped or filtered by it, and the menu's page lists its items with
  a "New menu item" button.

### Changed

- The documentation's example of writing a capability is now `Featured`.
- The admin lists break ties by ID, so equal values keep a stable order.
- The admin's delete page shows why an object cannot be deleted (409), e.g. a
  tree node with children.
- Fixed: in a `Weighted` list the weight and status columns were swapped in
  the rows.
- Fixed: the upgrade page showed its log in one line (each step is on its
  own line again).

## [0.0.6] – 2026-10-06

Migrations: reading and comparing the database schema, a migration framework
with built-in backups, upgrading from the browser, applying the definitions'
changes automatically, and the first migration (the users' roles).

**Upgrading from 0.0.5:**

1. Upload the new files (the dependencies did not change).
2. Until the upgrade has run, the site answers 503. Then either:
   - `php bin/campanella install`: schema version 6 (the new `cc_migrations`
     table), the changes of the definitions, and the roles migration; it asks
     first and makes a backup into `var/backups/`;
   - or, without a command line: log in and open `/admin/upgrade`. The web
     server must be able to write `var/backups/` (the System page checks it).
3. Check the users' roles: `php bin/campanella user:list`.

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
- **Upgrading from the browser:** `/admin/upgrade` creates the missing tables,
  makes a backup and runs the pending migrations, for web hosts without a
  command line. For administrators, or with an upgrade key
  (`upgrade.key` in `config/local.php`, at least 20 characters, throttled;
  for when logging in does not work before the upgrade). Each step is shown;
  a failure with its cause and the backup made before it.
- **The definitions are applied automatically** (`SchemaSync`, run by
  `install`, `migrate` and the upgrade page): new tables, new columns (a
  required one gets its default in the existing rows), new indexes, and
  capabilities added to a Blueprint are added to its existing objects with the
  defaults. What would need guessing (a required field without a default, a
  unique one) is reported and left to a migration. Data of a capability removed
  from a Blueprint is kept, or deleted with `--prune`. Additive changes alone
  are applied without asking or a backup.
- `php bin/campanella user:list --role=editor`: the users with the role.
- System page: capabilities to add to existing objects, and what cannot be
  applied automatically.
- System page: whether the web server can write `var/backups`, and a warning
  while an upgrade key is set.
- System page: a *Database tables* group with the differences (or that the
  tables match their definitions).
- `SchemaBuilder` generates `ALTER TABLE` statements (`addColumnSql()`,
  `addIndexSql()`, `dropColumnSql()`, `dropIndexSql()`), in the forms both
  MariaDB and MySQL accept, and is now public.

### Changed

- **The users' roles are a multi-valued field** (`String`, `Field::UNLIMITED`,
  stored in `field_values`), so users can be queried by role
  (`->where('roles', '=', 'editor')`). Campanella's first migration,
  `core:0006_roles_multi_value`, moves the stored roles and drops the old
  `roles` column of `cap_authenticatable`. Until it has run, the roles read
  as empty; the upgrade page still recognises the administrators.
  `Authenticatable::setRoles()` leaves out empty and repeated roles.
- **While an upgrade is needed** (the schema version is older than the code,
  or a migration is pending), every page answers 503 with `Retry-After`, except
  logging in and out and the upgrade page. Before, only a database error led to
  the "needs upgrade" page. The visitors' message no longer mentions commands.
- `upgrade` is a reserved Blueprint name too.
- A database error caused by a table or column of the code that is not in the
  database yet answers 503 (needs upgrade) instead of 500.
- The System check's results are grouped (an added check's lines join their
  group).
- The Docker image creates `var/backups` for the web server's user.

### Deprecated

- `FieldType::StringList`: use a multi-valued `String` field instead. It will
  be removed before 0.1.0.

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
