<?php

declare(strict_types=1);

namespace Campanella\Access;

use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;

/**
 * An access control policy has two faces:
 *
 *  - constrain(): adds its own conditions to the query, so filtering
 *    happens in SQL (QUERY + POLICY → SECURED QUERY → SQL);
 *  - allows(): decides for a single, already loaded object.
 *
 * The two must mean the same thing. That is why the conditions are
 * declarative (Condition objects), not arbitrary PHP code.
 */
interface AccessPolicy
{
    public function constrain(Query $query, Actor $actor): Query;

    public function allows(Actor $actor, Operation $operation, CampanellaObject $object): bool;
}
