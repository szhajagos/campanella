<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/** What kind of difference the SchemaComparator found between a definition and the database. */
enum DifferenceKind: string
{
    case MissingTable = 'missing_table';
    case ExtraTable = 'extra_table';
    case MissingColumn = 'missing_column';
    case ExtraColumn = 'extra_column';
    case ColumnType = 'column_type';
    case ColumnNullable = 'column_nullable';
    case ColumnDefault = 'column_default';
    case PrimaryKey = 'primary_key';
    case MissingIndex = 'missing_index';
    case ExtraIndex = 'extra_index';
    case IndexColumns = 'index_columns';
    case MissingForeignKey = 'missing_foreign_key';
}
