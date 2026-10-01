# 13. Admin UI

The browser-based administration, under `/admin` (since 0.0.4). It is built
in parts; this chapter grows with them (see the [ROADMAP](../../ROADMAP.md)):

1. ✅ Frame: access, layout, dashboard, one-time messages, translated labels.
2. Listing content, with filtering and sorting.
3. Generated forms: creating and editing objects.
4. Publishing, unpublishing and deleting.

## Access

- **Entering** is decided by role: by default `administrator` and `editor`
  (the `admin.roles` setting). Without login the visitor is redirected to the
  login page (and back after logging in); a logged-in user without such a role
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
The `subpath` route parameter selects the page; `''` is the dashboard, which
shows the number of objects per Blueprint and the ten most recently modified
objects (both through the `QueryEngine`, so only what the user may see).

## Templates

The admin templates live in `templates/admin/` and are always loaded as
`@core/admin/…`, so the public theme never affects the admin, and a broken
theme cannot lock anyone out. The look is Bootstrap 5.3 with a small
`public/assets/admin.css`.

| Template | Purpose |
|---|---|
| `admin/base.html.twig` | Layout: top bar, sidebar menu (block `menu`), messages, block `content` |
| `admin/dashboard.html.twig` | The dashboard |
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
