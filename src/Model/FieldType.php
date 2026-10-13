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
 *
 * Since 0.2.2 also: Decimal (a numeric string, e.g. `'12.50'`), Date (a
 * CalendarDate), and four kinds of text with their own checks and form
 * elements: Email, Url (an `http(s)://` address), Choice (the key of one of the
 * field's `choices`) and Html (formatted text, filtered on save).
 */
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case DateTime = 'datetime';
    case Decimal = 'decimal';
    case Date = 'date';
    case Email = 'email';
    case Url = 'url';
    case Choice = 'choice';
    case Html = 'html';

    /** The most digits before the decimal point of a Decimal (its column is DECIMAL(20,6)). */
    public const int DECIMAL_DIGITS = 14;

    /** The most digits after the decimal point of a Decimal. */
    public const int DECIMAL_SCALE = 6;

    public const string STORAGE_DATE_FORMAT = 'Y-m-d H:i:s';

    public function columnType(): ColumnType
    {
        return match ($this) {
            self::String, self::Email, self::Url, self::Choice => ColumnType::String,
            self::Text, self::Html => ColumnType::Text,
            self::Integer => ColumnType::Integer,
            self::Boolean => ColumnType::Boolean,
            self::DateTime => ColumnType::DateTime,
            self::Decimal => ColumnType::Decimal,
            self::Date => ColumnType::Date,
        };
    }

    /**
     * The column of the `field_values` table that holds the values of a
     * multi-valued field of this type.
     */
    public function valueColumn(): string
    {
        return match ($this) {
            self::String, self::Email, self::Url, self::Choice => 'value_string',
            self::Text, self::Html => 'value_text',
            self::Integer, self::Boolean => 'value_int',
            self::DateTime, self::Date => 'value_datetime',
            self::Decimal => 'value_decimal',
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
            self::String, self::Text, self::Email, self::Url, self::Choice, self::Html => is_scalar($value) || $value instanceof \Stringable
                ? (string) $value
                : throw new \InvalidArgumentException('Expected a string value.'),
            self::Integer => is_numeric($value)
                ? (int) $value
                : throw new \InvalidArgumentException('Expected an integer.'),
            self::Boolean => (bool) $value,
            self::DateTime => self::toUtc($value),
            self::Decimal => self::toDecimal($value),
            self::Date => self::toDate($value),
        };
    }

    /** A form that can be written to the database (or to JSON). */
    public function toStorage(mixed $value): string|int|null
    {
        $value = $this->cast($value);

        return match (true) {
            $value === null => null,
            $value instanceof CalendarDate => (string) $value,
            $value instanceof DateTimeInterface => $value->format(self::STORAGE_DATE_FORMAT),
            is_bool($value) => $value ? 1 : 0,
            default => $value,
        };
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->cast($value);
    }

    /**
     * A number as a canonical numeric string: `-12.5`, `3`, `0.25` (a comma is taken as
     * the decimal point; spaces are dropped; no exponent). The field rounds it to its scale.
     */
    private static function toDecimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Expected a number.');
            }
            $value = number_format($value, self::DECIMAL_SCALE, '.', '');
        }
        if (!is_string($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('Expected a number.');
        }
        $text = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], trim((string) $value));
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException('Expected a number.');
        }
        $whole = ltrim($m[2], '0');
        $fraction = rtrim($m[3] ?? '', '0');
        $number = ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);

        return ($m[1] === '-' && $number !== '0') ? '-' . $number : $number;
    }

    private static function toDate(mixed $value): CalendarDate
    {
        return match (true) {
            $value instanceof CalendarDate => $value,
            $value instanceof DateTimeInterface => CalendarDate::of($value),
            is_string($value) => new CalendarDate($value),
            default => throw new \InvalidArgumentException('Expected a date.'),
        };
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
