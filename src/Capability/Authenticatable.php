<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Model\ValidationException;

/**
 * Az objektum be tud jelentkezni: van jelszava, fiókállapota és szerepkörei.
 *
 * A jelszó csak hash-ként tárolódik (password_hash, a PHP alapértelmezett,
 * erős algoritmusával), a sablonokból nem érhető el. A szerepköröket az
 * AccessPolicy vizsgálja.
 *
 * Az Identifiable-re épül, mert a belépés e-mail-címmel történik.
 */
#[AsCapability('authenticatable', requires: [Identifiable::class], label: 'Bejelentkezni képes')]
final class Authenticatable extends Capability
{
    public const int MIN_PASSWORD_LENGTH = 10;

    /** A bcrypt csak az első 72 bájtot veszi figyelembe; a hosszabbat elutasítjuk. */
    public const int MAX_PASSWORD_BYTES = 72;

    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('password_hash', FieldType::String, required: true, length: 255, label: 'Jelszó (hash)', hidden: true),
            new Field(
                'account_status',
                FieldType::String,
                required: true,
                default: AccountStatus::Active->value,
                indexed: true,
                length: 16,
                label: 'Fiók állapota',
            ),
            new Field('roles', FieldType::StringList, default: [], label: 'Szerepkörök'),
        ];
    }

    /**
     * @throws ValidationException ha a jelszó túl rövid vagy túl hosszú
     */
    public function setPassword(#[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($password, 'UTF-8') < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationException(['password' => sprintf('legalább %d karakter legyen', self::MIN_PASSWORD_LENGTH)]);
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new ValidationException(['password' => sprintf('legfeljebb %d bájt lehet', self::MAX_PASSWORD_BYTES)]);
        }
        $this->object->set('password_hash', password_hash($password, PASSWORD_DEFAULT));
    }

    /**
     * Új hash ugyanahhoz a jelszóhoz, a jelszószabályok ellenőrzése nélkül
     * (belépéskor, ha a hash elavult algoritmussal készült).
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

    /** Igaz, ha a hash régebbi algoritmussal vagy beállítással készült. */
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

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->object->set('roles', $roles);
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
                return ['roles' => "érvénytelen szerepkörnév: {$role}"];
            }
        }

        return [];
    }
}
