<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\CapabilityDefinition;
use Campanella\Query\Query;
use Campanella\Relation\Relation;
use Closure;

/**
 * A named bundle of capabilities: the "type" as data.
 *
 * Not a PHP class but configuration (config/blueprints.php), so it can be
 * versioned in git. The Blueprint defines which capabilities a new object
 * is created with, which custom fields and relations it has, and which
 * lists belong to the object's page.
 */
final readonly class Blueprint
{
    /**
     * @param array<string, CapabilityDefinition> $capabilities Resolved, including dependencies.
     * @param array<string, Field> $fields Only the Blueprint's own (custom) fields.
     * @param array<string, Relation> $relations Only the Blueprint's own relations.
     * @param array<string, array{label?: string, query: Closure(CampanellaObject): Query}> $lists
     *        Lists shown on the object's own page: a label and a function that builds
     *        a Query from the object (e.g. the articles of a category).
     * @param array<string, Field> $narrowed Capability fields whose cardinality this Blueprint narrows.
     * @param list<string> $formOrder The order of fields and relations in the admin form (those not
     *        listed follow in their natural order).
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $capabilities,
        public array $fields,
        public array $relations = [],
        public array $lists = [],
        public array $narrowed = [],
        public array $formOrder = [],
    ) {
    }

    /**
     * The capability fields (with this Blueprint's narrowed cardinalities) and
     * the custom fields together.
     *
     * @return array<string, Field>
     */
    public function allFields(): array
    {
        $fields = [];
        foreach ($this->capabilities as $capability) {
            $fields += $capability->fields;
        }

        return $this->narrow($fields) + $this->fields;
    }

    /**
     * Applies the narrowed cardinalities to the given fields (those the Blueprint does not narrow stay as they are).
     *
     * @param array<string, Field> $fields
     * @return array<string, Field>
     */
    public function narrow(array $fields): array
    {
        foreach ($this->narrowed as $name => $field) {
            if (isset($fields[$name])) {
                $fields[$name] = $field;
            }
        }

        return $fields;
    }

    /** @return array<string, Relation> The capability relations and the own relations together. */
    public function allRelations(): array
    {
        $relations = [];
        foreach ($this->capabilities as $capability) {
            $relations += $capability->relations;
        }

        return $relations + $this->relations;
    }
}
