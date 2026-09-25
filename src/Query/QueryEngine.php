<?php

declare(strict_types=1);

namespace Campanella\Query;

use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;

/**
 * Lekérdezések végrehajtása.
 *
 * Az Actor kötelező paraméter: jogosultság nélküli lekérdezés nem
 * létezik. A folyamat:
 *
 *   Query → scope-ok feloldása → Policy (secured query) → SQL (id-k)
 *         → ObjectRepository (kötegelt betöltés) → ResultSet
 */
final class QueryEngine
{
    public function __construct(
        private readonly Connection $db,
        private readonly QueryCompiler $compiler,
        private readonly ObjectRepository $repository,
        private readonly CapabilityRegistry $capabilities,
        private readonly AccessPolicy $policy,
    ) {
    }

    public function execute(Query $query, Actor $actor, bool $withTotal = false): ResultSet
    {
        $secured = $this->secure($query, $actor);

        $compiled = $this->compiler->compile($secured);
        $ids = array_map(intval(...), $this->db->fetchColumn($compiled->sql, $compiled->params));

        $total = null;
        if ($withTotal) {
            $count = $this->compiler->compileCount($secured);
            $total = (int) $this->db->fetchValue($count->sql, $count->params);
        }

        return new ResultSet(
            array_values($this->repository->loadMany($ids)),
            $total,
            $secured->getLimit(),
            $secured->getOffset(),
        );
    }

    public function first(Query $query, Actor $actor): ?CampanellaObject
    {
        return $this->execute($query->limit(1), $actor)->first();
    }

    public function count(Query $query, Actor $actor): int
    {
        $compiled = $this->compiler->compileCount($this->secure($query, $actor));

        return (int) $this->db->fetchValue($compiled->sql, $compiled->params);
    }

    /** A futtatandó lekérdezés: scope-ok feloldva, jogosultsági feltételekkel. */
    public function secure(Query $query, Actor $actor): Query
    {
        $resolved = $query->withoutScopes();
        foreach ($query->scopes() as $scope) {
            $resolved = ($this->capabilities->scope($scope))($resolved);
        }

        return $this->policy->constrain($resolved, $actor);
    }
}
