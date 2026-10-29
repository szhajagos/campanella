<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Connection;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Installer;
use Campanella\Layout\LayoutSetup;
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
        $tables = static function () use ($installer, $output, $t): void {
            foreach ($installer->install() as $table) {
                $output->success($table);
            }
            $output->line();
            $output->line($t->translate('cli.install.done'));
        };
        if (!$existing) {
            $tables();
            self::layoutSetup($container, $output);

            return 0;
        }

        // An existing installation: the changes and migrations of the new version. The
        // tables and the schema version are written after the upgrade's backup
        // (since 0.2.0), so the backup holds the old version's database.
        $code = (new MigrationRunner(
            $container->get(Migrator::class),
            $container->get(DatabaseBackup::class),
            $t,
            $this->input,
            $output,
            $container->get(SchemaSync::class),
            $installer->needsUpgrade(),
            static function () use ($tables, $output): void {
                $tables();
                $output->line();
            },
        ))->run(Args::parse($args));
        if ($code === 0) {
            self::layoutSetup($container, $output);
        }

        return $code;
    }

    /**
     * The header and footer as components, once (since 0.3.4; LayoutSetup): after an
     * installation or an upgrade. Also run by `migrate`.
     */
    public static function layoutSetup(Container $container, Output $output): void
    {
        if (!$container->has(LayoutSetup::class) || !$container->get(Installer::class)->isInstalled()) {
            return;
        }
        $made = $container->get(LayoutSetup::class)->ensureQuietly();
        if ($made !== null) {
            $t = $container->get(Translator::class);
            $output->success($t->translate('cli.install.layout', ['components' => implode(', ', $made)]));
        }
    }
}
