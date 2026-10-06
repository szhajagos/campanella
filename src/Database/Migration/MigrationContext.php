<?php

declare(strict_types=1);

namespace Campanella\Database\Migration;

use Campanella\Database\Connection;
use Campanella\Database\Schema\Column;
use Campanella\Database\Schema\SchemaBuilder;
use Campanella\Database\Schema\SchemaReader;
use Campanella\Database\Schema\Table;
use Closure;

/**
 * What a migration works with: SQL on the database, and schema helpers that
 * check the state first, so a migration that failed halfway can run again
 * (adding an existing column or dropping a missing one does nothing). Table
 * names are without the prefix (`cap_authenticatable`); in SQL `{name}`, as
 * with the Connection.
 */
final class MigrationContext
{
    private readonly SchemaReader $reader;
    private readonly SchemaBuilder $builder;

    /** @param (Closure(string): void)|null $log */
    public function __construct(
        private readonly Connection $db,
        private readonly ?Closure $log = null,
    ) {
        $this->reader = new SchemaReader($db);
        $this->builder = new SchemaBuilder($db);
    }

    public function db(): Connection
    {
        return $this->db;
    }

    /**
     * Runs a statement (`{table}` placeholders, named parameters).
     *
     * @param array<string, mixed> $params
     * @return int The number of affected rows
     */
    public function sql(string $sql, array $params = []): int
    {
        return $this->db->execute($sql, $params);
    }

    /** A line for the person running the migration (e.g. how many rows were moved). */
    public function log(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }

    public function tableExists(string $table): bool
    {
        return $this->reader->tableExists($table);
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->reader->columnExists($table, $column);
    }

    public function indexExists(string $table, string $index): bool
    {
        return $this->reader->indexExists($table, $index);
    }

    /** Creates the table if it does not exist. */
    public function createTable(Table $table): void
    {
        $this->builder->create($table);
    }

    /**
     * Adds a column if the table does not have it yet.
     *
     * @param string|null $after The column it follows; null: at the end
     */
    public function addColumn(string $table, Column $column, ?string $after = null): bool
    {
        if ($this->reader->columnExists($table, $column->name)) {
            return false;
        }
        $this->db->execute($this->builder->addColumnDefinitionSql($table, $column, $after));

        return true;
    }

    /** Drops a column if the table has it. Its data is lost. */
    public function dropColumn(string $table, string $column): bool
    {
        if (!$this->reader->columnExists($table, $column)) {
            return false;
        }
        $this->db->execute($this->builder->dropColumnSql($table, $column));

        return true;
    }

    /**
     * Renames a column (keeping its type and data), unless it is renamed already.
     */
    public function renameColumn(string $table, string $from, string $to): bool
    {
        if (!$this->reader->columnExists($table, $from) || $this->reader->columnExists($table, $to)) {
            return false;
        }
        $this->db->execute(sprintf(
            'ALTER TABLE %s RENAME COLUMN %s TO %s',
            $this->db->table($table),
            Connection::quoteIdentifier($from),
            Connection::quoteIdentifier($to),
        ));

        return true;
    }

    /**
     * Adds an index if the table does not have one with this name.
     *
     * @param list<string> $columns
     */
    public function addIndex(string $table, string $index, array $columns, bool $unique = false): bool
    {
        if ($this->reader->indexExists($table, $index)) {
            return false;
        }
        $this->db->execute(sprintf(
            'ALTER TABLE %s ADD %s %s (%s)',
            $this->db->table($table),
            $unique ? 'UNIQUE INDEX' : 'INDEX',
            Connection::quoteIdentifier($index),
            implode(', ', array_map(Connection::quoteIdentifier(...), $columns)),
        ));

        return true;
    }

    public function dropIndex(string $table, string $index): bool
    {
        if (!$this->reader->indexExists($table, $index)) {
            return false;
        }
        $this->db->execute($this->builder->dropIndexSql($table, $index));

        return true;
    }

    /**
     * Goes through a large table in batches, ordered by an integer key (e.g.
     * `object_id`), so memory use does not grow with the table.
     *
     * @param Closure(array<string, mixed>): void $process Called with each row
     * @param string $where An extra condition (SQL), e.g. `roles IS NOT NULL`
     * @param array<string, mixed> $params Its parameters
     * @return int The number of rows processed
     */
    public function eachRow(string $table, string $key, Closure $process, string $where = '', array $params = [], int $batchSize = 500): int
    {
        $count = 0;
        $last = null;
        $k = Connection::quoteIdentifier($key);
        do {
            $conditions = array_filter([$where !== '' ? '(' . $where . ')' : '', $last !== null ? "{$k} > :__last" : '']);
            $rows = $this->db->fetchAll(
                sprintf(
                    'SELECT * FROM %s%s ORDER BY %s LIMIT %d',
                    $this->db->table($table),
                    $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
                    $k,
                    max(1, $batchSize),
                ),
                $params + ($last !== null ? ['__last' => $last] : []),
            );
            foreach ($rows as $row) {
                $process($row);
                $last = $row[$key];
                $count++;
            }
        } while (count($rows) === max(1, $batchSize));

        return $count;
    }
}
