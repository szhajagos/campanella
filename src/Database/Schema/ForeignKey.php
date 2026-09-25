<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

final readonly class ForeignKey
{
    public function __construct(
        public string $column,
        public string $referencedTable,
        public string $referencedColumn = 'id',
        public bool $cascadeDelete = true,
    ) {
    }
}
