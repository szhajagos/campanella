# 19. Menus

The site's navigation can be edited in the admin (since 0.0.7). A menu is an
object (the `menu` Blueprint), and its items are objects too (`menu_item`),
arranged in a tree ([chapter 18](18-trees.md)) in a hand-set order. A
template asks for a menu by its machine name:

```twig
{% set main = menu('main') %}
```

## The Blueprints

Defined in `config/blueprints.php`:

```php
'menu' => [
    'label' => 'blueprint.menu',
    'capabilities' => [Titled::class, Keyed::class],
    'form_order' => ['title', 'machine_name'],
],

'menu_item' => [
    'label' => 'blueprint.menu_item',
    'capabilities' => [Titled::class, Link::class, Hierarchical::class, Weighted::class],
    'relations' => [
        new Relation('menu', Cardinality::One, targetBlueprints: ['menu'], required: true, label: 'relation.menu'),
    ],
    'tree_scope' => 'menu',
    'form_order' => ['title', 'menu', 'parent', 'weight', 'target', 'url'],
],
```

- **A menu** has a title and a machine name (`Keyed`, e.g. `main`), by which
  templates find it.
- **A menu item** belongs to exactly one menu (the required `menu` relation).
  It is placed at the top of the menu or under another item **of the same
  menu**: the `tree_scope` makes each menu its own tree
  ([chapter 18](18-trees.md#separate-trees-tree_scope)). It leads to an object
  of the site (`target`) or to a URL (`Link`).
- **A menu with items cannot be deleted** (`tree.scope_in_use`): its items
  are deleted or moved to another menu first.

Menus and their items are not `Publishable`: they are visible to everyone.
What a visitor may not see is the item's target: see below.

## Link

`Campanella\Capability\Link` · name: `link` · no table of its own (since 0.0.7)

The object points somewhere: to an object of the site, or to a URL. Exactly
one of the two.

| Field / relation | Type | Storage | |
|---|---|---|---|
| `url` | String (2048) | Data | trimmed before saving |
| `target` | relation, One | | an object with `Routable` |

| Method | Description |
|---|---|
| `url(): string`, `targetId(): ?int` | |
| `href(?CampanellaObject $target = null): ?string` | Where it leads, without the base path: the loaded target's path, or the URL. Null for a target link whose target is not given (e.g. hidden from the visitor) |
| `static isSafeUrl(string $url): bool` | See below |
| `static isLocal(string $url): bool` | A path of the site (`/…`) or a fragment (`#…`) |
| `MAX_URL_LENGTH` | 2048 |

**A target rather than a URL:** an item pointing to an object follows it: if
the object's path changes, the menu changes with it; if the object is a draft
(or otherwise hidden from the visitor), the item is not shown.

**Only safe URLs** are accepted (`isSafeUrl()`): a path of the site (`/hirek`,
not `//…`), a fragment (`#kapcsolat`), an `http://` or `https://` address with
a host, `mailto:` and `tel:`. No whitespace, control characters or
backslashes. Everything else, `javascript:`, `data:` and the like, is
refused on save, so a menu cannot become a way to run code in a visitor's
browser.

Validation errors (on `url`): `link.missing` (neither), `link.both` (both),
`link.invalid_url`.

## Keyed

`Campanella\Capability\Keyed` · name: `keyed` · table: `cap_keyed` (since 0.0.7)

A unique machine name, by which code and templates find the object.

| Field | Type | Storage | |
|---|---|---|---|
| `machine_name` | String (64) | Table | required, unique |

| Method | Description |
|---|---|
| `key(): string` | |
| `PATTERN` | `^[a-z][a-z0-9_]{0,63}$`: lowercase letters, digits and underscores, starting with a letter |

It is trimmed and lowercased before saving; another format is
`validation.machine_name`, a taken name `validation.taken`.

## MenuBuilder

`Campanella\Menu\MenuBuilder` · **Public** · container: `MenuBuilder::class`

Builds a menu for a visitor, in three queries, all with the visitor's own
access rules: the menu by its machine name, its items (up to the given depth,
by weight), and their targets.

| Method | Description |
|---|---|
| `__construct(QueryEngine $queries, RelationLoader $relations)` | |
| `build(string $key, Actor $actor, string $currentPath = '/', int $levels = 2, string $basePath = ''): ?list<MenuEntry>` | The top items, each with its children; null if there is no such menu |
| `MENU_BLUEPRINT`, `MENU_RELATION` | `menu`, `menu`: the menus' Blueprint and the items' relation to their menu |
| `MAX_ITEMS` | 500: the most items read for one menu |

- **Hidden targets:** an item whose target the visitor may not see is left
  out, and so are the items below it.
- **The current item:** an item whose site path equals `$currentPath`
  (without the query string and fragment, case-insensitively) is `current`;
  it and the items above it are `active`.
- **Addresses:** site paths get `$basePath` in front; URLs stay as they are.

## MenuEntry

`Campanella\Menu\MenuEntry` · **Public** · `final readonly class`

| Member | Description |
|---|---|
| `$id`, `$title` | The item's ID and title |
| `$href` | The final address (a site path with the base path) |
| `$current` | It leads to the page being shown |
| `$active` | It, or an item below it, is current |
| `$external` | Not a path of this site (`https://…`, `mailto:`, `tel:`) |
| `$children` | `list<MenuEntry>` |
| `hasChildren(): bool` | |

## In templates: `menu()`

`menu(string $key, int $levels = 2): ?list<MenuEntry>`: the menu for the
current visitor and page. It returns null when there is no such menu, so a
template can fall back to fixed links. Building a menu never takes the page
down: if it fails (e.g. before the upgrade has created its tables), the error
is logged and it returns null too.

The default layout (`templates/base.html.twig`) shows the `main` menu in the
navbar: the top items as links, an item with children as a Bootstrap dropdown
(its own page first, then its children), the current item marked with
`aria-current="page"`. Until a `main` menu exists, it shows the links it had
built in, so the navigation does not disappear on an upgrade.

```twig
{% set footer = menu('footer', 1) %}
{% if footer %}
  <ul class="list-inline">
    {% for item in footer %}
      <li class="list-inline-item"><a href="{{ item.href }}"{% if item.current %} aria-current="page"{% endif %}>{{ item.title }}</a></li>
    {% endfor %}
  </ul>
{% endif %}
```

Unlike the rest of the view, `menu()` reads the database itself (through the
`MenuBuilder`), because the layout around every page needs it, whichever
controller renders it.

## In the admin

- **The menu's page** lists its items as a tree below the form, with a "New
  menu item" button that preselects the menu, and a link to the item list
  filtered to this menu.
- **The item list** can be filtered by menu (`/admin/menu_item?menu=3`);
  without a filter, each menu's tree comes under its own heading. Moving an
  item up or down keeps the filter.
- **The parent** is offered from the item's own menu. For a new item without
  a menu yet, all items are offered, each with its menu's title in front;
  choosing a parent from another menu is refused (`tree.other_scope`).
- **Moving an item to another menu** moves the items below it too.

## Seed

`php bin/campanella seed` creates the main menu (machine name `main`) if there
is none: *Kezdőlap* (`/`), *Hírek* (`/hirek`), *Kategóriák* (`/kategoriak`)
with the sample categories under it, and *Rólunk* (the page). A main menu
edited in the admin is never touched.
