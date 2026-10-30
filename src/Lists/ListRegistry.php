<?php

declare(strict_types=1);

namespace Campanella\Lists;

use Campanella\Model\BlueprintRegistry;

/**
 * The lists made in the admin, checked against the Blueprints (since 0.2.5). One
 * that does not pass (e.g. its field is not queryable any more) is left out, and
 * errors() says why.
 */
final class ListRegistry
{
    /** @var array<string, ListDefinition> */
    private array $lists = [];

    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, array<string, mixed>> $stored name => stored definition (ListStore::load()) */
    public function __construct(array $stored, private readonly BlueprintRegistry $blueprints)
    {
        $this->loadStored($stored);
    }

    /**
     * Adds (or replaces) stored definitions, e.g. a list saved in this same run, so it can be
     * used at once (since 0.3.5; the sample content does it).
     *
     * @param array<string, array<string, mixed>> $stored
     */
    public function loadStored(array $stored): void
    {
        foreach ($stored as $name => $definition) {
            unset($this->lists[(string) $name], $this->errors[(string) $name]);
            try {
                $this->lists[(string) $name] = ListDefinition::fromArray((string) $name, $definition, $this->blueprints);
            } catch (\InvalidArgumentException $e) {
                $this->errors[(string) $name] = $e->getMessage();
            }
        }
    }

    public function find(string $name): ?ListDefinition
    {
        return $this->lists[$name] ?? null;
    }

    /** @return array<string, ListDefinition> */
    public function all(): array
    {
        return $this->lists;
    }

    /** @return array<string, string> The stored lists left out, with the reason. */
    public function errors(): array
    {
        return $this->errors;
    }
}
