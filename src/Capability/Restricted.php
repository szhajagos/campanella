<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Access\Actor;
use Campanella\I18n\Message;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * Who may see the object (since 0.3.2): everyone (`public`, the default), every
 * logged-in user (`users`), or the users with one of the chosen roles (`roles`).
 * Administrators and editors see it in every case. DefaultPolicy applies it to
 * every query and every check, so a restricted object is left out of lists,
 * relations and the search as well.
 *
 * Since 0.3.2 the `file` Blueprint has it (private files); it is not offered to the
 * Blueprints made in the admin yet.
 */
#[AsCapability('restricted', label: 'capability.restricted')]
final class Restricted extends Capability
{
    public const string PUBLIC = 'public';
    public const string USERS = 'users';
    public const string ROLES = 'roles';

    /** The values of `visible_to`, in the order offered. */
    public const array SCOPES = [self::PUBLIC, self::USERS, self::ROLES];

    /** The most roles one object can be limited to. */
    public const int MAX_ROLES = 20;

    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('visible_to', FieldType::Choice, required: true, default: self::PUBLIC, indexed: true, length: 16, label: 'field.visible_to', choices: [
                self::PUBLIC => 'restricted.public',
                self::USERS => 'restricted.users',
                self::ROLES => 'restricted.roles',
            ]),
            // With `roles`: the roles whose users may see it.
            new Field('visible_roles', FieldType::String, length: 32, cardinality: self::MAX_ROLES, label: 'field.visible_roles'),
        ];
    }

    /** `public`, `users` or `roles` (anything else is taken as `roles` without roles: nobody but the staff). */
    public function scope(): string
    {
        $scope = (string) $this->object->get('visible_to');

        return in_array($scope, self::SCOPES, true) ? $scope : self::ROLES;
    }

    public function isPublic(): bool
    {
        return $this->scope() === self::PUBLIC;
    }

    /** @return list<string> */
    public function roles(): array
    {
        $roles = $this->object->get('visible_roles');

        return is_array($roles) ? array_values(array_map(strval(...), $roles)) : [];
    }

    /**
     * Limits who may see it. The roles count only with `roles`; they are trimmed,
     * repeated and empty ones left out.
     *
     * @param list<string> $roles
     */
    public function restrict(string $scope, array $roles = []): void
    {
        $this->object->set('visible_to', $scope);
        $clean = [];
        foreach ($scope === self::ROLES ? $roles : [] as $role) {
            $role = trim($role);
            if ($role !== '' && !in_array($role, $clean, true)) {
                $clean[] = $role;
            }
        }
        $this->object->set('visible_roles', $clean);
    }

    /**
     * Whether the actor is among those it is meant for. The staff (administrators,
     * editors) are decided by the policy, not here.
     */
    public function isVisibleTo(Actor $actor): bool
    {
        return match ($this->scope()) {
            self::PUBLIC => true,
            self::USERS => !$actor->isAnonymous(),
            default => !$actor->isAnonymous() && array_intersect($actor->roles, $this->roles()) !== [],
        };
    }

    #[\Override]
    public function prepareForSave(): void
    {
        if ($this->object->get('visible_to') === null || $this->object->get('visible_to') === '') {
            $this->object->set('visible_to', self::PUBLIC);
        }
        // Trimmed, without empty and repeated ones (a form's checkboxes send an empty value too).
        $roles = array_values(array_unique(array_filter(array_map(trim(...), $this->roles()), static fn (string $r): bool => $r !== '')));
        $this->object->set('visible_roles', $this->object->get('visible_to') === self::ROLES ? $roles : []);
    }

    #[\Override]
    public function validate(): array
    {
        if (!in_array($this->object->get('visible_to'), self::SCOPES, true)) {
            return ['visible_to' => 'validation.visible_to'];
        }
        foreach ($this->roles() as $role) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $role) !== 1) {
                return ['visible_roles' => new Message('validation.invalid_role', ['role' => $role])];
            }
        }
        if ($this->scope() === self::ROLES && $this->roles() === []) {
            return ['visible_roles' => 'validation.visible_roles_required'];
        }

        return [];
    }
}
