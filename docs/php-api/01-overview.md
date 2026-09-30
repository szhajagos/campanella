# 1. Overview

## The core idea

Campanella has no predefined content types (article, page, media…) as PHP
classes. There is a single generic object, `CampanellaObject`, and what it can
do is decided by the **capabilities** attached to it. A "type" is a named
bundle of capabilities, the **Blueprint**, which is configuration, not code.

```
Blueprint "article" = Titled + Textual + Routable + Publishable  (+ custom field: lead)
```

## Layers (MVC + Service)

```
HTTP request
  → Router                       which controller?
  → Controller                   thin: parameters in, response out
  → Service / QueryEngine        business logic, access control
  → ObjectRepository             loading, saving (Model)
  → Controller
  → Presentation + Twig          rendering (View)
  → HTTP response
```

| Layer | Main classes | Chapter |
|---|---|---|
| Model | `CampanellaObject`, `Capability`, `Blueprint`, `ObjectRepository`, `Query`, `QueryEngine`, `AccessPolicy`, `Relation`, `RelationLoader` | 2–6, 10 |
| Service | `ObjectService` | 6 |
| Controller | `ObjectController`, `QueryController` | 7 |
| View | `Presentation`, `CampanellaTwigExtension`, templates | 7 |
| Infrastructure | `Kernel`, `Container`, `Connection`, `Router`, CLI | 7–9 |

## Principles the API is built on

**Data Mapper.** An object does not save itself. There is no `$object->save()`;
use `ObjectRepository::save($object)` instead or, with access control checks,
`ObjectService::update(...)`.

**Capability as an adapter.** A capability's behavior is reached through the
object:

```php
$object->as(Publishable::class)->publish();
```

**Relations.** An object's relations (e.g. article → categories) are
definitions, like fields, provided by a capability or a Blueprint. The template
receives the related objects already loaded; see [10. Relations](10-relations.md).

**Unique field names.** Every field name is unique across the system, so an
object's fields are simply accessed by name: `$object->get('title')`.

**Storage rule.** A field lives either in its capability's own table
(queryable) or in the object's JSON `data` column (storage only). JSON fields
cannot be filtered or sorted on; `QueryCompiler` reports this as an error.

**No query without access control.** Every `QueryEngine` call requires an
`Actor`, and the access conditions are added to the query before the SQL is
built. What someone may not see is never even fetched from the database.

**Immutable value objects.** `Query`, `Request`, `Field`, `Blueprint`, `Actor`,
`ResultSet` and the conditions cannot be modified; `Query` methods return a new
instance.

**Time zones.** All timestamps are stored in UTC and returned as
`DateTimeImmutable`. Rendering converts them according to the `timezone`
setting (default: `Europe/Budapest`).

**SQL in one place.** SQL is only generated in `Connection`, `SchemaBuilder`
and `QueryCompiler`. The target is the common subset of MariaDB 10.6+ and
MySQL 8.0+.

## Exceptions

| Exception | When | Base class |
|---|---|---|
| `Campanella\Model\ValidationException` | Missing required field, value already taken for a unique field | `RuntimeException` |
| `Campanella\Access\AccessDeniedException` | The `Actor` may not perform the operation | `RuntimeException` |
| `Campanella\Http\HttpException` | HTTP error (e.g. 404) in the controller | `RuntimeException` |
| `Campanella\Capability\CapabilityException` | Invalid capability, Blueprint or scope definition, missing capability | `LogicException` |
| `Campanella\Query\QueryException` | Unknown or non-queryable field, invalid operator | `LogicException` |
| `OutOfBoundsException` | Reading or writing a nonexistent field or relation | (PHP) |
| `InvalidArgumentException` | Invalid value, e.g. several targets for a single relation, unsaved target object | (PHP) |
| `LogicException` | Programming error, e.g. reading a relation that was not loaded (`relatedObjects()`) | (PHP) |

Subclasses of `LogicException` signal programming errors: fix them, do not
catch them. Subclasses of `RuntimeException` can arise at runtime from data;
the caller handles them (e.g. by showing an error message).
