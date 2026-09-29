<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\Capability;
use Campanella\Capability\CapabilityDefinition;
use Campanella\Capability\CapabilityException;
use Campanella\Relation\Relation;
use DateTimeImmutable;

/**
 * Az általános objektum. Nincsenek típusonkénti alosztályai: amit tud,
 * azt a capability-jei határozzák meg.
 *
 * Data Mapper minta: az objektum nem tud magáról menteni, ezt az
 * ObjectRepository végzi.
 */
final class CampanellaObject
{
    /** @var array<string, mixed> mezőnév => érték */
    private array $values = [];

    /** @var array<class-string<Capability>, Capability> */
    private array $adapters = [];

    /** @var array<string, list<int>> kapcsolatnév => célobjektumok azonosítói, sorrendben */
    private array $relatedIds = [];

    /** @var array<string, list<CampanellaObject>> A RelationLoader által betöltött célobjektumok */
    private array $resolved = [];

    /**
     * @param array<string, CapabilityDefinition> $capabilities név => definíció
     * @param array<string, Field> $fields Az objektumon értelmezett összes mező
     * @param array<string, mixed> $values
     * @param array<string, Relation> $relations Az objektumon értelmezett kapcsolatok
     * @param array<string, list<int>> $relatedIds Kapcsolatnév => célazonosítók
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

    /** Rendelkezik-e a capability-vel (név vagy osztálynév alapján). */
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
     * Az objektum egy capability „szemüvegén” keresztül.
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
                'A(z) #%s (%s) objektum nem rendelkezik ezzel a capability-vel: %s',
                $this->id ?? 'új',
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
            throw new \OutOfBoundsException("Az objektumon nincs ilyen mező: {$field}");
        }

        return $this->values[$field];
    }

    public function set(string $field, mixed $value): void
    {
        $definition = $this->fields[$field]
            ?? throw new \OutOfBoundsException("Az objektumon nincs ilyen mező: {$field}");
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
     * Csak olvasható hozzáférés a sablonokból: {{ object.title }}.
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

    // --- Kapcsolatok -----------------------------------------------------------

    /** @return array<string, Relation> Az objektumon értelmezett kapcsolatok definíciói. */
    public function relations(): array
    {
        return $this->relations;
    }

    public function hasRelation(string $name): bool
    {
        return isset($this->relations[$name]);
    }

    /**
     * A kapcsolat célobjektumainak azonosítói, sorrendben. Nem ellenőrzi,
     * hogy az aktuális látogató láthatja-e őket; megjelenítéshez a
     * relatedObjects() való.
     *
     * @return list<int>
     */
    public function relatedIds(string $name): array
    {
        $this->relation($name);

        return $this->relatedIds[$name];
    }

    /**
     * A kapcsolat összes célja egyszerre, a megadott sorrendben.
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
            throw new \InvalidArgumentException("A(z) '{$name}' kapcsolatnak legfeljebb egy célja lehet.");
        }
        $this->relatedIds[$name] = $ids;
        unset($this->resolved[$name]);
    }

    /**
     * Új cél hozzáadása. Egyes (One) kapcsolatnál lecseréli a korábbit;
     * többesnél a végére fűzi, ha még nincs benne.
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
     * A kapcsolat célobjektumai, ahogy a RelationLoader betöltötte őket: csak
     * azok, amelyeket az adott Actor láthat, sorrendben.
     *
     * @return list<CampanellaObject>
     */
    public function relatedObjects(string $name): array
    {
        $this->relation($name);

        return $this->resolved[$name] ?? throw new \LogicException(
            "A(z) '{$name}' kapcsolat céljai nincsenek betöltve. Megjelenítés előtt a RelationLoader::resolve() tölti be őket.",
        );
    }

    public function isResolved(string $name): bool
    {
        return isset($this->resolved[$name]);
    }

    /**
     * @internal Csak a RelationLoader hívja.
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
            ?? throw new \OutOfBoundsException("Az objektumon nincs ilyen kapcsolat: {$name}");
    }

    private static function targetId(CampanellaObject|int $target): int
    {
        if ($target instanceof self) {
            return $target->id() ?? throw new \InvalidArgumentException(
                'Kapcsolat célja csak már elmentett objektum lehet.',
            );
        }

        return $target;
    }

    /** @internal Csak az ObjectRepository hívja mentés után. */
    public function markSaved(int $id, DateTimeImmutable $updated): void
    {
        $this->id = $id;
        $this->updated = $updated;
    }
}
