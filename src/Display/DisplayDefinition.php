<?php

declare(strict_types=1);

namespace Campanella\Display;

use Campanella\Capability\Capability;
use Campanella\Model\Field;

/** A display's declaration, read from its class (since 0.3.3). */
final readonly class DisplayDefinition
{
    /**
     * @param class-string<Display> $class
     * @param list<class-string<Capability>> $requires
     * @param list<string> $sources
     * @param array<string, Field> $settings name => field
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $label,
        public array $requires,
        public bool $many,
        public array $sources,
        public array $settings,
    ) {
    }

    /**
     * @param class-string<Display> $class
     * @throws DisplayException if it is not a valid display
     */
    public static function fromClass(string $class): self
    {
        if (!is_subclass_of($class, Display::class)) {
            throw new DisplayException("{$class} is not a Display.");
        }
        $attributes = (new \ReflectionClass($class))->getAttributes(AsDisplay::class);
        if ($attributes === []) {
            throw new DisplayException("{$class} has no #[AsDisplay] attribute.");
        }
        $as = $attributes[0]->newInstance();
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $as->name) !== 1) {
            throw new DisplayException("Invalid display name: {$as->name}");
        }
        $kinds = ($as->requires !== [] ? 1 : 0) + ($as->many ? 1 : 0) + ($as->sources !== [] ? 1 : 0);
        if ($kinds > 1) {
            throw new DisplayException("Display {$as->name}: it is for objects (requires), a list (many) or system sources, only one of them.");
        }
        $settings = [];
        foreach ($class::settings() as $field) {
            if (isset($settings[$field->name])) {
                throw new DisplayException("Display {$as->name}: the setting {$field->name} is declared twice.");
            }
            if ($field->required && $field->default === null) {
                throw new DisplayException("Display {$as->name}: the required setting {$field->name} needs a default.");
            }
            $settings[$field->name] = $field;
        }

        return new self($as->name, $class, $as->label !== '' ? $as->label : $as->name, $as->requires, $as->many, $as->sources, $settings);
    }

    /** Whether it is for objects (with no requirement: any object). */
    public function isForObjects(): bool
    {
        return !$this->many && $this->sources === [];
    }

    /** Whether its declaration allows this subject (the display itself may still refuse it: Display::accepts()). */
    public function allows(Subject $subject): bool
    {
        if ($this->many) {
            return $subject->isList();
        }
        if ($this->sources !== []) {
            return $subject->isSystem() && in_array($subject->source, $this->sources, true);
        }
        if (!$subject->isObject() || $subject->object === null) {
            return false;
        }
        foreach ($this->requires as $capability) {
            if (!$subject->object->has($capability)) {
                return false;
            }
        }

        return true;
    }
}
