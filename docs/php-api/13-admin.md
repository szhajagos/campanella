# 13. Admin UI

The browser-based administration, under `/admin` (since 0.0.4). It is built
in parts; this chapter grows with them (see the [ROADMAP](../../ROADMAP.md)):

1. ✅ Frame: access, layout, dashboard, one-time messages, translated labels.
2. ✅ Listing content, with filtering and sorting.
3. ✅ Generated forms: creating and editing objects.
4. ✅ Publishing, unpublishing (also scheduled) and deleting.

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
- Since 0.0.5 they carry a **Content-Security-Policy**
  (`AdminController::CONTENT_SECURITY_POLICY`): only scripts from this site,
  no inline script, no frames, no plugins, forms only to this site. Even HTML
  that got past the filter could not run code in the admin. Styles may be
  inline, because the editor creates style elements.

## AdminAccess

`Campanella\Admin\AdminAccess` · **Public** · `final readonly class` · container: `AdminAccess::class`

| Member | Description |
|---|---|
| `__construct(string $path = '/admin', array $roles = ['administrator', 'editor'], array $systemRoles = ['administrator'])` | From the `admin.path`, `admin.roles` and `admin.system_roles` settings. `InvalidArgumentException` for an invalid path |
| `allows(Actor $actor): bool` | Whether the actor has one of the roles |
| `allowsSystem(Actor $actor): bool` | Whether the actor may open the System page: may enter, and has one of the system roles (since 0.0.5) |
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
| `POST /admin/<blueprint>/<id>/publish` | Publishing, now or at a given time ([below](#publishing-and-deleting)) |
| `POST /admin/<blueprint>/<id>/unpublish` | Unpublishing |
| `/admin/<blueprint>/<id>/delete` | Deleting: a confirmation page; POST deletes |
| `POST /admin/media/upload` | Uploading an image: JSON for the editor and the Images list, a redirect for the form without JavaScript ([chapter 16](16-media.md#uploading-from-the-admin)) |
| `POST /admin/<blueprint>/<id>/convert-html` | Converts a saved plain body to a formatted one ([below](#formatted-text-the-html-editor)) |
| `/admin/system` | The System page ([below](#the-system-page)) |
| `/admin/upgrade` | Running the upgrade from the browser; a route of its own (`UpgradeController`), so it works before logging in and while the rest of the admin waits for the upgrade ([chapter 17](17-migrations.md#from-the-browser)) |
| `POST /admin/system/clear-cache` | Clears the template cache |

| Constant | Value |
|---|---|
| `PER_PAGE` | 20: objects per list page |
| `STATUSES` | The status filters of the list |
| `DISPLAY_DATETIME` | `'Y-m-d H:i'`: how times are shown (in the site's time zone) |
| `MAX_REFERRERS` | 20: the most referring objects listed on the delete page |

Blueprints with the `Authenticatable` capability (users) are not managed as
content; user management in the browser comes later. The objects of a
Blueprint with the `MediaFile` capability (images) are listed with
thumbnails, type, size and dimensions (sortable by size; a reminder where the
alternative text is missing), and edited (title, alternative text) with a
preview and the file's data beside the form, but not created from an empty
form: they are created by uploading, also right on the Images list
([chapter 16](16-media.md#in-the-images-list)). Their delete page warns that
texts showing the image will miss it. `system`, `media` and `upgrade` are
admin paths, so no Blueprint can have these names (`BlueprintRegistry::RESERVED_NAMES`).

### The list

`/admin/<blueprint>?q=…&status=…&sort=…&dir=…&page=…`, 20 per page
(`AdminController::PER_PAGE`).

| Parameter | Meaning |
|---|---|
| `q` | Search in the title (for `Titled` Blueprints); `%` and `_` are searched literally |
| `status` | For `Publishable` Blueprints: `draft`, `published` (published and the time has come, i.e. publicly visible), `scheduled` (published, but in the future) — `AdminController::STATUSES` |
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
| `__construct(QueryEngine $queries, Translator $translator, string $timezone = 'UTC', ?HtmlSanitizer $html = null)` | `$html`: its allowlist is given to the HTML editor (since 0.0.5) |
| `build(CampanellaObject $object, Actor $actor, ?array $input = null, array $errors = [], array $order = [], array $editors = []): list<FormField>` | The form elements; with `$input`, the submitted values are shown again (after a failed save); `$order` is the Blueprint's `form_order`, `$editors` its `editor` key |
| `read(CampanellaObject $object, array $post, Actor $actor): array` | The submitted form as `values`, `relations` (name → target IDs) and `errors` (values that cannot be read) |
| `MANAGED_FIELDS` | `status`, `published_at`, `format`: not in the form (publishing and converting a text to HTML are separate actions) |
| `EDITOR_PROFILES` | `full`, `basic`: the editor's toolbar profiles; the first is the default |
| `MAX_OPTIONS` | 500: the most relation targets offered (the current targets are always offered too) |
| `parseDateTime(string $text): ?DateTimeImmutable` | A date and time typed in the site's time zone (`2026-10-02T14:30:00`, `2026-10-02 14:30`, …) as UTC; empty: null; `UnexpectedValueException` with the key `validation.invalid_date` |
| `localDateTime(?DateTimeInterface $time, string $format = 'Y-m-d\TH:i:s'): string` | A time in the site's time zone (by default in the `datetime-local` input format); null: empty text |
| `timezone(): string` | The site's time zone (the `timezone` setting) |

`Campanella\Admin\Form\FormField` · **Internal** · `final readonly class`:
`$name`, `$kind` (`field` or `relation`), `$widget`, `$label`, `$required`,
`$multiple`, `$max`, `$value`, `$options`, `$error`, `$help`, `$disabled`,
`$attributes`; `inputName()` (`f[title]`, `f[tags][]`, `r[categories][]`)
and `inputId()`.

| Field type / relation | Widget (`templates/admin/form/<widget>.html.twig`) |
|---|---|
| String | `string`: text input with `maxlength` |
| Text | `text`: text area (tall for `body`) |
| Text, the `Textual` body in `html` format | `html`: the HTML editor ([below](#formatted-text-the-html-editor)) |
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
- **HTML text:** a `body` in `html` format is edited with the HTML editor (see
  below); it is filtered on save like any HTML text.
- **Path:** may be left empty (Routable makes it from the title). A path that
  a fixed route, the admin or a public folder (`/assets`, `/themes`) already
  uses is rejected (`validation.path_reserved`).
- **Order:** the Blueprint's `form_order` key, e.g.
  `'form_order' => ['title', 'lead', 'body', 'categories', 'author', 'path']`.

### Formatted text: the HTML editor

Since 0.0.5 a `Textual` body in `html` format is edited with
[Jodit](https://xdsoft.net/jodit/) (the MIT edition, 4.17, shipped in
`public/assets/vendor/jodit/`, no CDN). `public/assets/admin-editor.js` turns
every `<textarea data-editor>` of the `html` widget into an editor; switching
to another editor means replacing these two files. Without JavaScript the
textarea with the raw HTML remains.

- **The filter decides.** The editor is a convenience; every saved HTML text
  is filtered on the server ([chapter 15](15-html.md)). The editor gets the
  same allowlist (`data-allow-tags`), so it offers and keeps what the filter
  keeps, and cleans pasted content (e.g. from Word) the same way.
- **Toolbar profiles** per field, in the Blueprint:
  `'editor' => ['body' => 'full']`. `full`: paragraph styles (normal, h2–h4,
  quote, code), bold, italic, strikethrough, sub- and superscript, lists,
  link, image (if the user may upload images), table, horizontal rule, clear
  formatting, undo/redo, HTML view, full screen. `basic`: bold, italic, lists, link, clear formatting, undo/redo.
- **Nothing from other servers:** the HTML view is a plain text area (not Ace
  from a CDN), HTML beautifying (from a CDN) is off, and the plugins that call
  outside services (AI assistant, speech recognition, the "powered by" link)
  are disabled.
- **The editor's language** follows the `locale` setting; its colors follow the
  light or dark mode.
- **New articles and pages** get a formatted body: the Blueprint's `defaults`
  key sets `format` to `html` ([chapter 2](02-objects.md#blueprint)).
- **Converting a plain text:** the edit form of a saved object with a plain
  body has a *Convert to formatted text* button. It converts the stored text
  with `PlainText::toHtml()`, so the paragraphs and line breaks are kept and
  every character is escaped (`POST /admin/<blueprint>/<id>/convert-html`;
  CSRF and version checks as for publishing; it needs the `Update` permission).
  Unsaved changes of the form are not part of it.

`Campanella\Html\PlainText` · **Public** · `final class`

| Method | Description |
|---|---|
| `static toHtml(string $text): string` | Plain text as HTML, the way a plain text is shown on the site: blank lines separate paragraphs (`<p>`), a single line break becomes `<br>`, every character is escaped |

### Saving

Every form is a POST with a CSRF token; after a successful save the browser is
redirected (303) to the edit page, with a one-time "Saved" message, so
reloading does not save again. The new-object form of a `Publishable`
Blueprint also has a **Create and publish** button (since 0.0.5), if the
policy allows publishing: it creates the object published, now. Errors are shown at their fields (422); a value
that cannot be read (e.g. "abc" for a number) is reported the same way.

**Concurrent edits:** the edit form carries a version token (a hash of the
object's stored fields, relations and modification time). If someone else
saved the object since the form was opened, the save is refused (409) with a
warning, and the form keeps the user's input; saving again then overwrites
the other change deliberately.

## Publishing and deleting

**Publication panel.** The edit page of a `Publishable` object has a panel
next to the form with the state (draft, published since…, appears at…) and the
actions the `AccessPolicy` allows:

- **Publish** with an optional time, typed in the site's time zone. Empty:
  now. A future time schedules the publication: the object is `published`, but
  becomes visible only at that time (the `Publishable` rule, so no background job
  is needed). The time of an already published or scheduled object can be
  changed the same way.
- **Unpublish:** back to draft. The publication time is kept, so publishing
  again offers it.

These are separate forms: they change only the publication status and time,
not the unsaved changes of the edit form (a hint appears once the form is
changed). Like saving, they need the CSRF token and the current version token;
on a stale version nothing changes and a warning is shown. If the stored object
is invalid (e.g. a required relation lost its target), nothing changes and the
validation messages are shown. Every action redirects (303) back to the edit
page with a one-time message. A GET request to them (by a user who may take
the action) gives 405.

**Deleting.** The *Delete* button appears only if the policy allows `Delete`
(the `DefaultPolicy` allows it for the `administrator` only; an `editor` gets
no button and a 403 page). It opens a confirmation page, which lists the
objects whose relations point to the object (the references are removed with
it), marking those whose *required* relation would be left empty: they can be
saved again only after choosing a new target. Confirming (POST with a CSRF
token) deletes the object permanently and returns to the list.

## The system page

`/admin/system` shows the [system check](14-system-check.md): versions,
PHP extensions, writable folders, settings, limits, caches, each with a
verdict and, where useful, what to do. It also has a **Clear the template
cache** button. Only the `admin.system_roles` (by default `administrator`)
may open it; it appears in the sidebar for them, and the dashboard shows them
a warning bar if a check reports an error. Others get a 403.

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
| `admin/_publication.html.twig` | The publication panel of the edit page |
| `admin/delete.html.twig` | The delete confirmation page |
| `admin/system.html.twig` | The System page |
| `admin/form/_row.html.twig` | One form row: label, widget (repeated for multi-valued fields), help, error |
| `admin/form/<widget>.html.twig` | One input per widget (see above); `html` is the editor's textarea, `text` shows the convert button for a saved plain body |
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
