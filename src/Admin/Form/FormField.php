<?php

declare(strict_types=1);

namespace Campanella\Admin\Form;

/**
 * One element of a generated form, as data for the templates
 * (templates/admin/form/<widget>.html.twig).
 */
final readonly class FormField
{
    /**
     * @param string $kind 'field' or 'relation'
     * @param string $widget string, text, integer, boolean, datetime, list, select, checkboxes
     * @param string|list<string> $value The value in form form (a list for a multi-valued field or a Many relation)
     * @param int|null $max The value limit of a multi-valued field or a Many relation (null: none)
     * @param list<array{value: string, label: string}> $options For relations: the possible targets
     * @param array<string, string|int> $attributes Extra HTML attributes (e.g. maxlength)
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $widget,
        public string $label,
        public bool $required = false,
        public bool $multiple = false,
        public ?int $max = null,
        public string|array $value = '',
        public array $options = [],
        public ?string $error = null,
        public ?string $help = null,
        public bool $disabled = false,
        public array $attributes = [],
    ) {
    }

    /** The name of the input in the form: f[title], f[tags][], r[categories][] … */
    public function inputName(): string
    {
        $base = ($this->kind === 'relation' ? 'r' : 'f') . '[' . $this->name . ']';

        return $this->multiple ? $base . '[]' : $base;
    }

    /** The id of the (first) input, for <label for="…">. */
    public function inputId(): string
    {
        return 'field-' . $this->name;
    }
}
