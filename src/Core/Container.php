<?php

declare(strict_types=1);

namespace Campanella\Core;

use Closure;

/**
 * Minimális szolgáltatás-konténer. A szolgáltatásokat explicit gyártó
 * függvények hozzák létre (lásd Kernel::services()), első kéréskor, egyszer.
 * Nincs „mágikus” autowiring: a függőségek a kódban látszanak.
 */
final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @param Closure(self): mixed $factory */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $service): void
    {
        $this->instances[$id] = $service;
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        $factory = $this->factories[$id]
            ?? throw new \OutOfBoundsException("Ismeretlen szolgáltatás: {$id}");

        return $this->instances[$id] = $factory($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}
