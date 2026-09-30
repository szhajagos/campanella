# 4. Query

A Query describes *which objects* we want. It replaces the query mechanism
behind Drupal Views. `QueryCompiler` builds SQL from the description, and
`QueryEngine` executes it, together with access control.

```
Query → resolve scopes → AccessPolicy (secured query) → SQL (IDs)
      → ObjectRepository (batch loading) → ResultSet
```

## Query

`Campanella\Query\Query` · **Public** · `final class`, immutable

Every method returns a new instance; the original is left unchanged. A
definition can therefore be safely reused and extended.

```php
$base = Query::objects()->blueprint('article')->scope('published');

$latest = $base->orderBy('published_at', 'DESC')->limit(3);
$search = $base->where('title', 'LIKE', 'Neumann%');   // $base is unchanged
```

### Builder methods

| Method | Description |
|---|---|
| `static objects(): self` | Empty query: every object |
| `having(string ...$capabilities)` | Only objects that have all the given capabilities. Name or class name |
| `blueprint(string ...$blueprints)` | `=` for one name, `IN` for several |
| `where(string $field, Operator\|string $operator, mixed $value = null)` | Field condition, joined to the others with AND |
| `whereRelated(string $relation, CampanellaObject\|int ...$targets)` | Related to any of the targets; without targets: has such a relation at all ([chapter 10](10-relations.md#querying-by-relation)) |
| `whereNotRelated(string $relation, CampanellaObject\|int ...$targets)` | The negation of the previous one |
| `whereCondition(Condition $condition)` | Any condition, e.g. an OR group |
| `scope(string $name)` | A named filter defined by a capability |
| `orderBy(string $field, Direction\|string $direction = Direction::Asc)` | Can be called several times; the order is the order of the calls |
| `limit(?int $limit)` | At least 1, or `null` (no limit) |
| `offset(int $offset)` | Only valid together with `limit` |
| `page(int $page, int $perPage)` | Pagination; page 1 is the first |

### Accessor methods

Used by `QueryEngine` and `QueryCompiler`:

| Method | Returns |
|---|---|
| `conditions()` | `Group`: the root AND group |
| `scopes()` | `list<string>` |
| `withoutScopes()` | A copy without scopes |
| `ordering()` | `list<array{string, Direction}>` |
| `getLimit()`, `getOffset()` | `?int`, `int` |

### Fields in a query

**Base fields** (present on every object; listed in the `BASE_FIELDS` constant):

| Field | Column | Type |
|---|---|---|
| `id` | `objects.id` | Integer |
| `uuid` | `objects.uuid` | String |
| `blueprint` | `objects.blueprint` | String |
| `created` | `objects.created_at` | DateTime |
| `updated` | `objects.updated_at` | DateTime |

**Capability fields:** any field with `FieldStorage::Table` storage, by name
(`title`, `path`, `status`, `published_at`…). The compiler adds the necessary
`LEFT JOIN` automatically.

**Not usable:** `FieldStorage::Data` fields (e.g. `body`, `lead`). These raise
a `QueryException` with a message saying the field has to be promoted to an
own-table column.

### Multi-valued fields

A multi-valued `Table` field ([chapter 2](02-objects.md#multi-valued-fields))
can be filtered on; the condition becomes an `EXISTS` subquery on the
`field_values` table, so values never multiply the result rows:

| Operator | Matches if |
|---|---|
| `=`, `<`, `<=`, `>`, `>=`, `LIKE`, `IN` | at least one value matches |
| `!=`, `NOT IN` | the object has the field (its capability), and no value matches; an empty list counts as "no value matches" |
| `IS NULL` | the field has no value at all (also true for objects without the field, as for a single-valued field) |
| `IS NOT NULL` | the field has at least one value |

```php
Query::objects()->where('keywords', '=', 'php');          // has the keyword "php"
Query::objects()->where('keywords', 'NOT IN', ['a', 'b']); // has neither "a" nor "b"
```

Sorting on a multi-valued field is not possible (`QueryException`), because
it has no single value to sort by.

Values are converted according to the field's type: a date accepts
`DateTimeImmutable` or a string, and an enum-backed field accepts the enum
itself (`->where('status', '=', PublishStatus::Published)`).

## Operator

`Campanella\Query\Operator` · **Public** · `enum: string`

| Case | String form | Value |
|---|---|---|
| `Equals` | `=` | a single value |
| `NotEquals` | `!=` (or `<>`) | a single value |
| `LessThan`, `LessOrEqual` | `<`, `<=` | a single value |
| `GreaterThan`, `GreaterOrEqual` | `>`, `>=` | a single value |
| `In`, `NotIn` | `IN`, `NOT IN` | a non-empty array |
| `Like` | `LIKE` | a pattern (`%`, `_`) |
| `IsNull`, `IsNotNull` | `IS NULL`, `IS NOT NULL` | none |

| Method | Description |
|---|---|
| `static parse(self\|string $operator): self` | From a string (case-insensitive). `QueryException` if unknown |
| `sql(): string` | The SQL form (`<>` instead of `!=`) |
| `takesValue(): bool`, `takesList(): bool` | Whether it needs a value, or a list |

## Direction

`Campanella\Query\Direction` · **Public** · `enum: string` · `Asc = 'ASC'`, `Desc = 'DESC'`

`static parse(self|string $direction): self`: case-insensitive;
`QueryException` if unknown.

Ordering is always stable: after the given fields, the compiler also orders by
`id` (in the direction of the first ordering).

## Conditions

`Campanella\Query\Condition\*` · **Public** · immutable

Conditions form a small, declarative tree (an AST), not PHP code. This lets
the compiler turn them into SQL, and lets access policies be expressed in the
same language.

| Class | Meaning |
|---|---|
| `Condition` | Common (marker) interface |
| `FieldCondition(string $field, Operator $operator, mixed $value = null)` | `field OPERATOR value` |
| `HasCapability(string $capability, bool $negated = false)` | Whether the object has (or does not have) the capability |
| `Group(bool $any, list<Condition> $conditions)` | `$any = false`: AND, `true`: OR. Can be nested |
| `RelatedTo(string $relation, list<int> $targets = [], bool $negated = false)` | Whether it is related to any of the targets (empty list: to any object) |

`Group` helper methods: `static all(Condition ...)`, `static any(Condition ...)`,
`with(Condition): self` (a new group extended with the condition).

```php
use Campanella\Query\Condition\{FieldCondition, Group, HasCapability};
use Campanella\Query\Operator;

// Not publishable, OR already published
$query = Query::objects()->whereCondition(Group::any(
    new HasCapability('publishable', negated: true),
    new FieldCondition('status', Operator::Equals, 'published'),
));
```

For an unknown capability name, compiling `HasCapability` raises a
`CapabilityException`.

## Scopes

A capability can provide named filters in its `scopes()` method. A scope is a
`Closure(Query): Query` that `QueryEngine` applies before execution.

| Scope | Capability | Effect |
|---|---|---|
| `published` | Publishable | `status = published` and `published_at <= now` |

```php
Query::objects()->scope('published');
```

When the query is built, a scope is added to the Query by name only; it is
resolved at execution time. This way "now" is always the moment of execution,
even if the Query definition was created earlier.

## QueryEngine

`Campanella\Query\QueryEngine` · **Public** · `final class` · container: `QueryEngine::class`

| Method | Description |
|---|---|
| `execute(Query $query, Actor $actor, bool $withTotal = false): ResultSet` | Executes the query. With `$withTotal`, a separate `COUNT` query provides the total number of matches |
| `first(Query $query, Actor $actor): ?CampanellaObject` | The first match (sets the limit to 1) |
| `count(Query $query, Actor $actor): int` | Number of matches without pagination |
| `secure(Query $query, Actor $actor): Query` | The query that actually runs: scopes resolved, access conditions added. Useful for debugging |

The `Actor` is required: the same Query gives different results for an editor
and for an anonymous visitor.

```php
$result = $queries->execute(Query::objects()->blueprint('article')->page(2, 10), $actor, withTotal: true);
```

## ResultSet

`Campanella\Query\ResultSet` · **Public** · `final readonly class` · `IteratorAggregate`, `Countable`

The result of a query, not yet rendered. The same result can serve as the
source for HTML, JSON, RSS or CSV.

| Member | Description |
|---|---|
| `$items` | `list<CampanellaObject>` |
| `$total` | Total number of matches if `withTotal: true` was given; otherwise `null` |
| `$limit`, `$offset` | The pagination data of the query |
| `getIterator()`, `count()` | `foreach` and `count()` over the items of the current page |
| `isEmpty(): bool` | |
| `first(): ?CampanellaObject` | |
| `currentPage(): int` | Numbered from 1 |
| `pageCount(): int` | At least 1; always 1 without `$total` |

## QueryCompiler and CompiledQuery

`Campanella\Query\QueryCompiler`, `Campanella\Query\CompiledQuery` · **Internal**

`QueryCompiler` compiles a Query into SQL. Its constructor:
`__construct(CapabilityRegistry $capabilities, ?BlueprintRegistry $blueprints = null)`;
without the `BlueprintRegistry`, relation conditions cannot be compiled.
`compile(Query): CompiledQuery` returns the `SELECT` that fetches the IDs, with
ordering and pagination; `compileCount(Query): CompiledQuery` returns the
`COUNT(*)`. `CompiledQuery` has two fields: `$sql` (table names as
placeholders like `{objects}`) and `$params`.

The compiler never applies access control; `QueryEngine` does that. Use it
directly only for debugging.

## Errors

| Situation | Exception |
|---|---|
| Unknown field in `where` or `orderBy` | `QueryException` |
| Filtering or sorting on a field with `Data` storage | `QueryException` |
| Sorting on a multi-valued field | `QueryException` |
| Unknown operator or direction | `QueryException` |
| `IN`/`NOT IN` with an empty or non-array value | `QueryException` |
| `limit` less than 1; `offset` without `limit` | `QueryException` |
| Unknown scope or capability | `CapabilityException` |
| Unknown relation, unsaved target in `whereRelated` | `QueryException` |
