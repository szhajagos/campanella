# Változásnapló

Minden jelentős változás ide kerül. A formátum a
[Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi, a
verziózás a [szemantikus verziózást](https://semver.org/lang/hu/).

Szakaszok: **Új**, **Megváltozott**, **Elavult**, **Megszűnt**, **Javítva**,
**Biztonság**. A 0.x verziókban a **Megváltozott** és **Megszűnt** tételek
visszafelé nem kompatibilisek lehetnek.

## [Kiadatlan]

### Új

- Fejlesztői dokumentáció a `docs/` mappában: PHP API referencia 9 fejezetben,
  HTTP API (a HTML-felület és a tervezett JSON API).
- `composer docs:check`: jelzi a dokumentálatlan nyilvános osztályokat és
  metódusokat.
- `Dockerfile` és `compose.yaml` (PHP 8.3 + Apache + MariaDB).
- Az adatbázis-beállítások környezeti változókkal is megadhatók
  (`CAMPANELLA_DB_*`, `CAMPANELLA_DEBUG`); a projekt gyökere a
  `CAMPANELLA_ROOT` változóval.
- Érthető hibaoldal, ha hiányzik a `vendor/` mappa.

### Megváltozott

- `CampanellaTwigExtension::__construct()` második paramétere `string` helyett
  `Closure(): string` (az aktuális kérés URL-előtagja). **Belső** osztály.

### Javítva

- A `Kernel::handle()` már nem építi újra a konténert minden kérésnél, így a
  konténerben felülírt szolgáltatások (pl. saját `AccessPolicy`) megmaradnak.
- A `status` parancs kapcsolódási hibánál a valódi hibát jelzi, nem azt, hogy
  „nincs telepítve” (`Connection::tableExists()` kapcsolódási hibát dob).
- Ha a Twig gyorsítótár mappája nem írható, a rendszer gyorsítótár nélkül fut
  tovább.
- A beépített fejlesztői szerver (`php -S`) kiszolgálja a statikus fájlokat.

## [0.0.1]

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
