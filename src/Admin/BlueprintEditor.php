<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Installer;
use Campanella\Database\Migration\Migrator;
use Campanella\Database\Schema\SchemaReader;
use Campanella\Database\Sync\SchemaSync;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintDefinition;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\BlueprintStore;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Relation\Relation;
use Campanella\Security\Csrf;
use Campanella\Support\Slugger;
use Closure;

/**
 * Blueprints made in the admin (since 0.2.3), under /admin/system/blueprints, for
 * the system roles: creating one, its label and capabilities, adding fields and
 * relations, changing a field (since 0.2.4: everything but its name, its type
 * only to a wider one), their order, deleting fields and relations (since 0.2.4)
 * and a Blueprint without objects.
 *
 * A change is checked by defining every Blueprint again with it (config/blueprints.php
 * and the stored ones); then, if the database must change and the Blueprint has
 * objects, a backup is made; then the definition is saved and the SchemaSync applies
 * it (new table, column, capability, a changed column, moved values). If applying
 * fails, the old definition is put back. A deleted field's or relation's values are
 * deleted after its definition is saved, with a backup first.
 */
final class BlueprintEditor
{
    /**
     * The types a field may change to (since 0.2.4): only wider ones, so every value
     * stays valid. Its column changes with it (SchemaSync).
     */
    public const array WIDER_TYPES = [
        'integer' => ['decimal'],
        'string' => ['text'],
        'date' => ['datetime'],
        'email' => ['string', 'text'],
        'url' => ['string', 'text'],
        'choice' => ['string', 'text'],
    ];

    /** @return list<string> The field's type and those it may change to. */
    private static function typesFor(Field $field): array
    {
        return [$field->type->value, ...(self::WIDER_TYPES[$field->type->value] ?? [])];
    }

    /** The field types offered, in this order. */
    public const array TYPES = ['string', 'text', 'html', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'email', 'url', 'choice'];

    /**
     * @param Closure(): array<string, array{label?: string, capabilities: list<string>}> $codeBlueprints The definitions of
     *        config/blueprints.php (read again for each check)
     */
    public function __construct(
        private readonly BlueprintRegistry $blueprints,
        private readonly CapabilityRegistry $capabilities,
        private readonly BlueprintStore $store,
        private readonly Connection $db,
        private readonly Closure $codeBlueprints,
        private readonly Installer $installer,
        private readonly ?Migrator $migrator,
        private readonly DatabaseBackup $backup,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/blueprints (not empty)
     * @param Closure(string, string, array<string, mixed>): Response $render template, active menu item, context
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        if ($segments === ['new']) {
            return $this->create($request, $render);
        }
        $name = $segments[0];
        if (!$this->blueprints->isEditable($name)) {
            throw HttpException::notFound();
        }
        $blueprint = $this->blueprints->get($name);
        $rest = array_slice($segments, 1);

        return match (true) {
            $rest === [] => $this->edit($request, $blueprint, $render),
            $rest === ['delete'] => $this->delete($request, $blueprint),
            $rest === ['columns'] => $this->columns($request, $blueprint),
            $rest === ['fields', 'new'] => $this->field($request, $blueprint, null, $render),
            count($rest) === 2 && $rest[0] === 'fields' && isset($blueprint->fields[$rest[1]]) => $this->field($request, $blueprint, $blueprint->fields[$rest[1]], $render),
            count($rest) === 3 && $rest[0] === 'fields' && isset($blueprint->fields[$rest[1]]) && in_array($rest[2], ['move-up', 'move-down'], true)
                => $this->move($request, $blueprint, $rest[1], $rest[2] === 'move-up' ? -1 : 1),
            count($rest) === 3 && $rest[0] === 'fields' && isset($blueprint->fields[$rest[1]]) && $rest[2] === 'delete'
                => $this->deleteField($request, $blueprint, $blueprint->fields[$rest[1]]),
            count($rest) === 3 && $rest[0] === 'relations' && isset($blueprint->relations[$rest[1]]) && $rest[2] === 'delete'
                => $this->deleteRelation($request, $blueprint, $blueprint->relations[$rest[1]]),
            $rest === ['relations', 'new'] => $this->relation($request, $blueprint, null, $render),
            count($rest) === 2 && $rest[0] === 'relations' && isset($blueprint->relations[$rest[1]]) => $this->relation($request, $blueprint, $blueprint->relations[$rest[1]], $render),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function create(Request $request, Closure $render): Response
    {
        $form = ['name' => '', 'label' => '', 'capabilities' => [BlueprintDefinition::REQUIRED_CAPABILITY, 'textual', 'routable', 'publishable']];
        $context = ['title' => 'blueprints.new', 'capabilities' => $this->offeredCapabilities()];
        if (!$request->isPost()) {
            return $render('blueprint_new', 'blueprints', $context + ['form' => $form, 'errors' => []]);
        }
        $form = [
            'name' => trim($request->postString('name')),
            'label' => trim($request->postString('label')),
            'capabilities' => $this->postedCapabilities($request),
        ];
        $errors = [];
        if (!$this->csrf->isValid($request)) {
            return self::status($render('blueprint_new', 'blueprints', $context + ['form' => $form, 'errors' => [], 'alert' => 'auth.form_expired']), 400);
        }
        $name = $form['name'];
        try {
            BlueprintDefinition::name($name);
        } catch (\InvalidArgumentException) {
            $errors['name'] = new Message('blueprints.invalid_name');
        }
        if (!isset($errors['name']) && ($this->blueprints->find($name) !== null || in_array($name, BlueprintRegistry::RESERVED_NAMES, true)
            || $this->objectCount($name) > 0 || isset($this->blueprints->storedErrors()[$name]))) {
            $errors['name'] = new Message('blueprints.name_taken');
        }
        if ($form['label'] === '' || mb_strlen($form['label'], 'UTF-8') > BlueprintDefinition::MAX_LABEL) {
            $errors['label'] = new Message('validation.required');
        }
        if ($errors === []) {
            $definition = ['version' => BlueprintDefinition::VERSION, 'label' => $form['label'], 'capabilities' => $form['capabilities'], 'fields' => [], 'relations' => []];
            $error = $this->commit($name, $definition);
            if ($error === null) {
                $this->flash->add(Flash::SUCCESS, new Message('blueprints.created', ['label' => $form['label']]));

                return $this->redirect($request, $name);
            }
            $context['alert_text'] = $error;
        }

        return self::status($render('blueprint_new', 'blueprints', $context + ['form' => $form, 'errors' => $errors]), 422);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function edit(Request $request, Blueprint $blueprint, Closure $render): Response
    {
        $stored = $this->stored($blueprint->name);
        $form = ['label' => $blueprint->label, 'capabilities' => array_keys($blueprint->capabilities)];
        $errors = [];
        $alert = null;
        if ($request->isPost()) {
            $form = ['label' => trim($request->postString('label')), 'capabilities' => $this->postedCapabilities($request)];
            if (!$this->csrf->isValid($request)) {
                $alert = $this->translator->translate('auth.form_expired');
            } elseif ($form['label'] === '' || mb_strlen($form['label'], 'UTF-8') > BlueprintDefinition::MAX_LABEL) {
                $errors['label'] = new Message('validation.required');
            } else {
                $alert = $this->commit($blueprint->name, ['label' => $form['label'], 'capabilities' => $form['capabilities']] + $stored);
                if ($alert === null) {
                    $this->flash->add(Flash::SUCCESS, new Message('blueprints.saved', ['label' => $form['label']]));

                    return $this->redirect($request, $blueprint->name);
                }
            }
        }
        $count = $this->objectCount($blueprint->name);
        $response = $render('blueprint_edit', 'blueprints', [
            'title' => 'blueprints.edit',
            'blueprint' => [
                'name' => $blueprint->name,
                'label' => $blueprint->label,
                'table' => $blueprint->hasTable() ? $blueprint->tableName() : null,
                'count' => $count,
                'list_path' => $this->access->path($blueprint->name),
                'columns' => $blueprint->adminColumns,
            ],
            'max_columns' => BlueprintRegistry::MAX_ADMIN_COLUMNS,
            'form' => $form,
            'errors' => $errors,
            'alert_text' => $alert,
            'capabilities' => $this->offeredCapabilities(),
            'fields' => array_map(static fn (Field $f): array => [
                'name' => $f->name,
                'label' => $f->label,
                'type' => $f->type->value,
                'required' => $f->required,
                'queryable' => $f->storage === FieldStorage::Table,
                'multiple' => $f->isMultiple(),
                'translatable' => $f->translatable,
            ], array_values($blueprint->fields)),
            'relations' => array_map(static fn (Relation $r): array => [
                'name' => $r->name,
                'label' => $r->label,
                'targets' => $r->targetBlueprints,
                'many' => $r->isMany(),
                'required' => $r->required,
            ], array_values($blueprint->relations)),
        ]);

        return $request->isPost() ? self::status($response, 422) : $response;
    }

    /**
     * A new field, or the safe changes of one.
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function field(Request $request, Blueprint $blueprint, ?Field $field, Closure $render): Response
    {
        $form = $field === null
            ? ['name' => '', 'label' => '', 'type' => 'string', 'required' => false, 'queryable' => false, 'multiple' => false, 'max' => '', 'translatable' => false, 'length' => '255', 'scale' => '2', 'choices' => '', 'default' => '', 'confirm' => false]
            : self::fieldForm($field);
        $errors = [];
        $alert = null;
        if ($request->isPost()) {
            $form = $this->postedFieldForm($request, $field);
            if (!$this->csrf->isValid($request)) {
                $alert = $this->translator->translate('auth.form_expired');
            } else {
                [$item, $errors] = $this->fieldItem($form, $blueprint, $field);
                if ($errors === []) {
                    $stored = $this->stored($blueprint->name);
                    $fields = $stored['fields'];
                    if ($field === null) {
                        $fields[] = $item;
                    } else {
                        foreach ($fields as $i => $existing) {
                            if (($existing['name'] ?? null) === $field->name) {
                                $fields[$i] = $item;
                            }
                        }
                    }
                    $alert = $this->commit($blueprint->name, ['fields' => $fields] + $stored);
                    if ($alert === null) {
                        $this->flash->add(Flash::SUCCESS, new Message($field === null ? 'blueprints.field_added' : 'blueprints.field_saved', ['label' => $form['label']]));

                        return $this->redirect($request, $blueprint->name);
                    }
                }
            }
        }
        $response = $render('blueprint_field', 'blueprints', [
            'title' => $field === null ? 'blueprints.field_new' : 'blueprints.field_edit',
            'blueprint' => ['name' => $blueprint->name, 'label' => $blueprint->label, 'count' => $this->objectCount($blueprint->name)],
            'field' => $field?->name,
            'form' => $form,
            'errors' => $errors,
            'alert_text' => $alert,
            'types' => $field === null ? self::TYPES : self::typesFor($field),
            'multiple_before' => $field?->isMultiple() ?? false,
            'value_count' => $field === null ? 0 : count($this->objectsWithValue($blueprint, $field)),
        ]);

        return $request->isPost() ? self::status($response, 422) : $response;
    }

    /**
     * The field's stored form from the posted form, checked; or the errors by input name.
     *
     * @param array<string, mixed> $form
     * @return array{0: array<string, mixed>, 1: array<string, Message>}
     */
    private function fieldItem(array $form, Blueprint $blueprint, ?Field $field): array
    {
        $errors = [];
        if ($form['label'] === '' || mb_strlen($form['label'], 'UTF-8') > BlueprintDefinition::MAX_LABEL) {
            $errors['label'] = new Message('validation.required');
        }
        if ($field !== null) {
            // The name never changes; the type only to a wider one (since 0.2.4).
            $item = BlueprintDefinition::fieldArray($field);
            if (!in_array($form['type'], self::typesFor($field), true)) {
                $errors['type'] = new Message('blueprints.type_not_widening');
            }
            $item['type'] = $form['type'];
            $item['queryable'] = $form['queryable'];
        } else {
            $item = ['name' => $form['name'], 'type' => $form['type'], 'queryable' => $form['queryable'], 'translatable' => false];
            try {
                BlueprintDefinition::name($form['name']);
                if (in_array($form['name'], BlueprintRegistry::RESERVED_FIELD_NAMES, true) || isset($blueprint->allFields()[$form['name']])
                    || isset($blueprint->allRelations()[$form['name']]) || $this->capabilities->fieldOwner($form['name']) !== null
                    || $this->capabilities->relationOwner($form['name']) !== null) {
                    $errors['name'] = new Message('blueprints.name_taken');
                }
            } catch (\InvalidArgumentException) {
                $errors['name'] = new Message('blueprints.invalid_name');
            }
            if (!in_array($form['type'], self::TYPES, true)) {
                $errors['type'] = new Message('validation.required');
            }
        }
        $item['cardinality'] = 1;
        if ($form['multiple']) {
            $max = trim((string) $form['max']);
            if ($max === '') {
                $item['cardinality'] = Field::UNLIMITED;
            } elseif (ctype_digit($max) && (int) $max >= 2 && (int) $max <= 100) {
                $item['cardinality'] = (int) $max;
            } else {
                $errors['max'] = new Message('blueprints.invalid_max');
            }
        }
        $length = trim((string) $form['length']);
        $item['length'] = ctype_digit($length) && (int) $length >= 1 && (int) $length <= Field::MAX_MULTI_STRING_LENGTH ? (int) $length : 0;
        if ($item['length'] === 0) {
            $errors['length'] = new Message('blueprints.invalid_length');
        }
        // Several values becoming one keeps only the first of each object: said and confirmed first.
        if ($field !== null && $field->isMultiple() && $item['cardinality'] === 1 && !$form['confirm'] && $this->objectCount($blueprint->name) > 0) {
            $errors['multiple'] = new Message('blueprints.confirm_loss');
        }
        $item['label'] = $form['label'];
        $item['translatable'] = $form['translatable'];
        $scale = trim((string) $form['scale']);
        if (($item['type'] ?? '') === FieldType::Decimal->value) {
            if (ctype_digit($scale) && (int) $scale <= FieldType::DECIMAL_SCALE) {
                $item['scale'] = (int) $scale;
            } else {
                $errors['scale'] = new Message('blueprints.invalid_scale');
            }
        }
        // Choices: one per line, "key | label", or only a label (its key is made from it).
        $choices = [];
        if (($item['type'] ?? '') === FieldType::Choice->value) {
            foreach (preg_split('/\R/', (string) $form['choices']) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                [$key, $label] = str_contains($line, '|') ? array_map(trim(...), explode('|', $line, 2)) : [str_replace('-', '_', Slugger::slugify(trim($line))), trim($line)];
                if (preg_match(BlueprintDefinition::CHOICE_KEY, $key) !== 1 || $label === '' || isset($choices[$key])) {
                    $errors['choices'] = new Message('blueprints.invalid_choices');
                    break;
                }
                $choices[$key] = $label;
            }
            if ($choices === []) {
                $errors['choices'] ??= new Message('blueprints.invalid_choices');
            }
            // The keys in use stay: removing one would make the values that have it invalid.
            if ($field !== null && array_diff_key($field->choices, $choices) !== []) {
                $errors['choices'] = new Message('blueprints.choices_kept');
            }
        }
        $item['choices'] = $choices;
        $queryable = (bool) ($item['queryable'] ?? false);
        // Made required: the SchemaSync gives the empty values the default, or refuses without one.
        $item['required'] = $form['required'];
        $item['default'] = trim((string) $form['default']) === '' ? null : trim((string) $form['default']);
        if (($item['type'] ?? '') === FieldType::Boolean->value) {
            $item['default'] = $item['default'] === null ? null : in_array(mb_strtolower((string) $item['default'], 'UTF-8'), ['1', 'igen', 'yes', 'true'], true);
        }
        if ($errors === []) {
            try {
                $built = BlueprintDefinition::field($item);
                $item = BlueprintDefinition::fieldArray($built);
            } catch (\InvalidArgumentException $e) {
                $errors[str_contains($e->getMessage(), 'default') ? 'default' : 'type'] = new Message('blueprints.invalid_field', ['reason' => $e->getMessage()]);
            }
        }
        // A new required column needs a value for the objects there are already.
        if ($errors === [] && $field === null && $item['required'] && $queryable && $item['cardinality'] === 1
            && $item['default'] === null && $this->objectCount($blueprint->name) > 0) {
            $errors['default'] = new Message('blueprints.needs_default');
        }

        return [$item, $errors];
    }

    /**
     * @return array<string, mixed>
     */
    private function postedFieldForm(Request $request, ?Field $field): array
    {
        $form = [
            'name' => trim($request->postString('name')),
            'label' => trim($request->postString('label')),
            'type' => $request->postString('type'),
            'required' => $request->postString('required') === '1',
            'queryable' => $request->postString('queryable') === '1',
            'multiple' => $request->postString('multiple') === '1',
            'max' => $request->postString('max'),
            'translatable' => $request->postString('translatable') === '1',
            'length' => $request->postString('length'),
            'scale' => $request->postString('scale'),
            'choices' => $request->postString('choices'),
            'default' => $request->postString('default'),
            'confirm' => $request->postString('confirm') === '1',
        ];
        if ($field !== null) {
            $form['name'] = $field->name; // never changed
        }

        return $form;
    }

    /** @return array<string, mixed> The form of an existing field. */
    private static function fieldForm(Field $field): array
    {
        $choices = [];
        foreach ($field->choices as $key => $label) {
            $choices[] = $key . ' | ' . $label;
        }

        return [
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type->value,
            'required' => $field->required,
            'queryable' => $field->storage === FieldStorage::Table,
            'multiple' => $field->isMultiple(),
            'max' => $field->isMultiple() && !$field->isUnlimited() ? (string) $field->cardinality : '',
            'translatable' => $field->translatable,
            'length' => (string) $field->length,
            'scale' => (string) $field->scale,
            'choices' => implode("\n", $choices),
            'confirm' => false,
            'default' => match (true) {
                $field->default === null => '',
                is_bool($field->default) => $field->default ? '1' : '',
                default => (string) $field->type->toStorage($field->default),
            },
        ];
    }

    private function move(Request $request, Blueprint $blueprint, string $name, int $direction): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $this->redirect($request, $blueprint->name);
        }
        $stored = $this->stored($blueprint->name);
        $fields = $stored['fields'];
        $index = array_search($name, array_column($fields, 'name'), true);
        $other = is_int($index) ? $index + $direction : -1;
        if (is_int($index) && isset($fields[$other])) {
            [$fields[$index], $fields[$other]] = [$fields[$other], $fields[$index]];
            $error = $this->commit($blueprint->name, ['fields' => $fields] + $stored);
            if ($error !== null) {
                $this->flash->add(Flash::DANGER, $error);
            }
        }

        return $this->redirect($request, $blueprint->name);
    }

    /**
     * A new relation, or the label and required flag of one.
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function relation(Request $request, Blueprint $blueprint, ?Relation $relation, Closure $render): Response
    {
        $targets = [];
        foreach ($this->blueprints->all() as $name => $target) {
            if (!isset($target->capabilities['authenticatable']) && !isset($target->capabilities['submitted'])) {
                $targets[] = ['name' => $name, 'label' => $target->label];
            }
        }
        $form = $relation === null
            ? ['name' => '', 'label' => '', 'target' => '', 'many' => false, 'required' => false]
            : ['name' => $relation->name, 'label' => $relation->label, 'target' => $relation->targetBlueprints[0] ?? '', 'many' => $relation->isMany(), 'required' => $relation->required];
        $errors = [];
        $alert = null;
        if ($request->isPost()) {
            $form['label'] = trim($request->postString('label'));
            $form['required'] = $request->postString('required') === '1';
            if ($relation === null) {
                $form['name'] = trim($request->postString('name'));
                $form['target'] = $request->postString('target');
                $form['many'] = $request->postString('many') === '1';
            }
            if (!$this->csrf->isValid($request)) {
                $alert = $this->translator->translate('auth.form_expired');
            } else {
                if ($form['label'] === '' || mb_strlen($form['label'], 'UTF-8') > BlueprintDefinition::MAX_LABEL) {
                    $errors['label'] = new Message('validation.required');
                }
                if ($relation === null) {
                    try {
                        BlueprintDefinition::name($form['name']);
                        if (isset($blueprint->allFields()[$form['name']]) || isset($blueprint->allRelations()[$form['name']])
                            || in_array($form['name'], BlueprintRegistry::RESERVED_FIELD_NAMES, true)
                            || $this->capabilities->fieldOwner($form['name']) !== null || $this->capabilities->relationOwner($form['name']) !== null) {
                            $errors['name'] = new Message('blueprints.name_taken');
                        }
                    } catch (\InvalidArgumentException) {
                        $errors['name'] = new Message('blueprints.invalid_name');
                    }
                    if (!in_array($form['target'], array_column($targets, 'name'), true)) {
                        $errors['target'] = new Message('validation.required');
                    }
                }
                if ($errors === []) {
                    $item = ['name' => $form['name'], 'label' => $form['label'], 'targets' => $relation !== null ? $relation->targetBlueprints : [$form['target']], 'many' => $form['many'], 'required' => $form['required']];
                    $stored = $this->stored($blueprint->name);
                    $relations = $stored['relations'];
                    if ($relation === null) {
                        $relations[] = $item;
                    } else {
                        foreach ($relations as $i => $existing) {
                            if (($existing['name'] ?? null) === $relation->name) {
                                $relations[$i] = $item;
                            }
                        }
                    }
                    $alert = $this->commit($blueprint->name, ['relations' => $relations] + $stored);
                    if ($alert === null) {
                        $this->flash->add(Flash::SUCCESS, new Message($relation === null ? 'blueprints.relation_added' : 'blueprints.relation_saved', ['label' => $form['label']]));

                        return $this->redirect($request, $blueprint->name);
                    }
                }
            }
        }
        $response = $render('blueprint_relation', 'blueprints', [
            'title' => $relation === null ? 'blueprints.relation_new' : 'blueprints.relation_edit',
            'blueprint' => ['name' => $blueprint->name, 'label' => $blueprint->label],
            'relation' => $relation?->name,
            'form' => $form,
            'errors' => $errors,
            'alert_text' => $alert,
            'targets' => $targets,
            'link_count' => $relation === null ? 0 : $this->linkCount($blueprint, $relation),
        ]);

        return $request->isPost() ? self::status($response, 422) : $response;
    }

    /**
     * POST: deletes a field (since 0.2.4), confirmed: a backup first if any object has
     * a value, then the definition without it, then its values (its column, its rows in
     * field_values, its key in the JSON data; the table itself if nothing else is left).
     */
    private function deleteField(Request $request, Blueprint $blueprint, Field $field): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $back = Response::redirect($request->basePath . $this->access->path('system/blueprints/' . $blueprint->name . '/fields/' . $field->name), 303);
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $back;
        }
        if ($request->postString('confirm') !== '1') {
            $this->flash->add(Flash::DANGER, 'blueprints.delete_confirm_needed');

            return $back;
        }
        $error = $this->backupIf($this->objectsWithValue($blueprint, $field) !== []);
        if ($error === null) {
            $stored = $this->stored($blueprint->name);
            $fields = array_values(array_filter($stored['fields'], static fn (array $item): bool => ($item['name'] ?? null) !== $field->name));
            $columns = array_values(array_diff($stored['admin_columns'], [$field->name]));
            $error = $this->commit($blueprint->name, ['fields' => $fields, 'admin_columns' => $columns] + $stored);
        }
        if ($error !== null) {
            $this->flash->add(Flash::DANGER, $error);

            return $back;
        }
        try {
            $this->purgeField($blueprint, $field);
        } catch (\Throwable $e) {
            error_log('Campanella: the values of the deleted field ' . $blueprint->name . '.' . $field->name . ' could not be deleted: ' . $e);
            $this->flash->add(Flash::WARNING, new Message('blueprints.purge_failed', ['error' => $e->getMessage()]));
        }
        $this->flash->add(Flash::SUCCESS, new Message('blueprints.field_deleted', ['label' => $field->label]));

        return $this->redirect($request, $blueprint->name);
    }

    /** POST: deletes a relation (since 0.2.4), confirmed: a backup first if it has links, then the links of the Blueprint's objects. */
    private function deleteRelation(Request $request, Blueprint $blueprint, Relation $relation): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $back = Response::redirect($request->basePath . $this->access->path('system/blueprints/' . $blueprint->name . '/relations/' . $relation->name), 303);
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $back;
        }
        if ($request->postString('confirm') !== '1') {
            $this->flash->add(Flash::DANGER, 'blueprints.delete_confirm_needed');

            return $back;
        }
        $error = $this->backupIf($this->linkCount($blueprint, $relation) > 0);
        if ($error === null) {
            $stored = $this->stored($blueprint->name);
            $relations = array_values(array_filter($stored['relations'], static fn (array $item): bool => ($item['name'] ?? null) !== $relation->name));
            $columns = array_values(array_diff($stored['admin_columns'], [$relation->name]));
            $error = $this->commit($blueprint->name, ['relations' => $relations, 'admin_columns' => $columns] + $stored);
        }
        if ($error !== null) {
            $this->flash->add(Flash::DANGER, $error);

            return $back;
        }
        $this->db->execute(
            'DELETE r FROM {relationships} r JOIN {objects} o ON o.id = r.source_id WHERE o.blueprint = :b AND r.type = :t',
            ['b' => $blueprint->name, 't' => $relation->name],
        );
        $this->flash->add(Flash::SUCCESS, new Message('blueprints.relation_deleted', ['label' => $relation->label]));

        return $this->redirect($request, $blueprint->name);
    }

    /** A backup if there is something to lose; the reason it failed, or null. */
    private function backupIf(bool $needed): ?string
    {
        if (!$needed) {
            return null;
        }
        try {
            $file = $this->backup->create();
            $this->flash->add(Flash::INFO, new Message('blueprints.backup_made', ['file' => basename($file)]));
        } catch (\RuntimeException $e) {
            return $this->translator->translate('blueprints.backup_failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * The Blueprint's objects that have a value of the field, wherever it is stored.
     *
     * @return array<int, true>
     */
    private function objectsWithValue(Blueprint $blueprint, Field $field): array
    {
        $ids = [];
        $info = (new SchemaReader($this->db))->read($blueprint->tableName());
        if ($info?->column($field->name) !== null) {
            foreach ($this->db->fetchColumn(sprintf('SELECT object_id FROM %s WHERE %s IS NOT NULL', $this->db->table($blueprint->tableName()), Connection::quoteIdentifier($field->name))) as $id) {
                $ids[(int) $id] = true;
            }
        }
        foreach ($this->db->fetchColumn('SELECT DISTINCT fv.object_id FROM {field_values} fv JOIN {objects} o ON o.id = fv.object_id WHERE o.blueprint = :b AND fv.field = :f', ['b' => $blueprint->name, 'f' => $field->name]) as $id) {
            $ids[(int) $id] = true;
        }
        foreach ($this->objectsWithDataKey($blueprint->name, $field->name) as $id => $data) {
            if (!$field->isEmpty($data[$field->name])) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * The Blueprint's objects whose JSON data has the key.
     *
     * @return array<int, array<string, mixed>>
     */
    private function objectsWithDataKey(string $blueprint, string $key): array
    {
        $found = [];
        $rows = $this->db->fetchAll('SELECT id, data FROM {objects} WHERE blueprint = :b AND data LIKE :k', ['b' => $blueprint, 'k' => '%"' . $key . '":%']);
        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            if (is_array($data) && array_key_exists($key, $data)) {
                $found[(int) $row['id']] = $data;
            }
        }

        return $found;
    }

    /** Deletes a deleted field's values: its column (and the table, if nothing else is left), its rows, its JSON key. */
    private function purgeField(Blueprint $blueprint, Field $field): void
    {
        $table = $blueprint->tableName();
        $info = (new SchemaReader($this->db))->read($table);
        if ($info?->column($field->name) !== null) {
            if (array_keys($info->columns) === ['object_id', $field->name]) {
                $this->db->execute('DROP TABLE ' . $this->db->table($table));
            } else {
                $this->db->execute((new \Campanella\Database\Schema\SchemaBuilder($this->db))->dropColumnSql($table, $field->name));
            }
        }
        $this->db->execute(
            'DELETE fv FROM {field_values} fv JOIN {objects} o ON o.id = fv.object_id WHERE o.blueprint = :b AND fv.field = :f',
            ['b' => $blueprint->name, 'f' => $field->name],
        );
        foreach ($this->objectsWithDataKey($blueprint->name, $field->name) as $id => $data) {
            unset($data[$field->name]);
            $this->db->update('objects', ['data' => json_encode($data === [] ? new \stdClass() : $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
        }
    }

    /** The links of the Blueprint's objects through the relation. */
    private function linkCount(Blueprint $blueprint, Relation $relation): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM {relationships} r JOIN {objects} o ON o.id = r.source_id WHERE o.blueprint = :b AND r.type = :t',
            ['b' => $blueprint->name, 't' => $relation->name],
        );
    }

    /**
     * POST: the columns of the Blueprint's list in the admin (since 0.2.8): at most
     * BlueprintRegistry::MAX_ADMIN_COLUMNS of its fields and relations, in the order chosen
     * (an empty choice and a repeated one are left out).
     */
    private function columns(Request $request, Blueprint $blueprint): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $this->redirect($request, $blueprint->name);
        }
        $posted = is_array($request->post['columns'] ?? null) ? $request->post['columns'] : [];
        $names = [...array_keys($blueprint->fields), ...array_keys($blueprint->relations)];
        $columns = [];
        foreach ($posted as $name) {
            if (is_string($name) && in_array($name, $names, true) && !in_array($name, $columns, true)) {
                $columns[] = $name;
            }
        }
        $stored = $this->stored($blueprint->name);
        $error = $this->commit($blueprint->name, ['admin_columns' => array_slice($columns, 0, BlueprintRegistry::MAX_ADMIN_COLUMNS)] + $stored);
        $this->flash->add($error === null ? Flash::SUCCESS : Flash::DANGER, $error ?? new Message('blueprints.columns_saved'));

        return $this->redirect($request, $blueprint->name);
    }

    /** POST: deletes a Blueprint that has no objects (its empty table too). */
    private function delete(Request $request, Blueprint $blueprint): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $this->redirect($request, $blueprint->name);
        }
        if ($this->objectCount($blueprint->name) > 0) {
            $this->flash->add(Flash::DANGER, 'blueprints.delete_has_objects');

            return $this->redirect($request, $blueprint->name);
        }
        foreach ($this->blueprints->all() as $other) {
            foreach ($other->relations as $relation) {
                if ($other->name !== $blueprint->name && in_array($blueprint->name, $relation->targetBlueprints, true)) {
                    $this->flash->add(Flash::DANGER, new Message('blueprints.delete_is_target', ['blueprint' => $other->label]));

                    return $this->redirect($request, $blueprint->name);
                }
            }
        }
        $error = $this->commit($blueprint->name, null);
        if ($error !== null) {
            $this->flash->add(Flash::DANGER, $error);

            return $this->redirect($request, $blueprint->name);
        }
        $this->flash->add(Flash::SUCCESS, new Message('blueprints.deleted', ['label' => $blueprint->label]));

        return Response::redirect($request->basePath . $this->access->path('system/blueprints'), 303);
    }

    /**
     * Checks, backs up if needed, saves and applies a Blueprint's new definition
     * (null: deletes it).
     *
     * @param array<string, mixed>|null $definition
     * @return string|null The reason it was not done (translated), or null when it was
     */
    private function commit(string $name, ?array $definition): ?string
    {
        if ($this->installer->needsUpgrade()) {
            return $this->translator->translate('blueprints.needs_upgrade');
        }
        $stored = $this->store->load();
        $previous = $stored[$name] ?? null;
        if ($definition === null) {
            unset($stored[$name]);
        } else {
            $stored[$name] = ['version' => BlueprintDefinition::VERSION] + $definition;
        }

        // Every Blueprint defined again, with the change.
        $registry = new BlueprintRegistry($this->capabilities, ($this->codeBlueprints)());
        $registry->loadStored($stored);
        if (isset($registry->storedErrors()[$name])) {
            return $this->translator->translate('blueprints.invalid', ['reason' => $registry->storedErrors()[$name]]);
        }
        $sync = new SchemaSync($this->db, new Installer($this->db, $this->capabilities, $this->migrator, $registry), $this->capabilities, $registry);
        $plan = $sync->plan();
        $table = 'bp_' . $name;
        foreach ($plan->blocked() as $step) {
            if (($step->message->params['blueprint'] ?? null) === $name || ($step->message->params['table'] ?? null) === $table) {
                return $step->message->translate($this->translator);
            }
        }

        // A backup first, if the database changes and there is data to lose.
        if ($plan->hasWork() && ($this->objectCount($name) > 0 || $plan->changesData())) {
            try {
                $file = $this->backup->create();
                $this->flash->add(Flash::INFO, new Message('blueprints.backup_made', ['file' => basename($file)]));
            } catch (\RuntimeException $e) {
                return $this->translator->translate('blueprints.backup_failed', ['error' => $e->getMessage()]);
            }
        }

        if ($definition === null) {
            $this->store->delete($name);
        } else {
            $this->store->save($name, $stored[$name]);
        }
        try {
            $sync->apply();
        } catch (\Throwable $e) {
            error_log('Campanella: the Blueprint ' . $name . ' could not be applied: ' . $e);
            if ($previous === null) {
                $this->store->delete($name);
            } else {
                $this->store->save($name, $previous);
            }

            return $this->translator->translate('blueprints.apply_failed', ['error' => $e->getMessage()]);
        }
        if ($definition === null) {
            $this->dropEmptyTable($table);
        }

        return null;
    }

    /** A deleted Blueprint's own table, if it has no rows (it cannot have: it had no objects). */
    private function dropEmptyTable(string $table): void
    {
        if ((new SchemaReader($this->db))->tableExists($table)
            && $this->db->fetchValue(sprintf('SELECT 1 FROM %s LIMIT 1', $this->db->table($table))) === null) {
            $this->db->execute('DROP TABLE ' . $this->db->table($table));
        }
    }

    /**
     * The Blueprint's stored definition, with its lists.
     *
     * @return array<string, mixed>&array{fields: list<array<string, mixed>>, relations: list<array<string, mixed>>, admin_columns: list<string>}
     */
    private function stored(string $name): array
    {
        $stored = $this->store->find($name) ?? throw HttpException::notFound();
        $stored['fields'] = array_values(array_filter(is_array($stored['fields'] ?? null) ? $stored['fields'] : [], is_array(...)));
        $stored['relations'] = array_values(array_filter(is_array($stored['relations'] ?? null) ? $stored['relations'] : [], is_array(...)));
        $stored['admin_columns'] = array_values(array_filter(is_array($stored['admin_columns'] ?? null) ? $stored['admin_columns'] : [], is_string(...)));

        return $stored;
    }

    /**
     * The capabilities a Blueprint made here may have.
     *
     * @return list<array{name: string, label: string, requires: list<string>, required: bool}>
     */
    private function offeredCapabilities(): array
    {
        $offered = [];
        foreach ($this->capabilities->all() as $definition) {
            if (in_array($definition->name, BlueprintDefinition::SYSTEM_CAPABILITIES, true)) {
                continue;
            }
            $offered[] = [
                'name' => $definition->name,
                'label' => $definition->label,
                'requires' => array_map(fn (string $class): string => $this->capabilities->get($class)->name, $definition->requires),
                'required' => $definition->name === BlueprintDefinition::REQUIRED_CAPABILITY,
            ];
        }

        return $offered;
    }

    /** @return list<string> */
    private function postedCapabilities(Request $request): array
    {
        $offered = array_column($this->offeredCapabilities(), 'name');
        $posted = is_array($request->post['capabilities'] ?? null) ? $request->post['capabilities'] : [];
        $names = [BlueprintDefinition::REQUIRED_CAPABILITY];
        foreach ($offered as $name) {
            if (in_array($name, $posted, true) && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function objectCount(string $blueprint): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM {objects} WHERE blueprint = :b', ['b' => $blueprint]);
    }

    private function redirect(Request $request, string $name): Response
    {
        return Response::redirect($request->basePath . $this->access->path('system/blueprints/' . $name), 303);
    }

    private static function status(Response $response, int $status): Response
    {
        return new Response($response->body, $status, $response->headers);
    }
}
