<?php

declare(strict_types=1);

namespace Campanella\Query\Condition;

/**
 * Az objektumnak van-e (vagy nincs-e) adott kapcsolata a megadott
 * célobjektumok valamelyikével. Üres célista: bármilyen céllal.
 *
 *     new RelatedTo('categories', [12])      // a 12-es kategóriába tartozik
 *     new RelatedTo('categories', [])        // van legalább egy kategóriája
 */
final readonly class RelatedTo implements Condition
{
    /** @param list<int> $targets */
    public function __construct(
        public string $relation,
        public array $targets = [],
        public bool $negated = false,
    ) {
    }
}
