<?php

declare(strict_types=1);

namespace Campanella\Database\Sync;

use Campanella\Capability\CapabilityDefinition;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Database\Installer;
use Campanella\Database\Schema\Column;
use Campanella\Database\Schema\ColumnType;
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
use Closure;

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

    /** @var array<string, true> "table.column" whose change has a step already (plan()) */
    private array $changedColumns = [];

    public function plan(bool $prune = false): SyncPlan
    {
        $this->changedColumns = [];
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
            DifferenceKind::ColumnType, DifferenceKind::ColumnNullable => str_starts_with($t, 'bp_') ? $this->columnChangeStep($difference, $table, $builder) : null,
            default => null, // reported by schema:check; a migration decides
        };
    }

    /**
     * A changed column of a Blueprint's own table (since 0.2.4): a field's type widened
     * (whole number → decimal, text → long text, date → date and time, a longer text),
     * a field made required (the empty values get its default) or not required. Anything
     * else, a shorter text that does not fit, a required field without a default with
     * empty values, or a long text with an index: Blocked.
     */
    private function columnChangeStep(SchemaDifference $difference, Table $table, SchemaBuilder $builder): ?SyncStep
    {
        $name = (string) $difference->name;
        if (isset($this->changedColumns[$table->name . '.' . $name])) {
            return null;
        }
        $this->changedColumns[$table->name . '.' . $name] = true;
        $params = ['table' => $table->name, 'column' => $name];
        $info = (new SchemaReader($this->db))->read($table->name);
        $found = $info?->column($name);
        $column = null;
        foreach ($table->columns as $candidate) {
            if ($candidate->name === $name) {
                $column = $candidate;
            }
        }
        $field = $this->fieldOfColumn($table->name, $name);
        if ($info === null || $found === null || $column === null || $field === null) {
            return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_change', $params));
        }
        $db = $this->db;
        $quoted = Connection::quoteIdentifier($name);

        if ($found->type !== $column->type || ($column->type === ColumnType::String && $found->length !== $column->length)) {
            $widening = match (true) {
                $found->type === $column->type => true, // a String's length: checked below
                $found->type === ColumnType::Integer && $column->type === ColumnType::Decimal,
                $found->type === ColumnType::String && $column->type === ColumnType::Text,
                $found->type === ColumnType::Date && $column->type === ColumnType::DateTime => true,
                default => false,
            };
            $indexed = array_filter($info->indexes + $info->uniques, static fn (array $columns): bool => in_array($name, $columns, true)) !== [];
            if (!$widening || ($column->type === ColumnType::Text && $indexed)) {
                return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_change', $params));
            }
            if ($column->type === ColumnType::String && $found->length !== null && $column->length < $found->length) {
                $longest = (int) $db->fetchValue(sprintf('SELECT COALESCE(MAX(CHAR_LENGTH(%s)), 0) FROM %s', $quoted, $db->table($table->name)));
                if ($longest > $column->length) {
                    return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_shorter', $params + ['length' => $column->length, 'longest' => $longest]));
                }
            }
        }

        // NOT NULL: the empty values get the field's default first.
        $fill = null;
        if (!$column->nullable) {
            $empty = (int) $db->fetchValue(sprintf('SELECT COUNT(*) FROM %s WHERE %s IS NULL', $db->table($table->name), $quoted));
            if ($empty > 0) {
                $blueprint = $this->blueprintOfTable($table->name);
                $default = $blueprint !== null && array_key_exists($name, $blueprint->defaults) ? $blueprint->defaults[$name] : $field->default;
                if ($default === null) {
                    return new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_required_change', $params + ['count' => $empty]));
                }
                $fill = $field->type->toStorage($field->cast($default));
            }
        }

        return new SyncStep(
            SyncStepKind::ChangeColumn,
            new Message('sync.change_column', $params + ['type' => $column->type->sql($column->length) . ($column->nullable ? '' : ' NOT NULL')]),
            static function () use ($db, $builder, $table, $column, $quoted, $fill): void {
                if ($fill !== null) {
                    $db->execute(sprintf('UPDATE %s SET %s = :value WHERE %s IS NULL', $db->table($table->name), $quoted, $quoted), ['value' => $fill]);
                }
                $db->execute(sprintf('ALTER TABLE %s MODIFY COLUMN %s', $db->table($table->name), $builder->columnSql($column)));
            },
        );
    }

    private function blueprintOfTable(string $table): ?Blueprint
    {
        foreach ($this->blueprints->all() as $blueprint) {
            if ($blueprint->tableName() === $table) {
                return $blueprint;
            }
        }

        return null;
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
     * Moving the values of a Blueprint's own fields whose storage changed (since 0.2.2;
     * one or several values, and a changed type of several values, since 0.2.4).
     *
     * A value can be in three places: the JSON data, the Blueprint table's column, or
     * `field_values` (rows). The field's definition says where it belongs:
     *
     * - **into the table** (a queryable field): from the JSON (it wins: it is where the
     *   field was edited), else from the rows (a field that had several values: the
     *   first) or the column (a field that had one: it becomes the only value). The
     *   sources are emptied: the JSON key removed, the rows deleted, a column that may
     *   be NULL set to NULL.
     * - **back into the JSON** (not queryable any more): for the objects whose JSON does
     *   not have it, from the rows, else the column; nothing is deleted.
     * - **several values of a changed type:** rows whose value is in another column of
     *   `field_values` than the type's (e.g. whole numbers that became decimals) move
     *   into the right one.
     *
     * @param array<string, true> $blockedTables
     * @return list<SyncStep>
     */
    private function moveSteps(string $name, Blueprint $blueprint, array $blockedTables): array
    {
        $steps = [];
        $tableInfo = (new SchemaReader($this->db))->read($blueprint->tableName());
        $hasColumn = static fn (string $field): bool => $tableInfo?->column($field) !== null;

        // Into the table.
        $queryable = $blueprint->tableFields() + $blueprint->valueTableFields();
        $candidates = $queryable === [] ? [] : $this->objectsWithDataKeys($name, array_keys($queryable));
        $sources = []; // object ID => true, for those with a value to move from the rows or the column
        foreach ($blueprint->tableFields() as $field) {
            foreach ($this->objectsWithRows($name, $field->name) as $id) {
                $sources[$id] = true;
            }
        }
        foreach ($blueprint->valueTableFields() as $field) {
            if ($hasColumn($field->name)) {
                foreach ($this->objectsWithColumnOnly($blueprint, $field->name) as $id) {
                    $sources[$id] = true;
                }
            }
        }
        $ids = array_keys($candidates + $sources);
        sort($ids);
        if ($ids !== []) {
            if ($blueprint->hasTable() && isset($blockedTables[$blueprint->tableName()])) {
                return [new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_move_table', ['blueprint' => $name, 'count' => count($ids), 'table' => $blueprint->tableName()]))];
            }
            $existing = [];
            if ($tableInfo !== null) {
                [$in, $params] = self::inList($ids);
                foreach ($this->db->fetchAll(sprintf('SELECT object_id FROM %s WHERE object_id IN (%s)', $this->db->table($blueprint->tableName()), $in), $params) as $row) {
                    $existing[(int) $row['object_id']] = true;
                }
            }
            $data = $this->dataOf($ids);
            foreach ($blueprint->tableFields() as $field) {
                $default = array_key_exists($field->name, $blueprint->defaults) ? $blueprint->defaults[$field->name] : $field->default;
                if (!$field->required || $default !== null) {
                    continue;
                }
                foreach ($ids as $id) {
                    if (!isset($existing[$id]) && ($data[$id][$field->name] ?? null) === null && !isset($sources[$id])) {
                        return [new SyncStep(SyncStepKind::Blocked, new Message('sync.blocked_move', ['blueprint' => $name, 'field' => $field->name, 'count' => count($ids)]))];
                    }
                }
            }
            $steps[] = new SyncStep(
                SyncStepKind::MoveData,
                new Message('sync.move_to_table', ['blueprint' => $name, 'count' => count($ids), 'fields' => implode(', ', array_keys($queryable))]),
                fn () => $this->moveToTable($blueprint, $ids, $hasColumn),
            );
        }

        // Back into the JSON.
        $back = [];
        foreach ($blueprint->fields as $field) {
            if ($field->storage !== FieldStorage::Data) {
                continue;
            }
            $ids = $this->objectsWithRows($name, $field->name);
            if ($hasColumn($field->name)) {
                $ids = array_merge($ids, array_map(static fn (array $r): int => (int) $r['id'], $this->db->fetchAll(
                    sprintf('SELECT t.object_id AS id FROM %s t JOIN {objects} o ON o.id = t.object_id WHERE o.blueprint = :b AND t.%s IS NOT NULL', $this->db->table($blueprint->tableName()), Connection::quoteIdentifier($field->name)),
                    ['b' => $name],
                )));
            }
            foreach ($ids === [] ? [] : $this->dataOf(array_values(array_unique($ids))) as $id => $data) {
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
                fn () => $this->moveToData($blueprint, $back, $hasColumn),
            );
        }

        // Several values whose type changed: into the type's column of field_values.
        foreach ($blueprint->valueTableFields() as $field) {
            $column = $field->type->valueColumn();
            $others = array_diff(self::VALUE_COLUMNS, [$column]);
            $count = (int) $this->db->fetchValue(sprintf(
                'SELECT COUNT(*) FROM {field_values} fv JOIN {objects} o ON o.id = fv.object_id WHERE o.blueprint = :b AND fv.field = :f AND fv.%s IS NULL AND (%s)',
                Connection::quoteIdentifier($column),
                implode(' OR ', array_map(static fn (string $c): string => 'fv.' . Connection::quoteIdentifier($c) . ' IS NOT NULL', $others)),
            ), ['b' => $name, 'f' => $field->name]);
            if ($count > 0) {
                $steps[] = new SyncStep(
                    SyncStepKind::MoveData,
                    new Message('sync.convert_values', ['blueprint' => $name, 'field' => $field->name, 'count' => $count]),
                    fn () => $this->convertValues($name, $field),
                );
            }
        }

        return $steps;
    }

    /** The columns of field_values that hold values. */
    private const array VALUE_COLUMNS = ['value_string', 'value_text', 'value_int', 'value_datetime', 'value_decimal'];

    /** @return list<int> The Blueprint's objects with rows of the field in field_values. */
    private function objectsWithRows(string $blueprint, string $field): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->fetchAll(
            'SELECT DISTINCT fv.object_id AS id FROM {field_values} fv JOIN {objects} o ON o.id = fv.object_id WHERE o.blueprint = :b AND fv.field = :f',
            ['b' => $blueprint, 'f' => $field],
        ));
    }

    /** @return list<int> The objects with a value in the field's column but no rows of it in field_values. */
    private function objectsWithColumnOnly(Blueprint $blueprint, string $field): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->fetchAll(
            sprintf(
                'SELECT t.object_id AS id FROM %s t WHERE t.%2$s IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {field_values} fv WHERE fv.object_id = t.object_id AND fv.field = :f)',
                $this->db->table($blueprint->tableName()),
                Connection::quoteIdentifier($field),
            ),
            ['f' => $field],
        ));
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

    /**
     * The values of the field in field_values, by object, in order (whichever value column holds them).
     *
     * @param list<int> $ids
     * @return array<int, list<mixed>>
     */
    private function rowsOf(string $field, array $ids): array
    {
        [$in, $params] = self::inList($ids);
        $values = [];
        $rows = $this->db->fetchAll("SELECT * FROM {field_values} WHERE field = :f AND object_id IN ({$in}) ORDER BY object_id, delta, id", $params + ['f' => $field]);
        foreach ($rows as $row) {
            foreach (self::VALUE_COLUMNS as $column) {
                if ($row[$column] !== null) {
                    $values[(int) $row['object_id']][] = $row[$column];
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * @param list<int> $ids
     * @param Closure(string): bool $hasColumn Whether the Blueprint's table has a column of that name
     */
    private function moveToTable(Blueprint $blueprint, array $ids, Closure $hasColumn): void
    {
        $single = $blueprint->tableFields();
        $multi = $blueprint->valueTableFields();
        $keys = array_keys($single + $multi);
        // A column whose values become several (rows): it may be NULL from now on, so it can be emptied.
        foreach (array_keys($multi) as $name) {
            $info = $hasColumn($name) ? (new SchemaReader($this->db))->read($blueprint->tableName())?->column($name) : null;
            if ($info !== null && !$info->nullable) {
                $this->db->execute(sprintf('ALTER TABLE %s MODIFY COLUMN %s %s NULL', $this->db->table($blueprint->tableName()), Connection::quoteIdentifier($name), $info->rawType));
            }
        }
        $this->db->transactional(function (Connection $db) use ($blueprint, $ids, $single, $multi, $keys, $hasColumn): void {
            $rows = [];
            foreach (array_keys($single) as $name) {
                $rows[$name] = $this->rowsOf($name, $ids);
            }
            $columns = [];
            foreach (array_keys($multi) as $name) {
                if ($hasColumn($name)) {
                    [$in, $params] = self::inList($ids);
                    foreach ($db->fetchAll(sprintf('SELECT object_id, %s AS v FROM {%s} WHERE object_id IN (%s)', Connection::quoteIdentifier($name), $blueprint->tableName(), $in), $params) as $row) {
                        $columns[$name][(int) $row['object_id']] = $row['v'];
                    }
                }
            }
            foreach ($this->dataOf($ids) as $id => $data) {
                if ($single !== []) {
                    $exists = $db->fetchValue(sprintf('SELECT 1 FROM {%s} WHERE object_id = :id', $blueprint->tableName()), ['id' => $id]) !== null;
                    $row = [];
                    foreach ($single as $name => $field) {
                        $value = match (true) {
                            array_key_exists($name, $data) => is_array($data[$name]) ? ($data[$name][0] ?? null) : $data[$name],
                            isset($rows[$name][$id]) => $rows[$name][$id][0],
                            default => false,
                        };
                        if ($value !== false) {
                            $row[$name] = $field->type->toStorage($field->cast($value));
                        } elseif (!$exists) {
                            $default = array_key_exists($name, $blueprint->defaults) ? $blueprint->defaults[$name] : $field->default;
                            $row[$name] = $field->type->toStorage($field->cast($default));
                        }
                        if (isset($rows[$name][$id])) {
                            $db->delete(CoreSchema::FIELD_VALUES, ['object_id' => $id, 'field' => $name]);
                        }
                    }
                    if (!$exists) {
                        $db->insert($blueprint->tableName(), ['object_id' => $id] + $row);
                    } elseif ($row !== []) {
                        $db->update($blueprint->tableName(), $row, ['object_id' => $id]);
                    }
                }
                foreach ($multi as $name => $field) {
                    $value = match (true) {
                        array_key_exists($name, $data) => $data[$name],
                        isset($columns[$name][$id]) && $db->fetchValue('SELECT 1 FROM {field_values} WHERE object_id = :id AND field = :f', ['id' => $id, 'f' => $name]) === null => [$columns[$name][$id]],
                        default => false,
                    };
                    if ($value === false) {
                        continue;
                    }
                    $db->delete(CoreSchema::FIELD_VALUES, ['object_id' => $id, 'field' => $name]);
                    foreach ((array) $field->toStorage($value) as $delta => $item) {
                        $db->insert(CoreSchema::FIELD_VALUES, ['object_id' => $id, 'field' => $name, 'delta' => $delta, $field->type->valueColumn() => $item]);
                    }
                    if (isset($columns[$name][$id])) {
                        $db->update($blueprint->tableName(), [$name => null], ['object_id' => $id]);
                    }
                }
                $rest = array_diff_key($data, array_flip($keys));
                if (count($rest) !== count($data)) {
                    $db->update(CoreSchema::OBJECTS, ['data' => json_encode($rest === [] ? new \stdClass() : $rest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
                }
            }
        });
    }

    /**
     * @param array<string, list<int>> $fields field name => the objects whose value goes back into the data
     * @param Closure(string): bool $hasColumn
     */
    private function moveToData(Blueprint $blueprint, array $fields, Closure $hasColumn): void
    {
        $this->db->transactional(function (Connection $db) use ($blueprint, $fields, $hasColumn): void {
            $all = array_values(array_unique(array_merge(...array_values($fields))));
            $data = $this->dataOf($all);
            foreach ($fields as $name => $ids) {
                $field = $blueprint->fields[$name];
                $rows = $this->rowsOf($name, $ids);
                $column = [];
                if ($hasColumn($name)) {
                    [$in, $params] = self::inList($ids);
                    foreach ($db->fetchAll(sprintf('SELECT object_id, %s AS v FROM {%s} WHERE object_id IN (%s)', Connection::quoteIdentifier($name), $blueprint->tableName(), $in), $params) as $row) {
                        if ($row['v'] !== null) {
                            $column[(int) $row['object_id']] = $row['v'];
                        }
                    }
                }
                foreach ($ids as $id) {
                    $values = $rows[$id] ?? (isset($column[$id]) ? [$column[$id]] : null);
                    if ($values !== null) {
                        $data[$id][$name] = $field->toStorage($field->isMultiple() ? $values : $values[0]);
                    }
                }
            }
            foreach ($data as $id => $values) {
                $db->update(CoreSchema::OBJECTS, ['data' => json_encode($values === [] ? new \stdClass() : $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
            }
        });
    }

    /** Several values of a changed type: each row's value into the type's column of field_values. */
    private function convertValues(string $blueprint, Field $field): void
    {
        $column = $field->type->valueColumn();
        $this->db->transactional(function (Connection $db) use ($blueprint, $field, $column): void {
            $rows = $db->fetchAll(
                sprintf('SELECT fv.* FROM {field_values} fv JOIN {objects} o ON o.id = fv.object_id WHERE o.blueprint = :b AND fv.field = :f AND fv.%s IS NULL', Connection::quoteIdentifier($column)),
                ['b' => $blueprint, 'f' => $field->name],
            );
            foreach ($rows as $row) {
                foreach (self::VALUE_COLUMNS as $other) {
                    if ($other !== $column && $row[$other] !== null) {
                        $db->update(CoreSchema::FIELD_VALUES, [$column => $field->type->toStorage($field->type->cast($row[$other]))] + array_fill_keys(self::VALUE_COLUMNS, null), ['id' => (int) $row['id']]);
                        break;
                    }
                }
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
