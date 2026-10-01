<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Query\Query;
use Campanella\Relation\Relation;
use Closure;

/**
 * The Capability contract.
 *
 * A capability provides two things:
 *
 *  1. A static description (the system builds schema and queries from it):
 *     - AsCapability attribute: name, dependencies
 *     - fields(): which fields it brings, and where they are stored
 *     - relations(): which relations it brings (e.g. author, parent)
 *     - scopes(): named query filters (e.g. 'published')
 *
 *  2. Behavior on a concrete object. The capability is an adapter
 *     that wraps the object:
 *
 *         $object->as(Publishable::class)->publish();
 *
 * The capability itself does not write to the database; it sets values
 * on the object, and ObjectRepository does the saving.
 */
abstract class Capability
{
    final public function __construct(protected readonly CampanellaObject $object)
    {
    }

    /** @return list<Field> */
    abstract public static function fields(): array;

    /**
     * The relations brought by the capability.
     *
     * @return list<Relation>
     */
    public static function relations(): array
    {
        return [];
    }

    /**
     * Named query filters. The key is unique system-wide.
     *
     * @return array<string, Closure(Query): Query>
     */
    public static function scopes(): array
    {
        return [];
    }

    /**
     * Runs before saving: fills in derived values, normalizes
     * (e.g. Routable builds the path from the title here).
     */
    public function prepareForSave(): void
    {
    }

    /**
     * Validation before saving, after prepareForSave(). The system itself
     * checks required fields; the capability's own rules go here
     * (e.g. e-mail address format).
     *
     * @return array<string, Message|string> field name => message (a Message, or a message key)
     */
    public function validate(): array
    {
        return [];
    }

    public function object(): CampanellaObject
    {
        return $this->object;
    }
}
