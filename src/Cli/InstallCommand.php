<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Connection;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Installer;
use Campanella\Database\UnsupportedUpgradeException;
use Campanella\Database\Migration\Migrator;
use Campanella\Database\Sync\SchemaSync;
use Campanella\I18n\Translator;

/**
 * `php bin/campanella install [--sql] [--yes] [--no-backup] [--prune]`: creates
 * the missing tables. On an existing installation it also does what `migrate`
 * does: the additive changes, then the pending migrations (after a backup,
 * asking first unless `--yes`).
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
        $existing = $installer->isInstalled();
        try {
            $installer->assertUpgradable();
        } catch (UnsupportedUpgradeException $e) {
            $output->error($e->reason->translate($t));

            return 1;
        }
        foreach ($installer->install() as $table) {
            $output->success($table);
        }
        $output->line();
        $output->line($t->translate('cli.install.done'));

        // An existing installation: the changes and migrations of the new version.
        if (!$existing) {
            return 0;
        }
        $output->line();

        return (new MigrationRunner(
            $container->get(Migrator::class),
            $container->get(DatabaseBackup::class),
            $t,
            $this->input,
            $output,
            $container->get(SchemaSync::class),
        ))->run(Args::parse($args));
    }
}
