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
the backup (see below), the missing tables and the new schema version, the
additive changes of the definitions, the pending migrations, then the additive
changes again (a migration may have made one possible, e.g. by filling a
column).

Asking is for what cannot be undone: migrations and `--prune`. Additive
changes alone lose nothing, so they are applied without asking, and without a
backup, unless the schema version changes. An upgrade to a new schema version
is always backed up first (since 0.2.0), before anything is written, so the
backup holds the old version's database and going back to it works (see
[Upgrading: going back](../upgrading.md#going-back)).

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
| A Blueprint's own field made **queryable** (its `queryable` key; since 0.2.2) | Its table or column is created; the values move from the objects' JSON data into it (or `field_values`), and leave the JSON. The JSON value wins over an older one in the table. A required field without a value and without a default for an object that has no row yet: **Blocked** |
| … made **not queryable** again | The values in its column (or `field_values`) are copied back into the JSON data of the objects that do not have it there; the column and its values stay (nothing is deleted) |
| A queryable own field with **one value ↔ several** (since 0.2.4) | Its values move between the Blueprint table's column and `field_values`: one value becomes the only one of several; of several, the first stays. The source is emptied (the rows deleted, the column made NULL-able and emptied). In the JSON data a list becomes its first item when read |
| A queryable own field's **type widened** (since 0.2.4) | The column changes: whole number → decimal, text → long text, date → date and time, a longer (or, if every value fits, shorter) text. Several values move into the new type's column of `field_values`. Any other change, or a long text with an index: **Blocked** |
| A queryable own field made **required** (since 0.2.4) | The column becomes NOT NULL; the empty values get the field's default first. Without a default, while there are empty values: **Blocked** |
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
`hasPrune(): bool`, `hasMove(): bool` (a step moves data; since 0.2.2),
`changesData(): bool` (a step moves, converts, fills or deletes data; since 0.2.4).

Changing data (`changesData()`) counts as risky like pruning: `migrate` asks
and makes a backup first, and the upgrade page backs up as always.

`SyncStep` · `final readonly class`: `kind` (`SyncStepKind`), `message` (a
`Message`: `sync.*` in the language files), `isWork(): bool`; `run()` is called
by `apply()`.

`SyncStepKind` (enum): `CreateTable`, `AddColumn`, `AddIndex`,
`ChangeColumn` (since 0.2.4), `AddCapability`, `PruneCapability`, `MoveData`
(since 0.2.2), `Blocked`, `Note`.

`Campanella\Database\Sync\SyncCheck` · **Internal**: `static checks(Installer $installer, SchemaSync $sync): Closure`,
the System page's lines about the objects' capabilities (in the *Database
tables* group: capabilities to add, data to move, blocked steps, notes).

## From the browser

For web hosts without a command line: **`/admin/upgrade`** (under the admin's
path) does the same as `migrate`: makes a backup (before anything changes,
since 0.2.0), creates the missing tables, applies the definitions, runs the pending migrations, and shows each step and
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

## The upgrade test

*Since 0.2.0.* Upgrades are supported from 0.1.0 on, and a test plays one out
for real (`tests/upgrade/`). CI runs it on every push:

| From | Database | Why |
|---|---|---|
| 0.1.0 | MariaDB 10.6, MySQL 8.0 | the oldest supported version |
| 0.1.5 | MariaDB 11.4 | the newest 0.1.x |
| 0.2.0 | MySQL 8.4 | the oldest 0.2.x (since 0.3.0) |
| 0.2.9 | MariaDB 10.6, MySQL 8.0 | the newest 0.2.x, with a content model made in the admin (since 0.3.0) |
| 0.3.0 | MariaDB 11.4 | the start of the 0.3.x promise (since 0.3.1) |
| 0.3.1 | MySQL 8.4 | (since 0.3.2) |
| 0.3.2 | MariaDB 10.6 | (since 0.3.3) |
| 0.3.3 | MySQL 8.0 | (since 0.3.4) |
| 0.3.4 | MariaDB 11.4 | (since 0.3.5) |
| 0.3.5 | MySQL 8.4 | the previous version (since 0.3.6) |

From 0.2.6 on the old site's content model also has Blueprints called `file`
(since 0.3.2, up to 0.3.1) and `snippet` (since 0.3.3, up to 0.3.2), made in
the admin before the version that brought them: they must keep their names and
objects on the upgrade, and files must say they are off. On the others a file
is uploaded and downloaded (for everyone, and for logged-in users only). On
every upgraded site two components are made and shown (since 0.3.3), one only
to logged-in users. Since 0.3.4 every upgraded site's
header and footer must be components (the layouts on), and a row may be added
but never lost.

```bash
git worktree add /tmp/campanella-0.1.0 v0.1.0
bash tests/upgrade/run.sh /tmp/campanella-0.1.0       # --offline: no composer install; --keep: keep the site
```

1. **The old version** is installed into a temporary folder from its source
   (its own dependencies, `composer install`), with its sample content
   (`seed`) and what a real site has besides: an administrator, an editor, a
   blocked user, an uploaded image shown in an article (`old-fixture.php`,
   which uses only what 0.1.0 had). From 0.2.6 on (when the old version can
   import a content model) also a content model as an administrator makes it:
   a Blueprint with a field of each kind (a date, a choice, a decimal, a
   multi-valued text, formatted text) and a relation, with its admin columns
   from 0.2.8; a list at its own path, with a filter from 0.2.9; two events,
   and from 0.2.7 a changed path. Then a snapshot of every row of every
   table (but `system`, `migrations` and `throttle`), and of every media file.
2. **The upgrade:** the current files are copied over the old ones, as one
   uploads a new version (`config/local.php`, `public/media/` and `var/`
   stay), the new dependencies are installed, and
   `php bin/campanella install --yes` runs (with its backup).
3. **The checks** (`verify.php`, with the new code): the schema version, no
   pending change, a backup made that holds the old schema version (none when the schema stays the same); every old row with its old columns, and every
   media file, unchanged; the administrator and the editor can log in with their
   old passwords, the blocked user cannot; the front page, the article with its
   image, the contact page, the login page; the admin's pages as the
   administrator (the profile's session list and the *Submissions* too); the
   migration's work (the image's use in the article, `core:0008_media_usage`);
   the smaller copies of the old image made as the System page's button makes
   them; a contact message for administrators only. From 0.2.7 or older: the
   search index built as the System page's button builds it, and an old page
   found. With the content model: the Blueprint and the list loaded and
   editable, the events' values (the multi-valued ones and the relation too)
   and a query on them, the list's page with its filter, the admin's pages,
   a changed path leading on, the events found by the search, and the
   model's export importing again with no change. Any problem is listed and
   fails the test.

The database is the tests' (`CAMPANELLA_DB_*`), the tables get the
`CAMPANELLA_DB_PREFIX` prefix (`upg_` by default), dropped before and after.

## Backups: DatabaseBackup

`Campanella\Database\DatabaseBackup` · **Public** · `final class` · container: `DatabaseBackup::class`

```
php bin/campanella db:backup           # var/backups/campanella-20261006-185816-2b69ce.sql.gz
php bin/campanella db:backup --plain   # .sql, not compressed
```

Campanella's tables (those with the prefix) as an SQL file, written through
PDO, so it works on any web host. It restores them as they were (`DROP TABLE`,
`CREATE TABLE`, `INSERT`): import it into the database, e.g. phpMyAdmin >
Import, or `gunzip < file.sql.gz | mysql <database>`. Its first lines name
the Campanella version that made it and the database's schema version (since
0.2.0; until then the code's, which before an upgrade is not the database's).
The last line is `-- Campanella backup complete` (`COMPLETE`): a file without
it was cut off.

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
