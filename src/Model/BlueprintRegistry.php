<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\Capability;
use Campanella\Capability\CapabilityException;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Query\Query;
use Campanella\Relation\Relation;
use Closure;

final class BlueprintRegistry
{
    /**
     * Names a Blueprint cannot have, because the admin uses them as its own paths
     * (/admin/system, /admin/media for uploads, /admin/profile since 0.1.0). Since 0.0.5.
     */
    public const array RESERVED_NAMES = ['system', 'media', 'upgrade', 'profile'];

    /** @var array<string, Blueprint> */
    private array $blueprints = [];

    /** @var array<string, Relation> The Blueprints' own relations, by name. */
    private array $blueprintRelations = [];

    /** @var array<string, Field> The Blueprints' own fields, by name (the first definition; the others match it). */
    private array $ownFields = [];

    /**
     * The most single-valued queryable own fields of a Blueprint (since 0.2.2): its table's row
     * must stay within MySQL's row size limit.
     */
    public const int MAX_TABLE_FIELDS = 40;

    /** The longest name of a Blueprint with a table (`bp_<name>`, its keys' names fit in 64 characters). */
    public const int MAX_TABLE_NAME = 40;

    /**
     * @param array<string, array{label?: string, capabilities: list<class-string<Capability>|string>, fields?: list<Field>, queryable?: list<string>, relations?: list<Relation>, cardinality?: array<string, mixed>, form_order?: list<string>, defaults?: array<string, mixed>, editor?: array<string, string>, tree_scope?: string, lists?: array<string, array{label?: string, query: Closure(CampanellaObject): Query}>}> $config
     */
    public function __construct(private readonly CapabilityRegistry $capabilities, array $config = [])
    {
        foreach ($config as $name => $definition) {
            $this->define($name, $definition);
        }
    }

    /**
     * @param array{label?: string, capabilities: list<class-string<Capability>|string>, fields?: list<Field>, queryable?: list<string>, relations?: list<Relation>, cardinality?: array<string, mixed>, form_order?: list<string>, defaults?: array<string, mixed>, editor?: array<string, string>, tree_scope?: string, lists?: array<string, array{label?: string, query: Closure(CampanellaObject): Query}>} $definition
     */
    public function define(string $name, array $definition): Blueprint
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new CapabilityException("Invalid Blueprint name: {$name}");
        }
        if (in_array($name, self::RESERVED_NAMES, true)) {
            throw new CapabilityException("The Blueprint name '{$name}' is reserved by the admin.");
        }

        $capabilities = $this->capabilities->resolve($definition['capabilities']);

        // Own fields live in the data column, except the queryable ones (since 0.2.2): a column
        // in the Blueprint's own table, or the field_values table if multi-valued.
        $queryable = array_map(strval(...), $definition['queryable'] ?? []);
        $fields = [];
        foreach ($definition['fields'] ?? [] as $field) {
            if ($this->capabilities->fieldOwner($field->name) !== null
                || $this->capabilities->relationOwner($field->name) !== null) {
                throw new CapabilityException(
                    "Blueprint '{$name}': field '{$field->name}' conflicts with a capability field.",
                );
            }
            $fields[$field->name] = in_array($field->name, $queryable, true) ? $field->asTable() : $field->asData();
        }
        $this->checkQueryable($name, $queryable, $fields);

        // Narrowing the cardinality of capability fields, e.g. 'cardinality' => ['phones' => 2].
        $narrowed = [];
        $capabilityFields = [];
        foreach ($capabilities as $capability) {
            $capabilityFields += $capability->fields;
        }
        foreach ($definition['cardinality'] ?? [] as $fieldName => $cardinality) {
            $field = $capabilityFields[$fieldName] ?? throw new CapabilityException(isset($fields[$fieldName])
                ? "Blueprint '{$name}': set the cardinality of the custom field '{$fieldName}' on the field itself."
                : "Blueprint '{$name}': unknown field in 'cardinality': {$fieldName}");
            if (!is_int($cardinality)) {
                throw new CapabilityException("Blueprint '{$name}': the cardinality of '{$fieldName}' must be an integer.");
            }
            try {
                $narrowed[$fieldName] = $field->withCardinality($cardinality);
            } catch (\InvalidArgumentException $e) {
                throw new CapabilityException("Blueprint '{$name}': " . $e->getMessage(), 0, $e);
            }
        }

        $relations = [];
        foreach ($definition['relations'] ?? [] as $relation) {
            $this->checkRelation($name, $relation, $fields);
            $relations[$relation->name] = $relation;
        }
        foreach ($relations as $relationName => $relation) {
            $this->blueprintRelations[$relationName] = $relation;
        }
        foreach ($fields as $fieldName => $field) {
            $this->ownFields[$fieldName] ??= $field;
        }

        return $this->blueprints[$name] = new Blueprint(
            $name,
            $definition['label'] ?? ucfirst($name),
            $capabilities,
            $fields,
            $relations,
            $definition['lists'] ?? [],
            $narrowed,
            array_map(strval(...), $definition['form_order'] ?? []),
            $this->checkFieldKeys($name, 'defaults', $definition['defaults'] ?? [], $capabilities, $fields),
            array_map(strval(...), $this->checkFieldKeys($name, 'editor', $definition['editor'] ?? [], $capabilities, $fields)),
            $this->checkTreeScope($name, $definition['tree_scope'] ?? null, $capabilities, $relations),
        );
    }

    /**
     * 'tree_scope': a Hierarchical Blueprint's required single relation that splits its trees.
     *
     * @param array<string, \Campanella\Capability\CapabilityDefinition> $capabilities
     * @param array<string, Relation> $relations The Blueprint's own relations.
     */
    private function checkTreeScope(string $blueprint, ?string $scope, array $capabilities, array $relations): ?string
    {
        if ($scope === null) {
            return null;
        }
        if (!isset($capabilities['hierarchical'])) {
            throw new CapabilityException("Blueprint '{$blueprint}': 'tree_scope' needs the Hierarchical capability.");
        }
        $relation = $relations[$scope] ?? null;
        foreach ($capabilities as $capability) {
            $relation ??= $capability->relations[$scope] ?? null;
        }
        if ($relation === null || $relation->isMany() || !$relation->required || $scope === 'parent') {
            throw new CapabilityException("Blueprint '{$blueprint}': 'tree_scope' must name a required single relation of the Blueprint (not 'parent').");
        }

        return $scope;
    }

    /**
     * The `queryable` own fields: known, not too many, short enough names and texts;
     * a field name used by several Blueprints means one kind of value (the same type
     * and cardinality), so a query on it means the same everywhere.
     *
     * @param list<string> $queryable
     * @param array<string, Field> $fields The Blueprint's own fields.
     */
    private function checkQueryable(string $blueprint, array $queryable, array $fields): void
    {
        foreach ($queryable as $fieldName) {
            if (!isset($fields[$fieldName])) {
                throw new CapabilityException("Blueprint '{$blueprint}': unknown field in 'queryable': {$fieldName}");
            }
        }
        foreach ($fields as $field) {
            $other = $this->ownFields[$field->name] ?? null;
            if ($other !== null && ($other->type !== $field->type || $other->cardinality !== $field->cardinality)) {
                throw new CapabilityException("Blueprint '{$blueprint}': field '{$field->name}' is defined with another type or cardinality in another Blueprint.");
            }
            if ($field->storage !== FieldStorage::Table) {
                continue;
            }
            if (strlen($field->name) > 59) {
                throw new CapabilityException("Blueprint '{$blueprint}': the queryable field name '{$field->name}' is too long (at most 59 characters).");
            }
            if ($field->type->columnType() === \Campanella\Database\Schema\ColumnType::String && $field->length > Field::MAX_MULTI_STRING_LENGTH) {
                throw new CapabilityException(sprintf("Blueprint '%s': the queryable field '%s' can be at most %d characters long (use a Text field for longer texts).", $blueprint, $field->name, Field::MAX_MULTI_STRING_LENGTH));
            }
        }
        $columns = array_filter($fields, static fn (Field $f): bool => $f->storage === FieldStorage::Table && !$f->isMultiple());
        if (count($columns) > self::MAX_TABLE_FIELDS) {
            throw new CapabilityException(sprintf("Blueprint '%s': at most %d single-valued queryable fields.", $blueprint, self::MAX_TABLE_FIELDS));
        }
        if ($columns !== [] && strlen($blueprint) > self::MAX_TABLE_NAME) {
            throw new CapabilityException(sprintf("Blueprint '%s': a Blueprint with queryable fields can have a name of at most %d characters.", $blueprint, self::MAX_TABLE_NAME));
        }
    }

    /**
     * The Blueprints that have the field as an own queryable field, in definition order. Since 0.2.2.
     *
     * @return list<Blueprint>
     */
    public function queryableOwners(string $field): array
    {
        return array_values(array_filter(
            $this->blueprints,
            static fn (Blueprint $b): bool => ($b->fields[$field] ?? null)?->storage === FieldStorage::Table,
        ));
    }

    /** A Blueprint's own field by name (whichever Blueprint defines it; they all match), or null. Since 0.2.2. */
    public function ownField(string $name): ?Field
    {
        return $this->ownFields[$name] ?? null;
    }

    /**
     * The definition of a relation by name, whether a capability or a Blueprint provides it.
     */
    public function relation(string $name): ?Relation
    {
        return $this->capabilities->relationOwner($name)?->relations[$name] ?? $this->blueprintRelations[$name] ?? null;
    }

    /**
     * A per-field setting ('defaults', 'editor') may only name fields the Blueprint has.
     *
     * @param array<string, mixed> $values
     * @param array<string, \Campanella\Capability\CapabilityDefinition> $capabilities
     * @param array<string, Field> $fields The Blueprint's own fields.
     * @return array<string, mixed>
     */
    private function checkFieldKeys(string $blueprint, string $key, array $values, array $capabilities, array $fields): array
    {
        foreach (array_keys($values) as $fieldName) {
            $known = isset($fields[$fieldName]);
            foreach ($capabilities as $capability) {
                $known = $known || isset($capability->fields[$fieldName]);
            }
            if (!$known) {
                throw new CapabilityException("Blueprint '{$blueprint}': unknown field in '{$key}': {$fieldName}");
            }
        }

        return $values;
    }

    /**
     * @param array<string, Field> $fields The Blueprint's own fields.
     */
    private function checkRelation(string $blueprint, Relation $relation, array $fields): void
    {
        $name = $relation->name;
        if ($this->capabilities->fieldOwner($name) !== null || $this->capabilities->relationOwner($name) !== null
            || isset($fields[$name])) {
            throw new CapabilityException(
                "Blueprint '{$blueprint}': relation '{$name}' conflicts with a field or a capability relation.",
            );
        }
        // Several Blueprints may use the same relation name, but only with an identical definition.
        if (isset($this->blueprintRelations[$name]) && $this->blueprintRelations[$name] != $relation) {
            throw new CapabilityException("Relation '{$name}' is defined differently in another Blueprint.");
        }
        foreach ($relation->targetCapabilities as $capability) {
            $this->capabilities->get($capability); // throws for an unknown capability
        }
    }

    public function get(string $name): Blueprint
    {
        return $this->blueprints[$name] ?? throw new CapabilityException("Unknown Blueprint: {$name}");
    }

    public function find(string $name): ?Blueprint
    {
        return $this->blueprints[$name] ?? null;
    }

    /**
     * The relations that split trees ('tree_scope' of the Blueprints), each once. Since 0.0.7.
     *
     * @return list<string>
     */
    public function treeScopes(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (Blueprint $b): ?string => $b->treeScope,
            $this->blueprints,
        ))));
    }

    /** @return array<string, Blueprint> */
    public function all(): array
    {
        return $this->blueprints;
    }
}
