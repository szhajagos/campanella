<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * Compares table definitions (CoreSchema, the capabilities' tables) with the
 * actual database, and lists the differences: missing tables, columns and
 * indexes; columns with another type, NULL or default; columns and indexes
 * the definition does not have; tables with the prefix that no definition has
 * (e.g. of a capability no longer registered).
 *
 * It only reports: applying the differences is up to the caller (additive
 * ones can be applied with their `sql`; the others need a migration).
 * Not compared: the column order, the engine and the collation, and indexes
 * the server created for a foreign key by itself.
 */
final class SchemaComparator
{
    public function __construct(
        private readonly SchemaReader $reader,
        private readonly SchemaBuilder $builder,
    ) {
    }

    /**
     * @param list<Table> $definitions
     * @return list<SchemaDifference>
     */
    public function compare(array $definitions): array
    {
        $differences = [];
        $defined = [];
        foreach ($definitions as $table) {
            $defined[$table->name] = true;
            $actual = $this->reader->read($table->name);
            if ($actual === null) {
                $differences[] = new SchemaDifference(DifferenceKind::MissingTable, $table->name, sql: $this->builder->createSql($table), additive: true);
                continue;
            }
            array_push($differences, ...$this->compareTable($table, $actual));
        }
        foreach ($this->reader->tableNames() as $name) {
            if (!isset($defined[$name])) {
                $differences[] = new SchemaDifference(DifferenceKind::ExtraTable, $name);
            }
        }

        return $differences;
    }

    /** @return list<SchemaDifference> */
    public function compareTable(Table $table, TableInfo $actual): array
    {
        $differences = [];
        $t = $table->name;

        foreach ($table->columns as $column) {
            $found = $actual->column($column->name);
            if ($found === null) {
                // NOT NULL without a default: the existing rows would get an arbitrary value.
                $additive = $column->nullable || $column->default !== null;
                $differences[] = new SchemaDifference(
                    DifferenceKind::MissingColumn,
                    $t,
                    $column->name,
                    expected: $column->type->sql($column->length),
                    sql: $this->builder->addColumnSql($table, $column->name),
                    additive: $additive,
                );
                continue;
            }
            $expectedType = $column->type->sql($column->length);
            $sameLength = !in_array($column->type, [ColumnType::String], true) || $found->length === $column->length;
            if ($found->type !== $column->type || !$sameLength) {
                $differences[] = new SchemaDifference(DifferenceKind::ColumnType, $t, $column->name, $expectedType, $found->rawType);
            }
            if ($found->nullable !== $column->nullable) {
                $differences[] = new SchemaDifference(
                    DifferenceKind::ColumnNullable,
                    $t,
                    $column->name,
                    $column->nullable ? 'NULL' : 'NOT NULL',
                    $found->nullable ? 'NULL' : 'NOT NULL',
                );
            }
            $expectedDefault = $column->default === null ? null : (string) $column->default;
            if ($found->default !== $expectedDefault) {
                $differences[] = new SchemaDifference(DifferenceKind::ColumnDefault, $t, $column->name, $expectedDefault, $found->default);
            }
        }
        $definedColumns = array_flip(array_map(static fn (Column $c): string => $c->name, $table->columns));
        foreach ($actual->columns as $name => $found) {
            if (!isset($definedColumns[$name])) {
                $differences[] = new SchemaDifference(DifferenceKind::ExtraColumn, $t, $name, actual: $found->rawType, sql: $this->builder->dropColumnSql($t, $name));
            }
        }

        if ($actual->primaryKey !== $table->primaryKey) {
            $differences[] = new SchemaDifference(DifferenceKind::PrimaryKey, $t, null, implode(', ', $table->primaryKey), implode(', ', $actual->primaryKey) ?: null);
        }

        $definedIndexes = $table->indexes + $table->uniques;
        foreach ($definedIndexes as $name => $columns) {
            $found = $actual->indexes[$name] ?? $actual->uniques[$name] ?? null;
            $unique = isset($table->uniques[$name]);
            if ($found === null) {
                $differences[] = new SchemaDifference(
                    DifferenceKind::MissingIndex,
                    $t,
                    $name,
                    expected: ($unique ? 'UNIQUE ' : '') . '(' . implode(', ', $columns) . ')',
                    sql: $this->builder->addIndexSql($table, $name),
                    additive: true,
                );
            } elseif ($found !== $columns || $unique !== isset($actual->uniques[$name])) {
                $differences[] = new SchemaDifference(
                    DifferenceKind::IndexColumns,
                    $t,
                    $name,
                    ($unique ? 'UNIQUE ' : '') . '(' . implode(', ', $columns) . ')',
                    (isset($actual->uniques[$name]) ? 'UNIQUE ' : '') . '(' . implode(', ', $found) . ')',
                );
            }
        }
        foreach ($actual->indexes + $actual->uniques as $name => $columns) {
            // The server creates an index for a foreign key by itself, named after the constraint.
            $forForeignKey = isset($actual->foreignKeys[$name]) && $columns === [$actual->foreignKeys[$name]['column']];
            if (!isset($definedIndexes[$name]) && !$forForeignKey) {
                $differences[] = new SchemaDifference(DifferenceKind::ExtraIndex, $t, $name, actual: '(' . implode(', ', $columns) . ')', sql: $this->builder->dropIndexSql($t, $name));
            }
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $matches = array_filter(
                $actual->foreignKeys,
                static fn (array $fk): bool => $fk['column'] === $foreignKey->column
                    && $fk['table'] === $foreignKey->referencedTable
                    && $fk['referencedColumn'] === $foreignKey->referencedColumn
                    && $fk['cascadeDelete'] === $foreignKey->cascadeDelete,
            );
            if ($matches === []) {
                $differences[] = new SchemaDifference(
                    DifferenceKind::MissingForeignKey,
                    $t,
                    $foreignKey->column,
                    $foreignKey->referencedTable . '.' . $foreignKey->referencedColumn . ($foreignKey->cascadeDelete ? ' ON DELETE CASCADE' : ''),
                );
            }
        }

        return $differences;
    }
}
