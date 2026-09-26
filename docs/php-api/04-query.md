# 4. Query

A Query azt írja le, *milyen objektumokat* szeretnénk. Ez váltja ki a Drupal
Views mögötti lekérdező mechanizmust. A leírásból a `QueryCompiler` SQL-t
készít, a `QueryEngine` pedig végrehajtja, jogosultsággal együtt.

```
Query → scope-ok feloldása → AccessPolicy (secured query) → SQL (azonosítók)
      → ObjectRepository (kötegelt betöltés) → ResultSet
```

## Query

`Campanella\Query\Query` · **Nyilvános** · `final class`, megváltoztathatatlan

Minden metódus új példányt ad vissza, az eredeti nem változik. Egy definíció
ezért biztonságosan újrahasználható és kiegészíthető.

```php
$base = Query::objects()->blueprint('article')->scope('published');

$latest = $base->orderBy('published_at', 'DESC')->limit(3);
$search = $base->where('title', 'LIKE', 'Neumann%');   // $base változatlan
```

### Építő metódusok

| Metódus | Leírás |
|---|---|
| `static objects(): self` | Üres lekérdezés: minden objektum |
| `having(string ...$capabilities)` | Csak az összes megadott capability-vel rendelkezők. Név vagy osztálynév |
| `blueprint(string ...$blueprints)` | Egy névnél `=`, többnél `IN` |
| `where(string $field, Operator\|string $operator, mixed $value = null)` | Mezőfeltétel, ÉS kapcsolattal a többihez |
| `whereCondition(Condition $condition)` | Tetszőleges feltétel, pl. VAGY-csoport |
| `scope(string $name)` | Egy capability által definiált, elnevezett szűrő |
| `orderBy(string $field, Direction\|string $direction = Direction::Asc)` | Többször hívható; a sorrend a hívások sorrendje |
| `limit(?int $limit)` | Legalább 1, vagy `null` (nincs korlát) |
| `offset(int $offset)` | Csak `limit`-tel együtt érvényes |
| `page(int $page, int $perPage)` | Lapozás; az 1. oldal az első |

### Lekérdező metódusok

A `QueryEngine` és a `QueryCompiler` használja őket:

| Metódus | Visszatérés |
|---|---|
| `conditions()` | `Group`: a gyökér ÉS-csoport |
| `scopes()` | `list<string>` |
| `withoutScopes()` | Másolat scope-ok nélkül |
| `ordering()` | `list<array{string, Direction}>` |
| `getLimit()`, `getOffset()` | `?int`, `int` |

### Mezők a lekérdezésben

**Alapmezők** (minden objektumon; a `BASE_FIELDS` konstans tartalmazza):

| Mező | Oszlop | Típus |
|---|---|---|
| `id` | `objects.id` | Integer |
| `uuid` | `objects.uuid` | String |
| `blueprint` | `objects.blueprint` | String |
| `created` | `objects.created_at` | DateTime |
| `updated` | `objects.updated_at` | DateTime |

**Capability-mezők:** bármely `FieldStorage::Table` tárolású mező a neve szerint
(`title`, `path`, `status`, `published_at`…). A fordító automatikusan hozzáfűzi a
szükséges `LEFT JOIN`-t.

**Nem használható:** `FieldStorage::Data` mező (pl. `body`, `lead`). Ilyenkor
`QueryException` keletkezik azzal az üzenettel, hogy a mezőt saját táblás
oszloppá kell előléptetni.

Az értékek a mező típusa szerint alakulnak át: dátumhoz `DateTimeImmutable`
vagy szöveg, enumos mezőhöz maga az enum is megadható
(`->where('status', '=', PublishStatus::Published)`).

## Operator

`Campanella\Query\Operator` · **Nyilvános** · `enum: string`

| Eset | Szöveges alak | Érték |
|---|---|---|
| `Equals` | `=` | egy érték |
| `NotEquals` | `!=` (vagy `<>`) | egy érték |
| `LessThan`, `LessOrEqual` | `<`, `<=` | egy érték |
| `GreaterThan`, `GreaterOrEqual` | `>`, `>=` | egy érték |
| `In`, `NotIn` | `IN`, `NOT IN` | nem üres tömb |
| `Like` | `LIKE` | minta (`%`, `_`) |
| `IsNull`, `IsNotNull` | `IS NULL`, `IS NOT NULL` | nincs |

| Metódus | Leírás |
|---|---|
| `static parse(self\|string $operator): self` | Szövegből (kis- és nagybetű mindegy). `QueryException`, ha ismeretlen |
| `sql(): string` | Az SQL-alak (`!=` helyett `<>`) |
| `takesValue(): bool`, `takesList(): bool` | Kell-e érték, illetve lista |

## Direction

`Campanella\Query\Direction` · **Nyilvános** · `enum: string` · `Asc = 'ASC'`, `Desc = 'DESC'`

`static parse(self|string $direction): self`: kis- és nagybetű mindegy;
`QueryException`, ha ismeretlen.

A rendezés mindig stabil: a megadott mezők után a fordító az `id` szerint is
rendez (az első rendezés irányában).

## Feltételek

`Campanella\Query\Condition\*` · **Nyilvános** · megváltoztathatatlan

A feltételek egy kis, deklaratív fát (AST-t) alkotnak, nem PHP-kódot. Így a
fordító SQL-re tudja alakítani őket, és a jogosultsági szabályok ugyanebben a
nyelvben fogalmazhatók meg.

| Osztály | Jelentés |
|---|---|
| `Condition` | Közös interfész (jelölő) |
| `FieldCondition(string $field, Operator $operator, mixed $value = null)` | `mező OPERÁTOR érték` |
| `HasCapability(string $capability, bool $negated = false)` | Rendelkezik-e (vagy nem) a capability-vel |
| `Group(bool $any, list<Condition> $conditions)` | `$any = false`: ÉS, `true`: VAGY. Egymásba ágyazható |

`Group` segédmetódusai: `static all(Condition ...)`, `static any(Condition ...)`,
`with(Condition): self` (új csoport a feltétellel kiegészítve).

```php
use Campanella\Query\Condition\{FieldCondition, Group, HasCapability};
use Campanella\Query\Operator;

// Ami nem publikálható, VAGY már publikált
$query = Query::objects()->whereCondition(Group::any(
    new HasCapability('publishable', negated: true),
    new FieldCondition('status', Operator::Equals, 'published'),
));
```

Ismeretlen capability-névnél a `HasCapability` fordításakor
`CapabilityException` keletkezik.

## Scope-ok

Egy capability elnevezett szűrőket adhat a `scopes()` metódusában. A scope egy
`Closure(Query): Query`, amelyet a `QueryEngine` a végrehajtás előtt alkalmaz.

| Scope | Capability | Hatása |
|---|---|---|
| `published` | Publishable | `status = published` és `published_at <= most` |

```php
Query::objects()->scope('published');
```

A scope a lekérdezés építésekor csak a nevével kerül a Query-be, a feloldása
végrehajtáskor történik. Így a „most” időpont mindig a futtatás pillanata,
akkor is, ha a Query-definíció régebben készült.

## QueryEngine

`Campanella\Query\QueryEngine` · **Nyilvános** · `final class` · konténer: `QueryEngine::class`

| Metódus | Leírás |
|---|---|
| `execute(Query $query, Actor $actor, bool $withTotal = false): ResultSet` | Végrehajtás. `$withTotal` esetén külön `COUNT` lekérdezés adja az összes találat számát |
| `first(Query $query, Actor $actor): ?CampanellaObject` | Az első találat (a limitet 1-re állítja) |
| `count(Query $query, Actor $actor): int` | Találatok száma lapozás nélkül |
| `secure(Query $query, Actor $actor): Query` | A ténylegesen futó lekérdezés: scope-ok feloldva, jogosultsági feltételekkel kiegészítve. Hibakereséshez hasznos |

Az `Actor` kötelező: ugyanaz a Query más eredményt ad szerkesztőnek és
anonymous látogatónak.

```php
$result = $queries->execute(Query::objects()->blueprint('article')->page(2, 10), $actor, withTotal: true);
```

## ResultSet

`Campanella\Query\ResultSet` · **Nyilvános** · `final readonly class` · `IteratorAggregate`, `Countable`

A lekérdezés eredménye, még megjelenítés nélkül. Ugyanez szolgálhat HTML, JSON,
RSS vagy CSV forrásául.

| Tag | Leírás |
|---|---|
| `$items` | `list<CampanellaObject>` |
| `$total` | Összes találat, ha `withTotal: true` volt; egyébként `null` |
| `$limit`, `$offset` | A lekérdezés lapozási adatai |
| `getIterator()`, `count()` | `foreach` és `count()` az aktuális oldal elemein |
| `isEmpty(): bool` | |
| `first(): ?CampanellaObject` | |
| `currentPage(): int` | 1-től számozva |
| `pageCount(): int` | Legalább 1; `$total` nélkül mindig 1 |

## QueryCompiler és CompiledQuery

`Campanella\Query\QueryCompiler`, `Campanella\Query\CompiledQuery` · **Belső**

A `QueryCompiler` a Query-t SQL-re fordítja. `compile(Query): CompiledQuery` az
azonosítókat lekérdező `SELECT`-et adja rendezéssel és lapozással,
`compileCount(Query): CompiledQuery` a `COUNT(*)`-ot. A `CompiledQuery` két
mezője: `$sql` (táblanevek `{objects}` alakú helyőrzőkkel) és `$params`.

A fordító sosem alkalmaz jogosultságot; ezt a `QueryEngine` teszi. Közvetlenül
csak hibakereséshez érdemes használni.

## Hibák

| Helyzet | Kivétel |
|---|---|
| Ismeretlen mező a `where`-ben vagy `orderBy`-ban | `QueryException` |
| `Data` tárolású mezőre szűrés vagy rendezés | `QueryException` |
| Ismeretlen operátor vagy irány | `QueryException` |
| `IN`/`NOT IN` üres vagy nem tömb értékkel | `QueryException` |
| `limit` 1-nél kisebb; `offset` `limit` nélkül | `QueryException` |
| Ismeretlen scope vagy capability | `CapabilityException` |
