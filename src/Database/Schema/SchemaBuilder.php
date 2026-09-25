<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

use Campanella\Database\Connection;

/**
 * A Table-leírásokból DDL-t készít. Ez az egyetlen hely, ahol
 * CREATE TABLE utasítás keletkezik.
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
            $line = $q($column->name) . ' ' . $column->type->sql($column->length);
            $line .= $column->nullable ? ' NULL' : ' NOT NULL';
            if ($column->default !== null) {
                $line .= ' DEFAULT ' . (is_int($column->default)
                    ? (string) $column->default
                    : $this->db->pdo()->quote($column->default));
            }
            if ($column->autoIncrement) {
                $line .= ' AUTO_INCREMENT';
            }
            $lines[] = $line;
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
                $q($this->db->prefix() . 'fk_' . $table->name . '_' . $fk->column),
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
}
