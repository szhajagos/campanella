<?php

declare(strict_types=1);

namespace Campanella\Display;

use Campanella\Lists\ListDefinition;
use Campanella\Model\CampanellaObject;

/**
 * What a display shows (since 0.3.3): one object, a list of objects, or the value of a
 * system source (the visitor, the search …). Made by the source, already read with the
 * visitor's rights.
 */
final readonly class Subject
{
    public const string OBJECT = 'object';
    public const string LIST = 'list';
    public const string SYSTEM = 'system';

    /**
     * @param list<CampanellaObject> $items
     */
    private function __construct(
        public string $kind,
        public ?CampanellaObject $object = null,
        public array $items = [],
        public ?ListDefinition $list = null,
        public ?string $source = null,
        public mixed $value = null,
    ) {
    }

    public static function object(CampanellaObject $object): self
    {
        return new self(self::OBJECT, object: $object);
    }

    /**
     * @param iterable<mixed> $items Its objects (anything else in it is left out)
     * @param ListDefinition|null $list The list made in the admin they come from, if any
     */
    public static function list(iterable $items, ?ListDefinition $list = null): self
    {
        $objects = [];
        foreach ($items as $item) {
            if ($item instanceof CampanellaObject) {
                $objects[] = $item;
            }
        }

        return new self(self::LIST, items: $objects, list: $list);
    }

    /**
     * @param string $source The system source's name
     * @param mixed $value What it knows (e.g. the visitor's Actor)
     * @param CampanellaObject|null $object Its object, if it has one (e.g. the logged-in user)
     */
    public static function system(string $source, mixed $value = null, ?CampanellaObject $object = null): self
    {
        return new self(self::SYSTEM, object: $object, source: $source, value: $value);
    }

    public function isObject(): bool
    {
        return $this->kind === self::OBJECT;
    }

    public function isList(): bool
    {
        return $this->kind === self::LIST;
    }

    public function isSystem(): bool
    {
        return $this->kind === self::SYSTEM;
    }
}
