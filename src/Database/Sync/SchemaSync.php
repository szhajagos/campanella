<?php

declare(strict_types=1);

namespace Campanella\Database\Sync;

use Campanella\Capability\CapabilityDefinition;
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
 * Renaming, type changes, moving data: never here (a migration). plan() only
 * looks; apply() does the steps in order and returns them.
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

        return null;
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
