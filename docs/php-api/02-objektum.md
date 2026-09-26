# 2. Objektum, mező, Blueprint

## CampanellaObject

`Campanella\Model\CampanellaObject` · **Nyilvános** · `final class`

Az általános objektum. Nincsenek alosztályai; a viselkedését a capability-jei
adják. Új objektumot az `ObjectRepository::create()` vagy az
`ObjectService::create()` készít, betölteni az `ObjectRepository` vagy a
`QueryEngine` tud. A konstruktort közvetlenül nem érdemes hívni.

### Identitás

| Metódus | Visszatérés | Leírás |
|---|---|---|
| `id()` | `?int` | Adatbázis-azonosító; mentés előtt `null` |
| `uuid()` | `string` | UUID v7, már létrehozáskor megvan; kifelé ezt érdemes használni |
| `blueprint()` | `string` | A Blueprint neve, pl. `'article'` |
| `created()` | `DateTimeImmutable` | Létrehozás ideje (UTC) |
| `updated()` | `DateTimeImmutable` | Utolsó mentés ideje (UTC) |
| `isNew()` | `bool` | Igaz, ha még nincs elmentve |

### Capability-k

| Metódus | Visszatérés | Leírás |
|---|---|---|
| `has(string $capability)` | `bool` | Rendelkezik-e vele; név (`'publishable'`) vagy osztálynév (`Publishable::class`) is megadható |
| `as(string $class)` | a capability osztálya | Az objektum a capability „szemüvegén” át. `CapabilityException`, ha nincs ilyen capability-je |
| `capabilityNames()` | `list<string>` | A capability-k nevei függőségi sorrendben |
| `capabilities()` | `array<string, CapabilityDefinition>` | Ugyanez definíciókkal |

```php
if ($object->has(Publishable::class)) {
    $object->as(Publishable::class)->publish();
}
```

Az `as()` ugyanahhoz a capability-hez mindig ugyanazt az adapterpéldányt adja
vissza.

### Mezők

| Metódus | Leírás |
|---|---|
| `get(string $field): mixed` | Mező értéke. `OutOfBoundsException`, ha nincs ilyen mező |
| `set(string $field, mixed $value): void` | Érték beállítása a mező típusára alakítva (`FieldType::cast`). `OutOfBoundsException`, ha nincs ilyen mező |
| `fill(array $values): void` | Több mező egyszerre |
| `hasField(string $field): bool` | Van-e ilyen mezője |
| `fields(): array<string, Field>` | Az összes mezőjének definíciója |
| `values(): array<string, mixed>` | Az összes érték |

A beállítás csak memóriában történik; az adatbázisba a mentés viszi.

### Sablonokból

A `__get()` és `__isset()` csak olvasható hozzáférést ad, hogy a Twig-sablonok
egyszerűen írhassák: `{{ object.title }}`, `{{ object.published_at|date }}`.
Az `id`, `uuid`, `blueprint`, `created` és `updated` is így érhető el. PHP-kódban
a `get()` az ajánlott, mert az hibát jelez elírt mezőnévre.

### Belső

`markSaved(int $id, DateTimeImmutable $updated)`: csak az `ObjectRepository`
hívja mentés után. **Belső.**

## Field

`Campanella\Model\Field` · **Nyilvános** · `final readonly class`

Egy mező definíciója. A capability-k a `fields()` metódusukban, a Blueprintek a
`fields` kulcs alatt adják meg.

```php
new Field(
    name: 'published_at',          // ^[a-z][a-z0-9_]{0,62}$, rendszerszinten egyedi
    type: FieldType::DateTime,
    storage: FieldStorage::Table,  // alapértelmezés
    required: false,
    default: null,
    indexed: true,                 // index a capability táblájában
    unique: false,                 // egyedi index (pl. útvonal)
    length: 255,                   // String típusnál a VARCHAR hossza
    label: 'Publikálás ideje',
);
```

| Metódus | Leírás |
|---|---|
| `asData(): self` | Ugyanez a mező `FieldStorage::Data` tárolással (index és egyediség nélkül) |
| `isQueryable(): bool` | Igaz, ha `FieldStorage::Table`, vagyis szűrhető és rendezhető |
| `isEmpty(mixed $value): bool` | `null` vagy üres szöveg; a kötelező mezők ellenőrzése ezt használja |

A konstruktor `InvalidArgumentException`-t dob hibás névre, és ha egy `Data`
tárolású mező indexelt vagy egyedi lenne.

## FieldType

`Campanella\Model\FieldType` · **Nyilvános** · `enum: string`

| Eset | PHP-érték | Oszlop |
|---|---|---|
| `String` | `string` | `VARCHAR(length)` |
| `Text` | `string` | `MEDIUMTEXT` |
| `Integer` | `int` | `INT` |
| `Boolean` | `bool` | `TINYINT(1)` |
| `DateTime` | `DateTimeImmutable` (UTC) | `DATETIME` |

| Metódus | Leírás |
|---|---|
| `cast(mixed $value): mixed` | Egységes PHP-értékre alakít. `BackedEnum` esetén az értékét veszi (így `PublishStatus::Published` is megadható). Dátumnál szöveget is elfogad, UTC-ként értelmezve |
| `toStorage(mixed $value): string\|int\|null` | Adatbázisba vagy JSON-ba írható forma; dátum: `Y-m-d H:i:s` UTC |
| `fromStorage(mixed $value): mixed` | Vissza PHP-értékre |
| `columnType(): ColumnType` | A megfelelő oszloptípus |

A `STORAGE_DATE_FORMAT` konstans (`'Y-m-d H:i:s'`) a tárolt dátumok formátuma.

## FieldStorage

`Campanella\Model\FieldStorage` · **Nyilvános** · `enum`

| Eset | Hol él az érték | Szűrhető, rendezhető |
|---|---|---|
| `Table` | A capability saját táblájában (`cc_cap_<név>`) | igen |
| `Data` | Az objektum `data` JSON oszlopában | nem |

**Szabály:** ami szerint szűrni vagy rendezni kell, az `Table`. Minden más
mehet `Data`-ba. Ha egy `Data` mezőre később mégis szűrni kell, azt
capability-mezővé kell előléptetni.

## Blueprint

`Campanella\Model\Blueprint` · **Nyilvános** · `final readonly class`

Elnevezett capability-csomag. A `config/blueprints.php` fájlban definiálódik:

```php
return [
    'article' => [
        'label' => 'Cikk',
        'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class],
        'fields' => [
            new Field('lead', FieldType::Text, label: 'Bevezető'),
        ],
    ],
];
```

| Tag | Leírás |
|---|---|
| `$name`, `$label` | Név (`^[a-z][a-z0-9_]{0,62}$`) és felirat |
| `$capabilities` | `array<string, CapabilityDefinition>`, a függőségekkel kiegészítve |
| `$fields` | Csak a Blueprint saját mezői; mindig `FieldStorage::Data` tárolásúak |
| `allFields()` | A capability-mezők és a saját mezők együtt |

A függőségeket nem kell felsorolni: a `page` Blueprint a `Routable` miatt
automatikusan megkapja a `Titled`-et is.

## BlueprintRegistry

`Campanella\Model\BlueprintRegistry` · **Nyilvános** · `final class`

| Metódus | Leírás |
|---|---|
| `__construct(CapabilityRegistry $capabilities, array $config = [])` | A `config/blueprints.php` tömbjéből épül |
| `define(string $name, array $definition): Blueprint` | Új Blueprint felvétele futásidőben |
| `get(string $name): Blueprint` | `CapabilityException`, ha nincs ilyen |
| `find(string $name): ?Blueprint` | Ugyanez, de `null`, ha nincs ilyen |
| `all(): array<string, Blueprint>` | Az összes |

A `define()` `CapabilityException`-t dob érvénytelen névre, ismeretlen
capability-re, és ha egy saját mező neve ütközik egy capability-mezővel.

## Ismert korlát (0.0.1)

Az objektum capability-listája objektumonként tárolódik
(`cc_object_capabilities`). Ha egy Blueprint capability-listáját később
bővíted, az csak az ezután létrehozott objektumokra hat; a meglévők a régi
capability-listájukkal töltődnek be. Ezt a migrációk kezelik majd.
