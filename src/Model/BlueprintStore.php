<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * The Blueprints made in the admin (since 0.2.3): their definitions, stored as
 * JSON in the `blueprints` table (see BlueprintDefinition for the format). The
 * JSON is loaded, never queried. The BlueprintRegistry defines them after those
 * of config/blueprints.php.
 */
final class BlueprintStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Every stored definition, by name, in name order. Before the table exists (not
     * installed, or not upgraded to 0.2.3 yet): none.
     *
     * @return array<string, array<string, mixed>>
     */
    public function load(): array
    {
        try {
            $rows = $this->db->fetchAll('SELECT name, definition FROM {blueprints} ORDER BY name');
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
            if ($db->fetchValue('SELECT 1 FROM {blueprints} WHERE name = :name', ['name' => $name]) === null) {
                $db->insert(CoreSchema::BLUEPRINTS, ['name' => $name, 'definition' => $json, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->update(CoreSchema::BLUEPRINTS, ['definition' => $json, 'updated_at' => $now], ['name' => $name]);
            }
        });
    }

    public function delete(string $name): void
    {
        $this->db->delete(CoreSchema::BLUEPRINTS, ['name' => $name]);
    }
}
