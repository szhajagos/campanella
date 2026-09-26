# 6. Szolgáltatások és tárolás

## ObjectService

`Campanella\Service\ObjectService` · **Nyilvános** · `final class` · konténer: `ObjectService::class`

Az objektumokon végzett műveletek üzleti logikája. A controllerek, a CLI és
később az API és a Webform mind ezt hívják, így a jogosultság-ellenőrzés
(és később az Eventek kiváltása) egy helyen történik. **Módosító műveletekhez
mindig ezt használd**, ne közvetlenül a repository-t.

| Metódus | Ellenőrzött művelet | Leírás |
|---|---|---|
| `create(Actor $actor, string $blueprint, array $values, bool $publish = false): CampanellaObject` | `Create` (és `Publish`, ha kérted) | Létrehozza és elmenti. `$publish: true` esetén azonnal publikálja is, a mostani időponttal |
| `update(Actor $actor, CampanellaObject $object, array $values): void` | `Update` | Beállítja az értékeket és elment |
| `publish(Actor $actor, CampanellaObject $object, ?DateTimeImmutable $at = null): void` | `Publish` | Lásd `Publishable::publish()`. Jövőbeli `$at` = időzített publikálás |
| `unpublish(Actor $actor, CampanellaObject $object): void` | `Unpublish` | Vissza piszkozatba |
| `delete(Actor $actor, CampanellaObject $object): void` | `Delete` | Törli az objektumot és minden capability-adatát |

Hibák: `AccessDeniedException`, ha a szabály tilt; `ValidationException`, ha a
mentés érvénytelen; `OutOfBoundsException`, ha ismeretlen mezőt adtál meg;
`CapabilityException`, ha az objektumnak nincs `Publishable` capability-je a
`publish()`/`unpublish()` hívásakor.

```php
$service = $container->get(ObjectService::class);

$draft = $service->create($actor, 'article', ['title' => 'Időzített hír', 'body' => '…']);
$service->publish($actor, $draft, new DateTimeImmutable('2026-10-01 08:00', new DateTimeZone('Europe/Budapest')));
```

## ObjectRepository

`Campanella\Model\ObjectRepository` · **Nyilvános** · `final class` · konténer: `ObjectRepository::class`

Az objektumok betöltése és mentése (Data Mapper). **Nem ellenőriz
jogosultságot**: olvasáshoz a `QueryEngine`-t, íráshoz az `ObjectService`-t
érdemes használni.

| Metódus | Leírás |
|---|---|
| `create(string $blueprint, array $values = []): CampanellaObject` | Új, még el nem mentett objektum a Blueprint capability-ivel, alapértékekkel. `OutOfBoundsException` ismeretlen mezőre, `CapabilityException` ismeretlen Blueprintre |
| `find(int $id): ?CampanellaObject` | |
| `findByUuid(string $uuid): ?CampanellaObject` | |
| `loadMany(array $ids): array<int, CampanellaObject>` | Több objektum egyszerre, a bemenet sorrendjében, azonosító szerint kulcsolva. Capability-táblánként egyetlen lekérdezés fut |
| `save(CampanellaObject $object): void` | Mentés (lásd lent) |
| `delete(CampanellaObject $object): void` | Törlés; a capability-sorokat az adatbázis kaszkádolva törli. Mentetlen objektumnál nem csinál semmit |

### A mentés lépései

1. Minden capability `prepareForSave()` metódusa lefut, függőségi sorrendben.
2. Kötelező mezők ellenőrzése: hiány esetén `ValidationException`.
3. Egy tranzakcióban: az `objects` sor (a `Data` mezők JSON-ként), az
   `object_capabilities` sorok és a capability-táblák sorai.
4. Egyedi érték ütközésekor (pl. foglalt útvonal) a tranzakció visszagördül, és
   `ValidationException` keletkezik; félkész objektum nem marad az adatbázisban.
5. Az objektum megkapja az azonosítóját és az `updated` időpontot.

Ha az adatbázisban olyan capability szerepel, amelyet a rendszer már nem ismer
(pl. eltávolított modul), betöltéskor az objektum nem kapja meg, de az adata
megmarad.

## ValidationException

`Campanella\Model\ValidationException` · **Nyilvános** · `RuntimeException`

| Tag | Leírás |
|---|---|
| `__construct(array $errors)` | |
| `$errors` | `array<string, string>`: mezőnév → hibaüzenet |

```php
try {
    $service->create($actor, 'article', ['body' => 'cím nélkül']);
} catch (ValidationException $e) {
    $e->errors;   // ['title' => 'kötelező mező']
}
```
