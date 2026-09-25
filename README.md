# Campanella 0.0.1

Capability-vezérelt CMS. Nincsenek előre rögzített tartalomtípusok: az objektum
viselkedését a rá szerelt képességek (capability-k) határozzák meg.

## Követelmények

- PHP 8.3 vagy újabb (`pdo_mysql`, `mbstring`, `json`)
- MariaDB 10.6+ vagy MySQL 8.0+ (InnoDB, utf8mb4)
- Composer (a függőségek letöltéséhez; a tárhelyre a `vendor/` mappával együtt is feltölthető)

Külső függőség csak a Twig. A PHPStan kizárólag fejlesztéshez kell.

## Telepítés

```bash
composer install
cp config/local.php.dist config/local.php   # add meg az adatbázis adatait
php bin/campanella install                  # táblák létrehozása
php bin/campanella seed                     # példatartalom (opcionális)
php -S localhost:8000 -t public public/index.php
```

Ha a tárhelyen nincs parancssor, a `php bin/campanella install --sql` kimenetét
phpMyAdminban is le lehet futtatni.

Apache alatt a webgyökér legyen a `public/` mappa. Ha ezt a tárhely nem engedi,
a gyökérben lévő `.htaccess` minden kérést a `public/` alá irányít. Alkönyvtárba
telepítve (pl. `example.hu/campanella/`) is működik.

## Parancsok

| Parancs | Leírás |
|---|---|
| `php bin/campanella install` | Táblák létrehozása (ismételten futtatható) |
| `php bin/campanella install --sql` | Csak kiírja a DDL-t |
| `php bin/campanella seed` | Példatartalom |
| `php bin/campanella status` | Capability-k, Blueprintek, objektumszám |
| `composer test` | Tesztek (külön `test_` táblaprefixszel, valódi adatbázison) |
| `composer analyse` | PHPStan, level 8, PHP 8.3-ra |

## Felépítés (MVC + Service réteg)

```
HTTP kérés → Router → Controller → Service / QueryEngine (Model)
           → Presentation + Twig (View) → HTTP válasz
```

```
src/
  Core/         Kernel, Container, Config, Version
  Http/         Request, Response, Router
  Controller/   ObjectController (egy objektum), QueryController (lista)
  Service/      ObjectService: létrehozás, módosítás, publikálás + jogosultság
  Model/        CampanellaObject, Field, Blueprint, ObjectRepository
  Capability/   A Capability-szerződés és a négy alap capability
  Query/        Query, Condition-ek, QueryCompiler (SQL), QueryEngine, ResultSet
  Access/       Actor, Operation, AccessPolicy, DefaultPolicy
  Database/     Connection (PDO), Schema, Installer
  View/         Presentation, Twig-kiterjesztés
  Cli/          bin/campanella parancsai
config/         app.php, blueprints.php, routes.php, queries.php, local.php
templates/      Twig-sablonok
public/         index.php (egyetlen belépési pont), assets/
```

## Alapfogalmak a kódban

**Object.** Egyetlen általános osztály (`CampanellaObject`), típusonkénti
alosztályok nélkül. Data Mapper minta: az objektum nem ment magáról, ezt az
`ObjectRepository` végzi.

**Capability.** Egy osztály `#[AsCapability]` attribútummal. Megadja a mezőit
és a függőségeit, opcionálisan elnevezett lekérdezési szűrőket (scope) és
mentés előtti logikát. Az objektumon adapterként működik:

```php
$object->as(Publishable::class)->publish();
$object->as(Routable::class)->route();     // '/neumann-janos'
```

| Capability | Mezők | Tárolás | Függ |
|---|---|---|---|
| Titled | title | `cap_titled` | – |
| Textual | body, format | data (JSON) | – |
| Routable | path (egyedi) | `cap_routable` | Titled |
| Publishable | status, published_at | `cap_publishable` | – |

**Blueprint.** Elnevezett capability-csomag, konfigurációban
(`config/blueprints.php`). Egyedi mezőket is adhat, ezek a data oszlopba
kerülnek.

**Tárolási szabály.** A JSON (`objects.data`) csak tárolásra szolgál. Ami szerint
szűrünk vagy rendezünk, az a capability saját táblájába kerül. Data mezőre
szűrni a QueryCompiler nem is enged.

**Query.** Deklaratív és megváltoztathatatlan:

```php
Query::objects()
    ->having('textual', 'routable', 'publishable')
    ->scope('published')
    ->orderBy('published_at', 'DESC')
    ->limit(10);
```

**Access-aware lekérdezés.** A `QueryEngine::execute()` kötelezően megkapja az
Actort, és a Policy feltételeit még az SQL előtt a lekérdezéshez fűzi. Egy
piszkozat anonymous látogatónak nem „kiszűrve”, hanem egyáltalán nem jön le
az adatbázisból.

**Időzített publikálás.** Egy objektum akkor látható, ha `published` és a
`published_at` már elmúlt, így a jövőbeli dátummal publikált tartalom
magától jelenik meg.

## Ami szándékosan kimaradt a 0.0.1-ből

Bejelentkezés és admin felület, Relationship, Hierarchical, Component / Region /
Layout, Webform, Event / Action, cache, migrációk (meglévő tábla módosítása),
többnyelvűség, WYSIWYG szerkesztő és HTML-szűrő.
