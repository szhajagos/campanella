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
     * (/admin/system, and /admin/media for uploads). Since 0.0.5.
     */
    public const array RESERVED_NAMES = ['system', 'media', 'upgrade'];

    /** @var array<string, Blueprint> */
    private array $blueprints = [];

    /** @var array<string, Relation> The Blueprints' own relations, by name. */
    private array $blueprintRelations = [];

    /**
     * @param array<string, array{label?: string, capabilities: list<class-string<Capability>|string>, fields?: list<Field>, relations?: list<Relation>, cardinality?: array<string, mixed>, form_order?: list<string>, defaults?: array<string, mixed>, editor?: array<string, string>, lists?: array<string, array{label?: string, query: Closure(CampanellaObject): Query}>}> $config
     */
    public function __construct(private readonly CapabilityRegistry $capabilities, array $config = [])
    {
        foreach ($config as $name => $definition) {
            $this->define($name, $definition);
        }
    }

    /**
     * @param array{label?: string, capabilities: list<class-string<Capability>|string>, fields?: list<Field>, relations?: list<Relation>, cardinality?: array<string, mixed>, form_order?: list<string>, defaults?: array<string, mixed>, editor?: array<string, string>, lists?: array<string, array{label?: string, query: Closure(CampanellaObject): Query}>} $definition
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

        $fields = [];
        foreach ($definition['fields'] ?? [] as $field) {
            if ($this->capabilities->fieldOwner($field->name) !== null
                || $this->capabilities->relationOwner($field->name) !== null) {
                throw new CapabilityException(
                    "Blueprint '{$name}': field '{$field->name}' conflicts with a capability field.",
                );
            }
            // Custom fields live in the data column. If they ever need to be filtered on,
            // they must be promoted to capability fields (columns in their own table).
            $fields[$field->name] = $field->asData();
        }

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
        );
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

    /** @return array<string, Blueprint> */
    public function all(): array
    {
        return $this->blueprints;
    }
}
