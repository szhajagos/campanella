# Roadmap

Campanella evolves in small steps: every feature gets its own version, with
its own tests, documentation and CHANGELOG entry. Since 0.1.0 (the first
milestone, 2026-10-08) a real website can be run and managed from the
browser; since 0.2.0 (the second, 2026-10-12) the site communicates, and what
0.2.x keeps is [promised](docs/compatibility.md). Since 0.2.1 site owners have
a [guide](docs/guide/README.md) of their own. Each milestone is reached
in small steps (0.1.1, 0.1.2 …), each a release of its own.

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
| 0.1.0 | First milestone: installing from the browser (`/install`, with a key), users in the browser (administrators manage them; everyone's profile and password; sessions end on a password change), ready for a public server (trusted proxies, Content-Security-Policy and other headers, System page checks, deployment guide), Blueprints and capabilities in the admin (read-only), a security review (template sandbox, login throttling, fail-closed `.htaccess`, `composer audit` in CI); upgrades from 0.0.6 on |
| 0.1.1 | Site basics: site settings in the admin (name, slogan, description, address, share image, indexing), meta description, canonical URLs and Open Graph tags, `sitemap.xml`, `robots.txt` |
| 0.1.2 | Images: smaller copies for `srcset` (made on upload, and for older images from the System page or `media:variants`), responsive images in texts, where an image is used (listed on its delete page) |
| 0.1.3 | Events and e-mail: object and user events, actions bound in the configuration (`MailAdministrators`), e-mail with symfony/mailer (SMTP or PHP's own settings, templates, a log, a test e-mail from the System page), Mailpit for development |
| 0.1.4 | Forgotten password and sessions: a password reset by e-mail (a single-use, 60-minute link; the same answer and response time for every address), where one is logged in on the profile (log out one or every other), a 12-hour absolute login lifetime, configurable paths (`/login`, `/logout`, `/password-reset`), the `MailUser` action, work after the response; an independent security review |
| 0.1.5 | Contact form: its own page (`/contact`) and `contact_form()`, protected by CSRF, a honeypot, a signed time and limits; the messages as objects for administrators only (*Submissions*), an e-mail about each (`Reply-To`: the sender), deleted after 180 days, a privacy note and link; an independent security review |
| **0.2.0** | **Second milestone: the site communicates** (released 2026-10-12). What 0.1.1–0.1.5 built (search engines and sharing, images, e-mail and events, the forgotten password and sessions, the contact form), released as one whole: an upgrade test from 0.1.0, browser tests, the installation package checked as a site owner gets it, an upgrade guide from any 0.1.x, a security review of the whole 0.1.x layer, e-mail times in the site's time zone, and the compatibility promise for 0.2.x. Agreed in detail on 2026-10-11, in six parts. |
| 0.2.1 | A guide for site owners (released 2026-10-13), in Hungarian and English, with screenshots made by a script (`tests/guide/screenshots.sh`): installing, the admin, content, menus, images, users, the contact form and privacy, e-mail, backups and upgrades, troubleshooting; dates in the interface language. Agreed on 2026-10-12, in four parts. |
| – | Continuous integration (GitHub Actions): PHPStan, documentation, tests on MariaDB 10.6/11.4 and MySQL 8.0/8.4, an upgrade from 0.1.0, browser tests, the installation package checked before every release (with `vendor/`, for every version tag) |

## Next

### 0.3.0 – planned so far

Fixes of 0.2.x, security fixes first, come as 0.2.2, 0.2.3 … in the
meantime, keeping the [compatibility promise](docs/compatibility.md).

- **Search** with the database's FULLTEXT index (MariaDB / MySQL): simple and
  fast; a more sophisticated search engine may come later.
- **Redirects:** an automatic 301 redirect when a path changes, and a list of
  redirects in the admin.
- **Image picker** in the editor (choosing an uploaded image).

## Later

- **Workflow** (on the Event / Action foundation of 0.1.3): more operations
  bound to events (e.g. webhooks), a state machine for publishing.
- **Component / Region / Layout / Page:** assembling pages from components,
  instead of Drupal-style blocks.
- **Webform, extended** (after the contact form of 0.1.5): forms as a user
  interface for object operations.
- **Comments** (2026-10-07), after the Webform and the Event system (they need
  moderation and protection against spam): a `comment` Blueprint (`Textual`,
  `Authorable`, `Publishable` for moderation) with a required `subject`
  relation to the commented object, **not** a parent: the commented object is
  of another Blueprint. Replies form a tree with `Hierarchical`, among the
  comments of the same subject; ordered by time, not by weight. A
  `Commentable` capability on the commented Blueprints (comments open or
  closed, the count).
- **Appearance** (2026-10-11): a section of its own in the admin for the
  site's look: colour and other schemes, each in a light and a dark variant,
  and the colour mode: following the visitor's system setting (as now,
  `public/assets/theme.js`), or fixed (e.g. always dark). Whether visitors may
  switch it themselves, and how that control appears on the site, is decided
  then, within the concept of the CMS (e.g. as a component of the planned
  Component / Region / Layout system), not as a one-off button built into the
  header.
- **Cache:** object, query and render cache with cache tags and contexts.
- **JSON API** according to the [HTTP API draft](docs/http-api/README.md).
- **Media:** storing other files (documents) besides images.
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
- **Sample content in the site's language** (2026-10-12): the sample content
  (`seed`) is Hungarian only, its listing pages' paths (`/hirek`) too; an
  English site gets Hungarian samples.
- **Multilingual content.**
- **Trash, audit log.**
- **Login extensions:** two-factor authentication (e.g. TOTP) as its own
  capability, inserted between password verification and logging in;
  additional `LoginGuard`s (CAPTCHA).

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
- **E-mail: symfony/mailer** (2026-10-09), MIT licensed; SMTP or PHP's
  `mail()`.
- **Search: the database's FULLTEXT index** (2026-10-09), in 0.3.0; a more
  sophisticated search engine only later, if needed.
- **Language:** code, documentation, comments, commit messages and
  developer-facing messages are English; the UI is multilingual via the
  translation layer, with Hungarian as a first-class translation (2026-09-30).
