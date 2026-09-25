<?php

declare(strict_types=1);

namespace Campanella\Database;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Vékony réteg a PDO fölött.
 *
 * Minden SQL vagy ezen az osztályon, vagy a QueryCompileren keresztül
 * fut, más osztály nem nyúl közvetlenül a PDO-hoz. A táblanevek
 * `{objects}` alakban szerepelnek az SQL-ben, a Connection ezeket
 * cseréli le a prefixszel ellátott, idézőjelezett névre.
 *
 * Cél: MariaDB 10.6+ és MySQL 8.0+ közös részhalmaza.
 */
final class Connection
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $prefix = 'cc_',
    ) {
    }

    /**
     * @param array{host?: string, port?: int, name: string, user: string, password?: string, prefix?: string, socket?: string} $config
     */
    public static function fromConfig(array $config): self
    {
        $dsn = isset($config['socket']) && $config['socket'] !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $config['socket'], $config['name'])
            : sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 3306,
                $config['name'],
            );

        return new self($dsn, $config['user'], $config['password'] ?? '', $config['prefix'] ?? 'cc_');
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // Egységes munkamenet minden tárhelyen, a szerver alapbeállításaitól függetlenül.
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec(
                "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,"
                . "NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            );
        }

        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** A prefixszel ellátott, idézőjelezett táblanév. */
    public function table(string $name): string
    {
        return self::quoteIdentifier($this->prefix . $name);
    }

    public static function quoteIdentifier(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("Érvénytelen azonosító: {$name}");
        }

        return '`' . $name . '`';
    }

    /** A `{tabla}` helyőrzőket valódi táblanévre cseréli. */
    public function expand(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/',
            fn (array $m): string => $this->table($m[1]),
            $sql,
        );
    }

    /** @param array<string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($this->expand($sql));
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . ltrim((string) $name, ':'), $value, self::paramType($value));
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return array_values($this->run($sql, $params)->fetchAll());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<mixed>
     */
    public function fetchColumn(string $sql, array $params = []): array
    {
        return array_values($this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return int Az új sor azonosítója (AUTO_INCREMENT esetén).
     */
    public function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table($table),
            implode(', ', array_map(self::quoteIdentifier(...), $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );
        $this->run($sql, $row);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where Egyenlőségi feltételek, ÉS kapcsolattal.
     */
    public function update(string $table, array $row, array $where): int
    {
        $params = [];
        $set = [];
        foreach ($row as $column => $value) {
            $set[] = self::quoteIdentifier($column) . ' = :s_' . $column;
            $params['s_' . $column] = $value;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->table($table),
            implode(', ', $set),
            $this->whereClause($where, $params),
        );

        return $this->execute($sql, $params);
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        $params = [];
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->table($table), $this->whereClause($where, $params));

        return $this->execute($sql, $params);
    }

    /**
     * Tranzakcióban futtatja a műveletet. Egymásba ágyazható: csak a
     * legkülső hívás indít és zár tranzakciót.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $outermost = $this->transactionDepth === 0;
        if ($outermost) {
            $this->pdo()->beginTransaction();
        }
        $this->transactionDepth++;

        try {
            $result = $work($this);
            $this->transactionDepth--;
            if ($outermost) {
                $this->pdo()->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            $this->transactionDepth--;
            if ($outermost && $this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
    }

    public function tableExists(string $table): bool
    {
        try {
            $this->run(sprintf('SELECT 1 FROM %s LIMIT 1', $this->table($table)));

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function serverVersion(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * @param array<string, mixed> $where
     * @param array<string, mixed> $params
     */
    private function whereClause(array $where, array &$params): string
    {
        if ($where === []) {
            throw new \InvalidArgumentException('Feltétel nélküli UPDATE/DELETE nem engedélyezett.');
        }
        $parts = [];
        foreach ($where as $column => $value) {
            $parts[] = self::quoteIdentifier($column) . ' = :w_' . $column;
            $params['w_' . $column] = $value;
        }

        return implode(' AND ', $parts);
    }

    private static function paramType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            default => PDO::PARAM_STR,
        };
    }
}
