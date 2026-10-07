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
 * - validate(): the parent must exist, be of the same Blueprint (and of the same
 *   scope, e.g. menu, when the Blueprint has a 'tree_scope'), and not be the
 *   object's own descendant; the subtree must fit in MAX_DEPTH levels.
 * - place(): sets the object's `tree_path` and `depth` from its parent's, once
 *   its ID is known; if the path changed (a move), the descendants are moved too.
 * - carryScope(): a node moved to another scope takes its subtree with it.
 * - childCount(), scopeMembers(): a node with children, or a scope (e.g. a menu)
 *   with nodes, cannot be deleted.
 */
final class TreeKeeper
{
    private const string TABLE = 'cap_hierarchical';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param string|null $scope The Blueprint's 'tree_scope' relation
     * @return array<string, Message> On the `parent` relation
     */
    public function validate(CampanellaObject $object, ?string $scope = null): array
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
        $own = $scope === null ? null : ($object->relatedIds($scope)[0] ?? null);
        if ($scope !== null && $own !== null && $this->scopeOf($parentId, $scope) !== $own) {
            return ['parent' => new Message('tree.other_scope')];
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

    /**
     * Fills in the missing paths and depths (e.g. after `Hierarchical` was added to a
     * Blueprint with existing objects, whose rows got no path), from the parent
     * relations. Returns how many were filled.
     */
    public function repair(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT h.object_id FROM {" . self::TABLE . "} h WHERE h.tree_path IS NULL OR h.tree_path = ''",
        );
        $memo = [];
        $count = 0;
        foreach ($rows as $row) {
            $id = (int) $row['object_id'];
            $path = $this->pathOf($id, $memo, 0);
            $this->db->update(self::TABLE, ['tree_path' => $path, 'depth' => substr_count($path, '/') - 2], ['object_id' => $id]);
            $count++;
        }

        return $count;
    }

    /** @param array<int, string> $memo */
    private function pathOf(int $id, array &$memo, int $guard): string
    {
        if (isset($memo[$id])) {
            return $memo[$id];
        }
        $stored = $this->node($id);
        if ($stored !== null && $stored['path'] !== '') {
            return $memo[$id] = $stored['path'];
        }
        $parent = $this->db->fetchValue("SELECT target_id FROM {relationships} WHERE source_id = :id AND type = 'parent'", ['id' => $id]);
        // A broken chain (or a circle in old data) ends as a root rather than looping.
        $base = $parent === null || $guard >= Hierarchical::MAX_DEPTH ? '/' : $this->pathOf((int) $parent, $memo, $guard + 1);

        return $memo[$id] = $base . $id . '/';
    }

    /**
     * Gives the node's descendants the node's scope target (call in the save
     * transaction, after the node's relations are written): a node moved to
     * another menu takes its subtree with it.
     */
    public function carryScope(int $id, string $path, string $scope, int $target): void
    {
        if ($path === '') {
            return;
        }
        // The path is digits and slashes only: no LIKE wildcard in it.
        $this->db->execute(
            'UPDATE {relationships} SET target_id = :target
             WHERE type = :scope AND target_id <> :target2 AND source_id <> :id
               AND source_id IN (SELECT object_id FROM {' . self::TABLE . '} WHERE tree_path LIKE :path)',
            ['target' => $target, 'target2' => $target, 'scope' => $scope, 'id' => $id, 'path' => $path . '%'],
        );
    }

    /**
     * How many objects point to the object through a scope relation (e.g. the items of a menu).
     *
     * @param list<string> $scopes The 'tree_scope' relations
     */
    public function scopeMembers(int $id, array $scopes): int
    {
        if ($scopes === []) {
            return 0;
        }
        $params = ['id' => $id];
        foreach ($scopes as $i => $scope) {
            $params['s' . $i] = $scope;
        }
        $in = implode(', ', array_map(static fn (int $i): string => ':s' . $i, array_keys($scopes)));

        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM {relationships} WHERE target_id = :id AND type IN ({$in})", $params);
    }

    /** The node's target in the scope relation (e.g. its menu). */
    private function scopeOf(int $id, string $scope): ?int
    {
        $target = $this->db->fetchValue(
            'SELECT target_id FROM {relationships} WHERE source_id = :id AND type = :scope',
            ['id' => $id, 'scope' => $scope],
        );

        return $target === null ? null : (int) $target;
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
