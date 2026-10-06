<?php

declare(strict_types=1);

namespace Campanella\Database\Migration;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\I18n\Message;
use Closure;

/**
 * Runs the pending migrations and records them in the `migrations` table.
 *
 * Only one run at a time: a named database lock (`GET_LOCK`) is held while
 * migrating, so a second run (another terminal, the browser) stops at once.
 * Each migration is recorded right after it ran; if one fails, the run stops
 * there: the earlier ones stay recorded, the failed one runs again next time.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $db,
        private readonly MigrationRegistry $registry,
    ) {
    }

    public function registry(): MigrationRegistry
    {
        return $this->registry;
    }

    /**
     * The recorded migrations: ID => when (UTC, `Y-m-d H:i:s`). Empty if the
     * table does not exist yet (before `install` created it).
     *
     * @return array<string, string>
     */
    public function applied(): array
    {
        if (!$this->db->tableExists(CoreSchema::MIGRATIONS)) {
            return [];
        }
        $applied = [];
        foreach ($this->db->fetchAll('SELECT id, applied_at FROM {migrations} ORDER BY applied_at, id') as $row) {
            $applied[(string) $row['id']] = (string) $row['applied_at'];
        }

        return $applied;
    }

    /** @return list<Migration> The registered migrations that have not run, in order */
    public function pending(): array
    {
        if ($this->registry->isEmpty()) {
            return [];
        }
        $applied = $this->applied();

        return array_values(array_filter(
            $this->registry->all(),
            static fn (Migration $m): bool => !isset($applied[$m->id()]),
        ));
    }

    /**
     * Runs the pending migrations, in order.
     *
     * @param (Closure(Migration): void)|null $starting Called before each migration
     * @param (Closure(string): void)|null $log The migrations' log lines
     * @return list<MigrationResult>
     * @throws MigrationException if another run is in progress, or a migration fails
     */
    public function run(?Closure $starting = null, ?Closure $log = null): array
    {
        if (!$this->lock()) {
            throw new MigrationException(new Message('migration.locked'));
        }
        $done = [];
        try {
            $context = new MigrationContext($this->db, $log);
            foreach ($this->pending() as $migration) {
                if ($starting !== null) {
                    $starting($migration);
                }
                $started = hrtime(true);
                try {
                    $migration->up($context);
                } catch (\Throwable $e) {
                    throw new MigrationException(new Message('migration.failed', ['id' => $migration->id()]), $migration->id(), $done, $e);
                }
                $result = new MigrationResult($migration->id(), $migration->description(), intdiv(hrtime(true) - $started, 1_000_000));
                $this->record($result);
                $done[] = $result;
            }
        } finally {
            $this->unlock();
        }

        return $done;
    }

    /**
     * Records every registered migration as applied without running it: for a
     * fresh installation, whose tables are created as the definitions are now.
     */
    public function markAllApplied(): void
    {
        $applied = $this->applied();
        foreach ($this->registry->all() as $migration) {
            if (!isset($applied[$migration->id()])) {
                $this->record(new MigrationResult($migration->id(), $migration->description(), 0));
            }
        }
    }

    private function record(MigrationResult $result): void
    {
        $this->db->insert(CoreSchema::MIGRATIONS, [
            'id' => $result->id,
            'description' => mb_substr($result->description, 0, 255, 'UTF-8'),
            'applied_at' => gmdate('Y-m-d H:i:s'),
            'duration_ms' => $result->durationMs,
        ]);
    }

    /** The lock is per database and table prefix (GET_LOCK is server-wide). */
    private function lockName(): string
    {
        return 'cmp_mig_' . substr(hash('sha256', $this->db->prefix()), 0, 16);
    }

    private function lock(): bool
    {
        return (int) $this->db->fetchValue(
            'SELECT GET_LOCK(CONCAT(:name, MD5(DATABASE())), 0)',
            ['name' => $this->lockName()],
        ) === 1;
    }

    private function unlock(): void
    {
        $this->db->fetchValue('SELECT RELEASE_LOCK(CONCAT(:name, MD5(DATABASE())))', ['name' => $this->lockName()]);
    }
}
