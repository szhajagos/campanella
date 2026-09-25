<?php

declare(strict_types=1);

namespace Campanella\Access;

use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;

/**
 * Egy jogosultsági szabálynak két arca van:
 *
 *  - constrain(): a lekérdezésbe fűzi a saját feltételeit, így a szűrés
 *    SQL-ben történik (QUERY + POLICY → SECURED QUERY → SQL);
 *  - allows(): egyetlen, már betöltött objektumra dönt.
 *
 * A kettőnek ugyanazt kell jelentenie. Ezért a feltételek deklaratívak
 * (Condition-objektumok), nem tetszőleges PHP-kód.
 */
interface AccessPolicy
{
    public function constrain(Query $query, Actor $actor): Query;

    public function allows(Actor $actor, Operation $operation, CampanellaObject $object): bool;
}
