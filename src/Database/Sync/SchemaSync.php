<?php

declare(strict_types=1);

namespace Campanella\Database\Sync;

use Campanella\Capability\CapabilityDefinition;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Database\Installer;
use Campanella\Database\Schema\Column;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Database\Schema\DifferenceKind;
use Campanella\Database\Schema\SchemaBuilder;
use Campanella\Database\Schema\SchemaDifference;
use Campanella\Database\Schema\SchemaReader;
use Campanella\Database\Schema\Table;
use Campanella\I18n\Message;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Tree\TreeKeeper;

/**
 * Applies the additive changes of the definitions to an existing database,
 * without a migration (since 0.0.6):
 *
 * - **the schema:** missing tables, missing columns, missing indexes. A new
 *   required column gets the field's default in the existing rows; without a
 *   default (and with rows) it is not guessed: Blocked, a migration fills it.
 * - **Blueprints:** a capability added to a Blueprint is added to its existing
 *   objects, with the defaults (the Blueprint's `defaults`, else the field's).
 *   A required field without a default, or a unique field (one default for many
 *   objects), is Blocked.
 * - **a capability removed from a Blueprint:** its objects keep it and its data
 *   (a Note), unless `prune`: then the data is deleted (its table rows, its
 *   multi-valued values, its keys in the JSON data) and the objects lose it.
 *
 * - **a Blueprint's own field made queryable** (since 0.2.2): its values move
 *   from the JSON data into the Blueprint's table (or `field_values`); **made
 *   not queryable again:** its values are copied back into the JSON data (the
 *   column and its values stay, nothing is deleted).
 *
 * Renaming, type changes: never here (a migration). plan() only looks;
 * apply() does the steps in order and returns them.
 */
final class SchemaSync
{
    public function __construct(
        private readonly Connection $db,
        private readonly Installer $installer,
        private readonly CapabilityRegistry $capabilities,
        private readonly BlueprintRegistry $blueprints,
    ) {
    }

    public function plan(bool $prune = false): SyncPlan
    {
        $builder = new SchemaBuilder($this->db);
        $definitions = [];
        foreach ($this->installer->tables() as $table) {
            $definitions[$table->name] = $table;
        }

        $steps = [];
        $blockedTables = [];
        $missingTables = [];
        foreach ($this->installer->differences() as $difference) {
            $table = $definitions[$difference->table] ?? null;
            $step = $table === null ? null : $this->schemaStep($difference, $table, $builder);
            if ($step !== null) {
                $steps[] = $step;
                if ($step->kind === SyncStepKind::Blocked) {
                    $blockedTables[$difference->table] = true;
                }
                if ($difference->kind === DifferenceKind::MissingTable) {
                    $missingTables[$difference->table] = true;
                }
            }
        }
        if (isset($missingTables[CoreSchema::OBJECTS]) || isset($missingTables[CoreSchema::OBJECT_CAPABILITIES])) {
            return new SyncPlan($steps); // not installed: nothing to do with objects yet
        }

        foreach ($this->blueprints->all() as $name => $blueprint) {
            array_push($steps, ...$this->blueprintSteps($name, $blueprint, $blockedTables, $prune));
        }
        foreach ($this->blueprints->all() as $name => $blueprint) {
            array_push($steps, ...$this->moveSteps($name, $blueprint, $blockedTables));
        }
        foreach ($this->unknownBlueprints() as $name => $count) {
            $steps[] = new SyncStep(SyncStepKind::Note, new Message('sync.unknown_blueprint', ['blueprint' => $name, 'count' => $count]));
        }

        return new SyncPlan($steps);
    }

    /** Plans and does the steps; Blocked and Note steps are returned untouched. */
    public function apply(bool $prune = false): SyncPlan
    {
        $plan = $this->plan($prune);
        foreach ($plan->steps as $step) {
            $step->run();
        }

        return $plan;
    }

    private function schemaStep(SchemaDifference $difference, Table $table, SchemaBuilder $builder): ?SyncStep
    {
        $t = $difference->table;
        $db = $this->db;

        return match ($difference->kind) {
            DifferenceKind::MissingTable => new SyncStep(
                SyncStepKind::CreateTable,
                new Message('sync.create_table', ['table' => $t]),
                static fn () => $builder->create($table),
            ),
            DifferenceKind::MissingIndex => new SyncStep(
                SyncStepKind::AddIndex,
                new Message('sync.add_index', ['table' => $t, 'index' => (string) $difference->name]),
                static function () use ($db, $builder, $table, $difference): void {
                    $db->execute($builder->addIndexSql($table, (string) $difference->name));
                },
            ),
            DifferenceKind::MissingColumn => $this->columnStep($difference, $table, $builder),
            default => null, // reported by schema:check; a migration decides
        };
    }

    private function columnStep(SchemaDifference $difference, Table $table, SchemaBuilder $builder): SyncStep
    {
        $db = $this->db;
        $name = (string) $difference->name;
        $params = ['table' => $table->name, 'column' => $name];
        if ($difference->additive || $this->isEmpty($table->name)) {
            return new SyncStep(SyncStepKind::AddColumn, new Message('sync.add_column', $params), static function () use ($db, $builder, $table, $name): void {
                $db->execute($builder->addColumnSql($table, $name));
            });
        }

        // NOT NULL without a database default: the field's default for the existing rows.
        $field = $this->fieldOfColumn($table->name, $name);
        $value = $field === null || $field->isMultiple() ? null : $field->toStorage($field->default);
        if ($value === null || is_array($value)) {
            return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_column', $params));
        }
        $definition = null;
        $after = null;
        foreach ($table->columns as $column) {
            if ($column->name === $name) {
                $definition = $column;
                break;
            }
            $after = $column->name;
        }
        if ($definition === null) {
            return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_column', $params));
        }
        // Added as NULL, filled, then made NOT NULL: the same on MariaDB and MySQL, for
        // every type (MySQL does not allow a literal default on TEXT columns).
        $nullable = new Column($definition->name, $definition->type, true, null, false, $definition->length);

        return new SyncStep(
            SyncStepKind::AddColumn,
            new Message('sync.add_column_default', $params + ['value' => (string) $value]),
            static function () use ($db, $builder, $table, $nullable, $definition, $after, $value): void {
                $db->execute($builder->addColumnDefinitionSql($table->name, $nullable, $after, first: $after === null));
                $db->execute(sprintf('UPDATE %s SET %s = :value', $db->table($table->name), Connection::quoteIdentifier($definition->name)), ['value' => $value]);
                $db->execute(sprintf('ALTER TABLE %s MODIFY COLUMN %s', $db->table($table->name), $builder->columnSql($definition)));
            },
        );
    }

    /**
     * @param array<string, true> $blockedTables Tables with a column that cannot be added
     * @return list<SyncStep>
     */
    private function blueprintSteps(string $name, Blueprint $blueprint, array $blockedTables, bool $prune): array
    {
        $steps = [];
        $wanted = [];
        foreach ($blueprint->capabilities as $definition) {
            $wanted[$definition->name] = true;
            $missing = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM {objects} o WHERE o.blueprint = :b
                 AND NOT EXISTS (SELECT 1 FROM {object_capabilities} oc WHERE oc.object_id = o.id AND oc.capability = :c)',
                ['b' => $name, 'c' => $definition->name],
            );
            if ($missing > 0) {
                $steps[] = $this->addCapabilityStep($name, $blueprint, $definition, $missing, $blockedTables);
            }
        }

        $present = $this->db->fetchAll(
            'SELECT oc.capability AS capability, COUNT(*) AS n FROM {object_capabilities} oc
             JOIN {objects} o ON o.id = oc.object_id WHERE o.blueprint = :b GROUP BY oc.capability ORDER BY oc.capability',
            ['b' => $name],
        );
        foreach ($present as $row) {
            $capability = (string) $row['capability'];
            if (isset($wanted[$capability])) {
                continue;
            }
            $params = ['blueprint' => $name, 'capability' => $capability, 'count' => (int) $row['n']];
            $steps[] = $prune
                ? new SyncStep(SyncStepKind::PruneCapability, new Message('sync.prune', $params), fn () => $this->prune($name, $capability))
                : new SyncStep(SyncStepKind::Note, new Message('sync.kept', $params));
        }

        return $steps;
    }

    /** @param array<string, true> $blockedTables */
    private function addCapabilityStep(string $name, Blueprint $blueprint, CapabilityDefinition $definition, int $missing, array $blockedTables): SyncStep
    {
        $params = ['blueprint' => $name, 'capability' => $definition->name, 'count' => $missing];
        if ($definition->hasTable() && isset($blockedTables[$definition->tableName()])) {
            return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_table', $params + ['table' => $definition->tableName()]));
        }

        $values = [];
        foreach ($blueprint->narrow($definition->tableFields()) as $field) {
            $default = array_key_exists($field->name, $blueprint->defaults) ? $blueprint->defaults[$field->name] : $field->default;
            $value = $default === null ? null : $field->toStorage($default);
            if ($value === null && $field->required) {
                return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_required', $params + ['field' => $field->name]));
            }
            if ($value !== null && $field->unique && $missing + $this->tableRows($definition->tableName()) > 1) {
                return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_unique', $params + ['field' => $field->name]));
            }
            $values[$field->name] = $value;
        }

        return new SyncStep(
            SyncStepKind::AddCapability,
            new Message('sync.add_capability', $params),
            fn () => $this->addCapability($name, $definition, $values),
        );
    }

    /** @param array<string, mixed> $values The table fields' storage values */
    private function addCapability(string $blueprint, CapabilityDefinition $definition, array $values): void
    {
        $this->db->transactional(function (Connection $db) use ($blueprint, $definition, $values): void {
            if ($definition->hasTable()) {
                $columns = ['object_id'];
                $select = ['o.id'];
                $params = ['b' => $blueprint];
                $i = 0;
                foreach ($values as $column => $value) {
                    $columns[] = Connection::quoteIdentifier($column);
                    $select[] = ':v' . $i;
                    $params['v' . $i] = $value;
                    $i++;
                }
                $db->execute(sprintf(
                    'INSERT INTO %1$s (%2$s) SELECT %3$s FROM {objects} o WHERE o.blueprint = :b
                     AND NOT EXISTS (SELECT 1 FROM %1$s t WHERE t.object_id = o.id)',
                    $db->table($definition->tableName()),
                    implode(', ', $columns),
                    implode(', ', $select),
                ), $params);
            }
            $db->execute(
                'INSERT INTO {object_capabilities} (object_id, capability) SELECT o.id, :c FROM {objects} o WHERE o.blueprint = :b
                 AND NOT EXISTS (SELECT 1 FROM {object_capabilities} oc WHERE oc.object_id = o.id AND oc.capability = :c2)',
                ['b' => $blueprint, 'c' => $definition->name, 'c2' => $definition->name],
            );
            // A tree's rows need their paths (computed, not defaults): the objects become roots.
            if ($definition->class === Hierarchical::class) {
                (new TreeKeeper($db))->repair();
            }
        });
    }

    /** Deletes the capability's data of the Blueprint's objects, and the capability from them. */
    private function prune(string $blueprint, string $capability): void
    {
        $definition = $this->capabilities->has($capability) ? $this->capabilities->get($capability) : null;
        $this->db->transactional(function (Connection $db) use ($blueprint, $capability, $definition): void {
            if ($definition !== null) {
                if ($definition->hasTable()) {
                    $db->execute(sprintf(
                        'DELETE t FROM %s t JOIN {objects} o ON o.id = t.object_id WHERE o.blueprint = :b',
                        $db->table($definition->tableName()),
                    ), ['b' => $blueprint]);
                }
                $multi = array_keys($definition->valueTableFields());
                if ($multi !== []) {
                    $placeholders = [];
                    $params = ['b' => $blueprint];
                    foreach ($multi as $i => $field) {
                        $placeholders[] = ':f' . $i;
                        $params['f' . $i] = $field;
                    }
                    $db->execute('DELETE fv FROM {field_values} fv JOIN {objects} o ON o.id = fv.object_id WHERE o.blueprint = :b AND fv.field IN (' . implode(', ', $placeholders) . ')', $params);
                }
                $dataKeys = array_keys(array_filter($definition->fields, static fn (Field $f): bool => $f->storage === FieldStorage::Data));
                if ($dataKeys !== []) {
                    $this->removeDataKeys($blueprint, $capability, $dataKeys);
                }
            }
            $db->execute(
                'DELETE oc FROM {object_capabilities} oc JOIN {objects} o ON o.id = oc.object_id WHERE o.blueprint = :b AND oc.capability = :c',
                ['b' => $blueprint, 'c' => $capability],
            );
        });
    }

    /** @param list<string> $keys */
    private function removeDataKeys(string $blueprint, string $capability, array $keys): void
    {
        $rows = $this->db->fetchAll(
            'SELECT o.id, o.data FROM {objects} o JOIN {object_capabilities} oc ON oc.object_id = o.id AND oc.capability = :c WHERE o.blueprint = :b',
            ['b' => $blueprint, 'c' => $capability],
        );
        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            if (!is_array($data)) {
                continue;
            }
            $changed = array_diff_key($data, array_flip($keys));
            if (count($changed) !== count($data)) {
                $this->db->update(CoreSchema::OBJECTS, ['data' => json_encode($changed === [] ? new \stdClass() : $changed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => (int) $row['id']]);
            }
        }
    }

    /** @return array<string, int> The objects whose Blueprint is not defined (any more) */
    private function unknownBlueprints(): array
    {
        $known = array_keys($this->blueprints->all());
        $unknown = [];
        foreach ($this->db->fetchAll('SELECT blueprint, COUNT(*) AS n FROM {objects} GROUP BY blueprint ORDER BY blueprint') as $row) {
            if (!in_array((string) $row['blueprint'], $known, true)) {
                $unknown[(string) $row['blueprint']] = (int) $row['n'];
            }
        }

        return $unknown;
    }

    private function fieldOfColumn(string $table, string $column): ?Field
    {
        foreach ($this->capabilities->all() as $definition) {
            if ($definition->hasTable() && $definition->tableName() === $table) {
                return $definition->tableFields()[$column] ?? null;
            }
        }
        foreach ($this->blueprints->all() as $blueprint) {
            if ($blueprint->hasTable() && $blueprint->tableName() === $table) {
                return $blueprint->tableFields()[$column] ?? null;
            }
        }

        return null;
    }

    /**
     * Moving the values of a Blueprint's own fields whose storage changed (since 0.2.2).
     *
     * To the table: the objects whose JSON data still holds a queryable field. The JSON
     * value wins (it is the newer one: the field was edited there), and leaves the JSON.
     * Back to the data: the values in the table's column (or `field_values`) of a field
     * that is not queryable any more, for the objects whose JSON does not have it.
     *
     * @param array<string, true> $blockedTables
     * @return list<SyncStep>
     */
    private function moveSteps(string $name, Blueprint $blueprint, array $blockedTables): array
    {
        $steps = [];
        $reader = new SchemaReader($this->db);
        $tableInfo = $reader->read($blueprint->tableName());

        // To the table.
        $queryable = $blueprint->tableFields() + $blueprint->valueTableFields();
        $candidates = $queryable === [] ? [] : $this->objectsWithDataKeys($name, array_keys($queryable));
        if ($candidates !== []) {
            if ($blueprint->hasTable() && isset($blockedTables[$blueprint->tableName()])) {
                $steps[] = new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_move_table', ['blueprint' => $name, 'count' => count($candidates), 'table' => $blueprint->tableName()]));
            } else {
                $existing = [];
                if ($tableInfo !== null) {
                    [$in, $params] = self::inList(array_keys($candidates));
                    foreach ($this->db->fetchAll(sprintf('SELECT object_id FROM %s WHERE object_id IN (%s)', $this->db->table($blueprint->tableName()), $in), $params) as $row) {
                        $existing[(int) $row['object_id']] = true;
                    }
                }
                foreach ($blueprint->tableFields() as $field) {
                    if (!$field->required) {
                        continue;
                    }
                    $default = array_key_exists($field->name, $blueprint->defaults) ? $blueprint->defaults[$field->name] : $field->default;
                    foreach ($candidates as $id => $data) {
                        if (!isset($existing[$id]) && ($data[$field->name] ?? null) === null && $default === null) {
                            return [new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_move', ['blueprint' => $name, 'field' => $field->name, 'count' => count($candidates)]))];
                        }
                    }
                }
                $fields = implode(', ', array_keys(array_filter($queryable, static function (Field $f) use ($candidates): bool {
                    foreach ($candidates as $data) {
                        if (array_key_exists($f->name, $data)) {
                            return true;
                        }
                    }

                    return false;
                })));
                $steps[] = new SyncStep(
                    SyncStepKind::MoveData,
                    new Message('sync.move_to_table', ['blueprint' => $name, 'count' => count($candidates), 'fields' => $fields]),
                    fn () => $this->moveToTable($blueprint, array_keys($candidates)),
                );
            }
        }

        // Back to the data.
        $back = [];
        foreach ($blueprint->fields as $field) {
            if ($field->storage !== FieldStorage::Data) {
                continue;
            }
            $sql = $field->isMultiple()
                ? 'SELECT fv.object_id AS id FROM {field_values} fv JOIN {objects} o ON o.id = fv.object_id WHERE o.blueprint = :b AND fv.field = :f GROUP BY fv.object_id'
                : ($tableInfo?->column($field->name) !== null
                    ? sprintf('SELECT t.object_id AS id FROM %s t JOIN {objects} o ON o.id = t.object_id WHERE o.blueprint = :b AND t.%s IS NOT NULL', $this->db->table($blueprint->tableName()), Connection::quoteIdentifier($field->name))
                    : null);
            if ($sql === null) {
                continue;
            }
            $params = ['b' => $name] + ($field->isMultiple() ? ['f' => $field->name] : []);
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->db->fetchAll($sql, $params));
            foreach ($ids === [] ? [] : $this->dataOf($ids) as $id => $data) {
                if (!array_key_exists($field->name, $data)) {
                    $back[$field->name][] = $id;
                }
            }
        }
        if ($back !== []) {
            $count = count(array_unique(array_merge(...array_values($back))));
            $steps[] = new SyncStep(
                SyncStepKind::MoveData,
                new Message('sync.move_to_data', ['blueprint' => $name, 'count' => $count, 'fields' => implode(', ', array_keys($back))]),
                fn () => $this->moveToData($blueprint, $back),
            );
        }

        return $steps;
    }

    /**
     * The Blueprint's objects whose JSON data has any of the keys (a text search first,
     * then each candidate's JSON decoded and checked).
     *
     * @param list<string> $keys
     * @return array<int, array<string, mixed>> object ID => its data
     */
    private function objectsWithDataKeys(string $blueprint, array $keys): array
    {
        $likes = [];
        $params = ['b' => $blueprint];
        foreach ($keys as $i => $key) {
            $likes[] = 'data LIKE :k' . $i;
            $params['k' . $i] = '%"' . $key . '":%';
        }
        $found = [];
        $rows = $this->db->fetchAll('SELECT id, data FROM {objects} WHERE blueprint = :b AND (' . implode(' OR ', $likes) . ') ORDER BY id', $params);
        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            if (is_array($data) && array_intersect_key($data, array_flip($keys)) !== []) {
                $found[(int) $row['id']] = $data;
            }
        }

        return $found;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function dataOf(array $ids): array
    {
        [$in, $params] = self::inList($ids);
        $data = [];
        foreach ($this->db->fetchAll("SELECT id, data FROM {objects} WHERE id IN ({$in})", $params) as $row) {
            $decoded = json_decode((string) $row['data'], true);
            $data[(int) $row['id']] = is_array($decoded) ? $decoded : [];
        }

        return $data;
    }

    /** @param list<int> $ids */
    private function moveToTable(Blueprint $blueprint, array $ids): void
    {
        $single = $blueprint->tableFields();
        $multi = $blueprint->valueTableFields();
        $keys = array_keys($single + $multi);
        $this->db->transactional(function (Connection $db) use ($blueprint, $ids, $single, $multi, $keys): void {
            foreach ($this->dataOf($ids) as $id => $data) {
                if ($single !== []) {
                    $exists = $db->fetchValue(sprintf('SELECT 1 FROM {%s} WHERE object_id = :id', $blueprint->tableName()), ['id' => $id]) !== null;
                    $row = [];
                    foreach ($single as $name => $field) {
                        if (array_key_exists($name, $data)) {
                            $row[$name] = $field->type->toStorage($field->cast($data[$name]));
                        } elseif (!$exists) {
                            $default = array_key_exists($name, $blueprint->defaults) ? $blueprint->defaults[$name] : $field->default;
                            $row[$name] = $field->type->toStorage($field->cast($default));
                        }
                    }
                    if (!$exists) {
                        $db->insert($blueprint->tableName(), ['object_id' => $id] + $row);
                    } elseif ($row !== []) {
                        $db->update($blueprint->tableName(), $row, ['object_id' => $id]);
                    }
                }
                foreach ($multi as $name => $field) {
                    if (!array_key_exists($name, $data)) {
                        continue;
                    }
                    $db->delete(CoreSchema::FIELD_VALUES, ['object_id' => $id, 'field' => $name]);
                    foreach ((array) $field->toStorage($data[$name]) as $delta => $item) {
                        $db->insert(CoreSchema::FIELD_VALUES, ['object_id' => $id, 'field' => $name, 'delta' => $delta, $field->type->valueColumn() => $item]);
                    }
                }
                $rest = array_diff_key($data, array_flip($keys));
                $db->update(CoreSchema::OBJECTS, ['data' => json_encode($rest === [] ? new \stdClass() : $rest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
            }
        });
    }

    /** @param array<string, list<int>> $fields field name => the objects whose value goes back into the data */
    private function moveToData(Blueprint $blueprint, array $fields): void
    {
        $this->db->transactional(function (Connection $db) use ($blueprint, $fields): void {
            $all = array_values(array_unique(array_merge(...array_values($fields))));
            $data = $this->dataOf($all);
            foreach ($fields as $name => $ids) {
                $field = $blueprint->fields[$name];
                [$in, $params] = self::inList($ids);
                if ($field->isMultiple()) {
                    $column = Connection::quoteIdentifier($field->type->valueColumn());
                    $rows = $db->fetchAll("SELECT object_id, {$column} AS v FROM {field_values} WHERE field = :f AND object_id IN ({$in}) ORDER BY object_id, delta, id", $params + ['f' => $name]);
                    $values = [];
                    foreach ($rows as $row) {
                        $values[(int) $row['object_id']][] = $row['v'];
                    }
                } else {
                    $rows = $db->fetchAll(sprintf('SELECT object_id, %s AS v FROM {%s} WHERE object_id IN (%s)', Connection::quoteIdentifier($name), $blueprint->tableName(), $in), $params);
                    $values = [];
                    foreach ($rows as $row) {
                        $values[(int) $row['object_id']] = $row['v'];
                    }
                }
                foreach ($values as $id => $value) {
                    $data[$id][$name] = $field->toStorage($field->fromStorage($value));
                }
            }
            foreach ($data as $id => $values) {
                $db->update(CoreSchema::OBJECTS, ['data' => json_encode($values === [] ? new \stdClass() : $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
            }
        });
    }

    /**
     * @param list<int> $ids
     * @return array{string, array<string, int>}
     */
    private static function inList(array $ids): array
    {
        $params = [];
        foreach ($ids as $i => $id) {
            $params['id' . $i] = $id;
        }

        return [implode(', ', array_map(static fn (string $k): string => ':' . $k, array_keys($params))), $params];
    }

    private function isEmpty(string $table): bool
    {
        return $this->db->fetchValue(sprintf('SELECT 1 FROM %s LIMIT 1', $this->db->table($table))) === null;
    }

    /** The rows of a table (0 if it does not exist yet: it is created by an earlier step). */
    private function tableRows(string $table): int
    {
        if (!(new SchemaReader($this->db))->tableExists($table)) {
            return 0;
        }

        return (int) $this->db->fetchValue(sprintf('SELECT COUNT(*) FROM %s', $this->db->table($table)));
    }
}
