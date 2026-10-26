<?php

declare(strict_types=1);

namespace Campanella\Access;

use Campanella\Capability\Authenticatable;
use Campanella\Capability\Publishable;
use Campanella\Capability\PublishStatus;
use Campanella\Capability\Restricted;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Condition\HasCapability;
use Campanella\Query\Operator;
use Campanella\Query\Query;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The built-in policy, default deny:
 *
 *  - administrator: may do anything;
 *  - editor: may view everything (drafts too), and may create, update and
 *    publish content; may not manage users (Authenticatable) and may not
 *    delete;
 *  - everyone else: may view what is not Publishable, or what is published;
 *    every other operation is denied;
 *  - objects with an administrators-only capability (by default `submitted`: the
 *    contact form's messages, personal data; since 0.1.5) are for administrators
 *    only: nobody else may see or touch them, editors neither;
 *  - a Restricted object (e.g. a private file; since 0.3.2) is seen, besides the
 *    administrators and editors, only by those it is meant for: every logged-in
 *    user, or the users with one of its roles.
 *
 * User objects are visible (their name may appear as author), but the
 * password hash is a hidden field, not accessible from templates.
 */
final class DefaultPolicy implements AccessPolicy
{
    public const string EDITOR = 'editor';

    /** The capabilities whose objects only administrators may see (since 0.1.5). */
    public const array ADMINISTRATORS_ONLY = ['submitted'];

    /** @param list<string> $administratorsOnly Capability names */
    public function __construct(private readonly array $administratorsOnly = self::ADMINISTRATORS_ONLY)
    {
    }

    #[\Override]
    public function constrain(Query $query, Actor $actor): Query
    {
        if ($actor->hasRole(Actor::ADMINISTRATOR)) {
            return $query;
        }
        foreach ($this->administratorsOnly as $capability) {
            $query = $query->whereCondition(new HasCapability($capability, negated: true));
        }
        if ($actor->hasRole(self::EDITOR)) {
            return $query;
        }

        $query = $query->whereCondition(Group::any(
            new HasCapability('publishable', negated: true),
            Group::all(
                new FieldCondition('status', Operator::Equals, PublishStatus::Published),
                new FieldCondition('published_at', Operator::LessOrEqual, self::now()),
            ),
        ));

        return $query->whereCondition(self::restriction($actor));
    }

    /** The objects the actor may see as far as Restricted goes (since 0.3.2). */
    private static function restriction(Actor $actor): Group
    {
        $visible = [
            new HasCapability('restricted', negated: true),
            new FieldCondition('visible_to', Operator::Equals, Restricted::PUBLIC),
        ];
        if (!$actor->isAnonymous()) {
            $visible[] = new FieldCondition('visible_to', Operator::Equals, Restricted::USERS);
            if ($actor->roles !== []) {
                $visible[] = Group::all(
                    new FieldCondition('visible_to', Operator::Equals, Restricted::ROLES),
                    new FieldCondition('visible_roles', Operator::In, $actor->roles),
                );
            }
        }

        return Group::any(...$visible);
    }

    #[\Override]
    public function allows(Actor $actor, Operation $operation, CampanellaObject $object): bool
    {
        if ($actor->hasRole(Actor::ADMINISTRATOR)) {
            return true;
        }
        foreach ($this->administratorsOnly as $capability) {
            if ($object->has($capability)) {
                return false;
            }
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
        if ($object->has(Restricted::class) && !$object->as(Restricted::class)->isVisibleTo($actor)) {
            return false;
        }

        return !$object->has(Publishable::class) || $object->as(Publishable::class)->isPublished(self::now());
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
