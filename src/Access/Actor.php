<?php

declare(strict_types=1);

namespace Campanella\Access;

/**
 * Aki cselekszik. A szerepkör (role) nem Actor, hanem az Actor egyik
 * tulajdonsága, amit a Policy vizsgál.
 */
final readonly class Actor
{
    public const string ADMINISTRATOR = 'administrator';

    /** @param list<string> $roles */
    public function __construct(
        public ActorKind $kind,
        public ?int $id = null,
        public array $roles = [],
        public string $name = '',
    ) {
    }

    public static function anonymous(): self
    {
        return new self(ActorKind::Anonymous, name: 'anonymous');
    }

    /** A rendszer maga (telepítő, CLI, ütemezett feladatok). */
    public static function system(): self
    {
        return new self(ActorKind::Service, roles: [self::ADMINISTRATOR], name: 'system');
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isAnonymous(): bool
    {
        return $this->kind === ActorKind::Anonymous;
    }
}
