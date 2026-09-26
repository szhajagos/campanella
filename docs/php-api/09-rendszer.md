# 9. Rendszer

## Kernel

`Campanella\Core\Kernel` · **Nyilvános** · `final class`

A rendszer összerakása és egy kérés kiszolgálása.

| Metódus | Leírás |
|---|---|
| `__construct(string $rootDir)` | A projekt gyökere |
| `rootDir(): string` | |
| `container(): Container` | A szolgáltatás-konténer (első híváskor épül fel) |
| `handle(Request $request): Response` | Útválasztás, controller, hibakezelés |

Hibakezelés a `handle()`-ben:

| Kivétel | Válasz |
|---|---|
| `HttpException` | Hibaoldal a kivétel státuszával |
| Adatbázis-hiba, és a rendszer még nincs telepítve | 503, telepítési útmutatóval |
| Bármi más | 500; debug módban a kivétel üzenetével. A részletek a PHP hibanaplóba kerülnek |

Saját belépési pont (pl. teszthez):

```php
$kernel = new Kernel('/út/a/projekthez');
$response = $kernel->handle(new Request('GET', '/hirek'));
```

## Container

`Campanella\Core\Container` · **Nyilvános** · `final class`

Minimális szolgáltatás-konténer, „mágikus” autowiring nélkül: a szolgáltatásokat
a `Kernel` explicit gyártófüggvényei hozzák létre, első kéréskor, egyszer.

| Metódus | Leírás |
|---|---|
| `set(string $id, Closure $factory): void` | Gyártófüggvény: `fn (Container $c) => …`. Felülírja a korábbit |
| `instance(string $id, mixed $service): void` | Kész példány |
| `get(string $id): mixed` | `OutOfBoundsException`, ha ismeretlen |
| `has(string $id): bool` | |

### Szolgáltatásazonosítók

| Azonosító | Szolgáltatás |
|---|---|
| `Config::class` | Konfiguráció |
| `Connection::class` | Adatbázis-kapcsolat |
| `CapabilityRegistry::class`, `BlueprintRegistry::class` | Nyilvántartások |
| `ObjectRepository::class` | Tárolás |
| `AccessPolicy::class` | Jogosultsági szabály (alapból `DefaultPolicy`) |
| `QueryEngine::class` | Lekérdezések |
| `ObjectService::class` | Műveletek |
| `Installer::class` | Telepítő |
| `Twig\Environment::class`, `Presentation::class` | Megjelenítés |
| `Router::class` | Útválasztás |
| `controller.object`, `controller.query` | Controllerek; a `controller.<handler>` minta szerint |

Szolgáltatás cseréje, például saját jogosultsági szabály:

```php
$kernel->container()->set(AccessPolicy::class, static fn (): AccessPolicy => new EditorPolicy());
```

Új controller: `controller.<név>` bejegyzés a konténerben, és egy útvonal a
`config/routes.php`-ben, amelynek handlere `<név>`.

## Config

`Campanella\Core\Config` · **Nyilvános** · `final class` · konténer: `Config::class`

| Metódus | Leírás |
|---|---|
| `__construct(array $values)` | |
| `static load(string $configDir): self` | A `config/app.php`, és ha létezik, a `config/local.php` (ez írja felül) |
| `get(string $key, mixed $default = null): mixed` | Pontozott kulcs: `get('database.host')` |
| `all(): array` | |

### Konfigurációs fájlok

| Fájl | Tartalom |
|---|---|
| `config/app.php` | Alapbeállítások, capability-lista |
| `config/local.php` | Gépfüggő beállítások; nincs verziókezelve. Minta: `local.php.dist` |
| `config/blueprints.php` | Blueprintek |
| `config/routes.php` | Statikus útvonalak |
| `config/queries.php` | Elnevezett lekérdezések |

### Kulcsok (`config/app.php`)

| Kulcs | Alapérték | Környezeti változó |
|---|---|---|
| `debug` | `false` | `CAMPANELLA_DEBUG` |
| `timezone` | `'Europe/Budapest'` | |
| `site.name`, `site.slogan`, `site.language` | `'Campanella'`, …, `'hu'` | |
| `database.host` | `'localhost'` | `CAMPANELLA_DB_HOST` |
| `database.port` | `3306` | `CAMPANELLA_DB_PORT` |
| `database.name` | `'campanella'` | `CAMPANELLA_DB_NAME` |
| `database.user` | `'campanella'` | `CAMPANELLA_DB_USER` |
| `database.password` | `''` | `CAMPANELLA_DB_PASSWORD` |
| `database.prefix` | `'cc_'` | `CAMPANELLA_DB_PREFIX` |
| `capabilities` | a négy beépített | |

Sorrend: az `app.php` alapértéke < környezeti változó < `local.php`.

A `public/index.php` a `CAMPANELLA_ROOT` környezeti változót is figyeli: ha
meg van adva, az a projekt gyökere (alapból a `public/` szülőmappája).

## Version

`Campanella\Core\Version` · **Nyilvános**

`CAMPANELLA = '0.0.1'` (a rendszer verziója) és `SCHEMA = '1'` (az
adatbázisséma verziója; migrációnál nő).

## CLI

`php bin/campanella <parancs>`

| Parancs | Leírás |
|---|---|
| `install` | Táblák létrehozása; `--sql`: csak kiírja az SQL-t |
| `seed` | Példatartalom (csak üres adatbázisba) |
| `status` | Verzió, capability-k, Blueprintek, objektumszám |
| `help` | Parancslista |

### Saját parancs

`Campanella\Cli\Command` · **Nyilvános** · `interface`

```php
final class HelloCommand implements Command
{
    public function name(): string { return 'hello'; }

    public function description(): string { return 'Köszön'; }

    /** @param list<string> $args */
    public function run(Container $container, array $args, Output $output): int
    {
        $output->success('Szia, ' . ($args[0] ?? 'világ') . '!');

        return 0;   // kilépési kód
    }
}
```

A parancsok listája a 0.0.1-ben a `Console` konstruktorában van; a
modulrendszerrel ez regisztrálhatóvá válik.

| Osztály | Leírás |
|---|---|
| `Console` · **Belső** | `__construct(Kernel $kernel, Output $output = new Output())`, `run(array $argv): int` |
| `Output` · **Nyilvános** | `line(string $text = '')`, `success(string $text)` (✔ jellel), `error(string $text)` (✘ jellel, a hibakimenetre) |
| `InstallCommand`, `SeedCommand`, `StatusCommand` · **Belső** | A beépített parancsok |

## Segédosztályok

`Campanella\Support` · **Nyilvános**

| Metódus | Leírás |
|---|---|
| `Slugger::slugify(string $text): string` | URL-barát szöveg saját átírótáblával: `'Árvíztűrő tükörfúrógép'` → `'arvizturo-tukorfurogep'`. Üres eredmény helyett `'n-a'` |
| `Uuid::v7(): string` | Időrendben növekvő UUID (RFC 9562) |
| `Uuid::isValid(string $uuid): bool` | Formátum-ellenőrzés |
