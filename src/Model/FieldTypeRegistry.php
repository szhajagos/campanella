<?php

declare(strict_types=1);

namespace Campanella\Model;

/**
 * The field types (since 0.4.8): the core's (FieldType) and those of the modules that are on
 * (CustomFieldType), by name.
 */
final class FieldTypeRegistry
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{2,63}$/D';

    /** HTML attributes a type may not set on its widget. */
    private const string FORBIDDEN_ATTRIBUTES = '/^(on|style$|name$|id$|form$|type$|value$|formaction$)/';

    /** @var array<string, CustomFieldType> */
    private array $custom = [];

    /**
     * @param list<CustomFieldType> $types
     * @throws \InvalidArgumentException for a name that is not one, a core type's, or used twice
     */
    public function __construct(array $types = [])
    {
        foreach ($types as $type) {
            $name = $type->name();
            if (preg_match(self::NAME_PATTERN, $name) !== 1 || FieldType::tryFrom($name) !== null) {
                throw new \InvalidArgumentException("Not a name for a field type: {$name}");
            }
            if (isset($this->custom[$name])) {
                throw new \InvalidArgumentException("The field type {$name} is there twice.");
            }
            foreach (array_keys($type->attributes()) as $attribute) {
                if (preg_match('/^[a-z][a-z-]{0,30}$/D', (string) $attribute) !== 1 || preg_match(self::FORBIDDEN_ATTRIBUTES, (string) $attribute) === 1) {
                    throw new \InvalidArgumentException("The field type {$name} may not set the attribute {$attribute}.");
                }
            }
            $widget = $type->widget();
            if ($widget !== null && (preg_match('#^@[a-z][a-z0-9_]{1,40}/[A-Za-z0-9_/.-]+\.twig$#D', $widget) !== 1 || str_contains($widget, '..') || str_contains($widget, '//'))) {
                throw new \InvalidArgumentException("The field type {$name}'s widget must be a template of its module: {$widget}");
            }
            $this->custom[$name] = $type;
        }
    }

    public function find(?string $name): ?CustomFieldType
    {
        return $name === null ? null : ($this->custom[$name] ?? null);
    }

    /** @return array<string, CustomFieldType> The modules' types, by name */
    public function custom(): array
    {
        return $this->custom;
    }

    /** @return list<FieldType> The core's types */
    public function core(): array
    {
        return FieldType::cases();
    }
}
