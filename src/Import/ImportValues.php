<?php

declare(strict_types=1);

namespace Campanella\Import;

use Campanella\Html\PlainText;
use Campanella\I18n\Message;
use Campanella\Model\CalendarDate;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A source's value as a field's value (since 0.3.1), by the field's type:
 *
 * | Type | From |
 * |---|---|
 * | text (one line, long), e-mail, web address | the text, trimmed (checked on save) |
 * | formatted text | HTML as it is (filtered on save, as every text); plain text becomes paragraphs |
 * | whole number | digits, with a sign; spaces left out |
 * | decimal | a decimal point or comma (`1 234,5`), rounded to the field's decimals |
 * | yes/no | `1` `0`, `true` `false`, `yes` `no`, `igen` `nem`, `x`; JSON true or false |
 * | date | `2026-11-05`, `2026.11.05.`, `2026. 11. 05.` |
 * | date and time | as a date, with a time (`18:00`, `18:00:30`), or ISO 8601 with an offset (`2026-11-05T18:00:00+01:00`); without an offset, in the site's time zone |
 * | choice | its key, or its label (in any case) |
 *
 * An empty value is null (no value). A multi-valued field takes a JSON list, or a
 * text split by the mapping's separator.
 */
final class ImportValues
{
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    /**
     * The field's value; for a multi-valued field a list.
     *
     * @throws ImportException if it does not fit the field
     */
    public function convert(Field $field, mixed $raw, string $separator): mixed
    {
        if ($field->isMultiple()) {
            $values = [];
            foreach (self::split($raw, $separator) as $item) {
                $value = $this->one($field, $item);
                if ($value !== null) {
                    $values[] = $value;
                }
            }

            return $values;
        }

        return $this->one($field, is_array($raw) ? (array_is_list($raw) && count($raw) === 1 ? $raw[0] : self::invalid($field)) : $raw);
    }

    /**
     * The values of a multi-valued field or a relation: a JSON list as it is, a text split
     * by the separator; empty items left out.
     *
     * @return list<mixed>
     */
    public static function split(mixed $raw, string $separator): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if ($separator === '') {
            $separator = '|';
        }
        $items = is_array($raw) ? array_values($raw) : explode($separator, self::text($raw));

        return array_values(array_filter($items, static fn (mixed $v): bool => $v !== null && (!is_string($v) || trim($v) !== '')));
    }

    private function one(Field $field, mixed $raw): mixed
    {
        if ($raw === null || is_string($raw) && trim($raw) === '') {
            return null;
        }
        if (is_array($raw)) {
            self::invalid($field);
        }

        return match ($field->type) {
            FieldType::String, FieldType::Text, FieldType::Email, FieldType::Url => trim(self::text($raw)),
            FieldType::Html => self::html(self::text($raw)),
            FieldType::Integer => self::integer($field, $raw),
            FieldType::Decimal => self::decimal($field, $raw),
            FieldType::Boolean => self::boolean($field, $raw),
            FieldType::Date => self::date($field, self::text($raw)),
            FieldType::DateTime => $this->moment($field, self::text($raw)),
            FieldType::Choice => self::choice($field, self::text($raw)),
        };
    }

    /** HTML as it is; a text without tags becomes paragraphs (its lines kept). */
    private static function html(string $text): string
    {
        $text = trim($text);

        return preg_match('/<[a-z!\/][^>]*>/i', $text) === 1 ? $text : PlainText::toHtml($text);
    }

    private static function integer(Field $field, mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }
        $text = str_replace([' ', "\u{a0}", "\u{202f}"], '', trim(self::text($raw)));
        if (preg_match('/^[+-]?\d{1,18}$/', $text) !== 1) {
            self::invalid($field, 'import.error.number');
        }

        return (int) $text;
    }

    private static function decimal(Field $field, mixed $raw): string
    {
        try {
            return (string) $field->cast(is_float($raw) || is_int($raw) ? $raw : self::text($raw));
        } catch (\InvalidArgumentException) {
            self::invalid($field, 'import.error.number');
        }
    }

    private static function boolean(Field $field, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return $raw === 1;
        }
        $text = mb_strtolower(trim(self::text($raw)), 'UTF-8');
        if (in_array($text, ['1', 'true', 'yes', 'y', 'igen', 'i', 'x'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'n', 'nem'], true)) {
            return false;
        }
        self::invalid($field);
    }

    private static function date(Field $field, string $text): CalendarDate
    {
        $day = self::day(trim($text));
        if ($day === null) {
            self::invalid($field, 'import.error.date');
        }

        return new CalendarDate($day);
    }

    private function moment(Field $field, string $text): DateTimeImmutable
    {
        $text = trim($text);
        $utc = new DateTimeZone('UTC');
        // ISO 8601 with an offset (or Z).
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $text) === 1) {
            try {
                return (new DateTimeImmutable($text))->setTimezone($utc);
            } catch (\Exception) {
                self::invalid($field, 'import.error.date');
            }
        }
        // A day, and a time, in the site's time zone.
        if (preg_match('/^(.+?)(?:[T ]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $text, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            $day = self::day(rtrim((string) $m[1]));
            [$hour, $minute, $second] = [(int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (int) ($m[4] ?? 0)];
            if ($day !== null && $hour < 24 && $minute < 60 && $second < 60) {
                $time = sprintf('%02d:%02d:%02d', $hour, $minute, $second);
                $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $day . ' ' . $time, $this->timezone);
                if ($moment !== false) {
                    return $moment->setTimezone($utc);
                }
            }
        }
        self::invalid($field, 'import.error.date');
    }

    /** A day written as `2026-11-05`, `2026.11.05.` or `2026. 11. 05.`, as `Y-m-d`; null if it is not one. */
    private static function day(string $text): ?string
    {
        if (preg_match('/^(\d{4})(?:-|\.\s*)(\d{1,2})(?:-|\.\s*)(\d{1,2})\.?$/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    private static function choice(Field $field, string $text): string
    {
        $text = trim($text);
        if (array_key_exists($text, $field->choices)) {
            return $text;
        }
        foreach ($field->choices as $key => $label) {
            if (mb_strtolower($label, 'UTF-8') === mb_strtolower($text, 'UTF-8')) {
                return (string) $key;
            }
        }
        self::invalid($field, 'import.error.choice', ['value' => mb_substr($text, 0, 50)]);
    }

    private static function text(mixed $raw): string
    {
        return match (true) {
            is_string($raw) => $raw,
            is_int($raw), is_float($raw) => (string) $raw,
            is_bool($raw) => $raw ? '1' : '0',
            default => '',
        };
    }

    /**
     * @param array<string, string> $params
     * @return never
     */
    private static function invalid(Field $field, string $key = 'import.error.value', array $params = []): never
    {
        throw new ImportException(new Message($key, ['field' => $field->label !== '' ? $field->label : $field->name] + $params));
    }
}
