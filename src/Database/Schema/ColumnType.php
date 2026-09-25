<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * Oszloptípusok. Csak olyat tartalmaz, ami MariaDB 10.6+ és MySQL 8.0+
 * alatt ugyanúgy viselkedik.
 */
enum ColumnType
{
    case Id;        // BIGINT UNSIGNED
    case Integer;   // INT
    case Boolean;   // TINYINT(1)
    case String;    // VARCHAR(n)
    case Text;      // MEDIUMTEXT
    case DateTime;  // DATETIME, mindig UTC
    case Uuid;      // CHAR(36)
    case Json;      // JSON (MariaDB-n LONGTEXT + JSON_VALID ellenőrzés)

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
