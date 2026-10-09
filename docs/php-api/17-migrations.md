# 17. Migrations and backups

An existing database is changed by **migrations** (since 0.0.6): small PHP
steps that add a column, an index, or move and transform data. They run once,
in order, and are recorded in the `cc_migrations` table.

Decisions (ROADMAP, 2026-10-05):

- **Forward only.** There is no `down()`: undoing a migration that moved data
  is rarely reliable. A backup is made before migrating instead
  ([`db:backup`](#backups-databasebackup), built in, no `mysqldump` needed).
- **Additive changes from the definitions are automatic**
  ([below](#applying-the-definitions-schemasync)); renaming, changing a type,
  moving or deleting data only happen through an explicit migration, never by
  guessing.

## Running them

```
php bin/campanella migrate --dry-run   # lists what would be done
php bin/campanella migrate             # applies the definitions; asks, backs up, runs the migrations
php bin/campanella migrate --yes       # without asking (scripts, deployment)
php bin/campanella migrate --prune     # also deletes the data of capabilities removed from Blueprints
```

`install` does the same on an existing installation (`--yes`, `--no-backup`,
`--prune`); the usual upgrade is still `php bin/campanella install`. The order:
the missing tables, the additive changes of the definitions, the pending
migrations, then the additive changes again (a migration may have made one
possible, e.g. by filling a column).

Asking and the backup are for what cannot be undone: migrations and
`--prune`. Additive changes alone lose nothing, so they are applied without
either.

- Without `--yes` the command asks; when it is not run from a terminal (a
  script, a cron job), nothing can be asked, so it stops and says to add
  `--yes`.
- A backup is made first, into `var/backups/`. If it cannot be made, nothing
  runs (`--no-backup` runs them without one).
- Each migration is recorded right after it ran. If one fails, the run stops
  there: the earlier ones stay recorded, the failed one and the rest stay
  pending and run next time (after the cause is fixed). MySQL cannot roll back
  `ALTER TABLE`, which is why a migration should be one small step that can
  run again.
- Only one run at a time: a database lock (`GET_LOCK`, per database and table
  prefix); a second run stops at once with `migration.locked`.

**Fresh installation:** the tables are created as the definitions are now,
so every known migration is recorded as applied without running.
**Existing installation:** only the ones not recorded yet run.

While a migration is pending (or the schema version is older than the code),
`Installer::needsUpgrade()` is true, and:

- every page answers **503** (`Retry-After: 300`), the admin's with a pointer to
  the upgrade page; only logging in and out and the upgrade page work, because
  the code may not match the database yet;
- `status` says to run `migrate`; the System page (once it opens again) shows
  *Migrations applied* in the *Versions* group.

## Applying the definitions: SchemaSync

What changes in the definitions is applied to the existing database without
writing a migration (the ROADMAP's "done when" of 0.0.6):

| The definitions | What happens |
|---|---|
| A new capability (a new table) | The table is created |
| A new field of a capability, may be NULL or has a database default | The column is added (in the definition's order) |
| A new **required** field with a default | The column is added; the existing rows get the field's default (added as NULL, filled, then made NOT NULL: the same on MariaDB and MySQL, `TEXT` columns too) |
| A new required field **without** a default, and the table has rows | **Not guessed:** reported (Blocked); a migration has to fill it |
| A new index | Added (a unique one fails if the data has duplicates) |
| A capability added to a Blueprint | Its existing objects get it, with the defaults: the Blueprint's `defaults`, else the field's. Multi-valued fields start empty, `Data` fields use their default when read |
| … with a required field without a default, or a unique field (one default for many objects) | **Blocked** |
| A capability removed from a Blueprint | Its objects keep it and its data (a note on the System page and in `migrate`); with `--prune` the data is deleted (its table rows, multi-valued values, keys in the JSON data) and they lose it |
| Objects of a Blueprint that is not defined any more | A note; kept as they are |
| A renamed field, a changed type, a removed field | Nothing: a migration (the schema comparison lists them) |

A Blocked step fails the `migrate` run (exit code `1`) after the rest is
done; the upgrade page and the System page show it.

After uploading code with a new table or column, a page whose query hits it
answers 503 (needs upgrade) instead of 500, until the upgrade has run.

`Campanella\Database\Sync\SchemaSync` · **Public** · `final class` · container: `SchemaSync::class`

| Method | Description |
|---|---|
| `__construct(Connection $db, Installer $installer, CapabilityRegistry $capabilities, BlueprintRegistry $blueprints)` | |
| `plan(bool $prune = false): SyncPlan` | What would be done; changes nothing |
| `apply(bool $prune = false): SyncPlan` | Plans and does the steps in order; returns them |

`SyncPlan` · `final readonly class`: `steps` (`list<SyncStep>`), `hasWork(): bool`
(anything that changes the database), `work()`, `blocked()`, `notes()`,
`hasPrune(): bool`.

`SyncStep` · `final readonly class`: `kind` (`SyncStepKind`), `message` (a
`Message`: `sync.*` in the language files), `isWork(): bool`; `run()` is called
by `apply()`.

`SyncStepKind` (enum): `CreateTable`, `AddColumn`, `AddIndex`,
`AddCapability`, `PruneCapability`, `Blocked`, `Note`.

`Campanella\Database\Sync\SyncCheck` · **Internal**: `static checks(Installer $installer, SchemaSync $sync): Closure`,
the System page's lines about the objects' capabilities (in the *Database
tables* group: capabilities to add, blocked steps, notes).

## From the browser

For web hosts without a command line: **`/admin/upgrade`** (under the admin's
path) does the same as `migrate`: creates the missing tables, makes a backup,
applies the definitions, runs the pending migrations, and shows each step and
its log lines. It is offered whenever there is something to do: an older
schema, a pending migration, or a change of the definitions.

Who may run it:

- a logged-in user of the System page's roles (`admin.system_roles`, by
  default `administrator`), without anything else;
- or anyone who enters the **upgrade key**: for when logging in does not work
  until the upgrade has run (e.g. a migration changes the users' tables). It is
  off by default. To use it, put it into `config/local.php`, at least 20
  characters (`UpgradeController::MIN_KEY_LENGTH`; the page suggests a random
  one), and remove it after the upgrade (the System page warns while it is set):

  ```php
  'upgrade' => ['key' => '…a long random string…'],
  ```

  Wrong keys are throttled: 5 per IP address in 15 minutes.

Others see only that an upgrade is needed and how to log in; not what is
pending. The form needs the CSRF token, like every form.

The backup is made into `var/backups/`, which the **web server** must be able
to write (the System page checks it). If the command line runs as another user
(e.g. `root` in Docker) and created the folder first, give it to the web
server's user: `chown -R www-data:www-data var/backups` (Campanella's Docker
image creates it for `www-data`). If the backup fails, nothing is changed; the
"Without a backup" box runs the upgrade anyway. A failure is shown with its
cause and the backup's name; the site keeps waiting until a successful run.

The page stands alone (not the admin's layout), with the admin's
Content-Security-Policy. PHP's time limit is lifted for the run, and it goes on
even if the browser is closed.

`Campanella\Controller\UpgradeController` · **Internal** · `final class`:
`static usableKey(mixed $key): ?string` (the key if long enough), constants
`MIN_KEY_LENGTH` (20), `MAX_KEY_ATTEMPTS` (5), `KEY_DECAY_SECONDS` (900),
`CONTENT_SECURITY_POLICY`.

## Installing from the browser

`Campanella\Controller\InstallController` · handler: `install` · route: `/install` (`InstallController::PATH`; since 0.1.0)

For web hosts without a command line, the first installation is done in the
browser too: the page checks the requirements (PHP, the required extensions,
the database connection, the writable folders `var/cache`, `var/backups` and
the media folder), then creates the tables (`Installer::install()`) and the
first administrator (name, e-mail address, password twice), optionally with
the sample content (`seed`), and logs them in. The address the page was opened
at becomes the site's address (since 0.1.1; changeable under Site settings,
[chapter 20](20-site.md)).

- **Only with the install key.** Anyone who finds a freshly uploaded site
  could otherwise install it as their own (and become its administrator). The
  key is set in `config/local.php`, at least 20 characters
  (`InstallController::MIN_KEY_LENGTH`; the page suggests a random one):

  ```php
  'install' => ['key' => '…a long random string…'],
  ```

  Wrong keys are limited: 5 per address and 50 in total in 15 minutes
  (`FileThrottle`, in `var/cache/install-throttle.json`: the database cannot
  be used yet).
- **Only while there is no user.** The page is open while the site is not
  installed, or installed without a user (e.g. the tables were made in
  phpMyAdmin from `install --sql`); after that it answers 404, and the System
  page warns until the key is removed.
- The administrator's data is checked before anything is installed; two
  submissions at once are kept apart by a database lock. A database that
  cannot be reached is shown by its error code only (the message could name
  the database user or host).
- Stand-alone page (`admin/install.html.twig`), with the admin's
  Content-Security-Policy, `noindex` and `no-store`.

## Writing a migration

```php
namespace App\Migration;

use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\MigrationContext;
use Campanella\Database\Schema\Column;
use Campanella\Database\Schema\ColumnType;

final class AddSubtitle implements Migration
{
    public function id(): string
    {
        return 'site:2026_10_06_add_subtitle';
    }

    public function description(): string
    {
        return 'Adds a subtitle to the titled capability';
    }

    public function up(MigrationContext $m): void
    {
        $m->addColumn('cap_titled', new Column('subtitle', ColumnType::String, nullable: true, length: 255), after: 'title');
        $moved = $m->eachRow('cap_titled', 'object_id', function (array $row) use ($m): void {
            // … transform $row, write it back with $m->sql(…)
        });
        $m->log("{$moved} rows checked");
    }
}
```

Registered in `config/app.php` (or `config/local.php`), after Campanella's own:

```php
'migrations' => [App\Migration\AddSubtitle::class],
```

Rules:

- **SQL only**, through the context: never the model classes
  (`ObjectRepository`, capabilities). A migration may run a year later, when
  the model looks different.
- **Repeatable:** the helpers check the state first (`addColumn()` on an
  existing column does nothing, returns `false`), so a migration that failed
  halfway can run again from the start.
- **Never changed once released.** A fix is a new migration. The ID is
  recorded; it must never change.
- IDs: `<source>:<name>` (`MigrationRegistry::ID_PATTERN`: lowercase letters,
  digits, `_`, `-`, `.`; at most 128 characters), e.g. `core:0006_…` for
  Campanella's own, `site:…` for a site's.

## Migration

`Campanella\Database\Migration\Migration` · **Public** · interface

| Method | Description |
|---|---|
| `id(): string` | `<source>:<name>`; recorded, never changes |
| `description(): string` | A short English description, shown before running and recorded |
| `up(MigrationContext $m): void` | The change. An exception stops the run (the migration stays pending) |

## MigrationContext

`Campanella\Database\Migration\MigrationContext` · **Public** · `final class`

Table names without the prefix (`cap_titled`); in SQL `{name}`, as with the
[Connection](08-database.md#table-names).

| Method | Description |
|---|---|
| `__construct(Connection $db, ?Closure $log = null)` | The Migrator creates it |
| `db(): Connection` | |
| `sql(string $sql, array $params = []): int` | Runs a statement; the number of affected rows |
| `log(string $message): void` | A line for the person running it (shown by `migrate`) |
| `tableExists(string $table): bool`, `columnExists(string $table, string $column): bool`, `indexExists(string $table, string $index): bool` | |
| `createTable(Table $table): void` | If it does not exist |
| `addColumn(string $table, Column $column, ?string $after = null): bool` | If missing (`$after`: the column it follows; null: at the end); `false` if it existed |
| `dropColumn(string $table, string $column): bool` | If it exists; its data is lost |
| `renameColumn(string $table, string $from, string $to): bool` | Keeps the type and the data; `false` if already renamed |
| `addIndex(string $table, string $index, array $columns, bool $unique = false): bool`, `dropIndex(string $table, string $index): bool` | |
| `eachRow(string $table, string $key, Closure $process, string $where = '', array $params = [], int $batchSize = 500): int` | Every row (matching `$where`), in batches ordered by `$key` (a unique, increasing column), so memory does not grow with the table; the number of rows |

## MigrationRegistry and CoreMigrations

`Campanella\Database\Migration\MigrationRegistry` · **Public** · `final class` · container: `MigrationRegistry::class`

The known migrations, in the order they run: `CoreMigrations::classes()`
first, then the `migrations` setting.

| Member | Description |
|---|---|
| `__construct(iterable $migrations = [])` | |
| `static fromClasses(array $classes): self` | From class names; `LogicException` for anything that is not a `Migration` class |
| `add(Migration $migration): void` | `InvalidArgumentException` for an invalid or duplicate ID |
| `all(): list<Migration>`, `isEmpty(): bool` | |
| `ID_PATTERN` | See above |

`Campanella\Database\Migration\CoreMigrations` · **Internal**:
`static classes(): list<class-string<Migration>>`, Campanella's own, in order
(only ever appended).

## Migrator

`Campanella\Database\Migration\Migrator` · **Public** · `final class` · container: `Migrator::class`

| Method | Description |
|---|---|
| `__construct(Connection $db, MigrationRegistry $registry)` | |
| `registry(): MigrationRegistry` | |
| `applied(): array<string, string>` | The recorded IDs and when they ran (UTC); empty before the table exists |
| `pending(): list<Migration>` | The registered ones not recorded, in order |
| `run(?Closure $starting = null, ?Closure $log = null): list<MigrationResult>` | Runs the pending ones under the lock. `$starting(Migration)` before each, `$log(string)` for their lines. `MigrationException` if locked or one fails |
| `markAllApplied(): void` | Records every registered migration without running it (a fresh installation) |

`MigrationResult` · `final readonly class`: `id`, `description`, `durationMs`.

`MigrationException` · `RuntimeException`: `reason` (a `Message`:
`migration.locked`, `migration.failed`), `migrationId` (the failed one),
`applied` (the `MigrationResult`s before the failure); the cause is
`getPrevious()`.

## Campanella's migrations

| ID | Version | What it does |
|---|---|---|
| `core:0006_roles_multi_value` | 0.0.6 | The users' `roles` become a multi-valued field: the JSON lists of the `roles` column of `cap_authenticatable` are copied into `field_values` (one row per role), then the column is dropped. Users can then be queried by role (`user:list --role=editor`) |
| `core:0008_media_usage` | 0.1.2 | Fills `media_usage` (which text shows which image) from the HTML texts saved before; repeatable. `Campanella\Database\Migration\Core\MediaUsageIndex` ([chapter 16](16-media.md#where-an-image-is-used)) |

`Campanella\Database\Migration\Core\RolesMultiValue` · **Internal** · `Migration`:
constants `TABLE`, `COLUMN`; `static decode(mixed $stored): list<string>` reads
a stored list (JSON, or comma-separated), trimmed, without empty and repeated
roles.

**Before the roles migration has run**, the code reads no roles (they are
still in the old column), so a logged-in administrator is not one yet. Until
0.0.7 the upgrade page read the old column too; since 0.1.0 it does not: such
an installation is upgraded from the command line, or with the upgrade key.

## The oldest version that can be upgraded

Since 0.1.0, an installation older than 0.0.6 (schema version below
`Version::MIN_UPGRADE_SCHEMA`) is not upgraded: `install`, `migrate` and the
upgrade page stop with an explanation (`UnsupportedUpgradeException`,
`upgrade.too_old`), before changing anything. Such a site is upgraded to
0.0.7 first, then to the new version. This way the code that read the data of
old versions can be removed.

## Backups: DatabaseBackup

`Campanella\Database\DatabaseBackup` · **Public** · `final class` · container: `DatabaseBackup::class`

```
php bin/campanella db:backup           # var/backups/campanella-20261006-185816-2b69ce.sql.gz
php bin/campanella db:backup --plain   # .sql, not compressed
```

Campanella's tables (those with the prefix) as an SQL file, written through
PDO, so it works on any web host. It restores them as they were (`DROP TABLE`,
`CREATE TABLE`, `INSERT`): import it into the database, e.g. phpMyAdmin >
Import, or `gunzip < file.sql.gz | mysql <database>`. The last line is
`-- Campanella backup complete` (`COMPLETE`): a file without it was cut off.

The file contains everything, password hashes too. It is written into
`var/backups/` (outside the web root, with a `.htaccess` that denies access
in case the project root is served), readable by its owner only (`0600`,
where the file system supports it; it does on Linux servers, and in Docker on
Windows too).
Keep a copy off the server too; old files are not deleted automatically.

| Member | Description |
|---|---|
| `__construct(Connection $db, string $directory)` | The Kernel gives `var/backups` |
| `create(bool $compress = true, ?Closure $progress = null): string` | Writes a new file (`.sql.gz` if the zlib extension is available) and returns its path; `$progress(string $table, int $rows)`. Written to a `.part` file first, renamed when complete. `RuntimeException` if it cannot write |
| `write(Closure $write, ?Closure $progress = null): void` | The SQL through a function (create() writes it into the file) |
| `files(): list<array{name, path, size, time}>` | The backup files, newest first |
| `directory(): string` | |
| `BATCH` | 500: rows per `INSERT` and per query |
