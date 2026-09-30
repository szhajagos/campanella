<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * Description of a table. The schema does not live in SQL files but in
 * objects like this: the core tables in CoreSchema, while capability
 * tables are generated from the capabilities' fields.
 */
final readonly class Table
{
    /**
     * @param list<Column> $columns
     * @param list<string> $primaryKey
     * @param array<string, list<string>> $indexes Index name => columns
     * @param array<string, list<string>> $uniques Index name => columns
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
