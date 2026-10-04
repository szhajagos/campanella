# Roadmap

Campanella evolves in small steps: every feature gets its own version
(0.0.3, 0.0.4, …), with its own tests, documentation and CHANGELOG entry.
Once the system is suitable for a real website managed from the browser (by
the end of 0.0.7), the version becomes 0.1.0.

The roadmap is a direction, not a promise: the order may change based on experience.
Completed changes are listed in the [CHANGELOG](CHANGELOG.md).

## Done

| Version | Contents |
|---|---|
| 0.0.1 | Object model, Capability contract, Blueprint, Query, access-aware queries, Twig rendering, CLI |
| 0.0.2 | Relations (relationships): article → categories, `whereRelated`, `RelationLoader`, Blueprint lists |
| 0.0.3 | Users and login: `Identifiable`, `Authenticatable`, `Authorable`, session, CSRF, login throttling, `LoginGuard` + honeypot, `editor` role, `user:*` commands; MIT license |
| 0.0.4 | Admin UI (lists, generated forms, publishing incl. scheduled, deleting), multi-valued fields (cardinality), translation layer (en, hu), Bootstrap 5.3 shipped locally, themes |
| – | Continuous integration (GitHub Actions): PHPStan, documentation, tests on MariaDB 10.6/11.4 and MySQL 8.0/8.4; installation package with `vendor/` for every version tag |

## Next

### 0.0.5 – HTML editing

Agreed in detail on 2026-10-02. In five parts, each its own commit:

0. ✅ **System page and housekeeping.**
   - `/admin/system` ("System" menu), for the `administrator` role only (exact
     versions and settings are useful to an attacker too; an `editor` gets a 403).
   - Content:
     - versions: Campanella, the schema version of the code and of the
       database (with a hint to run `install` if they differ), PHP against the
       minimum, the database server against the supported range;
     - required PHP extensions (e.g. `pdo_mysql`, `mbstring`) and recommended
       ones (e.g. `gd`, `intl`, `opcache`), each with what it enables;
     - writable folders (`var/cache`, `public/media`);
     - settings: locale, time zone, theme, admin path; a warning if debug mode
       is on;
     - PHP upload limits against the image upload limit;
     - the opcache state, and a "Clear the template cache" button (POST + CSRF).
   - Never shown: the database password, environment variables, session data.
   - Built on a `SystemCheck` service: a list of checks, each with a result
     (ok / warning / error) and a translatable message. The same checks run
     from the command line (with `status`), later features can add their own
     (e.g. the media folder), and the dashboard shows a warning bar if a check
     reports an error.
   - The Twig cache goes into a folder per version (`var/cache/twig/<version>`),
     so uploading a new release never serves stale templates (upload tools
     often keep the old file times, which defeats `auto_reload`).
   - A "Create and publish" button on the new-object form.
1. ✅ **HTML sanitizer.**
   - `symfony/html-sanitizer` 7.x (MIT, with MIT dependencies; 8.x needs PHP 8.4).
   - Allowlist, overridable in `config/html.php`: paragraphs, h2–h4,
     bold/italic/strikethrough, lists, blockquote, code, horizontal rule,
     line break, links, images, simple tables.
   - Links: only `http`, `https`, `mailto` or relative; external links get
     `rel="noopener noreferrer"`. Images: only our own uploads (no external
     images: they leak visitor data to other servers and can change or vanish).
   - Everything else is removed: `script`, `style`, `on…` handlers,
     `javascript:` URLs, `iframe` (video embeds come later, with their own
     allowlist).
   - Runs on save in the `ObjectRepository`, the lowest layer, so the CLI, the
     seed and the later API cannot bypass it. `html:sanitize` cleans HTML
     stored earlier.
   - Tests with a collection of known XSS tricks.
2. ✅ **Jodit editor** (the MIT edition, shipped locally in
   `public/assets/vendor/jodit/`, no CDN).
   - An `html` widget template and a small `admin-editor.js`: switching to
     SunEditor would replace only these. Without JavaScript a plain textarea
     with the raw HTML remains (sanitized by the server as always).
   - Toolbar profile per field in the Blueprint:
     `'editor' => ['body' => 'full']`.
   - New articles and pages get an HTML body by default; the lead stays plain
     text. Existing plain texts can be converted with a button (paragraphs
     become `<p>`, nothing is lost).
   - A Content-Security-Policy header for the admin that allows only our own
     scripts, as a second line of defense.
3. **Image upload**, in three commits: (a) ✅ the image object and the
   upload service, server side; (b) ✅ uploading from the admin and the editor;
   (c) the "Images" list, system checks, Docker php.ini. To our own endpoint (`POST /admin/media/upload`, without
   Jodit's PHP connector; CSRF; only for users the policy lets create).
   - Checked by content, not by extension: JPEG, PNG, WebP, GIF. No SVG (it
     can carry scripts). A size limit (e.g. 5 MB) and a pixel limit.
   - Re-encoded with GD and scaled down to a maximum size (e.g. 2560 px): this
     removes metadata (e.g. the GPS position of phone photos) and disguised
     files. Without GD the original is stored, and the system page warns.
   - Stored as `public/media/YYYY/MM/<random name>.<ext>`; a `.htaccess` there
     forbids running PHP.
   - Every image is an object (`image` Blueprint), following the decision that
     anything referred to is an object. Its file data (path, type, size,
     width, height) is a new capability with its own table, so it can be
     queried; the alternative text is a field too. An "Images" list in the
     admin; deleting the object deletes the file. New table: run `install`
     after upgrading.
4. Release `v0.0.5`.

**Done when:** an article's body can be formatted in the browser, including
images, the sanitizer removes every non-allowed element from the submitted
HTML, and the system page shows whether the server meets the requirements.

### 0.0.6 – Migrations

- Versioned migration steps (e.g. a new column, a new capability on existing
  objects, data transformation), tracked in the `cc_system` table.
- `install` also runs the pending migrations.
- A warning to back up the database before migrating.
- First migration: `roles` (and `StringList` in general) becomes a
  multi-value `String` field; the existing values are moved to
  `cc_field_values`, so users can be queried by role.

**Done when:** a new field of a capability, or a capability added to a
Blueprint, can be applied to existing content without manual SQL.

### 0.0.7 – Hierarchy and menu

- `Hierarchical` capability: `parent` relation, fast tree queries
  (materialized path), no circular references.
- `Weighted`/`Ordered`: manual ordering.
- Menu as an object, menu items as objects; tree rendering.
- Taxonomy tree (nested categories).

**Done when:** the main menu can be edited from the admin UI, and categories
can be arranged in a tree.

### 0.1.0 – First milestone

0.0.3–0.0.7 together: login, admin, HTML editing, migrations, menu. From here
on, the system is suitable for running a real website.

## Later

- **Event / Action / Workflow:** events (`ObjectPublished` …), operations
  bound to them (e-mail, webhook), a state machine for publishing.
- **Component / Region / Layout / Page:** assembling pages from components,
  instead of Drupal-style blocks.
- **Webform:** forms as a user interface for object operations.
- **Cache:** object, query and render cache with cache tags and contexts.
- **JSON API** according to the [HTTP API draft](docs/http-api/README.md).
- **Media:** file storage, images, image variants.
- **User management in the browser** (until then: the `user:*` commands).
- **Blueprints defined in the admin** (until then: `config/blueprints.php`).
  Every object stays in `objects`; the question is only where a custom field
  of such a Blueprint is stored. A per-field (or per-Blueprint) setting
  decides (2026-10-04):
  - *not queryable*: in the `data` JSON column (as custom fields are now);
  - *queryable* (filtering, sorting, indexes): in a table of its own. Either a
    table generated for the Blueprint (its own columns; needs the migration
    system of 0.0.6, because the admin would change the database schema), or
    the shared typed value table (`cc_field_values`), which needs no schema
    change at all. To be decided when the feature is planned.
  - Order: after the migrations (0.0.6), because every change also affects
    the existing content.
  - Operations: creating and modifying Blueprints; choosing capabilities from
    the installed ones (adding one is applied to the existing objects too;
    removing one keeps their data); custom fields; labels, form order,
    defaults, editor profile.
  - Blueprints from `config/blueprints.php` would appear as protected ("defined
    in code"); those created in the admin would be fully editable.
  - New kinds of capabilities stay code (they carry behaviour): they come from
    modules, not from the admin.
- **Multilingual content.**
- **Search**, URL aliases and redirects, trash, audit log.
- **Login extensions:** two-factor authentication (e.g. TOTP) as its own
  capability, inserted between password verification and logging in;
  additional `LoginGuard`s (CAPTCHA); password reset by e-mail (after
  Event/Action and mail sending); listing sessions and logging them out.

## Accepted decisions

- **License: MIT** (2026-09-29). Only dependencies with MIT-compatible licenses
  may be used (MIT, BSD, Apache 2.0, etc.); no GPL.
- **PHP 8.3** is the minimum; the common subset of MariaDB 10.6+ / MySQL 8.0+.
- **Look and feel: Bootstrap 5.3** for the admin UI and the default theme,
  shipped locally, with a replaceable theme (2026-09-29).
- **WYSIWYG: Jodit**, only the free MIT edition; PRO is not an option. If an
  important feature is only available in PRO and cannot be replaced with our
  own plugin, the fallback is SunEditor (2026-09-29). Submitted HTML is always
  sanitized by the server, independently of the editor.
- **No jQuery-dependent components.**
- **Multi-value fields:** a field's cardinality is defined on the `Field`;
  queryable multi-value fields live in the shared `cc_field_values` table, and
  we never search in JSON (2026-09-29). Anything that refers to another thing
  (tag, image, author) is an object and a relation, not a multi-value field.
- **HTML content (0.0.5):** sanitized on save at the lowest layer; only our
  own uploaded images; uploaded images are re-encoded (with GD) and are objects
  (2026-10-02).
- **System page:** the admin's "System" menu checks the server's requirements;
  administrators only, and it never shows secrets (2026-10-02).
- **Language:** code, documentation, comments, commit messages and
  developer-facing messages are English; the UI is multilingual via the
  translation layer, with Hungarian as a first-class translation (2026-09-30).
