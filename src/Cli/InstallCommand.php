<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Connection;
use Campanella\Database\Installer;

final class InstallCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'install';
    }

    #[\Override]
    public function description(): string
    {
        return 'Létrehozza az adatbázistáblákat (--sql: csak kiírja az SQL-t)';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $installer = $container->get(Installer::class);

        if (in_array('--sql', $args, true)) {
            $output->line($installer->sql());

            return 0;
        }

        $db = $container->get(Connection::class);
        $output->line('Adatbázis-szerver: ' . $db->serverVersion());
        foreach ($installer->install() as $table) {
            $output->success($table);
        }
        $output->line();
        $output->line('Kész. Példatartalomhoz: php bin/campanella seed');

        return 0;
    }
}
