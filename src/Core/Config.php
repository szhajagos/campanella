<?php

declare(strict_types=1);

namespace Campanella\Core;

/**
 * Konfiguráció sima PHP-tömbökből (config/*.php). Nincs YAML-parser;
 * az opcache a konfigurációs fájlokat is gyorsítja.
 *
 * A config/local.php (nincs verziókezelve) felülírja az app.php értékeit,
 * ide kerülnek az adatbázis-hozzáférés és a gépfüggő beállítások.
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
            $values = array_replace_recursive($values, require $local);
        }

        return new self($values);
    }

    /** Pontozott kulcs: get('database.host'). */
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
