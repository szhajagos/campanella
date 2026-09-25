<?php

declare(strict_types=1);

namespace Campanella\Database;

use Campanella\Capability\CapabilityRegistry;
use Campanella\Core\Version;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Database\Schema\SchemaBuilder;
use Campanella\Database\Schema\Table;

/**
 * Létrehozza a mag és a capability-k tábláit.
 *
 * Ismételten futtatható (CREATE TABLE IF NOT EXISTS), így egy újonnan
 * regisztrált capability táblája is ezzel jön létre. Meglévő tábla
 * módosítása migrációt igényel, ez egy későbbi verzió feladata.
 */
final class Installer
{
    public function __construct(
        private readonly Connection $db,
        private readonly CapabilityRegistry $capabilities,
    ) {
    }

    /** @return list<Table> */
    public function tables(): array
    {
        $tables = CoreSchema::tables();
        foreach ($this->capabilities->all() as $definition) {
            $table = $definition->table();
            if ($table !== null) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /** @return list<string> A létrehozott (vagy már meglévő) táblák nevei. */
    public function install(): array
    {
        $builder = new SchemaBuilder($this->db);
        $names = [];
        foreach ($this->tables() as $table) {
            $builder->create($table);
            $names[] = $this->db->prefix() . $table->name;
        }

        $this->setSystemValue('schema_version', Version::SCHEMA);
        if ($this->systemValue('installed_at') === null) {
            $this->setSystemValue('installed_at', gmdate('Y-m-d H:i:s'));
        }

        return $names;
    }

    /** A teljes DDL, pl. kézi telepítéshez phpMyAdminban. */
    public function sql(): string
    {
        $builder = new SchemaBuilder($this->db);

        return implode(";\n\n", array_map($builder->createSql(...), $this->tables())) . ";\n";
    }

    public function isInstalled(): bool
    {
        return $this->db->tableExists(CoreSchema::SYSTEM) && $this->systemValue('schema_version') !== null;
    }

    public function systemValue(string $name): ?string
    {
        $value = $this->db->fetchValue('SELECT value FROM {system} WHERE name = :name', ['name' => $name]);

        return $value === null ? null : (string) $value;
    }

    private function setSystemValue(string $name, string $value): void
    {
        $this->db->transactional(function (Connection $db) use ($name, $value): void {
            $db->delete(CoreSchema::SYSTEM, ['name' => $name]);
            $db->insert(CoreSchema::SYSTEM, ['name' => $name, 'value' => $value]);
        });
    }
}
