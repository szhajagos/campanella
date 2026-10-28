# Components, displays, regions and layouts

*Design, agreed on 2026-10-27 (details on 2026-10-28); built in 0.3.3–0.3.5,
towards 0.4.0.* This chapter describes how a site's pages are assembled from
parts in 0.4.0, and why so. The [PHP API](php-api/README.md) documents the
classes as they come: the displays and sources in
[chapter 28](php-api/28-displays.md) (0.3.3); the rest below is the agreed
design, its signatures a draft.

## The idea in one paragraph

A **component** shows something somewhere: it takes its data from a
**source** (an object, a list, the page's own object, or something the
system knows, such as the visitor), shows it with a **display** (a menu as
navigation, a text in full, a file as a download link), with **settings**
(how deep the menu goes, whether the title shows). Components are placed in
the **regions** (header, sidebar, footer …) of a **layout**, which the theme
provides. A display is offered for an object because of the object's
**capabilities**: there is no "Blockable" capability; a menu can be shown as
navigation because it is `Hierarchical` and its items are `Link`s.

## The terms

| Term | What it is | Where it is defined |
|---|---|---|
| **Display** | A way of showing something: a name, a template, its settings, and what it needs (capabilities, a list, or a system source) | In code (core, later modules) |
| **Source** | Where a component's data comes from | An object or a list chosen in the admin; the page's object; a system source in code |
| **Component** | One placed thing: a source, a display, its settings, a region, an order, who sees it, where | An object of the `component` Blueprint, made in the admin |
| **Region** | A named area of a layout that holds components | The theme (with the core's defaults) |
| **Layout** | The structure of a page: which regions, in what arrangement | The theme; chosen in the admin (editable layouts: later) |

The names were chosen on 2026-10-27: *Component* and *Display*.
"Presentation" is taken (the class that renders templates), "Block" carries
Drupal's meaning, "Widget" is too technical. A display is what Drupal calls a
view mode.

## Displays

A display is a class with an attribute, as a capability is:

```php
#[AsDisplay('navigation', requires: [Hierarchical::class, Link::class], label: 'display.navigation')]
final class Navigation extends Display
{
    /** Its settings: fields, so the admin builds their form as it builds an object's. */
    public static function settings(): array
    {
        return [new Field('depth', FieldType::Integer, required: true, default: 2, label: 'display.depth')];
    }

    /** The template's variables for the subject; the template is display/navigation.html.twig. */
    public function variables(Subject $subject, array $settings, DisplayContext $context): array
    {
        return ['items' => $this->menus->build($subject->object(), $context->actor, $context->path, (int) $settings['depth'])];
    }
}
```

What a display can be used for:

- **an object** that has every capability in `requires` (for a menu, the
  items' capabilities count through its tree);
- **a list** (`many: true`): its objects, e.g. as cards or a table;
- **a system source** it names (`sources: ['visitor']`).

The built-in displays, first (more come with need):

| Display | For | Settings |
|---|---|---|
| `full` | `Titled` | the title shown or not |
| `teaser` (card) | `Titled` | the date, the lead |
| `link` | `Routable` | the text |
| `info_box` | `Titled` (+ `Textual`) | the title, a colour (the Bootstrap ones until the colour schemes of 0.3.6) |
| `navigation` | a menu | horizontal or vertical, depth |
| `image` | `MediaFile` | width, caption |
| `download` | `Downloadable` | type and size shown or not |
| `cards`, `table` | a list | the number, the columns |
| `login_button`, `admin_button`, `login_form`, `user_card` | the visitor | the forgotten password's link |
| `search_box` | the search | the placeholder |
| `branding` | the site | its name, its slogan (the logo with the appearance) |
| `breadcrumbs` | the page | the front page first |

The texts a component shows in a box (opening hours, a contact) are objects of
a **`snippet`** Blueprint (decided 2026-10-28): a title and a formatted text,
without a page of its own, shown with `info_box` or `full`. A component holds
no text of its own: a text is content, edited, searched and exported as
content.

**What exists already becomes displays, without breaking anything.** The
templates `object/full.html.twig` and `object/teaser.html.twig` are the `full`
and `teaser` displays (their names and variables stay, as the compatibility
promise says); `render_object(item, 'teaser')` keeps working and means the
display. A list's `teaser` and `table` (0.2.5) are the `cards` and `table`
displays. An object's own page is a layout whose main region shows the object
with `full`.

Displays are registered as capabilities are (a list in the configuration), so
the modules of 0.5.0 can bring new ones. `Display`, `AsDisplay`, `Subject`,
`DisplayContext` are Public API from their version on.

## Sources

| Source | Stored as | Example |
|---|---|---|
| An object | its ID (the content model's export carries it by reference, as lists' conditions) | the main menu, the "About us" page, a file |
| A list made in the admin | its machine name | the next five events |
| The page's object | `current` | the author box beside an article, its files |
| A system source | its name | `visitor`, `search`, `site`, `breadcrumbs` |

In templates, `display()` takes the same: `{{ display('list:esemenyek',
'cards', {count: 3}) }}` (0.3.3).

**System sources** (decided 2026-10-27) are classes in code, not Blueprints:
logging in is not data, it has no objects to store. The **visitor** source is
the one who looks at the page: anonymous, or the logged-in user (then its
subject is the user object, so the user's capabilities count too). Its
displays: the login (or logout) button, the admin button (only for those who
may enter the admin), the login form, a card of the user.

A source is always read **with the visitor's rights**: through the Query
engine and the policy, as every page reads. A component showing a draft, a
file for members or a restricted page to someone who may not see it shows
nothing instead. This is why a component never holds a copy of what it shows.

## Components

A component is an object of the **`component` Blueprint** (decided
2026-10-27). Its own capability holds what makes it a component; the source
needs no capability of its own:

| Part | Stored | |
|---|---|---|
| Title | `Titled` | for the admin; a display may show it |
| Source, display | own queryable fields | `object:12`, `list:esemenyek`, `current`, `system:visitor`; `navigation` |
| Settings | the `data` JSON | checked against the display's `settings()` on every save; never queried |
| Region | a queryable field | `header`, `sidebar` … (a region the layouts know) |
| Order | `Weighted` | within the region |
| Who sees it | `Restricted` (0.3.2) | everyone, logged-in users, some roles |
| Where | conditions in `data` | every page, these paths, the objects of these Blueprints, the front page; checked when the page is rendered (a region holds a few components, so they are filtered in PHP, not by a query) |
| Whose page | a relation `page` (0.3.5) | none: the whole site; an object: only its page, as its own content |

Being objects, components get what objects have: the admin's list and form
(the settings' form built from the display's fields), events, the content
model's export (0.4.0), and later translations and revisions (0.6.0).

A component has a **machine name**, so a template can show it anywhere,
before regions exist: `{{ component('fo-menu') }}` (0.3.3). In the admin,
components are under a new group, *Appearance*, for administrators (decided
2026-10-28), where layouts (0.3.4) and colour schemes (0.3.6) will come too.

**Who places them:** administrators (decided 2026-10-26: where something
appears on the site is a right above an editor's). Editors edit the content
of a page assembled from components (0.3.5): the components with that page as
their `page`.

## Regions and layouts

A **layout** is a template of the theme with its regions, declared beside it
(e.g. `layout/sidebar.html.twig` and `layout/sidebar.php`: name, label,
regions). The core brings two: *one column* (header, content, footer) and
*with a sidebar* (header, content, sidebar, footer). A theme can add its own
or override these.

The layout of a page is chosen in the admin: one for the site, and one per
Blueprint (e.g. events with a sidebar); per object for the pages assembled
from components (0.3.5). Layouts edited in the admin come later (decided
2026-10-27).

A region renders its components in their order: `{{ region('sidebar') }}` in
a layout's template. The main content of an object's page (the object with
`full`, a list's page, the search's results) is the `content` region's first
part, so a layout never loses it.

**Existing themes keep working** (decided 2026-10-26). The blocks of
`base.html.twig` stay. The core's `base.html.twig` gets regions around its
blocks, and on the upgrade to 0.4.0 the site gets the components that make
its header and footer look as now: the main menu as navigation, the search
box, the visitor's buttons. A theme that overrides `base.html.twig` keeps its
own header until it adds `region()`.

## Rendering a page

1. The controller finds what the page shows (an object, a list, the search)
   as now.
2. The layout is chosen: the object's (0.3.5), its Blueprint's, the site's.
3. For each region of the layout, its components: the site's, and the
   object's own (`page`), those the visitor may see (`Restricted`, by the
   query) and whose conditions hold here, in their order.
4. Each component's source is read with the visitor's rights; a display that
   cannot show the subject (e.g. the object lost a capability) renders
   nothing and the admin's list marks the component.
5. The display's template is rendered in the sandbox, as every template.

**Cache (0.5.0).** A component's HTML will be cached by its source's objects
(cache tags) and by what it depends on (the visitor's roles, the path): this
is why every display says which context it uses (`DisplayContext`).

## In steps

| Step | What |
|---|---|
| **0.3.3** | Displays and sources: `Display`, `AsDisplay`, the registry, the built-in displays and system sources, `display()` in templates; `full` and `teaser` as displays; the `snippet` Blueprint (part 1). The admin's menu in groups; the `component` Blueprint, made in the admin (source, display, its settings' form), and `component()` in templates to show one anywhere (part 2) |
| **0.3.4** | Regions and layouts: the core's two layouts, `region()`, the theme's own layouts; placing components (region, order, who sees them, where); the layout of the site and of a Blueprint; the upgrade's components that keep the header and footer as they are |
| **0.3.5** | Pages assembled from components: a component's `page`, a page's layout, editing a page's components (editors too), the front page made of components |
| **0.3.6** | Appearance: colour schemes; the visitor's colour-mode switch as a display of a system source |

## Still open (decided at their step)

- Whether a page assembled from components is any Routable object, or one
  of a Blueprint marked for it (a capability that gives it a layout of its
  own and makes its body the components), at 0.3.5.
- The admin's way of arranging a page's components (a form per region, or
  dragging), at 0.3.5.
- Which further displays come in 0.4.0 itself, and which wait for modules.
