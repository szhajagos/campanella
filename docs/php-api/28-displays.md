# 28. Displays

*Since 0.3.3.* A **display** is a way of showing something: a menu as
navigation, a text in full, a file as a download link, a list as cards, the
visitor as a login button. A **component** is a source, a display and its
settings, made in the admin ([below](#components)): a template shows it by its
machine name, and from 0.3.4 on it is placed in the regions of a layout
([the design](../components.md)). A theme can also use displays in its
templates directly, with `display()`.

A display is offered because of what the thing **has**, not what it is: an
object can be shown as a download because it has the `Downloadable`
capability, whatever its Blueprint. There is no "Blockable" capability.

```twig
{{ display(file, 'download') }}                                  {# an object #}
{{ display('list:esemenyek', 'cards', {count: 3, columns: 3}) }}  {# a list made in the admin #}
{{ display('system:visitor', 'login_button') }}                  {# a system source #}
{{ display('current', 'info_box') }}                             {# the page's object #}
```

## The parts

| Class | | |
|---|---|---|
| `Display` | **Public** (extend it) | A display: its settings, what it shows, its template |
| `AsDisplay` | **Public** | The attribute that declares a display: its name, what it is for, its label |
| `Subject` | **Public** | What a display shows: an object, a list of objects, or a system source's value |
| `DisplayContext` | **Public** | Where it is shown: the visitor, the page |
| `SystemSource` | **Public** (implement it) | Something the system knows: the visitor, the search, the site, the breadcrumbs |
| `DisplayRegistry` | **Public** | The displays and system sources known to the system |
| `DisplayDefinition` | **Public** | A display's declaration, read from its class |
| `DisplayRenderer` | **Public** | Reads a source and shows it with a display |
| `DisplayException` | **Public** | An unknown or invalid display or source, or one that cannot show the other |
| `CurrentPage` | Internal | The object the page being rendered shows, set by its controller |
| `ComponentService` | **Public** | Components: checked on save, shown by machine name, marked when they stop working ([below](#components)) |

All are in `Campanella\Display`; the core's displays in `Campanella\Display\Builtin`,
its system sources in `Campanella\Display\Source`.

## Writing a display

A display is a class with an attribute, as a capability is
([chapter 3](03-capabilities.md)):

```php
use Campanella\Capability\Downloadable;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

#[AsDisplay('file_card', requires: [Downloadable::class], label: 'display.file_card')]
final class FileCard extends Display
{
    public static function settings(): array
    {
        return [new Field('show_size', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_size')];
    }

    public function variables(Subject $subject, array $settings, DisplayContext $context): ?array
    {
        return ['size' => $subject->object->as(Downloadable::class)->size()];
    }
}
```

and its template, `templates/display/file_card.html.twig` (or the theme's):

```twig
<div class="file-card">
    <a href="{{ file_url(object) }}" download>{{ object.title }}</a>
    {% if settings.show_size %}<small>{{ size|file_size }}</small>{% endif %}
</div>
```

It is registered in `config/local.php`, with the core's displays:

```php
use Campanella\Display\DisplayRegistry;

'displays' => [...DisplayRegistry::BUILTIN, FileCard::class],
```

(The configuration files are merged key by key, so a list in `config/local.php`
replaces the one in `config/app.php` item by item: list the core's too.)

### `AsDisplay`

`#[AsDisplay(string $name, array $requires = [], bool $many = false, array $sources = [], string $label = '')]`

| Parameter | |
|---|---|
| `name` | Lowercase letters, digits and `_`, unique; components store it |
| `requires` | For **an object** that has every one of these capabilities (none: any object) |
| `many` | For **a list** of objects |
| `sources` | For **these system sources** (their names, e.g. `[VisitorSource::NAME]`) |
| `label` | Its name in the admin: a message key (`display.download`) or a text |

A display is for one of the three: objects, a list, or system sources. One
that names two is refused when it is registered.

### `Display`

`Campanella\Display\Display` · **Public** · abstract

| Method | Default | |
|---|---|---|
| `static settings(): list<Field>` | none | Its settings, as fields ([chapter 2](02-objects.md#field)): their type, default, label, the choices of a `Choice`. The admin (0.3.3, part 2) builds their form from them, and they are checked and cast as an object's fields are. A required setting needs a default |
| `validate(array $settings): array<string, Message>` | no problems | Checks beyond the fields' own, on the settings already cast: e.g. a number's range (the core's `count` is 1–100) |
| `accepts(Subject $subject): bool` | `true` | Whether it can show this subject beyond what its attribute says: the navigation shows only an object of the `menu` Blueprint |
| `variables(Subject $subject, array $settings, DisplayContext $context): ?array` | `[]` | The template's own variables. **`null`: nothing is shown**, e.g. the login form for a logged-in visitor, an empty menu |
| `templates(string $name, Subject $subject): list<string>` | `display/<name>--<blueprint>.html.twig`, `display/<name>.html.twig` | The templates to look for; the first that exists is rendered |

A display gets its services through its constructor. The registry makes it
with its factory: the Kernel's gives it from the container if the container
has its class (register it with `$container->set(FileCard::class, …)`), else
it makes it with `new`.

**A display reads nothing with more rights than the visitor's.** What it shows
comes from its source, already read with the visitor's rights; anything else it
reads goes through the Query engine with `$context->actor`, as the navigation
reads the menu's items ([chapter 19](19-menus.md)).

### The template's variables

| Variable | |
|---|---|
| `subject` | The `Subject` |
| `object` | For an object (and for the visitor, the logged-in user): the object; else null |
| `items` | For a list: its objects (at most the `count` setting, if the display has one) |
| `list` | For a list made in the admin: its `ListDefinition` (its `title`, `path` …); else null |
| `settings` | The settings, checked and cast, with the defaults for the missing and invalid ones |
| `display` | The display's name |
| `id` | Unique on the page (`display-3`), for HTML ids: the same display can be on a page twice |
| `page_path` | The page's path |
| … | What `variables()` returned |

The templates run in the sandbox, as every template ([chapter 7](07-http-and-view.md)).

## What is shown: `Subject`

`Campanella\Display\Subject` · **Public** · readonly

| | |
|---|---|
| `Subject::object(CampanellaObject $object)` | One object |
| `Subject::list(iterable $items, ?ListDefinition $list = null)` | A list of objects (anything else in it is left out), and the list made in the admin they come from |
| `Subject::system(string $source, mixed $value = null, ?CampanellaObject $object = null)` | A system source's value, and its object if it has one |
| `kind`, `object`, `items`, `list`, `source`, `value` | Its parts |
| `isObject()`, `isList()`, `isSystem()` | Which of the three |

## Where it is shown: `DisplayContext`

`Campanella\Display\DisplayContext` · **Public**

`new DisplayContext(Actor $actor, string $path = '/', ?CampanellaObject $current = null, ?Closure $loadUser = null, string $search = '', string $basePath = '')`

| | |
|---|---|
| `actor` | The visitor, with their roles |
| `path` | The page's path (without the installation's folder) |
| `current` | The page's object: the article on its own page; null elsewhere |
| `user()` | The logged-in user's object (read only when a display asks), or null |
| `search` | The words searched for, on the search page; `''` elsewhere |
| `basePath` | The installation's URL prefix |

A display reads only these from the request. This is what the cache of 0.5.0
will key a component's HTML by.

## Sources

A source is written as a component will store it:

| Source | | |
|---|---|---|
| `object:12` | An object, by its ID | the main menu, the "About us" page, a file, a snippet |
| `list:esemenyek` | A list made in the admin, by its machine name ([chapter 23](23-lists.md)), at most the display's `count` setting objects (else the list's own page size) | the next five events |
| `current` | The page's object | the author box beside an article |
| `system:visitor` | A system source, by its name | the login button |

**A source is always read with the visitor's rights**: through the Query
engine and the policy, as every page reads. A display of a draft, of a file for
members or of a restricted page shows nothing to one who may not see it. An
object's relations are loaded too, also with the visitor's rights.

### System sources

`Campanella\Display\SystemSource` · **Public** · interface

| Method | |
|---|---|
| `name(): string` | Lowercase, unique: `visitor` |
| `label(): string` | Its name in the admin |
| `subject(DisplayContext $context): ?Subject` | What it knows here, for this visitor; null: nothing to show |

The core's, registered in `config/app.php` (`display_sources`):

| Source | Class | Its value | Its object |
|---|---|---|---|
| `visitor` | `VisitorSource` | The visitor's `Actor` | The logged-in user, or null |
| `search` | `SearchSource` | The words searched for, on the search page | — |
| `site` | `SiteSource` | — (the template reads `site`: [chapter 20](20-site.md)) | — |
| `breadcrumbs` | `BreadcrumbsSource` | `ancestors` (for a tree node: those the visitor may see, from the top) and `current`; nothing on a page without an object | The page's object |

The page's object is set by the object's page (`ObjectController`) in
`CurrentPage` (`set()`, `object()`), and forgotten at the start of every
request.

## The registry

`Campanella\Display\DisplayRegistry` · **Public**

`new DisplayRegistry(array $displays = [], array $sourceClasses = [], ?Closure $factory = null)`

The Kernel makes it from `config/app.php`: `displays` and `display_sources`
(without them, the core's own: the constants `DisplayRegistry::BUILTIN` and
`BUILTIN_SOURCES`). A display declared wrong, or a name used twice, is a
`DisplayException`.

| Method | |
|---|---|
| `has(string $name): bool` | Whether there is such a display |
| `definition(string $name): DisplayDefinition` | Its declaration |
| `definitions(): array<string, DisplayDefinition>` | All of them, in their order |
| `display(string $name): Display` | The display (made once, by the factory) |
| `accepts(string $name, Subject $subject): bool` | Whether the display can show the subject: its declaration and its own `accepts()`; false for an unknown one |
| `for(Subject $subject): list<DisplayDefinition>` | The displays that can show the subject: the choice the admin offers |
| `sources(): array<string, SystemSource>` | The system sources |
| `hasSource(string $name): bool`, `source(string $name): SystemSource` | One of them |

`DisplayDefinition` (**Public**, readonly): `name`, `class`, `label`,
`requires`, `many`, `sources`, `settings` (name ⇒ `Field`);
`DisplayDefinition::fromClass($class)` reads it, `isForObjects()` tells
whether it is for objects, `allows(Subject $subject)` whether its declaration
allows the subject.

## Showing: `DisplayRenderer`

`Campanella\Display\DisplayRenderer` · **Public**

```php
$renderer = $container->get(DisplayRenderer::class);
$html = $renderer->show('system:visitor', 'login_button', [], $context);
$html = $renderer->render('download', Subject::object($file), ['show_details' => false], $context);
```

| Method | |
|---|---|
| `show(string $source, string $display, array $settings, DisplayContext $context): string` | A source shown with a display; `''` if the source shows nothing here (a hidden or deleted object, an unknown list, no page object) |
| `render(string $display, Subject $subject, array $settings, DisplayContext $context): string` | A subject shown with a display; `''` if the display's `variables()` says so |
| `subject(string $source, DisplayContext $context, ?int $limit = null): ?Subject` | What a source shows here, for this visitor |
| `static parseSource(string $source): array{0: string, 1: string}` | A source's parts: `['object', '12']`, `['list', 'esemenyek']`, `['current', '']`, `['system', 'visitor']` |
| `settings(string $display, array $given): array` | The settings it is shown with: cast to their fields' types, the missing, empty or invalid ones replaced by their defaults, unknown ones left out. Settings never stop a page |
| `check(string $display, array $given): array{settings, errors}` | The settings as given in a form, cast, with their problems (`Message`s by setting): for the component's form (0.3.3, part 2) |
| `registry(): DisplayRegistry` | Its registry |

An unknown display or source, a source not written as one, and a display that
cannot show the subject are a `DisplayException`.

`MAX_ITEMS` (100): the most objects a display shows from a list.

## The built-in displays

| Display | Class | For | Settings (default) |
|---|---|---|---|
| `full` | `FullDisplay` | a `Titled` object | `show_title` (yes), `show_date` (yes; since 0.3.5) |
| `teaser` | `TeaserDisplay` | a `Titled` object | `show_date` (yes), `show_lead` (yes) |
| `link` | `LinkDisplay` | a `Routable` object | `text` (the title) |
| `info_box` | `InfoBoxDisplay` | a `Titled` object (its text, if `Textual`) | `show_title` (yes), `style`: `light`, `primary`, `info`, `warning` (light) |
| `navigation` | `NavigationDisplay` | a menu | `style`: `horizontal` (a bar with dropdowns) or `vertical` (a list); `depth` 1–3 (2) |
| `image` | `ImageDisplay` | a `MediaFile` object | `width` in pixels, 16–4000 (none: the column's, with the copies as `srcset`); `caption` (no) |
| `download` | `DownloadDisplay` | a `Downloadable` object | `show_details`: the type and size (yes) |
| `cards` | `CardsDisplay` | a list | `count` 1–100 (5), `columns` 1–4 (1), `show_more`: a link to the list's page (yes), `show_title`: the list's title above the cards (no; since 0.3.5) |
| `table` | `TableDisplay` | a list | `count` 1–100 (10), `show_more` (yes); the columns are the list's own |
| `login_button` | `LoginButtonDisplay` | the visitor | logging in (and back to this page), or out |
| `admin_button` | `AdminButtonDisplay` | the visitor | only for those who may enter the admin |
| `login_form` | `LoginFormDisplay` | the visitor | `show_reset`: the forgotten password's link (yes); nothing for a logged-in visitor |
| `user_card` | `UserCardDisplay` | the visitor | the user's name, the admin and logout buttons; nothing for an anonymous visitor |
| `search_box` | `SearchBoxDisplay` | the search | `placeholder` (`search.placeholder`) |
| `branding` | `BrandingDisplay` | the site | `show_slogan` (yes) |
| `breadcrumbs` | `BreadcrumbsDisplay` | the breadcrumbs | `show_home` (yes) |

The constants: `ImageDisplay::MIN_WIDTH`, `MAX_WIDTH`;
`CardsDisplay::MAX_COLUMNS` (and `countProblem()`, the `count` check
`TableDisplay` shares); `NavigationDisplay::MAX_DEPTH`; each source's `NAME`.

**`full` and `teaser` are the templates they were.** Their templates are the
object's, `object/<blueprint>--full.html.twig` and `object/full.html.twig`
(`teaser` likewise), with the variables they had (`object`, `mode`) and
`settings`; `render_object(item, 'teaser')` renders the display, with the same
HTML. A theme's own template keeps working; one that copied the core's may
add the settings (`{% if settings.show_title ?? true %}`).

**The login form** is sent to the login page, which checks it (its CSRF token,
the login guards' fields) and shows any error. Like the login page, a page with
the login form starts a session (a cookie) for its token, so it is best placed
where visitors log in, not on every page.

The `cards` and `table` displays show a list as its page does (the teasers, the
table of its columns), without its filters and pager.

## In templates: `display()`

`{{ display(subject, name, settings = {}) }}`

- `subject`: an object, a list of objects (e.g. a query's result), or a source
  (`'object:12'`, `'list:esemenyek'`, `'current'`, `'system:visitor'`);
- `name`: the display;
- `settings`: its settings; the missing and invalid ones take their defaults.

It prints `''` if there is nothing to show. An unknown display, or one that
cannot show the subject, is an error in debug mode (`debug` in
`config/local.php`), else nothing is printed and the error log says why: a
page never breaks because of a display.

```twig
{# A theme's sidebar #}
{{ display('system:visitor', 'user_card') }}
{{ display('list:esemenyek', 'table', {count: 5}) }}
{% for file in related(object, 'files') %}{{ display(file, 'download') }}{% endfor %}
```

## The `snippet` Blueprint

A **snippet** (*Szövegrészlet*) is a short text with a title: the opening
hours, a contact, a notice. It has `Titled` and `Textual` (formatted text,
with the editor's basic toolbar), and no page of its own (it is not
`Routable`). It is made for displays: `display(snippet, 'info_box')`, and in
0.3.4 a component in a sidebar. Editors can edit snippets, as any content.

If a site made a Blueprint named `snippet` in the admin before 0.3.3, theirs
keeps the name ([chapter 2](02-objects.md#on-the-registry)), and the core's is
left out.

## Components

A component is an object of the **`component` Blueprint**: a title, a machine
name (`Keyed`), who sees it (`Restricted`: everyone, logged-in users, some
roles), and the `Component` capability with its source, its display and the
display's settings ([chapter 3](03-capabilities.md#component)). The settings are
JSON in the data column, never queried.

```twig
{{ component('lablec_menu') }}
```

`component()` shows the component of that machine name, read with the
visitor's rights: one for logged-in users is not shown to others, and its
source is read as every display's is. It prints `''` if there is no such
component, the visitor may not see it, or it no longer works; a component never
takes the page down. (Its machine name is unique among every `Keyed` object,
menus included.)

**Only administrators** make, change and delete components
(`DefaultPolicy::ADMINISTRATORS_EDIT`, [chapter 5](05-access.md#defaultpolicy)):
where something appears on the site is a right above an editor's. Editors
change what a component shows (the menu, the snippet), not the component.

### ComponentService

`Campanella\Display\ComponentService` · **Public**

```php
$components = $container->get(ComponentService::class);
$footer = $components->create(Actor::system(), ['title' => 'Footer menu', 'machine_name' => 'lablec_menu'],
    'object:12', 'navigation', ['style' => 'vertical']);
echo $components->show('lablec_menu', $context);
```

| Method | |
|---|---|
| `create(Actor $actor, array $values, string $source, string $display, array $settings = [], ?CampanellaObject $page = null): CampanellaObject` | A new component; `$values` are its other fields (`title`, `machine_name`, `visible_to`, `visible_roles`). With `$page` (since 0.3.5), that page's own ([chapter 29](29-layouts.md#a-pages-own-components)). A `ValidationException` for a problem of the source (`source`), the display (`display`), a setting (its name), a field, or the page (`page`) |
| `update(Actor $actor, CampanellaObject $component, array $values, string $source, string $display, array $settings = [])` | Changes one, with the same checks |
| `check(string $source, string $display, array $settings): array{settings, errors}` | The checks themselves: the source exists, the display is one of `displaysFor()`, the settings are valid (`DisplayRenderer::check()`) |
| `sourceExists(string $source): bool` | Whether a source names something that exists, whoever may see it; `current` always does |
| `displaysFor(string $source): list<DisplayDefinition>` | The displays it can be shown with; for `current`, every display for objects (the page's object decides when it is shown) |
| `problem(CampanellaObject $component): ?Message` | Why it does not work now: its source is gone (`components.source_missing`), its display is gone (`components.display_missing`) or cannot show the source any more (`components.display_unfit`); null if it works |
| `show(string $key, DisplayContext $context): string` | The component of this machine name shown here, if the visitor may see it |
| `find(string $key, Actor $actor): ?CampanellaObject` | The component of this machine name, if the actor may see it |
| `render(CampanellaObject $component, DisplayContext $context): string` | A component shown here |
| `isAvailable(): bool` | Whether there are components on this site: the `component` Blueprint has the `Component` capability. Not if the site made a Blueprint of that name in the admin before 0.3.3: theirs keeps the name, the admin's *Components* is not shown, `create()` is a `DisplayException`, and `component()` shows nothing |

The `Component` capability checks only the stored form (a source written as
one, a display's name, settings that are a JSON object of at most
`MAX_SETTINGS_BYTES`, 16 KB), so an object saved some other way (an importer)
cannot hold anything else; whatever it holds, a setting that is not valid is
shown with its default.

### In the admin: ComponentPages

`Campanella\Admin\ComponentPages` · **Internal** · *Appearance → Components*

| Path | Page |
|---|---|
| `/admin/component` | The list: title, source, display, who sees it, the `component()` call; the ones that no longer work marked with the reason |
| `/admin/component/new/source` | 1. **The source:** the system sources, the page's object, a list made in the admin, or an object (a Blueprint, then a search in the titles; at most `MAX_OBJECTS`, 50) |
| `/admin/component/new/display?source=…` | 2. **The display:** those that can show the source |
| `/admin/component/new?source=…&display=…` | 3. **The form:** the title, the machine name, who sees it, and the display's settings, a form built from its fields (`ObjectForm::settings()`); POST saves |
| `/admin/component/<id>` | Editing one; *Another source or display* goes through the steps again (`/admin/component/<id>/source`, `/display`) |
| `/admin/component/<id>/delete` | Deleting one (what it showed stays) |
| `/admin/component/regions?layout=…` | *Appearance → Regions* (since 0.3.4): the regions of a layout (the site's by default; the others as tabs), each with its components in their order, their conditions in words; **Up**, **Down**, **Take out** (`POST /admin/component/<id>/move-up`, `/move-down`, `/unplace`) and **Add** a component to the region's end (`POST /admin/component/regions/add`: `component`, `region`; one in another region leaves that one) |

Since 0.3.4 the form (3.) also says **where the component shows**: its region
(or none: only `component()` shows it) and its conditions (the front page, paths,
the Blueprints with pages, except paths; [chapter 29](29-layouts.md#conditions)),
checked with the rest (`ComponentService::placementProblems()`) and saved with
`place()`. Put into another region, it goes to that region's end; its order is
set on the regions page. The list shows each component's region.

Since 0.3.5 a page's own components are made in the same three steps under
the page's address (`/admin/<blueprint>/<id>/components/new/source`, `/display`,
`/new`; `handle(…, ?CampanellaObject $page)`), the region chosen among those of
the page's layout meant for a page (kept through the steps as `?region=`), with
no conditions; an editor reaches these, and a page's own component's form and
delete page (`/admin/component/<id>`, `/delete`), for the pages they may change,
and comes back to the page's components. `pagePath(CampanellaObject $page): string`
gives that address (`page/12/components`). The list shows whose page a
component is.

Every other page is for the system roles only (403 for an editor), every POST checks
the CSRF token. `isAvailable()`: whether there are components
(`ComponentService::isAvailable()`).

