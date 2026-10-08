<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\I18n\Message;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Model\ValidationException;

/**
 * The object can log in: it has a password, an account status and roles.
 *
 * The password is stored only as a hash (password_hash, with PHP's default,
 * strong algorithm) and is not accessible from templates. Roles are
 * inspected by the AccessPolicy.
 *
 * Builds on Identifiable, because login uses the e-mail address.
 */
#[AsCapability('authenticatable', requires: [Identifiable::class], label: 'capability.authenticatable')]
final class Authenticatable extends Capability
{
    public const int MIN_PASSWORD_LENGTH = 10;

    /** bcrypt only uses the first 72 bytes; longer passwords are rejected. */
    public const int MAX_PASSWORD_BYTES = 72;

    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('password_hash', FieldType::String, required: true, length: 255, label: 'field.password_hash', hidden: true),
            new Field(
                'account_status',
                FieldType::String,
                required: true,
                default: AccountStatus::Active->value,
                indexed: true,
                length: 16,
                label: 'field.account_status',
                // Not readable from templates: who is an administrator is nobody's business (since 0.1.0).
                hidden: true,
            ),
            // Multi-valued (since 0.0.6; before: a StringList), so users can be queried by role.
            new Field('roles', FieldType::String, length: 32, cardinality: Field::UNLIMITED, label: 'field.roles', hidden: true),
        ];
    }

    /**
     * @throws ValidationException if the password is too short or too long
     */
    public function setPassword(#[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($password, 'UTF-8') < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationException(['password' => new Message('validation.password_too_short', ['min' => self::MIN_PASSWORD_LENGTH])]);
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new ValidationException(['password' => new Message('validation.password_too_long', ['max' => self::MAX_PASSWORD_BYTES])]);
        }
        $this->object->set('password_hash', password_hash($password, PASSWORD_DEFAULT));
    }

    /**
     * A new hash for the same password, without checking the password rules
     * (on login, if the hash was made with an outdated algorithm).
     */
    public function rehash(#[\SensitiveParameter] string $password): void
    {
        $this->object->set('password_hash', password_hash($password, PASSWORD_DEFAULT));
    }

    public function verifyPassword(#[\SensitiveParameter] string $password): bool
    {
        $hash = (string) $this->object->get('password_hash');

        return $hash !== '' && password_verify($password, $hash);
    }

    /** True if the hash was made with an older algorithm or setting. */
    public function needsRehash(): bool
    {
        return password_needs_rehash((string) $this->object->get('password_hash'), PASSWORD_DEFAULT);
    }

    public function status(): AccountStatus
    {
        return AccountStatus::tryFrom((string) $this->object->get('account_status')) ?? AccountStatus::Blocked;
    }

    public function isActive(): bool
    {
        return $this->status() === AccountStatus::Active;
    }

    public function block(): void
    {
        $this->object->set('account_status', AccountStatus::Blocked);
    }

    public function activate(): void
    {
        $this->object->set('account_status', AccountStatus::Active);
    }

    /** @return list<string> */
    public function roles(): array
    {
        /** @var list<string> */
        return $this->object->get('roles') ?? [];
    }

    /** @param list<string> $roles Trimmed; empty and repeated ones are left out. */
    public function setRoles(array $roles): void
    {
        $clean = [];
        foreach ($roles as $role) {
            $role = trim($role);
            if ($role !== '' && !in_array($role, $clean, true)) {
                $clean[] = $role;
            }
        }
        $this->object->set('roles', $clean);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    #[\Override]
    public function validate(): array
    {
        foreach ($this->roles() as $role) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $role) !== 1) {
                return ['roles' => new Message('validation.invalid_role', ['role' => $role])];
            }
        }

        return [];
    }
}
