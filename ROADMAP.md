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
| – | Continuous integration (GitHub Actions): PHPStan, documentation, tests on MariaDB 10.6/11.4 and MySQL 8.0/8.4; installation package with `vendor/` for every version tag |

## Next

### 0.0.6 – Migrations

Agreed in detail on 2026-10-05. In six parts (0–5), each its own commit:

0. ✅ **Reading the schema, finding differences.**
   - The actual database from `information_schema`: tables, columns (type,
     NULL, default), indexes; the same on MariaDB and MySQL.
   - `ALTER` statements generated from the definitions: add a column, add an
     index, drop a column. Portable: `ADD COLUMN IF NOT EXISTS` and
     `DROP INDEX IF EXISTS` exist only on MariaDB, so the state is checked
     first, then a plain `ALTER` runs.
   - A schema comparison (definitions against the database): "column
     `cap_weighted.weight` is missing", "a column not in the definition". On
     the System page and in `status`.
1. ✅ **The migration framework.**
   - A migration is a PHP class: an ID (`core:0006_roles_multi_value`), a
     description and `up(MigrationContext $m)`. Forward only, no `down()`:
     a backup instead (see below).
   - `MigrationContext`: `addColumn`, `addIndex`, `dropColumn`,
     `renameColumn`, `columnExists`, `sql()`, batched processing of large
     tables. Migrations work on SQL, never with the current model classes
     (an old migration may run against a newer model).
   - Recorded when applied (ID, time, duration); a lock (`GET_LOCK`) so two
     runs cannot overlap. On an error it stops and says where; DDL cannot be
     rolled back in MySQL, so a migration is one small step, repeatable if
     possible.
   - A fresh installation creates the current tables and marks every
     migration as applied; an existing one records them from now on and runs
     only the pending ones. "Needs upgrade" means: a migration is pending
     (`schema_version` stays, for information).
   - `php bin/campanella migrate`: lists the pending ones, asks for
     confirmation after a backup warning (`--yes`), `--dry-run`. `install`
     runs them too.
   - `php bin/campanella db:backup`: the database as an SQL file through PDO
     (no `mysqldump` needed) into `var/backups/`, outside the web root;
     `migrate` offers it.
2. ✅ **Running the upgrade from the browser**, for web hosts without a command
   line: the upgrade page (`/admin/upgrade`), for administrators or with an
   upgrade key (for when logging in does not work before the upgrade); CSRF,
   the lock, a backup first. While an upgrade is needed, every page answers
   503, except logging in and out and the upgrade page.
3. **Blueprint and capability changes, automatically.** A new field of a
   capability or a new capability of a Blueprint: `install`/`migrate` adds
   the column or table, and the existing objects get the capability with its
   defaults. A removed capability: the data is kept and reported; deleted
   only with `--prune`. A new required field without a default cannot be
   filled in by guessing: reported as an error, a migration has to fill the
   values. Renaming, changing a type or moving data always needs a migration.
4. **The first migration: `roles`** becomes a multi-valued `String` field:
   the values move to `cc_field_values`, the old column is dropped. Users
   can be queried by role (`user:list --role=editor`). Tested on a schema 5
   database built from a fixture, compared with a fresh installation.
   `StringList` is deprecated (removed before 0.1.0).
5. Release `v0.0.6`.

**Done when:** a new field of a capability, or a capability added to a
Blueprint, can be applied to existing content without manual SQL, also on a
web host without a command line.

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
- **Media:** file storage, image variants (thumbnails, sizes for `srcset`),
  and tracking where an image is used (e.g. a relation filled from the texts
  on save), so the delete page can list those texts, and an image picker in
  the editor (choosing an uploaded image).
- **Before going live (by 0.1.0):** a deployment guide and checks for a
  public server (2026-10-04):
  - the web server's document root is `public/` (as in Campanella's Docker
    image), not the project root: the root `.htaccess` that routes requests
    under `public/` protects the rest only while Apache honours `.htaccess`;
  - HTTPS behind a proxy: a `trusted_proxies` setting, so `X-Forwarded-Proto`
    from a trusted proxy marks the request secure (and the login cookie
    `Secure`);
  - debug mode off; the system page could check these too.
- **Before 0.1.0:** remove the deprecated `FieldType::StringList` (deprecated
  in 0.0.6; multi-valued fields replace it).
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
- **Secure by default (2026-10-04):** Campanella is built for the public, not
  for one server. The defaults must be safe for someone who changes nothing
  (e.g. strict image handling, HTML filtering, no default accounts); a weaker
  option needs an explicit setting, documented with its risk. Until 0.1.0 a
  documented gap is acceptable (the "Before going live" checklist); from 0.1.0
  on, a release is not made with a known open security gap.
- **Migrations (2026-10-05):** forward only, no rollback: a backup before
  migrating instead (`db:backup`, built in). Additive changes from the
  definitions are applied automatically; renaming, type changes, moving or
  deleting data only through an explicit migration, never by guessing. Upgrades
  can be run from the browser too, for web hosts without a command line.
- **Language:** code, documentation, comments, commit messages and
  developer-facing messages are English; the UI is multilingual via the
  translation layer, with Hungarian as a first-class translation (2026-09-30).
