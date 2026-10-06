<?php

declare(strict_types=1);

namespace Campanella\Database;

use Campanella\Core\Version;
use Campanella\Database\Schema\SchemaReader;
use Closure;

/**
 * A backup of Campanella's tables (those with the table prefix) as an SQL file,
 * written through PDO: no `mysqldump` is needed, so it works on any web host.
 *
 * The file restores the tables as they were (DROP TABLE + CREATE TABLE +
 * INSERT), e.g. with phpMyAdmin's import or `mysql campanella < backup.sql`.
 * It is compressed (`.sql.gz`) if the zlib extension is available. The last
 * line is `-- Campanella backup complete`: a file without it was cut off.
 *
 * The files contain everything, password hashes too: they are written outside
 * the web root (`var/backups/`, with a `.htaccess` denying access in case the
 * project root is served) and readable by their owner only.
 */
final class DatabaseBackup
{
    /** Rows per INSERT statement, and per query when reading. */
    public const int BATCH = 500;

    public const string COMPLETE = '-- Campanella backup complete';

    public function __construct(
        private readonly Connection $db,
        private readonly string $directory,
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Writes a new backup file and returns its path.
     *
     * @param (Closure(string, int): void)|null $progress Called with each table and its row count
     * @throws \RuntimeException if the folder or the file cannot be written
     */
    public function create(bool $compress = true, ?Closure $progress = null): string
    {
        $this->prepareDirectory();
        $compress = $compress && function_exists('gzopen');
        $name = sprintf('campanella-%s-%s.sql%s', gmdate('Ymd-His'), bin2hex(random_bytes(3)), $compress ? '.gz' : '');
        $path = $this->directory . '/' . $name;
        $temp = $path . '.part';

        $handle = $compress ? @gzopen($temp, 'wb6') : @fopen($temp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot write the backup file: {$temp}");
        }
        @chmod($temp, 0600);
        $write = $compress
            ? static function (string $text) use ($handle): void { gzwrite($handle, $text); }
            : static function (string $text) use ($handle): void { fwrite($handle, $text); };
        try {
            $this->write($write, $progress);
        } finally {
            $compress ? gzclose($handle) : fclose($handle);
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException("Cannot write the backup file: {$path}");
        }

        return $path;
    }

    /**
     * Writes the SQL of the backup through a function (create() writes it into a file).
     *
     * @param Closure(string): void $write
     * @param (Closure(string, int): void)|null $progress
     */
    public function write(Closure $write, ?Closure $progress = null): void
    {
        $reader = new SchemaReader($this->db);
        $pdo = $this->db->pdo();

        $write(sprintf(
            "-- Campanella backup\n-- Campanella %s, schema %s, %s\n-- Created %s UTC; tables with the prefix \"%s\"\n"
            . "-- Restore: import this file into the database (e.g. phpMyAdmin > Import, or mysql <database> < file.sql)\n\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n",
            Version::CAMPANELLA,
            Version::SCHEMA,
            $this->db->serverVersion(),
            gmdate('Y-m-d H:i:s'),
            $this->db->prefix(),
        ));

        foreach ($reader->tableNames() as $table) {
            $quoted = $this->db->table($table);
            $create = $this->db->fetchOne('SHOW CREATE TABLE ' . $quoted);
            $createSql = $create === null ? '' : (string) array_values($create)[1];
            $write("DROP TABLE IF EXISTS {$quoted};\n{$createSql};\n");

            $info = $reader->read($table);
            $order = $info !== null && $info->primaryKey !== []
                ? ' ORDER BY ' . implode(', ', array_map(Connection::quoteIdentifier(...), $info->primaryKey))
                : '';
            $rows = 0;
            for ($offset = 0; ; $offset += self::BATCH) {
                $batch = $this->db->fetchAll(sprintf('SELECT * FROM %s%s LIMIT %d OFFSET %d', $quoted, $order, self::BATCH, $offset));
                if ($batch === []) {
                    break;
                }
                $columns = implode(', ', array_map(Connection::quoteIdentifier(...), array_keys($batch[0])));
                $values = [];
                foreach ($batch as $row) {
                    $values[] = '(' . implode(', ', array_map(
                        static fn (mixed $v): string => match (true) {
                            $v === null => 'NULL',
                            is_int($v), is_float($v) => (string) $v,
                            is_bool($v) => $v ? '1' : '0',
                            default => (string) $pdo->quote((string) $v),
                        },
                        $row,
                    )) . ')';
                }
                $write("INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n");
                $rows += count($batch);
                if (count($batch) < self::BATCH) {
                    break;
                }
            }
            $write("\n");
            if ($progress !== null) {
                $progress($table, $rows);
            }
        }

        $write("SET FOREIGN_KEY_CHECKS = 1;\n" . self::COMPLETE . "\n");
    }

    /**
     * The backup files, newest first.
     *
     * @return list<array{name: string, path: string, size: int, time: int}>
     */
    public function files(): array
    {
        $files = [];
        foreach (glob($this->directory . '/campanella-*.sql*') ?: [] as $path) {
            if (str_ends_with($path, '.part') || !is_file($path)) {
                continue;
            }
            $files[] = ['name' => basename($path), 'path' => $path, 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
        }
        usort($files, static fn (array $a, array $b): int => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);

        return $files;
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Cannot create the backup folder: {$this->directory}");
        }
        $htaccess = $this->directory . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Backups are never served.\nRequire all denied\n");
        }
    }
}
