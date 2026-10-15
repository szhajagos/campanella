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
| 0.2.2 | Fields to filter and sort on (released 2026-10-13): a Blueprint's `queryable` own fields in its `bp_<name>` table, their values moved by the upgrade when that changes; the field types Decimal, Date, Email, Url, Choice and Html; the `translatable` flag and the design of translations; `object_fields()` in templates. The first step of 0.3.0. |
| 0.2.3 | Blueprints made in the admin (released 2026-10-14): a new kind of content with its capabilities, fields and relations, stored in the `blueprints` table, applied at once with a backup first. The second step of 0.3.0. |
| 0.2.4 | Changing and deleting fields in the admin (released 2026-10-15): a widened type, the column, one or several values, required, deleting fields and relations; the SchemaSync follows each change, with a backup first. The third step of 0.3.0. |
| – | Continuous integration (GitHub Actions): PHPStan, documentation, tests on MariaDB 10.6/11.4 and MySQL 8.0/8.4, an upgrade from 0.1.0, browser tests, the installation package checked before every release (with `vendor/`, for every version tag) |

## Next: the road to 1.0

Agreed on 2026-10-13. Campanella becomes a complete CMS before 1.0: content
types (Blueprints) with their own fields, built in the admin, on a data model
that also holds translations. **What changes the data model or the Public PHP
API comes before 1.0**, because from 1.0 on the compatibility promise covers
all of 1.x; what only builds on it (comments, workflow, an AI assistant) may
come after.

As before, each milestone is reached in small steps, each a release of its
own (0.2.2, 0.2.3 … towards 0.3.0), keeping the
[compatibility promise](docs/compatibility.md) of 0.2.x; fixes, security fixes
first, come in between. Each milestone is planned in detail when it starts.

### 0.3.0 – the content model in the admin

Agreed in detail on 2026-10-13. Every step keeps the 0.2.x
[compatibility promise](docs/compatibility.md): it only adds tables, columns,
classes and settings; nothing existing is rewritten.

**Storage of a Blueprint's own fields.** Fields that are not queried stay in
the `data` JSON column. Single-valued queryable ones get a column in a table
generated for the Blueprint, `bp_<name>`, shaped exactly like the
capabilities' `cap_*` tables (`object_id` as the primary key): the
Blueprint's own fields behave as an anonymous capability, so the Query engine,
the schema comparison and the migrations work on them unchanged.
Multi-valued queryable ones live in `field_values`, as now. The admin never
writes SQL: a field becomes a definition, and the existing machinery
(SchemaSync, migrations) changes the schema, with a backup first. Guards: the
field name's strict pattern, no collision with the capabilities' fields, at
most 40 queryable fields per Blueprint (the row size limit of MySQL),
administrators only.

**Translations: a base and a translation layer** (designed now, built in
0.6.0). The existing rows stay the content in its original language; the
translations of translatable fields go into tables of their own,
`cap_<name>_t` and `bp_<name>_t` keyed by `(object_id, language)`, and those
of `data` fields into `object_translations`. A query in another language joins
the translation and falls back to the original. The existing tables never
change, a site in one language pays nothing, and a value that is not
translated lives in one place only. Relations (e.g. categories) are shared by
every language. From 0.2.2 on, a `Field` carries a `translatable` flag
(stored, and set in the admin, but without effect until 0.6.0).

**Blueprints made in the admin** are stored in the database (a `blueprints`
table, the definition as JSON, loaded but never queried). Exporting and
importing them as files serves taking a model from one site to another; the
web server never writes the configuration. The Blueprints of
`config/blueprints.php` appear as protected ("defined in code").

**Field types:** the existing ones (string, text, integer, yes/no, date and
time), and new ones: decimal, date (without time and time zone), a choice list
(the key is stored; the labels per language), e-mail address, web address,
formatted text (through the HTML filter and the editor). An image, a file or
another object is not a field but a relation (as decided on 2026-09-29),
chosen with a picker in the admin.

**Changing a field later:** its label, help, required flag, default, order
and choices at any time; its machine name never; queryable or not, and single
or multi-valued, by moving its data automatically (backup first); its type
only in a widening direction (integer → decimal, string → text, date → date
and time), otherwise a new field. Deleting a field first shows how many
objects have a value, backs up, then deletes. A Blueprint can be deleted only
without objects. A capability added to a Blueprint applies to its existing
objects too; a removed one keeps their data.

| Step | Contents |
|---|---|
| **0.2.2** ✔ | Released 2026-10-13. Field types and queryable own fields in code: the new types, the `bp_*` tables, the `translatable` flag, the [design of the translation layer](docs/translations.md). From here on, `config/blueprints.php` can give a Blueprint fields to filter and sort on; when one becomes queryable or stops being, the upgrade moves its values (brought forward from 0.2.4). |
| **0.2.3** ✔ | Released 2026-10-14. Blueprints in the admin: creating them, choosing capabilities, adding fields and relations, their order, the safe changes of a field, deleting a Blueprint without objects; the schema changed with a backup first. |
| **0.2.4** ✔ | Released 2026-10-15. Changing and deleting fields and relations in the admin: the type (widening), the column, the number of values, making a queryable field required, deleting; a backup before every change that touches data. |
| **0.2.5** | Lists in the admin, filtered and sorted by fields: a simple counterpart of Drupal's Views, on the existing Query. |
| **0.2.6** | Exporting and importing the content model as files; an import first lists what it would change. |
| **0.2.7** | Search with the database's FULLTEXT index, redirects (an automatic 301 when a path changes, and a list in the admin), an image picker in the editor (split in two if large). |
| **0.3.0** | The milestone: a security review, the upgrade test, a chapter of the guide on one's own content types, the compatibility promise for 0.3.x. |

### 0.4.0 – building pages and the look

- **Component / Region / Layout / Page:** assembling pages from components,
  instead of Drupal-style blocks.
- **Appearance** (2026-10-11): a section of its own in the admin for the
  site's look: colour and other schemes, each in a light and a dark variant,
  and the colour mode: following the visitor's system setting (as now,
  `public/assets/theme.js`), or fixed (e.g. always dark). Whether visitors may
  switch it themselves, and how that control appears on the site, is decided
  then, within the Component / Region / Layout system, not as a one-off button
  built into the header.
- **Media:** storing other files (documents) besides images.
- **Sample content in the site's language** (2026-10-12): the sample content
  (`seed`) is Hungarian only, its listing pages' paths (`/hirek`) too; an
  English site gets Hungarian samples.

### 0.5.0 – extensibility

- **Modules:** packaging, installing, versions and dependencies; the way new
  capabilities, field types and components reach a site.
- **JSON API** according to the [HTTP API draft](docs/http-api/README.md),
  with a machine-readable description of the Blueprints (JSON Schema).
- **Cache:** object, query and render cache with cache tags and contexts.

### 0.6.0 – content for the long term

- **Multilingual content** (before 1.0, decided 2026-10-13: it affects how
  fields are stored): translations of objects, the language in paths, menus
  and the site settings per language.
- **Revisions, trash, audit log.**

### 1.0 – ready to rely on

- Performance measured, an accessibility review, a security review (an
  external one, if possible).
- The Public PHP API finalised; what was deprecated in 0.x removed.
- The compatibility promise and the support period for 1.x; the upgrade path
  from 0.x.

## Later

- **The project's website** (after 1.0; 2026-10-13), e.g. campanellacms.org:
  guides for site owners and developers, the API reference, downloads. Run on
  Campanella itself; the documentation's source stays in this repository
  (changing with the code, checked by CI), the site loads it from there.
- **An AI assistant** (2026-10-13), as an optional module, never part of the
  core and off by default: Campanella connects to an endpoint of the site
  owner's choice (e.g. an open model run with Ollama, llama.cpp or vLLM
  through their OpenAI-compatible interface); no model is bundled. It only
  suggests, a person accepts: alternative texts, descriptions and summaries,
  translation drafts, a Blueprint drafted from a description, answers from the
  documentation. It never publishes by itself, and has no more rights than
  its user (content may carry instructions meant for it). An **MCP server**
  module on the JSON API could open the site to any assistant the same way.
- **Workflow** (on the Event / Action foundation of 0.1.3): more operations
  bound to events (e.g. webhooks), a state machine for publishing.
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
- **Search: the database's FULLTEXT index** (2026-10-09), in 0.3.x; a more
  sophisticated search engine only later, if needed.
- **The road to 1.0** (2026-10-13): the content model in the admin first
  (0.3.0), then building pages (0.4.0), extensibility (0.5.0), multilingual
  content and revisions (0.6.0); what changes the data model or the Public
  PHP API comes before 1.0. The project's website and an AI assistant come
  after 1.0, the assistant as an optional module only.
- **The content model** (2026-10-13): a Blueprint's own queryable fields in a
  generated `bp_<name>` table, shaped like the `cap_*` tables; translations as
  a layer over the original (`*_t` tables, `object_translations`), never by
  changing the existing tables; Blueprints made in the admin stored in the
  database, carried between sites by export and import (see 0.3.0 above).
- **Language:** code, documentation, comments, commit messages and
  developer-facing messages are English; the UI is multilingual via the
  translation layer, with Hungarian as a first-class translation (2026-09-30).
