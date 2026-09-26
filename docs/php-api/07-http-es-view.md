# 7. HTTP és megjelenítés

## Request

`Campanella\Http\Request` · **Nyilvános** · `final readonly class`

| Tag | Leírás |
|---|---|
| `$method` | Nagybetűs HTTP-metódus |
| `$path` | Az útvonal a telepítés gyökeréhez képest, normalizálva: `/`-rel kezdődik, nem végződik `/`-re |
| `$query`, `$post` | `$_GET`, `$_POST` |
| `$basePath` | URL-előtag alkönyvtáras telepítésnél, pl. `/campanella`; egyébként `''` |
| `$headers` | Kisbetűs fejlécnevek szerint, pl. `'accept-language'` |
| `static fromGlobals(): self` | A PHP szuperglobálisaiból |
| `static normalizePath(string $path): string` | `'/hirek/'` → `'/hirek'`; `'/index.php'` → `'/'` |
| `queryInt(string $name, int $default = 0): int` | Egész szám a query stringből |

Ha a gyökérben lévő `.htaccess` irányítja a kérést a `public/` alá, a
`basePath` ezt elrejti, így az URL-ekben nem jelenik meg a `/public`.

## Response

`Campanella\Http\Response` · **Nyilvános** · `final class`

| Tag | Leírás |
|---|---|
| `__construct(string $body = '', int $status = 200, array $headers = [...])` | Alapértelmezett fejléc: `Content-Type: text/html; charset=utf-8` |
| `$body`, `$status`, `$headers` | Csak olvasható |
| `static html(string $body, int $status = 200): self` | |
| `static redirect(string $url, int $status = 302): self` | |
| `send(): void` | Elküldi; minden válaszhoz `X-Content-Type-Options: nosniff` fejlécet is ad |

## HttpException

`Campanella\Http\HttpException` · **Nyilvános** · `RuntimeException`

`__construct(int $status, string $message = '')`, `$status`, és
`static notFound(string $message = 'Az oldal nem található.'): self`. A
controllerből dobva a `Kernel` hibaoldalt jelenít meg a megadott státusszal.

## Router és RouteMatch

`Campanella\Http\Router` · **Nyilvános** · `final class` · konténer: `Router::class`

| Metódus | Leírás |
|---|---|
| `__construct(array $routes = [])` | A `config/routes.php` tömbje: `útvonal => [handler, paraméterek]` |
| `add(string $path, string $handler, array $params = []): void` | |
| `match(Request $request): RouteMatch` | Ha van statikus útvonal, az; egyébként `RouteMatch('object', ['path' => …])` |

`Campanella\Http\RouteMatch` · `final readonly class`: `$handler` (a controller
neve) és `$params`.

A Router nem kérdez adatbázist. Hogy egy útvonal mögött van-e objektum, azt az
`ObjectController` dönti el, jogosultsággal együtt.

```php
// config/routes.php
return [
    '/'      => ['query', ['query' => 'frontpage', 'title' => '']],
    '/hirek' => ['query', ['query' => 'news', 'title' => 'Hírek', 'per_page' => 5]],
];
```

## Controllerek

`Campanella\Controller\Controller` · **Nyilvános** · `interface`

```php
public function handle(Request $request, RouteMatch $route, Actor $actor): Response;
```

A controller vékony: fogadja a kérést, meghívja a Model vagy Service réteget,
és az eredményt átadja a View-nak. A `Kernel` a `$route->handler` alapján a
`controller.<handler>` konténerbejegyzést hívja.

### ObjectController

`Campanella\Controller\ObjectController` · handler: `object`

Egy Routable objektum saját oldala. Az útvonalat normalizálja
(`Routable::normalize`), a `QueryEngine`-nel keresi meg (így a jogosultság is
érvényesül), és a `page/object.html.twig` sablonnal, `full` módban jeleníti meg.
Ha nincs ilyen objektum, vagy az `Actor` nem láthatja: 404.

### QueryController

`Campanella\Controller\QueryController` · handler: `query`

Egy elnevezett Query eredménye lapozható listaként. A Query-definíciók a
`config/queries.php` fájlban vannak (`név => Closure(): Query`).

| Útvonal-paraméter | Leírás |
|---|---|
| `query` | A Query neve (kötelező) |
| `title` | Az oldal címe; üres esetén a nyitóoldali bevezető jelenik meg |
| `per_page` | Elemszám oldalanként; alapból a Query limitje, ennek hiányában 10 |
| `item_mode` | Az elemek megjelenítési módja, alapból `teaser` |

Az oldalszám a `?page=` paraméterből jön. Nem létező oldalnál (a 2. oldaltól,
ha üres): 404.

## Presentation

`Campanella\View\Presentation` · **Nyilvános** · `final class` · konténer: `Presentation::class`

Megmondja, *hogyan* jelenjen meg valami, és kiválasztja hozzá a sablont. Nem
kérdez adatbázist, csak azt jeleníti meg, amit kap.

| Metódus | Leírás |
|---|---|
| `renderObject(CampanellaObject $object, string $mode = self::TEASER, array $context = []): string` | Egy objektum egy módban |
| `renderList(ResultSet $result, string $name, string $itemMode = self::TEASER, array $context = []): string` | Egy lista |
| `render(string $template, array $context = []): string` | Tetszőleges sablon |

Módok: `Presentation::FULL` (`'full'`) és `Presentation::TEASER` (`'teaser'`).
Új mód egyszerűen egy új sablonnal adható hozzá, pl. `object/card.html.twig`.

### Sablonkeresés

A legspecifikusabbtól az általánosig:

| Mit | 1. próbálkozás | 2. próbálkozás |
|---|---|---|
| Objektum | `object/<blueprint>--<mód>.html.twig` | `object/<mód>.html.twig` |
| Lista | `query/<query-név>.html.twig` | `query/list.html.twig` |

A sablonok változói:

| Sablon | Változók |
|---|---|
| objektum | `object`, `mode` + a `$context` |
| lista | `result` (ResultSet), `name`, `item_mode`, `path` + a `$context` |
| minden sablon | `site` (a `config/app.php` `site` kulcsa), `campanella_version` |

## Twig-kiterjesztés

`Campanella\View\CampanellaTwigExtension` · **Nyilvános** (a sablonfüggvények) · a kiterjesztés osztálya **Belső**

| Sablonban | PHP-metódus | Leírás |
|---|---|---|
| `{{ url('/hirek') }}` | `url(string $path)` | Alkönyvtár-biztos URL |
| `{{ asset('campanella.css') }}` | | A `public/assets/` alatti fájl URL-je |
| `{{ render_object(item, 'teaser') }}` | `renderObject(CampanellaObject $object, string $mode)` | Egy objektum egy módban |
| `{{ object\|body }}` | `body(CampanellaObject $object)` | A Textual törzs HTML-je: `plain` formátumnál escape-elve, bekezdésekre bontva; `html` formátumnál változtatás nélkül |

A kiterjesztés további metódusai (`getFunctions()`, `getFilters()`,
`getGlobals()`) a Twig számára készültek.

A dátumok a `date` szűrővel a `timezone` beállítás szerint, alapból
`Y. m. d. H:i` formában jelennek meg. A sablonok automatikusan escape-elnek
(HTML), debug módban a nem létező változó hibát ad.
