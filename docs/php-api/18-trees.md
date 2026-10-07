# 18. Trees

Objects can be arranged in a tree (since 0.0.7): categories under categories,
menu items under menu items. A Blueprint gets the `Hierarchical` capability,
usually with `Weighted` for the order of siblings
([chapter 3](03-capabilities.md#weighted)).

```php
'category' => [
    'capabilities' => [Textual::class, Routable::class, Publishable::class, Hierarchical::class, Weighted::class],
],
```

## The rules

Decided in the ROADMAP (2026-10-07):

- **The parent is of the same Blueprint.** A category's parent is a category.
  An object of another kind (e.g. a comment and its article) is linked by a
  relation, not as a parent.
- **No circles:** an object cannot be placed under itself or under one of its
  own descendants.
- **At most 10 levels** (`Hierarchical::MAX_DEPTH`): a root and 9 levels
  below it; also checked when a whole subtree is moved.
- **A node with children cannot be deleted** until they are moved elsewhere.
- **Separate trees** (since 0.0.7, with a `tree_scope`, see below): the
  parent is in the same scope, e.g. a menu item's parent is in the same menu.

They are checked by the `ObjectRepository` on save and delete, the lowest
layer, so neither the admin nor the command line nor a later API can bypass
them. The errors are `ValidationException`s: on the `parent` relation
(`tree.circular`, `tree.other_blueprint`, `tree.too_deep`, `tree.other_scope`),
and on `children` when deleting (`tree.has_children`, `tree.scope_in_use`). The admin's delete page shows the latter.

## How it is stored

Each node stores its **materialized path**: the IDs from the root down to
itself, `/1/5/12/`, and its depth (a root is 0), in `cap_hierarchical`.

- The descendants of a node are one indexed query: `tree_path LIKE '/1/5/%'`.
- Moving a node moves its subtree with one `UPDATE` (the paths' beginning is
  replaced, the depths shifted).
- The ancestors are in the path itself, so breadcrumbs need no extra queries
  to find them.

The path and the depth are set by the repository from the parent's, once the
object's ID is known; they are hidden in the forms and never set by hand. The
parent is the `parent` relation (in `relationships`), as any other relation.

## Hierarchical

`Campanella\Capability\Hierarchical` · **Public** · name: `hierarchical` · table: `cap_hierarchical`

| Field / relation | Type | |
|---|---|---|
| `tree_path` | String(255), indexed | Managed; hidden |
| `depth` | Integer, required, default `0` | Managed; hidden |
| `parent` | Relation, One, to a `Hierarchical` object | None: a root |

| Member | Description |
|---|---|
| `parentId(): ?int`, `setParent(CampanellaObject\|int\|null $parent): void` | Null: a root. Checked on save |
| `isRoot(): bool` | |
| `path(): string` | `/1/5/12/`; empty before the first save |
| `depth(): int` | 0 for a root |
| `ancestorIds(): list<int>` | From the root down, without the object itself |
| `static roots(Query $query): Query` | Only the roots |
| `static childrenOf(Query $query, CampanellaObject\|int $parent): Query` | The direct children |
| `static descendantsOf(Query $query, CampanellaObject $node): Query` | Everything below, at any depth |
| `static ancestorsOf(Query $query, CampanellaObject $node): Query` | The ancestors (order them by `depth`) |
| `static relatedWithin(Query $query, string $relation, CampanellaObject $node): Query` | Objects related (by `$relation`) to the node or to any of its descendants, in one condition; e.g. the articles of a category and its subcategories |
| `MAX_DEPTH` | 10 |

```php
$children = $queries->execute(
    Hierarchical::childrenOf(Query::objects()->blueprint('category'), $science)->scope('by_weight'),
    $actor,
);
```

## TreeBuilder and TreeNode

`Campanella\Tree\TreeBuilder` · **Public** · `final class`

| Method | Description |
|---|---|
| `static build(iterable $objects, bool $keepOrphans = true): list<TreeNode>` | A tree from a list of objects; the siblings keep the list's order (order the query, e.g. by weight). An object whose parent is not in the list becomes a root, so a subtree can be built from `descendantsOf()`; with `$keepOrphans` false it is left out, together with its subtree |
| `static flatten(array $roots): list<TreeNode>` | The tree in display order (a node, then its subtree), e.g. for an indented list |

```php
$tree = TreeBuilder::build($queries->execute(Query::objects()->blueprint('category')->scope('by_weight'), $actor));
foreach (TreeBuilder::flatten($tree) as $node) {
    echo str_repeat('— ', $node->level), $node->object->get('title'), "\n";
}
```

`Campanella\Tree\TreeNode` · **Public** · `final class`: `object`
(`CampanellaObject`), `children` (`list<TreeNode>`), `level` (0 for a root of
the built tree), `hasChildren(): bool`.

Note that a query returns only what the actor may see: a draft category's
children are still found by `childrenOf()`, but a tree built from a visitor's
query has the draft missing, so by default its children become roots. Pass
`$keepOrphans = false` to leave such a branch out (the `tree()` Twig function
does so).

## SiblingOrder

`Campanella\Tree\SiblingOrder` · **Public** · `final class`

Moving a `Weighted` object up or down among its siblings: the objects of its
Blueprint, or for a `Hierarchical` one, the children of the same parent (the
roots for a root; with a `tree_scope`, the roots of the same scope). The siblings are numbered again, `STEP` (10) apart, in the
new order; only the weights change.

| Method | Description |
|---|---|
| `__construct(Connection $db, ?BlueprintRegistry $blueprints = null)` | The registry gives the Blueprints' `tree_scope` (since 0.0.7) |
| `move(CampanellaObject $object, int $direction): bool` | `-1`: up, `1`: down; false at the first or last place |
| `siblings(CampanellaObject $object): list<int>` | The siblings' IDs (the object too), in their order |

## On the public site

- **The page of a tree node** (e.g. a category) shows breadcrumbs above it
  (the home page, the visible ancestors, then the node itself with
  `aria-current="page"`) and the list of its visible children below it, by
  weight when the Blueprint is `Weighted`. The `ObjectController` passes them
  to `page/object.html.twig` as `breadcrumbs` and `children`.
- **The articles of a category** include those of its subcategories: the
  category's `articles` list uses `relatedWithin()`.

```php
'articles' => [
    'query' => static fn (CampanellaObject $category): Query => Hierarchical::relatedWithin(
        Query::objects()->blueprint('article'), 'categories', $category,
    )->scope('published')->orderBy('published_at', 'DESC')->limit(20),
],
```

- **The category list** (`/kategoriak`, the `categories` Query, ordered by
  weight) is rendered as a nested list by `templates/query/categories.html.twig`
  with the `tree()` Twig function, which calls `TreeBuilder::build()`. In
  Twig an object whose parent is not in the list is left out with its
  subtree; `tree(result, true)` keeps it as a root instead (e.g. for a
  subtree from `descendantsOf()`):

```twig
{% macro branch(nodes) %}
  <ul>
    {% for node in nodes %}
      <li><a href="{{ url(node.object.get('path')) }}">{{ node.object.get('title') }}</a>
        {% if node.hasChildren %}{{ _self.branch(node.children) }}{% endif %}</li>
    {% endfor %}
  </ul>
{% endmacro %}
{{ _self.branch(tree(result)) }}
```

So a draft category is missing for visitors, and so is its branch.

## Separate trees: `tree_scope`

Some objects form not one tree but several: each menu has its own items. A
Hierarchical Blueprint names the relation that splits them, a required
single relation of the Blueprint:

```php
'menu_item' => [
    'capabilities' => [Titled::class, Link::class, Hierarchical::class, Weighted::class],
    'relations' => [new Relation('menu', Cardinality::One, targetBlueprints: ['menu'], required: true)],
    'tree_scope' => 'menu',
],
```

- **The parent must be in the same scope** (`tree.other_scope`).
- **Moving a node to another scope** (choosing another menu for it) moves the
  nodes below it too, in the same save.
- **The roots are siblings within their scope:** `SiblingOrder` moves the top
  items of one menu among themselves.
- **A scope object with nodes cannot be deleted** (`tree.scope_in_use`, on
  `children`), e.g. a menu with items.
- In the admin, the list is grouped or filtered by the scope, and the scope
  object's page shows its tree ([chapter 13](13-admin.md#trees-and-hand-set-order)).

## In the admin

A tree is listed as a tree, the parent is chosen from an indented list
without the object's own descendants, ↑ ↓ buttons change the order, and the
delete page lists a node's children
([chapter 13](13-admin.md#trees-and-hand-set-order)).

## Adding `Hierarchical` to an existing Blueprint

The objects become roots: `install` (or the upgrade page) adds the capability
([chapter 17](17-migrations.md#applying-the-definitions-schemasync)) and
fills in their paths (`TreeKeeper::repair()`). Arranging them into a tree is
then done in the admin.

## TreeKeeper

`Campanella\Tree\TreeKeeper` · **Internal** · used by the `ObjectRepository`

| Method | Description |
|---|---|
| `__construct(Connection $db)` | |
| `validate(CampanellaObject $object, ?string $scope = null): array<string, Message>` | The rules above (on `parent`); `$scope`: the Blueprint's `tree_scope` |
| `place(CampanellaObject $object, int $id): ?Closure` | Sets the path and the depth; returns the moving of the descendants if the path changed (run after the rows are written, in the same transaction) |
| `childCount(int $id): int` | The direct children |
| `carryScope(int $id, string $path, string $scope, int $target): void` | Gives the node's descendants its scope target (in the save transaction, after the relations are written) |
| `scopeMembers(int $id, list<string> $scopes): int` | How many objects point to the object through a scope relation (e.g. a menu's items) |
| `repair(): int` | Fills in the missing paths and depths from the parent relations (e.g. after the capability was added to existing objects); how many |
