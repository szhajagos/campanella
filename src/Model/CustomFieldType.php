<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\I18n\Message;

/**
 * A field type of a module's own (since 0.4.8), built on one of the core's (its base type,
 * FieldType): stored, queried and filtered as its base type is, so its values live in the same
 * columns; what it adds is its own behaviour. Registered by a module
 * (ModuleContext::fieldType()), its name starting with the module's (`newsletter_phone`).
 * Extend AbstractFieldType for the defaults.
 *
 * A field of the type is stored with its base too (`"type": "newsletter_phone", "base":
 * "string"`), so while the module is off or gone, the field works as its base type (its
 * content stays readable and editable; only this behaviour is left out).
 */
interface CustomFieldType
{
    /** Its name: small letters, digits, `_`, starting with its module's name and `_`. */
    public function name(): string;

    /** The core's type it is built on, stored and queried as. */
    public function base(): FieldType;

    /** Its name in the admin: a key of its module's translations. */
    public function label(): string;

    /** What it is for, in a sentence: a key of its module's translations, or ''. */
    public function description(): string;

    /** A value of the base type (already cast), as it is stored: e.g. without spaces. */
    public function normalize(mixed $value): mixed;

    /** Why a value (not empty, normalized, valid for the base type) is not one of the type; null if it is. */
    public function invalid(mixed $value): ?Message;

    /** The value as text to show (it is escaped where it is shown); null: as its base type shows it. */
    public function format(mixed $value): ?string;

    /**
     * Its own form widget in the admin, a template of its module (`@<module>/…`, given `field`,
     * `value`, `input_id`, `invalid` as the core's widgets); null: its base type's widget.
     */
    public function widget(): ?string;

    /**
     * HTML attributes for its base type's widget, e.g. `['inputmode' => 'tel', 'autocomplete' => 'tel']`:
     * names of small letters and `-`, never an event handler (`on…`), `style` or one that sets the field's name.
     *
     * @return array<string, string|int>
     */
    public function attributes(): array;

    /** A value from an import's file (text), before it is cast to the base type. */
    public function import(string $value): mixed;
}
