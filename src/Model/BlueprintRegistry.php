<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\Capability;
use Campanella\Capability\CapabilityException;
use Campanella\Capability\CapabilityRegistry;

final class BlueprintRegistry
{
    /** @var array<string, Blueprint> */
    private array $blueprints = [];

    /**
     * @param array<string, array{label?: string, capabilities: list<class-string<Capability>|string>, fields?: list<Field>}> $config
     */
    public function __construct(private readonly CapabilityRegistry $capabilities, array $config = [])
    {
        foreach ($config as $name => $definition) {
            $this->define($name, $definition);
        }
    }

    /**
     * @param array{label?: string, capabilities: list<class-string<Capability>|string>, fields?: list<Field>} $definition
     */
    public function define(string $name, array $definition): Blueprint
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new CapabilityException("Érvénytelen Blueprint-név: {$name}");
        }

        $capabilities = $this->capabilities->resolve($definition['capabilities']);

        $fields = [];
        foreach ($definition['fields'] ?? [] as $field) {
            if ($this->capabilities->fieldOwner($field->name) !== null) {
                throw new CapabilityException(
                    "A(z) '{$name}' Blueprint '{$field->name}' mezője ütközik egy capability mezőjével.",
                );
            }
            // Az egyedi mezők a data oszlopban élnek. Ha egyszer szűrni kell rájuk,
            // capability-mezővé (saját táblás oszloppá) kell előléptetni őket.
            $fields[$field->name] = $field->asData();
        }

        return $this->blueprints[$name] = new Blueprint(
            $name,
            $definition['label'] ?? ucfirst($name),
            $capabilities,
            $fields,
        );
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
