<?php

declare(strict_types=1);

namespace Campanella\Database;

use Campanella\Capability\CapabilityRegistry;
use Campanella\Core\Version;
use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\Migrator;
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
        private readonly ?Migrator $migrator = null,
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

    /**
     * Creates the missing tables. On a fresh installation every known migration
     * is recorded as applied (the tables are created as the definitions are now);
     * on an existing one the pending migrations are left for the Migrator.
     *
     * @return list<string> The names of the created (or already existing) tables.
     */
    public function install(): array
    {
        $this->assertUpgradable();
        $fresh = !$this->isInstalled();
        $builder = new SchemaBuilder($this->db);
        $names = [];
        foreach ($this->tables() as $table) {
            $builder->create($table);
            $names[] = $this->db->prefix() . $table->name;
        }

        if ($fresh) {
            $this->migrator?->markAllApplied();
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

    /**
     * Installed, but older than the code: the schema version differs (install must
     * be run), or a migration is pending (migrate must be run).
     */
    public function needsUpgrade(): bool
    {
        // Checked on every request (Kernel), so without isInstalled()'s extra query.
        try {
            $version = $this->systemValue('schema_version');
        } catch (\PDOException) {
            return false; // not installed (or the database is unreachable): handled elsewhere
        }

        return $version !== null && ($version !== Version::SCHEMA || $this->pendingMigrations() !== []);
    }

    /**
     * The installed schema version if it is too old to be upgraded by this version
     * (older than Version::MIN_UPGRADE_SCHEMA); null otherwise (or not installed).
     * Since 0.1.0.
     */
    public function tooOld(): ?string
    {
        try {
            $version = $this->systemValue('schema_version');
        } catch (\PDOException) {
            return null;
        }

        return $version !== null && (int) $version < (int) Version::MIN_UPGRADE_SCHEMA ? $version : null;
    }

    /** @throws UnsupportedUpgradeException for an installation older than 0.0.6 */
    public function assertUpgradable(): void
    {
        $old = $this->tooOld();
        if ($old !== null) {
            throw new UnsupportedUpgradeException($old);
        }
    }

    /** @return list<Migration> The migrations that have not run yet (none without a Migrator) */
    public function pendingMigrations(): array
    {
        return $this->migrator?->pending() ?? [];
    }

    /** The number of users (objects with Authenticatable); the installer is open only while there is none. Since 0.1.0. */
    public function userCount(): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM {object_capabilities} WHERE capability = 'authenticatable'");
    }

    public function migrator(): ?Migrator
    {
        return $this->migrator;
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
