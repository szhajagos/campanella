# 26. Importers

*Since 0.3.1.* An importer turns the items of a file into objects: the rows of
a CSV file, or the objects of a JSON file, become the objects of one Blueprint,
by a mapping of the file's columns to the Blueprint's fields and relations. A
**unique key** of each item (e.g. an `id` column) finds the object a former run
made of it, so the same file, or a newer one, can be imported again: an item
that did not change is skipped, one that changed updates its object, a new one
makes an object.

```sh
php bin/campanella import:run programok esemenyek.csv         # a trial: the first items, nothing changes
php bin/campanella import:run programok esemenyek.csv --yes   # the run, then its log
php bin/campanella import:undo 12 --yes                       # deletes what run 12 made (after a backup)
```

The rules, as agreed for the importers:

- **Never started by a visitor's request** (it would slow down their page): by
  hand, in the admin (System → Importers) or on the command line, e.g. from cron.
- **In the name of a user:** the objects are made and changed through the
  `ObjectService`, with the permissions of the importer's user (it becomes their
  author), and every value goes through the field's checks and the HTML filter,
  as in the admin. A blocked or deleted user cannot run an importer.
- **Never deletes:** an object whose item is no longer in a run's file is kept,
  or made a draft (`missing_items`). Undoing a run deletes the objects it made,
  after a backup; what it changed in older objects stays.
- **In batches:** a run goes on in steps of a few seconds (`Importer::step()`),
  from where the last one stopped, so a large file never holds one request too
  long. The admin takes a few MB; the command line a file of any size (a JSON
  file at most `ImportSource::MAX_JSON_BYTES`, it is read at once).

The tables (schema version 17): `importers` (the definitions, as JSON),
`import_runs` (each run: where it is, its counts, its first errors by item) and
`import_items` (which item became which object: the importer, the item's key,
the object, a hash of its values, the run that made it and the last one that
saw it; deleted with the object).

## The pages in the admin: ImporterPages

`Campanella\Admin\ImporterPages` · **Internal**, for the system roles
(`admin.system_roles`), under System in the admin's menu (*Importers*).

| Page | What it does |
|---|---|
| `/admin/system/importers` | Every importer: its Blueprint, format, how many objects it made, its last run; those that do not load, with the reason |
| `…/new` | GET, POST: a new importer: its label, machine name, Blueprint (one of content), format, and the key's and the title's columns; then its page. The current user becomes its user |
| `…/<name>` | GET, POST: its settings: the file (format, delimiter, JSON items), the key, the mapping (its rows and five empty ones; an empty row is left out), publishing, a missing target, missing items, its user (those who can log in and have a role). Checked by `ImporterDefinition::fromArray()` and `Importer::actor()` (422 otherwise). An importer whose Blueprint is gone can only be deleted |
| `…/<name>/delete` | POST, with `confirm` ticked: deletes it and its log (`Importer::delete()`); the objects stay |
| `…/<name>/run` | GET: the upload form and the log. POST, with `file` (at most `MAX_UPLOAD`, 5 MB): its trial (`Importer::trial()`); the file waits in `var/imports/` (`pending-<token>.<format>`, deleted after `PENDING_HOURS` if not confirmed) |
| `…/<name>/start` | POST, with the trial's `token`: starts its run (`Importer::start()`, the waiting file deleted), then the run's page |
| `…/<name>/runs/<id>` | A run's progress, counts and errors. While it runs, its *Continue* form is sent by `admin-import.js` again and again (or by hand) |
| `…/<name>/runs/<id>/step` | POST: goes on for `STEP_SECONDS` (8) seconds |
| `…/<name>/runs/<id>/cancel` | POST: stops it |
| `…/<name>/runs/<id>/undo` | POST, with `confirm` ticked: undoes it (`Importer::undo()`, a backup first) |

## The definition: ImporterDefinition

`Campanella\Import\ImporterDefinition` · **Public** · `final readonly class`

```json
{
  "version": 1,
  "label": "Programok a táblázatból",
  "blueprint": "esemeny",
  "format": "csv",
  "delimiter": "auto",
  "items": "",
  "key": "Azonosító",
  "mapping": [
    {"source": "Cím", "target": "title"},
    {"source": "Kezdés", "target": "kezdes"},
    {"source": "Címkék", "target": "cimkek", "separator": "|"},
    {"source": "Helyszín", "target": "helyszin"}
  ],
  "publish": true,
  "missing_target": "error",
  "missing_items": "keep",
  "user": 1
}
```

| Key | Meaning |
|---|---|
| `blueprint` | The Blueprint whose objects the items become: one of content (`ImporterDefinition::importable()`: not users, files, forms' messages, menus or menu items) |
| `format` | `csv` or `json` |
| `delimiter` | A CSV's delimiter: `auto` (the most frequent of `;`, `,` and a tab in the header row), `;`, `,` or `tab` |
| `items` | A JSON file's list of items, as a dotted path (`data.items`); empty: the file itself is the list |
| `key` | The source of the item's unique key (at most 191 characters; an item without one fails) |
| `mapping` | At most 50 lines: a `source` (a CSV column's heading; in JSON a key, or a dotted path such as `venue.name`) to a `target`: `title`, `body`, `path`, `published_at` (the ones the Blueprint has), an own field that is not hidden, or a relation to the objects of other Blueprints (`targets()`). Each target once; the title must be mapped. `separator` (default `\|`): splits a CSV's text into the values of a multi-valued field or a relation (a JSON list is taken as it is) |
| `publish` | Whether new objects are published at once (a Publishable Blueprint; a mapped `published_at` publishes at that time instead) |
| `missing_target` | A relation's value that names no object (by machine name, path or title, in this order, among the relation's target Blueprints): `error` (the item fails), `skip` (imported without that link), `create` (an object of the relation's first target Blueprint is made, with the value as its title, published if `publish`) |
| `missing_items` | When a run is done, the importer's objects whose items were not in its file: `keep`, or `draft` (unpublished) |
| `user` | The ID of the user in whose name the objects are made |

| Member | Description |
|---|---|
| `static fromArray(string $name, array $stored, BlueprintRegistry $blueprints): self` | Checks a stored or posted definition against the Blueprints (`InvalidArgumentException` with what is wrong). The name follows the rules of a Blueprint's |
| `toArray(): array` | The stored form |
| `static importable(Blueprint $blueprint): bool` | Whether its objects can be imported |
| `static targets(Blueprint $blueprint): array` | The targets it offers, by name: fields and relations |
| `static target(Blueprint $blueprint, string $name): Field\|Relation\|null` | One of them |
| `$name`, `$label`, `$blueprint`, `$format`, `$delimiter`, `$items`, `$key`, `$mapping`, `$publish`, `$missingTarget`, `$missingItems`, `$user` | The definition |

`Campanella\Import\ImportMapping` · **Public** · `final readonly class`: one
line of the mapping, `$source`, `$target`, `$separator`; `toArray()`.

`Campanella\Import\ImporterStore` · **Public** · container: `ImporterStore::class`:
`load(): array` (every stored definition by name; none before the table
exists), `find(string $name): ?array`, `save(string $name, array $definition)`,
`delete(string $name)` (with its runs' log and keys; the objects stay).

## The file: ImportSource

`Campanella\Import\ImportSource` · **Public** · `final class`

A CSV file is read as a stream: UTF-8 (a byte order mark is left out), its
first row the headings (each once; a column without one is called `#3` by its
place); fields in double quotes may hold the delimiter, `""` and line breaks;
empty rows are skipped. A JSON file is read at once.

| Member | Description |
|---|---|
| `static open(string $path, ImporterDefinition $importer): self` | The file, read as the importer says |
| `static file(string $path, string $format, string $delimiter = 'auto', string $itemsPath = ''): self` | The same, by hand. `ImportException` if it cannot be read: not there, no header row, not UTF-8, headings repeated, not JSON, no list there, too large |
| `columns(): array` | A CSV's headings; a JSON file's keys (nested ones as dotted paths) of its first 20 items |
| `items(int $skip = 0): Generator` | The items from the given one on: number (1 for the first) ⇒ values by heading, or an `ImportException` in place of an item that cannot be read (a row not UTF-8; a JSON item that is not an object) |
| `count(): int` | The number of items |
| `static value(array $item, string $source): mixed` | An item's value at a source (a key, or a dotted path); null if none |
| `const MAX_JSON_BYTES`, `const MAX_COLUMNS` | 20 MB; 200 columns |

## The values: ImportValues

`Campanella\Import\ImportValues` · **Public** · `final class`:
`__construct(DateTimeZone $timezone)`; `convert(Field $field, mixed $raw, string $separator): mixed`,
the field's value (a list for a multi-valued field), or `ImportException` with
the reason (`import.error.number`, `import.error.date`, `import.error.choice` …);
`static split(mixed $raw, string $separator): array`. An empty value is null.

| Type | From |
|---|---|
| text, long text, e-mail, web address | the text, trimmed (an address is checked on save) |
| formatted text | HTML as it is (filtered on save); a text without tags becomes paragraphs |
| whole number | digits with a sign, spaces left out |
| decimal | with a decimal point or comma, spaces left out (`1 234,5`), rounded to the field's decimals |
| yes/no | `1` `0`, `true` `false`, `yes` `no`, `igen` `nem`, `x`, and JSON's `true` `false` |
| date | `2026-11-05`, `2026.11.05.`, `2026. 11. 05.` |
| date and time | a date and a time (`18:00`, `18:00:30`) in the site's time zone, or ISO 8601 with an offset (`2026-11-05T18:00:00+01:00`) |
| choice | its key, or its label in any case |

## Running: Importer

`Campanella\Import\Importer` · **Public** · `final class`, a service

| Member | Description |
|---|---|
| `all(): array` | `[definitions by name, the stored ones that do not load: name ⇒ reason]` |
| `find(string $name): ?ImporterDefinition` | One that loads |
| `trial(ImporterDefinition $importer, string $file, int $items = 10): ImportTrial` | What the first items would become: each converted, its relations found, checked as saving checks it (saved in a transaction that is rolled back), and compared with what the last run made. Changes nothing |
| `start(ImporterDefinition $importer, string $file, string $sourceName, ?int $userId = null): ImportRun` | Starts a run: keeps a copy of the file in `var/imports/` until the run ends, counts its items. `ImportException` if the file cannot be read, the importer's user cannot work, or another run of it is not done |
| `step(int $runId, float $seconds = 10.0): ImportRun` | Goes on with a run for about this long (at least one item). When the last item is done, the run ends: the missing items (`missing_items`), the file deleted |
| `cancel(int $runId): void` | Stops a run: what it did stays (and can be undone) |
| `undo(int $runId): int` | Deletes the objects a finished run made (a backup first, if any); how many. `ImportException` if it is running or was undone |
| `runs(string $importer, int $limit = 20): array` | Its runs (`ImportRun`), the newest first |
| `run(int $id): ?ImportRun` | One run |
| `objectCount(string $importer): int` | How many objects its runs made that are still there |
| `delete(string $importer): void` | Deletes it, its log and its runs' files; the objects stay |
| `actor(ImporterDefinition $importer): Actor` | Its user's actor; `ImportException` if the user is not there or blocked |
| `const MAX_ERRORS`, `const TRIAL_ITEMS`, `const MAX_KEY` | 200 errors kept in a run's log (all are counted), 10 items in a trial, 191 characters of a key |

An item of a run:

1. Its key, then each mapped value converted (`ImportValues`); a relation's
   values found, made (`create`) or left out (`skip`). Anything wrong fails the
   item, with its row's number, its key and the reason in the log.
2. A key seen before in the same run fails the item (`import.error.duplicate`).
3. If a former run made an object of the key and it is still there: with the
   same hash (of the values as the file has them, the relations by their values)
   it is **unchanged**; otherwise the object is **updated** with the mapped values
   (the others stay). If not: a new object is **created** (published if
   `publish`). Each item is one transaction.

`Campanella\Import\ImportRun` · **Public** · `final readonly class`: a run as
its log keeps it: `$id`, `$importer`, `$status` (`running`, `done`,
`cancelled`, `undone`), `$sourceName`, `$position`, `$total`, `$created`,
`$updated`, `$unchanged`, `$failed`, `$missing`, `$errors` (`list` of `row`,
`key`, `field`, `message`), `$userId` (who started it; null: the command
line), `$startedAt`, `$finishedAt`, `$undoneAt`; `isRunning()`, `percent()`;
`static fromRow(array $row)`; `static text(Message $message, Translator $translator): string`,
a message of an import in the translator's language (a field's label that is
a message key translated too).

`Campanella\Import\ImportTrial` · **Public** · `final readonly class`: `$total`,
`$columns`, `$missingSources` (the mapping's sources a CSV has no column of),
`$rows` (each: `row`, `key`, `action`: `create`, `update`, `unchanged` or
`error`, `values` as text, `creates`: the targets that would be made, `error`,
`field`); `count(string $action): int`.

`Campanella\Import\ImportException` · **Public** · `final class extends RuntimeException`:
`$reason`, a `Message`.

`Campanella\Import\TrialRollback` · **Internal**: rolls back a trial's
checking save.

## The command line

`Campanella\Cli\ImportRunCommand`, `Campanella\Cli\ImportUndoCommand` ·
**Internal**: `import:run <importer> <file> [--yes]` shows a trial, and with
`--yes` runs the importer to its end, then its counts and errors (exit code 1
if an item failed); `import:undo <run> [--yes]`.
