<?php

declare(strict_types=1);

namespace Campanella\Database\Migration;

/**
 * Campanella's own migrations, in order. A new one is only ever appended; a
 * released one is never changed or removed.
 */
final class CoreMigrations
{
    /** @return list<class-string<Migration>> */
    public static function classes(): array
    {
        return [
            Core\RolesMultiValue::class,
            Core\MediaUsageIndex::class,
        ];
    }
}
