<?php

declare(strict_types=1);

namespace Campanella\Database\Migration;

/**
 * One step of changing an existing database: a new column, an index, moving or
 * transforming data. Forward only: there is no `down()`, a backup is made
 * before migrating instead (`db:backup`).
 *
 * Rules:
 *
 * - **SQL only.** A migration works through the MigrationContext, never with the
 *   model classes (ObjectRepository, capabilities): a migration written today
 *   may run a year later against a newer model.
 * - **Small and repeatable.** MySQL cannot roll back DDL, so if a migration
 *   fails halfway, it runs again from the start next time. The context's
 *   helpers skip what is already done (`addColumn()` on an existing column).
 * - **Never changed once released.** A fix is a new migration.
 *
 * The ID is `<source>:<name>`, e.g. `core:0006_roles_multi_value`; it is
 * recorded when the migration has run, so it must never change.
 */
interface Migration
{
    public function id(): string;

    /** A short English description, shown before running it. */
    public function description(): string;

    public function up(MigrationContext $m): void;
}
