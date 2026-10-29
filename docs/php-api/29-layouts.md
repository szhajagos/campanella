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
| `regions` | 1 to 20 regions, in their order: name (lowercase letters, digits, `_`) ⇒ `label`, and how it shows on small screens: `small` is `always` (the default), `collapse` (below the breakpoint a button opens it) or `hide` (hidden below it); `breakpoint` is Bootstrap's `sm`, `md` (the default), `lg` or `xl` |

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
| `one_column` (*One column*, the default) | `header`, `navigation`, `content`, `footer` |
| `sidebar` (*With a sidebar*) | the same and `sidebar`: beside the content from `lg` up, under it on smaller screens |

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
`BREAKPOINTS`); `withLook(string $small, string $breakpoint): self` gives the
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

## LayoutService

`Campanella\Layout\LayoutService` · **Public**

Which layout a page has, from the settings the admin saves (0.3.4, part 3):

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
| `forPage(?CampanellaObject $object): LayoutDefinition` | A page's: that of its object's Blueprint, or the site's (a list's page, the search, the login page) |
| `blueprintChoice(string $blueprint): ?string` | The Blueprint's own layout's name, or null |
| `withLooks(LayoutDefinition $layout): LayoutDefinition` | The layout with its regions' looks as saved |
| `registry(): LayoutRegistry` | |

Later (decided 2026-10-29): a layout chosen by rules (a path, a Blueprint, the
visitor's role), with `Conditions`.

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
| `placementProblems(?string $region, Conditions $where): array<string, Message>` | The same checks |
| `forRegions(array $regions, DisplayContext $context): array<string, list<CampanellaObject>>` | The components of these regions that show here, for this visitor, in their order: one query (at most `MAX_PLACED`, 200), the conditions checked in PHP |

## Existing themes

A theme that **extends** the core's `base.html.twig` and fills its blocks
keeps working: the `content` block is the layout's `main`. A theme that
**overrides** `base.html.twig` keeps its own header and footer until it adds
`region()` (it can include a layout: `{{ include(page_layout().template, {main: …}) }}`).
