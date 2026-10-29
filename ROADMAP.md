# Roadmap

Campanella evolves in small steps: every feature gets its own version, with
its own tests, documentation and CHANGELOG entry. Since 0.1.0 (the first
milestone, 2026-10-08) a real website can be run and managed from the
browser; since 0.2.0 (the second, 2026-10-12) the site communicates. Since
0.2.1 site owners have a [guide](docs/guide/README.md) of their own. Since
0.3.0 (the third, 2026-10-24) content types are built in the admin, and what
0.3.x keeps is [promised](docs/compatibility.md). Each milestone is reached
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
| 0.2.5 | Lists made in the admin (released 2026-10-15): the counterpart of `config/queries.php` without code, stored in the `lists` table, with a page of their own and `list()` in templates. The fourth step of 0.3.0. |
| 0.2.6 | The content model's export and import (released 2026-10-23): the Blueprints and lists made in the admin in one JSON file, in the admin (System → Content model) and on the command line; an import shows its plan first, follows the editors' rules, never deletes. The fifth step of 0.3.0. |
| 0.2.7 | Search, redirects and an image picker (released 2026-10-24): FULLTEXT search of the published pages, with or without accents, at `/search`; a 301 from an object's old path, and System → Redirects; the editor's picker of uploaded images. The sixth step of 0.3.0. |
| 0.2.8 | The admin's list with the own fields (released 2026-10-24): up to 4 columns per Blueprint (the editor, or `admin_columns`), sortable, with filters by type; carried by the content model's export. The seventh step of 0.3.0. |
| 0.2.9 | Visitors' filters on the lists (released 2026-10-24): at most 4 fields and relations per list, a form above it, kept by the pages, `noindex` when filtered; `FieldFilters` shared with the admin's lists. The eighth step of 0.3.0. |
| **0.3.0** | **Third milestone: the content model in the admin** (released 2026-10-24). What 0.2.2–0.2.9 built (fields to filter and sort on, Blueprints and lists made in the admin, the content model's export and import, search and redirects, the admin's own columns, visitors' filters), released as one whole: a security review of 0.2.2–0.2.9, upgrade tests from 0.2.0 and 0.2.9 (with a content model), the upgrade guide for any 0.2.x or 0.1.x, a performance check with 10 000 objects, the guide's chapter on one's own content types, sample events, and the compatibility promise for 0.3.x (0.2.x gets security fixes until 0.4.0). Agreed in detail on 2026-10-24, in five parts. |
| 0.3.1 | Importers, step 1 (released 2026-10-26): CSV and JSON files into the objects of a Blueprint, by a mapping and a unique key; a trial, a run in batches with a log, undoing a run; System → Importers and `import:run`, `import:undo`; the admin's lists as CSV; the guide's chapter on importing. |
| 0.3.2 | Files to download (released 2026-10-28): documents checked by their content, stored outside the web root and always downloaded through the site, for everyone, logged-in users or some roles (`Restricted`, applied by the access policy everywhere); the site's own roles; the media library; the editor's file picker; the guide's chapter on images and files. The first step of 0.4.0. |
| 0.3.3 | Displays and components (released 2026-10-28): 16 displays offered by the capabilities of what they show, for objects, lists and system sources (the visitor, the search, the site, the breadcrumbs), always read with the visitor's rights; `display()` in templates; the `snippet` Blueprint; components made in the admin in three steps, for administrators only, `component()` in templates; the admin's menu in groups; the guide's chapter on components. The second step of 0.4.0. |
| – | Continuous integration (GitHub Actions): PHPStan, documentation, tests on MariaDB 10.6/11.4 and MySQL 8.0/8.4, upgrades from 0.1.0, 0.1.5, 0.2.0, 0.2.9, 0.3.0, 0.3.1 and 0.3.2, a performance check, browser tests, the installation package checked before every release (with `vendor/`, for every version tag) |

## Next: the road to 1.0

Agreed on 2026-10-13. Campanella becomes a complete CMS before 1.0: content
types (Blueprints) with their own fields, built in the admin, on a data model
that also holds translations. **What changes the data model or the Public PHP
API comes before 1.0**, because from 1.0 on the compatibility promise covers
all of 1.x; what only builds on it (comments, workflow, an AI assistant) may
come after.

As before, each milestone is reached in small steps, each a release of its
own (0.2.2, 0.2.3 … towards 0.3.0; 0.3.1 … towards 0.4.0), keeping the
[compatibility promise](docs/compatibility.md) of its series; fixes, security fixes
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
| **0.2.5** ✔ | Released 2026-10-15. **Lists** made in the admin (see below): conditions on queryable fields and relations with today and now, an order, paging, short cards or a table, a path, `list()` in templates. |
| **0.2.6** ✔ | Released 2026-10-23. Exporting and importing the content model as a file (System → Content model, `model:export`, `model:import`); an import first lists what it would change, follows the editors' rules and never deletes. |
| **0.2.7** ✔ | Released 2026-10-24. Search with the database's FULLTEXT index (`/search`, accents folded), redirects (an automatic 301 when a path changes, and System → Redirects), an image picker in the editor. |
| **0.2.8** ✔ | Released 2026-10-24. **The admin's object list with the own fields** (see below): chosen fields as columns, sortable, with filters by type above the list. |
| **0.2.9** ✔ | Released 2026-10-24. **Visitors' filters** on a list (see below), e.g. a choice to pick the kind of event, or a date range. |
| **0.3.0** ✔ | Released 2026-10-24. The milestone (see below, agreed 2026-10-24): a security review, upgrade tests from 0.2.0 and 0.2.9, a chapter of the guide on one's own content types, sample content with a Blueprint of its own, a performance check, the compatibility promise for 0.3.x. |
| **0.3.1** ✔ | Released 2026-10-26. **Importers, step 1** (see below): importing objects from an uploaded CSV or JSON file, with a mapping, a unique key, a trial run, a log and undoing a run; and the admin's list exported as CSV. Decided 2026-10-23. |

**Lists (0.2.5, agreed 2026-10-15).** System → Lists. A list has a name, a
machine name and a title; the Blueprint whose objects it shows; conditions
on its queryable fields and relations (e.g. "the day ≥ today", "the kind =
talk", "the category = Events"), with two changing values, **today** and
**now**, so a list of upcoming events keeps itself up to date; one or more
sort orders; the number per page (at most 100), with paging; a display:
**teaser** (the existing short card) or a **table** of chosen fields, the
displays kept open to more later (e.g. with the Component system of 0.4.0);
and an optional path (`/esemenyek`), checked like an object's path, that a
menu item can point to. Templates use it as `{% for item in list('esemenyek') %}`.
Conditions only on queryable fields: they have columns and indexes, so a list
stays fast however many objects there are. Access is unchanged: the Query
engine adds the visitor's limits to every list. Stored as JSON definitions in
a `lists` table, like the Blueprints; the content model's export (0.2.6)
carries them too. Not in 0.2.5: visitors' filters (0.2.9), and the admin's
object list with the own fields (0.2.8).

**The admin's object list with the own fields (0.2.8, agreed 2026-10-24).**
At most 4 of a Blueprint's own fields as columns of its list in the admin,
the same for every user: chosen in the Blueprint editor for a Blueprint made
in the admin (and carried by the content model's export), with an
`admin_columns` key in `config/blueprints.php`. Any field can be a column (the
objects are loaded anyway), shown as on the site; a queryable single-valued
one is sortable by its header. The queryable columns get filters above the
list by their type: a choice (a dropdown), yes/no, a date or a moment (from,
to), a number (at least, at most); a relation's column offers its targets (at
most 200). Filters come with the columns automatically, the simplest for now;
choosing them apart from the columns (e.g. an `admin_filters` key) can be
added later without changing what exists. They work with the title search
and the status filter, in the address (`?f[fajta]=seta`); a value that is not
valid for its field is ignored. Not in 0.2.8: the list as CSV (0.3.1, with
the importers).

**Visitors' filters (0.2.9, agreed 2026-10-24).** A list made in the admin
offers the visitor filters on chosen queryable fields, as a form above the
list (`list/_filters.html.twig`, a theme can override it): the same filters by
type, checked the same way, added to the list's own conditions (what is not
published stays hidden). Paging keeps them; a filtered page is `noindex`, its
canonical address the list's own.

**The 0.3.0 milestone (agreed 2026-10-24; released the same day, all five parts done).** No new feature: what 0.2.2–0.2.9
built, checked, documented and promised for the longer term. Nothing was
marked Deprecated in 0.2.x, so 0.3.0 breaks nothing. In parts:

1. **A security review** of 0.2.2–0.2.9 (`docs/security.md`), fixes with
   tests: the Blueprint editor (names that become tables and columns), the
   content model's import (an untrusted file), the lists and their filters
   (what reaches a query; nothing unpublished through any filter; the targets
   a relation's filter shows), the search (its words, its load: throttled by
   address if the review finds it needed), the redirects (only within the
   site, loops, normalised paths), the image picker's JSON, and CSRF, the CSP
   and error messages on every new page.
2. **Upgrade tests from 0.2.0 and from 0.2.9** besides those from 0.1.0 and
   0.1.5, the latter with a content model (a Blueprint made in the admin with
   queryable and multi-valued fields, a list with conditions and filters,
   redirects, the search's index) that must survive and keep working; the
   upgrade guide rewritten for 0.3.0 (from any 0.2.x or 0.1.x).
3. **A performance check** (`tests/performance/`): 10 000 objects of a
   Blueprint with own fields; a list's page with filters, the admin's list
   with its columns and filters, and the search answer within set limits,
   measured on MariaDB and MySQL (the full measurement stays for 1.0).
4. **The guide: one chapter on one's own content types** (Hungarian and
   English, with screenshots): an events calendar built step by step (a
   Blueprint and its fields, changing them, the admin's columns, a list of the
   upcoming events with filters, a path and a menu item, carrying it to
   another site); the search and the redirects in the existing chapters.
   **Sample content** (`seed`) gets an events Blueprint with a list and
   filters (in Hungarian, like the rest of it until 0.4.0).
5. **The compatibility promise for 0.3.x** (`docs/compatibility.md`), now also
   covering the stored forms of Blueprints and lists, the content model's
   file (format 1), the addresses `/search` and `?f[…]`, the new Public
   classes and commands; **0.2.x gets security fixes until 0.4.0 is out**. The
   CHANGELOG's milestone entry, the release.

**Importers (agreed 2026-10-23)**, like Drupal's Feeds. System → Importers.
An importer is stored as a JSON definition in an `importers` table: its
source, its format, the Blueprint its objects become, the user in whose name
(and with whose permissions) they are made, the mapping of the source's
columns to fields and relations, and a unique key (e.g. an `id` column or a
feed's GUID) by which a later run updates an object instead of making
another. Values are converted by the field types (dates, decimal commas, a
choice by key or label, several values with a separator), formatted text goes
through the HTML filter. A relation's target is found by machine name, path
or title (as in the content model's import); if it is not found, the item is
an error (the default), or it is imported without the link, or the target is
made, chosen per importer. A trial run shows the first items as they would
become, changing nothing; a run works in batches, skips unchanged items (by a
hash), and leaves a log (made, updated, unchanged, failed, the errors by
row). Which item became which object is kept in real columns of an
`import_items` table (importer, key, object, hash, time), so a run can be
undone (the objects it made deleted, after a backup). An item that left the
source stays (the default) or becomes a draft; an import never deletes.
Running: by hand in the admin or with `php bin/campanella import:run <name>`,
on a schedule only from cron (`import:cron`), **never started by a visitor's
request** (it would slow down their page). Only the system roles set up
importers.

1. **Step 1, 0.3.1:** an uploaded CSV (with a header row, `;` or `,`, UTF-8)
   or JSON file (with a path to its items); at most a few MB in the browser,
   larger ones from the command line. No XML yet. With it, the admin's list
   exported as CSV (decided 2026-10-24), in the same format.
2. **Step 2, 0.5.0, as the first official module:** sources at a web address
   (CSV, JSON, RSS and Atom), fetched safely (only `https://` by default, no
   internal addresses after resolving the name, redirects checked again, time
   and size limits; XML without external entities or DTDs), on a schedule from
   cron, and what happens to items that left the source.
3. **Step 3, after 0.4.0's media:** images and files at addresses in the
   source become media objects related to the imported object.

### 0.4.0 – building pages and the look

Planned on 2026-10-26, in steps; each keeps the 0.3.x
[compatibility promise](docs/compatibility.md). Files come first: a small,
self-contained step that is sensitive for security, and whose picker the
components use later.

| Step | Contents |
|---|---|
| **0.3.2** ✔ | Released 2026-10-28. **Files** (see below): documents besides images, as objects; public and private files; one media library in the admin; a file chosen by the relation picker, or linked from the editor. |
| **0.3.3** ✔ | Released 2026-10-28. **Displays and components** ([the design](docs/components.md), detailed below): `Display` (Public API: a class with its template, its settings and what it needs: capabilities, a list or a system source) and the built-in ones; the sources (an object, a list, the page's object, the system sources such as the visitor); the `snippet` Blueprint; the `component` Blueprint made in the admin, and `component()` in templates; the admin's menu in groups; `full` and `teaser` become displays. |
| **0.3.4** | **Regions and layouts** (detailed below): the core's two layouts and the theme's own (JSON), `region()`; how a region shows on small screens; components placed in regions (order, who sees them, where); a layout for the site and per Blueprint; tokens in snippets; the upgrade keeps the header and footer as they are. |
| **0.3.5** | **Pages:** pages assembled from components (a front page, an "about us" page), their own layout; editors edit their components. |
| **0.3.6** | **Appearance** (2026-10-11): colour schemes with light and dark variants, the colour mode (the visitor's system setting, or fixed); a visitor's switch as a component, not a button built into the header. |
| **0.3.7** | **Sample content in the site's language** (2026-10-12): an English site gets English samples and paths (now `seed` is Hungarian only, `/hirek` too). |
| **0.4.0** | **The milestone**, as 0.3.0: a security review, upgrade tests from 0.3.0 and the last 0.3.x, the guide's chapters, a performance check, the compatibility promise for 0.4.x. |

Decided on 2026-10-26:

- **Who builds what.** Layouts and regions (where a component appears on the
  site) are a right above an editor's: administrators only. The content of a
  page assembled from components can be edited by editors too.
- **Existing themes keep working.** The blocks of `base.html.twig` stay; the
  regions come beside them, so a theme written for 0.3.x needs no change in
  0.4.0. Nothing has to be marked Deprecated for it.
- **Components** (decided 2026-10-27, [the design](docs/components.md)): a
  component is a source, a **display** and its settings. A display is offered
  for an object because of its capabilities (no "Blockable" capability); a
  system source (e.g. the visitor: login button, admin button, login form) is
  code, not a Blueprint. Components are objects of a `component` Blueprint,
  placed in a layout's regions for the whole site, or on one page as its own
  content. Layouts come from the theme; editable layouts come later. The names
  are *Component* and *Display* ("Presentation" is taken).

**Displays and components (0.3.3, agreed 2026-10-28),** in three parts:

1. **Displays** (part 1): `Display`, `AsDisplay`, `DisplayRegistry`
   (`config/app.php` `displays` and `display_sources`), `Subject`,
   `DisplayContext`, `DisplayRenderer`; the sources `object:<id>`,
   `list:<name>`, `current` (the page's object, from 0.3.3 on) and the system
   sources `visitor`, `search`, `site`, `breadcrumbs`, always read with the
   visitor's rights. The built-in displays: `full`, `teaser`, `link`,
   `info_box` (in the Bootstrap colours until the colour schemes),
   `navigation`, `image`, `download`, `cards` (1–4 columns), `table`,
   `login_button`, `admin_button`, `login_form`, `user_card`, `search_box`,
   `branding` (the site's name and slogan), `breadcrumbs`. `display()` in
   templates; `render_object()` renders `full` and `teaser` as displays, with
   the same templates. A **`snippet`** Blueprint (a title and a formatted text,
   no page of its own) for the texts components show: opening hours, a contact.
2. **Components in the admin** (part 2): the admin's menu in groups, built in
   PHP (`AdminMenu`, so modules can add to it): *Content*, *Media library*,
   *Audience* (submissions, users), and for administrators *Appearance*
   (components; layouts in 0.3.4, colour schemes in 0.3.6), *Structure*
   (Blueprints, capabilities, lists, the content model), *System* (status,
   settings, mail log, importers, redirects); the profile in the top bar. The
   admin's addresses stay. The `component` Blueprint (a title, a machine name,
   `Restricted`, and a capability of its own for the source, the display and
   the settings, which are stored in `data` and checked on every save); its
   form in three steps (the source, the displays it can have, the settings'
   form from the display's fields); the components that no longer work are
   marked. `component('fo-menu')` in templates. Administrators only.
3. **The guide, the docs and the release** (part 3): a guide chapter on
   components, the PHP API, the security review, the upgrade test.

**Regions and layouts (0.3.4, agreed 2026-10-29),** in four parts:

1. **The layouts and regions** (part 1): a layout is a template and a JSON
   file of its regions (`layout/<name>.html.twig`, `.json`; not PHP: a theme
   holds only sandboxed templates), from the core and the theme; the core's
   *one column* and *with a sidebar*, with separate *header* and *navigation*
   regions; each region's look on small screens (always, collapsed or hidden
   below a Bootstrap breakpoint); `region()`; a component's region and order,
   and its conditions (paths with a wildcard, except paths, Blueprints, the
   front page), written apart from components so layouts can use them later;
   the layout of the site and of a Blueprint. All of a page's components in
   one query.
2. **Tokens** (part 2): `[site:name]`, `[site:slogan]`, `[site:url]`,
   `[date:year]`, `[campanella:version]` in snippets, escaped, from a registry a
   module can add to; the list shown under the editor.
3. **The admin** (part 3, in two, decided 2026-10-29): **3a** *Appearance →
   Layouts* (the site's, per Blueprint; the regions' look on small screens;
   done); **3b** *Appearance → Regions* (a layout's regions with their
   components, in order: up, down, add, take out), and the component's form
   gets its region and where it shows.
4. **The upgrade, the guide, the release** (part 4): the components of the
   header and footer as they look now (the site's name, the main menu, the
   search box, the visitor's buttons, a footer snippet), on an upgrade and a new
   installation; the guide, the docs, the release.

Later (decided 2026-10-29): a layout chosen by rules (path, Blueprint, role …),
with the components' conditions; the regions' arrangement edited in the admin.

**Files (0.3.2, agreed 2026-10-26).** Documents (PDF, the Office and
OpenDocument formats, plain text, CSV …, by an allowlist) are objects of a
`file` Blueprint, as images are of `image`, related to the objects that use
them. A file is checked by its content, not its name; its stored name is
random, as an image's. Every file, public or not, lives outside `public/`
(`var/files/`) and is served by PHP as a download, only to those allowed to see
it (changed on 2026-10-27 from public files served by the web server: the same
headers on every server, readable download names, nothing moved when a file
becomes private). Who may see one (everyone, logged-in users, some roles) is a
capability, `Restricted`, applied by the access policy. This is decided before
1.0 because it is part of the data model.

### 0.5.0 – extensibility

- **Modules:** packaging, installing, versions and dependencies; the way new
  capabilities, field types and components reach a site.
- **JSON API** according to the [HTTP API draft](docs/http-api/README.md),
  with a machine-readable description of the Blueprints (JSON Schema).
- **Cache:** object, query and render cache with cache tags and contexts.
- **Importers, steps 2 and 3** (2026-10-23, see 0.3.0 above): feeds from web
  addresses on a schedule, and images from the source, as the first official
  module.

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
- **Search: the database's FULLTEXT index** (2026-10-09), done in 0.2.7; a more
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
- **Importers** (2026-10-23): like Drupal's Feeds, in three steps (files in
  0.3.1; web addresses and RSS/Atom as a module in 0.5.0; images after the
  media of 0.4.0). An import never deletes; a run is never started by a
  visitor's request, only by hand or from cron.
- **Language:** code, documentation, comments, commit messages and
  developer-facing messages are English; the UI is multilingual via the
  translation layer, with Hungarian as a first-class translation (2026-09-30).
