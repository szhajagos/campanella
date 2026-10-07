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
| – | Continuous integration (GitHub Actions): PHPStan, documentation, tests on MariaDB 10.6/11.4 and MySQL 8.0/8.4; installation package with `vendor/` for every version tag |

## Next

### 0.0.7 – Hierarchy and menu

Agreed in detail on 2026-10-07. In six parts (0–5), each its own commit:

0. **`Weighted`** becomes a built-in capability (until now only an example in
   the tests): a `weight` field (integer, indexed) and a scope ordering by it.
   This is the manual order.
1. **`Hierarchical`:** a tree.
   - A `parent` relation, within the same Blueprint (a category under a
     category; a menu item under an item of the same menu).
   - `tree_path` (the ancestors' IDs, e.g. `/1/5/12/`) and `depth`, kept up to
     date on save. No circular references: neither the object itself nor one
     of its descendants can be its parent. At most 10 levels.
   - Moving a node updates its descendants' paths with a single `UPDATE`.
   - **A node with children cannot be deleted** until they are moved
     elsewhere (the delete page says so, and lists them).
   - Queries: children, descendants, ancestors (for breadcrumbs); a
     `TreeBuilder` that turns results into a tree.
2. **Trees in the admin:** the list indented, in tree order; the parent chosen
   from an indented list without the object's own descendants; the order
   changed with up/down buttons (no dependency; drag and drop later, e.g.
   with SortableJS, MIT, served locally).
3. **The category tree:** `category` gets `Hierarchical` and `Weighted`;
   `/kategoriak` shows the tree; breadcrumbs on category pages; **a category
   page lists the articles of its subcategories too.**
4. **The menu:**
   - A `menu` Blueprint (e.g. "Main menu", key `main`) and a `menu_item`
     Blueprint (`Hierarchical`, `Weighted`, a required relation to its menu).
   - An item points to an object (its path followed if it changes) or to a URL.
     An item whose target the visitor may not see (a draft) is not shown.
   - In Twig: `menu('main')`; the second level as a Bootstrap dropdown, the
     current item marked with `aria-current`. The main menu shows 2 levels.
   - Until a `main` menu exists, the current built-in links stay as a
     fallback, so the navigation does not disappear on an upgrade; `seed`
     creates the main menu with them.
   - In the admin: the menu's page shows its items as a tree, with a "New
     menu item" button that fills in the menu.
5. Release `v0.0.7`.

The new tables and capabilities are added by the automatic application of the
definitions (0.0.6): no migration is needed.

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
- **Trees (2026-10-07):** a parent is always in the same Blueprint; a node
  with children cannot be deleted; the depth is at most 10. A relation to an
  object of another kind (e.g. a comment and its article) is a relation, not
  a parent.
- **Language:** code, documentation, comments, commit messages and
  developer-facing messages are English; the UI is multilingual via the
  translation layer, with Hungarian as a first-class translation (2026-09-30).
