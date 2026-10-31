# Compatibility promise

From 0.3.0 on, a site, a theme, a module or your own code written for 0.3.0
keeps working with every later **0.3.x** version. Any 0.3.x version can be
installed over any earlier one, or over any 0.2.x or 0.1.x (see
[Upgrading](upgrading.md)), and changes nothing that the list below covers,
except by adding to it.

0.2.x marked nothing Deprecated, so 0.3.0 removed and renamed nothing either:
what was written for any 0.2.x works with 0.3.0 too.

Campanella is still before 1.0: the promise is for one minor series at a time.
In **0.4.0** and later, backward-incompatible changes are possible again, but
only after a warning in 0.3.x (below), and always listed in the CHANGELOG with
what to do.

## What only grows within 0.3.x

| Area | What stays | What may be added |
|---|---|---|
| **PHP API** | Every class, interface, method, constant and property marked **Public** in the [PHP API](php-api/README.md) chapters: its name, its parameters (in order), what it returns, and its documented behaviour | New Public classes and methods; new optional parameters at the end; new constants |
| **Configuration** | Every key of `config/app.php` (and its `CAMPANELLA_*` environment variable), its meaning and its default; `config/local.php` written for 0.3.0 (or 0.2.x) needs no change. A Blueprint of `config/blueprints.php` keeps its keys ([chapter 2](php-api/02-objects.md#blueprint)) | New keys, with defaults that keep the old behaviour, unless the change is a security fix |
| **The content model** | The stored form of the Blueprints and the lists made in the admin (`version` 1: [BlueprintDefinition](php-api/02-objects.md#blueprintdefinition), [ListDefinition](php-api/23-lists.md)): what an administrator made in any 0.3.x keeps loading and working, with its objects | New optional keys; new field types, operators, displays |
| **Files and who sees them** (since 0.3.2) | A file's stored form (`Downloadable`'s fields, the `files` settings), `Restricted`'s values (`public`, `users`, `roles`) and how the policy applies them, the download's address `/files/<id>/<name>` (`paths.files`) and its headers (an attachment, never a page) | New types (by `files.types`), new scopes |
| **The importers** | The stored form of an importer (`version` 1, [chapter 26](php-api/26-importers.md)) keeps loading and running; the CSV export's columns and form (`id`, the machine names, `;`, UTF-8), so an export imports again | New optional keys, formats and columns |
| **The content model's file** | Format 1 ([chapter 24](php-api/24-content-model.md)): a file exported by 0.2.6 or later imports into any 0.3.x, with the same plan and the same rules (it never deletes) | New optional keys, which an older version ignores |
| **Templates** | Every template's name (`page/login.html.twig`, `page/search.html.twig`, `list/table.html.twig`, `list/_filters.html.twig`, `mail/submission.txt.twig`, the displays' `display/<name>.html.twig` (0.3.3), the layouts' `layout/<name>.html.twig` and their JSON (`version` 1), with `main` (0.3.4), and a region's `page` key (0.3.5) …) and the variables it gets; the blocks of `base.html.twig` | New templates; new variables; new blocks |
| **Twig** | Every function and filter (`path()`, `url()`, `t()`, `list()`, `object_fields()`, `field_value()`, `contact_form()`, `csrf_field()`, `image_srcset()`, `file_url()` (0.3.2), `display()`, `component()` (0.3.3), `region()`, `page_layout()`, the `tokens` filter (0.3.4), `body` …) with its arguments | New functions, filters and optional arguments |
| **Command line** | Every command of `bin/campanella` (`model:export`, `model:import`, `search:index`, `import:run`, `import:undo` among them) and its options, and the exit codes | New commands and options |
| **The site's addresses** | The paths of the `paths` setting and their defaults (`/login`, `/logout`, `/password-reset`, `/contact`, `/search`, `/files` since 0.3.2), `/install`, `/sitemap.xml`, `/robots.txt`, the admin's pages under its path; a list's own path; the query parameters of the search (`?q=`, `&page=`) and of a list (`?page=`, its filters as `?f[name]=value`, `?f[name][from]=…&f[name][to]=…`, `?f[name][min]=…&f[name][max]=…`), so a link or bookmark keeps working; an old path's redirect | New pages and parameters |
| **Data** | Every table and column (upgrades only add); no upgrade within 0.3.x deletes or rewrites data | New tables and columns |

**New Public classes since 0.2.0**, now covered too:
`CalendarDate` (0.2.2), `BlueprintStore` and `BlueprintDefinition` (0.2.3),
`ListDefinition`, `ListStore`, `ListRegistry` (0.2.5), `ModelFile`,
`ModelExport`, `ModelImport`, `ImportPlan`, `ImportItem` (0.2.6),
`SearchIndex`, `Search`, `SearchResult`, `SearchIndexer`, `Redirects` (0.2.7),
`FieldFilters` (0.2.9), `ImporterDefinition`, `ImportMapping`, `ImporterStore`,
`Importer`, `ImportSource`, `ImportValues`, `ImportRun`, `ImportTrial`,
`ImportException` (0.3.1), `Downloadable`, `Restricted`, `FileService`,
`FileInspector`, `FileStorage` (0.3.2), `Display`, `AsDisplay`, `DisplayRegistry`,
`DisplayDefinition`, `Subject`, `DisplayContext`, `SystemSource`,
`DisplayRenderer`, `DisplayException`, the built-in displays and system
sources, `Component`, `ComponentService`, `AdminMenu`, `AdminMenuItem` (0.3.3),
`LayoutDefinition`, `RegionDefinition`, `LayoutRegistry`, `LayoutService`,
`Conditions`, `TokenProvider`, `TokenRegistry` and the core's token providers (0.3.4), `Composed` (0.3.5); the tokens' written form `[prefix:name]`, with the methods their chapters mark Public. A display's name,
its settings (their names, types and defaults) and a source's written form
(`object:12`, `list:name`, `current`, `system:name`) stay too: components will
store them.

What 0.3.5 adds is covered the same way: a component's page (`component_page`)
and a page's own layout (`page_layout`), the front page setting (`front_page`),
the admin's `/admin/<blueprint>/<id>/components`, and the rule that editors may
change a page's own components (and only those).

What 0.3.6 adds is covered too: `ColorScheme`, `ColorSchemeRegistry` and
`Appearance` with the methods [chapter 31](php-api/31-appearance.md) marks Public;
a scheme's JSON (`version` 1 and its eight colours, with the readability rule),
the CSS variables it sets (`--cc-accent`, `--cc-on-accent` …), the settings
`appearance.scheme` and `appearance.color_mode`, the `campanella_color_mode`
cookie, `/color-scheme.css`, `POST /color-mode`, `color_mode()`,
`color_scheme_url()`, `visitor_color_mode()`, and the `color_mode_switch`
display with its `show_labels` setting.

What 0.3.7 adds: `ContentPaths` (its `get()`, `forLanguage()` and the path names
`news`, `categories`, `about`), the settings `content.path.news`,
`content.path.categories` and `content.path.about`, `content_path()` in
templates, and `seed --locale`. The sample content's file format
(`resources/seed/*.json`) is Internal: it may change with the samples; what
`seed` makes is not promised either.

A theme that overrides a template keeps working, because the template's name
and variables stay. Its **markup** (HTML elements, CSS classes, texts) may
change in a 0.3.x, so a theme that copied a core template should compare its
copy with the new one when upgrading. The CHANGELOG lists such changes under
**Changed**.

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
  0.3.x, and says so in the CHANGELOG (as 0.3.0 limited the searches a minute
  and made the content model's import stricter).

## Removing something: Deprecated first

What is covered above is never removed or renamed within 0.3.x. To go in
0.4.0 or later, it is first marked **Deprecated** in a 0.3.x version:

1. the CHANGELOG lists it under **Deprecated**, with what to use instead;
2. the PHP API chapter marks it **Deprecated** (and the PHPDoc has a
   `@deprecated` tag naming the replacement);
3. it keeps working throughout 0.3.x.

Only then can 0.4.0 (or a later version) remove it, listed under **Removed**
with the upgrade steps.

## Versions and security fixes

The 0.3.x versions after 0.3.0 are steps towards 0.4.0 (the
[ROADMAP](../ROADMAP.md) lists them): each keeps the promise above, and is a
drop-in upgrade: upload the new files and run the upgrade as usual. A security
fix comes in the next 0.3.x version, or in a patch version of its own if it
cannot wait.

**0.2.x gets security fixes until 0.4.0 is out**, as 0.2.10, 0.2.11 …, each
keeping the 0.2.x promise, for sites that cannot upgrade to 0.3.x yet. The
upgrade to 0.3.0 itself changes nothing that a 0.2.x site relies on, so it is
the better way. 0.1.x gets no more fixes: upgrade it to 0.3.0 in one go.

To report a vulnerability, see [Security](security.md#reporting-a-vulnerability).

## How it is checked

- The [upgrade tests](php-api/17-migrations.md#the-upgrade-test) install
  0.1.0, 0.1.5, 0.2.0, 0.2.9, 0.3.0 and 0.3.1 (from 0.2.9 on with a content
  model made in the admin: a Blueprint, its objects, a list with filters, a
  redirect, the search's index, and a Blueprint called `file`) and upgrade them
  to the current code on every push: every row, column and file must survive,
  the old content model must keep working and export again unchanged, and a
  file can be uploaded and downloaded on the upgraded site.
- The [browser tests](testing.md#the-browser-tests) and the
  [package check](testing.md#the-release-package) run every page a site owner
  and a visitor use, on the package as it is published.
- The [performance check](testing.md#the-performance-check) keeps the busiest
  pages within their limits with 10 000 objects, on MariaDB and MySQL.
- `composer docs:check` fails if a public class or method is not documented,
  so nothing becomes part of the API unnoticed.
