<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Database\Schema\ColumnType;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Mezőtípusok. Mindegyik tudja, hogyan normalizálja a PHP-oldali értéket
 * (cast), és hogyan alakítja tárolható formára és vissza.
 */
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case DateTime = 'datetime';

    public const string STORAGE_DATE_FORMAT = 'Y-m-d H:i:s';

    public function columnType(): ColumnType
    {
        return match ($this) {
            self::String => ColumnType::String,
            self::Text => ColumnType::Text,
            self::Integer => ColumnType::Integer,
            self::Boolean => ColumnType::Boolean,
            self::DateTime => ColumnType::DateTime,
        };
    }

    /** PHP-oldali, egységes értékre alakít. */
    public function cast(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return match ($this) {
            self::String, self::Text => is_scalar($value) || $value instanceof \Stringable
                ? (string) $value
                : throw new \InvalidArgumentException('Szöveges érték várt.'),
            self::Integer => is_numeric($value)
                ? (int) $value
                : throw new \InvalidArgumentException('Egész szám várt.'),
            self::Boolean => (bool) $value,
            self::DateTime => self::toUtc($value),
        };
    }

    /** Adatbázisba (vagy JSON-ba) írható forma. */
    public function toStorage(mixed $value): string|int|null
    {
        $value = $this->cast($value);

        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format(self::STORAGE_DATE_FORMAT),
            is_bool($value) => $value ? 1 : 0,
            default => $value,
        };
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->cast($value);
    }

    private static function toUtc(mixed $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($utc);
        }
        if (is_string($value)) {
            return (new DateTimeImmutable($value, $utc))->setTimezone($utc);
        }

        throw new \InvalidArgumentException('Dátum várt.');
    }
}
