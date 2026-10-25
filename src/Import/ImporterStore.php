<?php

declare(strict_types=1);

namespace Campanella\Import;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * The importers (since 0.3.1): their definitions, stored as JSON in the `importers`
 * table (see ImporterDefinition for the format). Loaded, never queried.
 */
final class ImporterStore
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
            $rows = $this->db->fetchAll('SELECT name, definition FROM {importers} ORDER BY name');
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
            if ($db->fetchValue('SELECT 1 FROM {importers} WHERE name = :name', ['name' => $name]) === null) {
                $db->insert(CoreSchema::IMPORTERS, ['name' => $name, 'definition' => $json, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->update(CoreSchema::IMPORTERS, ['definition' => $json, 'updated_at' => $now], ['name' => $name]);
            }
        });
    }

    /** Deletes the definition and its runs' log; the objects it made stay (and lose their keys). */
    public function delete(string $name): void
    {
        $this->db->transactional(function (Connection $db) use ($name): void {
            $db->delete(CoreSchema::IMPORT_ITEMS, ['importer' => $name]);
            $db->delete(CoreSchema::IMPORT_RUNS, ['importer' => $name]);
            $db->delete(CoreSchema::IMPORTERS, ['name' => $name]);
        });
    }
}
