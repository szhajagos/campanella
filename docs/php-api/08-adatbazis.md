# 8. Adatbázis

Cél: MariaDB 10.6+ és MySQL 8.0+ közös részhalmaza (InnoDB, utf8mb4). Gyártóspecifikus
SQL-utasítást a kód nem használ.

## Connection

`Campanella\Database\Connection` · **Nyilvános** · `final class` · konténer: `Connection::class`

Vékony réteg a PDO fölött. Kapcsolódáskor a munkamenetet egységesre állítja:
`time_zone = '+00:00'` és szigorú `sql_mode`, a szerver alapbeállításaitól
függetlenül. A kapcsolat az első lekérdezéskor jön létre.

### Létrehozás

| Metódus | Leírás |
|---|---|
| `__construct(string $dsn, string $user, string $password, string $prefix = 'cc_')` | |
| `static fromConfig(array $config): self` | A `database` beállításokból: `host`, `port`, `name`, `user`, `password`, `prefix`, vagy `socket` |

### Táblanevek

Az SQL-ben a táblák `{név}` alakban szerepelnek; a `Connection` cseréli le őket
a prefixszel ellátott, idézőjelezett névre: `{objects}` → `` `cc_objects` ``.

| Metódus | Leírás |
|---|---|
| `prefix(): string` | A táblaprefix |
| `table(string $name): string` | `'objects'` → `` '`cc_objects`' `` |
| `static quoteIdentifier(string $name): string` | Csak `[A-Za-z0-9_]` engedett; másra `InvalidArgumentException` |
| `expand(string $sql): string` | A `{név}` helyőrzők cseréje |

### Lekérdezés

A paraméterek névvel adhatók meg (`:id` az SQL-ben, `['id' => 5]` a tömbben).
A típus az értékből következik (`int`, `bool`, `null`, egyébként szöveg).

| Metódus | Visszatérés |
|---|---|
| `run(string $sql, array $params = []): PDOStatement` | |
| `fetchAll(string $sql, array $params = []): list<array<string, mixed>>` | Minden sor |
| `fetchOne(string $sql, array $params = []): ?array` | Az első sor |
| `fetchColumn(string $sql, array $params = []): list<mixed>` | Az első oszlop |
| `fetchValue(string $sql, array $params = []): mixed` | Az első sor első értéke vagy `null` |
| `execute(string $sql, array $params = []): int` | Érintett sorok száma |

```php
$db->fetchValue('SELECT COUNT(*) FROM {objects} WHERE blueprint = :b', ['b' => 'article']);
```

### Írás

| Metódus | Leírás |
|---|---|
| `insert(string $table, array $row): int` | Az új sor azonosítója |
| `update(string $table, array $row, array $where): int` | A `$where` egyenlőségi feltételek ÉS kapcsolattal; üres `$where` nem engedett |
| `delete(string $table, array $where): int` | Ugyanígy |
| `transactional(callable $work): mixed` | Tranzakcióban futtat; hiba esetén visszagördít és továbbdobja a kivételt. Egymásba ágyazható: csak a legkülső hívás nyit és zár |

Az `insert`, `update` és `delete` a táblanevet prefix nélkül várja (`'objects'`).

### Egyéb

| Metódus | Leírás |
|---|---|
| `pdo(): PDO` | A PDO-kapcsolat (szükség esetén létrehozza) |
| `tableExists(string $table): bool` | Létezik-e a tábla. Kapcsolódási hibánál kivételt dob, nem `false`-t ad |
| `serverVersion(): string` | Pl. `10.11.14-MariaDB` |

## Séma

`Campanella\Database\Schema\*` · **Nyilvános** · megváltoztathatatlan leírók

A séma nem SQL-fájlokban él, hanem PHP-objektumokban: a mag táblái a
`CoreSchema`-ban, a capability-táblák a `CapabilityDefinition::table()`
metódusban keletkeznek.

| Osztály | Leírás |
|---|---|
| `Table(string $name, list<Column> $columns, list<string> $primaryKey, array $indexes = [], array $uniques = [], list<ForeignKey> $foreignKeys = [])` | Indexek és egyedi indexek: `név => oszlopok` |
| `Column(string $name, ColumnType $type, bool $nullable = false, string\|int\|null $default = null, bool $autoIncrement = false, int $length = 255)` | |
| `ForeignKey(string $column, string $referencedTable, string $referencedColumn = 'id', bool $cascadeDelete = true)` | |
| `ColumnType` (enum) | `Id`, `Integer`, `Boolean`, `String`, `Text`, `DateTime`, `Uuid`, `Json`; `sql(int $length): string` adja az SQL-típust |

### SchemaBuilder

`Campanella\Database\Schema\SchemaBuilder` · **Belső**

`createSql(Table $table): string` a `CREATE TABLE IF NOT EXISTS …` utasítást
adja, `create(Table $table): void` le is futtatja.

### CoreSchema

`Campanella\Database\Schema\CoreSchema` · **Nyilvános**

`static tables(): list<Table>`, valamint a táblanevek konstansai: `OBJECTS`,
`OBJECT_CAPABILITIES`, `SYSTEM`.

| Tábla | Oszlopok | Szerepe |
|---|---|---|
| `cc_system` | `name` (PK), `value` | Rendszerértékek: `schema_version`, `installed_at` |
| `cc_objects` | `id`, `uuid` (egyedi), `blueprint`, `data` (JSON), `created_at`, `updated_at` | Az objektum identitása és JSON-adatai |
| `cc_object_capabilities` | `object_id`, `capability` (együtt PK) | Melyik objektum milyen capability-kkel rendelkezik |
| `cc_cap_<név>` | `object_id` (PK) + a capability `Table` mezői | Capability-nként egy tábla |

MariaDB-n a `JSON` típus a `LONGTEXT` álneve, beépített `JSON_VALID`
ellenőrzéssel; MySQL-en natív JSON. A Campanella a JSON-t csak tárolja, nem
kérdez le belőle, így a különbség nem számít.

## Installer

`Campanella\Database\Installer` · **Nyilvános** · konténer: `Installer::class`

| Metódus | Leírás |
|---|---|
| `tables(): list<Table>` | A mag és az összes regisztrált capability táblái |
| `install(): list<string>` | Létrehozza a hiányzó táblákat, beírja a `schema_version` értéket. Ismételten futtatható |
| `sql(): string` | A teljes DDL, pl. phpMyAdminhoz |
| `isInstalled(): bool` | |
| `systemValue(string $name): ?string` | Egy `cc_system` érték |

Meglévő tábla módosítását (új oszlop, típusváltás) az `install()` nem végzi el;
ez a migrációk feladata lesz.
