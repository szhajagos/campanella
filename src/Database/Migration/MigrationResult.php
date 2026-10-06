<?php

declare(strict_types=1);

namespace Campanella\Database\Migration;

/** A migration that has run: its ID, description and how long it took. */
final readonly class MigrationResult
{
    public function __construct(
        public string $id,
        public string $description,
        public int $durationMs,
    ) {
    }
}
