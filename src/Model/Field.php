<?php

declare(strict_types=1);

namespace Campanella\Model;

/**
 * Egy mező definíciója. A mezőt vagy egy capability hozza (pl. a Titled
 * a `title`-t), vagy egy Blueprint ad hozzá egyedi mezőként.
 *
 * A mezőnevek rendszerszinten egyediek, így az objektumon egyszerűen
 * `$object->get('title')` alakban érhetők el.
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
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Érvénytelen mezőnév: {$name}");
        }
        if ($storage === FieldStorage::Data && ($indexed || $unique)) {
            throw new \InvalidArgumentException(
                "A(z) {$name} mező a data oszlopban él, ezért nem lehet indexelt vagy egyedi.",
            );
        }
    }

    /** Ugyanez a mező, de a data (JSON) oszlopban tárolva. */
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
        );
    }

    public function isQueryable(): bool
    {
        return $this->storage === FieldStorage::Table;
    }

    public function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
