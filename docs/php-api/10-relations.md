# 10. Relations (Relationship)

*Since 0.0.2.*

A relation is a named, directed pointer from one object (the source) to other
objects (the targets): an article's categories, a page's author, a menu item's
parent. Relations are a generic mechanism: every concrete relation is just a
definition, the same way fields are.

```
article "Neumann János" ──categories──▶ category "Tudomány"
                        ──categories──▶ category "Campanella"
```

## Relation

`Campanella\Relation\Relation` · **Public** · `final readonly class`

```php
new Relation(
    name: 'categories',                  // ^[a-z][a-z0-9_]{0,62}$, unique system-wide
    cardinality: Cardinality::Many,      // default
    targetCapabilities: [],              // the target must have all of these (name or class)
    targetBlueprints: ['category'],      // the target was made from one of these; empty: any
    required: false,                     // whether at least one target is required
    label: 'relation.categories',
    max: null,                           // Many only: at most this many targets (since 0.0.4)
);
```

| Method | Description |
|---|---|
| `isMany(): bool` | Whether the relation is to-many |
| `exceedsMax(int $count): bool` | Whether the given number of targets is over the `max` limit |

The constructor throws `InvalidArgumentException` for an invalid name, for a
`max` on a `One` relation, and for a `max` less than 1.

## Cardinality

`Campanella\Relation\Cardinality` · **Public** · `enum: string`

| Case | Meaning |
|---|---|
| `One = 'one'` | At most one target (author, parent) |
| `Many = 'many'` | Any number of targets, ordered (categories, images) |

## Defining a relation

**In a Blueprint** (`config/blueprints.php`), under the `relations` key:

```php
'article' => [
    'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class],
    'relations' => [
        new Relation('categories', Cardinality::Many, targetBlueprints: ['category'], label: 'relation.categories'),
    ],
],
```

**In a capability**, with the static `relations()` method. This is the right
choice when the relation is essential to the capability (e.g. a future
`Hierarchical` capability brings the `parent` relation):

```php
#[AsCapability('authorable')]
final class Authorable extends Capability
{
    public static function fields(): array { return []; }

    public static function relations(): array
    {
        return [new Relation('author', Cardinality::One, targetCapabilities: ['titled'], label: 'relation.author')];
    }
}
```

**Naming rules:**

- A relation name must not collide with a field name or with another capability's relation.
- Several Blueprints may use the same name, but only with an identical
  definition (e.g. both articles and pages may have a `categories` relation).
- On a collision, registration throws `CapabilityException`.

## On the object

`Campanella\Model\CampanellaObject` · **Public**

| Method | Description |
|---|---|
| `relations(): array<string, Relation>` | The relations defined on the object |
| `hasRelation(string $name): bool` | |
| `relatedIds(string $name): list<int>` | The target IDs in order, without access control filtering |
| `setRelated(string $name, iterable $targets): void` | All targets at once (objects or IDs), in the given order; duplicates are removed |
| `relate(string $name, CampanellaObject\|int $target): void` | For a to-many relation, appends it (if not already present); for a to-one relation, replaces it |
| `unrelate(string $name, CampanellaObject\|int $target): void` | Removes it |
| `relatedObjects(string $name): list<CampanellaObject>` | The loaded target objects (see `RelationLoader`). `LogicException` if they are not loaded yet |
| `isResolved(string $name): bool` | Whether they are loaded |
| `attachResolved(string $name, array $objects)` | **Internal**, called by the `RelationLoader` |

An unknown relation name raises `OutOfBoundsException`; several targets for a
to-one relation, or an unsaved target object, raise `InvalidArgumentException`.

Changes happen in memory only; saving writes them to the database:

```php
$article->relate('categories', $science);
$article->relate('categories', $history);
$repository->save($article);           // or $service->update($actor, $article, [])
```

## Storage and validation

Relations live in the `cc_relationships` table: `source_id`, `type` (the
relation name), `target_id`, `weight` (order). Both sides are foreign keys to
the `objects` table: if the source or the target is deleted, the relationship
is deleted too.

On save, the `ObjectRepository` validates and, on failure, throws
`ValidationException` (key: the relation name):

| Error | Message |
|---|---|
| A required relation is empty | `kötelező kapcsolat` ("required relation") |
| More targets than `max` | `legfeljebb 2 kapcsolat adható meg` ("at most 2 relations can be given") |
| The object points to itself | `az objektum nem mutathat önmagára` ("the object cannot point to itself") |
| Non-existent target | `a cél (#12) nem létezik` ("target (#12) does not exist") |
| Target made from the wrong Blueprint | `a cél (#12) page típusú, de csak ez lehet: category` ("target (#12) is of type page, but it can only be: category") |
| Missing capability on the target | `a célnak (#12) nincs ilyen capability-je: titled` ("target (#12) lacks this capability: titled") |

On load, the object also receives the target IDs of its relations: a single
query fetches them for all loaded objects (no N+1).

## Querying by relation

`Query::whereRelated()` and `whereNotRelated()` · **Public**

| Method | Meaning |
|---|---|
| `whereRelated(string $relation, CampanellaObject\|int ...$targets)` | The relation points to one of the targets; without targets: the object has such a relation at all |
| `whereNotRelated(string $relation, CampanellaObject\|int ...$targets)` | The negation of the above |

```php
// Published articles of a category
Query::objects()->blueprint('article')->whereRelated('categories', $category)->scope('published');

// Articles without a category
Query::objects()->blueprint('article')->whereNotRelated('categories');
```

The condition runs in SQL, together with the access control policies. An
unknown relation name raises `QueryException`, and so does an unsaved target
object.

In the condition tree it is represented by the
`Campanella\Query\Condition\RelatedTo(string $relation, list<int> $targets = [],
bool $negated = false)` class.

## RelationLoader

`Campanella\Relation\RelationLoader` · **Public** · container: `RelationLoader::class`

Loads the related objects before rendering.

| Method | Description |
|---|---|
| `__construct(QueryEngine $queries)` | |
| `resolve(iterable $objects, Actor $actor, ?array $relations = null): void` | Loads the targets of the given objects' relations (or only of the listed ones) |

- A **single query** runs regardless of the number of objects and relations.
- The query is **access-control aware**: whatever the Actor may not see (e.g. a
  draft category) is left out of the `relatedObjects()` result.
- The order is the relation's order.

```php
$loader->resolve($result, $actor);      // e.g. for all items of a list
foreach ($article->relatedObjects('categories') as $category) { … }
```

The `ObjectController` and the `QueryController` call it automatically, so
templates always receive loaded relations.

## In templates

| In a template | Description |
|---|---|
| `{{ related(object, 'categories') }}` | The list of loaded targets. If they are not loaded, or there is no such relation: an empty list |
| `{% for name, relation in object.relations %}` | The definitions of the object's relations |
| `{{ include('object/_relations.html.twig') }}` | A ready-made partial: the relations as links, e.g. "Kategóriák: Tudomány, Campanella" |

## Lists on the object's page

A Blueprint's `lists` key defines lists that appear on the object's own page
(`full` rendering). Each list is a label and a function that builds a Query
from the object:

```php
'category' => [
    'capabilities' => [Textual::class, Routable::class, Publishable::class],
    'lists' => [
        'articles' => [
            'label' => 'list.category.articles',
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

The `ObjectController` runs the lists (with access control, also loading the
relations of the list items), and `page/object.html.twig` renders them from
the `lists` variable: `lists.<name>.label` and `lists.<name>.result`
(`ResultSet`).

The type of `Blueprint::$lists`: `array<string, array{label?: string, query:
Closure(CampanellaObject): Query}>`.
