<?php

declare(strict_types=1);

namespace Campanella\Search;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Database\Schema\SchemaReader;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\ObjectRepository;

/**
 * Builds the search's index (since 0.2.7) for the objects saved before it existed
 * (after the upgrade to 0.2.7), or again for all of them: from the System page in
 * batches, as many as fit into the time, or with `php bin/campanella search:index`.
 * Later saves keep it up to date by themselves (ObjectRepository).
 */
final class SearchIndexer
{
    /** The objects read at once. */
    private const int BATCH = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly ObjectRepository $repository,
        private readonly BlueprintRegistry $blueprints,
    ) {
    }

    /** How many objects are in the index. */
    public function indexed(): int
    {
        return $this->ready() ? (int) $this->db->fetchValue('SELECT COUNT(*) FROM {search_index}') : 0;
    }

    /** How many objects that should be in the index are not (0 before the table exists). */
    public function missing(): int
    {
        if (!$this->ready()) {
            return 0;
        }

        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM {objects} o WHERE ' . self::indexable() . ' AND NOT EXISTS (SELECT 1 FROM {search_index} s WHERE s.object_id = o.id)');
    }

    /**
     * Indexes the missing objects (or, with $all, every one again), until the time is up.
     *
     * @return array{0: int, 1: int} How many were indexed, and how many are still missing
     */
    public function build(float $seconds = 20.0, bool $all = false, ?\Closure $progress = null): array
    {
        if (!$this->ready()) {
            return [0, 0];
        }
        $start = microtime(true);
        $done = 0;
        $after = 0;
        while (microtime(true) - $start < $seconds) {
            $ids = array_map(intval(...), $this->db->fetchColumn(
                'SELECT o.id FROM {objects} o WHERE ' . self::indexable() . ' AND o.id > :after'
                . ($all ? '' : ' AND NOT EXISTS (SELECT 1 FROM {search_index} s WHERE s.object_id = o.id)')
                . ' ORDER BY o.id LIMIT ' . self::BATCH,
                ['after' => $after],
            ));
            if ($ids === []) {
                break;
            }
            foreach ($this->repository->loadMany($ids) as $id => $object) {
                SearchIndex::record($this->db, $id, $object, $this->blueprints->find($object->blueprint()));
                $done++;
            }
            $after = end($ids);
            if ($progress !== null) {
                $progress($done);
            }
        }

        return [$done, $this->missing()];
    }

    private function ready(): bool
    {
        return (new SchemaReader($this->db))->tableExists(CoreSchema::SEARCH_INDEX);
    }

    /** The objects with a page of their own, but not users or messages (SearchIndex::NEVER). */
    private static function indexable(): string
    {
        $never = implode(', ', array_map(static fn (string $c): string => "'" . $c . "'", SearchIndex::NEVER));

        return "EXISTS (SELECT 1 FROM {object_capabilities} r WHERE r.object_id = o.id AND r.capability = 'routable')"
            . " AND NOT EXISTS (SELECT 1 FROM {object_capabilities} n WHERE n.object_id = o.id AND n.capability IN ({$never}))";
    }
}
