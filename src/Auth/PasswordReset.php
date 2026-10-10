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
 *    kept only as a SHA-256 hash, compared in constant time): the table alone is no
 *    use to anyone who reads it. The hash also covers the account's e-mail address
 *    and password hash, so a link stops working when either changes, however (the
 *    profile, an administrator, the command line);
 *  - it is valid for `auth.password_reset_minutes` (60) minutes, works once, and a
 *    newer one replaces it; blocking the account voids it;
 *  - the address of the link is made from the site's address (Site settings), never
 *    from the request's Host header, which anyone can send ("reset poisoning"): without
 *    it, the forgotten password is not offered;
 *  - requests are limited per IP address (5 in 15 minutes) and per e-mail address
 *    (3 in an hour). Wrong links are not limited: guessing one is hopeless (2^384),
 *    and a limit per address would let others on a shared address block a valid link;
 *  - a new password ends every session of the user (they log in with it).
 */
final class PasswordReset
{
    public const int DEFAULT_MINUTES = 60;

    public const int MAX_PER_IP = 5;
    public const int IP_DECAY_SECONDS = 900;
    public const int MAX_PER_ADDRESS = 3;
    public const int ADDRESS_DECAY_SECONDS = 3600;

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
        private readonly ?SessionRegistry $sessions = null,
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
                'verifier_hash' => self::verifierHash($verifier, $user),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->minutes() * 60),
            ]);
        });

        return $selector . $verifier;
    }

    /** The user a link belongs to, or null if it is wrong, expired, used, voided, or its account is not active. */
    public function verify(string $token): ?CampanellaObject
    {
        return $this->find($token)[0] ?? null;
    }

    /**
     * Sets the new password with a link, which then stops working, and ends every
     * session of the user.
     *
     * The link is used up first (in a short locked transaction, so it cannot be used
     * twice at the same time), then the password is set: nothing slow (a listener, an
     * e-mail) runs while the row is locked, and a listener's failure cannot undo it.
     *
     * @return ?CampanellaObject The user, or null if the link is no longer usable
     * @throws \Campanella\Model\ValidationException on `password` (checked first: the link stays usable)
     */
    public function complete(string $token, #[\SensitiveParameter] string $password): ?CampanellaObject
    {
        if ($this->verify($token) === null) {
            return null;
        }
        Authenticatable::checkPassword($password);
        $user = $this->db->transactional(function (Connection $db) use ($token): ?CampanellaObject {
            [$user] = $this->find($token, true) + [null];
            if ($user !== null) {
                $db->execute('DELETE FROM {password_resets} WHERE user_id = :user', ['user' => $user->id()]);
            }

            return $user;
        });
        if ($user === null) {
            return null;
        }
        // Read again: an administrator may have blocked the account (or changed it) meanwhile.
        $user = $this->repository->find((int) $user->id());
        if ($user === null || !$user->has(Authenticatable::class) || !$user->as(Authenticatable::class)->isActive()) {
            return null;
        }
        $this->users->resetPassword($user, $password);
        $this->sessions?->endAll((int) $user->id());

        return $user;
    }

    /** Voids the user's link, if any (e.g. the account was blocked). */
    public function forget(int $userId): void
    {
        self::forgetIn($this->db, $userId);
    }

    /** Voids a user's link, if any, where no PasswordReset service is at hand (UserService, the command line). */
    public static function forgetIn(Connection $db, int $userId): void
    {
        if ($db->tableExists('password_resets')) {
            $db->execute('DELETE FROM {password_resets} WHERE user_id = :user', ['user' => $userId]);
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
        if ($row === null || (string) $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            return [];
        }
        $user = $this->repository->find((int) $row['user_id']);
        if ($user === null || !$user->has(Authenticatable::class) || !$user->as(Authenticatable::class)->isActive()
            || !hash_equals((string) $row['verifier_hash'], self::verifierHash($parts[2], $user))) {
            return [];
        }

        return [$user];
    }

    /**
     * The stored hash of a verifier: it covers the account's e-mail address and password
     * hash too, so a link sent before either changed no longer matches.
     */
    private static function verifierHash(string $verifier, CampanellaObject $user): string
    {
        return hash_hmac('sha256', $verifier, $user->as(Identifiable::class)->email() . "\0" . (string) $user->get('password_hash'));
    }

    private function isTableReady(): bool
    {
        // Before the upgrade that creates it: not offered. Any other database error is not hidden.
        $this->tableExists ??= $this->db->tableExists('password_resets');

        return $this->tableExists;
    }
}
