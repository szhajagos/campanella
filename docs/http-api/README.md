# HTTP API

Two parts: the **HTML interface**, which works as of 0.0.1, and the **JSON API**,
which is still a draft. The draft is here already so that, while extending the
PHP API, we can see what it will need to build on.

| Marker | Meaning |
|---|---|
| ✅ **done** | Works; the description records the current behavior |
| 📝 **planned** | Draft: may still change when implemented |

## HTML interface ✅

Pages intended for browsers. Without login, every request runs as an anonymous
visitor, so only publicly visible content is shown; when logged in, content is
shown according to the user's roles (e.g. an `editor` also sees drafts).

| Route | Source | Description |
|---|---|---|
| `GET /` | `config/routes.php` → Query `frontpage` | The 3 most recent published articles |
| `GET /hirek` | `config/routes.php` → Query `news` | Published articles, 5 per page |
| `GET /hirek?page=N` | | Pagination; a nonexistent page: 404 |
| `GET /kategoriak` | `config/routes.php` → Query `categories` | Published categories (0.0.2); since 0.0.7 as a tree, in their hand-set order |
| `GET /belepes`, `POST /belepes` | `AuthController` | Login form and login (0.0.3); details: [PHP API chapter 11](../php-api/11-users.md#web-interface) |
| `POST /kilepes` | `AuthController` | Logout with a CSRF token |
| `GET /admin/upgrade`, `POST /admin/upgrade` | `UpgradeController` | Running the upgrade from the browser (0.0.6): for administrators, or with the upgrade key; [PHP API chapter 17](../php-api/17-migrations.md#from-the-browser) |
| `GET /admin…`, `POST /admin…` | `AdminController` | The admin UI (0.0.4): dashboard, lists, forms, publishing, deleting; details: [PHP API chapter 13](../php-api/13-admin.md#admincontroller) |
| `GET /<path>` | Routable object | The object's own page, e.g. `/neumann-janos`. Its relations as links, and the Blueprint's `lists` below it (e.g. `/tudomany`: the articles of the category and, since 0.0.7, of its subcategories; a tree node also gets breadcrumbs and its children) |
| `GET /assets/<file>` | `public/assets/` | Static files |

| Status | When |
|---|---|
| `200` | OK |
| `302` | An admin page without login: to the login page, then back |
| `303` | After a successful form submission (login, saving, publishing, deleting): the next page |
| `400` | A form without a valid CSRF token |
| `403` | Logged in, but not allowed (e.g. the admin without an admin role, or an `editor` deleting) |
| `404` | No such route, or the object is not visible (draft, scheduled). The two are intentionally indistinguishable |
| `405` | A GET request to an action that only accepts POST (e.g. `/admin/<blueprint>/<id>/publish`) |
| `409` | Saving an admin form that someone else saved in the meantime |
| `422` | An admin form with invalid values |
| `500` | Internal error; with the message in debug mode |
| `503` | The system is not installed yet, or an upgrade is needed (with `Retry-After: 300`; since 0.0.6 also when a migration is pending: every page except logging in and out and the upgrade page) |

Every response gets an `X-Content-Type-Options: nosniff` header.

The navigation at the top of every page is the `main` menu (since 0.0.7),
edited in the admin ([PHP API chapter 19](../php-api/19-menus.md)); until
there is one, the built-in links.

## JSON API 📝

### Principles

- **Base URL:** `/api/v1/`. Backward-incompatible changes may only appear in a
  new version (`/api/v2/`).
- **Format:** JSON, UTF-8, `Content-Type: application/json`.
- **Identification:** externally always the object's `uuid`, never the internal
  numeric `id`. The UUID cannot be guessed, and it does not change on export or
  import.
- **Same core:** the JSON API is not new logic but another interface to the PHP
  API. It calls the `QueryEngine` for reading and the `ObjectService` for
  writing, so access control and validation apply the same way.
- **Timestamps:** ISO 8601, in UTC: `2026-09-25T12:54:00Z`.
- **Fields:** the object's fields by name, under the `fields` key.

### Object representation

```json
{
  "uuid": "01926f3a-7c1e-7b2a-9f4d-3c8e5a1b2d40",
  "blueprint": "article",
  "capabilities": ["titled", "textual", "routable", "publishable"],
  "created": "2026-09-20T12:54:00Z",
  "updated": "2026-09-20T12:54:00Z",
  "fields": {
    "title": "Neumann János",
    "path": "/neumann-janos",
    "status": "published",
    "published_at": "2026-09-20T12:54:00Z",
    "lead": "A számítógép-architektúra egyik atyja.",
    "body": "Neumann János 1903-ban született Budapesten.",
    "format": "plain"
  },
  "relations": {
    "categories": ["01926f3a-6b2d-7c11-8e0f-1a2b3c4d5e6f"]
  }
}
```

`relations` gives the UUIDs of each relation's targets, in the relation's
order. Only targets visible to the reader are included.

### Endpoints

| Method and route | PHP API | Status |
|---|---|---|
| `GET /api/v1/objects` | `QueryEngine::execute()` | 📝 |
| `GET /api/v1/objects/{uuid}` | `ObjectRepository::findByUuid()` + visibility | 📝 |
| `POST /api/v1/objects` | `ObjectService::create()` | 📝 |
| `PATCH /api/v1/objects/{uuid}` | `ObjectService::update()` | 📝 |
| `DELETE /api/v1/objects/{uuid}` | `ObjectService::delete()` | 📝 |
| `POST /api/v1/objects/{uuid}/publish` | `ObjectService::publish()` | 📝 |
| `POST /api/v1/objects/{uuid}/unpublish` | `ObjectService::unpublish()` | 📝 |
| `GET /api/v1/queries/{name}` | Named Query (`config/queries.php`) | 📝 |
| `GET /api/v1/blueprints` | `BlueprintRegistry::all()` | 📝 |
| `GET /api/v1/capabilities` | `CapabilityRegistry::all()` | 📝 |

#### Listing and filtering

```
GET /api/v1/objects?blueprint=article&having=routable,publishable
                   &filter[status]=published&sort=-published_at
                   &page=2&per_page=10
```

| Parameter | Query equivalent |
|---|---|
| `blueprint=a,b` | `blueprint('a', 'b')` |
| `having=x,y` | `having('x', 'y')` |
| `filter[field]=value` | `where('field', '=', 'value')` |
| `related[relation]=uuid,uuid` | `whereRelated('relation', …)` |
| `filter[field][op]=value` | `where('field', op, 'value')`, where `op` is one of `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in`, `like`, `null` |
| `scope=published` | `scope('published')` |
| `sort=field`, `sort=-field` | `orderBy('field', 'ASC')` or `'DESC'`; several can be given, separated by commas |
| `page`, `per_page` | `page()`; `per_page` is at most 100 |

The PHP API's rules apply here too: filtering and sorting are only possible on
fields stored in their own table (`Table`).

```json
{
  "data": [ { "uuid": "…", "blueprint": "article", "fields": { } } ],
  "meta": { "total": 42, "page": 2, "per_page": 10, "pages": 5 }
}
```

#### Creating

```http
POST /api/v1/objects
Content-Type: application/json

{ "blueprint": "article", "fields": { "title": "New article", "body": "…" }, "publish": false }
```

Response: `201 Created`, with the new object and a `Location` header.

#### Updating

With `PATCH`, only the given fields change:

```json
{ "fields": { "title": "Corrected title" }, "relations": { "categories": ["01926f3a-…"] } }
```

All targets of a given relation are replaced (`setRelated()`); relations not
mentioned do not change.

### Errors

Every error has the same structure:

```json
{
  "error": {
    "status": 422,
    "code": "validation_failed",
    "message": "Érvénytelen objektum.",
    "fields": { "title": "kötelező mező" }
  }
}
```

The `message` and `fields` texts are user-facing and currently Hungarian
("Invalid object.", "required field").

| Status | `code` | PHP exception |
|---|---|---|
| `400` | `bad_query` | `QueryException` (e.g. filtering on a JSON field) |
| `400` | `unknown_field` | `OutOfBoundsException` |
| `401` | `unauthenticated` | Missing or invalid token |
| `403` | `forbidden` | `AccessDeniedException` on a modifying operation |
| `404` | `not_found` | No such object, **or it is not visible**: when reading, a denial is also a 404, so that it does not reveal whether the object exists |
| `422` | `validation_failed` | `ValidationException`; `fields` is the contents of `$errors` |
| `500` | `internal_error` | Everything else |

### Authentication

Until authentication is implemented, the JSON API is read-only, as an
anonymous visitor. After that:

- `Authorization: Bearer <token>` header;
- the token identifies an `Actor` (`ActorKind::User` or `ActorKind::Service`)
  with its roles;
- access is decided by the same `AccessPolicy` as on the HTML interface.

### Open questions

- CORS settings (for applications running on other domains)
- Rate limiting
- Embedding related objects (`?include=categories`) instead of the UUID list
- Representation of multilingual fields
