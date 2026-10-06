<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * A table as it is in the database (read by the SchemaReader). `$name` is
 * without the table prefix, like in Table.
 */
final readonly class TableInfo
{
    /**
     * @param array<string, ColumnInfo> $columns By name, in the table's order
     * @param list<string> $primaryKey
     * @param array<string, list<string>> $indexes Non-unique indexes: name => columns
     * @param array<string, list<string>> $uniques Unique indexes (without the primary key): name => columns
     * @param array<string, array{column: string, table: string, referencedColumn: string, cascadeDelete: bool}> $foreignKeys By constraint name; `table` without the prefix
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

    public function column(string $name): ?ColumnInfo
    {
        return $this->columns[$name] ?? null;
    }

    public function hasIndex(string $name): bool
    {
        return isset($this->indexes[$name]) || isset($this->uniques[$name]);
    }
}
