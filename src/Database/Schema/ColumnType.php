<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * Column types. Contains only types that behave the same on
 * MariaDB 10.6+ and MySQL 8.0+.
 */
enum ColumnType
{
    case Id;        // BIGINT UNSIGNED
    case Integer;   // INT
    case Boolean;   // TINYINT(1)
    case String;    // VARCHAR(n)
    case Text;      // MEDIUMTEXT
    case DateTime;  // DATETIME, always UTC
    case Uuid;      // CHAR(36)
    case Json;      // JSON (on MariaDB: LONGTEXT + JSON_VALID check)

    public function sql(int $length): string
    {
        return match ($this) {
            self::Id => 'BIGINT UNSIGNED',
            self::Integer => 'INT',
            self::Boolean => 'TINYINT(1)',
            self::String => sprintf('VARCHAR(%d)', $length),
            self::Text => 'MEDIUMTEXT',
            self::DateTime => 'DATETIME',
            self::Uuid => 'CHAR(36)',
            self::Json => 'JSON',
        };
    }
}
