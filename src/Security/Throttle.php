<?php

declare(strict_types=1);

namespace Campanella\Security;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Http\Request;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Limits attempts per key (e.g. "e-mail address + IP address") within a
 * time window. It stores in the database, so it works across multiple PHP
 * processes and restarts.
 *
 * Only a keyed hash of the key is stored (HMAC-SHA-256 with a random secret of the
 * installation, kept in the `system` table; since 0.1.5, before: a plain SHA-256),
 * so the IP and e-mail addresses in the keys cannot be recovered from the table by
 * trying every address. Expired rows are deleted now and then (purge()), so nothing
 * is kept longer than its time window.
 */
final class Throttle
{
    /** The `system` row of the installation's secret. */
    public const string SECRET = 'throttle_key';

    /** One hit in this many also deletes the expired rows. */
    public const int PURGE_EVERY = 20;

    private ?string $secret = null;

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
        $hash = $this->hash($key);
        $now = self::now();
        // "%+d": "-5 seconds", not "+-5 seconds", which some PHP 8.3 versions read as +5.
        $reset = $now->modify(sprintf('%+d seconds', $decaySeconds))->format(FieldType::STORAGE_DATE_FORMAT);
        $nowText = $now->format(FieldType::STORAGE_DATE_FORMAT);

        $hits = $this->db->transactional(function (Connection $db) use ($hash, $reset, $nowText): int {
            // The assignments run left to right: `hits` still sees the old `reset_at`.
            $db->execute(
                'INSERT INTO {throttle} (key_hash, hits, reset_at) VALUES (:k, 1, :reset)
                 ON DUPLICATE KEY UPDATE hits = IF(reset_at <= :now1, 1, hits + 1), reset_at = IF(reset_at <= :now2, :reset2, reset_at)',
                ['k' => $hash, 'reset' => $reset, 'now1' => $nowText, 'now2' => $nowText, 'reset2' => $reset],
            );

            return (int) $db->fetchValue('SELECT hits FROM {throttle} WHERE key_hash = :k', ['k' => $hash]);
        });
        // After the commit, so the deletion holds no lock of the count above.
        if (random_int(1, self::PURGE_EVERY) === 1 && !$this->db->pdo()->inTransaction()) {
            $this->purge();
        }

        return $hits;
    }

    /**
     * Deletes the rows whose window has passed (they count for nothing).
     *
     * @return int How many were deleted
     */
    public function purge(): int
    {
        return $this->db->execute('DELETE FROM {throttle} WHERE reset_at <= :now', ['now' => self::now()->format(FieldType::STORAGE_DATE_FORMAT)]);
    }

    /**
     * The address a limit counts by (since 0.2.0): an IPv4 address as it is (an
     * IPv4-mapped IPv6 one as plain IPv4), an IPv6 address by its network of
     * `$ipv6Bits` bits, e.g. `2001:db8:1::/48`. One connection usually gets a whole
     * /64, often a /48: counted per address, it could make 2^16 or more as many tries.
     */
    public static function network(string $ip, int $ipv6Bits = 48): string
    {
        $ip = Request::normalizeIp($ip);
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        $bits = max(0, min(128, $ipv6Bits));
        $bytes = intdiv($bits, 8);
        $network = substr($packed, 0, $bytes);
        if ($bits % 8 !== 0) {
            $network .= chr(ord($packed[$bytes]) & (0xff << (8 - $bits % 8) & 0xff));
        }

        return (string) inet_ntop(str_pad($network, 16, "\0")) . '/' . $bits;
    }

    /** In how many seconds another attempt is allowed (0 if right now). */
    public function availableIn(string $key): int
    {
        $row = $this->row($key);

        return $row === null ? 0 : max(0, $row['reset_at']->getTimestamp() - self::now()->getTimestamp());
    }

    public function clear(string $key): void
    {
        $this->db->delete(CoreSchema::THROTTLE, ['key_hash' => $this->hash($key)]);
    }

    /** @return array{hits: int, reset_at: DateTimeImmutable}|null Only a row that is still valid. */
    private function row(string $key): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT hits, reset_at FROM {throttle} WHERE key_hash = :k',
            ['k' => $this->hash($key)],
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

    private function hash(string $key): string
    {
        return hash_hmac('sha256', $key, $this->secret());
    }

    /** The installation's secret, made on first use. */
    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        $read = fn (): mixed => $this->db->fetchValue('SELECT value FROM {system} WHERE name = :name', ['name' => self::SECRET]);
        $secret = $read();
        if (!is_string($secret) || strlen($secret) < 32) {
            // Two processes at once: the first one's secret stays (INSERT IGNORE), both read it.
            $this->db->execute('INSERT IGNORE INTO {system} (name, value) VALUES (:name, :value)', ['name' => self::SECRET, 'value' => bin2hex(random_bytes(32))]);
            $secret = $read();
        }

        return $this->secret = is_string($secret) ? $secret : '';
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
