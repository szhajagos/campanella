<?php

declare(strict_types=1);

namespace Campanella\Lists;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * The lists made in the admin (since 0.2.5): their definitions, stored as JSON in
 * the `lists` table (see ListDefinition for the format). Loaded, never queried.
 */
final class ListStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Every stored definition, by name, in name order; none before the table exists.
     *
     * @return array<string, array<string, mixed>>
     */
    public function load(): array
    {
        try {
            $rows = $this->db->fetchAll('SELECT name, definition FROM {lists} ORDER BY name');
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                return [];
            }
            throw $e;
        }
        $definitions = [];
        foreach ($rows as $row) {
            $definition = json_decode((string) $row['definition'], true);
            $definitions[(string) $row['name']] = is_array($definition) ? $definition : [];
        }

        return $definitions;
    }

    /** @return array<string, mixed>|null */
    public function find(string $name): ?array
    {
        return $this->load()[$name] ?? null;
    }

    /**
     * Creates or replaces a definition.
     *
     * @param array<string, mixed> $definition
     */
    public function save(string $name, array $definition): void
    {
        $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(FieldType::STORAGE_DATE_FORMAT);
        $this->db->transactional(function (Connection $db) use ($name, $json, $now): void {
            if ($db->fetchValue('SELECT 1 FROM {lists} WHERE name = :name', ['name' => $name]) === null) {
                $db->insert(CoreSchema::LISTS, ['name' => $name, 'definition' => $json, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->update(CoreSchema::LISTS, ['definition' => $json, 'updated_at' => $now], ['name' => $name]);
            }
        });
    }

    public function delete(string $name): void
    {
        $this->db->delete(CoreSchema::LISTS, ['name' => $name]);
    }
}
