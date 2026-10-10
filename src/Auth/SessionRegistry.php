<?php

declare(strict_types=1);

namespace Campanella\Auth;

use Campanella\Database\Connection;
use Campanella\Http\Request;

/**
 * The logins in progress, one row each in the `sessions` table (since 0.1.4, schema
 * version 10), so a user can see where they are logged in and end any of them.
 *
 * A login gets a random token, kept in its (server-side) session; the table holds
 * only the token's SHA-256 hash, never the session ID. On every request of a logged-in
 * user AuthService looks the token up: a deleted row ends the login there (its next
 * request is anonymous). Rows past the idle or the absolute lifetime are deleted at
 * the next login.
 *
 * Stored for the user's own list: the time of logging in and of the last activity,
 * the last IP address and the browser's User-Agent (at most 255 characters). The row
 * goes when the login ends.
 *
 * Before the upgrade that creates the table every method is a no-op (and lookups
 * answer "unknown"), so logging in still works and the upgrade can be run. Any other
 * database error is thrown (Connection::tableExists()): never "unknown".
 */
final class SessionRegistry
{
    /** The last activity is written at most this often, in seconds. */
    public const int TOUCH_INTERVAL = 60;

    public const int USER_AGENT_LENGTH = 255;

    private ?bool $available = null;

    public function __construct(
        private readonly Connection $db,
        private readonly int $idleTimeout = 7200,
        private readonly int $absoluteTimeout = AuthService::ABSOLUTE_TIMEOUT,
    ) {
    }

    /** Whether the table exists (read once). */
    public function isAvailable(): bool
    {
        // Only a missing table (before the upgrade) is a no: any other database error is
        // thrown, so a revoked login is never let through because of it.
        return $this->available ??= $this->db->tableExists('sessions');
    }

    /**
     * Records a new login; returns its token for the session (null before the upgrade).
     * Deletes the expired rows of every user.
     *
     * @param ?int $loggedInAt When the user logged in (a Unix time), if earlier than now:
     *        a login from before the table existed
     */
    public function start(int $userId, Request $request, ?int $loggedInAt = null): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $this->cleanup();
        $token = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('sessions', [
            'user_id' => $userId,
            'token_hash' => self::hash($token),
            'created_at' => $loggedInAt !== null && $loggedInAt < time() ? gmdate('Y-m-d H:i:s', $loggedInAt) : $now,
            'last_seen_at' => $now,
            'ip' => self::ipOf($request),
            'user_agent' => self::userAgentOf($request),
        ]);

        return $token;
    }

    /**
     * A request of the login with this token: true if it is still recorded for the user
     * (its last activity and IP address are updated, at most once a minute), false if
     * it was ended, null if unknown (before the upgrade).
     */
    public function touch(string $token, int $userId, Request $request): ?bool
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT id, user_id, last_seen_at, ip FROM {sessions} WHERE token_hash = :hash',
            ['hash' => self::hash($token)],
        );
        if ($row === null || (int) $row['user_id'] !== $userId) {
            return false;
        }
        $ip = self::ipOf($request);
        $seen = strtotime($row['last_seen_at'] . ' UTC');
        if ($seen === false || $seen + self::TOUCH_INTERVAL <= time() || $row['ip'] !== $ip) {
            $this->db->update('sessions', ['last_seen_at' => gmdate('Y-m-d H:i:s'), 'ip' => $ip], ['id' => (int) $row['id']]);
        }

        return true;
    }

    /** Ends the login with this token. */
    public function end(string $token): void
    {
        if ($this->isAvailable()) {
            $this->db->delete('sessions', ['token_hash' => self::hash($token)]);
        }
    }

    /** Ends one of the user's logins by its ID; false if the user has no such login. */
    public function endById(int $userId, int $id): bool
    {
        return $this->isAvailable() && $this->db->delete('sessions', ['id' => $id, 'user_id' => $userId]) > 0;
    }

    /**
     * Ends the user's logins, except the one with this token (null: every one).
     *
     * @return int How many were ended
     */
    public function endAll(int $userId, ?string $except = null): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        if ($except === null) {
            return $this->db->delete('sessions', ['user_id' => $userId]);
        }

        return $this->db->execute(
            'DELETE FROM {sessions} WHERE user_id = :user AND token_hash <> :hash',
            ['user' => $userId, 'hash' => self::hash($except)],
        );
    }

    /**
     * The user's logins in progress, the most recently active first.
     *
     * @param ?string $current The token of the request's own login, to mark it
     * @return list<SessionInfo>
     */
    public function forUser(int $userId, ?string $current = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        [$where, $params] = $this->live();
        $rows = $this->db->fetchAll(
            "SELECT id, token_hash, created_at, last_seen_at, ip, user_agent FROM {sessions} WHERE user_id = :user AND {$where} ORDER BY last_seen_at DESC, id DESC",
            ['user' => $userId] + $params,
        );
        $currentHash = $current !== null ? self::hash($current) : null;

        return array_map(static fn (array $row): SessionInfo => new SessionInfo(
            (int) $row['id'],
            new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')),
            new \DateTimeImmutable($row['last_seen_at'], new \DateTimeZone('UTC')),
            (string) $row['ip'],
            (string) $row['user_agent'],
            $currentHash !== null && hash_equals($currentHash, (string) $row['token_hash']),
        ), $rows);
    }

    /**
     * Deletes the rows past the idle or the absolute lifetime.
     *
     * @return int How many were deleted
     */
    public function cleanup(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        [$where, $params] = $this->live();

        return $this->db->execute("DELETE FROM {sessions} WHERE NOT ({$where})", $params);
    }

    /** @return array{string, array<string, string>} The condition of a login still in progress */
    private function live(): array
    {
        $where = 'last_seen_at >= :idle';
        $params = ['idle' => gmdate('Y-m-d H:i:s', time() - max(1, $this->idleTimeout))];
        if ($this->absoluteTimeout > 0) {
            $where .= ' AND created_at >= :absolute';
            $params['absolute'] = gmdate('Y-m-d H:i:s', time() - $this->absoluteTimeout);
        }

        return [$where, $params];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function ipOf(Request $request): string
    {
        return substr($request->ip, 0, 45);
    }

    private static function userAgentOf(Request $request): string
    {
        $agent = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $request->headers['user-agent'] ?? ''));

        return mb_check_encoding($agent, 'UTF-8')
            ? mb_substr($agent, 0, self::USER_AGENT_LENGTH)
            : substr((string) preg_replace('/[^\x20-\x7E]/', '?', $agent), 0, self::USER_AGENT_LENGTH);
    }
}
