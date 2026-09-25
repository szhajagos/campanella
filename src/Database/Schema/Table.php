<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * Egy tábla leírása. A séma nem SQL-fájlokban él, hanem ilyen
 * objektumokban: a mag táblái a CoreSchema-ban, a capability-táblák
 * pedig a capability-k mezőiből keletkeznek.
 */
final readonly class Table
{
    /**
     * @param list<Column> $columns
     * @param list<string> $primaryKey
     * @param array<string, list<string>> $indexes Indexnév => oszlopok
     * @param array<string, list<string>> $uniques Indexnév => oszlopok
     * @param list<ForeignKey> $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primaryKey,
        public array $indexes = [],
        public array $uniques = [],
        public array $foreignKeys = [],
    ) {
    }
}
