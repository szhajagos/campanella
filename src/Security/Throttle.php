<?php

declare(strict_types=1);

namespace Campanella\Security;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Próbálkozások korlátozása kulcsonként (pl. „e-mail-cím + IP-cím”) egy
 * időablakon belül. Az adatbázisban tárol, így több PHP-folyamat és
 * újraindítás esetén is működik. A kulcsnak csak a SHA-256 hash-e kerül
 * az adatbázisba.
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

    /** Egy sikertelen próbálkozás rögzítése; visszaadja a próbálkozások számát. */
    public function hit(string $key, int $decaySeconds): int
    {
        $hash = self::hash($key);
        $now = self::now();

        return $this->db->transactional(function (Connection $db) use ($key, $hash, $now, $decaySeconds): int {
            $row = $this->row($key);
            if ($row === null) {
                $db->delete(CoreSchema::THROTTLE, ['key_hash' => $hash]);   // lejárt sor
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

    /** Hány másodperc múlva lehet újra próbálkozni (0, ha most is). */
    public function availableIn(string $key): int
    {
        $row = $this->row($key);

        return $row === null ? 0 : max(0, $row['reset_at']->getTimestamp() - self::now()->getTimestamp());
    }

    public function clear(string $key): void
    {
        $this->db->delete(CoreSchema::THROTTLE, ['key_hash' => self::hash($key)]);
    }

    /** @return array{hits: int, reset_at: DateTimeImmutable}|null Csak a még érvényes sor. */
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
