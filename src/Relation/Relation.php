<?php

declare(strict_types=1);

namespace Campanella\Relation;

/**
 * Egy kapcsolat definíciója: egy objektumból (forrás) más objektumokra
 * (célok) mutató, elnevezett, irányított kapcsolat.
 *
 *     new Relation('categories', Cardinality::Many, targetBlueprints: ['category'], label: 'Kategóriák')
 *
 * A kapcsolatot, a mezőkhöz hasonlóan, egy capability (relations())
 * vagy egy Blueprint ('relations' kulcs) adja meg. A név rendszerszinten
 * egyedi, és nem ütközhet mezőnévvel.
 */
final readonly class Relation
{
    /**
     * @param list<string> $targetCapabilities A célnak mindegyikkel rendelkeznie kell (név vagy osztálynév).
     * @param list<string> $targetBlueprints A cél ezek egyikéből készült; üres lista: bármelyik.
     */
    public function __construct(
        public string $name,
        public Cardinality $cardinality = Cardinality::Many,
        public array $targetCapabilities = [],
        public array $targetBlueprints = [],
        public bool $required = false,
        public string $label = '',
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Érvénytelen kapcsolatnév: {$name}");
        }
    }

    public function isMany(): bool
    {
        return $this->cardinality === Cardinality::Many;
    }
}
