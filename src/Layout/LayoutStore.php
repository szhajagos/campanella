<?php

declare(strict_types=1);

namespace Campanella\Layout;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * The layouts made in the admin (since 0.4.10): their definitions, stored as JSON in the
 * `layouts` table, in the form of a layout's file of rows (LayoutDefinition::toArray()).
 * Loaded, never queried; LayoutRegistry reads them after the files' layouts.
 */
final class LayoutStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Every stored definition, by name, in name order, as JSON; none before the table exists.
     *
     * @return array<string, string>
     */
    public function load(): array
    {
        try {
            $rows = $this->db->fetchAll('SELECT name, definition FROM {layouts} ORDER BY name');
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                return []; // before the upgrade to 0.4.10
            }
            throw $e;
        }
        $definitions = [];
        foreach ($rows as $row) {
            $definitions[(string) $row['name']] = (string) $row['definition'];
        }

        return $definitions;
    }

    /**
     * Creates or replaces a layout: one of rows, checked by the caller (LayoutDefinition::fromJson()).
     *
     * @throws \InvalidArgumentException if it is not a layout of rows
     */
    public function save(LayoutDefinition $layout): void
    {
        if (!$layout->hasRows()) {
            throw new \InvalidArgumentException("Layout {$layout->name}: only a layout of rows is stored.");
        }
        $json = json_encode($layout->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(FieldType::STORAGE_DATE_FORMAT);
        $this->db->transactional(function (Connection $db) use ($layout, $json, $now): void {
            if ($db->fetchValue('SELECT 1 FROM {layouts} WHERE name = :name', ['name' => $layout->name]) === null) {
                $db->insert(CoreSchema::LAYOUTS, ['name' => $layout->name, 'definition' => $json, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->update(CoreSchema::LAYOUTS, ['definition' => $json, 'updated_at' => $now], ['name' => $layout->name]);
            }
        });
    }

    public function delete(string $name): void
    {
        $this->db->delete(CoreSchema::LAYOUTS, ['name' => $name]);
    }
}
