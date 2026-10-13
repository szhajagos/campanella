<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

use Campanella\Model\Field;

/**
 * The table of single-valued queryable fields: `object_id` and a column per
 * field, with the fields' indexes. The shape of a capability's `cap_<name>`
 * table and of a Blueprint's `bp_<name>` table (since 0.2.2). **Internal.**
 */
final class FieldTable
{
    /** @param array<string, Field> $fields */
    public static function build(string $name, array $fields): Table
    {
        $columns = [new Column('object_id', ColumnType::Id)];
        $indexes = [];
        $uniques = [];
        foreach ($fields as $field) {
            $columns[] = new Column(
                name: $field->name,
                type: $field->type->columnType(),
                nullable: !$field->required,
                length: $field->length,
            );
            if ($field->unique) {
                $uniques['uniq_' . $field->name] = [$field->name];
            } elseif ($field->indexed) {
                $indexes['idx_' . $field->name] = [$field->name];
            }
        }

        return new Table(
            name: $name,
            columns: $columns,
            primaryKey: ['object_id'],
            indexes: $indexes,
            uniques: $uniques,
            foreignKeys: [new ForeignKey('object_id', CoreSchema::OBJECTS)],
        );
    }
}
