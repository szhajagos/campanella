# 2. Objects

## CampanellaObject

`Campanella\Model\CampanellaObject` · **Public** · `final class`

The generic object. It has no subclasses; its behavior comes from its
capabilities. New objects are created by `ObjectRepository::create()` or
`ObjectService::create()`, and loaded by `ObjectRepository` or `QueryEngine`.
Do not call the constructor directly.

### Identity

| Method | Returns | Description |
|---|---|---|
| `id()` | `?int` | Database ID; `null` before saving |
| `uuid()` | `string` | UUID v7, available from creation on; use this one externally |
| `blueprint()` | `string` | Name of the Blueprint, e.g. `'article'` |
| `created()` | `DateTimeImmutable` | Creation time (UTC) |
| `updated()` | `DateTimeImmutable` | Time of the last save (UTC) |
| `isNew()` | `bool` | True if not saved yet |

### Capabilities

| Method | Returns | Description |
|---|---|---|
| `has(string $capability)` | `bool` | Whether the object has it; accepts a name (`'publishable'`) or a class name (`Publishable::class`) |
| `as(string $class)` | the capability's class | The object seen through the "lens" of the capability. `CapabilityException` if it does not have that capability |
| `capabilityNames()` | `list<string>` | Names of the capabilities in dependency order |
| `capabilities()` | `array<string, CapabilityDefinition>` | The same, with definitions |

```php
if ($object->has(Publishable::class)) {
    $object->as(Publishable::class)->publish();
}
```

For the same capability, `as()` always returns the same adapter instance.

### Fields

| Method | Description |
|---|---|
| `get(string $field): mixed` | Value of the field. `OutOfBoundsException` if there is no such field |
| `set(string $field, mixed $value): void` | Sets the value, cast to the field's type (`Field::cast`). For a multi-valued field, pass a list (a single value becomes a one-element list). `OutOfBoundsException` if there is no such field |
| `fill(array $values): void` | Several fields at once |
| `hasField(string $field): bool` | Whether it has such a field |
| `fields(): array<string, Field>` | Definitions of all its fields |
| `values(): array<string, mixed>` | All values |

Setting happens in memory only; saving writes it to the database.

### Relations

The `relations()`, `hasRelation()`, `relatedIds()`, `setRelated()`, `relate()`,
`unrelate()`, `relatedObjects()` and `isResolved()` methods are described in
chapter [10. Relations](10-relations.md#on-the-object).

### From templates

`__get()` and `__isset()` give read-only access so that Twig templates can
simply write `{{ object.title }}`, `{{ object.published_at|date }}`.
`id`, `uuid`, `blueprint`, `created` and `updated` are available this way too.
In PHP code, `get()` is recommended, because it reports an error for a
misspelled field name.

Hidden (`hidden: true`) fields (e.g. `password_hash`, `email`) are not
available this way: `__get()` returns `null`, `__isset()` returns false. If a
template really needs them, they can only be read explicitly, as
`{{ object.get('email') }}`.

### Internal

`markSaved(int $id, DateTimeImmutable $updated)`: called only by
`ObjectRepository` after saving. **Internal.**

## Field

`Campanella\Model\Field` · **Public** · `final readonly class`

The definition of a field. Capabilities provide them in their `fields()`
method, Blueprints under the `fields` key.

```php
new Field(
    name: 'published_at',          // ^[a-z][a-z0-9_]{0,62}$, unique across the system
    type: FieldType::DateTime,
    storage: FieldStorage::Table,  // default
    required: false,
    default: null,
    indexed: true,                 // index in the capability's table
    unique: false,                 // unique index (e.g. path)
    length: 255,                   // VARCHAR length for the String type
    label: 'field.published_at',
    hidden: false,                 // true: not available from templates as {{ object.field }}
    cardinality: 1,                // number of values: 1, a limit (e.g. 3) or Field::UNLIMITED (since 0.0.4)
    translatable: false,           // the value may differ per language (since 0.2.2; no effect yet)
    choices: [],                   // a Choice field's keys and labels (since 0.2.2)
    scale: 2,                      // a Decimal field's digits after the point, 0–6 (since 0.2.2)
);
```

**`translatable`** (since 0.2.2) marks a field whose value may differ per
language (a title, a text, a path), as opposed to one that is the same in
every language (a date, a price). It is stored with the definition but has no
effect until multilingual content (0.6.0); the design is in
[Translations](../translations.md).

**`choices`** (since 0.2.2): the keys and labels of a `Choice` field, e.g.
`['talk' => 'event.kind.talk', 'walk' => 'Séta']`; a label is a message key
(translated) or text. The key is stored. Keys are letters, digits, `_`, `.`
and `-`, at most 64 characters (and not longer than `length`). A Choice field
must have choices; no other field may.

| Method | Description |
|---|---|
| `asData(): self` | The same field with `FieldStorage::Data` storage (without index and uniqueness) |
| `asTable(): self` | The same field with `FieldStorage::Table` storage (a Blueprint's `queryable` own field; since 0.2.2) |
| `withCardinality(int $cardinality): self` | The same field with a lower value limit; used by a Blueprint to narrow a capability field (see below) |
| `isQueryable(): bool` | True if `FieldStorage::Table`, i.e. it can be filtered on (and, if single-valued, sorted on) |
| `isMultiple(): bool` | True if the cardinality is not 1 |
| `isUnlimited(): bool` | True for `Field::UNLIMITED` |
| `usesValueTable(): bool` | A multi-valued `Table` field: its values live in the `field_values` table |
| `cast(mixed $value): mixed` | Uniform PHP value; for a multi-valued field always a list (see below); a Decimal rounded to `scale` (half away from zero) |
| `toStorage(mixed $value): mixed` | Storable form; for a multi-valued field a list of the items' storable forms |
| `fromStorage(mixed $value): mixed` | Back to a PHP value |
| `exceedsCardinality(mixed $value): bool` | Whether a list has more values than the limit |
| `hasTooLongItem(mixed $value): bool` | For a multi-valued `String`: whether an item is longer than `length` |
| `isTooLong(mixed $value): bool` | For a single-valued `String`: whether the value is longer than `length` (checked on save since 0.0.4, `validation.value_too_long`) |
| `isEmpty(mixed $value): bool` | `null`, empty string or empty list; the required-field check uses this |
| `invalid(mixed $value): ?Message` | Whether a (non-empty) value is invalid for the type, checked on save (since 0.2.2): `validation.invalid_email`, `validation.invalid_web_address`, `validation.invalid_choice`, `validation.number_too_large`; null if it is fine |
| `static isWebAddress(string $url): bool` | An `http://` or `https://` address with a host, without whitespace, control characters or backslashes (a `Url` field's check; since 0.2.2) |
| `choiceLabel(string $key): ?string` | A Choice field's label for a key (a message key or text), or null for a key not offered (since 0.2.2) |

The constructor throws `InvalidArgumentException` for an invalid name, if a
field with `Data` storage would be indexed or unique, for the invalid
multi-valued combinations listed below, for missing or invalid `choices`
(or choices on another type), a multi-valued `Html` field, and a `scale`
outside 0–6.

### Multi-valued fields

Since 0.0.4 a field can hold several values. The cardinality is set by whoever
defines the field (the capability, or the Blueprint for its own fields):

```php
new Field('phones', FieldType::String, length: 32, cardinality: 3);         // at most 3
new Field('keywords', FieldType::String, cardinality: Field::UNLIMITED);    // any number
new Field('notes', FieldType::Text, storage: FieldStorage::Data, cardinality: Field::UNLIMITED);
```

- **The value is always a list**, in order: `get('phones')` returns
  `['+36 1 111 1111', …]`, or `[]` when empty. `set()` accepts a list or a
  single value (which becomes a one-element list). Every item is cast by the
  field's type; empty items (`null`, `''`) are dropped, duplicates are kept.
  Any iterable is accepted as a list.
- **Validation on save:** `required` means at least one value; more values
  than the limit give the error `legfeljebb 3 érték adható meg` ("at most 3
  values can be given").
- **Storage:** a `Table` (queryable) multi-valued field lives in the shared
  `field_values` table, one row per value
  ([chapter 8](08-database.md#coreschema)); a `Data` one in the `data` JSON
  column as a list. It never gets a column in the capability's table.
- **Querying:** a condition matches if *any* value matches; sorting is not
  possible ([chapter 4](04-query.md#multi-valued-fields)).
- **Length:** every item of a multi-valued `String` field is checked against
  `length` on save (`egy érték legfeljebb 32 karakter lehet`, "a value can be
  at most 32 characters"), because the shared `field_values` column is always
  `VARCHAR(255)`.
- **Default:** `default` applies to new objects (`create()`). On load, a
  queryable multi-valued field without stored values is `[]`.
- **Indexing:** the values in `field_values` are always indexed; `indexed` has
  no extra effect.
- **Not allowed:** `unique`; a queryable
  multi-valued `String` in a capability longer than 255 characters
  (`Field::MAX_MULTI_STRING_LENGTH`; `CapabilityException` on registration).
  A Blueprint's own fields are stored in data, so for them the limit does not apply.
- **Changing it later:** switching an existing field between single- and
  multi-valued moves its data to another place, so it needs a migration
  (planned for 0.0.6); until then, choose the cardinality when the field is
  first introduced.

Whether a field is single- or multi-valued is decided by its definition and
cannot be changed by a Blueprint, because the capability's code relies on
getting either a value or a list. A Blueprint can only **narrow** the limit
(see [Blueprint](#blueprint)).

In templates the value is a list as well:

```twig
{% for phone in object.phones %}<a href="tel:{{ phone }}">{{ phone }}</a>{% endfor %}
```

## FieldType

`Campanella\Model\FieldType` · **Public** · `enum: string`

| Case | PHP value | Column |
|---|---|---|
| `String` | `string` | `VARCHAR(length)` |
| `Text` | `string` | `MEDIUMTEXT` |
| `Integer` | `int` | `INT` |
| `Boolean` | `bool` | `TINYINT(1)` |
| `DateTime` | `DateTimeImmutable` (UTC) | `DATETIME` |
| `Decimal` | numeric `string`, e.g. `'1234.50'` (since 0.2.2) | `DECIMAL(20,6)` |
| `Date` | `CalendarDate` (since 0.2.2) | `DATE` |
| `Email` | `string`, checked as an e-mail address (since 0.2.2) | `VARCHAR(length)` |
| `Url` | `string`, an `http(s)://` address (since 0.2.2) | `VARCHAR(length)` |
| `Choice` | `string`: one of the field's `choices` keys (since 0.2.2) | `VARCHAR(length)` |
| `Html` | `string`: formatted text, filtered by the HtmlSanitizer on save, like a formatted Textual body (since 0.2.2) | `MEDIUMTEXT` |

A **Decimal** is a string, so no precision is lost: `cast()` accepts an
integer, a float, or text with a comma or a point as the decimal point and
spaces between the digits (`'1 234,5'`), and gives `'1234.5'`; the field
rounds it to its `scale` (`'1234.50'`). At most 14 digits before the point
(`FieldType::DECIMAL_DIGITS`) and 6 after it (`DECIMAL_SCALE`). Text that is
not a number throws `InvalidArgumentException`.

A **Date** is a calendar day without a time and time zone: a `CalendarDate`
(see below). A **DateTime** is an instant, shown in the site's time zone.
A list of values is a multi-valued field (`cardinality`), e.g. a multi-valued
`String`. The former `StringList` type (a JSON array in a column, deprecated
in 0.0.6) was removed in 0.1.0.

| Method | Description |
|---|---|
| `cast(mixed $value): mixed` | Converts to a uniform PHP value. For a `BackedEnum` it takes its value (so `PublishStatus::Published` can be passed too). For dates it also accepts a string, interpreted as UTC |
| `toStorage(mixed $value): string\|int\|null` | A form that can be written to the database or JSON; date: `Y-m-d H:i:s` UTC |
| `fromStorage(mixed $value): mixed` | Back to a PHP value |
| `columnType(): ColumnType` | The matching column type |
| `valueColumn(): string` | The `field_values` column holding the values of a multi-valued field of this type: `value_string` (String, Email, Url, Choice), `value_text` (Text, Html), `value_int` (Integer, Boolean), `value_datetime` (DateTime, Date) or `value_decimal` (Decimal, since 0.2.2) |

The `STORAGE_DATE_FORMAT` constant (`'Y-m-d H:i:s'`) is the format of stored
dates.

## CalendarDate

`Campanella\Model\CalendarDate` · **Public** · `final class extends DateTimeImmutable` (since 0.2.2)

The value of a `Date` field: a day of the calendar (a birthday, the day of an
event). It is a `DateTimeImmutable` at midnight, so `format()`, comparisons and
Twig's `date` filter work as usual, but `setTimezone()` does nothing: 24
October is 24 October in every time zone, also where Twig converts dates to
the site's time zone. Printed as text, it is `2026-10-24`.

| Member | Description |
|---|---|
| `__construct(string $date)` | From `Y-m-d` (anything after the tenth character, e.g. a time, is ignored); `InvalidArgumentException` for an invalid date (`2026-02-30`) |
| `static of(DateTimeInterface $moment, ?DateTimeZone $timezone = null): self` | The day of a moment, as seen in the time zone (e.g. the site's) |
| `setTimezone(DateTimeZone $timezone): static` | Returns the same date |
| `FORMAT` | `'Y-m-d'`: the storage form and the value of `<input type="date">` |

```twig
{{ object.event_day|date('Y. m. d.') }}   {# or the language's: date(t('date.format_day')) #}
```

## FieldStorage

`Campanella\Model\FieldStorage` · **Public** · `enum`

| Case | Where the value lives | Filterable, sortable |
|---|---|---|
| `Table` | In the capability's own table (`cc_cap_<name>`), or for a Blueprint's own queryable field in the Blueprint's table (`cc_bp_<name>`, since 0.2.2); a multi-valued field in the shared `cc_field_values` table | yes (multi-valued: filterable only) |
| `Data` | In the object's `data` JSON column (a multi-valued field as a list) | no |

**Rule:** whatever needs to be filtered or sorted on is `Table`. Everything
else can go into `Data`. A Blueprint's own field is `Data` unless the
Blueprint lists it under `queryable` (since 0.2.2; see below); making it
queryable later moves its values (SchemaSync,
[chapter 17](17-migrations.md#applying-the-definitions-schemasync)).

## Blueprint

`Campanella\Model\Blueprint` · **Public** · `final readonly class`

A named bundle of capabilities. Defined in the `config/blueprints.php` file:

```php
return [
    'article' => [
        'label' => 'blueprint.article',
        'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class],
        'fields' => [
            new Field('lead', FieldType::Text, label: 'field.lead'),
        ],
        // Optional (since 0.2.2): own fields to filter and sort on.
        // 'queryable' => ['lead'],
        // Optional (since 0.0.4): narrow the value limit of a capability's multi-valued field.
        // 'cardinality' => ['phones' => 2],
        // Optional (since 0.0.5): initial values of a new object, and the admin editor's profile.
        'defaults' => ['format' => 'html'],
        'editor' => ['body' => 'full'],
    ],
];
```

A Hierarchical Blueprint may also name a `tree_scope` (since 0.0.7), e.g. a
menu item's `'tree_scope' => 'menu'` ([chapter 18](18-trees.md#separate-trees-tree_scope)).

| Member | Description |
|---|---|
| `$name`, `$label` | Name (`^[a-z][a-z0-9_]{0,62}$`) and label |
| `$capabilities` | `array<string, CapabilityDefinition>`, completed with the dependencies |
| `$fields` | Only the Blueprint's own fields: `FieldStorage::Data`, or `FieldStorage::Table` for those under `queryable` (since 0.2.2; until then always Data) |
| `$relations` | Only the Blueprint's own relations (`array<string, Relation>`) |
| `$lists` | Lists shown on the object's page ([chapter 10](10-relations.md#lists-on-the-objects-page)) |
| `$narrowed` | The capability fields whose cardinality this Blueprint narrows (`array<string, Field>`) |
| `$formOrder` | The order of fields and relations in the admin form (`form_order` key; those not listed follow in their natural order) |
| `$defaults` | Initial values of a new object (`defaults` key, since 0.0.5): `ObjectRepository::create()` uses them for the fields not given, instead of the fields' own defaults. E.g. `['format' => 'html']`: new articles get a formatted body |
| `$editors` | The toolbar profile of HTML text fields in the admin (`editor` key, since 0.0.5), e.g. `['body' => 'full']` ([chapter 13](13-admin.md#formatted-text-the-html-editor)) |
| `$treeScope` | `tree_scope` key (since 0.0.7): the required single relation that splits a Hierarchical Blueprint's objects into separate trees, or null ([chapter 18](18-trees.md#separate-trees-tree_scope)) |
| `$adminColumns` | `admin_columns` key (since 0.2.8): at most `BlueprintRegistry::MAX_ADMIN_COLUMNS` (4) of its own fields (not hidden ones) and relations, the columns of its list in the admin ([chapter 13](13-admin.md#the-list)); an unknown name or one twice is a `CapabilityException` |
| `allFields()` | The capability fields (narrowed where configured) and the own fields together |
| `narrow(array $fields): array` | Applies the narrowed cardinalities to the given field definitions |
| `allRelations()` | The capability relations and the own relations together |
| `tableName(): string` | `bp_<name>`: the Blueprint's own table, without the prefix (since 0.2.2) |
| `tableFields(): array<string, Field>` | The own single-valued queryable fields: the table's columns (since 0.2.2) |
| `valueTableFields(): array<string, Field>` | The own multi-valued queryable fields, in `field_values` (since 0.2.2) |
| `hasTable(): bool`, `table(): ?Table` | Whether it has a table, and its schema (since 0.2.2) |

Dependencies need not be listed: because of `Routable`, the `page` Blueprint
automatically gets `Titled` too.

**Queryable own fields** (since 0.2.2, `'queryable' => ['price', 'tags']`):
the single-valued ones become columns of the Blueprint's own table
`cc_bp_<name>`, shaped like a capability's table (`object_id`, a column per
field, the fields' `indexed` and `unique` indexes, deleted with the object);
the multi-valued ones live in `field_values`. They can be filtered on, and the
single-valued ones sorted on ([chapter 4](04-query.md#fields-in-a-query)). The
rest stay in the JSON data. Limits: at most 40 single-valued queryable fields
(`BlueprintRegistry::MAX_TABLE_FIELDS`, MySQL's row size), a queryable text
column of at most 255 characters (`Field::MAX_MULTI_STRING_LENGTH`; longer
texts are `Text`), a queryable field name of at most 59 characters, and a
Blueprint name of at most 40 characters (`MAX_TABLE_NAME`) if it has a table.
**A field name means one kind of value:** several Blueprints may have an own
field of the same name only with the same type and cardinality (queryable in
some of them only is fine).

The table, its columns and its indexes are created by `install`, `migrate`
or the upgrade page like any other definition's.

**Narrowing the cardinality** (`'cardinality' => ['phones' => 2]`): the field
must be a multi-valued field of one of the Blueprint's capabilities, and the new
limit must be at least 2 and not higher than the capability's limit (any value
if it is unlimited). The Blueprint's own fields set their cardinality on the
`Field` itself. Violations throw `CapabilityException` when the Blueprint is
defined, as does a `defaults` or `editor` entry for a field the Blueprint does
not have.

## BlueprintRegistry

`Campanella\Model\BlueprintRegistry` · **Public** · `final class`

| Method | Description |
|---|---|
| `__construct(CapabilityRegistry $capabilities, array $config = [])` | Built from the array in `config/blueprints.php` |
| `define(string $name, array $definition): Blueprint` | Adds a new Blueprint at runtime |
| `get(string $name): Blueprint` | `CapabilityException` if there is no such Blueprint |
| `find(string $name): ?Blueprint` | The same, but `null` if there is no such Blueprint |
| `relation(string $name): ?Relation` | A relation definition by name, whether provided by a capability or a Blueprint |
| `all(): array<string, Blueprint>` | All of them |
| `treeScopes(): list<string>` | The `tree_scope` relations of the Blueprints, each once (since 0.0.7) |
| `queryableOwners(string $field): list<Blueprint>` | The Blueprints that have the field as an own queryable field (since 0.2.2) |
| `ownField(string $name): ?Field` | A Blueprint's own field by name, whichever Blueprint defines it (since 0.2.2) |

`RESERVED_NAMES` (since 0.0.5): `system`, `media` and `upgrade` (since
0.0.6) cannot be Blueprint names, because the admin uses them as its own paths.

`define()` throws `CapabilityException` for an invalid or reserved name, an unknown
capability, if the name of an own field or relation collides with a field
or relation of a capability, for a `queryable` entry that is not an own field
or breaks the limits above, for an own field defined with another type or
cardinality in another Blueprint, for an invalid `cardinality` entry, and for a
`tree_scope` that is not a required single relation of a Hierarchical
Blueprint.

## Blueprints made in the admin

*Since 0.2.3.* Besides those of `config/blueprints.php`, Blueprints can be made
in the admin ([chapter 13](13-admin.md#making-blueprints-blueprinteditor)).
Their definitions live in the database; the container's BlueprintRegistry
defines them after those of the file.

### BlueprintStore

`Campanella\Model\BlueprintStore` · **Public** · container: `BlueprintStore::class`

| Method | Description |
|---|---|
| `load(): array<string, array>` | Every stored definition by name, in name order; none before the `blueprints` table exists (not installed or not upgraded) |
| `find(string $name): ?array` | One definition |
| `save(string $name, array $definition): void` | Creates or replaces it |
| `delete(string $name): void` | |

The definition is JSON in the `cc_blueprints` table, loaded but never queried.

### BlueprintDefinition

`Campanella\Model\BlueprintDefinition` · **Public** · `final class`

The stored form, and its conversion to what `BlueprintRegistry::define()`
takes. The same form will be the content model's export (0.2.6):

```json
{
  "version": 1,
  "label": "Esemény",
  "capabilities": ["titled", "textual", "routable", "publishable"],
  "fields": [
    {"name": "nap", "type": "date", "label": "Nap", "required": true, "queryable": true,
     "cardinality": 1, "translatable": false, "length": 255, "scale": 2, "choices": [], "default": null},
    {"name": "fajta", "type": "choice", "label": "Fajta",
     "choices": [{"key": "eloadas", "label": "Előadás"}, {"key": "seta", "label": "Séta"}]}
  ],
  "relations": [
    {"name": "kep", "label": "Kép", "targets": ["image"], "many": false, "required": false}
  ],
  "admin_columns": ["nap", "fajta"]
}
```

| Member | Description |
|---|---|
| `static toRegistry(array $stored): array` | The definition for `define()`: `titled` always added, the own fields with `queryable`, the relations, the form's order (title, own fields, body, own relations), and with `textual` the `html` format and the editor; `InvalidArgumentException` for anything not valid |
| `static field(array $item): Field` / `static fieldArray(Field $field): array` | A field from its stored form, checked (type, name, at most 100 values or unlimited, length 1–255, choices, a default valid for the field), and back |
| `static relation(array $item): Relation` / `static relationArray(Relation $relation): array` | A relation to the objects of the listed Blueprints, one or several, required, and back |
| `static name(mixed $name): string` | A machine name: `^[a-z][a-z0-9_]{0,39}$`; `InvalidArgumentException` otherwise |
| `static adminColumns(mixed $columns, array $names): array` | The columns of its list in the admin (since 0.2.8): those of the given own fields and relations, each once, at most 4; a name that is no longer one (a field deleted since) is left out, so the Blueprint still loads |
| `SYSTEM_CAPABILITIES` | The capabilities such a Blueprint cannot have: `identifiable`, `authenticatable`, `media_file`, `submitted`, `keyed`, `link` |
| `REQUIRED_CAPABILITY`, `MAX_LABEL`, `MAX_FIELDS`, `VERSION` | `titled`; 100 characters; 60 fields and relations; 1 |

The choices are a list of key–label pairs, not a JSON object: MySQL stores an
object with its keys sorted, so their order would be lost.

Everything read from the database is checked again: a definition that does not
pass (e.g. changed by hand) is not loaded.

### On the registry

| Member | Description |
|---|---|
| `loadStored(array $stored): void` | Defines the stored Blueprints; one that does not pass, or has the name of one of the file's, is left out |
| `isEditable(string $name): bool` | Made in the admin (so it can be edited there) |
| `storedErrors(): array<string, string>` | The stored ones left out, with the reason (shown on the Blueprints page) |
| `RESERVED_FIELD_NAMES` | `id`, `uuid`, `blueprint`, `created`, `updated`, `object_id`: no own field may have these names (any Blueprint's, since 0.2.3) |

## Known limitation (0.0.1)

An object's capability list is stored per object (`cc_object_capabilities`).
If you later extend a Blueprint's capability list, it only affects objects
created afterwards; existing ones are loaded with their old capability list.
Migrations will handle this.
