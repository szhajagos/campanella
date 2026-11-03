<?php

declare(strict_types=1);

namespace Tablas;

use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\MigrationContext;

/** A newer version's migration (a test): a column more. */
final class Oszlop implements Migration
{
    public function id(): string
    {
        return 'tablas:0002_oszlop';
    }

    public function description(): string
    {
        return 'A column more.';
    }

    public function up(MigrationContext $m): void
    {
        $m->sql('ALTER TABLE {tablas_sor} ADD COLUMN megjegyzes VARCHAR(50) NULL');
    }
}
