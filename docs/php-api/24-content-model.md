# 24. The content model's export and import

*Since 0.2.6.* The content model of a site is what was made in the admin: its
Blueprints ([chapter 13](13-admin.md)) and lists ([chapter 23](23-lists.md)).
It can be saved into one JSON file and imported into another site, e.g. from a
development site to the live one. The Blueprints of `config/blueprints.php`
are not in the file: they come with the code.

```sh
php bin/campanella model:export campanella-model.json
php bin/campanella model:import campanella-model.json         # only the plan
php bin/campanella model:import campanella-model.json --yes   # does it
```

An import first shows its **plan**: a line for each Blueprint and list of the
file and of the site, with what would change. It is all or nothing: if any line
has an error, nothing is imported. **Nothing is deleted:** a Blueprint, list,
field or relation the site has and the file does not stays as it is (and the
plan says so). Deleting is done by hand, in the admin.

## The page in the admin: ModelPage

`Campanella\Admin\ModelPage` · **Internal**, for the system roles
(`admin.system_roles`), under System in the admin's menu (*Content model*).

| Page | What it does |
|---|---|
| `/admin/system/model` | How many Blueprints and lists the site has made in the admin; the download; the form to upload a file |
| `/admin/system/model/export` | The file, `campanella-model.json` (`Content-Disposition: attachment`, `Cache-Control: no-store`) |
| `/admin/system/model/import` | POST, with the file (`file`; at most 1 MB): its plan. Changes nothing. With errors, it says why and offers no confirming (422) |
| `/admin/system/model/apply` | POST: the confirmed import. The file comes back from the plan's page (base64, in a hidden field) and is checked again in full; the plan's `fingerprint` must still be the same, otherwise the new plan is shown (409). Then `ModelImport::apply()`, and back to the page with what was done (and the backup, if one was made) |

The uploaded file is never stored on the server.

## The file: ModelFile

`Campanella\Transfer\ModelFile` · **Public** · `final class`

```json
{
  "format": "campanella.content-model",
  "version": 1,
  "campanella": "0.2.6",
  "exported_at": "2026-10-23T14:47:00Z",
  "blueprints": {
    "esemeny": {"version": 1, "label": "Esemény", "capabilities": ["titled", "routable"], "fields": [], "relations": []}
  },
  "lists": {
    "esemenyek": {"version": 1, "title": "Események", "blueprint": "esemeny",
      "conditions": [{"relation": "kategoria", "targets": [{"blueprint": "category", "path": "/rendezvenyek", "title": "Rendezvények"}]}],
      "sort": [], "per_page": 10, "display": "teaser", "columns": [], "path": "/esemenyek", "filters": []}
  }
}
```

A Blueprint is in its stored form (`BlueprintDefinition`, chapter 13), a list
too (`ListDefinition`), but **a relation condition names its objects by
reference**, not by ID: an ID means another object on another site. A
reference has the object's Blueprint and what it has of a machine name, a path
and a title; the import looks for the object by these, in this order (by the
title only if one object of the Blueprint has it). A file with IDs in a
relation condition is not imported.

| Member | Description |
|---|---|
| `const FORMAT`, `const VERSION` | `campanella.content-model`, `1`; a file of a newer version is not read |
| `const MAX_BYTES`, `const MAX_ITEMS` | At most 1 MB, and at most 200 Blueprints and 200 lists |
| `const FILE_NAME` | `campanella-model.json`, the suggested name |
| `static decode(string $json): array` | The file's `blueprints`, `lists`, `campanella` and `exported_at`, its frame checked (the format, the version, objects of names, at most 16 levels deep, a byte order mark allowed); `InvalidArgumentException` otherwise. The items themselves are checked by the import |
| `static encode(array $model): string` | The file's contents, as JSON with indentation |

## ModelExport

`Campanella\Transfer\ModelExport` · **Public** · `final class`, a service

| Member | Description |
|---|---|
| `model(?DateTimeImmutable $now = null): array` | The site's model: every stored Blueprint and list (`BlueprintStore`, `ListStore`), the relation conditions' objects as references |
| `json(?DateTimeImmutable $now = null): string` | The same as the file's contents |

## ModelImport

`Campanella\Transfer\ModelImport` · **Public** · `final class`, a service

| Member | Description |
|---|---|
| `plan(array $model): ImportPlan` | What importing `ModelFile::decode()`'s result would do. Changes nothing |
| `apply(ImportPlan $plan): ?string` | Does all of a plan without errors; the backup's file, if one was made. `RuntimeException` if the plan has errors or the database could not be changed (the previous definitions are put back then) |

**The rules** are those of the admin's editors:

- A Blueprint of the file that the site does not have is made. The name of a
  Blueprint of `config/blueprints.php`, a reserved name, or a name that objects
  without a Blueprint have is an error.
- A Blueprint the site has (made in the admin) gets the file's name, label and
  capabilities, its fields and relations in the file's order, with the
  editor's safe changes only: a field's type only to a wider one
  (`BlueprintEditor::WIDER_TYPES`), its choices in use kept, several values not
  made one while there are objects, a new required queryable field with a
  default while there are objects, a relation's targets and number unchanged.
  Its fields and relations that the file does not have stay, after the others.
  The columns of its list in the admin (`admin_columns`, since 0.2.8) are the
  file's, of the fields and relations it has then.
- Every Blueprint is defined again with the file's (as on every request), and
  the SchemaSync plans the database's changes; what it cannot do (e.g. a field
  made required without a default while objects lack it) is an error of that
  Blueprint.
- A list is made or replaced, checked by `ListDefinition::fromArray()` against
  the Blueprints as they would be, its path by `ListPathCheck` and against the
  file's other lists. A reference not found is a warning; a relation condition
  with none found is an error. A list of the site that the import would break
  (e.g. its field stops being queryable) is a warning.
- **A backup** is made first if the database changes and the changed
  Blueprints have objects, or values move or change (`SyncPlan::changesData()`).

## ImportPlan and ImportItem

`Campanella\Transfer\ImportPlan` · **Public** · `final readonly class`

| Member | Description |
|---|---|
| `$items` | `list<ImportItem>`: the file's Blueprints and lists, then the site's not in the file |
| `$errors` | Errors of the whole import (`list<Message>`, e.g. the database needs an upgrade) |
| `$database` | The database's changes, in order (`list<Message>`) |
| `$backup` | Whether a backup is made first |
| `$blueprints`, `$lists` | The definitions that would be saved, by name |
| `$fingerprint` | A hash of the site's model and the changes: the admin applies a confirmed import only if it is still the same |
| `hasErrors(): bool`, `hasChanges(): bool` | Whether anything is an error; whether anything would change |
| `counts(): array` | How many items per action |

`Campanella\Transfer\ImportItem` · **Public** · `final readonly class`:
`$kind` (`blueprint`, `list`), `$name`, `$label`, `$action` (`create`,
`update`, `unchanged`, `kept`: on the site, not in the file; `error`),
`$changes` and `$warnings` (`list<Message>`), `$error` (`?Message`).

## ObjectReferences

`Campanella\Transfer\ObjectReferences` · **Internal**

| Member | Description |
|---|---|
| `describe(array $ids): array` | The references of objects (`blueprint`, and `machine_name`, `path`, `title` if it has them) |
| `resolve(mixed $reference, array $blueprints): ?int` | The ID of the one object a reference names, among the given Blueprints' objects; null if none (or not one) |

## ListPathCheck

`Campanella\Lists\ListPathCheck` · **Internal** · (since 0.2.6; the check of
the list editor, chapter 23)

| Member | Description |
|---|---|
| `isTaken(string $path, string $name): bool` | Whether a route other than the list's own, the admin, a public folder or an object has the path |
