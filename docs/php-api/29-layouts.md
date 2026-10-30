# 29. Layouts and regions

*Since 0.3.4.* A **layout** is the structure of a page: which **regions** it has
(header, navigation, content, sidebar, footer …) and how they are arranged.
Components ([chapter 28](28-displays.md#components)) are placed in regions, in
an order, with conditions of where they show. The layouts come from the core
and the theme; the admin chooses one for the site and one per Blueprint
([the design](../components.md#regions-and-layouts)).

| Class | | |
|---|---|---|
| `LayoutDefinition` | **Public** | A layout: its name, label, regions, template |
| `RegionDefinition` | **Public** | A region: its name, label, how it shows on small screens |
| `LayoutRegistry` | **Public** | The layouts there are, from the core's and the theme's `layout/` folders |
| `LayoutService` | **Public** | Which layout a page has, from the settings |
| `Conditions` | **Public** | Where something shows: paths, Blueprints, the front page, except paths |
| `Regions` | Internal | Renders a region's components (`region()`) |
| `LayoutSetup` | Internal | The header and footer as components, made once (the upgrade to 0.3.4, a new installation) |
| `LayoutPages` | Internal | The admin's *Appearance → Layouts* page (`Campanella\Admin`) |

All are in `Campanella\Layout`.

## A layout: template and JSON

A layout is two files of the same name in a `layout/` folder of the templates:
the core's `templates/layout/`, or the theme's `themes/<name>/templates/layout/`.

```
layout/sidebar.json         its regions (data only)
layout/sidebar.html.twig    its markup
```

The JSON (decided 2026-10-29: data, not PHP, since a theme holds only templates,
and they run in the sandbox):

```json
{
    "version": 1,
    "label": "layout.sidebar",
    "regions": {
        "header": {"label": "layout.region.header"},
        "navigation": {"label": "layout.region.navigation", "small": "collapse", "breakpoint": "md"},
        "content": {"label": "layout.region.content"},
        "sidebar": {"label": "layout.region.sidebar"},
        "footer": {"label": "layout.region.footer"}
    }
}
```

| Key | |
|---|---|
| `version` | `1` |
| `label` | A message key or text, at most 100 characters |
| `regions` | 1 to 20 regions, in their order: name (lowercase letters, digits, `_`) ⇒ `label`, and how it shows on small screens: `small` is `always` (the default), `collapse` (below the breakpoint a button opens it) or `hide` (hidden below it); `breakpoint` is Bootstrap's `sm`, `md` (the default), `lg` or `xl`; `page` (since 0.3.5; `true` or `false`, the default): a page's own components may be placed in it |

Every layout has a `content` region. A layout whose JSON is not valid, or that
has no template, is left out; `LayoutRegistry::errors()` says why.

The template is included by the core's `base.html.twig`, with the page's
variables and:

| Variable | |
|---|---|
| `main` | The page's own content: what the page's template puts in the `content` block (an article, a list, the search's results). Print it in the `content` region's place: a layout never loses it |
| `layout` | The `LayoutDefinition` |

```twig
{# themes/mytheme/templates/layout/landing.html.twig #}
{{ include('layout/_header.html.twig') }}
<div class="hero">{{ region('hero') }}</div>
<main class="container">{{ main }}{{ region('content') }}</main>
{{ include('layout/_footer.html.twig') }}
```

### The core's layouts

| Layout | Regions |
|---|---|
| `one_column` (*One column*, the default) | `header`, `navigation`, `content`, `footer`; `content` is `page` |
| `sidebar` (*With a sidebar*) | the same and `sidebar`: beside the content from `lg` up, under it on smaller screens; `content` and `sidebar` are `page` |

`header` is always seen (the site's name); `navigation` (the menu, the search,
the buttons) is beside it, and collapses below `md` behind a button, as the
header did before 0.3.4. Their markup is `layout/_header.html.twig` and
`layout/_footer.html.twig` (the footer only if its region has something), which
a theme's layout can include too.

A theme's layout of a core layout's name takes its place; a new name adds one.

## In templates: `region()`

`{{ region('sidebar', {row: true, class: 'flex-grow-1'}) }}`

A region of the page's layout, with its components: those placed in it, that
the visitor may see (their `Restricted` setting) and whose conditions hold on
this page, in their order. `''` if the layout has no such region, or nothing
shows in it. Options: `row` (the components side by side from the region's
breakpoint up), `class` (CSS classes of its wrapper).

The wrapper is `layout/_region.html.twig` (a theme can override it): the region's
`region--<name>` class, and its look on small screens: with `collapse`, a
`navbar-toggler` button below the breakpoint and a Bootstrap collapse shown from
it up (`collapse d-md-block`); with `hide`, `d-none d-md-block`.

The components of all the layout's regions are read with the first `region()`
of a page, in one query (`ComponentService::forRegions()`); their conditions
are checked in PHP (a page has a few). A region's name is shared by the
layouts: a component in `sidebar` shows in every layout with a sidebar.

`{% set l = page_layout() %}`: the page's `LayoutDefinition`, or null while
layouts are off (below).

## LayoutDefinition and RegionDefinition

`Campanella\Layout\LayoutDefinition` · **Public** · readonly

| Member | |
|---|---|
| `name`, `label`, `regions` (name ⇒ `RegionDefinition`), `origin` (`core` or `theme`) | |
| `template(): string` | `layout/<name>.html.twig` |
| `hasRegion(string $name): bool` | |
| `static fromJson(string $name, string $json, string $origin = 'core'): self` | `InvalidArgumentException` for a layout that is not valid |
| `VERSION` (1), `MAX_REGIONS` (20), `CONTENT` (`content`) | |

`Campanella\Layout\RegionDefinition` · **Public** · readonly: `name`, `label`,
`small` (`ALWAYS`, `COLLAPSE`, `HIDE`; the list `LOOKS`), `breakpoint` (one of
`BREAKPOINTS`), `page` (since 0.3.5: a page's own components may be in it); `withLook(string $small, string $breakpoint): self` gives the
same region with another look (an invalid one is ignored).

## LayoutRegistry

`Campanella\Layout\LayoutRegistry` · **Public**

`new LayoutRegistry(array $directories)`: the template folders, the core's
first; the Kernel gives the core's `templates/` and the theme's.

| Method | |
|---|---|
| `all(): array<string, LayoutDefinition>` | The layouts, the core's first |
| `has(string $name): bool` | |
| `get(string $name): LayoutDefinition` | The layout, or the default (`DEFAULT`, `one_column`) for an unknown name |
| `errors(): array<string, string>` | The layouts left out, and why |
| `regionNames(): list<string>` | Every layout's regions, each once: where a component can be placed |
| `pageRegionNames(): list<string>` | Since 0.3.5: the regions that are `page` in any layout, each once: where a page's own component can be placed |

## LayoutService

`Campanella\Layout\LayoutService` · **Public**

Which layout a page has, from the settings the admin saves ([the admin's page](#in-the-admin-layoutpages)):

| Setting | |
|---|---|
| `layout.enabled` | `1` once the site's header and footer are components (the upgrade to 0.3.4 makes them). Until then the core's `base.html.twig` shows its header and footer as before |
| `layout.site` | The site's layout (default `one_column`) |
| `layout.blueprint.<blueprint>` | A Blueprint's own: the pages of its objects |
| `layout.region.<layout>.<region>` | A region's look on small screens: `collapse:md` |

| Method | |
|---|---|
| `isEnabled(): bool` | Whether the core's `base.html.twig` shows the layouts |
| `site(): LayoutDefinition` | The site's layout |
| `forBlueprint(string $blueprint): LayoutDefinition` | The Blueprint's own, or the site's |
| `forPage(?CampanellaObject $object): LayoutDefinition` | A page's: its object's own (a `Composed` object's `page_layout`, since 0.3.5; an unknown one is ignored), that of its object's Blueprint, or the site's (a list's page, the search, the login page) |
| `blueprintChoice(string $blueprint): ?string` | The Blueprint's own layout's name, or null |
| `withLooks(LayoutDefinition $layout): LayoutDefinition` | The layout with its regions' looks as saved |
| `save(?string $site, array $blueprints = [], array $looks = []): void` | Saves the choices: the site's layout (null: kept), Blueprints' own (Blueprint ⇒ layout, `''` for the site's; one not given keeps its choice), the regions' looks (layout ⇒ region ⇒ `collapse:md`; one equal to the layout's default is not stored). All or nothing: `ValidationException` with `site`, `blueprint.<name>` or `look.<layout>.<region>` for a layout or region that does not exist, or a look that is not valid |
| `registry(): LayoutRegistry` | |

Later (decided 2026-10-29): a layout chosen by rules (a path, a Blueprint, the
visitor's role), with `Conditions`.

### In the admin: LayoutPages

`Campanella\Admin\LayoutPages` · Internal: `/admin/system/layouts`, *Appearance →
Layouts* in the menu, for the system roles. One form: the site's layout (with
its regions listed, and those from the theme marked), a layout for each Blueprint
whose objects have pages (`Routable`; *the site's layout* by default), and for
each layout its regions' looks: *always shown*, *collapsed below* or *hidden
below* 576, 768, 992 or 1200 px, the layout's own default marked. While
`layout.enabled` is not set the page says that the site does not show the
layouts yet; what is saved counts from then on. A layout left out of the registry
(`LayoutRegistry::errors()`) is shown as a warning, with why.

`LayoutPages::looks()` lists the looks offered: `always`, `collapse:<breakpoint>`,
`hide:<breakpoint>`; an `always` look is saved with the layout's own breakpoint.

## The header and footer: LayoutSetup

`Campanella\Layout\LayoutSetup` · Internal

What the core's `base.html.twig` showed before 0.3.4, made as components once,
then the layouts turned on (`layout.enabled`). Run at the end of an installation
and an upgrade: `php bin/campanella install` (and `migrate`), the upgrade page,
the installation page (before the sample content). The components:

| Region | Component (machine name) | Source, display |
|---|---|---|
| `header` | *The site's name* (`site_branding`) | `system:site`, `branding` without the slogan |
| `navigation` | *Main menu* (`main_menu`) | the main menu (`main`), `navigation`, horizontal, 2 levels |
| | *Search box* (`site_search`) | `system:search`, `search_box` |
| | *Admin button* (`admin_button`) | `system:visitor`, `admin_button` (only for those who may enter the admin) |
| | *Login button* (`login_button`) | `system:visitor`, `login_button` (logging out once logged in) |
| `footer` | *Footer* (`site_footer`) | a new snippet, `full` without its title: `© [date:year] [site:name] · Campanella [campanella:version]` |

A machine name already taken gets `_2`, `_3` …. A site without a main menu
gets one with the links the header had built in (`STARTER_LINKS`: the front
page, `/hirek`, `/kategoriak`, `/rolunk`); its id is kept in
`layout.starter_menu`, and `seed` fills that menu with the sample one (instead
of leaving it). A site whose `snippet` Blueprint is its own (made before 0.3.3)
gets no footer; one whose `component` Blueprint is its own keeps the old header
and footer (the layouts stay off).

Once `layout.enabled` is set, nothing is done again: the components can be
changed, moved or deleted in the admin (*Appearance → Regions*).

| Method | |
|---|---|
| `isPending(): bool` | Whether it is still to be done (`layout.enabled` never set) |
| `ensure(): ?array` | Makes them and turns the layouts on: the titles of the components made, or null if nothing was to be done |
| `ensureQuietly(): ?array` | The same; a problem goes to the error log (the layouts stay off, and it is tried again next time) |

## Conditions

`Campanella\Layout\Conditions` · **Public** · readonly

Where something shows: a component (`Component::conditions()`), and later a
layout chosen by rules.

| Part | |
|---|---|
| `paths` | Only on these paths; `*` stands for anything: `/hirek/*` is every page under `/hirek/`, not `/hirek` itself |
| `blueprints` | Only on the pages of these Blueprints' objects |
| `front` | Only on the front page |
| `except` | Never on these paths (the same patterns) |

The first three add up: a page matching any of them is in; with none of them,
every page is. `except` is checked last. Paths are compared without their case.

| Method | |
|---|---|
| `new Conditions(array $paths = [], array $blueprints = [], bool $front = false, array $except = [])`, `Conditions::everywhere()` | |
| `Conditions::fromArray(array $stored)`, `toArray()` | The stored form (in a component's data), only the parts that are set |
| `Conditions::fromText(string $paths, array $blueprints, bool $front, string $except)` | From a form's textareas: one pattern a line |
| `matches(string $path, ?CampanellaObject $object = null): bool` | Whether a page matches |
| `isEverywhere(): bool` | |
| `problems(?array $knownBlueprints = null): array<string, Message>` | What is not valid (`paths`, `except`, `blueprints`): a pattern not starting with `/`, longer than `MAX_PATH` (255) or with spaces, `?`, `#` or `\`; an unknown Blueprint; more than `MAX` (20) of a kind |
| `Conditions::pathMatches(string $pattern, string $path): bool` | A pattern against a normalized, lowercase path |

## Placing components

The `Component` capability has, since 0.3.4, `component_region` (queryable:
the region, or none) and `component_conditions` (JSON in the data column), and
the `component` Blueprint has `Weighted` (the order within the region):
`region(): ?string`, `conditions(): Conditions`,
`place(?string $region, ?Conditions $conditions = null)`.

`ComponentService` ([chapter 28](28-displays.md#componentservice)):

| Method | |
|---|---|
| `place(Actor $actor, CampanellaObject $component, ?string $region, ?Conditions $where = null, ?int $weight = null)` | Places it in a region (null: none, only `component()` shows it), where it shows, its order (null: kept). `ValidationException` for a region no layout has (`region`), or conditions that are not valid |
| `placementProblems(?string $region, Conditions $where, bool $ofPage = false): array<string, Message>` | The same checks; for a page's own (`$ofPage`, since 0.3.5) also a region that is not `page` (`region`), and any condition (`paths`) |
| `forRegions(array $regions, DisplayContext $context): array<string, list<CampanellaObject>>` | The components of these regions that show here, for this visitor, in their order: one query (at most `MAX_PLACED`, 200), the conditions checked in PHP. Since 0.3.5 with the page's own (a `Composed` page object's), first in each region, whatever their conditions |
| `inRegion(string $region, Actor $actor, ?int $page = null): list<CampanellaObject>` | The components placed in a region, in their order, whatever their conditions (for the admin): those the actor may see, at most `MAX_PLACED`; the whole site's, or (since 0.3.5) a page's own |
| `nextWeight(string $region, Actor $actor, ?int $page = null): int` | The order after the region's last component: where one added goes; among the site's, or a page's own |
| `move(Actor $actor, CampanellaObject $component, int $by): void` | Up (`-1`) or down (`1`) within its region: the region's components (the site's, or its page's own) are numbered 0, 1, 2 … in their order, it is swapped with its neighbour; nothing at the top, the bottom, or outside a region |

## A page's own components

*Since 0.3.5* (decided 2026-10-30). An object with the `Composed` capability
(the core's `page` Blueprint has it; a Blueprint made in the admin can have it)
is a page assembled from components: it has **components of its own**, those
whose `component_page` is the object. They show only on its page, after its own
text (`main`) in the content region and in its other regions meant for a page
(`"page": true` in the layout's JSON: the core's `content` and `sidebar`), the
page's own first, then the site's. They have no conditions (they are on their
page), but `Restricted` counts as for any component. The page may have a layout
of its own (`page_layout`).

Who changes them: those who may change the page; the core's policy lets
editors create, change and delete them ([chapter 5](05-access.md#defaultpolicy)),
while the whole site's components stay with the administrators.

The page is stored as a queryable field, not a relation (decided 2026-10-30):
when a page is deleted, its own components are deleted with it
(`DeletePageComponents`, an `ObjectListener`), and a component whose page is
gone never becomes the whole site's (it would show everywhere); `problem()`
marks it.

| `ComponentService` | |
|---|---|
| `create(…, ?CampanellaObject $page = null)` | A page's own ([chapter 28](28-displays.md#componentservice)) |
| `pageProblem(Actor $actor, CampanellaObject $page): ?Message` | Why it cannot have components of its own: not saved or not `Composed` (`components.page_not_composed`), or the actor may not change it (`components.page_forbidden`); null if it can |
| `pageOf(CampanellaObject $component): ?CampanellaObject` | Its page, if it still exists; null for the whole site's |
| `ofPage(CampanellaObject $page, Actor $actor): array<string, list<CampanellaObject>>` | A page's own, by region (`''`: in none), each in its order (for the page's editor) |

`Campanella\Display\DeletePageComponents` · Internal: the listener that deletes a
deleted `Composed` object's own components, registered by the Kernel.

## Existing themes

A theme that **extends** the core's `base.html.twig` and fills its blocks
keeps working: the `content` block is the layout's `main`. A theme that
**overrides** `base.html.twig` keeps its own header and footer until it adds
`region()` (it can include a layout: `{{ include(page_layout().template, {main: …}) }}`).
