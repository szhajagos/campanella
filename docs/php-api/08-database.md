# 8. Database

Target: the common subset of MariaDB 10.6+ and MySQL 8.0+ (InnoDB, utf8mb4). The
code uses no vendor-specific SQL statements.

## Connection

`Campanella\Database\Connection` · **Public** · `final class` · container: `Connection::class`

A thin layer over PDO. On connect it sets the session to a uniform state:
`time_zone = '+00:00'` and a strict `sql_mode`, regardless of the server's
defaults. The connection is established on the first query.

### Creation

| Method | Description |
|---|---|
| `__construct(string $dsn, string $user, string $password, string $prefix = 'cc_')` | |
| `static fromConfig(array $config): self` | From the `database` settings: `host`, `port`, `name`, `user`, `password`, `prefix`, or `socket` |

### Table names

In SQL, tables are written as `{name}`; the `Connection` replaces them with the
prefixed, quoted name: `{objects}` → `` `cc_objects` ``.

| Method | Description |
|---|---|
| `prefix(): string` | The table prefix |
| `table(string $name): string` | `'objects'` → `` '`cc_objects`' `` |
| `static quoteIdentifier(string $name): string` | Only `[A-Za-z0-9_]` is allowed; anything else throws `InvalidArgumentException` |
| `expand(string $sql): string` | Replaces the `{name}` placeholders |

### Querying

Parameters are passed by name (`:id` in the SQL, `['id' => 5]` in the array).
The type is derived from the value (`int`, `bool`, `null`, otherwise string).

| Method | Returns |
|---|---|
| `run(string $sql, array $params = []): PDOStatement` | |
| `fetchAll(string $sql, array $params = []): list<array<string, mixed>>` | All rows |
| `fetchOne(string $sql, array $params = []): ?array` | The first row |
| `fetchColumn(string $sql, array $params = []): list<mixed>` | The first column |
| `fetchValue(string $sql, array $params = []): mixed` | The first value of the first row, or `null` |
| `execute(string $sql, array $params = []): int` | Number of affected rows |

```php
$db->fetchValue('SELECT COUNT(*) FROM {objects} WHERE blueprint = :b', ['b' => 'article']);
```

### Writing

| Method | Description |
|---|---|
| `insert(string $table, array $row): int` | The ID of the new row |
| `update(string $table, array $row, array $where): int` | The `$where` equality conditions are combined with AND; an empty `$where` is not allowed |
| `delete(string $table, array $where): int` | Same as above |
| `transactional(callable $work): mixed` | Runs in a transaction; on error it rolls back and rethrows the exception. Can be nested: only the outermost call opens and commits |

`insert`, `update` and `delete` expect the table name without the prefix (`'objects'`).

### Other

| Method | Description |
|---|---|
| `pdo(): PDO` | The PDO connection (created if needed) |
| `tableExists(string $table): bool` | Whether the table exists. Throws on a connection error instead of returning `false` |
| `serverVersion(): string` | E.g. `10.11.14-MariaDB` |

## Schema

`Campanella\Database\Schema\*` · **Public** · immutable descriptors

The schema does not live in SQL files but in PHP objects: the core tables are
defined in `CoreSchema`, the capability tables in the
`CapabilityDefinition::table()` method.

| Class | Description |
|---|---|
| `Table(string $name, list<Column> $columns, list<string> $primaryKey, array $indexes = [], array $uniques = [], list<ForeignKey> $foreignKeys = [])` | Indexes and unique indexes: `name => columns` |
| `Column(string $name, ColumnType $type, bool $nullable = false, string\|int\|null $default = null, bool $autoIncrement = false, int $length = 255)` | |
| `ForeignKey(string $column, string $referencedTable, string $referencedColumn = 'id', bool $cascadeDelete = true)` | |
| `ColumnType` (enum) | `Id`, `Integer`, `Boolean`, `String`, `Text`, `DateTime`, `Uuid`, `Json`; `sql(int $length): string` returns the SQL type |

### SchemaBuilder

`Campanella\Database\Schema\SchemaBuilder` · **Public** · `final class`

Generates the DDL; the only place where `CREATE TABLE` and `ALTER TABLE`
statements are written. The `ALTER` statements are the plain forms both
MariaDB and MySQL accept (`ADD COLUMN IF NOT EXISTS` and `DROP INDEX IF EXISTS`
exist only on MariaDB): check the state first with the `SchemaReader`.

| Method | Description |
|---|---|
| `__construct(Connection $db)` | |
| `createSql(Table $table): string` | `CREATE TABLE IF NOT EXISTS …` |
| `create(Table $table): void` | Runs it |
| `columnSql(Column $column): string` | A column's definition: `` `weight` INT NOT NULL DEFAULT 0 `` |
| `addColumnSql(Table $table, string $column): string` | `ALTER TABLE … ADD COLUMN …`, after the column it follows in the definition (or `FIRST`), so the order matches a fresh installation (since 0.0.6) |
| `addIndexSql(Table $table, string $index): string` | `ALTER TABLE … ADD [UNIQUE] INDEX …`, an index of the definition (since 0.0.6) |
| `dropColumnSql(string $table, string $column): string` | `ALTER TABLE … DROP COLUMN …`; its data is lost (since 0.0.6) |
| `dropIndexSql(string $table, string $index): string` | `ALTER TABLE … DROP INDEX …` (since 0.0.6) |
| `foreignKeyName(Table $table, ForeignKey $foreignKey): string` | The constraint's name: `cc_fk_<table>_<column>` |

### Reading the database: SchemaReader

`Campanella\Database\Schema\SchemaReader` · **Public** · `final class` (since 0.0.6)

Reads the actual tables from `information_schema`. Only the tables with the
connection's prefix are seen, and their names are returned without it. The
differences of the two servers are evened out: `int(11)` (MariaDB) and `int`
(MySQL 8) are both `Integer`; a default is `active` whether the server reports
`'active'` (MariaDB) or `active` (MySQL); JSON is `longtext` on MariaDB.

| Method | Description |
|---|---|
| `__construct(Connection $db)` | |
| `tableNames(): list<string>` | The tables with the prefix, without it, sorted |
| `read(string $table): ?TableInfo` | The table's columns, primary key, indexes and foreign keys; null if it does not exist |
| `tableExists(string $table): bool`, `columnExists(string $table, string $column): bool`, `indexExists(string $table, string $index): bool` | |
| `static normalizeDefault(mixed $default): ?string` | A default as either server reports it, as text without quotes; null for none |

`TableInfo` (`name`, `columns` by name in the table's order, `primaryKey`,
`indexes`, `uniques`, `foreignKeys` by constraint name: `column`, `table`
without the prefix, `referencedColumn`, `cascadeDelete`; `column(string $name): ?ColumnInfo`,
`hasIndex(string $name): bool`) and `ColumnInfo` (`name`, `type`: the
`ColumnType`, or null for a type Campanella does not create, `rawType` as the
server reports it, `nullable`, `default`, `autoIncrement`, `length` for strings)
are `final readonly` value classes.

### Comparing: SchemaComparator

`Campanella\Database\Schema\SchemaComparator` · **Public** · `final class` (since 0.0.6)

Compares definitions with the database and lists the differences; it changes
nothing.

| Method | Description |
|---|---|
| `__construct(SchemaReader $reader, SchemaBuilder $builder)` | |
| `compare(list<Table> $definitions): list<SchemaDifference>` | Every definition against its table, plus the tables with the prefix that no definition has |
| `compareTable(Table $table, TableInfo $actual): list<SchemaDifference>` | One table |

Not compared: the column order, the engine, the collation, and an index the
server created for a foreign key by itself.

`SchemaDifference` · `final readonly class`: `kind` (`DifferenceKind`),
`table` (without the prefix), `name` (the column or index), `expected`,
`actual`, `sql` (the statement that applies it, if one can be generated),
`additive`; `message(): Message` (`schema.<kind>` in the language files).

| `DifferenceKind` | Additive | `sql` |
|---|---|---|
| `MissingTable` | yes | `CREATE TABLE` |
| `MissingColumn` | if it may be NULL or has a default: the existing rows get that | `ADD COLUMN` (also when not additive, for a migration to use) |
| `MissingIndex` | yes (a unique index fails if the data has duplicates) | `ADD INDEX` |
| `ExtraColumn`, `ExtraIndex` | no: data or an index would be lost | `DROP …` |
| `ExtraTable` | only reported (e.g. a capability no longer registered; its data is kept) | – |
| `ColumnType`, `ColumnNullable`, `ColumnDefault`, `PrimaryKey`, `IndexColumns`, `MissingForeignKey` | no: a migration decides | – |

"Additive" means it can be applied without losing or guessing data. A new
`NOT NULL` column without a default is not: the existing rows would get an
arbitrary value (the decision of 0.0.6: such a field needs a migration that
fills it).

### CoreSchema

`Campanella\Database\Schema\CoreSchema` · **Public**

`static tables(): list<Table>`, plus constants for the table names: `OBJECTS`,
`OBJECT_CAPABILITIES`, `SYSTEM`, `RELATIONSHIPS`, `THROTTLE`, `FIELD_VALUES`.

| Table | Columns | Purpose |
|---|---|---|
| `cc_system` | `name` (PK), `value` | System values: `schema_version`, `installed_at` |
| `cc_objects` | `id`, `uuid` (unique), `blueprint`, `data` (JSON), `created_at`, `updated_at` | The object's identity and JSON data |
| `cc_object_capabilities` | `object_id`, `capability` (together the PK) | Which object has which capabilities |
| `cc_relationships` | `id`, `source_id`, `type`, `target_id`, `weight`; unique: (`source_id`, `type`, `target_id`) | Relations; cascade on deletion of either side (since 0.0.2) |
| `cc_throttle` | `key_hash` (PK), `hits`, `reset_at` | Login throttling (since 0.0.3) |
| `cc_field_values` | `id`, `object_id`, `field`, `delta` (order), `value_string`, `value_text`, `value_int`, `value_datetime`; unique key on (`object_id`, `field`, `delta`); covering indexes (`field`, value, `object_id`) for `value_string`, `value_int` and `value_datetime` (`value_text` is not indexed) | The values of the multi-valued queryable fields, one row per value; only the column matching the field's type is filled; cascade on deletion of the object (since 0.0.4, schema version 4) |
| `cc_cap_<name>` | `object_id` (PK) + the capability's single-valued `Table` fields | One table per capability (a capability with only multi-valued or `Data` fields has none) |

On MariaDB the `JSON` type is an alias of `LONGTEXT` with a built-in
`JSON_VALID` check; on MySQL it is native JSON. Campanella only stores JSON and
never queries into it, so the difference does not matter.

## Installer

`Campanella\Database\Installer` · **Public** · container: `Installer::class`

| Method | Description |
|---|---|
| `tables(): list<Table>` | The tables of the core and of all registered capabilities |
| `install(): list<string>` | Creates the missing tables and writes the `schema_version` value. Can be run repeatedly |
| `sql(): string` | The complete DDL, e.g. for phpMyAdmin |
| `differences(): list<SchemaDifference>` | The definitions against the database (empty if they match); every table with the prefix is checked (since 0.0.6) |
| `isInstalled(): bool` | |
| `needsUpgrade(): bool` | Installed, but the `schema_version` is older than the code: `install` needs to be run |
| `systemValue(string $name): ?string` | A single `cc_system` value |

`install()` does not alter existing tables (new column, type change); that will
be the job of migrations. `differences()` shows what differs, and
`php bin/campanella schema:check` prints it.
