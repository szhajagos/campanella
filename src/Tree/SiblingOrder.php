<?php

declare(strict_types=1);

namespace Campanella\Tree;

use Campanella\Capability\Hierarchical;
use Campanella\Capability\Weighted;
use Campanella\Database\Connection;
use Campanella\Model\CampanellaObject;

/**
 * Moving a Weighted object up or down among its siblings: the objects of its
 * Blueprint, or, for a Hierarchical one, the children of the same parent (the
 * roots for a root). The siblings are numbered again (0, 10, 20 …) in the new
 * order, so equal or scattered weights become a clean sequence.
 *
 * Only the weights change (one UPDATE per changed sibling, in a transaction);
 * nothing else of the objects is saved again.
 */
final class SiblingOrder
{
    public const int STEP = 10;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param int $direction -1: up (earlier), 1: down (later)
     * @return bool Whether it moved (false at the first or last place)
     */
    public function move(CampanellaObject $object, int $direction): bool
    {
        if (!$object->has(Weighted::class) || $object->id() === null || !in_array($direction, [-1, 1], true)) {
            return false;
        }
        $ids = $this->siblings($object);
        $index = array_search((int) $object->id(), $ids, true);
        $target = $index === false ? -1 : $index + $direction;
        if ($index === false || $target < 0 || $target >= count($ids)) {
            return false;
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        $ids = array_values($ids);

        $this->db->transactional(function (Connection $db) use ($ids): void {
            $current = $this->weights($ids);
            foreach ($ids as $position => $id) {
                $weight = $position * self::STEP;
                if (($current[$id] ?? null) !== $weight) {
                    $db->update('cap_weighted', ['weight' => $weight], ['object_id' => $id]);
                }
            }
        });

        return true;
    }

    /** @return list<int> The siblings' IDs (the object too), in their current order */
    public function siblings(CampanellaObject $object): array
    {
        $params = ['b' => $object->blueprint()];
        $sql = 'SELECT o.id FROM {objects} o JOIN {cap_weighted} w ON w.object_id = o.id';
        if ($object->has(Hierarchical::class)) {
            $parent = $object->as(Hierarchical::class)->parentId();
            $sql .= " LEFT JOIN {relationships} p ON p.source_id = o.id AND p.type = 'parent' WHERE o.blueprint = :b";
            if ($parent === null) {
                $sql .= ' AND p.target_id IS NULL';
            } else {
                $sql .= ' AND p.target_id = :parent';
                $params['parent'] = $parent;
            }
        } else {
            $sql .= ' WHERE o.blueprint = :b';
        }

        return array_map(intval(...), $this->db->fetchColumn($sql . ' ORDER BY w.weight, o.id', $params));
    }

    /**
     * @param list<int> $ids
     * @return array<int, int>
     */
    private function weights(array $ids): array
    {
        $params = [];
        foreach ($ids as $i => $id) {
            $params['i' . $i] = $id;
        }
        $rows = $this->db->fetchAll(
            'SELECT object_id, weight FROM {cap_weighted} WHERE object_id IN (' . implode(', ', array_map(static fn (string $k): string => ':' . $k, array_keys($params))) . ')',
            $params,
        );
        $weights = [];
        foreach ($rows as $row) {
            $weights[(int) $row['object_id']] = (int) $row['weight'];
        }

        return $weights;
    }
}
