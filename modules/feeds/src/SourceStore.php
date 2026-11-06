<?php

declare(strict_types=1);

namespace CampanellaFeeds;

use Campanella\Database\Connection;
use Campanella\I18n\Message;
use Campanella\Schedule\Scheduler;
use DateTimeImmutable;

/** The sources, in the module's table `feeds_sources`. */
final class SourceStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Source> By their importers' names */
    public function all(): array
    {
        return array_map(self::source(...), $this->db->fetchAll('SELECT * FROM {feeds_sources} ORDER BY importer'));
    }

    public function find(int $id): ?Source
    {
        $row = $this->db->fetchOne('SELECT * FROM {feeds_sources} WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::source($row);
    }

    public function findByImporter(string $importer): ?Source
    {
        $row = $this->db->fetchOne('SELECT * FROM {feeds_sources} WHERE importer = :i', ['i' => $importer]);

        return $row === null ? null : self::source($row);
    }

    /**
     * The sources that are on and due at that time (a run of theirs going on counts as due).
     *
     * @return list<Source>
     */
    public function due(DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->all(), static fn (Source $s): bool => $s->enabled
            && ($s->lastStatus === Source::RUNNING || $s->nextDue === null || $s->nextDue <= Scheduler::format($now))));
    }

    /** A new source, due at once; its ID. */
    public function create(string $importer, string $url, int $everyMinutes, bool $enabled): int
    {
        $now = Scheduler::format(Scheduler::now());

        return $this->db->insert('feeds_sources', [
            'importer' => $importer,
            'url' => $url,
            'kind' => 'file',
            'every_minutes' => $everyMinutes,
            'enabled' => (int) $enabled,
            'failures' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Its settings changed; a new address is fetched in full next time, at once. */
    public function update(Source $source, string $url, int $everyMinutes, bool $enabled): void
    {
        $values = ['every_minutes' => $everyMinutes, 'enabled' => (int) $enabled, 'updated_at' => Scheduler::format(Scheduler::now())];
        if ($url !== $source->url) {
            $values += ['url' => $url, 'etag' => null, 'last_modified' => null, 'content_hash' => null, 'next_due' => null, 'failures' => 0];
        }
        $this->db->update('feeds_sources', $values, ['id' => $source->id]);
    }

    /** Due in the next cron:run. */
    public function makeDue(Source $source): void
    {
        $this->db->update('feeds_sources', ['next_due' => null], ['id' => $source->id]);
    }

    /** @param array<string, string|int|null> $values */
    public function record(Source $source, array $values): void
    {
        if (array_key_exists('last_message', $values) && $values['last_message'] !== null && !is_string($values['last_message'])) {
            throw new \LogicException('A message is recorded as JSON.');
        }
        $this->db->update('feeds_sources', $values, ['id' => $source->id]);
    }

    public function delete(Source $source): void
    {
        $this->db->delete('feeds_sources', ['id' => $source->id]);
    }

    public static function json(Message $message): string
    {
        return (string) json_encode(['key' => $message->key, 'params' => $message->params], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param array<string, mixed> $row */
    private static function source(array $row): Source
    {
        $data = is_string($row['last_message'] ?? null) ? json_decode($row['last_message'], true) : null;
        $message = null;
        if (is_array($data) && is_string($data['key'] ?? null)) {
            /** @var array<string, string|int|float> $params */
            $params = array_filter((array) ($data['params'] ?? []), static fn ($v): bool => is_string($v) || is_int($v) || is_float($v));
            $message = new Message($data['key'], $params);
        }

        return new Source(
            (int) $row['id'],
            (string) $row['importer'],
            (string) $row['url'],
            (string) $row['kind'],
            (int) $row['every_minutes'],
            (bool) $row['enabled'],
            $row['etag'] === null ? null : (string) $row['etag'],
            $row['last_modified'] === null ? null : (string) $row['last_modified'],
            $row['content_hash'] === null ? null : (string) $row['content_hash'],
            $row['run_id'] === null ? null : (int) $row['run_id'],
            $row['next_due'] === null ? null : (string) $row['next_due'],
            $row['last_fetched'] === null ? null : (string) $row['last_fetched'],
            $row['last_status'] === null ? null : (string) $row['last_status'],
            $message,
            (int) $row['failures'],
        );
    }
}
