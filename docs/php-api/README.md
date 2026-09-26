# PHP API

A Campanella 0.0.1 PHP API-jának referenciája. Minden osztály a `Campanella\`
névtérben van, a `src/` mappában (PSR-4).

## Fejezetek

1. [Áttekintés](01-attekintes.md): rétegek, egy kérés útja, alapelvek
2. [Objektum, mező, Blueprint](02-objektum.md): `CampanellaObject`, `Field`, `FieldType`, `FieldStorage`, `Blueprint`
3. [Capability](03-capability.md): a szerződés, a négy beépített capability, új capability írása
4. [Query](04-query.md): lekérdezések, feltételek, scope-ok, `QueryEngine`, `ResultSet`
5. [Jogosultság](05-jogosultsag.md): `Actor`, `Operation`, `AccessPolicy`
6. [Szolgáltatások és tárolás](06-szolgaltatasok.md): `ObjectService`, `ObjectRepository`, validáció
7. [HTTP és megjelenítés](07-http-es-view.md): `Request`, `Router`, controllerek, `Presentation`, Twig
8. [Adatbázis](08-adatbazis.md): `Connection`, séma, telepítő
9. [Rendszer](09-rendszer.md): `Kernel`, `Container`, konfiguráció, CLI, segédosztályok

## Névterek

| Névtér | Réteg | Tartalom |
|---|---|---|
| `Campanella\Model` | Model | Objektum, mezők, Blueprint, Repository |
| `Campanella\Capability` | Model | A Capability-szerződés és a beépített capability-k |
| `Campanella\Query` | Model | Query, feltételek, fordító, végrehajtó |
| `Campanella\Access` | Model | Szereplők, műveletek, szabályok |
| `Campanella\Service` | Service | Üzleti műveletek jogosultság-ellenőrzéssel |
| `Campanella\Controller` | Controller | HTTP-kérések kiszolgálása |
| `Campanella\View` | View | Megjelenítés, Twig-integráció |
| `Campanella\Http` | Infrastruktúra | Kérés, válasz, útválasztás |
| `Campanella\Database` | Infrastruktúra | PDO-réteg, séma, telepítő |
| `Campanella\Core` | Infrastruktúra | Kernel, konténer, konfiguráció |
| `Campanella\Cli` | Infrastruktúra | Parancssori eszköz |
| `Campanella\Support` | Segéd | Slug, UUID |

## Gyors példa

```php
use Campanella\Access\Actor;
use Campanella\Capability\Publishable;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;

$c = $kernel->container();
$service = $c->get(ObjectService::class);
$queries = $c->get(QueryEngine::class);

// Létrehozás és publikálás
$article = $service->create(Actor::system(), 'article', [
    'title' => 'Neumann János',
    'lead'  => 'A számítógép-architektúra egyik atyja.',
    'body'  => "Budapesten született 1903-ban.\n\nNevéhez fűződik…",
], publish: true);

$article->get('path');                                   // '/neumann-janos'
$article->as(Publishable::class)->isPublished();         // true

// Lekérdezés: amit az adott Actor láthat
$news = $queries->execute(
    Query::objects()->blueprint('article')->scope('published')->orderBy('published_at', 'DESC')->limit(10),
    Actor::anonymous(),
);
foreach ($news as $item) {
    echo $item->get('title'), PHP_EOL;
}
```
