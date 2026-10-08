# 3. Capabilities

## The contract

`Campanella\Capability\Capability` · **Public** · `abstract class`

A capability gives the system two things.

**1. A static description** from which the system builds schema and queries:

| Element | Required | Description |
|---|---|---|
| `#[AsCapability(...)]` attribute | yes | Name, dependencies, label |
| `static fields(): list<Field>` | yes | Which fields it brings, and where they are stored |
| `static relations(): list<Relation>` | no | Which relations it brings ([chapter 10](10-relations.md)) |
| `static scopes(): array<string, Closure(Query): Query>` | no | Named query filters |

**2. Behavior on a concrete object.** A capability is an adapter that wraps the
object; `CampanellaObject::as()` creates it:

| Element | Description |
|---|---|
| `final __construct(CampanellaObject $object)` | The constructor is final; a capability cannot have dependencies of its own |
| `protected readonly CampanellaObject $object` | The wrapped object |
| `prepareForSave(): void` | Runs before saving, in dependency order. This is where derived values are filled in and values are normalized |
| `validate(): array<string, Message\|string>` | Runs after `prepareForSave()`; the capability's own rules (e.g. e-mail format). Field name → message (a `Message` with a key from `lang/`, or a key string); the errors become a `ValidationException` (since 0.0.3; messages since 0.0.4) |
| `object(): CampanellaObject` | The wrapped object |

A capability does not write to the database: it sets values on the object
(`$this->object->set(...)`), and `ObjectRepository` does the saving.

## AsCapability

`Campanella\Capability\AsCapability` · **Public** · attribute

```php
#[AsCapability('routable', requires: [Titled::class], label: 'capability.routable')]
```

| Parameter | Description |
|---|---|
| `string $name` | Unique across the system, `^[a-z][a-z0-9_]{0,40}$`. It is stored in the database and determines the table name (`cap_<name>`), so do not rename it later |
| `list<class-string<Capability>> $requires` | Capabilities it cannot work without. These are added to the object automatically |
| `string $label` | Human-readable label; defaults to the name with an uppercase first letter |

## CapabilityDefinition

`Campanella\Capability\CapabilityDefinition` · **Public** · `final readonly class`

The processed description of a capability. Created by `CapabilityRegistry`.

| Member | Description |
|---|---|
| `$name`, `$class`, `$label` | Name, class name, label |
| `$requires` | Class names of the dependencies |
| `$fields` | `array<string, Field>` |
| `$relations` | `array<string, Relation>` |
| `static fromClass(string $class): self` | Reads the attribute and the fields. `CapabilityException` if the class is not a `Capability`, has no attribute, the name is invalid, or a queryable multi-valued `String` field is longer than 255 characters |
| `tableName(): string` | `'cap_' . $name` (without prefix) |
| `tableFields()` / `dataFields()` | The single-valued fields with `Table` storage (the columns of `cc_cap_<name>`), and the fields with `Data` storage, respectively |
| `valueTableFields()` | The multi-valued fields with `Table` storage, stored in `cc_field_values` (since 0.0.4) |
| `hasTable(): bool` | Whether there is at least one single-valued `Table` field, i.e. whether an own table is needed (a capability with only multi-valued or `Data` fields has none) |
| `table(): ?Table` | The schema of the own table derived from the fields, or `null` |

The table: `object_id` primary key (foreign key to the `objects` table, cascade
on delete), one column per field. A column allows `NULL` if the field is not
required. An `indexed` field gets an index, a `unique` field a unique index.

## CapabilityRegistry

`Campanella\Capability\CapabilityRegistry` · **Public** · `final class`

The list of capabilities known to the system. Built from the `capabilities`
key of `config/app.php`.

| Method | Description |
|---|---|
| `__construct(array $classes = [])` | A list of class names |
| `register(string $class): CapabilityDefinition` | Registers a capability. `CapabilityException` if the name, or a field, relation or scope name, is already taken |
| `get(string $nameOrClass): CapabilityDefinition` | By name or class name; `CapabilityException` if unknown |
| `has(string $nameOrClass): bool` | |
| `all(): array<string, CapabilityDefinition>` | |
| `resolve(iterable $namesOrClasses): array` | The list completed with the dependencies, in dependency order. `CapabilityException` on circular dependencies |
| `fieldOwner(string $field): ?CapabilityDefinition` | Which capability the field belongs to |
| `field(string $field): ?Field` | The definition of the field |
| `relationOwner(string $relation): ?CapabilityDefinition` | Which capability the relation belongs to |
| `scope(string $name): Closure` | A named filter; `CapabilityException` if unknown |

```php
array_keys($registry->resolve([Routable::class]));   // ['titled', 'routable']
```

## Built-in capabilities

### Titled

`Campanella\Capability\Titled` · name: `titled` · table: `cap_titled`

| Field | Type | Storage | |
|---|---|---|---|
| `title` | String | Table | required, indexed |

| Method | Description |
|---|---|
| `title(): string` | |
| `setTitle(string $title): void` | Trims leading and trailing whitespace |

### Textual

`Campanella\Capability\Textual` · name: `textual` · no own table

| Field | Type | Storage | |
|---|---|---|---|
| `body` | Text | Data | |
| `format` | String | Data | default: `plain` |

| Method | Description |
|---|---|
| `body(): string` | |
| `format(): TextFormat` | `Plain` for an unknown value |
| `setBody(string $body, TextFormat $format = TextFormat::Plain): void` | |

`TextFormat` (`enum: string`): `Plain = 'plain'` (escaped when rendered, split
into paragraphs), `Html = 'html'` (filtered with an allowlist on every save,
then rendered as stored; [chapter 15](15-html.md)).

### Routable

`Campanella\Capability\Routable` · name: `routable` · table: `cap_routable` · depends on: `Titled`

| Field | Type | Storage | |
|---|---|---|---|
| `path` | String | Table | required, unique |

| Method | Description |
|---|---|
| `route(): string` | The path, e.g. `/neumann-janos` |
| `setPath(string $path): void` | Sets it normalized |
| `prepareForSave(): void` | If the path is empty, creates one from the title (`'/' . Slugger::slugify($title)`), then normalizes it |
| `static normalize(string $path): string` | Starts with a slash, does not end with a slash, lowercase, no empty segments: `' Hírek//2026/ '` → `'/hírek/2026'` |

Saving a path that is already taken raises a `ValidationException`
(`path: ez az érték már foglalt`, "this value is already taken").

Since 0.1.0 a path may not contain backslashes, whitespace, control
characters, `?` or `#` (`static isSafePath(string $path): bool`;
`validation.invalid_path`): a browser would read `/\evil.com` as another site.

### Publishable

`Campanella\Capability\Publishable` · name: `publishable` · table: `cap_publishable`

| Field | Type | Storage | |
|---|---|---|---|
| `status` | String | Table | required, indexed, default: `draft` |
| `published_at` | DateTime | Table | indexed |

| Method | Description |
|---|---|
| `status(): PublishStatus` | |
| `publishedAt(): ?DateTimeImmutable` | |
| `isPublished(?DateTimeImmutable $now = null): bool` | Status is `published`, and `published_at` is not later than the given time (default: now) |
| `publish(?DateTimeImmutable $at = null): void` | Status: `published`. Time: the given one, otherwise the previous one, otherwise now |
| `unpublish(): void` | Status: `draft`; `published_at` is kept |

Scope: `published`, i.e. `status = published` and `published_at <= now`.

`PublishStatus` (`enum: string`): `Draft = 'draft'`, `Published = 'published'`.

**Scheduled publishing:** an object published with a future time is not
visible until that time comes, and then appears on its own.

### User capabilities

`Identifiable`, `Authenticatable` and `Authorable` are described in chapter
[11. Users and login](11-users.md#capabilities).

### MediaFile

A stored file (e.g. an uploaded image): path, type, size, dimensions,
checksum, in its own table. Since 0.0.5; described in chapter
[16. Images](16-media.md#the-image-blueprint-and-the-mediafile-capability).

### Weighted

`Campanella\Capability\Weighted` · name: `weighted` · table: `cap_weighted` (since 0.0.7)

A hand-set order: a lighter object comes first.

| Field | Type | Storage | |
|---|---|---|---|
| `weight` | Integer | Table | required, indexed, default: `0` |

| Method | Description |
|---|---|
| `weight(): int`, `setWeight(int $weight): void` | |

Scope: `by_weight`, i.e. by `weight`, then by `id` (equal weights keep the
order of creation). In the admin, the list of a Blueprint with this capability
is shown in this order by default, with a *Weight* column.

### Hierarchical

A node of a tree: a `parent` relation within the same Blueprint, and the
materialized path kept by the repository. Since 0.0.7; described in chapter
[18. Trees](18-trees.md).

### Keyed and Link

`Keyed` (`keyed`): a unique machine name (`machine_name`), by which templates
find an object, e.g. the menu `main`. `Link` (`link`): the object leads to an
object of the site (`target`) or to a safe URL (`url`). Since 0.0.7; described
in chapter [19. Menus](19-menus.md#link).

## Writing a new capability

Example: a `Featured` capability that marks objects as featured, so that a
list can show only those.

### 1. The class

```php
<?php

declare(strict_types=1);

namespace App\Capability;

use Campanella\Capability\AsCapability;
use Campanella\Capability\Capability;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;

#[AsCapability('featured', label: 'Featured')]
final class Featured extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            // We filter on it, so it is an own-table (Table) and indexed field.
            new Field('featured', FieldType::Boolean, required: true, default: false, indexed: true, label: 'Featured'),
        ];
    }

    #[\Override]
    public static function scopes(): array
    {
        return [
            'featured' => static fn (Query $q): Query => $q->where('featured', '=', true),
        ];
    }

    public function isFeatured(): bool
    {
        return (bool) $this->object->get('featured');
    }

    public function feature(bool $featured = true): void
    {
        $this->object->set('featured', $featured);
    }
}
```

### 2. Registration

`config/app.php`:

```php
'capabilities' => [
    Titled::class, Textual::class, Routable::class, Publishable::class,
    App\Capability\Featured::class,
],
```

### 3. Creating the table

```bash
php bin/campanella install      # creates the cc_cap_featured table
```

Later changes of the capability (a new field) are applied the same way
([chapter 17](17-migrations.md#applying-the-definitions-schemasync)).

### 4. Usage

In a Blueprint (`config/blueprints.php`):

```php
'page' => [
    'capabilities' => [Textual::class, Routable::class, Publishable::class, Featured::class],
],
```

The existing pages get the capability (with `featured = false`) on the next
`install`. In code and in queries:

```php
$page->as(Featured::class)->feature();

$pages = $queries->execute(
    Query::objects()->having('featured')->scope('featured'),
    $actor,
);
```

### Checklist

- The name and the field, relation and scope names must be unique across the whole system.
- Whatever needs filtering or sorting: `FieldStorage::Table`. Everything else: `Data`.
- A capability must not contain database access or external services.
  Anything that needs them belongs in the Service layer.
- `prepareForSave()` may only work from the object's own values.
- Labels and a short explanation in the language files: `capability.<name>`,
  `capability.<name>.description` (shown on the admin's Capabilities page,
  since 0.1.0), and `field.<name>` for its fields.
