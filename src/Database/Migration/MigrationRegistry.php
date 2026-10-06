<?php

declare(strict_types=1);

namespace Campanella\Database\Migration;

/**
 * The known migrations, in the order they run: Campanella's own (CoreMigrations)
 * first, then those of the `migrations` setting (class names).
 */
final class MigrationRegistry
{
    /** `<source>:<name>`: lowercase letters, digits, `_`, `-` and `.`. */
    public const string ID_PATTERN = '/^[a-z][a-z0-9_-]*:[a-z0-9][a-z0-9_.-]*$/';

    /** @var array<string, Migration> */
    private array $migrations = [];

    /** @param iterable<Migration> $migrations */
    public function __construct(iterable $migrations = [])
    {
        foreach ($migrations as $migration) {
            $this->add($migration);
        }
    }

    /**
     * @param list<mixed> $classes Class names (from the `migrations` setting, so anything may be in it)
     */
    public static function fromClasses(array $classes): self
    {
        $migrations = [];
        foreach ($classes as $class) {
            $migration = is_string($class) && class_exists($class) ? new $class() : null;
            if (!$migration instanceof Migration) {
                throw new \LogicException('migrations may only contain Migration classes: ' . var_export($class, true));
            }
            $migrations[] = $migration;
        }

        return new self($migrations);
    }

    public function add(Migration $migration): void
    {
        $id = $migration->id();
        if (strlen($id) > 128 || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException("Invalid migration ID: {$id} (expected e.g. core:0006_roles_multi_value).");
        }
        if (isset($this->migrations[$id])) {
            throw new \InvalidArgumentException("Duplicate migration ID: {$id}.");
        }
        $this->migrations[$id] = $migration;
    }

    /** @return list<Migration> In the order they run */
    public function all(): array
    {
        return array_values($this->migrations);
    }

    public function isEmpty(): bool
    {
        return $this->migrations === [];
    }
}
