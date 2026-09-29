<?php

declare(strict_types=1);

namespace Campanella\Access;

use Campanella\Capability\Authenticatable;
use Campanella\Capability\Publishable;
use Campanella\Capability\PublishStatus;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Condition\HasCapability;
use Campanella\Query\Operator;
use Campanella\Query\Query;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A beépített szabály, alapból tiltó (default deny):
 *
 *  - administrator: mindent megtehet;
 *  - editor: mindent láthat (a piszkozatokat is), és tartalmat hozhat
 *    létre, módosíthat, publikálhat; felhasználót (Authenticatable) nem
 *    kezelhet, és nem törölhet;
 *  - mindenki más: azt láthatja, ami nem Publishable, illetve ami publikált;
 *    minden más művelet tiltott.
 *
 * A felhasználó-objektumok láthatók (a nevük szerzőként megjelenhet), de a
 * jelszó-hash rejtett mező, a sablonokból nem érhető el.
 */
final class DefaultPolicy implements AccessPolicy
{
    public const string EDITOR = 'editor';

    #[\Override]
    public function constrain(Query $query, Actor $actor): Query
    {
        if ($actor->hasRole(Actor::ADMINISTRATOR) || $actor->hasRole(self::EDITOR)) {
            return $query;
        }

        return $query->whereCondition(Group::any(
            new HasCapability('publishable', negated: true),
            Group::all(
                new FieldCondition('status', Operator::Equals, PublishStatus::Published),
                new FieldCondition('published_at', Operator::LessOrEqual, self::now()),
            ),
        ));
    }

    #[\Override]
    public function allows(Actor $actor, Operation $operation, CampanellaObject $object): bool
    {
        if ($actor->hasRole(Actor::ADMINISTRATOR)) {
            return true;
        }
        if ($actor->hasRole(self::EDITOR)) {
            return match ($operation) {
                Operation::View => true,
                Operation::Delete => false,
                default => !$object->has(Authenticatable::class),
            };
        }
        if ($operation !== Operation::View) {
            return false;
        }

        return !$object->has(Publishable::class) || $object->as(Publishable::class)->isPublished(self::now());
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
