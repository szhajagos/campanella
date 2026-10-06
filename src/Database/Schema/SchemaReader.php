<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

use Campanella\Database\Connection;

/**
 * Reads the actual tables of the database from `information_schema`: columns,
 * indexes, foreign keys. The same on MariaDB 10.6+ and MySQL 8.0+; their
 * differences (e.g. `int(11)` / `int`, a quoted / unquoted default, JSON as
 * `longtext` on MariaDB) are evened out here.
 *
 * Only the tables with the connection's prefix are seen; their names are
 * returned without the prefix.
 */
final class SchemaReader
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<string> The tables with the prefix (without it), sorted. */
    public function tableNames(): array
    {
        $prefix = $this->db->prefix();
        $names = $this->db->fetchColumn(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = :type AND TABLE_NAME LIKE :prefix',
            ['type' => 'BASE TABLE', 'prefix' => addcslashes($prefix, '%_\\') . '%'],
        );
        $names = array_map(static fn (mixed $n): string => substr((string) $n, strlen($prefix)), $names);
        sort($names);

        return $names;
    }

    public function tableExists(string $table): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name',
            ['name' => $this->db->prefix() . $table],
        ) !== null;
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name AND COLUMN_NAME = :column',
            ['name' => $this->db->prefix() . $table, 'column' => $column],
        ) !== null;
    }

    public function indexExists(string $table, string $index): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name AND INDEX_NAME = :idx',
            ['name' => $this->db->prefix() . $table, 'idx' => $index],
        ) !== null;
    }

    /** The table as it is, or null if it does not exist. */
    public function read(string $table): ?TableInfo
    {
        $name = $this->db->prefix() . $table;
        $rows = $this->db->fetchAll(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable,
                    COLUMN_DEFAULT AS dflt, EXTRA AS extra, CHARACTER_MAXIMUM_LENGTH AS max_length
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name
             ORDER BY ORDINAL_POSITION',
            ['name' => $name],
        );
        if ($rows === []) {
            return null;
        }
        $columns = [];
        foreach ($rows as $row) {
            $column = self::column($row);
            $columns[$column->name] = $column;
        }

        $primaryKey = [];
        $indexes = [];
        $uniques = [];
        $statistics = $this->db->fetchAll(
            'SELECT INDEX_NAME AS idx, COLUMN_NAME AS col, NON_UNIQUE AS non_unique
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['name' => $name],
        );
        foreach ($statistics as $row) {
            $index = (string) $row['idx'];
            $column = (string) $row['col'];
            match (true) {
                $index === 'PRIMARY' => $primaryKey[] = $column,
                (int) $row['non_unique'] === 0 => $uniques[$index][] = $column,
                default => $indexes[$index][] = $column,
            };
        }

        $foreignKeys = [];
        $references = $this->db->fetchAll(
            'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_table,
                    k.REFERENCED_COLUMN_NAME AS ref_col, r.DELETE_RULE AS delete_rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = :name AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            ['name' => $name],
        );
        $prefix = $this->db->prefix();
        foreach ($references as $row) {
            $referenced = (string) $row['ref_table'];
            $foreignKeys[(string) $row['name']] = [
                'column' => (string) $row['col'],
                'table' => str_starts_with($referenced, $prefix) ? substr($referenced, strlen($prefix)) : $referenced,
                'referencedColumn' => (string) $row['ref_col'],
                'cascadeDelete' => strtoupper((string) $row['delete_rule']) === 'CASCADE',
            ];
        }

        return new TableInfo($table, $columns, $primaryKey, $indexes, $uniques, $foreignKeys);
    }

    /** @param array<string, mixed> $row */
    private static function column(array $row): ColumnInfo
    {
        $dataType = strtolower((string) $row['data_type']);
        $columnType = strtolower((string) $row['column_type']);
        $unsigned = str_contains($columnType, 'unsigned');
        $length = $row['max_length'] === null ? null : (int) $row['max_length'];

        $type = match (true) {
            $dataType === 'bigint' && $unsigned => ColumnType::Id,
            $dataType === 'int' && !$unsigned => ColumnType::Integer,
            // MySQL 8.0.19+ drops the display width, except for tinyint(1).
            $dataType === 'tinyint' && str_starts_with($columnType, 'tinyint(1)') => ColumnType::Boolean,
            $dataType === 'varchar' => ColumnType::String,
            $dataType === 'mediumtext' => ColumnType::Text,
            $dataType === 'datetime' => ColumnType::DateTime,
            $dataType === 'char' && $length === 36 => ColumnType::Uuid,
            // MariaDB stores JSON as longtext (with a JSON_VALID check).
            $dataType === 'json', $dataType === 'longtext' => ColumnType::Json,
            default => null,
        };

        return new ColumnInfo(
            name: (string) $row['name'],
            type: $type,
            rawType: $columnType,
            nullable: strtoupper((string) $row['nullable']) === 'YES',
            default: self::normalizeDefault($row['dflt']),
            autoIncrement: str_contains(strtolower((string) $row['extra']), 'auto_increment'),
            length: in_array($type, [ColumnType::String, ColumnType::Uuid], true) ? $length : null,
        );
    }

    /**
     * MySQL reports a default as it is (`active`, `0`, NULL); MariaDB 10.2.7+ as an
     * expression (`'active'`, `0`, the text `NULL`). Both become `active`, `0`, null.
     */
    public static function normalizeDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        $default = (string) $default;
        if ($default === 'NULL') {
            return null;
        }
        if (strlen($default) >= 2 && $default[0] === "'" && str_ends_with($default, "'")) {
            return str_replace("''", "'", substr($default, 1, -1));
        }

        return $default;
    }
}
