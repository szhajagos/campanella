# 6. Services

## ObjectService

`Campanella\Service\ObjectService` · **Public** · `final class` · container: `ObjectService::class`

The business logic of operations on objects. Controllers, the CLI and, later,
the API and Webform all call it, so access control checks (and, later,
dispatching Events) happen in one place. **Always use it for modifying
operations**, not the repository directly.

| Method | Checked operation | Description |
|---|---|---|
| `create(Actor $actor, string $blueprint, array $values, bool $publish = false, array $relations = []): CampanellaObject` | `Create` (and `Publish`, if requested) | Creates and saves the object. With `$publish: true` it also publishes it immediately, with the current time. `$relations`: relation name → target IDs (since 0.0.4) |
| `update(Actor $actor, CampanellaObject $object, array $values, array $relations = []): void` | `Update` | Sets the values (and the given relations; the others stay as they are) and saves |
| `publish(Actor $actor, CampanellaObject $object, ?DateTimeImmutable $at = null): void` | `Publish` | See `Publishable::publish()`. A future `$at` = scheduled publishing |
| `unpublish(Actor $actor, CampanellaObject $object): void` | `Unpublish` | Back to draft |
| `delete(Actor $actor, CampanellaObject $object): void` | `Delete` | Deletes the object and all of its capability data |

Errors: `AccessDeniedException` if the policy denies the operation;
`ValidationException` if the save is invalid; `OutOfBoundsException` if you
passed an unknown field; `CapabilityException` if the object has no
`Publishable` capability when calling `publish()`/`unpublish()`.

```php
$service = $container->get(ObjectService::class);

// Articles have an HTML body by default (Blueprint 'defaults'); it is filtered on save.
$draft = $service->create($actor, 'article', ['title' => 'Scheduled news item', 'body' => '<p>…</p>']);
// A plain text body: 'format' => 'plain', or PlainText::toHtml($text) as the body.
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
| `__construct(Connection $db, CapabilityRegistry $capabilities, BlueprintRegistry $blueprints, ?HtmlSanitizer $html = null)` | `$html`: the HTML filter (since 0.0.5); null: the built-in allowlist |
| `save(CampanellaObject $object): void` | Save (see below) |
| `delete(CampanellaObject $object): void` | Delete; the database removes the capability rows by cascade. Does nothing for an unsaved object |

### Save steps

1. Every capability's `prepareForSave()` method runs, in dependency order.
2. A `Textual` body in `html` format is filtered with the allowlist
   (`HtmlSanitizer`, [chapter 15](15-html.md)); one longer than the limit is a
   validation error instead.
3. Validation: required fields and relations (whether required, whether the
   target exists, whether it matches the definition). On failure:
   `ValidationException`.
4. In a single transaction: the `objects` row (the `Data` fields as JSON), the
   `object_capabilities` rows, the capability table rows and the relations.
5. On a unique value conflict (e.g. a path already in use) the transaction is
   rolled back and a `ValidationException` is thrown; no half-saved object is
   left in the database.
6. The object receives its ID and the `updated` time.

If the database contains a capability the system no longer knows (e.g. from a
removed module), the object does not get it on load, but its data is kept.

## ValidationException

`Campanella\Model\ValidationException` · **Public** · `RuntimeException`

| Member | Description |
|---|---|
| `__construct(array $errors)` | Field name → `Message`, or a message key string (a ready-made text from custom code also works: it is shown as it is) |
| `$errors` | `array<string, Message>`: field name → untranslated message (key and parameters) |
| `messages(Translator $translator): array<string, string>` | The messages as text in the translator's language |
| `getMessage()` | A developer-facing summary with the keys, e.g. `Invalid object: title: validation.required` |

```php
try {
    $service->create($actor, 'article', ['body' => 'without a title']);
} catch (ValidationException $e) {
    $e->errors['title']->key;      // 'validation.required'
    $e->messages($translator);     // ['title' => 'kötelező mező'] with the hu locale ("required field")
}
```

The message keys are listed in `lang/en.php` under `validation.` (since 0.0.4;
see [chapter 12](12-translation.md#in-php-code)).
