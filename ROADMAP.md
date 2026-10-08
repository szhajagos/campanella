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
| 0.0.5 | HTML editing: System page, HTML allowlist filter on every save, Jodit editor (shipped locally), Content-Security-Policy for the admin, image upload (checked by content, re-encoded, `image` Blueprint, Images list) |
| 0.0.6 | Migrations: reading and comparing the schema (`schema:check`), migrations with built-in backups (`migrate`, `db:backup`), upgrading from the browser (`/admin/upgrade`), the definitions' additive changes applied automatically, roles as a multi-valued field |
| 0.0.7 | Trees and menus: `Weighted` and `Hierarchical` (materialized path, no circles, at most 10 levels), trees in the admin (indented, up/down), the category tree on the site (breadcrumbs, articles of subcategories), menus edited in the admin (`menu`, `menu_item`, `Link` with safe URLs, `Keyed`, separate trees per menu), `menu()` in templates |
| – | Continuous integration (GitHub Actions): PHPStan, documentation, tests on MariaDB 10.6/11.4 and MySQL 8.0/8.4; installation package with `vendor/` for every version tag |

## Next

### 0.1.0 – First milestone

0.0.3–0.0.7 together: login, admin, HTML editing, migrations, menu. From here
on, the system is suitable for running a real website, and (Secure by
default) no release is made with a known open security gap.

Agreed in detail on 2026-10-08. Kept narrow on purpose: it is still 0.x. In
seven parts (0–6), each its own commit:

0. ✅ **Housekeeping:** remove the deprecated `FieldType::StringList` and the
   upgrade page's reading of the old roles column. Upgrading to 0.1.0 is
   possible from 0.0.6 or later only (from older versions: through 0.0.7
   first); the installer stops with a clear message otherwise.
   `config/local.php.dist` no longer turns debug mode on. On the dashboard,
   the content types link to their lists.
1. **Ready for a public server:**
   - a `trusted_proxies` setting (IPs / CIDR ranges, empty by default):
     `X-Forwarded-Proto` and `X-Forwarded-For` count only from these, for
     HTTPS (the `Secure` cookie) and the visitor's IP (login throttling);
   - security headers on the public site too: a strict Content-Security-Policy
     (only our own scripts, no inline script), which a theme may relax only
     by an explicit setting, documented with its risk; `Permissions-Policy`;
     HSTS as an opt-in setting (off by default: a wrong setting locks
     visitors out);
   - System page checks: the document root is `public/`, debug mode is off,
     HTTPS (also behind a proxy), writable folders;
   - a deployment guide (`docs/deployment.md`): Apache, nginx, Docker, HTTPS
     behind a proxy, file permissions, backups and upgrades, a checklist
     before going live.
2. **Installing from the browser,** for web hosts without a command line:
   `/telepites` checks the requirements, creates the tables and the first
   administrator. Only with an install key set in `config/local.php` (like
   the upgrade key), so nobody else can install the site between uploading
   and installing; once installed, the page answers 404.
3. **Users in the browser:**
   - for administrators: the users' list (roles, status), a new user,
     changing roles, disabling and enabling, setting a new password;
   - for everyone logged in: their own profile (name, changing the password
     with the current one);
   - the last active administrator cannot be disabled or lose the role;
     changing a password ends the user's other sessions; editors still do
     not manage users;
   - a forgotten password by e-mail comes later (it needs e-mail sending).
4. **Blueprints and capabilities in the admin** (read-only, for
   administrators): a *Blueprints* page lists the Blueprints with their
   capabilities, fields and relations, and links to their lists; a
   *Capabilities* page lists the installed capabilities with a short
   explanation, their fields, and the Blueprints that use them. A first step
   towards Blueprints defined in the admin.
5. **Security review:** an independent review of the whole code (login and
   sessions, CSRF, XSS, SQL parameters, uploads, open redirects, error
   messages, headers, throttling, permissions), `composer audit` and the
   dependencies' licenses; the fixes in their own commit, anything left as a
   documented decision.
6. Release `v0.1.0`: the README rewritten for the milestone (what the system
   can do, installing from the browser or the command line), the upgrade
   path, CHANGELOG, ROADMAP.

Left for later on purpose: `<meta name="description">`, canonical URLs,
`sitemap.xml` and `robots.txt`; a forgotten password by e-mail; site settings
edited in the admin.

## Later

- **Event / Action / Workflow:** events (`ObjectPublished` …), operations
  bound to them (e-mail, webhook), a state machine for publishing.
- **Component / Region / Layout / Page:** assembling pages from components,
  instead of Drupal-style blocks.
- **Webform:** forms as a user interface for object operations.
- **Comments** (2026-10-07), after the Webform and the Event system (they need
  moderation and protection against spam): a `comment` Blueprint (`Textual`,
  `Authorable`, `Publishable` for moderation) with a required `subject`
  relation to the commented object, **not** a parent: the commented object is
  of another Blueprint. Replies form a tree with `Hierarchical`, among the
  comments of the same subject; ordered by time, not by weight. A
  `Commentable` capability on the commented Blueprints (comments open or
  closed, the count).
- **Cache:** object, query and render cache with cache tags and contexts.
- **JSON API** according to the [HTTP API draft](docs/http-api/README.md).
- **Media:** file storage, image variants (thumbnails, sizes for `srcset`),
  and tracking where an image is used (e.g. a relation filled from the texts
  on save), so the delete page can list those texts, and an image picker in
  the editor (choosing an uploaded image).
- **Site basics** (moved out of 0.1.0, 2026-10-08): `<meta name="description">`
  from the lead, canonical URLs, `sitemap.xml` of the public content,
  `robots.txt`.
- **Forgotten password by e-mail** (after e-mail sending; user management
  itself comes in 0.1.0).
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
- **Secure by default (2026-10-04):** Campanella is built for the public, not
  for one server. The defaults must be safe for someone who changes nothing
  (e.g. strict image handling, HTML filtering, no default accounts); a weaker
  option needs an explicit setting, documented with its risk. Until 0.1.0 a
  documented gap is acceptable (parts 1 and 5 of 0.1.0 close them); from 0.1.0
  on, a release is not made with a known open security gap.
- **Migrations (2026-10-05):** forward only, no rollback: a backup before
  migrating instead (`db:backup`, built in). Additive changes from the
  definitions are applied automatically; renaming, type changes, moving or
  deleting data only through an explicit migration, never by guessing. Upgrades
  can be run from the browser too, for web hosts without a command line.
- **Trees (2026-10-07):** a parent is always in the same Blueprint; a node
  with children cannot be deleted; the depth is at most 10. A relation to an
  object of another kind (e.g. a comment and its article) is a relation, not
  a parent.
- **Language:** code, documentation, comments, commit messages and
  developer-facing messages are English; the UI is multilingual via the
  translation layer, with Hungarian as a first-class translation (2026-09-30).
