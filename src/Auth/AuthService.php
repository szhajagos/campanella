<?php

declare(strict_types=1);

namespace Campanella\Auth;

use Campanella\Access\Actor;
use Campanella\Access\ActorKind;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Identifiable;
use Campanella\Capability\Titled;
use Campanella\Http\Request;
use Campanella\Http\Session;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Security\Csrf;
use Campanella\Security\Throttle;

/**
 * Login, logout and the current user.
 *
 * Security principles:
 *  - the same error message whether the e-mail address or the password is
 *    wrong, and the same running time (a password hash runs even for a
 *    non-existent account);
 *  - login throttling by e-mail address + IP address, and by IP address;
 *  - a new session ID and a new CSRF token on login;
 *  - a blocked account cannot log in, and its existing session ends;
 *  - a changed password ends the user's other sessions (since 0.1.0): the
 *    session holds a stamp of the password hash, checked on every request;
 *  - a login lasts at most `session.absolute_timeout` seconds (12 hours by
 *    default; since 0.1.4), however actively it is used: then one logs in again;
 *  - every login is recorded (SessionRegistry, since 0.1.4), and checked on every
 *    request: the user can end any of them from their profile.
 *
 * Extensibility: LoginGuards run before the password check (honeypot,
 * CAPTCHA …). Checking the password (attempt) and actually logging in
 * (login) are separate steps, so a second factor (e.g. TOTP) can be
 * inserted between them.
 */
final class AuthService
{
    public const string SESSION_USER = 'auth_user_id';

    /** A hash of the user's password hash at login (since 0.1.0). */
    public const string SESSION_STAMP = 'auth_stamp';
    /** When the user logged in (a Unix time; since 0.1.4). */
    public const string SESSION_LOGIN_AT = 'auth_login_at';
    /** The login's token in the SessionRegistry (since 0.1.4). */
    public const string SESSION_TOKEN = 'auth_token';

    /** The default absolute lifetime of a login: 12 hours. */
    public const int ABSOLUTE_TIMEOUT = 43200;

    /** Message key of the generic login error (the same for a wrong e-mail address and a wrong password). */
    public const string GENERIC_ERROR = 'auth.invalid_credentials';

    private ?Request $resolvedFor = null;
    private ?CampanellaObject $current = null;

    /**
     * @param array{max_attempts?: int, max_attempts_per_ip?: int, max_attempts_per_account?: int, decay_seconds?: int} $config
     * @param list<LoginGuard> $guards Additional protections that run before the password check.
     * @param int $absoluteTimeout The longest a login lasts, in seconds, however actively it is used
     *        (`session.absolute_timeout`; 0: no limit, only the idle timeout)
     * @param ?SessionRegistry $sessions The record of logins; null: none (no session list)
     */
    public function __construct(
        private readonly ObjectRepository $repository,
        private readonly QueryEngine $queries,
        private readonly Session $session,
        private readonly Throttle $throttle,
        private readonly Csrf $csrf,
        private readonly array $config = [],
        private readonly array $guards = [],
        private readonly int $absoluteTimeout = self::ABSOLUTE_TIMEOUT,
        private readonly ?SessionRegistry $sessions = null,
    ) {
    }

    /** The longest a login lasts, in seconds (0: no limit). */
    public function absoluteTimeout(): int
    {
        return max(0, $this->absoluteTimeout);
    }

    /** @return list<LoginGuard> */
    public function guards(): array
    {
        return $this->guards;
    }

    public function attempt(Request $request, string $email, #[\SensitiveParameter] string $password): LoginResult
    {
        foreach ($this->guards as $guard) {
            $error = $guard->check($request);
            if ($error !== null) {
                return LoginResult::failure($error);
            }
        }

        $email = Identifiable::normalize($email);
        $user = $email === '' ? null : $this->findUserByEmail($email);
        // The account's own address (the database may match accented variants of it), so
        // variants share one counter; an IPv6 address counts by its /64 network.
        $address = self::clientKey($request->ip);
        $pairKey = 'login|' . ($user !== null ? (string) $user->get('email') : $email) . '|' . $address;
        $ipKey = 'login-ip|' . $address;
        // Unknown addresses are counted too, so a locked account cannot be told from a
        // non-existent one (since 0.1.0).
        $accountKey = 'login-account|' . ($user !== null ? 'id:' . $user->id() : 'email:' . $email);
        $decay = $this->config['decay_seconds'] ?? 900;
        $maxPair = $this->config['max_attempts'] ?? 5;
        $maxAccount = $this->config['max_attempts_per_account'] ?? 30;

        $refused = fn (): LoginResult => LoginResult::failure('auth.too_many_attempts', ['minutes' => max(1, (int) ceil(max(
            $this->throttle->availableIn($pairKey),
            $this->throttle->availableIn($ipKey),
            $this->throttle->availableIn($accountKey),
        ) / 60))]);
        if ($this->throttle->tooManyAttempts($pairKey, $maxPair)
            || $this->throttle->tooManyAttempts($ipKey, $this->config['max_attempts_per_ip'] ?? 20)
            || $this->throttle->tooManyAttempts($accountKey, $maxAccount)) {
            return $refused();
        }
        // Counted before the password is checked (atomically), so parallel requests
        // cannot all slip through the check above; a successful login clears them.
        $overPair = $this->throttle->hit($pairKey, $decay) > $maxPair;
        $overAccount = $this->throttle->hit($accountKey, $decay) > $maxAccount;
        $overIp = $this->throttle->hit($ipKey, $decay) > ($this->config['max_attempts_per_ip'] ?? 20);
        if ($overPair || $overAccount || $overIp) {
            return $refused();
        }

        if ($user === null) {
            password_hash($password, PASSWORD_DEFAULT); // same running time when there is no such account

            return LoginResult::failure(self::GENERIC_ERROR);
        }

        $auth = $user->as(Authenticatable::class);
        if (!$auth->verifyPassword($password)) {
            return LoginResult::failure(self::GENERIC_ERROR);
        }
        if (!$auth->isActive()) {
            return LoginResult::failure('auth.account_blocked');
        }

        if ($auth->needsRehash()) {
            $auth->rehash($password);
            $this->repository->save($user);
        }
        // The address's counter is not cleared: logging in to one's own account must not
        // reset it between guesses at other accounts.
        $this->throttle->clear($pairKey);
        $this->throttle->clear($accountKey);
        $this->login($request, $user);

        return LoginResult::success($user);
    }

    /** Logs the user in (with a new session ID). */
    public function login(Request $request, CampanellaObject $user): void
    {
        $this->session->start($request);
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set(self::SESSION_USER, $user->id());
        $this->session->set(self::SESSION_STAMP, self::stamp($user));
        $this->session->set(self::SESSION_LOGIN_AT, time());
        $previous = $this->token();   // logging in again in the same browser: the old login ends
        if ($this->sessions !== null && $previous !== null) {
            $this->sessions->end($previous);
        }
        $this->session->remove(self::SESSION_TOKEN);
        $this->record($request, $user);
        $this->resolvedFor = $request;
        $this->current = $user;
    }

    /**
     * After the user changed their own password: this session goes on (with a new
     * ID and stamp, but not longer: its login time stays), the user's other sessions
     * end on their next request.
     */
    public function refresh(Request $request, CampanellaObject $user): void
    {
        $this->session->start($request);
        $this->session->regenerate();
        $this->session->set(self::SESSION_STAMP, self::stamp($user));
        $token = $this->token();
        if ($this->sessions !== null && $token !== null) {
            $this->sessions->endAll((int) $user->id(), $token);
        }
        $this->resolvedFor = $request;
        $this->current = $user;
    }

    public function logout(): void
    {
        $token = $this->token();
        try {
            if ($this->sessions !== null && $token !== null) {
                $this->sessions->end($token);
            }
        } finally {
            // Logged out even if the record cannot be deleted (e.g. a database error).
            $this->session->destroy();
            $this->current = null;
        }
    }

    /** The logged-in user of the request, or null. */
    public function currentUser(Request $request): ?CampanellaObject
    {
        if ($this->resolvedFor === $request) {
            return $this->current;
        }
        $this->resolvedFor = $request;
        $this->current = null;

        if (!$this->session->resume($request)) {
            return null;
        }
        $id = $this->session->get(self::SESSION_USER);
        if (!is_int($id)) {
            return null;
        }
        $user = $this->repository->find($id);
        if ($user === null || !$user->has(Authenticatable::class) || !$user->as(Authenticatable::class)->isActive()) {
            $this->endLogin();   // deleted or blocked account: log it out

            return null;
        }
        // The absolute lifetime (since 0.1.4); a session from before it starts counting now.
        $loginAt = $this->session->get(self::SESSION_LOGIN_AT);
        if (!is_int($loginAt)) {
            $this->session->set(self::SESSION_LOGIN_AT, time());
        } elseif ($this->absoluteTimeout() > 0 && $loginAt + $this->absoluteTimeout() < time()) {
            $this->endLogin();

            return null;
        }
        $stamp = $this->session->get(self::SESSION_STAMP);
        if (!is_string($stamp)) {
            $this->session->set(self::SESSION_STAMP, self::stamp($user)); // a session from before 0.1.0
        } elseif (!hash_equals($stamp, self::stamp($user))) {
            $this->endLogin();   // the password changed since: log it out

            return null;
        }
        // Still recorded? A login ended from the session list (or before 0.1.4: not recorded yet).
        if ($this->sessions !== null) {
            $token = $this->token();
            if ($token === null) {
                $this->record($request, $user);
            } elseif ($this->sessions->touch($token, (int) $user->id(), $request) === false) {
                $this->endLogin();

                return null;
            }
        }

        return $this->current = $user;
    }

    /** Ends the login of the session (with a new session ID); the session itself (e.g. its CSRF token) stays. */
    private function endLogin(): void
    {
        $token = $this->token();
        if ($this->sessions !== null && $token !== null) {
            $this->sessions->end($token);
        }
        $this->session->remove(self::SESSION_TOKEN);
        $this->session->remove(self::SESSION_USER);
        $this->session->remove(self::SESSION_STAMP);
        $this->session->remove(self::SESSION_LOGIN_AT);
        $this->session->regenerate();
    }

    /**
     * The logged-in user's logins in progress, this one marked (since 0.1.4).
     *
     * @return list<SessionInfo>
     */
    public function sessions(Request $request): array
    {
        $user = $this->currentUser($request);
        if ($user === null || $this->sessions === null) {
            return [];
        }

        return $this->sessions->forUser((int) $user->id(), $this->token());
    }

    /** Ends one of the logged-in user's other logins; false if there is no such login, or it is this one. */
    public function endSession(Request $request, int $id): bool
    {
        $user = $this->currentUser($request);
        if ($user === null || $this->sessions === null) {
            return false;
        }
        foreach ($this->sessions->forUser((int) $user->id(), $this->token()) as $info) {
            if ($info->id === $id && $info->current) {
                return false;   // this one: logging out is for that
            }
        }

        return $this->sessions->endById((int) $user->id(), $id);
    }

    /**
     * Ends every login of the logged-in user but this one.
     *
     * @return int How many were ended
     */
    public function endOtherSessions(Request $request): int
    {
        $user = $this->currentUser($request);
        $token = $this->token();
        if ($user === null || $this->sessions === null || $token === null) {
            return 0;
        }

        return $this->sessions->endAll((int) $user->id(), $token);
    }

    /** Records the login of the session in the SessionRegistry, if there is one. */
    private function record(Request $request, CampanellaObject $user): void
    {
        $loginAt = $this->session->get(self::SESSION_LOGIN_AT);
        $token = $this->sessions?->start((int) $user->id(), $request, is_int($loginAt) ? $loginAt : null);
        if ($token !== null) {
            $this->session->set(self::SESSION_TOKEN, $token);
        }
    }

    private function token(): ?string
    {
        $token = $this->session->get(self::SESSION_TOKEN);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function currentActor(Request $request): Actor
    {
        $user = $this->currentUser($request);

        return $user === null ? Actor::anonymous() : self::actorFor($user);
    }

    public static function actorFor(CampanellaObject $user): Actor
    {
        return new Actor(
            ActorKind::User,
            $user->id(),
            $user->as(Authenticatable::class)->roles(),
            $user->has(Titled::class) ? $user->as(Titled::class)->title() : $user->as(Identifiable::class)->email(),
        );
    }

    /**
     * The address a throttle counts by: an IPv4 address as it is, an IPv6 address by
     * its /64 network (one connection usually gets a whole /64).
     */
    public static function clientKey(string $ip): string
    {
        $ip = Request::normalizeIp($ip);
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** The session's stamp of the user's password (not the hash itself). */
    private static function stamp(CampanellaObject $user): string
    {
        return hash('sha256', 'campanella-session|' . (string) $user->get('password_hash'));
    }

    public function findUserByEmail(string $email): ?CampanellaObject
    {
        return $this->queries->first(
            Query::objects()->having('authenticatable')->where('email', '=', Identifiable::normalize($email)),
            Actor::system(),
        );
    }
}
