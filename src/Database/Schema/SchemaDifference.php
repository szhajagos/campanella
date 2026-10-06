<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

use Campanella\I18n\Message;

/**
 * One difference between a table's definition and the database.
 *
 * `$additive`: it can be applied without losing or guessing data (a missing
 * table, a missing column that may be NULL or has a default, a missing index).
 * `$sql`: the statement that applies it, if one can be generated; for the
 * other kinds (e.g. a changed type) a migration decides what to do.
 */
final readonly class SchemaDifference
{
    public function __construct(
        public DifferenceKind $kind,
        public string $table,
        public ?string $name = null,
        public ?string $expected = null,
        public ?string $actual = null,
        public ?string $sql = null,
        public bool $additive = false,
    ) {
    }

    /** A readable description (`schema.<kind>` in the language files). */
    public function message(): Message
    {
        return new Message('schema.' . $this->kind->value, [
            'table' => $this->table,
            'name' => $this->name ?? '',
            'expected' => $this->expected ?? '–',
            'actual' => $this->actual ?? '–',
        ]);
    }
}
