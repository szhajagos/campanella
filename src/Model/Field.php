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
 */
final readonly class Field
{
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
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid field name: {$name}");
        }
        if ($storage === FieldStorage::Data && ($indexed || $unique)) {
            throw new \InvalidArgumentException(
                "Field {$name} lives in the data column, so it cannot be indexed or unique.",
            );
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
        );
    }

    public function isQueryable(): bool
    {
        return $this->storage === FieldStorage::Table;
    }

    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
