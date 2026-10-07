<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;
use Campanella\Relation\Cardinality;
use Campanella\Relation\Relation;

/**
 * The object is a node of a tree: a `parent` relation to an object of the same
 * Blueprint (none: a root). Since 0.0.7.
 *
 * The position is kept in `tree_path` (a "materialized path": the IDs from the
 * root to the object, `/1/5/12/`) and `depth` (a root is 0). They are set by the
 * ObjectRepository on save, never by hand: so the descendants of a node are one
 * indexed `LIKE '/1/5/%'` query, and moving a node moves its subtree with one
 * UPDATE. The repository also refuses a parent of another Blueprint, a
 * descendant as the parent (a circle), more than MAX_DEPTH levels, and deleting
 * a node that has children.
 *
 * Queries:
 *
 *     Hierarchical::roots(Query::objects()->blueprint('category'))
 *     Hierarchical::childrenOf(Query::objects(), $category)
 *     Hierarchical::descendantsOf(Query::objects(), $category)
 *     Hierarchical::ancestorsOf(Query::objects(), $category)   // for breadcrumbs
 */
#[AsCapability('hierarchical', label: 'capability.hierarchical')]
final class Hierarchical extends Capability
{
    /** The most levels a tree may have (a root and 9 levels below it). */
    public const int MAX_DEPTH = 10;

    #[\Override]
    public static function fields(): array
    {
        return [
            // Managed by the repository; hidden in forms.
            new Field('tree_path', FieldType::String, indexed: true, length: 255, label: 'field.tree_path', hidden: true),
            new Field('depth', FieldType::Integer, required: true, default: 0, label: 'field.depth', hidden: true),
        ];
    }

    #[\Override]
    public static function relations(): array
    {
        return [
            new Relation('parent', Cardinality::One, targetCapabilities: [self::class], label: 'relation.parent'),
        ];
    }

    public function parentId(): ?int
    {
        return $this->object->relatedIds('parent')[0] ?? null;
    }

    /** The parent (null: the object becomes a root). Checked on save. */
    public function setParent(CampanellaObject|int|null $parent): void
    {
        $this->object->setRelated('parent', $parent === null ? [] : [$parent]);
    }

    public function isRoot(): bool
    {
        return $this->parentId() === null;
    }

    /** `/1/5/12/`; empty before the first save. */
    public function path(): string
    {
        return (string) $this->object->get('tree_path');
    }

    public function depth(): int
    {
        return (int) $this->object->get('depth');
    }

    /** @return list<int> The ancestors' IDs, from the root down (from the stored path). */
    public function ancestorIds(): array
    {
        $ids = array_map(intval(...), array_values(array_filter(explode('/', $this->path()), static fn (string $s): bool => $s !== '')));
        array_pop($ids); // the object itself

        return $ids;
    }

    /** Only the roots (no parent). */
    public static function roots(Query $query): Query
    {
        return $query->having('hierarchical')->where('depth', '=', 0);
    }

    public static function childrenOf(Query $query, CampanellaObject|int $parent): Query
    {
        return $query->whereRelated('parent', $parent);
    }

    /** Everything below the node, at any depth (not the node itself). */
    public static function descendantsOf(Query $query, CampanellaObject $node): Query
    {
        $path = $node->as(self::class)->path();

        return $query
            ->where('tree_path', 'LIKE', ($path === '' ? '/-/' : $path) . '%')
            ->where('id', '!=', (int) $node->id());
    }

    /** The node's ancestors (to be ordered by depth, e.g. for breadcrumbs). */
    public static function ancestorsOf(Query $query, CampanellaObject $node): Query
    {
        $ids = $node->as(self::class)->ancestorIds();

        return $ids === [] ? $query->where('id', '=', 0) : $query->where('id', 'IN', $ids);
    }
}
