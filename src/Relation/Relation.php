<?php

declare(strict_types=1);

namespace Campanella\Relation;

/**
 * The definition of a relation: a named, directed link from one object
 * (the source) to other objects (the targets).
 *
 *     new Relation('categories', Cardinality::Many, targetBlueprints: ['category'], label: 'Kategóriák')
 *
 * Like fields, a relation is provided by a capability (relations()) or by
 * a Blueprint (the 'relations' key). The name is unique system-wide and
 * must not conflict with a field name.
 */
final readonly class Relation
{
    /**
     * @param list<string> $targetCapabilities The target must have all of them (name or class name).
     * @param list<string> $targetBlueprints The target was created from one of these; empty list: any.
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
            throw new \InvalidArgumentException("Invalid relation name: {$name}");
        }
    }

    public function isMany(): bool
    {
        return $this->cardinality === Cardinality::Many;
    }
}
