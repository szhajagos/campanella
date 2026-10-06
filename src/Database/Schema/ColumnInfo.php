<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * A column as it is in the database (read by the SchemaReader).
 *
 * `$type` is the matching ColumnType, or null for a type Campanella does not
 * create (`$rawType` holds it as the server reports it, e.g. `decimal(10,2)`).
 * `$default` is the default value as text (unquoted), or null if there is none.
 */
final readonly class ColumnInfo
{
    public function __construct(
        public string $name,
        public ?ColumnType $type,
        public string $rawType,
        public bool $nullable,
        public ?string $default = null,
        public bool $autoIncrement = false,
        public ?int $length = null,
    ) {
    }
}
