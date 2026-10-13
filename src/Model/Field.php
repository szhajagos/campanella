<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\I18n\Message;

/**
 * The definition of a field. A field is either provided by a capability
 * (e.g. Titled provides `title`) or added by a Blueprint as a custom field.
 *
 * Field names are unique system-wide, so on the object they are simply
 * accessible as `$object->get('title')`.
 *
 * A `hidden` field (e.g. a password hash) is not accessible from templates
 * as `{{ object.field }}`; from PHP, get() still reads it.
 *
 * Cardinality: how many values the field holds. `1` (the default) is a
 * single value; anything else makes it multi-valued, and its value is then
 * always a list: a limit (e.g. `3`) or `Field::UNLIMITED`. A queryable
 * (Table) multi-valued field lives in the shared `field_values` table, a
 * Data one in the `data` JSON column as a list.
 *
 * Since 0.2.2: `translatable` marks a field whose value may differ per
 * language (stored, without effect until multilingual content, see
 * docs/translations.md); `choices` are the keys and labels of a Choice field;
 * `scale` is the number of digits after the decimal point of a Decimal field.
 */
final readonly class Field
{
    /** Cardinality: any number of values. */
    public const int UNLIMITED = -1;

    /**
     * A queryable multi-valued String field is stored in a VARCHAR(255) column of
     * `field_values`, so its length can be at most this (checked when the capability
     * is registered, and for a Blueprint's own queryable fields).
     */
    public const int MAX_MULTI_STRING_LENGTH = 255;

    /**
     * @param array<string, string> $choices A Choice field's keys and labels (a message key or text)
     */
    public function __construct(
        public string $name,
        public FieldType $type,
        public FieldStorage $storage = FieldStorage::Table,
        public bool $required = false,
        public mixed $default = null,
        public bool $indexed = false,
        public bool $unique = false,
        public int $length = 255,
        public string $label = '',
        public bool $hidden = false,
        public int $cardinality = 1,
        public bool $translatable = false,
        public array $choices = [],
        public int $scale = 2,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid field name: {$name}");
        }
        if ($type === FieldType::Choice) {
            if ($choices === []) {
                throw new \InvalidArgumentException("Field {$name}: a Choice field needs its choices.");
            }
            foreach ($choices as $key => $label) {
                if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) $key) !== 1 || strlen((string) $key) > $length || $label === '') {
                    throw new \InvalidArgumentException("Field {$name}: invalid choice '{$key}' (a key of letters, digits, _ . -, and a label).");
                }
            }
        } elseif ($choices !== []) {
            throw new \InvalidArgumentException("Field {$name}: only a Choice field has choices.");
        }
        if ($type === FieldType::Html && $cardinality !== 1) {
            throw new \InvalidArgumentException("Field {$name}: an Html field is single-valued.");
        }
        if ($scale < 0 || $scale > FieldType::DECIMAL_SCALE) {
            throw new \InvalidArgumentException(sprintf('Field %s: the scale must be between 0 and %d.', $name, FieldType::DECIMAL_SCALE));
        }
        if ($storage === FieldStorage::Data && ($indexed || $unique)) {
            throw new \InvalidArgumentException(
                "Field {$name} lives in the data column, so it cannot be indexed or unique.",
            );
        }
        if ($cardinality !== self::UNLIMITED && $cardinality < 1) {
            throw new \InvalidArgumentException(
                "Field {$name}: cardinality must be at least 1, or Field::UNLIMITED.",
            );
        }
        if ($cardinality !== 1) {
            if ($unique) {
                throw new \InvalidArgumentException("Field {$name}: a multi-valued field cannot be unique.");
            }
        }
    }

    /** The same field, but stored in the data (JSON) column. */
    public function asData(): self
    {
        return $this->copy(storage: FieldStorage::Data, indexed: false, unique: false);
    }

    /**
     * The same field as a queryable one (FieldStorage::Table), e.g. a Blueprint's own
     * field listed under its `queryable` key. Since 0.2.2.
     */
    public function asTable(): self
    {
        return $this->copy(storage: FieldStorage::Table);
    }

    /**
     * The same field with a lower value limit (a Blueprint may narrow a
     * capability's field, e.g. from 3 to 2 or from unlimited to 5).
     */
    public function withCardinality(int $cardinality): self
    {
        if (!$this->isMultiple()) {
            throw new \InvalidArgumentException("Field {$this->name} is single-valued; its cardinality cannot be changed.");
        }
        if ($cardinality < 2) {
            throw new \InvalidArgumentException(
                "Field {$this->name}: a multi-valued field can only be narrowed to at least 2 values.",
            );
        }
        if ($this->cardinality !== self::UNLIMITED && $cardinality > $this->cardinality) {
            throw new \InvalidArgumentException(sprintf(
                'Field %s: the cardinality can only be narrowed (%d → %d is an increase).',
                $this->name,
                $this->cardinality,
                $cardinality,
            ));
        }

        return $this->copy(cardinality: $cardinality);
    }

    private function copy(
        ?FieldStorage $storage = null,
        ?bool $indexed = null,
        ?bool $unique = null,
        ?int $cardinality = null,
    ): self {
        return new self(
            name: $this->name,
            type: $this->type,
            storage: $storage ?? $this->storage,
            required: $this->required,
            default: $this->default,
            indexed: $indexed ?? $this->indexed,
            unique: $unique ?? $this->unique,
            length: $this->length,
            label: $this->label,
            hidden: $this->hidden,
            cardinality: $cardinality ?? $this->cardinality,
            translatable: $this->translatable,
            choices: $this->choices,
            scale: $this->scale,
        );
    }

    public function isMultiple(): bool
    {
        return $this->cardinality !== 1;
    }

    public function isUnlimited(): bool
    {
        return $this->cardinality === self::UNLIMITED;
    }

    public function isQueryable(): bool
    {
        return $this->storage === FieldStorage::Table;
    }

    /** A queryable multi-valued field: its values live in the `field_values` table. */
    public function usesValueTable(): bool
    {
        return $this->isMultiple() && $this->storage === FieldStorage::Table;
    }

    /**
     * The uniform PHP-side value. For a multi-valued field always a list:
     * a single value becomes a one-element list, empty items (null, '') are dropped.
     */
    public function cast(mixed $value): mixed
    {
        if (!$this->isMultiple()) {
            return $this->castOne($value);
        }
        if ($value === null) {
            return [];
        }
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value, false);
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $items = [];
        foreach ($value as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $items[] = $this->castOne($item);
        }

        return $items;
    }

    /** One value of the field's type; a Decimal is rounded to the field's scale (half away from zero). */
    private function castOne(mixed $value): mixed
    {
        $value = $this->type->cast($value);
        if ($this->type === FieldType::Decimal && is_string($value)) {
            return self::round($value, $this->scale);
        }

        return $value;
    }

    /** A canonical numeric string rounded to `$scale` digits after the point, padded with zeros. */
    private static function round(string $number, int $scale): string
    {
        $negative = str_starts_with($number, '-');
        [$whole, $fraction] = explode('.', ltrim($number, '-') . '.');
        $fraction = str_pad($fraction, $scale + 1, '0');
        $digits = $whole . substr($fraction, 0, $scale);
        if ((int) $fraction[$scale] >= 5) {
            // Add one to the last kept digit, carrying to the left.
            $i = strlen($digits) - 1;
            while ($i >= 0 && $digits[$i] === '9') {
                $digits[$i] = '0';
                $i--;
            }
            $digits = $i < 0 ? '1' . $digits : substr_replace($digits, (string) ((int) $digits[$i] + 1), $i, 1);
        }
        $whole = ltrim(substr($digits, 0, strlen($digits) - $scale), '0');
        $result = ($whole === '' ? '0' : $whole) . ($scale > 0 ? '.' . substr($digits, -$scale) : '');

        return $negative && trim($result, '0.') !== '' ? '-' . $result : $result;
    }

    /**
     * Whether a value of the field is invalid for its type (since 0.2.2): an e-mail
     * address or web address that is not one, a choice that is not offered, a number
     * too large for its column. Empty values are not checked here (see `required`).
     */
    public function invalid(mixed $value): ?Message
    {
        foreach ($this->isMultiple() ? (array) $value : [$value] as $item) {
            if ($this->isEmpty($item)) {
                continue;
            }
            $text = is_scalar($item) || $item instanceof \Stringable ? (string) $item : '';
            $problem = match ($this->type) {
                FieldType::Email => filter_var($text, FILTER_VALIDATE_EMAIL) === false ? new Message('validation.invalid_email') : null,
                FieldType::Url => self::isWebAddress($text) ? null : new Message('validation.invalid_web_address'),
                FieldType::Choice => array_key_exists($text, $this->choices) ? null : new Message('validation.invalid_choice'),
                FieldType::Decimal => strlen(explode('.', ltrim($text, '-'))[0]) > FieldType::DECIMAL_DIGITS
                    ? new Message('validation.number_too_large', ['digits' => FieldType::DECIMAL_DIGITS])
                    : null,
                default => null,
            };
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** An `http://` or `https://` address with a host, without spaces or control characters. Since 0.2.2. */
    public static function isWebAddress(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1
            && preg_match('/[\x00-\x20\x7f\\\\]/', $url) !== 1
            && (string) (parse_url($url, PHP_URL_HOST) ?? '') !== '';
    }

    /** The label of a Choice field's key (a message key or text), or null for a key not offered. Since 0.2.2. */
    public function choiceLabel(string $key): ?string
    {
        $label = $this->choices[$key] ?? null;

        return is_string($label) ? $label : null;
    }

    /**
     * A storable form. For a multi-valued field a list of the items' storable forms
     * (for the data JSON column or the rows of the `field_values` table).
     */
    public function toStorage(mixed $value): mixed
    {
        if (!$this->isMultiple()) {
            return $this->type->toStorage($value);
        }

        return array_map($this->type->toStorage(...), (array) $this->cast($value));
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->cast($value);
    }

    /**
     * For a multi-valued String field: whether an item is longer than the field's length.
     * (A single-valued field is limited by its VARCHAR column; the shared
     * `field_values` column is always VARCHAR(255).)
     */
    public function hasTooLongItem(mixed $value): bool
    {
        if (!$this->isMultiple() || $this->type->columnType() !== \Campanella\Database\Schema\ColumnType::String || !is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (is_string($item) && mb_strlen($item, 'UTF-8') > $this->length) {
                return true;
            }
        }

        return false;
    }

    /** For a single-valued String field: whether the value is longer than the field's length. */
    public function isTooLong(mixed $value): bool
    {
        return !$this->isMultiple() && $this->type->columnType() === \Campanella\Database\Schema\ColumnType::String && is_string($value)
            && mb_strlen($value, 'UTF-8') > $this->length;
    }

    /** Whether the number of values exceeds the limit. */
    public function exceedsCardinality(mixed $value): bool
    {
        return $this->isMultiple() && !$this->isUnlimited() && is_array($value) && count($value) > $this->cardinality;
    }

    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
