<?php

declare(strict_types=1);

namespace Campanella\Model;

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
 */
final readonly class Field
{
    /** Cardinality: any number of values. */
    public const int UNLIMITED = -1;

    /**
     * A queryable multi-valued String field is stored in a VARCHAR(255) column of
     * `field_values`, so its length can be at most this (checked when the capability
     * is registered; a Blueprint's own fields are stored in data and have no limit).
     */
    public const int MAX_MULTI_STRING_LENGTH = 255;

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
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid field name: {$name}");
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
            if ($type === FieldType::StringList) {
                throw new \InvalidArgumentException(
                    "Field {$name}: a StringList field is already a list; it cannot be multi-valued.",
                );
            }
            if ($unique) {
                throw new \InvalidArgumentException("Field {$name}: a multi-valued field cannot be unique.");
            }
        }
    }

    /** The same field, but stored in the data (JSON) column. */
    public function asData(): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            storage: FieldStorage::Data,
            required: $this->required,
            default: $this->default,
            length: $this->length,
            label: $this->label,
            hidden: $this->hidden,
            cardinality: $this->cardinality,
        );
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

        return new self(
            name: $this->name,
            type: $this->type,
            storage: $this->storage,
            required: $this->required,
            default: $this->default,
            indexed: $this->indexed,
            unique: $this->unique,
            length: $this->length,
            label: $this->label,
            hidden: $this->hidden,
            cardinality: $cardinality,
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
            return $this->type->cast($value);
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
            $items[] = $this->type->cast($item);
        }

        return $items;
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
        if (!$this->isMultiple() || $this->type !== FieldType::String || !is_array($value)) {
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
        return !$this->isMultiple() && $this->type === FieldType::String && is_string($value)
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
