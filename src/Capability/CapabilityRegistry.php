<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Query\Query;
use Closure;

/**
 * The registry of capabilities known to the system.
 *
 * On registration it checks that names, field names and scope names are
 * unique, and that dependencies can be resolved.
 */
final class CapabilityRegistry
{
    /** @var array<string, CapabilityDefinition> name => definition */
    private array $definitions = [];

    /** @var array<class-string, string> class => name */
    private array $names = [];

    /** @var array<string, string> field name => capability name */
    private array $fieldOwners = [];

    /** @var array<string, Closure(Query): Query> */
    private array $scopes = [];

    /** @var array<string, string> relation name => capability name */
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
            throw new CapabilityException("Capability '{$definition->name}' is already registered.");
        }
        foreach ($definition->fields as $fieldName => $field) {
            if (isset($this->fieldOwners[$fieldName])) {
                throw new CapabilityException(sprintf(
                    "Field '%s' is already defined by capability '%s'.",
                    $fieldName,
                    $this->fieldOwners[$fieldName],
                ));
            }
        }
        foreach ($definition->relations as $relationName => $_) {
            if (isset($this->relationOwners[$relationName]) || isset($this->fieldOwners[$relationName])
                || isset($definition->fields[$relationName])) {
                throw new CapabilityException("Relation name '{$relationName}' is already taken (relation or field).");
            }
        }
        foreach ($definition->fields as $fieldName => $_) {
            if (isset($this->relationOwners[$fieldName])) {
                throw new CapabilityException("Field name '{$fieldName}' is already the name of a relation.");
            }
        }
        foreach ($class::scopes() as $scopeName => $scope) {
            if (isset($this->scopes[$scopeName])) {
                throw new CapabilityException("Scope '{$scopeName}' already exists.");
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

    /** By name or class name. */
    public function get(string $nameOrClass): CapabilityDefinition
    {
        $name = $this->names[$nameOrClass] ?? $nameOrClass;

        return $this->definitions[$name]
            ?? throw new CapabilityException("Unknown capability: {$nameOrClass}");
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
     * The given capabilities together with their dependencies,
     * in dependency order (dependencies first).
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
                throw new CapabilityException("Circular capability dependency: {$definition->name}");
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

    /** The capability the field belongs to (if it is a capability field). */
    public function fieldOwner(string $fieldName): ?CapabilityDefinition
    {
        $owner = $this->fieldOwners[$fieldName] ?? null;

        return $owner === null ? null : $this->definitions[$owner];
    }

    /** The capability the relation belongs to (if it is a capability relation). */
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
        return $this->scopes[$name] ?? throw new CapabilityException("Unknown scope: {$name}");
    }
}
