<?php

declare(strict_types=1);

namespace Campanella\Access;

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
 * A 0.0.1 szabálya, alapból tiltó (default deny):
 *
 *  - az administrator szerepkör mindent megtehet;
 *  - bárki megtekintheti azt, ami nem Publishable, illetve ami publikált;
 *  - minden más művelet tiltott.
 */
final class DefaultPolicy implements AccessPolicy
{
    #[\Override]
    public function constrain(Query $query, Actor $actor): Query
    {
        if ($actor->hasRole(Actor::ADMINISTRATOR)) {
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
