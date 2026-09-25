<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

final readonly class Column
{
    public function __construct(
        public string $name,
        public ColumnType $type,
        public bool $nullable = false,
        public string|int|null $default = null,
        public bool $autoIncrement = false,
        public int $length = 255,
    ) {
    }
}
