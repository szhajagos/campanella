<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Database\Schema\ColumnType;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Field types. Each knows how to normalize the PHP-side value (cast) and
 * how to convert it to a storable form and back.
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

    /**
     * The column of the `field_values` table that holds the values of a
     * multi-valued field of this type.
     */
    public function valueColumn(): string
    {
        return match ($this) {
            self::String => 'value_string',
            self::Text => 'value_text',
            self::Integer, self::Boolean => 'value_int',
            self::DateTime => 'value_datetime',
        };
    }

    /** Converts to a uniform PHP-side value. */
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
                : throw new \InvalidArgumentException('Expected a string value.'),
            self::Integer => is_numeric($value)
                ? (int) $value
                : throw new \InvalidArgumentException('Expected an integer.'),
            self::Boolean => (bool) $value,
            self::DateTime => self::toUtc($value),
        };
    }

    /** A form that can be written to the database (or to JSON). */
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

        throw new \InvalidArgumentException('Expected a date.');
    }
}
