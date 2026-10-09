<?php

declare(strict_types=1);

namespace Campanella\Settings;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;

/**
 * The settings edited in the admin (since 0.1.1): name => text value, in the
 * `settings` table. Names are dotted, by area (`site.name`, later `mail.…`).
 *
 * Only the values that were saved are here; what a setting is when it was never
 * saved (the configuration file's value) is decided by the area's own service
 * (e.g. SiteSettings). Read once per instance, on first use. If the table cannot
 * be read (before the upgrade that creates it, or with the database down), every
 * setting is simply missing: the site keeps running on its configuration file.
 */
final class Settings
{
    /** The longest setting name. */
    public const int MAX_NAME_LENGTH = 128;

    /** @var array<string, string>|null */
    private ?array $values = null;

    private bool $available = true;

    public function __construct(private readonly Connection $db)
    {
    }

    /** A saved value, or null if it was never saved. */
    public function get(string $name): ?string
    {
        return $this->load()[$name] ?? null;
    }

    /**
     * The saved values whose names start with the prefix (e.g. `site.`), by full name.
     *
     * @return array<string, string>
     */
    public function all(string $prefix = ''): array
    {
        return array_filter($this->load(), static fn (string $name): bool => str_starts_with($name, $prefix), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Saves several values in one transaction. A null value removes the setting (so it
     * falls back to the configuration file again).
     *
     * @param array<string, ?string> $values
     * @throws \InvalidArgumentException for an invalid name
     */
    public function set(array $values): void
    {
        foreach (array_keys($values) as $name) {
            if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+\z/', $name) !== 1 || strlen($name) > self::MAX_NAME_LENGTH) {
                throw new \InvalidArgumentException("Not a valid setting name: {$name}");
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->transactional(static function (Connection $db) use ($values, $now): void {
            foreach ($values as $name => $value) {
                $db->delete(CoreSchema::SETTINGS, ['name' => $name]);
                if ($value !== null) {
                    $db->insert(CoreSchema::SETTINGS, ['name' => $name, 'value' => $value, 'updated_at' => $now]);
                }
            }
        });
        $this->values = null; // read again on next use
        $this->available = true;
    }

    /** Whether the settings could be read (false before the upgrade that creates the table). */
    public function isAvailable(): bool
    {
        $this->load();

        return $this->available;
    }

    /** Forgets the values read, so the next use reads them again (e.g. after another process saved them). */
    public function reset(): void
    {
        $this->values = null;
    }

    /** @return array<string, string> */
    private function load(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        try {
            $values = [];
            foreach ($this->db->fetchAll('SELECT name, value FROM {settings}') as $row) {
                $values[(string) $row['name']] = (string) ($row['value'] ?? '');
            }
            $this->available = true;
        } catch (\PDOException) {
            $values = [];
            $this->available = false;
        }

        return $this->values = $values;
    }
}
