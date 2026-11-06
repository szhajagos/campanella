<?php

declare(strict_types=1);

namespace CampanellaFeeds;

use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\MigrationContext;
use Campanella\Database\Schema\Column;
use Campanella\Database\Schema\ColumnType;
use Campanella\Database\Schema\Table;

/** The sources' table: one source for each importer, where it stands. */
final class CreateSources implements Migration
{
    public function id(): string
    {
        return 'feeds:0001_sources';
    }

    public function description(): string
    {
        return 'The feeds module\'s sources.';
    }

    public function up(MigrationContext $m): void
    {
        if ($m->tableExists('feeds_sources')) {
            return;
        }
        $m->createTable(new Table(
            name: 'feeds_sources',
            columns: [
                new Column('id', ColumnType::Id, autoIncrement: true),
                new Column('importer', ColumnType::String, length: 64),
                new Column('url', ColumnType::Text),
                new Column('kind', ColumnType::String, length: 16, default: 'file'),
                new Column('every_minutes', ColumnType::Integer, default: 60),
                new Column('enabled', ColumnType::Boolean, default: 1),
                new Column('etag', ColumnType::String, length: 255, nullable: true),
                new Column('last_modified', ColumnType::String, length: 100, nullable: true),
                new Column('content_hash', ColumnType::String, length: 64, nullable: true),
                new Column('run_id', ColumnType::Id, nullable: true),
                new Column('next_due', ColumnType::DateTime, nullable: true),
                new Column('last_fetched', ColumnType::DateTime, nullable: true),
                new Column('last_status', ColumnType::String, length: 16, nullable: true),
                new Column('last_message', ColumnType::Text, nullable: true),
                new Column('failures', ColumnType::Integer, default: 0),
                new Column('created_at', ColumnType::DateTime),
                new Column('updated_at', ColumnType::DateTime),
            ],
            primaryKey: ['id'],
            uniques: ['uniq_importer' => ['importer']],
        ));
    }
}
