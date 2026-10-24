<?php

declare(strict_types=1);

namespace Campanella\Transfer;

use Campanella\Admin\BlueprintEditor;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Installer;
use Campanella\Database\Migration\Migrator;
use Campanella\Database\Sync\SchemaSync;
use Campanella\Database\Sync\SyncStep;
use Campanella\I18n\Message;
use Campanella\Lists\ListDefinition;
use Campanella\Lists\ListPathCheck;
use Campanella\Lists\ListStore;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintDefinition;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\BlueprintStore;
use Campanella\Model\Field;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * The content model's import (since 0.2.6): first a plan of what it would do
 * (plan()), then, if the plan has no errors, all of it (apply()).
 *
 * The rules are those of the admin's editors:
 * - A Blueprint of the file that the site does not have is made; one it has (made
 *   in the admin) gets the file's label, capabilities, fields and relations, by
 *   the editor's safe changes only: a field's type only to a wider one, its choices
 *   in use kept, several values not made one while there are objects, a new
 *   required queryable field with a default if there are objects, a relation's
 *   targets and number unchanged. Anything else is an error.
 * - A Blueprint of config/blueprints.php cannot be imported (it comes with the code).
 * - A list is made or replaced, checked like in the editor, its path too; its
 *   relation conditions find their objects by reference (ObjectReferences).
 * - Nothing is deleted: what the site has and the file does not stays as it is, and
 *   so do the fields and relations of a Blueprint that the file does not have.
 *
 * Applying it: a backup first if the database changes and there are objects (or
 * values move), then the definitions are saved and the SchemaSync applies them;
 * if that fails, the previous definitions are put back.
 */
final class ModelImport
{
    /** The field properties compared, in the order they are reported. */
    private const array FIELD_PROPERTIES = ['type', 'label', 'required', 'queryable', 'cardinality', 'translatable', 'length', 'scale', 'choices', 'default'];

    /** The list keys compared, in the order they are reported. */
    private const array LIST_PROPERTIES = ['title', 'blueprint', 'conditions', 'sort', 'per_page', 'display', 'columns', 'path', 'filters'];

    /**
     * @param Closure(): array<string, array{label?: string, capabilities: list<string>}> $codeBlueprints The definitions of config/blueprints.php
     */
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly BlueprintStore $blueprintStore,
        private readonly ListStore $listStore,
        private readonly Connection $db,
        private readonly Closure $codeBlueprints,
        private readonly Installer $installer,
        private readonly ?Migrator $migrator,
        private readonly DatabaseBackup $backup,
        private readonly ListPathCheck $paths,
        private readonly ObjectReferences $references,
    ) {
    }

    /**
     * What importing the file's model would do.
     *
     * @param array{blueprints: array<string, array<string, mixed>>, lists: array<string, array<string, mixed>>} $model ModelFile::decode()
     */
    public function plan(array $model): ImportPlan
    {
        $errors = [];
        if ($this->installer->needsUpgrade()) {
            $errors[] = new Message('transfer.error.needs_upgrade');
        }
        $code = ($this->codeBlueprints)();
        $storedBlueprints = $this->blueprintStore->load();
        $storedLists = $this->listStore->load();
        $counts = [];
        foreach ($this->db->fetchAll('SELECT blueprint, COUNT(*) AS n FROM {objects} GROUP BY blueprint') as $row) {
            $counts[(string) $row['blueprint']] = (int) $row['n'];
        }
        $current = new BlueprintRegistry($this->capabilities, $code);
        $current->loadStored($storedBlueprints);

        /** @var array<string, ImportItem> $items */
        $items = [];
        $save = [];
        foreach ($model['blueprints'] as $name => $incoming) {
            $name = (string) $name;
            [$item, $definition] = $this->blueprintItem($name, $incoming, $code, $current, $storedBlueprints, $counts[$name] ?? 0);
            $items['blueprint:' . $name] = $item;
            if ($definition !== null && $item->action !== ImportItem::UNCHANGED) {
                $save[$name] = $definition;
            }
        }

        // Every Blueprint defined again, with the file's.
        $next = new BlueprintRegistry($this->capabilities, $code);
        $next->loadStored(array_replace($storedBlueprints, $save));
        foreach ($save as $name => $definition) {
            if (isset($next->storedErrors()[$name])) {
                $items['blueprint:' . $name] = self::failed($items['blueprint:' . $name], new Message('transfer.error.invalid', ['reason' => $next->storedErrors()[$name]]));
            }
        }

        $sync = null;
        $database = [];
        $backup = false;
        if ($errors === [] && $save !== []) {
            $sync = new SchemaSync($this->db, new Installer($this->db, $this->capabilities, $this->migrator, $next), $this->capabilities, $next);
            $steps = $sync->plan();
            foreach ($steps->blocked() as $step) {
                $name = self::stepBlueprint($step, array_keys($save));
                if ($name !== null) {
                    $items['blueprint:' . $name] = self::failed($items['blueprint:' . $name], $step->message);
                }
            }
            $database = array_map(static fn (SyncStep $s): Message => $s->message, $steps->work());
            $backup = $steps->hasWork() && ($steps->changesData() || array_filter(array_keys($save), static fn (string $n): bool => ($counts[$n] ?? 0) > 0) !== []);
        }

        // The lists, against the Blueprints as they would be.
        $lists = [];
        $paths = [];
        foreach ($model['lists'] as $name => $incoming) {
            $name = (string) $name;
            [$item, $definition] = $this->listItem($name, $incoming, $next, $current, $storedLists, $paths);
            $items['list:' . $name] = $item;
            if ($definition !== null && $item->action !== ImportItem::UNCHANGED) {
                $lists[$name] = $definition;
            }
        }

        // What the site has and the file does not: it stays.
        foreach ($storedBlueprints as $name => $definition) {
            if (!isset($model['blueprints'][$name])) {
                $items['blueprint:' . $name] = new ImportItem(ImportItem::BLUEPRINT, (string) $name, $current->find((string) $name)->label ?? (string) $name, ImportItem::KEPT);
            }
        }
        foreach ($storedLists as $name => $definition) {
            if (isset($model['lists'][$name])) {
                continue;
            }
            $warnings = [];
            try {
                ListDefinition::fromArray((string) $name, $definition, $current);
                try {
                    ListDefinition::fromArray((string) $name, $definition, $next);
                } catch (InvalidArgumentException $e) {
                    $warnings[] = new Message('transfer.warning.list_breaks', ['reason' => $e->getMessage()]);
                }
            } catch (InvalidArgumentException) {
                // Not loaded now either: the lists' page says why.
            }
            $items['list:' . $name] = new ImportItem(ImportItem::LIST, (string) $name, is_string($definition['title'] ?? null) ? $definition['title'] : (string) $name, ImportItem::KEPT, warnings: $warnings);
        }

        $fingerprint = hash('sha256', json_encode([$storedBlueprints, $storedLists, $save, $lists], JSON_THROW_ON_ERROR));

        return new ImportPlan(array_values($items), $errors, $database, $backup, $save, $lists, $fingerprint, $sync);
    }

    /**
     * Does all of a plan without errors. A backup first, if the plan says so.
     *
     * @return string|null The backup's file, if one was made
     * @throws RuntimeException if the plan has errors, or something failed (nothing changed then)
     */
    public function apply(ImportPlan $plan): ?string
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('The import has errors; nothing was changed.');
        }
        if ($this->installer->needsUpgrade()) {
            throw new RuntimeException('The database needs an upgrade first; nothing was changed.');
        }
        $file = null;
        if ($plan->backup) {
            $file = $this->backup->create();
        }
        if ($plan->blueprints !== []) {
            $previous = $this->blueprintStore->load();
            foreach ($plan->blueprints as $name => $definition) {
                $this->blueprintStore->save($name, $definition);
            }
            try {
                $plan->sync?->apply();
            } catch (\Throwable $e) {
                error_log('Campanella: the imported content model could not be applied: ' . $e);
                foreach (array_keys($plan->blueprints) as $name) {
                    if (isset($previous[$name])) {
                        $this->blueprintStore->save($name, $previous[$name]);
                    } else {
                        $this->blueprintStore->delete($name);
                    }
                }

                throw new RuntimeException('The database could not be changed: ' . $e->getMessage() . ' The previous definitions were put back.', 0, $e);
            }
        }
        foreach ($plan->lists as $name => $definition) {
            $this->listStore->save($name, $definition);
        }

        return $file;
    }

    /**
     * A Blueprint of the file: its line, and its definition to save (null if it cannot be).
     *
     * @param array<string, mixed> $incoming
     * @param array<string, array{label?: string, capabilities: list<string>}> $code
     * @param array<string, array<string, mixed>> $stored
     * @return array{0: ImportItem, 1: array<string, mixed>|null}
     */
    private function blueprintItem(string $name, array $incoming, array $code, BlueprintRegistry $current, array $stored, int $count): array
    {
        $label = is_string($incoming['label'] ?? null) ? mb_substr($incoming['label'], 0, BlueprintDefinition::MAX_LABEL) : $name;
        $error = static fn (Message $m): array => [new ImportItem(ImportItem::BLUEPRINT, $name, $label, ImportItem::ERROR, error: $m), null];
        try {
            BlueprintDefinition::name($name);
        } catch (InvalidArgumentException) {
            return $error(new Message('transfer.error.name', ['name' => mb_substr($name, 0, 64)]));
        }
        if (isset($code[$name]) || in_array($name, BlueprintRegistry::RESERVED_NAMES, true)) {
            return $error(new Message('transfer.error.code_blueprint'));
        }
        if (isset($current->storedErrors()[$name])) {
            return $error(new Message('transfer.error.stored_invalid', ['reason' => $current->storedErrors()[$name]]));
        }
        try {
            $definition = self::normalize($incoming);
        } catch (InvalidArgumentException $e) {
            return $error(new Message('transfer.error.invalid', ['reason' => $e->getMessage()]));
        }
        $label = $definition['label'];
        if (!isset($stored[$name])) {
            if ($count > 0) {
                return $error(new Message('transfer.error.orphan_objects', ['count' => $count]));
            }

            return [new ImportItem(ImportItem::BLUEPRINT, $name, $label, ImportItem::CREATE, [new Message('transfer.change.created', [
                'fields' => count($definition['fields']),
                'relations' => count($definition['relations']),
            ])]), $definition];
        }

        $before = self::normalize($stored[$name]);
        [$merged, $changes, $warnings, $problem] = self::merge($before, $definition, $current->get($name), $count);
        if ($problem !== null) {
            return [new ImportItem(ImportItem::BLUEPRINT, $name, $label, ImportItem::ERROR, $changes, $warnings, $problem), null];
        }

        return [new ImportItem(ImportItem::BLUEPRINT, $name, $label, $changes === [] ? ImportItem::UNCHANGED : ImportItem::UPDATE, $changes, $warnings), $merged];
    }

    /**
     * A stored or imported definition in one form: each item through BlueprintDefinition.
     *
     * @param array<string, mixed> $definition
     * @return array{version: int, label: string, capabilities: list<string>, fields: list<array<string, mixed>>, relations: list<array<string, mixed>>, admin_columns: list<string>}
     * @throws InvalidArgumentException
     */
    private static function normalize(array $definition): array
    {
        $checked = BlueprintDefinition::toRegistry($definition); // checks it all

        return [
            'version' => BlueprintDefinition::VERSION,
            'label' => $checked['label'],
            'capabilities' => $checked['capabilities'],
            'fields' => array_map(static fn (array $item): array => BlueprintDefinition::fieldArray(BlueprintDefinition::field($item)), array_values(array_filter((array) ($definition['fields'] ?? []), is_array(...)))),
            'relations' => array_map(static fn (array $item): array => BlueprintDefinition::relationArray(BlueprintDefinition::relation($item)), array_values(array_filter((array) ($definition['relations'] ?? []), is_array(...)))),
            'admin_columns' => $checked['admin_columns'],
        ];
    }

    /**
     * The site's definition with the file's, by the editor's rules.
     *
     * @param array{version: int, label: string, capabilities: list<string>, fields: list<array<string, mixed>>, relations: list<array<string, mixed>>, admin_columns: list<string>} $before
     * @param array{version: int, label: string, capabilities: list<string>, fields: list<array<string, mixed>>, relations: list<array<string, mixed>>, admin_columns: list<string>} $file
     * @return array{0: array<string, mixed>, 1: list<Message>, 2: list<Message>, 3: Message|null}
     */
    private static function merge(array $before, array $file, Blueprint $blueprint, int $count): array
    {
        $changes = [];
        $warnings = [];
        if ($before['label'] !== $file['label']) {
            $changes[] = new Message('transfer.change.label', ['from' => $before['label'], 'to' => $file['label']]);
        }
        foreach (array_diff($file['capabilities'], $before['capabilities']) as $capability) {
            $changes[] = new Message('transfer.change.capability_added', ['capability' => $capability]);
        }
        foreach (array_diff($before['capabilities'], $file['capabilities']) as $capability) {
            $changes[] = new Message('transfer.change.capability_removed', ['capability' => $capability]);
        }

        $old = array_column($before['fields'], null, 'name');
        $fields = [];
        foreach ($file['fields'] as $item) {
            $name = (string) $item['name'];
            $existing = $old[$name] ?? null;
            if ($existing === null) {
                if ($item['required'] && $item['queryable'] && $item['cardinality'] === 1 && $item['default'] === null && $count > 0) {
                    return [[], $changes, $warnings, new Message('transfer.error.needs_default', ['field' => $name])];
                }
                $changes[] = new Message('transfer.change.field_added', ['field' => $name]);
                $fields[] = $item;
                continue;
            }
            $field = $blueprint->fields[$name] ?? null;
            if ($existing['type'] !== $item['type'] && !in_array($item['type'], BlueprintEditor::WIDER_TYPES[$existing['type']] ?? [], true)) {
                return [[], $changes, $warnings, new Message('transfer.error.type_narrower', ['field' => $name, 'from' => (string) $existing['type'], 'to' => (string) $item['type']])];
            }
            if (array_diff(array_column($existing['choices'], 'key'), array_column($item['choices'], 'key')) !== []) {
                return [[], $changes, $warnings, new Message('transfer.error.choices_removed', ['field' => $name])];
            }
            if ($field instanceof Field && $field->isMultiple() && $item['cardinality'] === 1 && $count > 0) {
                return [[], $changes, $warnings, new Message('transfer.error.multiple_to_single', ['field' => $name])];
            }
            foreach (self::FIELD_PROPERTIES as $property) {
                if ($existing[$property] !== $item[$property]) {
                    $changes[] = new Message('transfer.change.field.' . $property, ['field' => $name]);
                }
            }
            $fields[] = $item;
        }
        $order = array_values(array_intersect(array_keys($old), array_column($file['fields'], 'name')));
        if ($order !== array_values(array_intersect(array_column($file['fields'], 'name'), array_keys($old)))) {
            $changes[] = new Message('transfer.change.field_order');
        }
        foreach ($before['fields'] as $item) {
            if (!in_array($item['name'], array_column($file['fields'], 'name'), true)) {
                $warnings[] = new Message('transfer.warning.field_kept', ['field' => (string) $item['name']]);
                $fields[] = $item;
            }
        }

        $oldRelations = array_column($before['relations'], null, 'name');
        $relations = [];
        foreach ($file['relations'] as $item) {
            $name = (string) $item['name'];
            $existing = $oldRelations[$name] ?? null;
            if ($existing === null) {
                $changes[] = new Message('transfer.change.relation_added', ['relation' => $name]);
            } elseif ($existing['targets'] !== $item['targets'] || $existing['many'] !== $item['many']) {
                return [[], $changes, $warnings, new Message('transfer.error.relation_changed', ['relation' => $name])];
            } elseif ($existing !== $item) {
                $changes[] = new Message('transfer.change.relation', ['relation' => $name]);
            }
            $relations[] = $item;
        }
        foreach ($before['relations'] as $item) {
            if (!in_array($item['name'], array_column($file['relations'], 'name'), true)) {
                $warnings[] = new Message('transfer.warning.relation_kept', ['relation' => (string) $item['name']]);
                $relations[] = $item;
            }
        }

        // The list's columns in the admin (since 0.2.8): the file's, of the fields and relations there are.
        $names = [...array_column($fields, 'name'), ...array_column($relations, 'name')];
        $columns = array_values(array_filter($file['admin_columns'], static fn (string $c): bool => in_array($c, $names, true)));
        if ($columns !== $before['admin_columns']) {
            $changes[] = new Message('transfer.change.admin_columns');
        }

        return [['fields' => $fields, 'relations' => $relations, 'admin_columns' => $columns] + $file, $changes, $warnings, null];
    }

    /**
     * A list of the file: its line, and its definition to save (null if it cannot be).
     *
     * @param array<string, mixed> $incoming
     * @param array<string, array<string, mixed>> $stored
     * @param array<string, string> $paths The paths of the file's lists so far, path => list
     * @return array{0: ImportItem, 1: array<string, mixed>|null}
     */
    private function listItem(string $name, array $incoming, BlueprintRegistry $next, BlueprintRegistry $current, array $stored, array &$paths): array
    {
        $title = is_string($incoming['title'] ?? null) ? mb_substr($incoming['title'], 0, ListDefinition::MAX_TITLE) : $name;
        $error = static fn (Message $m, ?array $warnings = null): array => [new ImportItem(ImportItem::LIST, $name, $title, ImportItem::ERROR, warnings: array_values(array_filter($warnings ?? [], static fn (mixed $w): bool => $w instanceof Message)), error: $m), null];
        try {
            BlueprintDefinition::name($name);
        } catch (InvalidArgumentException) {
            return $error(new Message('transfer.error.name', ['name' => mb_substr($name, 0, 64)]));
        }

        // Relation conditions: their objects, found by reference.
        $warnings = [];
        $blueprint = $next->find(is_string($incoming['blueprint'] ?? null) ? $incoming['blueprint'] : '');
        $conditions = is_array($incoming['conditions'] ?? null) && array_is_list($incoming['conditions']) ? $incoming['conditions'] : [];
        foreach ($conditions as $i => $condition) {
            if (!is_array($condition) || !isset($condition['relation']) || $blueprint === null) {
                continue;
            }
            $relation = is_string($condition['relation']) ? ($blueprint->allRelations()[$condition['relation']] ?? null) : null;
            if ($relation === null) {
                continue; // ListDefinition says it is unknown
            }
            $ids = [];
            $references = is_array($condition['targets'] ?? null) ? array_slice($condition['targets'], 0, 200) : [];
            foreach ($references as $reference) {
                if (is_int($reference)) {
                    return $error(new Message('transfer.error.list_ids', ['relation' => $relation->name]));
                }
                $id = $this->references->resolve($reference, $relation->targetBlueprints);
                if ($id === null) {
                    $warnings[] = new Message('transfer.warning.target_missing', ['relation' => $relation->name, 'object' => self::describe($reference)]);
                } else {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                return $error(new Message('transfer.error.no_targets', ['relation' => $relation->name]), $warnings);
            }
            $conditions[$i] = ['relation' => $relation->name, 'targets' => $ids];
        }
        $incoming['conditions'] = $conditions;

        try {
            $list = ListDefinition::fromArray($name, $incoming, $next);
        } catch (InvalidArgumentException $e) {
            return $error(new Message('transfer.error.invalid', ['reason' => $e->getMessage()]), $warnings);
        }
        $title = $list->title;
        if ($list->path !== null) {
            if (isset($paths[$list->path]) || $this->paths->isTaken($list->path, $name)) {
                return $error(new Message('transfer.error.path_taken', ['path' => $list->path]), $warnings);
            }
            $paths[$list->path] = $name;
        }
        $definition = $list->toArray();
        if (!isset($stored[$name])) {
            return [new ImportItem(ImportItem::LIST, $name, $title, ImportItem::CREATE, [new Message('transfer.change.list_created')], $warnings), $definition];
        }

        try {
            $before = ListDefinition::fromArray($name, $stored[$name], $current)->toArray();
        } catch (InvalidArgumentException) {
            $before = $stored[$name];
        }
        $changes = [];
        foreach (self::LIST_PROPERTIES as $property) {
            // == : MySQL sorts the keys of a stored JSON object.
            if (($before[$property] ?? null) != $definition[$property]) {
                $changes[] = new Message('transfer.change.list.' . $property);
            }
        }

        return [new ImportItem(ImportItem::LIST, $name, $title, $changes === [] ? ImportItem::UNCHANGED : ImportItem::UPDATE, $changes, $warnings), $definition];
    }

    /** A reference, for a message: its title, path or machine name. */
    private static function describe(mixed $reference): string
    {
        if (is_array($reference)) {
            foreach (['title', 'path', 'machine_name'] as $key) {
                if (is_string($reference[$key] ?? null) && $reference[$key] !== '') {
                    return mb_substr($reference[$key], 0, 100);
                }
            }
        }

        return '?';
    }

    /**
     * The imported Blueprint a blocked step is about, if any.
     *
     * @param list<string> $names
     */
    private static function stepBlueprint(SyncStep $step, array $names): ?string
    {
        foreach ($names as $name) {
            if (($step->message->params['blueprint'] ?? null) === $name || ($step->message->params['table'] ?? null) === 'bp_' . $name) {
                return $name;
            }
        }

        return null;
    }

    private static function failed(ImportItem $item, Message $error): ImportItem
    {
        return new ImportItem($item->kind, $item->name, $item->label, ImportItem::ERROR, $item->changes, $item->warnings, $error);
    }
}
