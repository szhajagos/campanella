<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Relation\Cardinality;
use Campanella\Relation\Relation;
use InvalidArgumentException;

/**
 * The stored form of a Blueprint made in the admin (since 0.2.3), and its
 * conversion to the definition the BlueprintRegistry takes. Plain JSON-safe
 * arrays, the same that the content model's export will write:
 *
 * ```json
 * {
 *   "version": 1,
 *   "label": "Esemény",
 *   "capabilities": ["titled", "textual", "routable", "publishable"],
 *   "fields": [
 *     {"name": "day", "type": "date", "label": "Nap", "required": true, "queryable": true,
 *      "cardinality": 1, "translatable": false, "length": 255, "scale": 2, "choices": [], "default": null},
 *     {"name": "fajta", "type": "choice", "label": "Fajta",
 *      "choices": [{"key": "eloadas", "label": "Előadás"}, {"key": "seta", "label": "Séta"}]}
 *   ],
 *   "relations": [
 *     {"name": "photo", "label": "Kép", "targets": ["image"], "many": false, "required": false}
 *   ],
 *   "admin_columns": ["day", "fajta"]
 * }
 * ```
 *
 * The choices are a list of key–label pairs, not an object: MySQL stores a JSON
 * object with its keys sorted, which would lose their order (0.2.3 wrote an
 * object; it is still read).
 *
 * Everything read from the database is checked again here: a definition that
 * does not pass is not loaded (it may have been changed by hand).
 */
final class BlueprintDefinition
{
    /** The format's version. */
    public const int VERSION = 1;

    /**
     * Capabilities a Blueprint made in the admin cannot have: they give objects a role of
     * the system (a user, an uploaded file, a form's message, a menu's key or link) with
     * pages of their own.
     */
    public const array SYSTEM_CAPABILITIES = ['identifiable', 'authenticatable', 'media_file', 'submitted', 'keyed', 'link', 'downloadable', 'restricted'];

    /** Every Blueprint made in the admin has a title: the lists and the choices show it. */
    public const string REQUIRED_CAPABILITY = 'titled';

    /** The longest label. */
    public const int MAX_LABEL = 100;

    /** A choice's key (since 0.3.0 checked here too; the editor's pattern since 0.2.3). */
    public const string CHOICE_KEY = '/^[A-Za-z0-9_.-]{1,64}$/';

    /** The most choices of a field (since 0.3.0). */
    public const int MAX_CHOICES = 200;

    /** The most own fields and relations of a Blueprint made in the admin. */
    public const int MAX_FIELDS = 60;

    /**
     * The definition for BlueprintRegistry::define(), from the stored form.
     *
     * @param array<string, mixed> $stored
     * @return array{label: string, capabilities: list<string>, fields: list<Field>, queryable: list<string>, relations: list<Relation>, form_order: list<string>, defaults: array<string, mixed>, editor: array<string, string>, admin_columns: list<string>}
     * @throws InvalidArgumentException for a definition that is not valid
     */
    public static function toRegistry(array $stored): array
    {
        $capabilities = self::capabilities($stored['capabilities'] ?? null);
        $fields = [];
        $queryable = [];
        foreach (self::listOf($stored['fields'] ?? [], 'fields') as $item) {
            $field = self::field(is_array($item) ? $item : throw new InvalidArgumentException('A field must be an object.'));
            $fields[] = $field;
            if ($field->storage === FieldStorage::Table) {
                $queryable[] = $field->name;
            }
        }
        $relations = array_map(
            static fn (mixed $item): Relation => self::relation(is_array($item) ? $item : throw new InvalidArgumentException('A relation must be an object.')),
            self::listOf($stored['relations'] ?? [], 'relations'),
        );
        if (count($fields) + count($relations) > self::MAX_FIELDS) {
            throw new InvalidArgumentException(sprintf('At most %d fields and relations.', self::MAX_FIELDS));
        }
        $names = [...array_map(static fn (Field $f): string => $f->name, $fields), ...array_map(static fn (Relation $r): string => $r->name, $relations)];
        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('A field or relation name is used twice.');
        }
        $textual = in_array('textual', $capabilities, true);

        return [
            'admin_columns' => self::adminColumns($stored['admin_columns'] ?? [], $names),
            'label' => self::label($stored['label'] ?? null),
            'capabilities' => $capabilities,
            // The own fields are Data here; 'queryable' lists those that get a column.
            'fields' => array_map(static fn (Field $f): Field => $f->asData(), $fields),
            'queryable' => $queryable,
            'relations' => $relations,
            // The title first, then the own fields, the text, the relations; the rest after them.
            'form_order' => ['title', ...array_map(static fn (Field $f): string => $f->name, $fields), 'body', ...array_map(static fn (Relation $r): string => $r->name, $relations)],
            // A text is formatted (HTML), edited with the editor.
            'defaults' => $textual ? ['format' => 'html'] : [],
            'editor' => $textual ? ['body' => 'full'] : [],
        ];
    }

    /**
     * The columns of the Blueprint's list in the admin (since 0.2.8): its own fields and
     * relations, at most BlueprintRegistry::MAX_ADMIN_COLUMNS. A name that is no longer one
     * of them (a field deleted since) is left out, so the Blueprint still loads.
     *
     * @param list<string> $names The Blueprint's own fields and relations
     * @return list<string>
     * @throws InvalidArgumentException
     */
    public static function adminColumns(mixed $columns, array $names): array
    {
        $checked = [];
        foreach (self::listOf($columns, 'admin_columns') as $column) {
            if (is_string($column) && in_array($column, $names, true) && !in_array($column, $checked, true)) {
                $checked[] = $column;
            }
        }

        return array_slice($checked, 0, BlueprintRegistry::MAX_ADMIN_COLUMNS);
    }

    /**
     * A field from its stored form.
     *
     * @param array<string, mixed> $item
     * @throws InvalidArgumentException
     */
    public static function field(array $item): Field
    {
        $type = FieldType::tryFrom(is_string($item['type'] ?? null) ? $item['type'] : '')
            ?? throw new InvalidArgumentException('Unknown field type: ' . json_encode($item['type'] ?? null));
        $name = self::name($item['name'] ?? null);
        if (in_array($name, BlueprintRegistry::RESERVED_FIELD_NAMES, true)) {
            throw new InvalidArgumentException("The field name '{$name}' is reserved.");
        }
        $cardinality = $item['cardinality'] ?? 1;
        if (!is_int($cardinality) || ($cardinality !== Field::UNLIMITED && ($cardinality < 1 || $cardinality > 100))) {
            throw new InvalidArgumentException("Field {$name}: the number of values is 1 to 100, or unlimited.");
        }
        $length = $item['length'] ?? 255;
        if (!is_int($length) || $length < 1 || $length > Field::MAX_MULTI_STRING_LENGTH) {
            throw new InvalidArgumentException("Field {$name}: the length is 1 to 255.");
        }
        $choices = [];
        $stored = is_array($item['choices'] ?? null) ? $item['choices'] : [];
        foreach ($stored as $key => $choice) {
            if (array_is_list($stored)) {
                // [{"key": …, "label": …}, …]
                if (!is_array($choice) || !is_scalar($choice['key'] ?? null)) {
                    throw new InvalidArgumentException("Field {$name}: a choice is a key and a label.");
                }
                $key = $choice['key'];
                $choice = $choice['label'] ?? null;
            }
            // The key is stored and goes into addresses: the editor's pattern (checked here too
            // since 0.3.0, for an imported file).
            if (preg_match(self::CHOICE_KEY, (string) $key) !== 1) {
                throw new InvalidArgumentException("Field {$name}: a choice's key is 1 to 64 letters, digits, '_', '.' or '-': " . json_encode($key));
            }
            if (array_key_exists((string) $key, $choices)) {
                throw new InvalidArgumentException("Field {$name}: the choice '{$key}' is given twice.");
            }
            if (count($choices) >= self::MAX_CHOICES) {
                throw new InvalidArgumentException(sprintf('Field %s: at most %d choices.', $name, self::MAX_CHOICES));
            }
            $choices[(string) $key] = self::label($choice);
        }
        $default = $item['default'] ?? null;
        if ($default !== null && !is_scalar($default)) {
            throw new InvalidArgumentException("Field {$name}: the default must be a single value.");
        }

        $field = new Field(
            name: $name,
            type: $type,
            storage: ($item['queryable'] ?? false) === true ? FieldStorage::Table : FieldStorage::Data,
            required: ($item['required'] ?? false) === true,
            length: $length,
            label: self::label($item['label'] ?? $name),
            cardinality: $cardinality,
            translatable: ($item['translatable'] ?? false) === true,
            choices: $choices,
            scale: is_int($item['scale'] ?? null) ? $item['scale'] : 2,
        );
        if ($default !== null && $default !== '') {
            try {
                $value = $field->cast($default);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException("Field {$name}: the default is not a value of the field's type.");
            }
            if ($field->isMultiple() || $field->invalid($value) !== null || $field->isTooLong($value)) {
                throw new InvalidArgumentException("Field {$name}: the default is not a valid value of the field.");
            }
            $field = new Field(
                name: $field->name,
                type: $field->type,
                storage: $field->storage,
                required: $field->required,
                default: $value,
                length: $field->length,
                label: $field->label,
                cardinality: $field->cardinality,
                translatable: $field->translatable,
                choices: $field->choices,
                scale: $field->scale,
            );
        }

        return $field;
    }

    /**
     * A field's stored form.
     *
     * @return array<string, mixed>
     */
    public static function fieldArray(Field $field): array
    {
        return [
            'name' => $field->name,
            'type' => $field->type->value,
            'label' => $field->label,
            'required' => $field->required,
            'queryable' => $field->storage === FieldStorage::Table,
            'cardinality' => $field->cardinality,
            'translatable' => $field->translatable,
            'length' => $field->length,
            'scale' => $field->scale,
            'choices' => array_map(
                static fn (string|int $key, string $label): array => ['key' => (string) $key, 'label' => $label],
                array_keys($field->choices),
                array_values($field->choices),
            ),
            'default' => $field->default === null ? null : $field->type->toStorage($field->default),
        ];
    }

    /**
     * A relation from its stored form: to objects of the given Blueprints.
     *
     * @param array<string, mixed> $item
     * @throws InvalidArgumentException
     */
    public static function relation(array $item): Relation
    {
        $name = self::name($item['name'] ?? null);
        $targets = [];
        foreach (is_array($item['targets'] ?? null) ? $item['targets'] : [] as $target) {
            $targets[] = self::name($target);
        }
        if ($targets === []) {
            throw new InvalidArgumentException("Relation {$name}: it needs a target Blueprint.");
        }

        return new Relation(
            name: $name,
            cardinality: ($item['many'] ?? false) === true ? Cardinality::Many : Cardinality::One,
            targetBlueprints: array_values(array_unique($targets)),
            required: ($item['required'] ?? false) === true,
            label: self::label($item['label'] ?? $name),
        );
    }

    /** @return array<string, mixed> A relation's stored form. */
    public static function relationArray(Relation $relation): array
    {
        return [
            'name' => $relation->name,
            'label' => $relation->label,
            'targets' => $relation->targetBlueprints,
            'many' => $relation->isMany(),
            'required' => $relation->required,
        ];
    }

    /**
     * A machine name: lowercase letters, digits and `_`, starting with a letter, at most 40
     * characters (a Blueprint's table name and its keys must fit into 64).
     *
     * @throws InvalidArgumentException
     */
    public static function name(mixed $name): string
    {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid name: ' . json_encode($name));
        }

        return $name;
    }

    /** @throws InvalidArgumentException */
    private static function label(mixed $label): string
    {
        if (!is_string($label) || trim($label) === '' || mb_strlen($label, 'UTF-8') > self::MAX_LABEL || preg_match('/[\x00-\x1f\x7f]/', $label) === 1) {
            throw new InvalidArgumentException('Invalid label: ' . json_encode($label));
        }

        return trim($label);
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException
     */
    private static function capabilities(mixed $capabilities): array
    {
        $names = [];
        foreach (self::listOf($capabilities ?? [], 'capabilities') as $name) {
            if (!is_string($name) || in_array($name, self::SYSTEM_CAPABILITIES, true)) {
                throw new InvalidArgumentException('A capability a Blueprint made in the admin cannot have: ' . json_encode($name));
            }
            $names[] = $name;
        }
        if (!in_array(self::REQUIRED_CAPABILITY, $names, true)) {
            array_unshift($names, self::REQUIRED_CAPABILITY);
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<mixed>
     * @throws InvalidArgumentException
     */
    private static function listOf(mixed $value, string $key): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("'{$key}' must be a list.");
        }

        return $value;
    }
}
