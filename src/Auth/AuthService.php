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
 * Belépés, kilépés és az aktuális felhasználó.
 *
 * Biztonsági elvek:
 *  - egyforma hibaüzenet, akár az e-mail-cím, akár a jelszó hibás, és
 *    egyforma futásidő (nem létező fióknál is lefut egy jelszó-hash);
 *  - próbálkozások korlátozása e-mail-cím + IP-cím, illetve IP-cím szerint;
 *  - belépéskor új munkamenet-azonosító és új CSRF-token;
 *  - letiltott fiók nem léphet be, és a már belépett munkamenete megszűnik.
 *
 * Bővíthetőség: a LoginGuard-ok a jelszó-ellenőrzés előtt futnak (honeypot,
 * CAPTCHA …). A jelszó ellenőrzése (attempt) és a tényleges beléptetés
 * (login) külön lépés, így egy második lépcső (pl. TOTP) közéjük illeszthető.
 */
final class AuthService
{
    public const string SESSION_USER = 'auth_user_id';
    public const string GENERIC_ERROR = 'Hibás e-mail-cím vagy jelszó.';

    private ?Request $resolvedFor = null;
    private ?CampanellaObject $current = null;

    /**
     * @param array{max_attempts?: int, max_attempts_per_ip?: int, decay_seconds?: int} $config
     * @param list<LoginGuard> $guards A jelszó-ellenőrzés előtt futó kiegészítő védelmek.
     */
    public function __construct(
        private readonly ObjectRepository $repository,
        private readonly QueryEngine $queries,
        private readonly Session $session,
        private readonly Throttle $throttle,
        private readonly Csrf $csrf,
        private readonly array $config = [],
        private readonly array $guards = [],
    ) {
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
        $pairKey = 'login|' . $email . '|' . $request->ip;
        $ipKey = 'login-ip|' . $request->ip;
        $decay = $this->config['decay_seconds'] ?? 900;

        if ($this->throttle->tooManyAttempts($pairKey, $this->config['max_attempts'] ?? 5)
            || $this->throttle->tooManyAttempts($ipKey, $this->config['max_attempts_per_ip'] ?? 20)) {
            $minutes = (int) ceil(max($this->throttle->availableIn($pairKey), $this->throttle->availableIn($ipKey)) / 60);

            return LoginResult::failure(sprintf('Túl sok sikertelen próbálkozás. Próbáld újra %d perc múlva.', max(1, $minutes)));
        }

        $user = $email === '' ? null : $this->findUserByEmail($email);
        if ($user === null) {
            password_hash($password, PASSWORD_DEFAULT); // egyforma futásidő, ha nincs ilyen fiók
            $this->throttle->hit($pairKey, $decay);
            $this->throttle->hit($ipKey, $decay);

            return LoginResult::failure(self::GENERIC_ERROR);
        }

        $auth = $user->as(Authenticatable::class);
        if (!$auth->verifyPassword($password)) {
            $this->throttle->hit($pairKey, $decay);
            $this->throttle->hit($ipKey, $decay);

            return LoginResult::failure(self::GENERIC_ERROR);
        }
        if (!$auth->isActive()) {
            return LoginResult::failure('A fiók le van tiltva.');
        }

        if ($auth->needsRehash()) {
            $auth->rehash($password);
            $this->repository->save($user);
        }
        $this->throttle->clear($pairKey);
        $this->login($request, $user);

        return LoginResult::success($user);
    }

    /** A felhasználó beléptetése (új munkamenet-azonosítóval). */
    public function login(Request $request, CampanellaObject $user): void
    {
        $this->session->start($request);
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set(self::SESSION_USER, $user->id());
        $this->resolvedFor = $request;
        $this->current = $user;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->current = null;
    }

    /** A kéréshez tartozó bejelentkezett felhasználó, vagy null. */
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
            $this->session->remove(self::SESSION_USER);   // törölt vagy letiltott fiók: kiléptetjük

            return null;
        }

        return $this->current = $user;
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

    public function findUserByEmail(string $email): ?CampanellaObject
    {
        return $this->queries->first(
            Query::objects()->having('authenticatable')->where('email', '=', Identifiable::normalize($email)),
            Actor::system(),
        );
    }
}
