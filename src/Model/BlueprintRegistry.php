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
    /** @var array<string, Blueprint> */
    private array $blueprints = [];

    /** @var array<string, Relation> A Blueprintek saját kapcsolatai, név szerint. */
    private array $blueprintRelations = [];

    /**
     * @param array<string, array{label?: string, capabilities: list<class-string<Capability>|string>, fields?: list<Field>, relations?: list<Relation>, lists?: array<string, array{label?: string, query: Closure(CampanellaObject): Query}>}> $config
     */
    public function __construct(private readonly CapabilityRegistry $capabilities, array $config = [])
    {
        foreach ($config as $name => $definition) {
            $this->define($name, $definition);
        }
    }

    /**
     * @param array{label?: string, capabilities: list<class-string<Capability>|string>, fields?: list<Field>, relations?: list<Relation>, lists?: array<string, array{label?: string, query: Closure(CampanellaObject): Query}>} $definition
     */
    public function define(string $name, array $definition): Blueprint
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new CapabilityException("Érvénytelen Blueprint-név: {$name}");
        }

        $capabilities = $this->capabilities->resolve($definition['capabilities']);

        $fields = [];
        foreach ($definition['fields'] ?? [] as $field) {
            if ($this->capabilities->fieldOwner($field->name) !== null
                || $this->capabilities->relationOwner($field->name) !== null) {
                throw new CapabilityException(
                    "A(z) '{$name}' Blueprint '{$field->name}' mezője ütközik egy capability mezőjével.",
                );
            }
            // Az egyedi mezők a data oszlopban élnek. Ha egyszer szűrni kell rájuk,
            // capability-mezővé (saját táblás oszloppá) kell előléptetni őket.
            $fields[$field->name] = $field->asData();
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
        );
    }

    /**
     * Egy kapcsolat definíciója név szerint, akár capability, akár Blueprint adja.
     */
    public function relation(string $name): ?Relation
    {
        return $this->capabilities->relationOwner($name)?->relations[$name] ?? $this->blueprintRelations[$name] ?? null;
    }

    /**
     * @param array<string, Field> $fields A Blueprint saját mezői.
     */
    private function checkRelation(string $blueprint, Relation $relation, array $fields): void
    {
        $name = $relation->name;
        if ($this->capabilities->fieldOwner($name) !== null || $this->capabilities->relationOwner($name) !== null
            || isset($fields[$name])) {
            throw new CapabilityException(
                "A(z) '{$blueprint}' Blueprint '{$name}' kapcsolata ütközik egy mezővel vagy capability-kapcsolattal.",
            );
        }
        // Több Blueprint is használhatja ugyanazt a kapcsolatnevet, de csak azonos definícióval.
        if (isset($this->blueprintRelations[$name]) && $this->blueprintRelations[$name] != $relation) {
            throw new CapabilityException("A(z) '{$name}' kapcsolat egy másik Blueprintben eltérő definícióval szerepel.");
        }
        foreach ($relation->targetCapabilities as $capability) {
            $this->capabilities->get($capability); // ismeretlen capability esetén hibát dob
        }
    }

    public function get(string $name): Blueprint
    {
        return $this->blueprints[$name] ?? throw new CapabilityException("Ismeretlen Blueprint: {$name}");
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
