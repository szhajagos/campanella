<?php

declare(strict_types=1);

namespace Campanella\Database;

use Campanella\Capability\CapabilityRegistry;
use Campanella\Core\Version;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Database\Schema\SchemaBuilder;
use Campanella\Database\Schema\SchemaComparator;
use Campanella\Database\Schema\SchemaDifference;
use Campanella\Database\Schema\SchemaReader;
use Campanella\Database\Schema\Table;

/**
 * Creates the core and capability tables.
 *
 * Can be run repeatedly (CREATE TABLE IF NOT EXISTS), so the table of a
 * newly registered capability is created this way too. differences() compares
 * the definitions with the database; changing an existing table requires a
 * migration.
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

    /** @return list<string> The names of the created (or already existing) tables. */
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

    /**
     * The differences between the table definitions and the database (empty if
     * they match). Every table with the prefix is checked, so a table of a
     * capability that is no longer registered is listed too.
     *
     * @return list<SchemaDifference>
     */
    public function differences(): array
    {
        return (new SchemaComparator(new SchemaReader($this->db), new SchemaBuilder($this->db)))->compare($this->tables());
    }

    /** The full DDL, e.g. for manual installation in phpMyAdmin. */
    public function sql(): string
    {
        $builder = new SchemaBuilder($this->db);

        return implode(";\n\n", array_map($builder->createSql(...), $this->tables())) . ";\n";
    }

    public function isInstalled(): bool
    {
        return $this->db->tableExists(CoreSchema::SYSTEM) && $this->systemValue('schema_version') !== null;
    }

    /** Installed, but the schema is older than the code: install must be run. */
    public function needsUpgrade(): bool
    {
        return $this->isInstalled() && $this->systemValue('schema_version') !== Version::SCHEMA;
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
