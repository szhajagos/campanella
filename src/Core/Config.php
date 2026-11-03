<?php

declare(strict_types=1);

namespace Campanella\Core;

/**
 * Configuration from plain PHP arrays (config/*.php). No YAML parser;
 * opcache speeds up the configuration files too.
 *
 * config/local.php (not under version control) overrides the values of
 * app.php; the database credentials and machine-specific settings go there.
 * The two are merged key by key, at any depth; a list (e.g. `admin.roles`,
 * `files.types`) given in local.php replaces app.php's whole (since 0.4.5;
 * before, only its first items were replaced, and the rest stayed).
 */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $configDir): self
    {
        $values = require $configDir . '/app.php';
        $local = $configDir . '/local.php';
        if (is_file($local)) {
            $values = self::merge($values, require $local);
        }

        return new self($values);
    }

    /**
     * app.php's values with local.php's over them (since 0.4.5): key by key, at any depth; a
     * list, or a value that is not an array, replaces the one before whole.
     *
     * @param array<mixed> $base
     * @param array<mixed> $over
     * @return array<mixed>
     */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null) && !array_is_list($base[$key])
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /** Dotted key: get('database.host'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
