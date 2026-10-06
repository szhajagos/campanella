<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Connection;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Installer;
use Campanella\Database\Migration\Migrator;
use Campanella\I18n\Translator;

/**
 * `php bin/campanella install [--sql] [--yes] [--no-backup]`: creates the
 * missing tables. On an existing installation the pending migrations run too,
 * as with `migrate` (after a backup, asking first unless `--yes`).
 */
final class InstallCommand implements Command
{
    public function __construct(private readonly Input $input = new Input())
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'install';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.install.description';
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
        $t = $container->get(Translator::class);
        $output->line($t->translate('cli.install.server', ['version' => $db->serverVersion()]));
        foreach ($installer->install() as $table) {
            $output->success($table);
        }
        $output->line();
        $output->line($t->translate('cli.install.done'));

        // An existing installation: the migrations of the new version.
        if ($installer->pendingMigrations() === []) {
            return 0;
        }
        $output->line();

        return (new MigrationRunner(
            $container->get(Migrator::class),
            $container->get(DatabaseBackup::class),
            $t,
            $this->input,
            $output,
        ))->run(Args::parse($args));
    }
}
