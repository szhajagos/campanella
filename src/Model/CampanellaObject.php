<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\Capability;
use Campanella\Capability\CapabilityDefinition;
use Campanella\Capability\CapabilityException;
use Campanella\Relation\Relation;
use DateTimeImmutable;

/**
 * The generic object. It has no per-type subclasses: what it can do is
 * determined by its capabilities.
 *
 * Data Mapper pattern: the object cannot save itself; the
 * ObjectRepository does that.
 */
final class CampanellaObject
{
    /** @var array<string, mixed> field name => value */
    private array $values = [];

    /** @var array<class-string<Capability>, Capability> */
    private array $adapters = [];

    /** @var array<string, list<int>> relation name => target object IDs, in order */
    private array $relatedIds = [];

    /** @var array<string, list<CampanellaObject>> Target objects loaded by the RelationLoader */
    private array $resolved = [];

    /**
     * @param array<string, CapabilityDefinition> $capabilities name => definition
     * @param array<string, Field> $fields All fields defined on the object
     * @param array<string, mixed> $values
     * @param array<string, Relation> $relations The relations defined on the object
     * @param array<string, list<int>> $relatedIds Relation name => target IDs
     */
    public function __construct(
        private ?int $id,
        private readonly string $uuid,
        private readonly string $blueprint,
        private readonly array $capabilities,
        private readonly array $fields,
        array $values,
        private readonly DateTimeImmutable $created,
        private DateTimeImmutable $updated,
        private readonly array $relations = [],
        array $relatedIds = [],
    ) {
        foreach ($fields as $name => $field) {
            $value = array_key_exists($name, $values) ? $values[$name] : $field->default;
            $this->values[$name] = $field->type->cast($value);
        }
        foreach ($relations as $name => $_) {
            $this->relatedIds[$name] = array_map(intval(...), $relatedIds[$name] ?? []);
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function blueprint(): string
    {
        return $this->blueprint;
    }

    public function created(): DateTimeImmutable
    {
        return $this->created;
    }

    public function updated(): DateTimeImmutable
    {
        return $this->updated;
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }

    /** Whether it has the capability (by name or class name). */
    public function has(string $capability): bool
    {
        if (isset($this->capabilities[$capability])) {
            return true;
        }
        foreach ($this->capabilities as $definition) {
            if ($definition->class === $capability) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function capabilityNames(): array
    {
        return array_keys($this->capabilities);
    }

    /** @return array<string, CapabilityDefinition> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * The object seen through the "lens" of a capability.
     *
     *     $object->as(Publishable::class)->publish();
     *
     * @template T of Capability
     * @param class-string<T> $class
     * @return T
     */
    public function as(string $class): Capability
    {
        if (!$this->has($class)) {
            throw new CapabilityException(sprintf(
                'Object #%s (%s) does not have this capability: %s',
                $this->id ?? 'new',
                $this->blueprint,
                $class,
            ));
        }

        /** @var T */
        return $this->adapters[$class] ??= new $class($this);
    }

    public function get(string $field): mixed
    {
        if (!array_key_exists($field, $this->values)) {
            throw new \OutOfBoundsException("The object has no such field: {$field}");
        }

        return $this->values[$field];
    }

    public function set(string $field, mixed $value): void
    {
        $definition = $this->fields[$field]
            ?? throw new \OutOfBoundsException("The object has no such field: {$field}");
        $this->values[$field] = $definition->type->cast($value);
    }

    /** @param array<string, mixed> $values */
    public function fill(array $values): void
    {
        foreach ($values as $field => $value) {
            $this->set($field, $value);
        }
    }

    public function hasField(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    /** @return array<string, Field> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Read-only access from templates: {{ object.title }}.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'id' => $this->id,
            'uuid' => $this->uuid,
            'blueprint' => $this->blueprint,
            'created' => $this->created,
            'updated' => $this->updated,
            default => isset($this->fields[$name]) && $this->fields[$name]->hidden ? null : ($this->values[$name] ?? null),
        };
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['id', 'uuid', 'blueprint', 'created', 'updated'], true)
            || (array_key_exists($name, $this->values) && !$this->fields[$name]->hidden);
    }

    // --- Relations -------------------------------------------------------------

    /** @return array<string, Relation> Definitions of the relations defined on the object. */
    public function relations(): array
    {
        return $this->relations;
    }

    public function hasRelation(string $name): bool
    {
        return isset($this->relations[$name]);
    }

    /**
     * The IDs of the relation's target objects, in order. Does not check
     * whether the current visitor may see them; for rendering, use
     * relatedObjects().
     *
     * @return list<int>
     */
    public function relatedIds(string $name): array
    {
        $this->relation($name);

        return $this->relatedIds[$name];
    }

    /**
     * Sets all targets of the relation at once, in the given order.
     *
     * @param iterable<CampanellaObject|int> $targets
     */
    public function setRelated(string $name, iterable $targets): void
    {
        $relation = $this->relation($name);
        $ids = [];
        foreach ($targets as $target) {
            $id = self::targetId($target);
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (!$relation->isMany() && count($ids) > 1) {
            throw new \InvalidArgumentException("Relation '{$name}' can have at most one target.");
        }
        $this->relatedIds[$name] = $ids;
        unset($this->resolved[$name]);
    }

    /**
     * Adds a new target. For a single (One) relation it replaces the previous one;
     * for a Many relation it appends it if not already present.
     */
    public function relate(string $name, CampanellaObject|int $target): void
    {
        $relation = $this->relation($name);
        $id = self::targetId($target);

        $ids = $relation->isMany() ? $this->relatedIds[$name] : [];
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        $this->relatedIds[$name] = $ids;
        unset($this->resolved[$name]);
    }

    public function unrelate(string $name, CampanellaObject|int $target): void
    {
        $this->relation($name);
        $id = self::targetId($target);
        $this->relatedIds[$name] = array_values(array_filter(
            $this->relatedIds[$name],
            static fn (int $existing): bool => $existing !== $id,
        ));
        unset($this->resolved[$name]);
    }

    /**
     * The relation's target objects as loaded by the RelationLoader: only
     * those the given Actor may see, in order.
     *
     * @return list<CampanellaObject>
     */
    public function relatedObjects(string $name): array
    {
        $this->relation($name);

        return $this->resolved[$name] ?? throw new \LogicException(
            "The targets of relation '{$name}' are not loaded. RelationLoader::resolve() loads them before rendering.",
        );
    }

    public function isResolved(string $name): bool
    {
        return isset($this->resolved[$name]);
    }

    /**
     * @internal Called only by the RelationLoader.
     * @param list<CampanellaObject> $objects
     */
    public function attachResolved(string $name, array $objects): void
    {
        $this->relation($name);
        $this->resolved[$name] = $objects;
    }

    private function relation(string $name): Relation
    {
        return $this->relations[$name]
            ?? throw new \OutOfBoundsException("The object has no such relation: {$name}");
    }

    private static function targetId(CampanellaObject|int $target): int
    {
        if ($target instanceof self) {
            return $target->id() ?? throw new \InvalidArgumentException(
                'A relation target must be an already saved object.',
            );
        }

        return $target;
    }

    /** @internal Called only by the ObjectRepository after saving. */
    public function markSaved(int $id, DateTimeImmutable $updated): void
    {
        $this->id = $id;
        $this->updated = $updated;
    }
}
