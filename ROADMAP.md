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
| – | Continuous integration (GitHub Actions): PHPStan, documentation, tests on MariaDB 10.6/11.4 and MySQL 8.0/8.4; installation package with `vendor/` for every version tag |

## Next

### 0.0.4 – Admin UI

0.0.4 and 0.0.5 were originally a single step; HTML editing was moved to a
separate release for security reasons (2026-09-29).

As a first step, before the forms:

- **Number of field values (cardinality).** A new `cardinality` property on
  `Field`: `1` (the default; existing fields do not change), an upper limit
  (e.g. `3`), or `Field::UNLIMITED`. For a multi-value field, `get()` returns
  a list; the type applies to every item, `required` means at least one value,
  and the count may not exceed the limit.
- Storage: a non-queryable (`Data`) multi-value field lives as a list in the
  data JSON; a queryable one (`Table`/`indexed`) in a new, shared
  `cc_field_values` table (`object_id`, `field`, `delta`, per-type value
  columns, with indexes). A multi-value field cannot have its own column.
- Query: a condition on a multi-value field compiles to an `EXISTS` subquery
  ("any of its values"); the query language does not change. Sorting by a
  multi-value field is not allowed.
- Cardinality is defined by the capability; a Blueprint may narrow it (e.g.
  3 → 2), but it cannot turn a single-value field into a multi-value one (or
  vice versa).
- Upper limit for relations: `new Relation(..., Cardinality::Many, max: 3)`.
- `StringList` becomes shorthand for "multi-value String with `Data` storage".
- Schema upgrade: the `cc_field_values` table.

Then:

- Translation layer for user-facing texts: messages are referenced by key
  (e.g. `auth.invalid_credentials`), texts live in `lang/en.php` and
  `lang/hu.php`; English is the base language, Hungarian a full translation.
  A `lang:check` script reports missing keys. Existing hard-coded Hungarian
  texts move there; the admin UI is built on it from the start.
- Listing, filtering, creating, editing, deleting and publishing content from
  the browser.
- Forms are generated from the field and relation definitions, so the editing
  UI for a new Blueprint or capability is created automatically. Multi-value
  fields get an "add another value" button and reordering, up to the
  cardinality limit.
- Clear error messages based on `ValidationException`.
- Look and feel: Bootstrap 5.3, shipped with Campanella
  (`public/assets/vendor/bootstrap`), without a CDN. Both the admin UI and the
  default public theme are built on it.
- Simple theme system: a template in the theme's folder takes precedence over
  the base template, so a custom theme can also be built without Bootstrap.
- In this step, text fields in `html` format still get a plain textarea; the
  editor and the sanitizer come in 0.0.5.

**Done when:** all content of the sample site can be managed from the browser,
without the command line.

### 0.0.5 – HTML editing

- HTML sanitizer for texts in `html` format, server-side, allowlist-based
  (candidate: `symfony/html-sanitizer`, MIT; HTMLPurifier is LGPL, so not
  that). The sanitizer runs on save, independently of the editor.
- WYSIWYG editor for text fields in `html` format: Jodit (the MIT base
  edition), shipped locally, with a replaceable integration and a toolbar
  profile selectable per field.
- Image upload to Campanella's own endpoint (without Jodit's PHP connector),
  as the most essential part of the media topic: file storage, type and size
  validation.

**Done when:** an article's body can be formatted in the browser, including
images, and the sanitizer removes every non-allowed element from the
submitted HTML.

### 0.0.6 – Migrations

- Versioned migration steps (e.g. a new column, a new capability on existing
  objects, data transformation), tracked in the `cc_system` table.
- `install` also runs the pending migrations.
- A warning to back up the database before migrating.

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
- **Language:** code, documentation, comments, commit messages and
  developer-facing messages are English; the UI is multilingual via the
  translation layer, with Hungarian as a first-class translation (2026-09-30).
