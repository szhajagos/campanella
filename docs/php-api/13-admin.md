# 13. Admin UI

The browser-based administration, under `/admin` (since 0.0.4). It is built
in parts; this chapter grows with them (see the [ROADMAP](../../ROADMAP.md)):

1. ✅ Frame: access, layout, dashboard, one-time messages, translated labels.
2. ✅ Listing content, with filtering and sorting.
3. ✅ Generated forms: creating and editing objects.
4. Publishing, unpublishing and deleting.

## Access

- **Entering** is decided by role: by default `administrator` and `editor`
  (the `admin.roles` setting). Without login the visitor is redirected to the
  login page (and back to the requested page after logging in); a logged-in user without such a role
  gets a 403 page.
- **What a user may do inside** (create, edit, delete…) is still decided by the
  `AccessPolicy` object by object ([chapter 5](05-access.md)); e.g. the
  `DefaultPolicy` does not let an `editor` delete.
- Admin pages are personal (`Cache-Control: private, no-store`, as every page
  with a session) and are not indexed (`X-Robots-Tag: noindex, nofollow`).

## AdminAccess

`Campanella\Admin\AdminAccess` · **Public** · `final readonly class` · container: `AdminAccess::class`

| Member | Description |
|---|---|
| `__construct(string $path = '/admin', array $roles = ['administrator', 'editor'])` | From the `admin.path` and `admin.roles` settings. `InvalidArgumentException` for an invalid path |
| `allows(Actor $actor): bool` | Whether the actor has one of the roles |
| `path(string $subpath = ''): string` | An admin path, e.g. `path('article')` → `/admin/article` |
| `matches(Request $request): bool` | Whether the request is for an admin page |

## AdminController

`Campanella\Controller\AdminController` · **Internal** · container: `controller.admin`

Handles every path under the admin prefix (registered with
`Router::prefix()`, [chapter 7](07-http-and-view.md#router-and-routematch)).
The `subpath` route parameter selects the page. Everything goes through the
`QueryEngine`, so only what the user may see is shown.

| Path | Page |
|---|---|
| `/admin` | Dashboard: the number of objects per Blueprint, the ten most recently modified objects |
| `/admin/<blueprint>` | The list of a Blueprint's objects (see below) |
| `/admin/<blueprint>/new` | Creating an object (form; POST creates it) |
| `/admin/<blueprint>/<id>` | Editing an object (form; POST saves it) |

Blueprints with the `Authenticatable` capability (users) are not managed as
content; user management in the browser comes later.

### The list

`/admin/<blueprint>?q=…&status=…&sort=…&dir=…&page=…`, 20 per page
(`AdminController::PER_PAGE`).

| Parameter | Meaning |
|---|---|
| `q` | Search in the title (for `Titled` Blueprints); `%` and `_` are searched literally |
| `status` | For `Publishable` Blueprints: `draft`, `published` (published and the time has come), `scheduled` (published, but in the future) — `AdminController::STATUSES` |
| `sort` | `title`, `updated`, `created`, `published_at` (those the Blueprint has); anything else falls back to `updated` |
| `dir` | `asc` or `desc` (default) |

The columns follow the Blueprint's capabilities: title, status, author
(`Authorable`; loaded with one query by the `RelationLoader`), publication
time, modification time. The column headers sort the list.

## Forms

The editing form is generated from the object's field and relation
definitions, so a new Blueprint or capability gets its form automatically.

### ObjectForm and FormField

`Campanella\Admin\Form\ObjectForm` · **Internal**

| Member | Description |
|---|---|
| `build(CampanellaObject $object, Actor $actor, ?array $input = null, array $errors = [], array $order = []): list<FormField>` | The form elements; with `$input`, the submitted values are shown again (after a failed save); `$order` is the Blueprint's `form_order` |
| `read(CampanellaObject $object, array $post, Actor $actor): array` | The submitted form as `values`, `relations` (name → target IDs) and `errors` (values that cannot be read) |
| `MANAGED_FIELDS` | `status`, `published_at`, `format`: not in the form (publishing is a separate action; the text format belongs to the HTML editor in 0.0.5) |
| `MAX_OPTIONS` | 500: the most relation targets offered (the current targets are always offered too) |

`Campanella\Admin\Form\FormField` · **Internal** · `final readonly class`:
`$name`, `$kind` (`field` or `relation`), `$widget`, `$label`, `$required`,
`$multiple`, `$max`, `$value`, `$options`, `$error`, `$help`, `$disabled`,
`$attributes`; `inputName()` (`f[title]`, `f[tags][]`, `r[categories][]`)
and `inputId()`.

| Field type / relation | Widget (`templates/admin/form/<widget>.html.twig`) |
|---|---|
| String | `string`: text input with `maxlength` |
| Text | `text`: text area (tall for `body`) |
| Integer | `integer`: number input (the `INT` range) |
| Boolean | `boolean`: checkbox; in a multi-valued field a yes/no select per value |
| DateTime | `datetime`: date and time in the site time zone (`timezone` setting), stored in UTC |
| StringList | `list`: text area, one value per line |
| `One` relation | `select`: drop-down with "none" |
| `Many` relation | `checkboxes`: one checkbox per possible target |

- **Multi-valued fields** get one input per value, with buttons to add,
  remove and reorder values, up to the limit (`public/assets/admin.js`, plain
  JavaScript, no dependencies).
- **Hidden fields** (e.g. `password_hash`) are never in the form, and posted
  values for them are ignored, as for the managed fields.
- **Relations:** the possible targets are those of the relation's target
  Blueprints and capabilities that the user may see, ordered by title. Only
  offered targets can be added or removed: a current target that was not
  offered is kept, and a posted ID that was not offered is ignored.
- **HTML text:** a `body` in `html` format is read-only until the HTML filter
  arrives in 0.0.5.
- **Path:** may be left empty (Routable makes it from the title). A path that
  a fixed route, the admin or a public folder (`/assets`, `/themes`) already
  uses is rejected (`validation.path_reserved`).
- **Order:** the Blueprint's `form_order` key, e.g.
  `'form_order' => ['title', 'lead', 'body', 'categories', 'author', 'path']`.

### Saving

Every form is a POST with a CSRF token; after a successful save the browser is
redirected (303) to the edit page, with a one-time "Saved" message, so
reloading does not save again. Errors are shown at their fields (422); a value
that cannot be read (e.g. "abc" for a number) is reported the same way.

**Concurrent edits:** the edit form carries a version token (a hash of the
object's stored fields, relations and modification time). If someone else
saved the object since the form was opened, the save is refused (409) with a
warning, and the form keeps the user's input; saving again then overwrites
the other change deliberately.

## Templates

The admin templates live in `templates/admin/` and are always loaded as
`@core/admin/…`, so the public theme never affects the admin, and a broken
theme cannot lock anyone out. The look is Bootstrap 5.3 with a small
`public/assets/admin.css`.

| Template | Purpose |
|---|---|
| `admin/base.html.twig` | Layout: top bar, sidebar menu (block `menu`), messages, block `content` |
| `admin/dashboard.html.twig` | The dashboard |
| `admin/list.html.twig` | The list of a Blueprint's objects, with the filter form and pagination |
| `admin/form.html.twig` | The create/edit page |
| `admin/form/_row.html.twig` | One form row: label, widget (repeated for multi-valued fields), help, error |
| `admin/form/<widget>.html.twig` | One input per widget (see above) |
| `admin/_status.html.twig` | Publication status badge (draft, published, scheduled) |

## One-time messages: Flash

`Campanella\Http\Flash` · **Public** · `final class` · container: `Flash::class`

Messages for the next page (e.g. "Saved." after a redirect), kept in the
session; reading them removes them.

| Member | Description |
|---|---|
| `SUCCESS`, `INFO`, `WARNING`, `DANGER` | Types (Bootstrap alert variants) |
| `add(string $type, Message\|string $message): void` | Adds a message (a `Message` or a message key); the session must be started |
| `take(): list<array{type, message}>` | The messages, removed from the session; does not start a session |

In templates: `{% for flash in flash_messages() %}…{{ flash.text }}…{% endfor %}`.

## Labels

The labels of capabilities, fields, relations, Blueprints and Blueprint lists
are message keys (`capability.titled`, `field.title`, `relation.categories`,
`blueprint.article`, `list.category.articles`), translated where they are
shown: `{{ t(field.label) }}`. A label that is not a key (e.g. in a custom
capability) is shown as it is.
