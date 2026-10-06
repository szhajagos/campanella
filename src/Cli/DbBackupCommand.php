<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\DatabaseBackup;
use Campanella\I18n\Translator;

/**
 * `php bin/campanella db:backup [--plain]`: saves Campanella's tables into an SQL
 * file in `var/backups/` (compressed, `.sql.gz`, unless `--plain` or no zlib).
 */
final class DbBackupCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'db:backup';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.backup.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $backup = $container->get(DatabaseBackup::class);
        try {
            $file = $backup->create(
                !Args::parse($args)->flag('plain'),
                static function (string $table, int $rows) use ($output): void {
                    $output->line(sprintf('  %-32s %d', $table, $rows));
                },
            );
        } catch (\RuntimeException $e) {
            $output->error($t->translate('cli.backup.failed', ['error' => $e->getMessage()]));

            return 1;
        }
        $output->success($t->translate('cli.backup.created', ['file' => $file, 'size' => MigrationRunner::size((int) filesize($file))]));
        $output->line($t->translate('cli.backup.note'));

        return 0;
    }
}
