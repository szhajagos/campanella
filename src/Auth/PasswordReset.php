<?php

declare(strict_types=1);

namespace Campanella\Auth;

use Campanella\Capability\Authenticatable;
use Campanella\Capability\Identifiable;
use Campanella\Core\Deferred;
use Campanella\Database\Connection;
use Campanella\Http\Request;
use Campanella\Http\SitePaths;
use Campanella\I18n\Message;
use Campanella\Mail\Mailer;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Security\Throttle;
use Campanella\Service\UserService;
use Campanella\Site\SiteSettings;

/**
 * The forgotten password (since 0.1.4): a link by e-mail that sets a new password.
 *
 * Security:
 *  - the same answer whether the address belongs to an (active) account or not; the
 *    account is looked up, the link made and e-mailed after the response (Deferred),
 *    so the response's time does not tell either;
 *  - the link holds a selector (128 bits, finds the row) and a verifier (256 bits,
 *    kept only as its SHA-256 hash, compared in constant time): the table alone is
 *    no use to anyone who reads it;
 *  - it is valid for `auth.password_reset_minutes` (60) minutes, works once, and a
 *    newer one replaces it; any change of the password (e.g. on the profile) voids it;
 *  - the address of the link is made from the site's address (Site settings), never
 *    from the request's Host header, which anyone can send ("reset poisoning"): without
 *    it, the forgotten password is not offered;
 *  - requests are limited per IP address (5 in 15 minutes) and per e-mail address
 *    (3 in an hour), wrong links per IP address (20 in 15 minutes);
 *  - a new password ends every session of the user (they log in with it).
 */
final class PasswordReset
{
    public const int DEFAULT_MINUTES = 60;

    public const int MAX_PER_IP = 5;
    public const int IP_DECAY_SECONDS = 900;
    public const int MAX_PER_ADDRESS = 3;
    public const int ADDRESS_DECAY_SECONDS = 3600;
    public const int MAX_INVALID_PER_IP = 20;
    public const int INVALID_DECAY_SECONDS = 900;

    /** A link's token: a 32-character selector and a 64-character verifier, in hexadecimal. */
    private const string TOKEN_PATTERN = '/^([0-9a-f]{32})([0-9a-f]{64})$/';

    private ?bool $tableExists = null;

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
        private readonly ObjectRepository $repository,
        private readonly UserService $users,
        private readonly Mailer $mailer,
        private readonly SiteSettings $site,
        private readonly SitePaths $paths,
        private readonly Throttle $throttle,
        private readonly Deferred $deferred,
        private readonly int $minutes = self::DEFAULT_MINUTES,
    ) {
    }

    /** Whether the forgotten password can be offered: e-mail is set up, the site's address is set, the table exists. */
    public function isAvailable(): bool
    {
        return $this->mailer->isConfigured() && $this->site->url() !== '' && $this->isTableReady();
    }

    /** How long a link is valid, in minutes. */
    public function minutes(): int
    {
        return max(1, $this->minutes);
    }

    /**
     * A request for a link. After the response, if the address belongs to an active
     * account, a link is made and e-mailed; the caller answers the same either way.
     *
     * @return ?Message An error that does not depend on the account (an invalid address,
     *         too many requests), or null: "if the address is registered, a link was sent"
     */
    public function request(Request $request, string $email): ?Message
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return new Message('auth.reset.invalid_email');
        }
        // Counted before anything else, for every address alike.
        $ipKey = 'reset-ip|' . AuthService::clientKey($request->ip);
        $addressKey = 'reset-address|' . $email;
        foreach ([[$ipKey, self::MAX_PER_IP, self::IP_DECAY_SECONDS], [$addressKey, self::MAX_PER_ADDRESS, self::ADDRESS_DECAY_SECONDS]] as [$key, $max, $decay]) {
            if ($this->throttle->tooManyAttempts($key, $max) || $this->throttle->hit($key, $decay) > $max) {
                return new Message('auth.reset.too_many', ['minutes' => max(1, (int) ceil($this->throttle->availableIn($key) / 60))]);
            }
        }
        // Everything that depends on the account happens after the response, so the
        // response takes the same time whether the address is registered or not.
        $this->deferred->add(function () use ($email): void {
            $this->send($email);
        });

        return null;
    }

    /** Makes a link for an active account with this address, and e-mails it; nothing for other addresses. */
    private function send(string $email): void
    {
        $user = $this->auth->findUserByEmail($email);
        if ($user === null || !$user->has(Authenticatable::class) || !$user->as(Authenticatable::class)->isActive()) {
            return;
        }
        $token = $this->issue($user);
        $link = $this->site->absolute($this->paths->get('password_reset')) . '?token=' . $token;
        $this->mailer->send($user->as(Identifiable::class)->email(), 'password_reset', ['link' => $link, 'minutes' => $this->minutes()], UserService::nameOf($user));
    }

    /**
     * Makes a new link for the user (the previous one stops working); returns its token.
     * Deletes the expired links of every user.
     */
    public function issue(CampanellaObject $user): string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $this->db->transactional(function (Connection $db) use ($user, $selector, $verifier): void {
            $db->execute('DELETE FROM {password_resets} WHERE user_id = :user OR expires_at < :now', ['user' => $user->id(), 'now' => gmdate('Y-m-d H:i:s')]);
            $db->insert('password_resets', [
                'user_id' => (int) $user->id(),
                'selector' => $selector,
                'verifier_hash' => hash('sha256', $verifier),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->minutes() * 60),
            ]);
        });

        return $selector . $verifier;
    }

    /**
     * The user a link belongs to, or null if it is wrong, expired, used, or its account
     * is not active. A wrong link counts against the IP address.
     */
    public function verify(Request $request, string $token): ?CampanellaObject
    {
        $key = 'reset-invalid|' . AuthService::clientKey($request->ip);
        if ($this->throttle->tooManyAttempts($key, self::MAX_INVALID_PER_IP)) {
            return null;
        }
        $user = $this->find($token)[0] ?? null;
        if ($user === null) {
            $this->throttle->hit($key, self::INVALID_DECAY_SECONDS);
        }

        return $user;
    }

    /**
     * Sets the new password with a link, which then stops working. The user's sessions
     * end (the Kernel's PasswordChanged listener).
     *
     * @return ?CampanellaObject The user, or null if the link is no longer usable
     * @throws \Campanella\Model\ValidationException on `password` (the link stays usable)
     */
    public function complete(Request $request, string $token, #[\SensitiveParameter] string $password): ?CampanellaObject
    {
        if ($this->verify($request, $token) === null) {
            return null;
        }

        return $this->db->transactional(function (Connection $db) use ($token, $password): ?CampanellaObject {
            // Locked, so the same link cannot be used twice at the same time.
            [$user] = $this->find($token, true) + [null];
            if ($user === null) {
                return null;
            }
            $this->users->resetPassword($user, $password);
            $db->execute('DELETE FROM {password_resets} WHERE user_id = :user', ['user' => $user->id()]);

            return $user;
        });
    }

    /** Voids the user's link, if any (their password changed some other way). */
    public function forget(int $userId): void
    {
        if ($this->isTableReady()) {
            $this->db->execute('DELETE FROM {password_resets} WHERE user_id = :user', ['user' => $userId]);
        }
    }

    /** @return array{0?: CampanellaObject} */
    private function find(string $token, bool $lock = false): array
    {
        if (preg_match(self::TOKEN_PATTERN, $token, $parts) !== 1 || !$this->isTableReady()) {
            return [];
        }
        $row = $this->db->fetchOne(
            'SELECT user_id, verifier_hash, expires_at FROM {password_resets} WHERE selector = :selector' . ($lock ? ' FOR UPDATE' : ''),
            ['selector' => $parts[1]],
        );
        if ($row === null || !hash_equals((string) $row['verifier_hash'], hash('sha256', $parts[2]))
            || (string) $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            return [];
        }
        $user = $this->repository->find((int) $row['user_id']);
        if ($user === null || !$user->has(Authenticatable::class) || !$user->as(Authenticatable::class)->isActive()) {
            return [];
        }

        return [$user];
    }

    private function isTableReady(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = $this->db->tableExists('password_resets');
            } catch (\Throwable) {
                $this->tableExists = false;
            }
        }

        return $this->tableExists;
    }
}
