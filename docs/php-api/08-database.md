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

`Campanella\Database\Schema\SchemaBuilder` · **Internal**

`createSql(Table $table): string` returns the `CREATE TABLE IF NOT EXISTS …`
statement; `create(Table $table): void` also executes it.

### CoreSchema

`Campanella\Database\Schema\CoreSchema` · **Public**

`static tables(): list<Table>`, plus constants for the table names: `OBJECTS`,
`OBJECT_CAPABILITIES`, `SYSTEM`, `RELATIONSHIPS`, `THROTTLE`.

| Table | Columns | Purpose |
|---|---|---|
| `cc_system` | `name` (PK), `value` | System values: `schema_version`, `installed_at` |
| `cc_objects` | `id`, `uuid` (unique), `blueprint`, `data` (JSON), `created_at`, `updated_at` | The object's identity and JSON data |
| `cc_object_capabilities` | `object_id`, `capability` (together the PK) | Which object has which capabilities |
| `cc_relationships` | `id`, `source_id`, `type`, `target_id`, `weight`; unique: (`source_id`, `type`, `target_id`) | Relations; cascade on deletion of either side (since 0.0.2) |
| `cc_throttle` | `key_hash` (PK), `hits`, `reset_at` | Login throttling (since 0.0.3) |
| `cc_cap_<name>` | `object_id` (PK) + the fields of the capability's `Table` | One table per capability |

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
| `isInstalled(): bool` | |
| `needsUpgrade(): bool` | Installed, but the `schema_version` is older than the code: `install` needs to be run |
| `systemValue(string $name): ?string` | A single `cc_system` value |

`install()` does not alter existing tables (new column, type change); that will
be the job of migrations.
