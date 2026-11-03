<?php

declare(strict_types=1);

namespace Tablas;

use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\MigrationContext;

/** The module's table (a test). */
final class Sorok implements Migration
{
    public function id(): string
    {
        return 'tablas:0001_sorok';
    }

    public function description(): string
    {
        return 'The rows of the test module.';
    }

    public function up(MigrationContext $m): void
    {
        $m->sql('CREATE TABLE IF NOT EXISTS {tablas_sor} (id INT NOT NULL PRIMARY KEY, nev VARCHAR(50) NOT NULL)');
    }
}
