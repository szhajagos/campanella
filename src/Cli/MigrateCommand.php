<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Installer;
use Campanella\Database\UnsupportedUpgradeException;
use Campanella\Database\Migration\Migrator;
use Campanella\Database\Sync\SchemaSync;
use Campanella\I18n\Translator;

/**
 * `php bin/campanella migrate [--dry-run] [--yes] [--no-backup] [--prune]`:
 * applies the additive changes of the definitions (new tables, columns,
 * indexes, capabilities added to Blueprints), then runs the pending migrations
 * after a backup. `--dry-run` only lists them; `--yes` does not ask (needed when
 * not run from a terminal); `--prune` deletes the data of capabilities removed
 * from Blueprints.
 */
final class MigrateCommand implements Command
{
    public function __construct(private readonly Input $input = new Input())
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'migrate';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.migrate.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $installer = $container->get(Installer::class);
        if (!$installer->isInstalled()) {
            $output->error($t->translate('admin.system.schema_missing'));

            return 1;
        }
        try {
            $installer->assertUpgradable();
        } catch (UnsupportedUpgradeException $e) {
            $output->error($e->reason->translate($t));

            return 1;
        }

        // New tables (e.g. of a new capability) and the schema version: after the
        // backup of an upgrade (MigrationRunner), so it holds the old database.
        return (new MigrationRunner(
            $container->get(Migrator::class),
            $container->get(DatabaseBackup::class),
            $t,
            $this->input,
            $output,
            $container->get(SchemaSync::class),
            $installer->needsUpgrade(),
            static function () use ($installer): void {
                $installer->install();
            },
        ))->run(Args::parse($args));
    }
}
