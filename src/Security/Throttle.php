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

    /** Records a failed attempt; returns the number of attempts. */
    public function hit(string $key, int $decaySeconds): int
    {
        $hash = self::hash($key);
        $now = self::now();

        return $this->db->transactional(function (Connection $db) use ($key, $hash, $now, $decaySeconds): int {
            $row = $this->row($key);
            if ($row === null) {
                $db->delete(CoreSchema::THROTTLE, ['key_hash' => $hash]);   // expired row
                $db->insert(CoreSchema::THROTTLE, [
                    'key_hash' => $hash,
                    'hits' => 1,
                    'reset_at' => $now->modify("+{$decaySeconds} seconds")->format(FieldType::STORAGE_DATE_FORMAT),
                ]);

                return 1;
            }
            $db->update(CoreSchema::THROTTLE, ['hits' => $row['hits'] + 1], ['key_hash' => $hash]);

            return $row['hits'] + 1;
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
