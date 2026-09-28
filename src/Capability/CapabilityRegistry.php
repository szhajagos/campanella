<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Query\Query;
use Closure;

/**
 * A rendszerben ismert capability-k nyilvántartása.
 *
 * Regisztráláskor ellenőrzi, hogy a nevek, a mezőnevek és a scope-nevek
 * egyediek-e, és hogy a függőségek feloldhatók-e.
 */
final class CapabilityRegistry
{
    /** @var array<string, CapabilityDefinition> név => definíció */
    private array $definitions = [];

    /** @var array<class-string, string> osztály => név */
    private array $names = [];

    /** @var array<string, string> mezőnév => capability-név */
    private array $fieldOwners = [];

    /** @var array<string, Closure(Query): Query> */
    private array $scopes = [];

    /** @var array<string, string> kapcsolatnév => capability-név */
    private array $relationOwners = [];

    /** @param list<class-string<Capability>> $classes */
    public function __construct(array $classes = [])
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /** @param class-string<Capability> $class */
    public function register(string $class): CapabilityDefinition
    {
        $definition = CapabilityDefinition::fromClass($class);

        if (isset($this->definitions[$definition->name])) {
            throw new CapabilityException("A(z) '{$definition->name}' capability már regisztrálva van.");
        }
        foreach ($definition->fields as $fieldName => $field) {
            if (isset($this->fieldOwners[$fieldName])) {
                throw new CapabilityException(sprintf(
                    "A(z) '%s' mezőt már a(z) '%s' capability definiálja.",
                    $fieldName,
                    $this->fieldOwners[$fieldName],
                ));
            }
        }
        foreach ($definition->relations as $relationName => $_) {
            if (isset($this->relationOwners[$relationName]) || isset($this->fieldOwners[$relationName])
                || isset($definition->fields[$relationName])) {
                throw new CapabilityException("A(z) '{$relationName}' kapcsolatnév már foglalt (kapcsolat vagy mező).");
            }
        }
        foreach ($definition->fields as $fieldName => $_) {
            if (isset($this->relationOwners[$fieldName])) {
                throw new CapabilityException("A(z) '{$fieldName}' mezőnév már egy kapcsolat neve.");
            }
        }
        foreach ($class::scopes() as $scopeName => $scope) {
            if (isset($this->scopes[$scopeName])) {
                throw new CapabilityException("A(z) '{$scopeName}' scope már létezik.");
            }
            $this->scopes[$scopeName] = $scope;
        }

        $this->definitions[$definition->name] = $definition;
        $this->names[$class] = $definition->name;
        foreach ($definition->fields as $fieldName => $_) {
            $this->fieldOwners[$fieldName] = $definition->name;
        }
        foreach ($definition->relations as $relationName => $_) {
            $this->relationOwners[$relationName] = $definition->name;
        }

        return $definition;
    }

    /** Név vagy osztálynév alapján. */
    public function get(string $nameOrClass): CapabilityDefinition
    {
        $name = $this->names[$nameOrClass] ?? $nameOrClass;

        return $this->definitions[$name]
            ?? throw new CapabilityException("Ismeretlen capability: {$nameOrClass}");
    }

    public function has(string $nameOrClass): bool
    {
        return isset($this->definitions[$this->names[$nameOrClass] ?? $nameOrClass]);
    }

    /** @return array<string, CapabilityDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * A megadott capability-k listája a függőségeikkel együtt,
     * függőségi sorrendben (előbb az, amitől a másik függ).
     *
     * @param iterable<string> $namesOrClasses
     * @return array<string, CapabilityDefinition>
     */
    public function resolve(iterable $namesOrClasses): array
    {
        $resolved = [];
        $visiting = [];

        $visit = function (string $nameOrClass) use (&$visit, &$resolved, &$visiting): void {
            $definition = $this->get($nameOrClass);
            if (isset($resolved[$definition->name])) {
                return;
            }
            if (isset($visiting[$definition->name])) {
                throw new CapabilityException("Körkörös capability-függőség: {$definition->name}");
            }
            $visiting[$definition->name] = true;
            foreach ($definition->requires as $required) {
                $visit($required);
            }
            unset($visiting[$definition->name]);
            $resolved[$definition->name] = $definition;
        };

        foreach ($namesOrClasses as $nameOrClass) {
            $visit($nameOrClass);
        }

        return $resolved;
    }

    /** Melyik capability-hez tartozik a mező (ha capability-mező). */
    public function fieldOwner(string $fieldName): ?CapabilityDefinition
    {
        $owner = $this->fieldOwners[$fieldName] ?? null;

        return $owner === null ? null : $this->definitions[$owner];
    }

    /** Melyik capability-hez tartozik a kapcsolat (ha capability-kapcsolat). */
    public function relationOwner(string $relationName): ?CapabilityDefinition
    {
        $owner = $this->relationOwners[$relationName] ?? null;

        return $owner === null ? null : $this->definitions[$owner];
    }

    public function field(string $fieldName): ?Field
    {
        return $this->fieldOwner($fieldName)?->fields[$fieldName];
    }

    /** @return Closure(Query): Query */
    public function scope(string $name): Closure
    {
        return $this->scopes[$name] ?? throw new CapabilityException("Ismeretlen scope: {$name}");
    }
}
