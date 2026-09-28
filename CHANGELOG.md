# Változásnapló

Minden jelentős változás ide kerül. A formátum a
[Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi, a
verziózás a [szemantikus verziózást](https://semver.org/lang/hu/).

Szakaszok: **Új**, **Megváltozott**, **Elavult**, **Megszűnt**, **Javítva**,
**Biztonság**. A 0.x verziókban a **Megváltozott** és **Megszűnt** tételek
visszafelé nem kompatibilisek lehetnek.

## [Kiadatlan]

## [0.0.2] – 2026-09-28

Kapcsolatok (Relationship) objektumok között.

### Új

- `Relation` és `Cardinality` (`Campanella\Relation`): elnevezett, irányított
  kapcsolatok definíciója számossággal, célfeltételekkel (Blueprint,
  capability) és kötelezőséggel.
- Kapcsolatot a capability `relations()` metódusa vagy a Blueprint
  `relations` kulcsa adhat meg.
- `CampanellaObject`: `relations()`, `hasRelation()`, `relatedIds()`,
  `setRelated()`, `relate()`, `unrelate()`, `relatedObjects()`, `isResolved()`.
- `cc_relationships` tábla; mentéskor ellenőrzés (létező, megfelelő cél,
  kötelező kapcsolat, önhivatkozás tiltása), törléskor kaszkád.
- `Query::whereRelated()`, `Query::whereNotRelated()` és a `RelatedTo` feltétel.
- `RelationLoader`: a kapcsolódó objektumok betöltése egyetlen,
  jogosultság-tudatos lekérdezéssel; a controllerek automatikusan használják.
- Blueprint `lists`: listák az objektum saját oldalán (pl. egy kategória cikkei).
- Twig: `related(object, 'név')` függvény és `object/_relations.html.twig` részlet.
- Példa: `category` Blueprint, a cikkek `categories` kapcsolata, `/kategoriak`
  oldal, kategóriaoldalak a cikkeikkel.
- `Installer::needsUpgrade()`; a `status` parancs és a weboldal (503) jelzi,
  ha az adatbázis sémája régebbi a kódnál.
- A `seed` ismételten futtatható: a meglévő tartalmat nem hozza létre újra,
  a hiányzó példákat (pl. kategóriák) hozzáadja.
- Dokumentáció: [10. Kapcsolatok](docs/php-api/10-kapcsolatok.md) fejezet.

### Megváltozott

- Sémaverzió: `2` (új tábla). Frissítés után `php bin/campanella install`.
- `QueryCompiler::__construct()` második, opcionális paramétere a
  `BlueprintRegistry` (kapcsolat-feltételekhez). **Belső** osztály.
- `ObjectController::__construct()` új paraméterei: `BlueprintRegistry`,
  `RelationLoader`; `QueryController::__construct()` opcionális
  `RelationLoader` paramétert kapott.
- `CampanellaObject::__construct()` és a `Blueprint` új, opcionális
  paramétereket kapott (kapcsolatok, listák).

### Korábbi, kiadatlan változások (0.0.1 után)

- Fejlesztői dokumentáció a `docs/` mappában: PHP API referencia 9 fejezetben,
  HTTP API (a HTML-felület és a tervezett JSON API).
- `composer docs:check`: jelzi a dokumentálatlan nyilvános osztályokat és
  metódusokat.
- `Dockerfile` és `compose.yaml` (PHP 8.3 + Apache + MariaDB).
- Az adatbázis-beállítások környezeti változókkal is megadhatók
  (`CAMPANELLA_DB_*`, `CAMPANELLA_DEBUG`); a projekt gyökere a
  `CAMPANELLA_ROOT` változóval.
- Érthető hibaoldal, ha hiányzik a `vendor/` mappa.

#### Megváltozott

- `CampanellaTwigExtension::__construct()` második paramétere `string` helyett
  `Closure(): string` (az aktuális kérés URL-előtagja). **Belső** osztály.

#### Javítva

- A `Kernel::handle()` már nem építi újra a konténert minden kérésnél, így a
  konténerben felülírt szolgáltatások (pl. saját `AccessPolicy`) megmaradnak.
- A `status` parancs kapcsolódási hibánál a valódi hibát jelzi, nem azt, hogy
  „nincs telepítve” (`Connection::tableExists()` kapcsolódási hibát dob).
- Ha a Twig gyorsítótár mappája nem írható, a rendszer gyorsítótár nélkül fut
  tovább.
- A beépített fejlesztői szerver (`php -S`) kiszolgálja a statikus fájlokat.

## [0.0.1] – 2026-09-25

Az első, minimális mag.

### Új

- Általános objektummodell: `CampanellaObject`, `Field`, `FieldType`,
  `FieldStorage`.
- Capability-szerződés (`Capability`, `#[AsCapability]`,
  `CapabilityRegistry`) és négy beépített capability: `Titled`, `Textual`,
  `Routable`, `Publishable` (időzített publikálással).
- Blueprintek konfigurációból (`config/blueprints.php`).
- `ObjectRepository` (Data Mapper, kötegelt betöltés) és `ObjectService`.
- Deklaratív, megváltoztathatatlan `Query`, feltétel-AST, scope-ok,
  `QueryCompiler`, jogosultság-tudatos `QueryEngine`, `ResultSet`.
- Jogosultság: `Actor`, `Operation`, `AccessPolicy`, `DefaultPolicy`.
- HTTP: `Request`, `Response`, `Router`, `ObjectController`, `QueryController`.
- Megjelenítés: `Presentation`, Twig-sablonok, `CampanellaTwigExtension`.
- Adatbázis: `Connection`, sémaleírók, `Installer` (MariaDB 10.6+ / MySQL 8.0+).
- CLI: `install`, `seed`, `status`.
