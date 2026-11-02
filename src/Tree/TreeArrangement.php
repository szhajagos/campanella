<?php

declare(strict_types=1);

namespace Campanella\Tree;

use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\Weighted;
use Campanella\Database\Connection;
use Campanella\I18n\Message;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;

/**
 * A whole tree arranged at once, as it was dragged (since 0.4.4): every node's parent and its
 * place among its siblings. For a Blueprint that is Hierarchical and Weighted; with a
 * 'tree_scope' (e.g. menu items), one scope's tree at a time (e.g. one menu's items).
 *
 * The nodes are given in tree order (pre-order: a parent before its children), each with its
 * parent (0: a root). The set must be the tree's as it is now (otherwise the page was out of
 * date), every node must be one the actor may change, and the new tree must have no circle
 * and fit in Hierarchical::MAX_DEPTH levels. All of it is done in one transaction, or nothing:
 *
 *   1. every node whose parent changes is made a root first (so no step makes a circle or a
 *      tree too deep on the way), through ObjectService (its checks, its events);
 *   2. then put under its new parent, in tree order;
 *   3. the siblings are numbered 0, 10, 20 … (SiblingOrder::STEP) in their new order.
 */
final class TreeArrangement
{
    /** The most nodes arranged at once (as many as the admin lists as a tree). */
    public const int MAX_NODES = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly QueryEngine $queries,
        private readonly ObjectService $objects,
        private readonly AccessPolicy $policy,
        private readonly BlueprintRegistry $blueprints,
    ) {
    }

    /** Whether the Blueprint's trees can be arranged: Hierarchical and Weighted. */
    public function supports(string $blueprint): bool
    {
        $found = $this->blueprints->find($blueprint);

        return $found !== null && isset($found->capabilities['hierarchical'], $found->capabilities['weighted']);
    }

    /**
     * The tree as it is now, for the actor: its roots (TreeNode, the siblings by weight); null if
     * it has more than MAX_NODES.
     *
     * @param int|null $scope The scope's target (e.g. the menu's ID), for a Blueprint with a 'tree_scope'
     * @return list<TreeNode>|null
     */
    public function tree(Actor $actor, string $blueprint, ?int $scope = null): ?array
    {
        $nodes = $this->nodes($actor, $blueprint, $scope);

        return $nodes === null ? null : TreeBuilder::build($nodes);
    }

    /**
     * @param list<array{0: int, 1: int}> $nodes [ID, parent ID or 0], in tree order
     * @param int|null $scope The scope's target, as for tree()
     * @return int How many nodes changed (their parent or their place)
     * @throws ValidationException (`tree`) if the nodes are not the tree's, or the new tree is not valid
     * @throws \Campanella\Access\AccessDeniedException if the actor may not change a node
     */
    public function arrange(Actor $actor, string $blueprint, array $nodes, ?int $scope = null): int
    {
        $current = $this->nodes($actor, $blueprint, $scope);
        if ($current === null || !$this->supports($blueprint)) {
            throw new ValidationException(['tree' => new Message('tree.arrange_too_many', ['max' => self::MAX_NODES])]);
        }
        $byId = [];
        foreach ($current as $object) {
            $byId[(int) $object->id()] = $object;
        }
        $parents = [];
        foreach ($nodes as [$id, $parent]) {
            if (!isset($byId[$id]) || isset($parents[$id])) {
                throw new ValidationException(['tree' => new Message('tree.arrange_outdated')]);
            }
            // A parent comes before its children.
            if ($parent !== 0 && !isset($parents[$parent])) {
                throw new ValidationException(['tree' => new Message('tree.arrange_invalid')]);
            }
            $parents[$id] = $parent;
        }
        if (count($parents) !== count($byId)) {
            throw new ValidationException(['tree' => new Message('tree.arrange_outdated')]);
        }
        // The levels: a parent comes first, so its level is known (and no circle is possible).
        $levels = [];
        $siblings = [];
        foreach ($parents as $id => $parent) {
            $levels[$id] = $parent === 0 ? 0 : $levels[$parent] + 1;
            if ($levels[$id] >= Hierarchical::MAX_DEPTH) {
                throw new ValidationException(['tree' => new Message('tree.too_deep', ['max' => Hierarchical::MAX_DEPTH])]);
            }
            $siblings[$parent][] = $id;
        }

        // What changes, and whether the actor may change it.
        $moved = [];
        $weights = [];
        foreach ($siblings as $ids) {
            foreach ($ids as $position => $id) {
                $object = $byId[$id];
                $weight = $position * SiblingOrder::STEP;
                $before = (int) $object->as(Hierarchical::class)->parentId();
                if ($before !== $parents[$id]) {
                    $moved[$id] = $parents[$id];
                }
                if ((int) $object->as(Weighted::class)->weight() !== $weight) {
                    $weights[$id] = $weight;
                }
                if ((isset($moved[$id]) || isset($weights[$id])) && !$this->policy->allows($actor, Operation::Update, $object)) {
                    throw new \Campanella\Access\AccessDeniedException('The actor may not change object ' . $id . '.');
                }
            }
        }
        if ($moved === [] && $weights === []) {
            return 0;
        }

        $this->db->transactional(function () use ($actor, $byId, $moved, $weights): void {
            foreach (array_keys($moved) as $id) {
                if ((int) $byId[$id]->as(Hierarchical::class)->parentId() !== 0) {
                    $this->objects->update($actor, $byId[$id], [], ['parent' => []]);
                }
            }
            foreach ($moved as $id => $parent) {
                if ($parent !== 0) {
                    $this->objects->update($actor, $byId[$id], [], ['parent' => [$parent]]);
                }
            }
            foreach ($weights as $id => $weight) {
                $this->db->update('cap_weighted', ['weight' => $weight], ['object_id' => $id]);
            }
        });

        return count($moved + $weights);
    }

    /**
     * The tree's objects the actor may see, or null if there are more than MAX_NODES.
     *
     * @return list<CampanellaObject>|null
     */
    private function nodes(Actor $actor, string $blueprint, ?int $scope): ?array
    {
        $query = Query::objects()->blueprint($blueprint);
        $name = $this->blueprints->find($blueprint)?->treeScope;
        if ($name !== null) {
            $query = $query->whereRelated($name, $scope ?? 0);
        }
        $found = $this->queries->execute($query->orderBy('weight', 'asc')->orderBy('id', 'asc')->limit(self::MAX_NODES + 1), $actor)->items;

        return count($found) > self::MAX_NODES ? null : $found;
    }
}
