# 6. Services

## ObjectService

`Campanella\Service\ObjectService` · **Public** · `final class` · container: `ObjectService::class`

The business logic of operations on objects. Controllers, the CLI and, later,
the API and Webform all call it, so access control checks (and, later,
dispatching Events) happen in one place. **Always use it for modifying
operations**, not the repository directly.

| Method | Checked operation | Description |
|---|---|---|
| `create(Actor $actor, string $blueprint, array $values, bool $publish = false): CampanellaObject` | `Create` (and `Publish`, if requested) | Creates and saves the object. With `$publish: true` it also publishes it immediately, with the current time |
| `update(Actor $actor, CampanellaObject $object, array $values): void` | `Update` | Sets the values and saves |
| `publish(Actor $actor, CampanellaObject $object, ?DateTimeImmutable $at = null): void` | `Publish` | See `Publishable::publish()`. A future `$at` = scheduled publishing |
| `unpublish(Actor $actor, CampanellaObject $object): void` | `Unpublish` | Back to draft |
| `delete(Actor $actor, CampanellaObject $object): void` | `Delete` | Deletes the object and all of its capability data |

Errors: `AccessDeniedException` if the policy denies the operation;
`ValidationException` if the save is invalid; `OutOfBoundsException` if you
passed an unknown field; `CapabilityException` if the object has no
`Publishable` capability when calling `publish()`/`unpublish()`.

```php
$service = $container->get(ObjectService::class);

$draft = $service->create($actor, 'article', ['title' => 'Scheduled news item', 'body' => '…']);
$service->publish($actor, $draft, new DateTimeImmutable('2026-10-01 08:00', new DateTimeZone('Europe/Budapest')));
```

## ObjectRepository

`Campanella\Model\ObjectRepository` · **Public** · `final class` · container: `ObjectRepository::class`

Loading and saving objects (Data Mapper). **It does not check access
control**: use the `QueryEngine` for reading and the `ObjectService` for
writing.

| Method | Description |
|---|---|
| `create(string $blueprint, array $values = []): CampanellaObject` | A new, not yet saved object with the Blueprint's capabilities and default values. `OutOfBoundsException` for an unknown field, `CapabilityException` for an unknown Blueprint |
| `find(int $id): ?CampanellaObject` | |
| `findByUuid(string $uuid): ?CampanellaObject` | |
| `loadMany(array $ids): array<int, CampanellaObject>` | Several objects at once, in the input order, keyed by ID. A single query runs per capability table, plus one for relations |
| `save(CampanellaObject $object): void` | Save (see below) |
| `delete(CampanellaObject $object): void` | Delete; the database removes the capability rows by cascade. Does nothing for an unsaved object |

### Save steps

1. Every capability's `prepareForSave()` method runs, in dependency order.
2. Validation: required fields and relations (whether required, whether the
   target exists, whether it matches the definition). On failure:
   `ValidationException`.
3. In a single transaction: the `objects` row (the `Data` fields as JSON), the
   `object_capabilities` rows, the capability table rows and the relations.
4. On a unique value conflict (e.g. a path already in use) the transaction is
   rolled back and a `ValidationException` is thrown; no half-saved object is
   left in the database.
5. The object receives its ID and the `updated` time.

If the database contains a capability the system no longer knows (e.g. from a
removed module), the object does not get it on load, but its data is kept.

## ValidationException

`Campanella\Model\ValidationException` · **Public** · `RuntimeException`

| Member | Description |
|---|---|
| `__construct(array $errors)` | |
| `$errors` | `array<string, string>`: field name → error message |

```php
try {
    $service->create($actor, 'article', ['body' => 'without a title']);
} catch (ValidationException $e) {
    $e->errors;   // ['title' => 'kötelező mező'] ("required field")
}
```
