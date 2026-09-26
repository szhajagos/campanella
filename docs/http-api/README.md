# HTTP API

Két rész: a **HTML-felület**, amely a 0.0.1-ben működik, és a **JSON API**,
amely még tervezet. A tervezet azért van itt már most, hogy a PHP API
bővítésekor lássuk, mire kell majd ráépülnie.

| Jelölés | Jelentés |
|---|---|
| ✅ **kész** | Működik, a leírás a mostani viselkedést rögzíti |
| 📝 **tervezett** | Tervezet: megvalósításkor még változhat |

## HTML-felület ✅

A böngészőknek szóló oldalak. Minden kérés anonymous látogatóként fut, így
csak a nyilvánosan látható tartalom jelenik meg.

| Útvonal | Forrás | Leírás |
|---|---|---|
| `GET /` | `config/routes.php` → Query `frontpage` | A 3 legfrissebb publikált cikk |
| `GET /hirek` | `config/routes.php` → Query `news` | Publikált cikkek, oldalanként 5 |
| `GET /hirek?page=N` | | Lapozás; nem létező oldal: 404 |
| `GET /<útvonal>` | Routable objektum | Az objektum saját oldala, pl. `/neumann-janos` |
| `GET /assets/<fájl>` | `public/assets/` | Statikus fájlok |

| Státusz | Mikor |
|---|---|
| `200` | Rendben |
| `404` | Nincs ilyen útvonal, vagy az objektum nem látható (piszkozat, időzített). A kettő szándékosan nem különböztethető meg |
| `500` | Belső hiba; debug módban az üzenettel |
| `503` | A rendszer még nincs telepítve |

Minden válasz `X-Content-Type-Options: nosniff` fejlécet kap.

## JSON API 📝

### Alapelvek

- **Alap-URL:** `/api/v1/`. Visszafelé nem kompatibilis változás csak új
  verzióban (`/api/v2/`) jelenhet meg.
- **Formátum:** JSON, UTF-8, `Content-Type: application/json`.
- **Azonosítás:** kifelé mindig az objektum `uuid`-ja, nem a belső numerikus
  `id`. A UUID nem kitalálható, és exportnál, importnál sem változik.
- **Ugyanaz a mag:** a JSON API nem új logika, hanem a PHP API másik felülete.
  Olvasáshoz a `QueryEngine`-t, íráshoz az `ObjectService`-t hívja, így a
  jogosultság és a validáció ugyanúgy érvényes.
- **Időpontok:** ISO 8601, UTC-ben: `2026-09-25T12:54:00Z`.
- **Mezők:** az objektum mezői név szerint, a `fields` kulcs alatt.

### Objektum-ábrázolás

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
  }
}
```

### Végpontok

| Metódus és útvonal | PHP API | Állapot |
|---|---|---|
| `GET /api/v1/objects` | `QueryEngine::execute()` | 📝 |
| `GET /api/v1/objects/{uuid}` | `ObjectRepository::findByUuid()` + láthatóság | 📝 |
| `POST /api/v1/objects` | `ObjectService::create()` | 📝 |
| `PATCH /api/v1/objects/{uuid}` | `ObjectService::update()` | 📝 |
| `DELETE /api/v1/objects/{uuid}` | `ObjectService::delete()` | 📝 |
| `POST /api/v1/objects/{uuid}/publish` | `ObjectService::publish()` | 📝 |
| `POST /api/v1/objects/{uuid}/unpublish` | `ObjectService::unpublish()` | 📝 |
| `GET /api/v1/queries/{név}` | Elnevezett Query (`config/queries.php`) | 📝 |
| `GET /api/v1/blueprints` | `BlueprintRegistry::all()` | 📝 |
| `GET /api/v1/capabilities` | `CapabilityRegistry::all()` | 📝 |

#### Lista és szűrés

```
GET /api/v1/objects?blueprint=article&having=routable,publishable
                   &filter[status]=published&sort=-published_at
                   &page=2&per_page=10
```

| Paraméter | Query-megfelelő |
|---|---|
| `blueprint=a,b` | `blueprint('a', 'b')` |
| `having=x,y` | `having('x', 'y')` |
| `filter[mező]=érték` | `where('mező', '=', 'érték')` |
| `filter[mező][op]=érték` | `where('mező', op, 'érték')`, ahol `op`: `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in`, `like`, `null` |
| `scope=published` | `scope('published')` |
| `sort=mező`, `sort=-mező` | `orderBy('mező', 'ASC')`, illetve `'DESC'`; vesszővel több is megadható |
| `page`, `per_page` | `page()`; a `per_page` legfeljebb 100 |

A PHP API szabályai itt is érvényesek: csak saját táblás (`Table`) mezőre lehet
szűrni és rendezni.

```json
{
  "data": [ { "uuid": "…", "blueprint": "article", "fields": { } } ],
  "meta": { "total": 42, "page": 2, "per_page": 10, "pages": 5 }
}
```

#### Létrehozás

```http
POST /api/v1/objects
Content-Type: application/json

{ "blueprint": "article", "fields": { "title": "Új cikk", "body": "…" }, "publish": false }
```

Válasz: `201 Created`, az új objektummal és `Location` fejléccel.

#### Módosítás

`PATCH` esetén csak a megadott mezők változnak:

```json
{ "fields": { "title": "Javított cím" } }
```

### Hibák

Minden hiba azonos szerkezetű:

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

| Státusz | `code` | PHP-kivétel |
|---|---|---|
| `400` | `bad_query` | `QueryException` (pl. JSON-mezőre szűrés) |
| `400` | `unknown_field` | `OutOfBoundsException` |
| `401` | `unauthenticated` | Hiányzó vagy érvénytelen token |
| `403` | `forbidden` | `AccessDeniedException` módosító műveletnél |
| `404` | `not_found` | Nincs ilyen objektum, **vagy nem látható**: olvasásnál a tiltás is 404, hogy ne derüljön ki, létezik-e |
| `422` | `validation_failed` | `ValidationException`; a `fields` a `$errors` tartalma |
| `500` | `internal_error` | Minden más |

### Hitelesítés

A bejelentkezés megvalósításáig a JSON API csak olvasható, anonymous
látogatóként. Utána:

- `Authorization: Bearer <token>` fejléc;
- a token egy `Actor`-t azonosít (`ActorKind::User` vagy `ActorKind::Service`)
  a szerepköreivel;
- a jogosultságot ugyanaz az `AccessPolicy` dönti el, mint a HTML-felületen.

### Nyitott kérdések

- CORS-beállítások (más domainről futó alkalmazásokhoz)
- Kérésszám-korlátozás
- Kapcsolódó objektumok beágyazása (`?include=author`), a Relationship után
- Többnyelvű mezők ábrázolása
