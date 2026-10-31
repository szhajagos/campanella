# Changelog

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

Sections: **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**,
**Security**. In 0.x versions, items under **Changed** and **Removed** may be
backward-incompatible.

## [Unreleased]

**Sample content in the site's language, part 1.** The sixth step towards 0.4.0.

### Changed

- **The sample content is data** ([PHP API, chapter 9](docs/php-api/09-system.md#the-sample-content)):
  one JSON file per language in `resources/seed/` (`hu.json`, the content
  before), read and checked whole before anything is made (`SeedContent`). `seed`
  uses the site's language, or `--locale=<locale>`, and says which file it
  takes; a language without a file of its own gets English, else the first
  there is.

## [0.3.6] – 2026-10-31

**Colour schemes and the colour mode.** The fifth step towards 0.4.0: the
site's colours in a light and a dark variant, chosen in the admin; the colour
mode; the visitor's colour mode switch as a component.

**Upgrading from 0.3.0–0.3.5:** upload the new files and run the upgrade
(`php bin/campanella install`, or `/admin/upgrade`); no table changes. The site
keeps its colours (the *Terracotta* scheme is the look before) and its mode (the
visitor's system setting) until you choose others; only the info box takes the
scheme's colours. No colour mode switch is added: make a component of *The
visitor* as *Colour mode switch* if you want one. A theme that overrides
`base.html.twig` keeps its own colours until it links `color_scheme_url()`. From
older versions: see [docs/upgrading.md](docs/upgrading.md).

### Added

- **Colour schemes** ([PHP API, chapter 31](docs/php-api/31-appearance.md)):
  the site's colours in a light and a dark variant, from a JSON file of the core
  (`templates/scheme/`: `default`, `forest`, `sea`) or of the theme
  (`ColorScheme`, `ColorSchemeRegistry`). Every colour is `#rrggbb`, and a
  scheme must be readable: the text, the muted text, the links and the buttons'
  labels have a contrast of at least 4.5:1 (WCAG 2.2 AA) in both variants, or
  the scheme is left out, with the reason.
- **The colour mode** (`Appearance`): the visitor's system setting (the
  default), or always light or dark (`appearance.color_mode`); a visitor's own
  choice (the `campanella_color_mode` cookie) comes first. The server sets
  `data-bs-theme`, so a page never shows in the wrong mode first. The admin
  follows the mode, never the scheme.
- The scheme as CSS at `/color-scheme.css`, linked with its version and cached
  for a year; `color_mode()` and `color_scheme_url()` in templates.
- **Appearance → Colour schemes** in the admin (`ColorSchemePages`,
  `/admin/system/color-schemes`, for administrators): the schemes, each with a
  preview of its light and dark variant, the colour mode; the schemes left out,
  with the reason, and a warning if the saved one is gone.
- **The colour mode switch** (`color_mode_switch`, a display of the visitor):
  as the system, light, dark; a form (`POST /color-mode`) that works without
  JavaScript, `color-mode.js` on top changes the mode at once. The visitor's
  choice is a cookie for a year: no session, no CSRF token, and a request from
  another site is refused. The sample content (`seed`) puts it at the end of
  the navigation, as a component.
- `Vary: Cookie` on every HTML page, since a page depends on the visitor's mode.

### Security

- Reviewed as a whole ([the review](docs/security.md#review-of-the-colour-schemes-and-the-colour-mode-036-2026-10-30)):
  a scheme's colours are `#rrggbb` only, and a theme's label is escaped; the
  visitor's mode is one of three words, set only by a request from the site's
  own pages, back to its own paths. Nothing needed fixing.

### Documentation and tests

- The guide's chapter 16, *Colour schemes and the colour mode* (hu, en), with
  screenshots of the colour schemes page, the switch and the site in dark mode.
- The upgrade test checks that an upgraded site keeps its colours and mode, and
  gets no switch. CI: the upgrade from 0.3.5 (MySQL 8.4).

### Changed

- `campanella.css`: the text on the accent (buttons, the current dropdown item,
  the current page of a pagination) is `--cc-on-accent`; `theme.js` only sets
  the mode when the page has none. The `default` scheme is the look before, so
  little changes on the upgrade: only the info box takes the scheme's colours
  (the *light* style its surface, the *primary* style a shade of its accent),
  instead of Bootstrap's grey and blue.

## [0.3.5] – 2026-10-30

**Pages assembled from components.** The fourth step towards 0.4.0: a page's
own layout and components (shown only on it, arranged by editors too), the
front page as a chosen page, the sample front page.

**Upgrading from 0.3.0–0.3.4:** upload the new files and run the upgrade
(`php bin/campanella install`, or `/admin/upgrade`): it adds a column (schema
version 21), and the existing pages become pages assembled from components
(with no components yet). If you keep a `config/blueprints.php` or
`config/app.php` of your own, add `Composed` to the `page` Blueprint and to the
capabilities, as in the new ones. The front page stays the latest content until
you choose a page. From older versions: see [docs/upgrading.md](docs/upgrading.md).

### Added

- **`Composed`** ([PHP API, chapter 29](docs/php-api/29-layouts.md#a-pages-own-components)):
  a page assembled from components, with a layout of its own (`page_layout`)
  and components of its own (`component_page`), shown only on its page, after
  its text, first in its regions; the core's `page` Blueprint has it. A deleted
  page's components are deleted with it (`DeletePageComponents`).
- The layouts' regions say whether a page's own components may be placed in
  them (`"page": true`: the core's content and sidebar);
  `LayoutRegistry::pageRegionNames()`.
- `ComponentService`: `create()` for a page, `pageProblem()`, `pageOf()`,
  `ofPage()`; `inRegion()`, `nextWeight()` and `move()` among the site's or a
  page's own. `ObjectService::allows()`.
- **The page's components** in the admin (`PageComponentPages`): from a
  page's form, its layout (its own, or its content type's) and its layout's
  regions meant for a page, each with its own components: up, down, delete,
  and a new one in the three steps (the region kept), for **editors** too; a
  page's own component's form and delete page lead back to the page. The
  Components list says whose page a component is; the Regions page lists the
  site's only.
- **The front page** can be a page assembled from components (*System → Site
  settings*, *Front page*; `SiteSettings::frontPage()`): shown at `/` with its
  components, its canonical address `/`, its own path leading there (301), in the
  sitemap at `/` only; while it is a draft, the latest content, as before (the
  default, and what the upgrade keeps).
- The sample content's front page (`seed`): a page with a sidebar, the latest
  news and the upcoming events as cards, a contact box.
- Settings of the displays: `full` gets `show_date`, `cards` `show_title`;
  `render_object()` takes a display's settings; `ListRegistry::loadStored()`.

### Security

- The steps of a component read an object source with the actor's rights (an
  editor could otherwise have learnt the title of an object they may not see);
  a page's own component shows only in the regions meant for a page, however it
  was saved ([the review](docs/security.md#review-of-the-pages-assembled-from-components-035-2026-10-30)).

### Documentation and tests

- The guide's chapter 15, *Pages assembled from components* (hu, en), with
  screenshots of a page's components, a page's new component, the front page's
  setting and the sample front page.
- CI: the upgrade from 0.3.4 (MariaDB 11.4).

### Changed

- The policy: editors may create, change and delete **a page's own components**
  (the site's stay with the administrators). `ObjectService::update()` checks
  the changed object too, so a change cannot make it something the actor may
  not change.
- Schema version 21: the `component_page` column of `cap_component`.

## [0.3.4] – 2026-10-29

**Regions and layouts.** The third step towards 0.4.0: layouts with regions,
components placed in them (in an order, on the pages they are meant for), the
site's header and footer made of components, tokens in the snippets.

**Upgrading from 0.3.0–0.3.3:** upload the new files and run the upgrade
(`php bin/campanella install`, or `/admin/upgrade`): it adds a column (schema
version 20), makes the header's and footer's components as the header looked,
and turns the layouts on. If you keep a `config/blueprints.php` of your own, add
`Weighted` to the `component` Blueprint, as in the new one. A theme that
overrides `base.html.twig` keeps its own header and footer. Turn PHP's JIT off
(`opcache.jit=disable`) if it is on. From older versions: see
[docs/upgrading.md](docs/upgrading.md).

### Added

- **Layouts** ([PHP API, chapter 29](docs/php-api/29-layouts.md)): a template
  and a JSON file of its regions (`layout/<name>.html.twig`, `.json`), from the
  core and the theme (`LayoutDefinition`, `RegionDefinition`,
  `LayoutRegistry`); the core's *one column* and *with a sidebar*, with a
  *header* and a *navigation* region (collapsed below `md`), content and
  footer. Each region's look on small screens: always, collapsed or hidden
  below a Bootstrap breakpoint.
- `region()` and `page_layout()` in templates; the core's `base.html.twig`
  shows the page's layout once layouts are on (`layout.enabled`): the site's,
  or its Blueprint's (`LayoutService`).
- **Placing components:** a component's region, order (`Weighted`) and
  conditions (`Conditions`: paths with a wildcard, Blueprints, the front page,
  except paths); `ComponentService::place()`, `forRegions()` (one query for a
  page's components).
- **Tokens** ([PHP API, chapter 30](docs/php-api/30-tokens.md)): `[site:name]`,
  `[site:slogan]`, `[site:url]`, `[date:year]`, `[campanella:version]` in the
  snippets' texts, replaced when shown, escaped; an unknown one stays as it is.
  `TokenRegistry` and `TokenProvider` (`config/app.php`: `tokens`), the list
  under a snippet's editor, the `tokens` filter.
- **Appearance → Layouts** in the admin (`/admin/system/layouts`, for the system
  roles; `LayoutPages`): the site's layout, a layout for each Blueprint whose
  objects have pages, and each region's look on small screens (always shown,
  collapsed or hidden below 576, 768, 992 or 1200 px). `LayoutService::save()`
  checks and saves them, all or nothing. Until the layouts are on, the page
  says so; what is saved counts from then on.
- **Appearance → Regions** (`/admin/component/regions`): a layout's regions
  with their components in their order, and their conditions in words; a
  component added to a region's end, moved up or down, taken out
  (`ComponentService::inRegion()`, `nextWeight()`, `move()`).
- The component's form says **where it shows**: its region, and on which pages
  (the front page, paths with a wildcard, the Blueprints with pages, except
  paths). The components' list shows their region.
- **The header and footer are components** (`LayoutSetup`): a new installation
  and the upgrade to 0.3.4 make them as the header looked (the site's name, the
  main menu, the search box, the admin and login buttons; the footer a snippet:
  `© [date:year] [site:name] · Campanella [campanella:version]`) and turn the
  layouts on. A site without a main menu gets one with the header's built-in
  links, which `seed` fills with the sample menu. `install` says which
  components it made.
- The guide's chapter **14. Layouts and regions** ([hu](docs/guide/hu/14-elrendezesek.md),
  [en](docs/guide/en/14-layouts.md)): the header's and footer's components, the
  tokens, a sidebar beside the articles, the regions' order, where a component
  shows, on small screens; chapters 2, 4 and 13 follow, the screenshots are new.
- Tests: layouts, conditions, the layouts' and regions' pages, placing
  components, the tokens, the header and footer made once (and `seed` filling
  the menu); browser tests for the regions and the layouts; every upgrade test
  checks the header and footer; the upgrade test from 0.3.3 too.
- The System page warns while **PHP's JIT** is on (`SystemCheck::jitIsOn()`):
  PHP 8.3's JIT (8.3.35, 8.3.36) made PHP itself crash now and then in
  Campanella's tests; `opcache.jit=disable` turns it off, the OPcache stays
  ([why](docs/php-api/14-system-check.md#the-php-jit)).

### Changed

- **The header:** the menu, the search and the buttons fit in one row on a
  laptop (the header and footer are 72rem wide, the text stays 48rem); where they
  do not, the menu stays whole and the rest goes below it, to the right (before,
  the menu broke into two lines or the login button went below). The current
  page's menu link stands out.
- The performance check allows 75 SQL statements on the site's pages (60 on the
  admin's): the header and footer components are about 15
  ([testing](docs/testing.md#the-layouts-since-034)). The upgrade check allows
  rows added by the upgrade (never lost).
- The CI runs PHP with the JIT off (`setup-php` turns it on): the browser
  tests' web server crashed with it, in code unchanged since 0.1 (the same
  happens with 0.3.3's code).

## [0.3.3] – 2026-10-28

**Displays and components.** The second step towards 0.4.0: the ways of
showing something (a menu as navigation, a file as a download, a list as cards,
the visitor as a login button), and components, made in the admin, that show a
source with a display; the admin's menu in groups.

**Upgrading from 0.3.0–0.3.2:** upload the new files and run the upgrade
(`php bin/campanella install`, or `/admin/upgrade`): it adds a table (schema
version 19). If you keep a `config/blueprints.php` of your own, add the
`snippet` and `component` Blueprints from the new one. From older versions: see
[docs/upgrading.md](docs/upgrading.md).

### Added

- **Displays** ([PHP API, chapter 28](docs/php-api/28-displays.md)): `Display`
  and `AsDisplay` (a display is offered for an object because of its
  capabilities, for a list, or for a system source), `DisplayRegistry`
  (`config/app.php`: `displays`, `display_sources`), `Subject`,
  `DisplayContext`, `DisplayRenderer`. The built-in displays: `full`,
  `teaser`, `link`, `info_box`, `navigation`, `image`, `download`, `cards`,
  `table`, `login_button`, `admin_button`, `login_form`, `user_card`,
  `search_box`, `branding`, `breadcrumbs`, each with its settings and its
  template (`templates/display/`, which a theme can override).
- **Sources:** an object (`object:12`), a list made in the admin
  (`list:esemenyek`), the page's object (`current`), and the system sources
  `visitor`, `search`, `site`, `breadcrumbs`. A source is always read with the
  visitor's rights: a draft, a file for members or a restricted page is not
  shown to one who may not see it.
- `display()` in templates: `{{ display('list:hirek', 'cards', {count: 3}) }}`,
  `{{ display(file, 'download') }}`.
- The **`snippet`** Blueprint: a title and a formatted text, without a page of
  its own, for the texts a display shows in a box (opening hours, a contact).
- `MenuBuilder::buildFor()`: the items of a menu already read.
- **Components** ([PHP API, chapter 28](docs/php-api/28-displays.md#components)):
  objects of the `component` Blueprint (a title, a machine name, who sees it,
  and the `Component` capability with the source, the display and its
  settings), made in the admin under *Appearance → Components* in three steps
  (the source, the displays that can show it, the form with the display's
  settings), for administrators only. `ComponentService` checks them on every
  save and tells why one no longer works (its object was deleted …); the list
  marks those. `{{ component('lablec_menu') }}` shows one in a template, with
  the visitor's rights.
- **The admin's menu in groups** (`AdminMenu`, [chapter 13](docs/php-api/13-admin.md#the-menu-adminmenu)):
  *Content*, *Media library*, *Audience*, and for the system roles
  *Appearance*, *Structure*, *System*. Built in PHP, so a module can add its
  links. The admin's addresses did not change.
- `DefaultPolicy::ADMINISTRATORS_EDIT` (by default `component`): objects only
  administrators may change, seen as any object.
- The guide's chapter **13. Components** ([hu](docs/guide/hu/13-komponensek.md),
  [en](docs/guide/en/13-components.md)): snippets, a component in three steps,
  who sees it, in a template; the other chapters follow the new menu, and the
  screenshots are new.
- Tests: the displays' and the components' (a source read with the visitor's
  rights, the admin's three steps, administrators only); a browser test making
  a component; the upgrade test from 0.3.2 too, and a site with a Blueprint of
  its own called `snippet`.

### Changed

- The admin's sidebar: the links in groups (above); the profile only in the top
  bar (the user's name), *System* became *Status* in the *System* group.
- `render_object(item, 'teaser')` and `'full'` render the `teaser` and `full`
  displays. Their templates are the same (`object/<blueprint>--<mode>.html.twig`,
  `object/<mode>.html.twig`), with the same HTML; they also get `settings`
  (`show_title`; `show_date`, `show_lead`), which the core's templates use.

### Security

- **symfony/mime 7.4.22** (CVE-2026-45070, an e-mail header injection through
  parameter names with characters that are not allowed there). Campanella's own
  e-mails do not put text from a visitor into such a name, so it was not
  reachable through them; updated anyway, as `composer audit` requires.

### Fixed

- The browser tests waited for a click, not for the page it loads, before
  checking an error message (the forgotten password's, the honeypot's): a
  failure now and then on a slower machine.

## [0.3.2] – 2026-10-28

**Files to download.** The first step towards 0.4.0: besides images, a site
offers documents, spreadsheets and text files to download, for everyone, for
the logged-in users, or for some roles; images and files are kept together
in the admin's media library, and the editor links to a file.

**Upgrading from 0.3.1 or 0.3.0:** upload the new files and run the upgrade
(`php bin/campanella install`, or `/admin/upgrade`): it adds two tables
(schema version 18). Back up `var/files/` from now on, with the database and
`public/media/`. If you keep a `config/blueprints.php` of your own, add the
`file` Blueprint from the new one. From older versions: see
[docs/upgrading.md](docs/upgrading.md).

### Added

- **Files to download** ([PHP API, chapter 27](docs/php-api/27-files.md)): a
  `file` Blueprint (`Downloadable`), checked by its content (PDF, Office,
  OpenDocument, text; zip only by a setting; macro-enabled Office files and
  OpenDocument files with scripts, HTML, SVG and programs never), stored
  outside the web root (`var/files/`), always sent by Campanella as a download
  (`/files/<id>/<name>`, `paths.files`), with an ETag; `file_url()` in templates.
- **Who may see an object** (`Restricted`): everyone, every logged-in user,
  or the users with some roles; the `DefaultPolicy` applies it everywhere
  (lists, relations, the search), and a file not meant for the visitor is a
  404.
- **The site's own roles** (`users.roles`, e.g. `member`): given to users in
  the admin, without entering it.
- **Files in the admin** (part 2): the *Files* list uploads several files at
  once (chosen or dropped), with who may download them; the edit form shows the
  file's data and sets who may see it (the site's roles as checkboxes).
- **The media library** (*Media*, with *Images* and *Files* under it): images
  and files together, by kind and title, and one upload form for both.
- **The editor's file picker**: links the selected text to an uploaded file,
  in both toolbars.
- A file's delete page lists the texts that link to it; on the site, a
  relation to a file is its download link with its type and size.
- `Response::file()`: a body sent from a file in pieces.
- The system page's *Images and files* group: the files' folder, types and
  largest size.
- The guide's chapter 5, *Images and files* (Hungarian and English, with
  screenshots): the Files list, who may download, the site's own roles, a file
  in a text, the media library; backups, troubleshooting and the menu updated.
- **Upgrade tests from 0.3.1** too; they upload and download a file on the
  upgraded site, and from 0.2.9 on the old site has a Blueprint called `file`
  that must keep its name.
- The security notes on files ([security](docs/security.md#review-of-the-files-to-download-032-2026-10-28)):
  what is checked, and what is accepted (what a document does when opened).
- The browser tests run PHP's built-in web server under gdb on CI
  (`CAMPANELLA_TEST_GDB=1`): if PHP itself crashes, as it did once on CI in the
  importer's test, the log shows the last requests and the backtrace.

### Changed

- **A Blueprint made in the admin keeps its name** when a later version brings
  one of that name in `config/blueprints.php` (`BlueprintRegistry::yielded()`):
  a site with its own `file` Blueprint keeps it and its objects, and files are
  off until `files.blueprint` names another. Before, the stored one was left
  out.
- Schema version 18: the `cap_downloadable` and `cap_restricted` tables.
- **The admin's menu** has *Media*, with *Images* and *Files* under it; the
  images left *Content*. The System page's *Images* group is *Images and
  files*. The image upload's button says *Upload*.
- **The design of 0.4.0's pages** ([components](docs/components.md)): a
  component is a source, a display and its settings, placed in a layout's
  regions (built in 0.3.3–0.3.5).

### Fixed

- **PHP's built-in web server** (development, tests) handed an address with
  an extension (`/files/12/a.pdf`) to the site with a wrong base path; the
  site's `public/index.php` corrects it.

## [0.3.1] – 2026-10-26

**Importers, step 1: CSV and JSON files.** The first step after the 0.3.0
milestone: content can be brought in from a spreadsheet or another system's
export, again and again, and a list of the admin can be taken out as CSV.

**Upgrading from 0.3.0:** upload the new files and run the upgrade
(`php bin/campanella install`, or `/admin/upgrade`): it adds the importers'
three tables (schema version 17). From older versions: see
[docs/upgrading.md](docs/upgrading.md).

### Added

- **Importers** ([PHP API](docs/php-api/26-importers.md)): an importer turns the
  rows of a CSV file or the objects of a JSON file into objects of a Blueprint,
  by a mapping of the file's columns to its fields and relations, with a unique
  key, so a file can be imported again: an unchanged item is skipped, a changed
  one updates its object, a new one makes an object. Values are converted by
  the field types (decimal commas, Hungarian and ISO dates, a choice by key or
  label, several values with a separator), formatted text goes through the
  HTML filter; a relation's target is found by machine name, path or title,
  and if it is not found the item fails, is imported without the link, or the
  target is made (per importer). A trial shows the first items as they would
  become, changing nothing; a run goes on in batches, in the name and with the
  permissions of the importer's user, and leaves a log (made, updated,
  unchanged, failed, the errors by row); a run can be undone (the objects it
  made deleted, after a backup). An object whose item left the file is kept or
  made a draft; an import never deletes. Never started by a visitor's request.
- **System → Importers** in the admin, for the system roles: an importer made
  (its Blueprint, format, key and title columns), then its settings and
  mapping (the Blueprint's fields and relations offered by type), its user;
  a file uploaded (at most 5 MB) shows the trial of its first items, then the
  run goes on by itself step by step while its page is open (or by hand),
  with its progress, counts and errors by row; stopping and undoing a run.
- `php bin/campanella import:run <importer> <file> [--yes]` (a trial, or the
  run, e.g. from cron) and `import:undo <run> [--yes]`.
- The tables `importers`, `import_runs`, `import_items` (schema version 17).
- **The admin's list as CSV** (the *CSV export* button of every content
  list): with its search, filters and order, every page, in the form an
  importer reads (the machine names as headings, `;`, UTF-8 for Excel); a text
  a spreadsheet would run as a formula gets an apostrophe in front.
- The site owners' guide's new chapter, **Importing from a spreadsheet**
  ([en](docs/guide/en/12-importing.md), [hu](docs/guide/hu/12-importalas.md)),
  with screenshots.
- The upgrade tests also start from 0.3.0, and run an importer on every
  upgraded site.

### Fixed

- A throttling window given in negative seconds was read as positive by some
  PHP 8.3 versions (`+-5 seconds`); it only showed in a test.

## [0.3.0] – 2026-10-24

**The third milestone: the content model in the admin.** 0.3.0 releases what
0.2.2–0.2.9 built, as one whole, tested, documented and promised for the
longer term:

- **Fields to filter and sort on** (0.2.2): a Blueprint's own queryable
  fields, six new field types (decimal, date, e-mail, web address, choice,
  formatted text).
- **Blueprints made in the admin** (0.2.3, 0.2.4): a new kind of content with
  its capabilities, fields and relations, changed and deleted safely, with a
  backup first.
- **Lists made in the admin** (0.2.5): conditions, an order, pages, cards or a
  table, a path of their own, `list()` in templates.
- **The content model's export and import** (0.2.6): to carry it to another
  site, with a plan first, never deleting.
- **Search, redirects, an image picker** (0.2.7).
- **The admin's own columns** with sorting and filters (0.2.8), and
  **visitors' filters** on the lists (0.2.9).

What 0.3.0 itself adds is below: no new feature, but a security review of
0.2.2–0.2.9, upgrade tests from 0.2.0 and 0.2.9 (the latter with a content
model), the upgrade guide for 0.3.0, a performance check with 10 000 objects,
the guide's chapter on one's own content types, sample events, a few fixes,
and the [compatibility promise for 0.3.x](docs/compatibility.md): within 0.3.x
the Public API, the settings, the content model (its stored forms and its
file), the templates and their variables, the Twig functions, the commands
and the addresses only grow. **0.2.x gets security fixes until 0.4.0 is out.**

**Upgrading from any 0.2.x or 0.1.x** (in one go): follow
[docs/upgrading.md](docs/upgrading.md). In short:

1. Make a backup (the database, `public/media/`, `config/local.php`).
2. Upload the release package, or `git pull` and `composer install --no-dev`.
3. Run `php bin/campanella install`, or open `/admin/upgrade`. From 0.2.7,
   0.2.8 or 0.2.9 the database does not change (the schema version stays 16;
   0.3.0 adds none).
4. From 0.2.6 or older: put the existing pages into the search (the System
   page's button, or `php bin/campanella search:index`). From 0.1.x: also the
   steps of [From 0.1.x](docs/upgrading.md#from-01x).
5. Optionally `php bin/campanella seed` for the sample events (only what the
   site does not have is added).

### Security

- The [security review for 0.3.0](docs/security.md#security-review-for-030-2026-10-24)
  of 0.2.2–0.2.9. No critical or high issue; fixed:
  - the search is limited to 30 searches a minute per network (then 429);
  - an imported Blueprint's choice keys follow the editor's pattern (at most
    200 choices), and its relations cannot point to users, forms' messages or
    a Blueprint the site does not have;
  - a redirect's `Location` header is ASCII (accented letters percent-encoded);
  - a page number too large for the search or the redirects' list is an empty
    page or a 404, not an error.

### Added

- Upgrade tests from 0.2.0 (MySQL 8.4) and from 0.2.9 (MariaDB 10.6,
  MySQL 8.0) in CI, besides 0.1.0 and 0.1.5. From 0.2.6 on the old site gets
  a content model made as in the admin (a Blueprint with admin columns, a
  list with a filter, events, a changed path), and the checks cover it: its
  values, pages, admin pages, redirect, search, and export
  ([The upgrade test](docs/php-api/17-migrations.md#the-upgrade-test)).
- The performance check (`tests/performance/run.sh`), in CI on MariaDB 10.6
  and MySQL 8.0: 10 000 objects of a Blueprint with own fields, then a list's
  pages with filters, the admin's list with its columns and filters, and the
  search, each within 500 ms (the median) and 60 SQL statements. All of them
  answer in 15–135 ms, with the same number of queries for any number of
  objects ([The performance check](docs/testing.md#the-performance-check)).

- The site owners' guide's new chapter, **Your own kinds of content: an event
  calendar** ([en](docs/guide/en/11-own-content-types.md),
  [hu](docs/guide/hu/11-sajat-tartalomtipusok.md)), with screenshots: a
  Blueprint and its fields, changing them, the admin's columns, the events, a
  list of the upcoming ones with visitors' filters and a menu item, carrying
  the model to another site with an import's plan, deleting. The search and
  the redirects in the chapter on content, the new System pages in the first
  steps, new rows in the troubleshooting.
- **Sample content** (`seed`): the Blueprint *Esemény* made as in the admin
  (a start, a kind, a venue, a ticket price, with admin columns), the list
  *Közelgő események* at `/esemenyek` with visitors' filters, seven events,
  and *Események* in the main menu. Only if the site has no Blueprint or list
  of these names.

### Changed

- The [compatibility promise](docs/compatibility.md) is for 0.3.x: besides
  what 0.2.x promised, the stored forms of the Blueprints and lists made in
  the admin (version 1), the content model's file (format 1), the addresses
  `/search`, a list's path and its `?f[…]` filters, the commands
  `model:export`, `model:import`, `search:index`, and the Public classes added
  since 0.2.0. 0.2.x gets security fixes until 0.4.0 is out; 0.1.x none.
- The [upgrade guide](docs/upgrading.md) is for 0.3.0: from any 0.2.x or
  0.1.x in one go, with the database's changes, what is new since 0.2.0, what
  behaves differently, and a new checklist.
- The Blueprint editor keeps what a list made in the admin uses: a Blueprint, a
  field or a relation in a list's condition, order, columns or filters is not
  deleted, nor a field made unqueryable; the message names the list (a list
  left broken was hidden before).

### Fixed

- The admin's own columns showed a value of 0 (a price, a number) as empty.
- System → Blueprints named the newer field types by their keys
  (`structure.type.decimal`); it uses the editor's names now. The *made here*
  label was hard to read.
- A list's date filter for visitors left too little room for the dates on a
  wide screen.
- A main menu too long for one line broke inside an item (*Opening / hours*);
  it wraps between the items now.
- `composer lang:check` also checks the keys made from a value (the field
  types, the lists' operators, displays and relative dates).

## [0.2.9] – 2026-10-24

**Visitors' filters on the lists.** The eighth and last step before the 0.3.0
milestone: a list made in the admin can let its visitors narrow it, e.g. to
one kind of event or a range of days.

**Upgrading from 0.2.8:** upload the new files. The database does not change
(the schema version stays 16).

### Added

- **Filters for visitors** ([PHP API](docs/php-api/23-lists.md#visitors-filters)):
  a list's editor chooses at most 4 queryable fields (a choice, yes/no, a
  date, a number) and relations; its page shows them as a form above the list
  (`list/_filters.html.twig`, overridable by a theme), sent as `?f[…]`. They
  add to the list's own conditions (what is not published stays hidden), keep
  to the pages, and an invalid value is ignored. A filtered page is `noindex`,
  its canonical address the list's own. The content model's export carries
  them.
- `Campanella\Query\FieldFilters`: the filters by a field's type or a
  relation's targets, shared by the admin's lists and the visitors' filters;
  `PageMeta::notIndexed()`.

### Changed

- `Admin\ListColumns` uses `FieldFilters` (its constructor takes one).

## [0.2.8] – 2026-10-24

**The admin's list with the own fields.** The seventh step towards 0.3.0: a
Blueprint's list in the admin shows the fields that matter for it, sorts by
them and filters by them.

**Upgrading from 0.2.7:** upload the new files. The database does not change
(the schema version stays 16).

### Added

- **Own columns in the admin's lists** ([PHP API](docs/php-api/13-admin.md#the-list)):
  at most 4 of a Blueprint's own fields and relations as columns, the same for
  every user, chosen in the Blueprint editor (*Columns of the list*), or with
  the `admin_columns` key in `config/blueprints.php` (`BlueprintRegistry::MAX_ADMIN_COLUMNS`).
  A queryable single-valued column sorts the list by its header; the queryable
  ones get filters above the list by their type: a choice, yes/no, a date or a
  moment (from, to, in the site's time zone), a number (at least, at most), and
  a relation's targets (while there are at most 200). They combine with the
  title search and the status filter, in the address (`?f[fajta]=seta`); a
  value that is not valid is ignored. A text is shown as a short plain
  excerpt.
- The content model's export and import carry the columns (`admin_columns`
  in the stored form of a Blueprint).

### Changed

- The articles' list in the admin shows their categories, with a filter
  (`'admin_columns' => ['categories']` in `config/blueprints.php`).

## [0.2.7] – 2026-10-24

**Search, redirects and an image picker.** The sixth step towards 0.3.0: the
site can be searched, a page keeps its old addresses when its path changes,
and the editor inserts images uploaded before.

**Upgrading from 0.2.6:** upload the new files, then run the upgrade
(`/admin/upgrade`, or `php bin/campanella migrate`): it adds the
`search_index` and `redirects` tables (schema version 16). Then add the
existing pages to the search: the System page has a button for it (press it
until it is done), or run `php bin/campanella search:index`.

### Added

- **Search** ([PHP API](docs/php-api/25-search.md)) with the database's
  FULLTEXT indexes, at `/search` (`paths.search`), with a search field in the
  default theme's header. It finds the published pages (objects with a path),
  by every word as the start of a word, with or without accents; a match in
  the title counts more; results a page at a time, with an excerpt, the words
  marked. Users, messages and files are never found. The new `search_index`
  table (schema version 16) is written on every save; the pages saved before
  are added from the System page (a button) or with
  `php bin/campanella search:index` (`--all`: every page again). The search
  page is `noindex` and not to be crawled (`robots.txt`).
- **Redirects** ([PHP API](docs/php-api/20-site.md#redirects-redirects)): when
  an object's path changes, its old path leads to it with `301 Moved
  Permanently` (to its current path, however often it changes again), and
  **System → Redirects** in the admin lists them, makes new ones from a path to
  a path of the site, and deletes them. A redirect is used only where no page
  answers; the query is kept; each use is counted. The new `redirects` table
  (schema version 16).
- **An image picker in the editor** ([PHP API](docs/php-api/16-media.md#the-image-picker)):
  a toolbar button lists the uploaded images (the newest first, with a search
  by title, a page at a time); a click inserts one where the cursor was, with
  its alternative text. `GET /admin/media/browse` answers in JSON.
- FULLTEXT indexes in table definitions (`Table::$fulltexts`), made, read and
  compared by the schema classes.
- The browser tests open the image picker, search the site, and check the
  System pages of 0.2.3–0.2.7 for administrators and editors.

## [0.2.6] – 2026-10-23

**The content model's export and import.** The fifth step towards 0.3.0: the
Blueprints and lists made in the admin go into one file and can be carried to
another site, e.g. from a test site to the live one. An import shows its plan
first and changes nothing until it is confirmed; it never deletes anything.

**Upgrading from 0.2.5:** upload the new files. The database does not change
(the schema version stays 15).

### Added

- **The content model's export and import** ([PHP API](docs/php-api/24-content-model.md)):
  the Blueprints and lists made in the admin in one JSON file
  (`campanella-model.json`). An import first shows its plan (what is new, what
  changes, what stays, and why something cannot be imported), and is done
  only without errors, all of it, with the admin editors' rules (a field's
  type only to a wider one, the choices in use kept, and so on), a backup first
  when objects are affected. It never deletes: what the site has and the file
  does not stays. A list's relation condition names its objects by machine
  name, path or title, not by ID. The Blueprints of `config/blueprints.php`
  are not in the file.
- **System → Content model** in the admin, for the system roles: the
  download, the upload (at most 1 MB) and its plan, the confirmation. The
  uploaded file is not stored; a confirmation is applied only if the plan is
  still the same.
- `php bin/campanella model:export [file] [--force]` and
  `php bin/campanella model:import <file> [--yes]` (without `--yes`, only the
  plan).

### Changed

- The list editor's check of a path is a class of its own, `ListPathCheck`,
  used by the import too.

## [0.2.5] – 2026-10-15

**Lists made in the admin.** The fourth step towards 0.3.0: the counterpart of
`config/queries.php` that needs no code. A list shows the objects of one
Blueprint, under conditions on its fields and relations (with `today` and
`now` for dates, so "upcoming events" keeps itself up to date), in a chosen
order, a page at a time, as short cards or as a table, at a path of its own
and in templates.

**Upgrading from 0.2.4:** upload the new files, then run the upgrade
(`/admin/upgrade`, or `php bin/campanella migrate`): it adds the `lists`
table (schema version 15). Nothing else changes for an existing site.

### Added

- **Lists** ([PHP API](docs/php-api/23-lists.md)): which objects of a
  Blueprint, under which conditions (on queryable fields and relations, with
  `today` and `now` for dates), in what order, how many per page, shown as
  teasers or as a table, at a path of their own. Stored as JSON in the new
  `lists` table (schema version 15), checked against the Blueprints whenever
  they are loaded. Their pages are in `sitemap.xml`.
- **System → Lists** in the admin: a new list (its name, title and
  Blueprint), and on its page the title, path, number per page, display,
  conditions (on the fields that can be filtered on, with a value or today /
  now; on relations, by choosing the targets), order and the table's columns;
  deleting it. A path that a route, the admin, a public folder or an object
  has is refused.
- `list('name')` and `field_value(item, 'field')` in templates.

## [0.2.4] – 2026-10-15

**Changing and deleting fields in the admin.** The third step towards 0.3.0:
a Blueprint made in the admin can now be reshaped after it has content. A
field's type can be widened, it can get or lose its column, one value can
become several or the other way round, it can be made required, and fields
and relations can be deleted. Every change that touches data is done after a
backup, and what cannot be done safely is refused with the reason.

**Upgrading from 0.2.3:** upload the new files. The database does not change
(schema version 14), so there is nothing to run.

### Added

- **The SchemaSync follows a changed own field** of a Blueprint: a widened
  type (whole number → decimal, text → long text, date → date and time, a
  longer text) changes its column; a field made required gets its default
  in the empty values first; a queryable field with one value that gets
  several, or the other way round, has its values moved between the column
  and `field_values`; several values of a widened type move into its column
  of `field_values`. Anything else is Blocked. `migrate` backs up first
  (`SyncPlan::changesData()`).
- **Changing a field in the admin:** its type to a wider one, whether it can be
  filtered and sorted on (its column), one or several values (several to one
  only after confirming that the first value of each object stays), its
  length, and making it required (the empty values get its default; without
  one, the form says why not). Applied at once, with a backup first if the
  Blueprint has objects.
- **Deleting a field or a relation in the admin**, after ticking a
  confirmation: the page says how many objects have a value (or how many
  links there are); a backup is made first; then its column (or the
  Blueprint's table, if nothing else is left), its rows in `field_values` and
  its key in the objects' JSON data, or the relation's links, are deleted.

### Fixed

- A field of the JSON data that had several values and has one now is read
  as its first value (it threw an error).

## [0.2.3] – 2026-10-14

**Blueprints made in the admin.** The second step towards 0.3.0: an
administrator can now make a new kind of content in the browser, with its
capabilities, fields and relations, without touching `config/blueprints.php`.
Its objects are edited, listed and shown like any other.

**Upgrading from 0.2.2:** upload the new files, then run the upgrade
(`/admin/upgrade`, or `php bin/campanella migrate`): it adds the `blueprints`
table (schema version 14). Nothing else changes for an existing site.

### Added

- **Making Blueprints** under System → Blueprints → New Blueprint: a name, a
  machine name and capabilities (the content ones; never those of users,
  images, forms' messages or menus). On its page: fields (every type of 0.2.2;
  required, filter and sort by it, several values, translatable, choices,
  length, decimals, default), relations to another Blueprint's objects (one
  or several, required), the fields' order, its name and capabilities, and
  deleting it while it has no objects
  ([PHP API](docs/php-api/13-admin.md#making-blueprints-blueprinteditor)).
- Every change is checked by defining all the Blueprints again; when the
  database changes and the Blueprint has objects, **a backup is made first**;
  if applying the change fails, the old definition is put back. A required
  field that can be filtered on asks for a default when there are objects
  already.
- The definitions are stored in the new `blueprints` table as JSON
  (`BlueprintStore`, `BlueprintDefinition`: the format the content model's
  export will use). A stored definition that does not pass the checks is not
  loaded, and the Blueprints page says why.
- The Blueprints page marks which ones are defined in code and which were made
  in the admin.

### Changed

- No Blueprint's own field may be called `id`, `uuid`, `blueprint`,
  `created`, `updated` or `object_id` (they were never usable: the first five
  are every object's base fields, the last the key of the Blueprint's table).

## [0.2.2] – 2026-10-13

**Fields to filter and sort on, and new field types.** The first step towards
0.3.0, the content model in the admin: a Blueprint's own fields can now be
queryable, stored in a table of the Blueprint's own, and there are six new
field types. The storage of translations (0.6.0) was designed at the same
time, so the new tables will not have to be rebuilt.

**Upgrading from 0.2.1:** upload the new files, then run the upgrade
(`/admin/upgrade`, or `php bin/campanella migrate`): it adds a column to the
`field_values` table (schema version 13). Nothing else changes for an
existing site.

### Added

- **Queryable own fields:** a Blueprint's `queryable` key lists own fields to
  filter and sort on. The single-valued ones get a column in the Blueprint's
  own table, `bp_<name>` (shaped like a capability's table); the multi-valued
  ones live in `field_values`. Queries use them by name; a field several
  Blueprints have is queried across all of them. Until now, every own field
  lived in the JSON data
  ([PHP API](docs/php-api/02-objects.md#blueprint)).
- **Moving the values** when an own field becomes queryable, or stops being:
  the upgrade (SchemaSync) moves them from the JSON data into the table, or
  copies them back; `migrate` asks and backs up first. Nothing is deleted.
- **New field types:** `Decimal` (a numeric string, with a `scale`),
  `Date` (a `CalendarDate`: a day without a time zone, which does not move
  to the day before west of UTC), `Email`, `Url` (an `http(s)://` address),
  `Choice` (one of the field's `choices`) and `Html` (formatted text, filtered
  on save like a formatted body). Each is checked on save and has its own
  form element in the admin.
- `Field`'s `translatable` flag (no effect until multilingual content) and
  [the design of translations](docs/translations.md): the original and a
  translation layer, without changing any existing table.
- `object_fields()` in templates: an object's own fields with a value, shown
  by type; the default full page of an object lists them after its text.
- `ColumnType::Decimal` and `ColumnType::Date`; the `value_decimal` column of
  `field_values`.

### Changed

- The `object/full.html.twig` template shows the object's own fields
  (except `lead`, shown above the text as before) after its text. A theme
  with its own copy of the template is not affected.
- `FieldType` has six new cases: code of one's own that `match`es every case
  of it should handle them too (or have a `default`).

## [0.2.1] – 2026-10-13

**A guide for site owners.** 0.2.1 adds a guide, in Hungarian and English,
for people who run a Campanella site but do not develop it, with screenshots
made by a script from a fresh site in each language. On the way, writing it
turned up a few small bugs, fixed here; dates now follow the interface
language.

**Upgrading from 0.2.0:** upload the new files
([chapter 9 of the guide](docs/guide/en/09-backups-and-upgrades.md)). The
database does not change, so there is nothing to run. Nothing your site
relies on changes ([compatibility promise](docs/compatibility.md)), except
the default date format (see Changed).

### Added

- **The guide for site owners** ([docs/guide/](docs/guide/README.md)), in
  Hungarian and English, with screenshots: installing (uploading, the
  settings file, the installer in the browser, what to do when something
  goes wrong) and the first steps (logging in, the admin, the site settings,
  the System page).
- The guide's chapters on content (articles, pages, categories, the editor,
  publishing now or later, deleting), menus and images (uploading, the
  alternative text, images in texts).
- The guide's chapters on users and one's own account (roles, new users,
  blocking, the profile and its logins, the forgotten password), the
  contact form and privacy (the messages, the privacy notice, a table of the
  personal data Campanella keeps), and e-mail (setting it up, the log,
  common problems).
- The guide's chapters on backups and upgrades (what to back up and how,
  restoring, upgrading step by step, going back) and on troubleshooting (the
  common problems, their causes and what to do; where to get help).
- `tests/guide/screenshots.sh`: makes the guide's screenshots from a fresh
  site in each language, installed from the browser as the guide tells.

### Changed

- **Dates follow the interface language:** the `date` filter's default
  format is the new `date.format` message (Hungarian `2026. 10. 12. 22:30`,
  English `12 Oct 2026, 22:30`); until now every site showed the Hungarian
  format.

### Fixed

- The System page's last e-mail and the E-mail log showed their times in UTC;
  now in the site's time zone, as the e-mails themselves since 0.2.0.
- The English profile's password button said "Changing my password"; now
  "Change my password".
- The default theme's header: with a longer main menu, a logged-in user's
  links (Admin, the name, Log out) ran out of the column on the right; they
  now wrap below the menu.
- The upgrade page: its log's long lines ran out of the box (now they wrap),
  and the English result said "1 migrations applied" (now "Migrations
  applied: 1").

## [0.2.0] – 2026-10-12

**The second milestone: the site communicates.** 0.2.0 releases what 0.1.1–0.1.5
built, as one whole, tested, documented and promised for the longer term:

- **For search engines and sharing** (0.1.1): the site's name, description
  and address edited in the admin; meta description, canonical URL and Open
  Graph tags on every page; `sitemap.xml` and `robots.txt`.
- **Images** (0.1.2): smaller copies for every screen (`srcset`), and where
  each image is used.
- **Events and e-mail** (0.1.3): e-mail through SMTP or PHP's own settings,
  with templates and a log; events with actions bound to them.
- **Forgotten password and sessions** (0.1.4): a single-use link by e-mail;
  every login listed on the profile and ended from there; a login lasts at
  most 12 hours.
- **Contact form** (0.1.5): on its own page or any page, protected against
  bots and floods; the messages for administrators only, by e-mail too, and
  deleted after a set time.

What 0.2.0 itself adds is below: no new feature, but an upgrade test from
0.1.0, browser tests, a check of the installation package, the upgrade guide,
a security review of the whole 0.1.x layer, e-mail times in the site's time
zone, and the [compatibility promise](docs/compatibility.md): within 0.2.x
the Public API, the settings, the templates and their variables, and the Twig
functions only grow.

**Upgrading from any 0.1.x** (0.1.0 to 0.1.5, in one go): follow
[docs/upgrading.md](docs/upgrading.md). In short:

1. Make a backup (the database, `public/media/`, `config/local.php`).
2. Upload the release package (`vendor/` included; it changed in 0.1.3), or
   `git pull` and `composer install --no-dev`.
3. Run `php bin/campanella install`, or open `/admin/upgrade`: a backup first,
   then the new tables (schema 12; 0.2.0 adds none) and, from 0.1.0 or 0.1.1,
   one migration.
4. From 0.1.0 or 0.1.1: make the images' smaller copies (System page, or
   `php bin/campanella media:variants`).
5. Set what is new: the site's address, e-mail, the privacy notice; check
   the [list of what behaves differently](docs/upgrading.md#what-behaves-differently)
   (logging in at `/login`, the contact form on, a blocked account's answer).

### Added

- **The upgrade test** (`tests/upgrade/run.sh`): installs 0.1.0 with its
  sample content, users of every kind and an image shown in an article,
  upgrades it to the current code as one uploads a new version, and checks
  that every row, column and media file survived, the old passwords work, and
  the pages, the admin and the new features work on the old data. In CI on
  MariaDB 10.6 and MySQL 8.0, and from 0.1.5 on MariaDB 11.4 (the `upgrade`
  job); an upgrade with the same schema (0.1.5 to 0.2.0) needs no backup.
- **The browser tests** (`tests/browser/run.sh`, Playwright with headless
  Chromium): a fresh site, a mail catcher and PHP's web server, and what the
  0.1.x series was checked by hand: logging in and out (the same answer for a
  wrong address and password, the honeypot, the CSRF token, a new session id,
  the cookie's flags), the lock-out, the session list, the forgotten password
  (the link read from the caught e-mail, used once, ending the old sessions),
  the contact form's protections and notification, the admin's lists and
  forms, Submissions for administrators only, the System page. Every page is
  watched for JavaScript errors, Content-Security-Policy violations and
  missing files. In CI as the `browser` job; described in the new
  [docs/testing.md](docs/testing.md).
- **The upgrade guide** ([docs/upgrading.md](docs/upgrading.md)): from any
  0.1.x to 0.2.0 in one document: the steps, the database changes, the new
  settings, what behaves differently, what a theme or custom code must
  follow, a checklist, and going back from the backup.
- **The compatibility promise** ([docs/compatibility.md](docs/compatibility.md)):
  within 0.2.x the Public PHP API, the configuration keys, the templates'
  names and variables, the Twig functions, the commands, the site's addresses
  and the data only grow; anything to be removed is marked **Deprecated** in a
  0.2.x first and goes in 0.3.0 at the earliest; security fixes come as
  0.2.1, 0.2.2 …
- **The package check** (`tests/package/check.sh`, the `package` job in CI):
  the installation package, built as the release builds it
  (`tests/package/build.sh`, which the release now uses too), unpacked into
  an empty folder, its contents checked (nothing of the tests, local settings,
  uploads or development packages; MIT-compatible licences only; LF line
  endings; PHP syntax), installed from the browser on a PHP with only the
  required extensions, and put through the browser tests. A release is
  published only if it passes.
- `Mailer::time()` and `Event::summaryWith()` (see Changed).

### Changed

- **Times in e-mails are in the site's time zone** (the `timezone` setting,
  e.g. `2026-10-12 21:16 CEST`), not UTC: the contact form's notification,
  the e-mails to users and administrators, the test e-mail, and a scheduled
  publication's time in the summary. The template variables (`received_at`,
  `occurred_at`, `sent_at`) stay strings. `Mailer::fromConfig()` and the
  constructor take the time zone as a new last argument (default `UTC`).
- `composer.json` requires `ext-ctype` and `ext-session` too, as the System
  page always did; the README lists the extensions a host needs, with `gd`
  for uploading images.

### Fixed

- **The upgrade's backup did not hold the old database.** `install`,
  `migrate` and the upgrade page created the new tables and wrote the new
  schema version first, and made the backup only then: restoring it brought
  back the new schema version with the old code, which then asked for an
  upgrade again. The backup is now made before anything is written, and an
  upgrade to a new schema version is always backed up, also when it only
  adds tables. Found while writing the upgrade guide's "Going back"; the
  upgrade test now checks it, and going back from 0.2.0 to 0.1.0 was tried
  by hand.
- A backup's first lines named the code's schema version; they name the
  database's now.
- `bin/campanella` was not executable in the release packages (it was
  committed without the executable bit), so `./bin/campanella` did not run;
  `php bin/campanella` did. Found by the new package check.

### Security

A review of the whole 0.1.x layer together (sessions, the forgotten
password, the contact form, e-mail, the work after the response, throttling;
[docs/security.md](docs/security.md#security-review-for-020-2026-10-11)).
No critical or high issue; fixed:

- **Blocking an account in the admin ends its logins at once.** Until now
  each ended at its next request, so a login that made none until the
  account was activated again went on (the command line's `--block` ended
  them already).
- **Logins and the forgotten password count an IPv6 address by its /48** for
  their per-address limits (`Throttle::network()`), as the contact form
  does; one connection often has a whole /48. The limit per account and
  address stays /64.
- **A blocked account gets the same answer as a wrong password**: "The
  account is blocked" told whoever tried that the password was right. The
  `auth.account_blocked` message is gone.
- The dependencies were checked against the advisories of 2026: none
  affects the versions in use. Three risks were added to the accepted ones
  (sessions started by forms, `X-Forwarded-Proto` behind appending proxies,
  the installer's limit file).

## [0.1.5] – 2026-10-11

The contact form: visitors can write to the site, the messages arrive under
*Submissions* in the admin (for administrators only) and by e-mail, and they
are deleted after a set time. An independent security review of the changes;
its findings are fixed.

**Upgrading from 0.1.4:**

1. Upload the new files. No new dependency.
2. Run `php bin/campanella install`, or open `/admin/upgrade` (schema version
   12: the `submitted` capability's table; no migration). Until then the site
   answers 503; logging in works.
3. The contact form is **on** at `/contact` once the upgrade ran. Link it
   from the menu (*Menus* in the admin), or put it on a page of your theme
   with `{{ contact_form() }}`; turn it off with
   `'contact' => ['enabled' => false]` in `config/local.php`.
4. For the e-mail about each message, e-mail must be set up; it goes to the
   administrators, or to `contact.notify_to`.
5. Give the privacy notice's address under *Site settings → Privacy*, and
   say there how long messages are kept (`contact.keep_days`, 180 days). On a
   quiet site, run `php bin/campanella submissions:prune` daily from cron.
6. **A custom access policy** (one that replaced `DefaultPolicy`) must hide
   the `submitted` objects itself: only `DefaultPolicy` does it.
7. The login and other throttling counters start again (their keys are now
   hashed with a secret of the installation): an account locked by too many
   wrong passwords at the moment of the upgrade is free again.

### Added

- **Submissions** in the admin (for administrators): the messages of the
  site's forms, newest first, unread ones marked and counted in the menu;
  opening one marks it read; reply by e-mail, mark unread, delete. A message
  is an object of the new `submission` Blueprint (`Submitted` capability:
  e-mail address, subject, message, form, read time; hidden from templates;
  no IP address stored). New: `Campanella\Capability\Submitted`,
  `Campanella\Service\SubmissionService`; schema version 12.
- **The contact form** at `/contact` (`paths.contact`), and on any page with
  `{{ contact_form() }}`: name, e-mail address, subject (optional), message.
  Protected by a CSRF token, the honeypot (`contact.guards`), a signed time
  (sent sooner than `contact.min_seconds`, 3 seconds: taken for a bot's),
  and limits in an hour: 5 messages per IP address (IPv6: per /48), 3 per
  e-mail address.
  `contact.enabled: false` turns it off. The contact page is in
  `sitemap.xml` while it is on. New: `Campanella\Webform\ContactForm`,
  `ContactResult`, `SubmissionService::check()`.
- **An e-mail about each new message** of the contact form, to the active
  administrators or to `contact.notify_to`; replying to it goes to the
  sender (`Reply-To`). Sent after the response; its subject holds nothing the
  sender wrote; at most `contact.notify_per_hour` (50) an hour on the whole
  site (the messages are all kept). `contact.notify: false` turns it off. New: the
  `FormSubmitted` event, the `MailSubmission` action, the `submission` mail
  template, `Mailer::send()`'s `$replyTo` and `$replyToName`.

- **Data protection for the contact form's messages:** they are deleted
  `contact.keep_days` (180) days after they arrived (after a new message,
  when an administrator opens the dashboard or the messages, and with the new
  `submissions:prune` command for cron; `0`: kept for ever); the form says what
  happens to a message, and links to the privacy notice, whose address is a
  new Site setting (*Privacy notice*, `privacy_url`); a *Contact form* group
  on the System page. New: `SubmissionService::prune()`, `keepDays()`,
  `SiteSettings::privacyUrl()`.

### Fixed

- **E-mails' footer:** the site's name and address were on one line.

### Security

- **An independent security review** of these changes; its findings are
  fixed (see [docs/security.md](docs/security.md#security-review-for-015-2026-10-11)).
- **The throttling counters keep only keyed hashes** (HMAC with a random
  secret of the installation, instead of a plain SHA-256: the IP and e-mail
  addresses in their keys could be recovered by trying every address), and
  expired rows are deleted (`Throttle::purge()`).
- **A response with a session is never stored by caches**, error pages
  included (`Cache-Control: private, no-store`).
- **Administrators-only objects:** `DefaultPolicy` keeps the objects of the
  `submitted` capability (personal data) from editors and visitors, in every
  query and operation (`DefaultPolicy::ADMINISTRATORS_ONLY`, the constructor's
  argument).

## [0.1.4] – 2026-10-10

Forgotten password and sessions: a forgotten password can be reset with a
link by e-mail, everyone sees on their profile where they are logged in and
can log out any of those, and a login lasts at most 12 hours. The paths of
logging in and out are settings (English by default). An independent security
review of the changes; its findings are fixed.

**Upgrading from 0.1.3:**

1. Upload the new files (`public/index.php` changed too). No new dependency.
2. Run `php bin/campanella install`, or open `/admin/upgrade` (schema version
   11: the new `sessions` and `password_resets` tables; no migration). Until
   then the site answers 503; logging in works.
3. **Logging in moved to `/login`** (was `/belepes`), logging out to
   `/logout`. To keep the old addresses (e.g. bookmarks):
   `'paths' => ['login' => '/belepes', 'logout' => '/kilepes']` in
   `config/local.php`. A site that changed `config/routes.php` removes the two
   old routes there. A theme's templates use `path('login')` and
   `path('logout')` instead of `url('/belepes')`.
4. For the forgotten password, e-mail must be set up and the site's address
   set (*Site settings*); otherwise it is not offered.
5. Logins from before the upgrade stay logged in; their 12 hours start at
   their next request.

### Added

- **The forgotten password**: *Forgot your password?* on the login page, a
  link by e-mail (`/password-reset`; the path is `paths.password_reset`) that
  sets a new password. Offered only while e-mail and the site's address are
  set up. The same answer and response time for every address (the account
  is looked up and the e-mail sent after the response); the link works once,
  for `auth.password_reset_minutes` (60) minutes, is stored only as a hash,
  and is voided by a newer link or any password change; requests and wrong
  links are limited; the new password ends every session. New:
  `Campanella\Auth\PasswordReset`, `UserService::resetPassword()`, the
  `password_resets` table (**schema version 11: run the upgrade**),
  `PasswordChanged::$byReset`, the `password_reset` mail template.
- **`MailUser`**, an action that e-mails the user an event is about (their
  account was created, their password changed), with its own overridable
  templates (`user_created`, `user_password_changed`, `user_event`). Not
  bound by default. New: the `UserEvent` interface.
- **Work after the response** (`Campanella\Core\Deferred`,
  `Kernel::terminate()`): `public/index.php` calls it after sending the
  response; a custom entry point should too. Where PHP cannot end the
  connection itself (mod_php), a response with such work has a
  `Content-Length` and `Connection: close`.
- **Where you are logged in**, on the profile (`/admin/profile`): every login
  in progress with its browser, IP address, the time of logging in and of
  the last activity; any other one can be logged out, or all of them at once
  (*Log out everywhere else*). A login ended so is logged out on its next
  request. A password set by an administrator now ends the user's logins on
  the list too. New: `Campanella\Auth\SessionRegistry`, `SessionInfo`,
  `AuthService::sessions()`, `endSession()`, `endOtherSessions()`; the
  `sessions` table (**schema version 10: run the upgrade**). The table holds a
  hash of each login's token, never the session ID.

### Security

- **An independent security review** of these changes; its findings are
  fixed (see [docs/security.md](docs/security.md#security-review-for-014-2026-10-10)).
  Among them: `user:password` on the command line now ends the user's
  logins (as an administrator's new password does), and blocking from the
  command line ends them too.
- **`Connection::tableExists()` answers no only for a missing table**
  (SQLSTATE `42S02`); any other database error is thrown instead of being
  taken for a missing table.
- **A login lasts at most 12 hours**, however actively it is used
  (`session.absolute_timeout`, in seconds; `0`: no limit). Until now only 2
  hours without activity ended a session, so a stolen session cookie kept
  working as long as it was used. Sessions from before this version start
  counting on their next request. `AuthService` takes the lifetime as its new
  last constructor argument.

### Fixed

- **A changed template could keep its old compiled copy** until the next
  version: when the files kept their original times (unpacking a ZIP, many
  FTP clients), Twig took them for older than their compiled copies. The key
  of a compiled template now holds a hash of its content
  (`Campanella\View\TemplateLoader`), so a changed template is always
  compiled again.

### Changed

- **Logging in and out moved to `/login` and `/logout`** (until now `/belepes`
  and `/kilepes`), and their paths are settings (`paths` in
  `config/local.php`, e.g. `'paths' => ['login' => '/belepes', 'logout' =>
  '/kilepes']` to keep the old ones; `SitePaths`). The login page's return
  parameter is `return` (was `vissza`). Templates: `path('login')`,
  `path('logout')` instead of `url('/belepes')`. The two routes left
  `config/routes.php`: a site that changed that file removes them there.

## [0.1.3] – 2026-10-10

Events and e-mail: Campanella says what happened (an article was published, a
user was created), actions can be bound to that in the configuration, and the
site can send e-mail through symfony/mailer, with templates and a log. The
forgotten password (0.1.4) and the contact form (0.1.5) build on these.

**Upgrading from 0.1.2:**

1. Upload the new files, and **install the new dependencies**:
   `composer install --no-dev` (with Docker: `docker compose exec web composer
   install`; then `docker compose up -d` to start the new Mailpit container).
   The release package of the tag has them in `vendor/`.
2. Run `php bin/campanella install`, or open `/admin/upgrade` (schema version
   9: the new `mail_log` table; no migration). Until then the site answers 503.
3. Set up e-mail in `config/local.php` (`mail.dsn`, `mail.from`;
   [docs/deployment.md](docs/deployment.md#e-mail)), and send a test e-mail
   from the System page. Without it, nothing is sent and the System page warns.

### Added

- **Events** (`Campanella\Event`): `ObjectCreated`, `ObjectUpdated`,
  `ObjectPublished` (also scheduled), `ObjectUnpublished`, `ObjectDeleted`
  from the `ObjectService`; `UserCreated`, `PasswordChanged` from the
  `UserService`; dispatched after saving by the `EventDispatcher`. A failing
  listener is logged; the operation stands.
- **Actions** bound to events in the configuration (`events`: event class =>
  Action classes), and a built-in one, `MailAdministrators` (not bound by
  default): e-mails the active administrators about the event, with a link to
  the object in the admin.
- **E-mail** (`Campanella\Mail\Mailer`, symfony/mailer 7.4, MIT): SMTP
  (`smtp://`, `smtps://`) or PHP's own settings (`native://default`), set in
  `config/local.php` (`mail.dsn`, `mail.from`, `mail.from_name`) or as
  environment variables; translatable templates in `templates/mail/` (a theme
  can override them); every attempt logged in `mail_log` (never the text; kept
  for `mail.log_days`, 90 days); sending never throws. The mail server gets
  `mail.timeout` (10) seconds, and after a failure the other e-mails of the
  request are not tried, so a hanging server cannot hold a request for minutes.
- A wrong entry in the `events` setting is skipped and shown as an error on
  the System page, instead of breaking the site.
- The System page's **E-mail** group: whether sending is set up, the sender,
  the latest e-mail, and a button for a **test e-mail to one's own address**
  (at most 5 in 15 minutes); the **e-mail log** under *System → E-mail*.
- Docker: **Mailpit** catches the e-mails of the development site
  (http://localhost:8025).
- Documentation: [chapter 21, Events and e-mail](docs/php-api/21-events-and-mail.md);
  e-mail in the [deployment guide](docs/deployment.md#e-mail).

### Changed

- `ObjectService` and `UserService` take an optional `EventDispatcher` (their
  last constructor argument).
- New dependency: symfony/mailer (with symfony/mime, egulias/email-validator
  and their dependencies; all MIT).

## [0.1.2] – 2026-10-09

Images: every uploaded image gets smaller copies, and the texts show the copy
that fits the screen (`srcset`); an image's delete page lists the texts that
show it.

**Upgrading from 0.1.1:**

1. Upload the new files (the dependencies did not change).
2. Run `php bin/campanella install`, or open `/admin/upgrade` (schema version
   8): it adds the images' `variants` column and the `media_usage` table, and
   the migration `core:0008_media_usage` records which of the existing texts
   show which image. Until then the site answers 503.
3. Make the smaller copies of the images uploaded so far: on the System page
   (*Images → Smaller copies*, the *Make the copies* button; press it again
   while some are left), or `php bin/campanella media:variants`. Until then
   those images are shown in full size, as before.

The copies take extra space in `public/media/` (being smaller, less than the
originals themselves); they are backed up with the folder, and can be made
again at any time with `media:variants --all`.

### Added

- **Smaller copies of images** (`media.variants`: 320, 640, 1024 and 1600
  pixels wide; only those at most 90% of the image's width), made on upload
  from the re-encoded pixels, stored beside the image (`…-640.jpg`) and
  deleted with it (`ImageProcessor`, `ImageVariant`, `MediaStorage`).
- **Responsive images in texts:** when a page is rendered, the images of this
  site in HTML texts get `srcset`, `sizes` (`media.sizes`), their width and
  height, `loading="lazy"` and `decoding="async"` (`ResponsiveImages`); the
  stored texts do not change. `image_url(image, width)` and
  `image_srcset(image)` for templates.
- **Where an image is used:** the `media_usage` table, kept up to date on
  every save of a text (`MediaUsage`); the delete page of an image lists the
  texts that show it or link to it, and says if it is the site's share image.
- Making the missing copies: the System page's *Smaller copies* line with a
  button (`POST /admin/system/media-variants`), and the `media:variants`
  command (`--all`: every image again).
- System check lines can offer a button (`CheckResult::$action`).
- The admin's image thumbnails use the smallest copy.

### Fixed

- The Site settings field of the site's address is called *Web address (URL)*
  (Hungarian: *Webcím (URL)*): "A webhely címe" could be read as the site's
  title, and the System page's warning now names the field to fill in.
- The empty field no longer shows the address the page was opened at as its
  placeholder (it looked like a saved value): it says *Not set yet*, and the
  suggested address is in the help text.

### Removed

- The delete page's general warning that image usage is not tracked (it is
  now).

## [0.1.1] – 2026-10-09

The first step towards 0.2.0 ("the site communicates"): site basics. The
site's name, slogan, default description, address and share image are edited
in the admin; every public page has a meta description, a canonical URL and
Open Graph tags; `sitemap.xml` and `robots.txt` are made from the content.

**Upgrading from 0.1.0:**

1. Upload the new files (the dependencies did not change).
2. Run `php bin/campanella install`, or open `/admin/upgrade`: it creates the
   new `settings` table (schema version 7; no migration). Until then the site
   answers 503.
3. Open **System → Site settings** (`/admin/system/settings`): set the site's
   address (e.g. `https://example.hu`), check the name and the slogan (until
   saved, they come from `config/app.php` as before), and save. Without the
   address the pages have no canonical URL or share link, and there is no
   `sitemap.xml`; the System page warns about it.

For themes: `site` in templates is now a read-only object that reads the
settings on first use. `{{ site.name }}`, `site.x is defined` and
`{% for key, value in site %}` work as before; filters that need an array
(e.g. `site|merge(…)`) need `site.toArray()` instead.

### Added

- **Site settings** in the admin (`/admin/system/settings`, for
  administrators): the site's name, slogan, default meta description, address,
  share image, and whether search engines may index the site. Stored in the new
  `settings` table (`Campanella\Settings\Settings`); a setting never saved comes
  from the `site` section of the configuration file (`SiteSettings`).
- **Meta tags** on the object pages and lists (`MetaBuilder`, `PageMeta`,
  `templates/page/_meta.html.twig`): the meta description (an article's lead,
  else the beginning of its text, else the site's default), the canonical URL,
  Open Graph (`og:title`, `og:type` with an article's times, `og:url`,
  `og:description`, `og:image` with its size: the first uploaded image of the
  text, else the site's share image) and `twitter:card`.
- **`/sitemap.xml`**: the lists and every page the anonymous visitor can see
  (drafts and scheduled content never), with their last modification; an index
  of several files above 2,000 addresses.
- **`/robots.txt`**: keeps search engines out of the admin (at its default
  path), the login and the installer, and points to the sitemap.
- While indexing is turned off, every response says `noindex` (the pages'
  head and the `X-Robots-Tag` header), and there is no sitemap.
- The browser installer saves the address it was opened at as the site's
  address.
- The System page's new *Site* group: the site's address (not set, or
  different from the address the page was opened at), indexing, a
  `robots.txt` file in `public/`.
- `Router::paths()`: the fixed paths of a handler.
- Documentation: [chapter 20, The site](docs/php-api/20-site.md); the site's
  address and search engines in the [deployment guide](docs/deployment.md).

### Changed

- `site` in templates: the saved settings over the configuration file's (see
  above). `config/app.php` has the new keys `site.description`, `site.url`
  (or `CAMPANELLA_SITE_URL`), `site.share_image`, `site.indexing`.
- `ObjectController` and `QueryController` take an optional `MetaBuilder` and
  pass `meta` to their templates; the base layout has a `meta` block.

### Security

- Absolute URLs are made only from the configured site address, never from
  the request's `Host` header, so a forged `Host` cannot put another site's
  address into a page or the sitemap. The browser installer saves the address
  it was opened at only if the configuration file sets none.
- The login page and every error page send `X-Robots-Tag: noindex`.

## [0.1.0] – 2026-10-08

The first milestone: a real website can be run and managed from the browser.
Installing from the browser, users managed in the admin, ready for a public
server (trusted proxies, security headers, a deployment guide), a read-only
overview of the Blueprints and capabilities, and a security review of the
whole code ([docs/security.md](docs/security.md)).

**Upgrading from 0.0.6 or 0.0.7** (an older installation is upgraded to 0.0.7
first; 0.1.0 stops with an explanation otherwise):

1. Upload the new files (the dependencies did not change), including the
   project root's `.htaccess` and the new `var/.htaccess`.
2. Run `php bin/campanella install`, or open `/admin/upgrade`. No migration;
   nothing changes in the tables.
3. In `config/local.php`: `'debug' => false` on a public server; behind a
   proxy, its address in `trusted_proxies` (the System page's *Proxy* line
   shows it). Then go through the checklist of
   [docs/deployment.md](docs/deployment.md).
4. Copy `.github/workflows/ci.yml` if you use the CI (it now runs
   `composer audit`).

Templates now run in a sandbox: a theme that called `get()`, `values()` or
`as()` on an object reads the fields as properties instead (`item.title`).

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
