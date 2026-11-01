<?php

declare(strict_types=1);

namespace Campanella\Appearance;

use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * The colour schemes made in the admin (since 0.4.2): their definitions, stored as JSON in
 * the `color_schemes` table, in the form of a scheme's file (ColorScheme). Loaded, never
 * queried.
 */
final class ColorSchemeStore
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
            $rows = $this->db->fetchAll('SELECT name, definition FROM {color_schemes} ORDER BY name');
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                return []; // before the upgrade to 0.4.2
            }
            throw $e;
        }
        $definitions = [];
        foreach ($rows as $row) {
            $definitions[(string) $row['name']] = (string) $row['definition'];
        }

        return $definitions;
    }

    /** Creates or replaces a scheme (checked by the caller, ColorScheme::fromJson()). */
    public function save(ColorScheme $scheme): void
    {
        $json = json_encode(['version' => ColorScheme::VERSION, 'label' => $scheme->label, 'light' => $scheme->light, 'dark' => $scheme->dark], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(FieldType::STORAGE_DATE_FORMAT);
        $this->db->transactional(function (Connection $db) use ($scheme, $json, $now): void {
            if ($db->fetchValue('SELECT 1 FROM {color_schemes} WHERE name = :name', ['name' => $scheme->name]) === null) {
                $db->insert(CoreSchema::COLOR_SCHEMES, ['name' => $scheme->name, 'definition' => $json, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->update(CoreSchema::COLOR_SCHEMES, ['definition' => $json, 'updated_at' => $now], ['name' => $scheme->name]);
            }
        });
    }

    public function delete(string $name): void
    {
        $this->db->delete(CoreSchema::COLOR_SCHEMES, ['name' => $name]);
    }
}
