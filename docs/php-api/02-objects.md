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
    label: 'Publikálás ideje',
    hidden: false,                 // true: not available from templates as {{ object.field }}
    cardinality: 1,                // number of values: 1, a limit (e.g. 3) or Field::UNLIMITED (since 0.0.4)
);
```

| Method | Description |
|---|---|
| `asData(): self` | The same field with `FieldStorage::Data` storage (without index and uniqueness) |
| `withCardinality(int $cardinality): self` | The same field with a lower value limit; used by a Blueprint to narrow a capability field (see below) |
| `isQueryable(): bool` | True if `FieldStorage::Table`, i.e. it can be filtered on (and, if single-valued, sorted on) |
| `isMultiple(): bool` | True if the cardinality is not 1 |
| `isUnlimited(): bool` | True for `Field::UNLIMITED` |
| `usesValueTable(): bool` | A multi-valued `Table` field: its values live in the `field_values` table |
| `cast(mixed $value): mixed` | Uniform PHP value; for a multi-valued field always a list (see below) |
| `toStorage(mixed $value): mixed` | Storable form; for a multi-valued field a list of the items' storable forms |
| `fromStorage(mixed $value): mixed` | Back to a PHP value |
| `exceedsCardinality(mixed $value): bool` | Whether a list has more values than the limit |
| `hasTooLongItem(mixed $value): bool` | For a multi-valued `String`: whether an item is longer than `length` |
| `isEmpty(mixed $value): bool` | `null`, empty string or empty list; the required-field check uses this |

The constructor throws `InvalidArgumentException` for an invalid name, if a
field with `Data` storage would be indexed or unique, and for the invalid
multi-valued combinations listed below.

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
- **Not allowed:** `unique`; a multi-valued `StringList`; a queryable
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
| `StringList` | `list<string>` (without duplicates and empty items) | `MEDIUMTEXT`, as a JSON array (since 0.0.3) |

| Method | Description |
|---|---|
| `cast(mixed $value): mixed` | Converts to a uniform PHP value. For a `BackedEnum` it takes its value (so `PublishStatus::Published` can be passed too). For dates it also accepts a string, interpreted as UTC |
| `toStorage(mixed $value): string\|int\|null` | A form that can be written to the database or JSON; date: `Y-m-d H:i:s` UTC |
| `fromStorage(mixed $value): mixed` | Back to a PHP value |
| `columnType(): ColumnType` | The matching column type |
| `valueColumn(): string` | The `field_values` column holding the values of a multi-valued field of this type: `value_string`, `value_text`, `value_int` (Integer, Boolean) or `value_datetime`; `LogicException` for `StringList` |

The `STORAGE_DATE_FORMAT` constant (`'Y-m-d H:i:s'`) is the format of stored
dates.

## FieldStorage

`Campanella\Model\FieldStorage` · **Public** · `enum`

| Case | Where the value lives | Filterable, sortable |
|---|---|---|
| `Table` | In the capability's own table (`cc_cap_<name>`); a multi-valued field in the shared `cc_field_values` table | yes (multi-valued: filterable only) |
| `Data` | In the object's `data` JSON column (a multi-valued field as a list) | no |

**Rule:** whatever needs to be filtered or sorted on is `Table`. Everything
else can go into `Data`. If a `Data` field later needs to be filtered on after
all, it has to be promoted to a capability field.

## Blueprint

`Campanella\Model\Blueprint` · **Public** · `final readonly class`

A named bundle of capabilities. Defined in the `config/blueprints.php` file:

```php
return [
    'article' => [
        'label' => 'Cikk',
        'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class],
        'fields' => [
            new Field('lead', FieldType::Text, label: 'Bevezető'),
        ],
        // Optional (since 0.0.4): narrow the value limit of a capability's multi-valued field.
        // 'cardinality' => ['phones' => 2],
    ],
];
```

| Member | Description |
|---|---|
| `$name`, `$label` | Name (`^[a-z][a-z0-9_]{0,62}$`) and label |
| `$capabilities` | `array<string, CapabilityDefinition>`, completed with the dependencies |
| `$fields` | Only the Blueprint's own fields; always stored as `FieldStorage::Data` |
| `$relations` | Only the Blueprint's own relations (`array<string, Relation>`) |
| `$lists` | Lists shown on the object's page ([chapter 10](10-relations.md#lists-on-the-objects-page)) |
| `$narrowed` | The capability fields whose cardinality this Blueprint narrows (`array<string, Field>`) |
| `allFields()` | The capability fields (narrowed where configured) and the own fields together |
| `narrow(array $fields): array` | Applies the narrowed cardinalities to the given field definitions |
| `allRelations()` | The capability relations and the own relations together |

Dependencies need not be listed: because of `Routable`, the `page` Blueprint
automatically gets `Titled` too.

**Narrowing the cardinality** (`'cardinality' => ['phones' => 2]`): the field
must be a multi-valued field of one of the Blueprint's capabilities, and the new
limit must be at least 2 and not higher than the capability's limit (any value
if it is unlimited). The Blueprint's own fields set their cardinality on the
`Field` itself. Violations throw `CapabilityException` when the Blueprint is
defined.

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

`define()` throws `CapabilityException` for an invalid name, an unknown
capability, if the name of an own field or relation collides with a field
or relation of a capability, and for an invalid `cardinality` entry.

## Known limitation (0.0.1)

An object's capability list is stored per object (`cc_object_capabilities`).
If you later extend a Blueprint's capability list, it only affects objects
created afterwards; existing ones are loaded with their old capability list.
Migrations will handle this.
