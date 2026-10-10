<?php

declare(strict_types=1);

namespace Campanella\Database\Schema;

/**
 * The core tables. The capabilities' own tables are not defined here but
 * in the CapabilityDefinition::table() method.
 */
final class CoreSchema
{
    public const string OBJECTS = 'objects';
    public const string OBJECT_CAPABILITIES = 'object_capabilities';
    public const string SYSTEM = 'system';
    public const string RELATIONSHIPS = 'relationships';
    public const string THROTTLE = 'throttle';
    public const string FIELD_VALUES = 'field_values';
    public const string MIGRATIONS = 'migrations';
    public const string SETTINGS = 'settings';
    public const string MEDIA_USAGE = 'media_usage';
    public const string MAIL_LOG = 'mail_log';
    public const string SESSIONS = 'sessions';

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
            // The object's identity and its non-queried data (data).
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
            // Which object has which capabilities.
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
            // Directed relationships: source --type--> target, with ordering (weight).
            // Deleting either side also deletes the relationship.
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
            // The values of the multi-valued queryable fields: one row per value, in
            // order (delta). Only the column matching the field's type is filled.
            new Table(
                name: self::FIELD_VALUES,
                columns: [
                    new Column('id', ColumnType::Id, autoIncrement: true),
                    new Column('object_id', ColumnType::Id),
                    new Column('field', ColumnType::String, length: 64),
                    new Column('delta', ColumnType::Integer, default: 0),
                    new Column('value_string', ColumnType::String, nullable: true, length: 255),
                    new Column('value_text', ColumnType::Text, nullable: true),
                    new Column('value_int', ColumnType::Integer, nullable: true),
                    new Column('value_datetime', ColumnType::DateTime, nullable: true),
                ],
                primaryKey: ['id'],
                indexes: [
                    // Covering indexes for the EXISTS subqueries of the Query engine.
                    'idx_string' => ['field', 'value_string', 'object_id'],
                    'idx_int' => ['field', 'value_int', 'object_id'],
                    'idx_datetime' => ['field', 'value_datetime', 'object_id'],
                ],
                uniques: ['uniq_value' => ['object_id', 'field', 'delta']],
                foreignKeys: [new ForeignKey('object_id', self::OBJECTS)],
            ),
            // The migrations that have run (since 0.0.6, schema version 6).
            new Table(
                name: self::MIGRATIONS,
                columns: [
                    new Column('id', ColumnType::String, length: 128),
                    new Column('description', ColumnType::String, length: 255),
                    new Column('applied_at', ColumnType::DateTime),
                    new Column('duration_ms', ColumnType::Integer, default: 0),
                ],
                primaryKey: ['id'],
            ),
            // Settings edited in the admin (since 0.1.1, schema version 7), e.g. the site's
            // name: name => value as text. Read through Campanella\Settings\Settings.
            new Table(
                name: self::SETTINGS,
                columns: [
                    new Column('name', ColumnType::String, length: 128),
                    new Column('value', ColumnType::Text, nullable: true),
                    new Column('updated_at', ColumnType::DateTime),
                ],
                primaryKey: ['name'],
            ),
            // Which text shows which uploaded file (since 0.1.2, schema version 8): filled
            // from the texts on save (Campanella\Media\MediaUsage), so an image's delete page
            // can list them. Deleting either side deletes the row.
            new Table(
                name: self::MEDIA_USAGE,
                columns: [
                    new Column('object_id', ColumnType::Id),
                    new Column('media_id', ColumnType::Id),
                ],
                primaryKey: ['object_id', 'media_id'],
                indexes: ['idx_media' => ['media_id', 'object_id']],
                foreignKeys: [
                    new ForeignKey('object_id', self::OBJECTS),
                    new ForeignKey('media_id', self::OBJECTS),
                ],
            ),
            // The e-mails sent, or tried (since 0.1.3, schema version 9): kept for mail.log_days
            // days (Campanella\Mail\Mailer). Never the message itself.
            new Table(
                name: self::MAIL_LOG,
                columns: [
                    new Column('id', ColumnType::Id, autoIncrement: true),
                    new Column('created_at', ColumnType::DateTime),
                    new Column('recipient', ColumnType::String, length: 255),
                    new Column('template', ColumnType::String, length: 64),
                    new Column('subject', ColumnType::String, length: 255),
                    new Column('status', ColumnType::String, length: 16),
                    new Column('error', ColumnType::Text, nullable: true),
                ],
                primaryKey: ['id'],
                indexes: ['idx_created' => ['created_at']],
            ),
            // The logins in progress (since 0.1.4, schema version 10): a hash of each one's
            // token, never the session ID (Campanella\Auth\SessionRegistry). Deleting the
            // user deletes their rows.
            new Table(
                name: self::SESSIONS,
                columns: [
                    new Column('id', ColumnType::Id, autoIncrement: true),
                    new Column('user_id', ColumnType::Id),
                    new Column('token_hash', ColumnType::String, length: 64),
                    new Column('created_at', ColumnType::DateTime),
                    new Column('last_seen_at', ColumnType::DateTime),
                    new Column('ip', ColumnType::String, length: 45),
                    new Column('user_agent', ColumnType::String, length: 255),
                ],
                primaryKey: ['id'],
                indexes: [
                    'idx_user' => ['user_id', 'last_seen_at'],
                    'idx_seen' => ['last_seen_at'],
                ],
                uniques: ['uniq_token' => ['token_hash']],
                foreignKeys: [new ForeignKey('user_id', self::OBJECTS)],
            ),
            // Throttling of attempts (e.g. login). The SHA-256 hash of the key.
            new Table(
                name: self::THROTTLE,
                columns: [
                    new Column('key_hash', ColumnType::String, length: 64),
                    new Column('hits', ColumnType::Integer, default: 0),
                    new Column('reset_at', ColumnType::DateTime),
                ],
                primaryKey: ['key_hash'],
                indexes: ['idx_reset' => ['reset_at']],
            ),
        ];
    }
}
