# Compatibility promise

From 0.4.0 on, a site, a theme, a module or your own code written for 0.4.0
keeps working with every later **0.4.x** version. Any 0.4.x version can be
installed over any earlier one, or over any 0.3.x, 0.2.x or 0.1.x (see
[Upgrading](upgrading.md)), and changes nothing that the list below covers,
except by adding to it.

0.3.x marked nothing Deprecated, so 0.4.0 removed and renamed nothing either:
what was written for any 0.3.x (or 0.2.x) works with 0.4.0 too. The 0.3.x
promise is kept as a whole, and grows with what 0.3.2–0.3.7 added.

Campanella is still before 1.0: the promise is for one minor series at a time.
In **0.5.0** and later, backward-incompatible changes are possible again, but
only after a warning in 0.4.x (below), and always listed in the CHANGELOG with
what to do.

## What only grows within 0.4.x

| Area | What stays | What may be added |
|---|---|---|
| **PHP API** | Every class, interface, method, constant and property marked **Public** in the [PHP API](php-api/README.md) chapters: its name, its parameters (in order), what it returns, and its documented behaviour | New Public classes and methods; new optional parameters at the end; new constants |
| **Configuration** | Every key of `config/app.php` (and its `CAMPANELLA_*` environment variable), its meaning and its default; `config/local.php` written for 0.4.0 (or 0.3.x, 0.2.x) needs no change. A Blueprint of `config/blueprints.php` keeps its keys ([chapter 2](php-api/02-objects.md#blueprint)) | New keys, with defaults that keep the old behaviour, unless the change is a security fix |
| **Settings** | The settings saved in the admin and their meaning: the site's (`site.*`, the front page among them), the layouts' (`layout.*`), the colour scheme's and mode's (`appearance.scheme`, `appearance.color_mode`), the content paths (`content.path.news`, `.categories`, `.about`) | New settings, absent meaning the old behaviour |
| **The content model** | The stored form of the Blueprints and the lists made in the admin (`version` 1: [BlueprintDefinition](php-api/02-objects.md#blueprintdefinition), [ListDefinition](php-api/23-lists.md)): what an administrator made in any 0.3.x or 0.4.x keeps loading and working, with its objects | New optional keys; new field types, operators, displays |
| **Files and who sees them** | A file's stored form (`Downloadable`'s fields, the `files` settings), `Restricted`'s values (`public`, `users`, `roles`) and how the policy applies them, the download's address `/files/<id>/<name>` (`paths.files`) and its headers (an attachment, never a page) | New types (by `files.types`), new scopes |
| **Displays and components** | Every built-in display's name, what it can show, and its settings (their names, types and defaults: [chapter 28](php-api/28-displays.md#the-built-in-displays)); a source's written form (`object:12`, `list:name`, `current`, `system:name`) and the system sources' names; a component's stored form (its source, display, settings, region, weight, conditions, page) and that it shows with the visitor's rights; who may change which (administrators the site's, editors a page's own) | New displays, sources and optional settings |
| **Layouts and regions** | A layout's JSON (`version` 1: its regions, `page`, `small`, `breakpoint`) and template (`main`), the core's layouts (`one_column`, `sidebar`) and regions (`header`, `navigation`, `content`, `sidebar`, `footer`), a component's conditions (paths with `*`, Blueprints, the front page, except) | New layouts, regions and optional keys |
| **Colour schemes** | A scheme's JSON (`version` 1, its eight colours, the readability rule of [chapter 31](php-api/31-appearance.md)), the core's schemes (`default`, `forest`, `sea`), the CSS variables they set (`--cc-accent`, `--cc-on-accent` …), the colour modes (`auto`, `light`, `dark`) and the visitor's cookie (`campanella_color_mode`) | New schemes and variables |
| **Tokens** | The written form `[prefix:name]` and the core's tokens (`[site:name]`, `[date:year]`, `[campanella:version]` …, [chapter 30](php-api/30-tokens.md)) | New tokens and providers |
| **The importers** | The stored form of an importer (`version` 1, [chapter 26](php-api/26-importers.md)) keeps loading and running; the CSV export's columns and form (`id`, the machine names, `;`, UTF-8), so an export imports again | New optional keys, formats and columns |
| **The content model's file** | Format 1 ([chapter 24](php-api/24-content-model.md)): a file exported by 0.2.6 or later imports into any 0.4.x, with the same plan and the same rules (it never deletes) | New optional keys, which an older version ignores |
| **Templates** | Every template's name (`page/login.html.twig`, `page/search.html.twig`, `list/table.html.twig`, `list/_filters.html.twig`, `mail/submission.txt.twig`, the displays' `display/<name>.html.twig`, `object/teaser.html.twig`, the layouts' `layout/<name>.html.twig` …) and the variables it gets; the blocks of `base.html.twig` | New templates; new variables; new blocks |
| **Twig** | Every function and filter (`path()`, `url()`, `t()`, `list()`, `object_fields()`, `field_value()`, `contact_form()`, `csrf_field()`, `image_srcset()`, `file_url()`, `display()`, `render_object()` with its settings, `component()`, `region()`, `page_layout()`, `color_mode()`, `color_scheme_url()`, `visitor_color_mode()`, `content_path()`, the `tokens` and `body` filters …) with its arguments | New functions, filters and optional arguments |
| **Command line** | Every command of `bin/campanella` (`model:export`, `model:import`, `search:index`, `import:run`, `import:undo`, `seed --locale` among them) and its options, and the exit codes | New commands and options |
| **The site's addresses** | The paths of the `paths` setting and their defaults (`/login`, `/logout`, `/password-reset`, `/contact`, `/search`, `/files`), a site's content paths (`/hirek` or `/news` …, as it was installed), `/install`, `/sitemap.xml`, `/robots.txt`, `/color-scheme.css`, `POST /color-mode`, the admin's pages under its path (`/admin/<blueprint>/<id>/components`, `/admin/system/layouts`, `/admin/system/color-schemes` …); a list's own path; the query parameters of the search (`?q=`, `&page=`) and of a list (`?page=`, its filters as `?f[name]=value`, `?f[name][from]=…&f[name][to]=…`, `?f[name][min]=…&f[name][max]=…`), so a link or bookmark keeps working; an old path's redirect | New pages and parameters |
| **Data** | Every table and column (upgrades only add); no upgrade within 0.4.x deletes or rewrites data | New tables and columns |

The Public classes are those the PHP API chapters mark so; since 0.3.0 these
were added and are covered now: `ImporterDefinition`, `ImportMapping`,
`ImporterStore`, `Importer`, `ImportSource`, `ImportValues`, `ImportRun`,
`ImportTrial`, `ImportException` (0.3.1); `Downloadable`, `Restricted`,
`FileService`, `FileInspector`, `FileStorage` (0.3.2); `Display`, `AsDisplay`,
`DisplayRegistry`, `DisplayDefinition`, `Subject`, `DisplayContext`,
`SystemSource`, `DisplayRenderer`, `DisplayException`, the built-in displays
and system sources, `Component`, `ComponentService`, `AdminMenu`,
`AdminMenuItem` (0.3.3); `LayoutDefinition`, `RegionDefinition`,
`LayoutRegistry`, `LayoutService`, `Conditions`, `TokenProvider`,
`TokenRegistry` and the core's token providers (0.3.4); `Composed` (0.3.5);
`ColorScheme`, `ColorSchemeRegistry`, `Appearance` (0.3.6); `ContentPaths`
(0.3.7). The sample content's file format (`resources/seed/*.json`) is
Internal: it may change with the samples.

A theme that overrides a template keeps working, because the template's name
and variables stay. Its **markup** (HTML elements, CSS classes, texts) may
change in a 0.4.x, so a theme that copied a core template should compare its
copy with the new one when upgrading. The CHANGELOG lists such changes under
**Changed** (as 0.4.0's header: a `div.navbar` instead of a `nav`).

## What is not covered

- Anything marked **Internal** in the PHP API (the admin's pages and editors,
  the controllers, `ListColumns` …), and classes and methods that are not
  documented: they may change in any version.
- The HTML, CSS classes and JavaScript of the core templates and of the admin
  (see above), and the look of the admin.
- The wording of texts and messages (the translations), and the text of the
  command line's output. The message **keys** a theme uses in `t()` stay.
- The database's own structure as seen by SQL, the Blueprints' own tables
  (`bp_<name>`) included: use the PHP API, not the tables.
- The search's ranking (which result comes first) and its excerpts; the
  measured times of the [performance check](testing.md#the-performance-check).
- The sample content (`seed`): it may change, and it only adds what a site
  does not have.
- Behaviour that is a security problem: a security fix may change it in a
  0.4.x, and says so in the CHANGELOG (as 0.3.0 limited the searches a minute
  and made the content model's import stricter, and 0.3.7 routed the content
  paths after the system's own).
- The accessibility of the core's pages may be improved in a 0.4.x (its
  markup, see above), as 0.4.0 did ([accessibility](accessibility.md)).

## Removing something: Deprecated first

What is covered above is never removed or renamed within 0.4.x. To go in
0.5.0 or later, it is first marked **Deprecated** in a 0.4.x version:

1. the CHANGELOG lists it under **Deprecated**, with what to use instead;
2. the PHP API chapter marks it **Deprecated** (and the PHPDoc has a
   `@deprecated` tag naming the replacement);
3. it keeps working throughout 0.4.x.

Only then can 0.5.0 (or a later version) remove it, listed under **Removed**
with the upgrade steps.

## Versions and security fixes

The 0.4.x versions after 0.4.0 are steps towards 0.5.0 (the
[ROADMAP](../ROADMAP.md) lists them): each keeps the promise above, and is a
drop-in upgrade: upload the new files and run the upgrade as usual. A security
fix comes in the next 0.4.x version, or in a patch version of its own if it
cannot wait.

**0.3.x gets security fixes until 0.5.0 is out**, as 0.3.8, 0.3.9 …, each
keeping the 0.3.x promise, for sites that cannot upgrade to 0.4.x yet. The
upgrade to 0.4.0 itself changes nothing that a 0.3.x site relies on, so it is
the better way. **0.2.x's security fixes end with 0.4.0**, as promised; 0.2.x
and 0.1.x sites upgrade to 0.4.0 in one go.

To report a vulnerability, see [Security](security.md#reporting-a-vulnerability).

## How it is checked

- The [upgrade tests](php-api/17-migrations.md#the-upgrade-test) install
  0.1.0, 0.1.5, 0.2.0, 0.2.9 and every 0.3.x (from 0.2.9 on with a content model
  made in the admin; 0.3.7 with everything of 0.3.2–0.3.7 in use, in Hungarian
  and as an English site) and upgrade them to the current code on every push:
  every row, column and file must survive, the old content model must keep
  working and export again unchanged, and the files, components, layouts,
  front page, colour scheme and paths must work as before.
- The [browser tests](testing.md#the-browser-tests) and the
  [package check](testing.md#the-release-package) run every page a site owner
  and a visitor use, on the package as it is published.
- The [performance check](testing.md#the-performance-check) keeps the busiest
  pages within their limits with 10 000 objects, on MariaDB and MySQL.
- `composer docs:check` fails if a public class or method is not documented,
  so nothing becomes part of the API unnoticed.
