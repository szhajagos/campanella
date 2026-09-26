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
php -S localhost:8000 -t public public/index.php   # csak helyi kipróbáláshoz
```

Az utolsó sor a PHP beépített fejlesztői szerverét indítja a saját gépeden, a
<http://localhost:8000> címen. Éles tárhelyen nem ezt kell használni, és a
tárhely beállításaira sem következtethetsz belőle (lásd lent).

Ha a tárhelyen nincs parancssor, a `php bin/campanella install --sql` kimenetét
phpMyAdminban is le lehet futtatni.

### Hová kerüljenek a fájlok?

A projekt mappái mindig együtt maradnak. A webszerver felé csak a `public/`
mappa látszik, de az `index.php` onnan egy szinttel feljebb keresi a `vendor/`,
`src/` és `config/` mappát. Ezért **nem elég csak a `public/` tartalmát a
webgyökérbe másolni.** Két helyes felállás van:

1. **A webgyökér a `public/` mappa.** Ez csak akkor működik, ha a PHP a
   webgyökéren kívüli mappákat is látja: saját szerveren az Apache
   `DocumentRoot` beállításával, vagy a mellékelt `Dockerfile` használatával.
   **Figyelem:** sok Dockeres tárhely csak a webgyökérként kijelölt mappát teszi
   be a konténerbe (`/var/www/html` néven). Ilyenkor a `public/` megadása
   esetén a `vendor/`, `src/` és `config/` mappák a PHP számára láthatatlanok,
   és a rendszer a „Hiányzik a vendor mappa” hibaoldalt mutatja.
2. **Az egész projekt a webgyökérbe kerül.** Ez a megoldás, ha a webgyökér nem
   állítható, vagy ha a tárhely csak a webgyökér mappáját látja.
   Ilyenkor a gyökérben lévő `.htaccess` minden kérést a `public/` alá irányít,
   a többi mappa pedig kívülről nem érhető el. Ehhez az Apache `mod_rewrite`
   moduljának be kell kapcsolva lennie, és engedélyezni kell a `.htaccess`
   használatát (`AllowOverride All`).

   Telepítés után ellenőrizd, hogy a `…/composer.json` és a `…/config/app.php`
   címen a Campanella „Az oldal nem található” oldala jelenik-e meg. Ha a
   `composer.json` tartalma látszik, a `.htaccess` nem működik, és a projekt
   fájljai kívülről olvashatók.

Alkönyvtárba telepítve (pl. `example.hu/campanella/`) is működik.

### Dockerrel

```bash
docker compose up -d --build
docker compose exec web composer install          # ha a vendor/ még hiányzik
docker compose exec web php bin/campanella install
docker compose exec web php bin/campanella seed
```

Ezután a rendszer a <http://localhost:8080> címen érhető el. A `Dockerfile` a
hivatalos `php:8.3-apache` image-re épül: telepíti a `pdo_mysql` bővítményt,
bekapcsolja a `mod_rewrite` modult, és a webgyökeret a `public/` mappára állítja.
Az adatbázis-beállításokat a `compose.yaml` környezeti változói adják meg
(`CAMPANELLA_DB_HOST`, `CAMPANELLA_DB_NAME` stb.). Dockerben ne legyen
`config/local.php`, mert az felülírná ezeket.

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
