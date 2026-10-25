<?php

declare(strict_types=1);

namespace Campanella\Import;

use Campanella\I18n\Message;
use Campanella\I18n\Translator;

/**
 * A run of an importer (since 0.3.1), as its log keeps it: where it is (`running`,
 * `done`, `cancelled`, `undone`), how many items it made, updated, found unchanged
 * and could not import, how many objects it made drafts (whose items left the
 * source), and the first errors by item.
 */
final readonly class ImportRun
{
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string CANCELLED = 'cancelled';
    public const string UNDONE = 'undone';

    /**
     * @param list<array{row: int, key: string, field: string, message: Message}> $errors
     */
    public function __construct(
        public int $id,
        public string $importer,
        public string $status,
        public string $sourceName,
        public int $position,
        public int $total,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $failed,
        public int $missing,
        public array $errors,
        public ?int $userId,
        public string $startedAt,
        public ?string $finishedAt,
        public ?string $undoneAt,
    ) {
    }

    /** @param array<string, mixed> $row A row of the `import_runs` table */
    public static function fromRow(array $row): self
    {
        $errors = [];
        foreach ((array) json_decode((string) $row['errors'], true) as $error) {
            if (is_array($error)) {
                $errors[] = [
                    'row' => (int) ($error['row'] ?? 0),
                    'key' => (string) ($error['key'] ?? ''),
                    'field' => (string) ($error['field'] ?? ''),
                    'message' => new Message((string) ($error['message'] ?? 'import.error.value'), array_map(
                        static fn (mixed $v): string|int|float => is_int($v) || is_float($v) ? $v : (string) (is_scalar($v) ? $v : ''),
                        is_array($error['params'] ?? null) ? $error['params'] : [],
                    )),
                ];
            }
        }

        return new self(
            (int) $row['id'],
            (string) $row['importer'],
            (string) $row['status'],
            (string) $row['source_name'],
            (int) $row['position'],
            (int) $row['total'],
            (int) $row['created'],
            (int) $row['updated'],
            (int) $row['unchanged'],
            (int) $row['failed'],
            (int) $row['missing'],
            $errors,
            $row['user_id'] === null ? null : (int) $row['user_id'],
            (string) $row['started_at'],
            $row['finished_at'] === null ? null : (string) $row['finished_at'],
            $row['undone_at'] === null ? null : (string) $row['undone_at'],
        );
    }

    /**
     * An import's message in the translator's language; a field's or relation's label
     * that is a message key (a Blueprint of config/blueprints.php) is translated too.
     */
    public static function text(Message $message, Translator $translator): string
    {
        $params = $message->params;
        foreach (['field', 'relation'] as $name) {
            if (is_string($params[$name] ?? null) && $translator->has($params[$name])) {
                $params[$name] = $translator->translate($params[$name]);
            }
        }

        return $translator->translate($message->key, $params);
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /** Its share done, 0–100. */
    public function percent(): int
    {
        return $this->total === 0 ? ($this->isRunning() ? 0 : 100) : (int) min(100, floor($this->position * 100 / $this->total));
    }
}
