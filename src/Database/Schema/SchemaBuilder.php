<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

use Campanella\Database\Connection;

/**
 * Builds DDL from Table descriptions. This is the only place where
 * CREATE TABLE and ALTER TABLE statements are generated.
 *
 * The ALTER statements are the plain forms that MariaDB and MySQL both
 * accept (`ADD COLUMN IF NOT EXISTS` and `DROP INDEX IF EXISTS` exist only on
 * MariaDB): check the state first (SchemaReader), then run them.
 */
final class SchemaBuilder
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function createSql(Table $table): string
    {
        $q = Connection::quoteIdentifier(...);
        $lines = [];

        foreach ($table->columns as $column) {
            $lines[] = $this->columnSql($column);
        }

        $lines[] = 'PRIMARY KEY (' . implode(', ', array_map($q, $table->primaryKey)) . ')';

        foreach ($table->uniques as $name => $columns) {
            $lines[] = 'UNIQUE KEY ' . $q($name) . ' (' . implode(', ', array_map($q, $columns)) . ')';
        }
        foreach ($table->indexes as $name => $columns) {
            $lines[] = 'KEY ' . $q($name) . ' (' . implode(', ', array_map($q, $columns)) . ')';
        }
        foreach ($table->foreignKeys as $fk) {
            $lines[] = sprintf(
                'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)%s',
                $q($this->foreignKeyName($table, $fk)),
                $q($fk->column),
                $this->db->table($fk->referencedTable),
                $q($fk->referencedColumn),
                $fk->cascadeDelete ? ' ON DELETE CASCADE' : '',
            );
        }

        return sprintf(
            "CREATE TABLE IF NOT EXISTS %s (\n  %s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            $this->db->table($table->name),
            implode(",\n  ", $lines),
        );
    }

    public function create(Table $table): void
    {
        $this->db->execute($this->createSql($table));
    }

    /** A column's definition, as in CREATE TABLE: `` `weight` INT NOT NULL DEFAULT 0 ``. */
    public function columnSql(Column $column): string
    {
        $line = Connection::quoteIdentifier($column->name) . ' ' . $column->type->sql($column->length);
        $line .= $column->nullable ? ' NULL' : ' NOT NULL';
        if ($column->default !== null) {
            $line .= ' DEFAULT ' . (is_int($column->default)
                ? (string) $column->default
                : $this->db->pdo()->quote($column->default));
        }
        if ($column->autoIncrement) {
            $line .= ' AUTO_INCREMENT';
        }

        return $line;
    }

    /**
     * Adds a column of the table's definition, after the column it follows there
     * (so the order matches a fresh installation).
     */
    public function addColumnSql(Table $table, string $column): string
    {
        $after = null;
        $definition = null;
        foreach ($table->columns as $candidate) {
            if ($candidate->name === $column) {
                $definition = $candidate;
                break;
            }
            $after = $candidate->name;
        }
        if ($definition === null) {
            throw new \InvalidArgumentException("No column {$column} in the definition of {$table->name}.");
        }

        return $this->addColumnDefinitionSql($table->name, $definition, $after, first: $after === null);
    }

    /**
     * Adds the given column (e.g. in a migration, which has its own definitions):
     * after `$after`, first (`$first`), or at the end.
     */
    public function addColumnDefinitionSql(string $table, Column $column, ?string $after = null, bool $first = false): string
    {
        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s%s',
            $this->db->table($table),
            $this->columnSql($column),
            match (true) {
                $after !== null => ' AFTER ' . Connection::quoteIdentifier($after),
                $first => ' FIRST',
                default => '',
            },
        );
    }

    /** Adds an index (or unique index) of the table's definition. */
    public function addIndexSql(Table $table, string $index): string
    {
        $unique = isset($table->uniques[$index]);
        $columns = $table->uniques[$index] ?? $table->indexes[$index]
            ?? throw new \InvalidArgumentException("No index {$index} in the definition of {$table->name}.");

        return sprintf(
            'ALTER TABLE %s ADD %s %s (%s)',
            $this->db->table($table->name),
            $unique ? 'UNIQUE INDEX' : 'INDEX',
            Connection::quoteIdentifier($index),
            implode(', ', array_map(Connection::quoteIdentifier(...), $columns)),
        );
    }

    /** Drops a column (its data is lost). */
    public function dropColumnSql(string $table, string $column): string
    {
        return sprintf('ALTER TABLE %s DROP COLUMN %s', $this->db->table($table), Connection::quoteIdentifier($column));
    }

    public function dropIndexSql(string $table, string $index): string
    {
        return sprintf('ALTER TABLE %s DROP INDEX %s', $this->db->table($table), Connection::quoteIdentifier($index));
    }

    /** The name of a foreign key constraint, as createSql() gives it. */
    public function foreignKeyName(Table $table, ForeignKey $foreignKey): string
    {
        return $this->db->prefix() . 'fk_' . $table->name . '_' . $foreignKey->column;
    }
}
