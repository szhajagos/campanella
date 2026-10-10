<?php

declare(strict_types=1);

namespace Campanella\Service;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\ActorKind;
use Campanella\Access\Operation;
use Campanella\Auth\AuthService;
use Campanella\Capability\AccountStatus;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Identifiable;
use Campanella\Capability\Titled;
use Campanella\Database\Connection;
use Campanella\Event\EventDispatcher;
use Campanella\Event\PasswordChanged;
use Campanella\Event\UserCreated;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Security\Throttle;

/**
 * Managing users from the admin, with the rules that keep a site manageable
 * (since 0.1.0):
 *
 *  - who may manage users is decided by the AccessPolicy (by default:
 *    administrators; editors may not);
 *  - the last active administrator cannot be blocked or lose the role, so the
 *    site always has someone who can manage it; nobody can block themselves;
 *  - a password is changed only through setPassword() / changeOwnPassword(),
 *    and a changed password ends the user's other sessions (AuthService);
 *  - changing one's own password needs the current one (attempts are limited);
 *  - UserCreated and PasswordChanged are dispatched after saving (since 0.1.3).
 *
 * Users are not deleted here: blocking keeps their content's authors intact.
 */
final class UserService
{
    public const string BLUEPRINT = 'user';

    public const string ADMINISTRATOR = Actor::ADMINISTRATOR;

    /** The database lock held while a user's roles or status change. */
    private const string LOCK = 'cmp_users';

    /** Wrong current passwords when changing one's own, per user. */
    public const int MAX_PASSWORD_ATTEMPTS = 5;

    public const int PASSWORD_DECAY_SECONDS = 900;

    /** @param list<string> $roles The roles offered in the admin (e.g. administrator, editor) */
    public function __construct(
        private readonly ObjectRepository $repository,
        private readonly QueryEngine $queries,
        private readonly AccessPolicy $policy,
        private readonly Throttle $throttle,
        private readonly array $roles = [Actor::ADMINISTRATOR, 'editor'],
        private readonly ?Connection $db = null,
        private readonly ?EventDispatcher $events = null,
    ) {
    }

    /** Whether the actor may manage users (create, edit, block, set passwords). */
    public function canManage(Actor $actor): bool
    {
        return $this->policy->allows($actor, Operation::Create, $this->repository->create(self::BLUEPRINT))
            && $this->policy->allows($actor, Operation::Update, $this->repository->create(self::BLUEPRINT));
    }

    /**
     * The roles offered for a user: the configured ones, and the user's own (so an
     * unknown role is not lost by saving the form).
     *
     * @return list<string>
     */
    public function assignableRoles(?CampanellaObject $user = null): array
    {
        $roles = $this->roles;
        foreach ($user === null ? [] : $user->as(Authenticatable::class)->roles() as $role) {
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /** @return list<CampanellaObject> Every user, by name */
    public function all(Actor $actor): array
    {
        $this->authorize($actor, Operation::View);

        return $this->queries->execute(Query::objects()->having(Authenticatable::class)->orderBy('title')->orderBy('id')->limit(1000), $actor)->items;
    }

    public function find(Actor $actor, int $id): ?CampanellaObject
    {
        $this->authorize($actor, Operation::View);
        $user = $this->repository->find($id);

        return $user !== null && $user->has(Authenticatable::class) ? $user : null;
    }

    /**
     * @param list<string> $roles
     * @throws AccessDeniedException|ValidationException
     */
    public function create(Actor $actor, string $name, string $email, #[\SensitiveParameter] string $password, array $roles): CampanellaObject
    {
        $this->authorize($actor, Operation::Create);
        $user = $this->repository->create(self::BLUEPRINT, ['title' => trim($name), 'email' => $email]);
        $errors = $this->checkRoles($roles, $user);
        try {
            $user->as(Authenticatable::class)->setPassword($password);
        } catch (ValidationException $e) {
            $errors += $e->errors;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $user->as(Authenticatable::class)->setRoles($roles);
        $this->repository->save($user);
        $this->events?->dispatch(new UserCreated($user, $actor));

        return $user;
    }

    /**
     * Name, e-mail address, roles and status.
     *
     * @param list<string> $roles
     * @throws AccessDeniedException|ValidationException
     */
    public function update(Actor $actor, CampanellaObject $user, string $name, string $email, array $roles, bool $active): void
    {
        $this->authorize($actor, Operation::Update);
        // Counting the administrators and saving under one lock: two parallel demotions
        // cannot both see "one more administrator left" (since 0.1.0).
        $locked = $this->db !== null && (int) $this->db->fetchValue('SELECT GET_LOCK(:name, 10)', ['name' => self::LOCK]) === 1;
        try {
            $this->applyUpdate($actor, $user, $name, $email, $roles, $active);
        } finally {
            if ($locked) {
                $this->db->fetchValue('SELECT RELEASE_LOCK(:name)', ['name' => self::LOCK]);
            }
        }
    }

    /** @param list<string> $roles */
    private function applyUpdate(Actor $actor, CampanellaObject $user, string $name, string $email, array $roles, bool $active): void
    {
        $errors = $this->checkRoles($roles, $user);
        if (!$active && $actor->id !== null && $actor->id === $user->id()) {
            $errors['account_status'] = new Message('users.self_block');
        }
        $auth = $user->as(Authenticatable::class);
        $wasAdmin = $auth->isActive() && $auth->hasRole(self::ADMINISTRATOR);
        $staysAdmin = $active && in_array(self::ADMINISTRATOR, $roles, true);
        if ($wasAdmin && !$staysAdmin && $this->activeAdministrators((int) $user->id()) === 0) {
            $errors[$active ? 'roles' : 'account_status'] ??= new Message('users.last_admin');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user->set('title', trim($name));
        $user->as(Identifiable::class)->setEmail($email);
        $auth->setRoles($roles);
        $active ? $auth->activate() : $auth->block();
        $this->repository->save($user);
    }

    /**
     * An administrator sets someone's password (e.g. a forgotten one); their sessions end.
     *
     * @throws AccessDeniedException|ValidationException
     */
    public function setPassword(Actor $actor, CampanellaObject $user, #[\SensitiveParameter] string $password): void
    {
        $this->authorize($actor, Operation::Update);
        // One's own password needs the current one: changeOwnPassword() (since 0.1.0).
        if ($actor->id !== null && $actor->id === $user->id()) {
            throw new ValidationException(['password' => new Message('users.own_password_profile')]);
        }
        $user->as(Authenticatable::class)->setPassword($password);
        $this->repository->save($user);
        $this->events?->dispatch(new PasswordChanged($user, true, $actor));
    }

    /**
     * A new password with a forgotten password's link (PasswordReset; since 0.1.4): the
     * link stands for the current password. Every session of the user ends.
     *
     * @throws ValidationException on `password`
     */
    public function resetPassword(CampanellaObject $user, #[\SensitiveParameter] string $password): void
    {
        $user->as(Authenticatable::class)->setPassword($password);
        $this->repository->save($user);
        $this->throttle->clear('password-change|' . $user->id());
        $this->events?->dispatch(new PasswordChanged($user, false, AuthService::actorFor($user), true));
    }

    /** One's own name (the profile page). */
    public function updateProfile(CampanellaObject $user, string $name): void
    {
        if (trim($name) === '') {
            throw new ValidationException(['title' => 'validation.required']);
        }
        $user->set('title', trim($name));
        $this->repository->save($user);
    }

    /**
     * One's own password, with the current one. The other sessions end; the caller
     * keeps the current one (AuthService::refresh()).
     *
     * @throws ValidationException on `current_password` (wrong, or too many attempts) or `password`
     */
    public function changeOwnPassword(CampanellaObject $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new): void
    {
        $key = 'password-change|' . $user->id();
        // Counted before checking (atomically), so parallel requests cannot slip through.
        if ($this->throttle->tooManyAttempts($key, self::MAX_PASSWORD_ATTEMPTS)
            || $this->throttle->hit($key, self::PASSWORD_DECAY_SECONDS) > self::MAX_PASSWORD_ATTEMPTS) {
            throw new ValidationException(['current_password' => new Message('users.too_many', ['minutes' => max(1, (int) ceil($this->throttle->availableIn($key) / 60))])]);
        }
        $auth = $user->as(Authenticatable::class);
        if (!$auth->verifyPassword($current)) {
            throw new ValidationException(['current_password' => new Message('users.wrong_password')]);
        }
        $auth->setPassword($new);
        $this->repository->save($user);
        $this->throttle->clear($key);
        $this->events?->dispatch(new PasswordChanged($user, false, new Actor(ActorKind::User, (int) $user->id(), $auth->roles())));
    }

    /** The active administrators, other than the given user. */
    public function activeAdministrators(int $except = 0): int
    {
        return $this->queries->count(
            Query::objects()
                ->having(Authenticatable::class)
                ->where('roles', '=', self::ADMINISTRATOR)
                ->where('account_status', '=', AccountStatus::Active->value)
                ->where('id', '!=', $except),
            Actor::system(),
        );
    }

    public static function nameOf(CampanellaObject $user): string
    {
        $name = $user->has(Titled::class) ? $user->as(Titled::class)->title() : '';

        return $name !== '' ? $name : $user->as(Identifiable::class)->email();
    }

    /**
     * @param list<string> $roles
     * @return array<string, Message>
     */
    private function checkRoles(array $roles, CampanellaObject $user): array
    {
        $allowed = $this->assignableRoles($user->isNew() ? null : $user);
        foreach ($roles as $role) {
            if (!in_array($role, $allowed, true)) {
                return ['roles' => new Message('validation.invalid_role', ['role' => $role])];
            }
        }

        return [];
    }

    private function authorize(Actor $actor, Operation $operation): void
    {
        if (!$this->canManage($actor)) {
            throw AccessDeniedException::for($actor, $operation, self::BLUEPRINT);
        }
    }
}
