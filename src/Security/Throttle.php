<?php

declare(strict_types=1);

namespace Campanella\Security;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Limits attempts per key (e.g. "e-mail address + IP address") within a
 * time window. It stores in the database, so it works across multiple PHP
 * processes and restarts. Only the SHA-256 hash of the key is stored in
 * the database.
 */
final class Throttle
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $row = $this->row($key);

        return $row !== null && $row['hits'] >= $maxAttempts;
    }

    /**
     * Records an attempt; returns the number of attempts in the current window.
     *
     * One atomic statement (since 0.1.0), so parallel requests cannot lose a count:
     * a new key starts at 1, an expired window starts again at 1.
     */
    public function hit(string $key, int $decaySeconds): int
    {
        $hash = self::hash($key);
        $now = self::now();
        $reset = $now->modify("+{$decaySeconds} seconds")->format(FieldType::STORAGE_DATE_FORMAT);
        $nowText = $now->format(FieldType::STORAGE_DATE_FORMAT);

        return $this->db->transactional(function (Connection $db) use ($hash, $reset, $nowText): int {
            // The assignments run left to right: `hits` still sees the old `reset_at`.
            $db->execute(
                'INSERT INTO {throttle} (key_hash, hits, reset_at) VALUES (:k, 1, :reset)
                 ON DUPLICATE KEY UPDATE hits = IF(reset_at <= :now1, 1, hits + 1), reset_at = IF(reset_at <= :now2, :reset2, reset_at)',
                ['k' => $hash, 'reset' => $reset, 'now1' => $nowText, 'now2' => $nowText, 'reset2' => $reset],
            );

            return (int) $db->fetchValue('SELECT hits FROM {throttle} WHERE key_hash = :k', ['k' => $hash]);
        });
    }

    /** In how many seconds another attempt is allowed (0 if right now). */
    public function availableIn(string $key): int
    {
        $row = $this->row($key);

        return $row === null ? 0 : max(0, $row['reset_at']->getTimestamp() - self::now()->getTimestamp());
    }

    public function clear(string $key): void
    {
        $this->db->delete(CoreSchema::THROTTLE, ['key_hash' => self::hash($key)]);
    }

    /** @return array{hits: int, reset_at: DateTimeImmutable}|null Only a row that is still valid. */
    private function row(string $key): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT hits, reset_at FROM {throttle} WHERE key_hash = :k',
            ['k' => self::hash($key)],
        );
        if ($row === null) {
            return null;
        }
        $resetAt = new DateTimeImmutable((string) $row['reset_at'], new DateTimeZone('UTC'));
        if ($resetAt <= self::now()) {
            return null;
        }

        return ['hits' => (int) $row['hits'], 'reset_at' => $resetAt];
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
