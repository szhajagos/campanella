<?php

declare(strict_types=1);

namespace Campanella\Tree;

use Campanella\Capability\Hierarchical;
use Campanella\Database\Connection;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;

/**
 * Keeps the trees of Hierarchical objects consistent; used by the
 * ObjectRepository on save and delete (the lowest layer, so nothing can
 * bypass it).
 *
 * - validate(): the parent must exist, be of the same Blueprint, and not be the
 *   object's own descendant; the subtree must fit in MAX_DEPTH levels.
 * - place(): sets the object's `tree_path` and `depth` from its parent's, once
 *   its ID is known; if the path changed (a move), the descendants are moved too.
 * - childCount(): a node with children cannot be deleted.
 */
final class TreeKeeper
{
    private const string TABLE = 'cap_hierarchical';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, Message> On the `parent` relation */
    public function validate(CampanellaObject $object): array
    {
        $parentId = $object->as(Hierarchical::class)->parentId();
        $id = $object->id();
        $height = $id === null ? 0 : $this->subtreeHeight($id);
        if ($parentId === null) {
            return $height + 1 > Hierarchical::MAX_DEPTH
                ? ['parent' => new Message('tree.too_deep', ['max' => Hierarchical::MAX_DEPTH])]
                : [];
        }
        $parent = $this->node($parentId);
        if ($parent === null) {
            return []; // reported by the relation check (no such target)
        }
        if ($parent['blueprint'] !== $object->blueprint()) {
            return ['parent' => new Message('tree.other_blueprint')];
        }
        if ($id !== null && str_contains($parent['path'], '/' . $id . '/')) {
            return ['parent' => new Message('tree.circular')];
        }
        if ($parent['depth'] + 1 + $height + 1 > Hierarchical::MAX_DEPTH) {
            return ['parent' => new Message('tree.too_deep', ['max' => Hierarchical::MAX_DEPTH])];
        }

        return [];
    }

    /**
     * Sets the object's path and depth (call in the save transaction, after the
     * object's row exists, before its capability rows are written).
     *
     * @return (\Closure(): void)|null What to do after the rows are written: moving the descendants
     */
    public function place(CampanellaObject $object, int $id): ?\Closure
    {
        $parentId = $object->as(Hierarchical::class)->parentId();
        $parent = $parentId === null ? null : $this->node($parentId);
        $path = ($parent === null || $parent['path'] === '' ? '/' : $parent['path']) . $id . '/';
        $depth = $parent === null ? 0 : $parent['depth'] + 1;

        $old = $this->node($id);
        $object->set('tree_path', $path);
        $object->set('depth', $depth);
        if ($old === null || $old['path'] === '' || $old['path'] === $path) {
            return null;
        }

        $db = $this->db;
        $oldPath = $old['path'];
        $shift = $depth - $old['depth'];

        return static function () use ($db, $oldPath, $path, $shift, $id): void {
            // The path is digits and slashes only: no LIKE wildcard in it.
            $db->execute(
                'UPDATE {' . self::TABLE . '} SET tree_path = CONCAT(:new, SUBSTRING(tree_path, :from)), depth = depth + :shift
                 WHERE tree_path LIKE :old AND object_id <> :id',
                ['new' => $path, 'from' => strlen($oldPath) + 1, 'shift' => $shift, 'old' => $oldPath . '%', 'id' => $id],
            );
        };
    }

    /** The number of the node's direct children. */
    public function childCount(int $id): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM {relationships} WHERE target_id = :id AND type = 'parent'",
            ['id' => $id],
        );
    }

    /** How many levels are below the node (0: it has no children). */
    private function subtreeHeight(int $id): int
    {
        $node = $this->node($id);
        if ($node === null || $node['path'] === '') {
            return 0;
        }
        $deepest = $this->db->fetchValue(
            'SELECT MAX(depth) FROM {' . self::TABLE . '} WHERE tree_path LIKE :path',
            ['path' => $node['path'] . '%'],
        );

        return $deepest === null ? 0 : max(0, (int) $deepest - $node['depth']);
    }

    /** @return array{blueprint: string, path: string, depth: int}|null */
    private function node(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT o.blueprint, h.tree_path, h.depth FROM {objects} o LEFT JOIN {' . self::TABLE . '} h ON h.object_id = o.id WHERE o.id = :id',
            ['id' => $id],
        );

        return $row === null ? null : [
            'blueprint' => (string) $row['blueprint'],
            'path' => (string) ($row['tree_path'] ?? ''),
            'depth' => (int) ($row['depth'] ?? 0),
        ];
    }
}
