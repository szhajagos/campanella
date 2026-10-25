<?php

declare(strict_types=1);

namespace Campanella\Import;

use Campanella\I18n\Message;

/**
 * A trial run (since 0.3.1): what the first items of a file would become, changing
 * nothing. `rows`: the item's number, key, what would happen (`create`, `update`,
 * `unchanged`, `error`), its values as they would be saved (shown as text), the
 * relations' targets that would be made, and the error.
 */
final readonly class ImportTrial
{
    public const string CREATE = 'create';
    public const string UPDATE = 'update';
    public const string UNCHANGED = 'unchanged';
    public const string ERROR = 'error';

    /**
     * @param list<string> $columns The file's headings (keys)
     * @param list<string> $missingSources The mapping's sources the file has no column of
     * @param list<array{row: int, key: string, action: string, values: array<string, string>, creates: list<string>, error: ?Message, field: string}> $rows
     */
    public function __construct(
        public int $total,
        public array $columns,
        public array $missingSources,
        public array $rows,
    ) {
    }

    /** How many of the rows shown have the action. */
    public function count(string $action): int
    {
        return count(array_filter($this->rows, static fn (array $row): bool => $row['action'] === $action));
    }
}
