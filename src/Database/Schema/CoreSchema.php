<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * A mag táblái. A capability-k saját táblái nem itt, hanem a
 * CapabilityDefinition::table() metódusban keletkeznek.
 */
final class CoreSchema
{
    public const string OBJECTS = 'objects';
    public const string OBJECT_CAPABILITIES = 'object_capabilities';
    public const string SYSTEM = 'system';
    public const string RELATIONSHIPS = 'relationships';

    /** @return list<Table> */
    public static function tables(): array
    {
        return [
            new Table(
                name: self::SYSTEM,
                columns: [
                    new Column('name', ColumnType::String, length: 128),
                    new Column('value', ColumnType::Text, nullable: true),
                ],
                primaryKey: ['name'],
            ),
            // Az objektum identitása és a nem lekérdezett adatai (data).
            new Table(
                name: self::OBJECTS,
                columns: [
                    new Column('id', ColumnType::Id, autoIncrement: true),
                    new Column('uuid', ColumnType::Uuid),
                    new Column('blueprint', ColumnType::String, length: 64),
                    new Column('data', ColumnType::Json),
                    new Column('created_at', ColumnType::DateTime),
                    new Column('updated_at', ColumnType::DateTime),
                ],
                primaryKey: ['id'],
                indexes: [
                    'idx_blueprint' => ['blueprint'],
                    'idx_created' => ['created_at'],
                ],
                uniques: ['uniq_uuid' => ['uuid']],
            ),
            // Melyik objektum milyen capability-kkel rendelkezik.
            new Table(
                name: self::OBJECT_CAPABILITIES,
                columns: [
                    new Column('object_id', ColumnType::Id),
                    new Column('capability', ColumnType::String, length: 64),
                ],
                primaryKey: ['object_id', 'capability'],
                indexes: ['idx_capability' => ['capability', 'object_id']],
                foreignKeys: [new ForeignKey('object_id', self::OBJECTS)],
            ),
            // Irányított kapcsolatok: source --type--> target, sorrenddel (weight).
            // Bármelyik oldal törlésekor a kapcsolat is törlődik.
            new Table(
                name: self::RELATIONSHIPS,
                columns: [
                    new Column('id', ColumnType::Id, autoIncrement: true),
                    new Column('source_id', ColumnType::Id),
                    new Column('type', ColumnType::String, length: 64),
                    new Column('target_id', ColumnType::Id),
                    new Column('weight', ColumnType::Integer, default: 0),
                ],
                primaryKey: ['id'],
                indexes: [
                    'idx_source' => ['source_id', 'type', 'weight'],
                    'idx_target' => ['target_id', 'type'],
                ],
                uniques: ['uniq_relationship' => ['source_id', 'type', 'target_id']],
                foreignKeys: [
                    new ForeignKey('source_id', self::OBJECTS),
                    new ForeignKey('target_id', self::OBJECTS),
                ],
            ),
        ];
    }
}
