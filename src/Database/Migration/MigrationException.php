<?php

declare(strict_types=1);

namespace Campanella\Database\Migration;

use Campanella\I18n\Message;

/**
 * Migrating stopped: another run holds the lock, or a migration failed. The
 * migrations before the failed one are recorded as applied; the failed one and
 * the rest are still pending.
 */
final class MigrationException extends \RuntimeException
{
    /**
     * @param list<MigrationResult> $applied What ran before the failure
     */
    public function __construct(
        public readonly Message $reason,
        public readonly ?string $migrationId = null,
        public readonly array $applied = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason->key . ($migrationId !== null ? " ({$migrationId})" : '') . ($previous !== null ? ': ' . $previous->getMessage() : ''), 0, $previous);
    }
}
