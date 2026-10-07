<?php

declare(strict_types=1);

namespace Campanella\Tree;

use Campanella\Capability\Hierarchical;
use Campanella\Model\CampanellaObject;

/**
 * Turns a list of Hierarchical objects (e.g. a query's result) into a tree.
 * Siblings keep the order of the list, so ordering the query (e.g. by weight)
 * orders every level. An object whose parent is not in the list becomes a root
 * (so a subtree can be built from descendantsOf()), or with $keepOrphans false
 * it is left out together with its subtree (a hidden parent hides its branch).
 *
 *     $tree = TreeBuilder::build($queries->execute(Query::objects()->blueprint('category')->scope('by_weight'), $actor));
 *     foreach (TreeBuilder::flatten($tree) as $node) { echo str_repeat('— ', $node->level), $node->object->get('title'); }
 */
final class TreeBuilder
{
    /**
     * @param iterable<CampanellaObject> $objects
     * @param bool $keepOrphans Whether an object whose parent is not in the list becomes a root
     * @return list<TreeNode> The roots
     */
    public static function build(iterable $objects, bool $keepOrphans = true): array
    {
        $byId = [];
        $children = [];
        $order = [];
        foreach ($objects as $object) {
            $id = (int) $object->id();
            $byId[$id] = $object;
            $order[] = $id;
        }
        $roots = [];
        foreach ($order as $id) {
            $parent = $byId[$id]->has(Hierarchical::class) ? $byId[$id]->as(Hierarchical::class)->parentId() : null;
            if ($parent !== null && isset($byId[$parent]) && $parent !== $id) {
                $children[$parent][] = $id;
            } elseif ($parent === null || $parent === $id || $keepOrphans) {
                $roots[] = $id;
            }
        }

        $make = static function (int $id, int $level, array $seen) use (&$make, $byId, $children): TreeNode {
            $node = new TreeNode($byId[$id], [], $level);
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                if (!isset($seen[$child])) { // never loops, even on inconsistent data
                    $node->children[] = $make($child, $level + 1, $seen);
                }
            }

            return $node;
        };

        return array_map(static fn (int $id): TreeNode => $make($id, 0, []), $roots);
    }

    /**
     * The tree in display order (a node, then its subtree), each with its level.
     *
     * @param list<TreeNode> $roots
     * @return list<TreeNode>
     */
    public static function flatten(array $roots): array
    {
        $list = [];
        $walk = static function (array $nodes) use (&$walk, &$list): void {
            foreach ($nodes as $node) {
                $list[] = $node;
                $walk($node->children);
            }
        };
        $walk($roots);

        return $list;
    }
}
