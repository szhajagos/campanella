<?php

declare(strict_types=1);

namespace Tablas;

use Campanella\Database\Migration\MigrationContext;
use Campanella\Module\ModuleContext;
use Campanella\Module\Uninstallable;

/** A test module with a table of its own, whose data can be removed (since 0.4.7). */
final class Module implements \Campanella\Module\Module, Uninstallable
{
    /** @var list<class-string> The migrations (a test adds one, as a newer version would) */
    public static array $migrations = [Sorok::class];

    public function register(ModuleContext $context): void
    {
        foreach (self::$migrations as $migration) {
            $context->migration($migration);
        }
    }

    public function uninstall(MigrationContext $m): void
    {
        $m->sql('DROP TABLE IF EXISTS {tablas_sor}');
        $m->db()->execute("DELETE FROM {settings} WHERE name LIKE 'tablas.%'");
    }
}
