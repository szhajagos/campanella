<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\CapabilityDefinition;
use Campanella\Database\Schema\FieldTable;
use Campanella\Database\Schema\Table;
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
 *
 * Its own fields live in the `data` JSON column, except those listed under its
 * `queryable` key (since 0.2.2): the single-valued ones get a column in the
 * Blueprint's own table, `bp_<name>`, shaped like a capability's table; the
 * multi-valued ones live in `field_values`. They can be filtered and sorted on.
 */
final readonly class Blueprint
{
    /**
     * @param array<string, CapabilityDefinition> $capabilities Resolved, including dependencies.
     * @param array<string, Field> $fields Only the Blueprint's own (custom) fields: FieldStorage::Data,
     *        or FieldStorage::Table for the queryable ones (since 0.2.2).
     * @param array<string, Relation> $relations Only the Blueprint's own relations.
     * @param array<string, array{label?: string, query: Closure(CampanellaObject): Query}> $lists
     *        Lists shown on the object's own page: a label and a function that builds
     *        a Query from the object (e.g. the articles of a category).
     * @param array<string, Field> $narrowed Capability fields whose cardinality this Blueprint narrows.
     * @param list<string> $formOrder The order of fields and relations in the admin form (those not
     *        listed follow in their natural order).
     * @param array<string, mixed> $defaults Initial values of a new object, overriding the fields' own
     *        defaults (e.g. `['format' => 'html']`); values given on creation win. Since 0.0.5.
     * @param array<string, string> $editors The editor (toolbar) profile of HTML text fields in the
     *        admin, e.g. `['body' => 'full']`. Since 0.0.5.
     * @param string|null $treeScope For a Hierarchical Blueprint: a required single relation that
     *        splits the objects into separate trees (e.g. a menu item's `menu`). A parent must have
     *        the same target, siblings are counted within it, and moving a node moves its subtree
     *        with it. Since 0.0.7.
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
        public array $defaults = [],
        public array $editors = [],
        public ?string $treeScope = null,
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

    /** The name of the Blueprint's own table (without prefix): `bp_<name>`. Since 0.2.2. */
    public function tableName(): string
    {
        return 'bp_' . $this->name;
    }

    /**
     * The own single-valued queryable fields: the columns of the Blueprint's table. Since 0.2.2.
     *
     * @return array<string, Field>
     */
    public function tableFields(): array
    {
        return array_filter($this->fields, static fn (Field $f): bool => $f->storage === FieldStorage::Table && !$f->isMultiple());
    }

    /**
     * The own multi-valued queryable fields (in `field_values`). Since 0.2.2.
     *
     * @return array<string, Field>
     */
    public function valueTableFields(): array
    {
        return array_filter($this->fields, static fn (Field $f): bool => $f->usesValueTable());
    }

    public function hasTable(): bool
    {
        return $this->tableFields() !== [];
    }

    /** The schema of the Blueprint's own table, or null if it has no single-valued queryable field. Since 0.2.2. */
    public function table(): ?Table
    {
        return $this->hasTable() ? FieldTable::build($this->tableName(), $this->tableFields()) : null;
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
