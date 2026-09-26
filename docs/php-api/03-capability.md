# 3. Capability

## A szerződés

`Campanella\Capability\Capability` · **Nyilvános** · `abstract class`

Egy capability két dolgot ad a rendszernek.

**1. Statikus leírás**, amiből a rendszer sémát és lekérdezést épít:

| Elem | Kötelező | Leírás |
|---|---|---|
| `#[AsCapability(...)]` attribútum | igen | Név, függőségek, felirat |
| `static fields(): list<Field>` | igen | Milyen mezőket hoz, és hol tárolódnak |
| `static scopes(): array<string, Closure(Query): Query>` | nem | Elnevezett lekérdezési szűrők |

**2. Viselkedés egy konkrét objektumon.** A capability egy adapter, amely az
objektumot csomagolja be; a `CampanellaObject::as()` hozza létre:

| Elem | Leírás |
|---|---|
| `final __construct(CampanellaObject $object)` | A konstruktor végleges; a capability-nek nem lehetnek saját függőségei |
| `protected readonly CampanellaObject $object` | A becsomagolt objektum |
| `prepareForSave(): void` | Mentés előtt fut, függőségi sorrendben. Ide kerül a származtatott értékek kitöltése és a normalizálás |
| `object(): CampanellaObject` | A becsomagolt objektum |

A capability nem ír adatbázisba: az értékeket az objektumon állítja
(`$this->object->set(...)`), a mentést az `ObjectRepository` végzi.

## AsCapability

`Campanella\Capability\AsCapability` · **Nyilvános** · attribútum

```php
#[AsCapability('routable', requires: [Titled::class], label: 'Útvonallal rendelkező')]
```

| Paraméter | Leírás |
|---|---|
| `string $name` | Rendszerszinten egyedi, `^[a-z][a-z0-9_]{0,40}$`. Ez kerül az adatbázisba, és ebből lesz a tábla neve (`cap_<név>`), ezért később nem érdemes átnevezni |
| `list<class-string<Capability>> $requires` | Mely capability-k nélkül nem működik. Ezek automatikusan az objektumra kerülnek |
| `string $label` | Emberi felirat; alapértelmezés a név nagy kezdőbetűvel |

## CapabilityDefinition

`Campanella\Capability\CapabilityDefinition` · **Nyilvános** · `final readonly class`

Egy capability feldolgozott leírása. A `CapabilityRegistry` készíti.

| Tag | Leírás |
|---|---|
| `$name`, `$class`, `$label` | Név, osztálynév, felirat |
| `$requires` | A függőségek osztálynevei |
| `$fields` | `array<string, Field>` |
| `static fromClass(string $class): self` | Beolvassa az attribútumot és a mezőket. `CapabilityException`, ha az osztály nem `Capability`, nincs rajta attribútum, vagy érvénytelen a név |
| `tableName(): string` | `'cap_' . $name` (prefix nélkül) |
| `tableFields()` / `dataFields()` | A `Table`, illetve a `Data` tárolású mezők |
| `hasTable(): bool` | Van-e legalább egy `Table` mező, vagyis kell-e saját tábla |
| `table(): ?Table` | A saját tábla sémája a mezőkből levezetve, vagy `null` |

A tábla: `object_id` elsődleges kulcs (idegen kulcs az `objects` táblára, törléskor
kaszkád), mezőnként egy oszlop. Az oszlop `NULL`-t enged, ha a mező nem kötelező.
Az `indexed` mező indexet, az `unique` mező egyedi indexet kap.

## CapabilityRegistry

`Campanella\Capability\CapabilityRegistry` · **Nyilvános** · `final class`

A rendszerben ismert capability-k listája. A `config/app.php` `capabilities`
kulcsából épül.

| Metódus | Leírás |
|---|---|
| `__construct(array $classes = [])` | Osztálynevek listája |
| `register(string $class): CapabilityDefinition` | Felvétel. `CapabilityException`, ha a név, egy mezőnév vagy egy scope-név már foglalt |
| `get(string $nameOrClass): CapabilityDefinition` | Név vagy osztálynév szerint; `CapabilityException`, ha ismeretlen |
| `has(string $nameOrClass): bool` | |
| `all(): array<string, CapabilityDefinition>` | |
| `resolve(iterable $namesOrClasses): array` | A lista kiegészítve a függőségekkel, függőségi sorrendben. Körkörös függőségnél `CapabilityException` |
| `fieldOwner(string $field): ?CapabilityDefinition` | Melyik capability-hez tartozik a mező |
| `field(string $field): ?Field` | A mező definíciója |
| `scope(string $name): Closure` | Egy elnevezett szűrő; `CapabilityException`, ha ismeretlen |

```php
array_keys($registry->resolve([Routable::class]));   // ['titled', 'routable']
```

## Beépített capability-k

### Titled

`Campanella\Capability\Titled` · név: `titled` · tábla: `cap_titled`

| Mező | Típus | Tárolás | |
|---|---|---|---|
| `title` | String | Table | kötelező, indexelt |

| Metódus | Leírás |
|---|---|
| `title(): string` | |
| `setTitle(string $title): void` | A szélső szóközöket levágja |

### Textual

`Campanella\Capability\Textual` · név: `textual` · saját tábla nincs

| Mező | Típus | Tárolás | |
|---|---|---|---|
| `body` | Text | Data | |
| `format` | String | Data | alapértelmezés: `plain` |

| Metódus | Leírás |
|---|---|
| `body(): string` | |
| `format(): TextFormat` | Ismeretlen érték esetén `Plain` |
| `setBody(string $body, TextFormat $format = TextFormat::Plain): void` | |

`TextFormat` (`enum: string`): `Plain = 'plain'` (megjelenítéskor escape-elve,
bekezdésekre bontva), `Html = 'html'` (változtatás nélkül jelenik meg).

> **Biztonság:** HTML formátumú szöveg a 0.0.1-ben szűrés nélkül jelenik meg,
> ezért csak megbízható forrásból (CLI, seed) kerülhet be. WYSIWYG-szerkesztő
> bekötése előtt HTML-szűrő kell.

### Routable

`Campanella\Capability\Routable` · név: `routable` · tábla: `cap_routable` · függ: `Titled`

| Mező | Típus | Tárolás | |
|---|---|---|---|
| `path` | String | Table | kötelező, egyedi |

| Metódus | Leírás |
|---|---|
| `route(): string` | Az útvonal, pl. `/neumann-janos` |
| `setPath(string $path): void` | Normalizálva állítja be |
| `prepareForSave(): void` | Üres útvonal esetén a címből készít egyet (`'/' . Slugger::slugify($title)`), majd normalizál |
| `static normalize(string $path): string` | Perjellel kezdődik, nem végződik perjelre, kisbetűs, nincsenek üres szakaszok: `' Hírek//2026/ '` → `'/hírek/2026'` |

Foglalt útvonal mentésekor `ValidationException` keletkezik (`path: ez az érték
már foglalt`).

### Publishable

`Campanella\Capability\Publishable` · név: `publishable` · tábla: `cap_publishable`

| Mező | Típus | Tárolás | |
|---|---|---|---|
| `status` | String | Table | kötelező, indexelt, alapértelmezés: `draft` |
| `published_at` | DateTime | Table | indexelt |

| Metódus | Leírás |
|---|---|
| `status(): PublishStatus` | |
| `publishedAt(): ?DateTimeImmutable` | |
| `isPublished(?DateTimeImmutable $now = null): bool` | `published` állapotú, és a `published_at` nem későbbi a megadott időpontnál (alapból: most) |
| `publish(?DateTimeImmutable $at = null): void` | Állapot: `published`. Időpont: a megadott, ennek hiányában a korábbi, ennek hiányában most |
| `unpublish(): void` | Állapot: `draft`; a `published_at` megmarad |

Scope: `published`, vagyis `status = published` és `published_at <= most`.

`PublishStatus` (`enum: string`): `Draft = 'draft'`, `Published = 'published'`.

**Időzített publikálás:** a jövőbeli időpontra publikált objektum addig nem
látható, amíg az időpont el nem jön, és utána magától megjelenik.

## Új capability írása

Példa: egy `Weighted` capability, amely súlyt ad az objektumnak, hogy a listák
kézzel sorba rendezhetők legyenek.

### 1. Az osztály

```php
<?php

declare(strict_types=1);

namespace App\Capability;

use Campanella\Capability\AsCapability;
use Campanella\Capability\Capability;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;

#[AsCapability('weighted', label: 'Súlyozott')]
final class Weighted extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            // Rendezünk rá, ezért saját táblás (Table) és indexelt mező.
            new Field('weight', FieldType::Integer, required: true, default: 0, indexed: true, label: 'Súly'),
        ];
    }

    #[\Override]
    public static function scopes(): array
    {
        return [
            'by_weight' => static fn (Query $q): Query => $q->orderBy('weight', 'ASC'),
        ];
    }

    public function weight(): int
    {
        return (int) $this->object->get('weight');
    }

    public function setWeight(int $weight): void
    {
        $this->object->set('weight', $weight);
    }
}
```

### 2. Regisztrálás

`config/app.php`:

```php
'capabilities' => [
    Titled::class, Textual::class, Routable::class, Publishable::class,
    App\Capability\Weighted::class,
],
```

### 3. A tábla létrehozása

```bash
php bin/campanella install      # létrehozza a cc_cap_weighted táblát
```

### 4. Használat

Blueprintben (`config/blueprints.php`):

```php
'page' => [
    'capabilities' => [Textual::class, Routable::class, Publishable::class, Weighted::class],
],
```

Kódban és lekérdezésben:

```php
$page->as(Weighted::class)->setWeight(10);

$pages = $queries->execute(
    Query::objects()->having('weighted')->scope('by_weight'),
    $actor,
);
```

### Ellenőrzőlista

- A név, a mezőnevek és a scope-nevek az egész rendszerben egyediek legyenek.
- Amire szűrni vagy rendezni kell: `FieldStorage::Table`. Minden más: `Data`.
- A capability ne tartalmazzon adatbázis-hozzáférést és külső szolgáltatást.
  Ami ilyet igényel, az a Service rétegbe kerül.
- A `prepareForSave()` csak az objektum saját értékeiből dolgozhat.
