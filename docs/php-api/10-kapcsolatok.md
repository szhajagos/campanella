# 10. Kapcsolatok (Relationship)

*0.0.2 óta.*

Egy kapcsolat elnevezett, irányított mutató egy objektumból (forrás) más
objektumokra (célok): a cikk kategóriái, egy oldal szerzője, egy menüpont
szülője. A kapcsolat általános mechanizmus: minden konkrét kapcsolat csak egy
definíció, ugyanúgy, ahogy a mezők.

```
cikk "Neumann János" ──categories──▶ kategória "Tudomány"
                     ──categories──▶ kategória "Campanella"
```

## Relation

`Campanella\Relation\Relation` · **Nyilvános** · `final readonly class`

```php
new Relation(
    name: 'categories',                  // ^[a-z][a-z0-9_]{0,62}$, rendszerszinten egyedi
    cardinality: Cardinality::Many,      // alapértelmezés
    targetCapabilities: [],              // a célnak mindegyikkel rendelkeznie kell (név vagy osztály)
    targetBlueprints: ['category'],      // a cél ezek egyikéből készült; üres: bármelyik
    required: false,                     // legalább egy cél kötelező-e
    label: 'Kategóriák',
);
```

| Metódus | Leírás |
|---|---|
| `isMany(): bool` | Többes kapcsolat-e |

A konstruktor `InvalidArgumentException`-t dob érvénytelen névre.

## Cardinality

`Campanella\Relation\Cardinality` · **Nyilvános** · `enum: string`

| Eset | Jelentés |
|---|---|
| `One = 'one'` | Legfeljebb egy cél (szerző, szülő) |
| `Many = 'many'` | Tetszőleges számú cél, sorrendben (kategóriák, képek) |

## Kapcsolat definiálása

**Blueprintben** (`config/blueprints.php`), a `relations` kulcs alatt:

```php
'article' => [
    'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class],
    'relations' => [
        new Relation('categories', Cardinality::Many, targetBlueprints: ['category'], label: 'Kategóriák'),
    ],
],
```

**Capability-ben**, a `relations()` statikus metódussal. Ez akkor való, ha a
kapcsolat a képesség lényegéhez tartozik (pl. egy későbbi `Hierarchical`
capability hozza a `parent` kapcsolatot):

```php
#[AsCapability('authorable')]
final class Authorable extends Capability
{
    public static function fields(): array { return []; }

    public static function relations(): array
    {
        return [new Relation('author', Cardinality::One, targetCapabilities: ['titled'], label: 'Szerző')];
    }
}
```

**Névszabályok:**

- A kapcsolatnév nem ütközhet mezőnévvel és más capability kapcsolatával.
- Ugyanazt a nevet több Blueprint is használhatja, de csak azonos
  definícióval (pl. a cikk és az oldal is kaphat `categories` kapcsolatot).
- Ütközéskor a regisztráció `CapabilityException`-t dob.

## Az objektumon

`Campanella\Model\CampanellaObject` · **Nyilvános**

| Metódus | Leírás |
|---|---|
| `relations(): array<string, Relation>` | Az objektumon értelmezett kapcsolatok |
| `hasRelation(string $name): bool` | |
| `relatedIds(string $name): list<int>` | A célok azonosítói sorrendben, jogosultság-szűrés nélkül |
| `setRelated(string $name, iterable $targets): void` | Az összes cél egyszerre (objektum vagy azonosító), a megadott sorrendben; ismétlődést kiszűri |
| `relate(string $name, CampanellaObject\|int $target): void` | Többesnél a végére fűzi (ha még nincs benne), egyesnél lecseréli |
| `unrelate(string $name, CampanellaObject\|int $target): void` | Eltávolítja |
| `relatedObjects(string $name): list<CampanellaObject>` | A betöltött célobjektumok (lásd `RelationLoader`). `LogicException`, ha még nincsenek betöltve |
| `isResolved(string $name): bool` | Be vannak-e töltve |
| `attachResolved(string $name, array $objects)` | **Belső**, a `RelationLoader` hívja |

Ismeretlen kapcsolatnévre `OutOfBoundsException`, egyes kapcsolatnál több
célra, illetve mentetlen célobjektumra `InvalidArgumentException` keletkezik.

A módosítás csak memóriában történik; az adatbázisba a mentés viszi:

```php
$article->relate('categories', $science);
$article->relate('categories', $history);
$repository->save($article);           // vagy $service->update($actor, $article, [])
```

## Tárolás és ellenőrzés

A kapcsolatok a `cc_relationships` táblában élnek: `source_id`, `type` (a
kapcsolat neve), `target_id`, `weight` (sorrend). Mindkét oldal idegen kulcs
az `objects` táblára: ha a forrást vagy a célt törlik, a kapcsolat is
törlődik.

Mentéskor az `ObjectRepository` ellenőriz, és hiba esetén
`ValidationException`-t dob (kulcs: a kapcsolat neve):

| Hiba | Üzenet |
|---|---|
| Kötelező kapcsolat üres | `kötelező kapcsolat` |
| Az objektum önmagára mutat | `az objektum nem mutathat önmagára` |
| Nem létező cél | `a cél (#12) nem létezik` |
| Rossz Blueprintből készült cél | `a cél (#12) page típusú, de csak ez lehet: category` |
| Hiányzó capability a célon | `a célnak (#12) nincs ilyen capability-je: titled` |

Betöltéskor az objektum a kapcsolatai célazonosítóit is megkapja: az összes
betöltött objektumét egyetlen lekérdezés hozza (nincs N+1).

## Lekérdezés kapcsolat szerint

`Query::whereRelated()` és `whereNotRelated()` · **Nyilvános**

| Metódus | Jelentés |
|---|---|
| `whereRelated(string $relation, CampanellaObject\|int ...$targets)` | A kapcsolat a célok valamelyikére mutat; cél nélkül: van ilyen kapcsolata |
| `whereNotRelated(string $relation, CampanellaObject\|int ...$targets)` | Az előző tagadása |

```php
// Egy kategória publikált cikkei
Query::objects()->blueprint('article')->whereRelated('categories', $category)->scope('published');

// Kategória nélküli cikkek
Query::objects()->blueprint('article')->whereNotRelated('categories');
```

A feltétel a jogosultsági szabályokkal együtt, SQL-ben fut. Ismeretlen
kapcsolatnévre `QueryException`, mentetlen célobjektumra szintén
`QueryException` keletkezik.

A feltétel-fában a `Campanella\Query\Condition\RelatedTo(string $relation,
list<int> $targets = [], bool $negated = false)` osztály ábrázolja.

## RelationLoader

`Campanella\Relation\RelationLoader` · **Nyilvános** · konténer: `RelationLoader::class`

A kapcsolódó objektumokat megjelenítés előtt tölti be.

| Metódus | Leírás |
|---|---|
| `__construct(QueryEngine $queries)` | |
| `resolve(iterable $objects, Actor $actor, ?array $relations = null): void` | A megadott objektumok kapcsolatainak (vagy csak a felsoroltaknak) a céljait tölti be |

- Akárhány objektum és kapcsolat esetén is **egyetlen lekérdezés** fut.
- A lekérdezés **jogosultság-tudatos**: amit az Actor nem láthat (pl. egy
  piszkozat kategória), az kimarad a `relatedObjects()` eredményéből.
- A sorrend a kapcsolat sorrendje.

```php
$loader->resolve($result, $actor);      // pl. egy lista összes eleméhez
foreach ($article->relatedObjects('categories') as $category) { … }
```

Az `ObjectController` és a `QueryController` ezt automatikusan meghívja, így
a sablonok mindig betöltött kapcsolatokat kapnak.

## Sablonokban

| Sablonban | Leírás |
|---|---|
| `{{ related(object, 'categories') }}` | A betöltött célok listája. Ha nincsenek betöltve, vagy nincs ilyen kapcsolat: üres lista |
| `{% for name, relation in object.relations %}` | Az objektum kapcsolatainak definíciói |
| `{{ include('object/_relations.html.twig') }}` | Kész részlet: a kapcsolatok linkként, pl. „Kategóriák: Tudomány, Campanella” |

## Listák az objektum oldalán

Egy Blueprint `lists` kulcsa olyan listákat ad meg, amelyek az objektum saját
oldalán (`full` megjelenítés) jelennek meg. Minden lista egy felirat és egy
függvény, amely az objektumból Query-t készít:

```php
'category' => [
    'capabilities' => [Textual::class, Routable::class, Publishable::class],
    'lists' => [
        'articles' => [
            'label' => 'Cikkek ebben a kategóriában',
            'query' => static fn (CampanellaObject $category): Query => Query::objects()
                ->blueprint('article')
                ->whereRelated('categories', $category)
                ->scope('published')
                ->orderBy('published_at', 'DESC')
                ->limit(20),
        ],
    ],
],
```

Az `ObjectController` futtatja le a listákat (jogosultsággal, a listaelemek
kapcsolatait is betöltve), a `page/object.html.twig` pedig a `lists` változóból
jeleníti meg őket: `lists.<név>.label` és `lists.<név>.result` (`ResultSet`).

A `Blueprint::$lists` típusa: `array<string, array{label?: string, query:
Closure(CampanellaObject): Query}>`.
