<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Database\DatabaseBackup;
use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\MigrationException;
use Campanella\Database\Migration\Migrator;
use Campanella\I18n\Translator;

/**
 * Running the pending migrations from the command line, for `migrate` and
 * `install`: lists them, asks (unless `--yes`), makes a backup (unless
 * `--no-backup`), runs them and reports each.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly DatabaseBackup $backup,
        private readonly Translator $t,
        private readonly Input $input,
        private readonly Output $output,
    ) {
    }

    /** @return int Exit code */
    public function run(Args $args): int
    {
        $t = $this->t;
        $out = $this->output;
        $pending = $this->migrator->pending();
        if ($pending === []) {
            $out->line($t->translate('cli.migrate.none'));

            return 0;
        }
        $out->line($t->translate('cli.migrate.pending', ['count' => count($pending)]));
        foreach ($pending as $migration) {
            $out->line('  ' . $migration->id() . '  ' . $migration->description());
        }
        if ($args->flag('dry-run')) {
            return 0;
        }

        $backup = !$args->flag('no-backup');
        $out->line();
        $out->line($t->translate($backup ? 'cli.migrate.warning' : 'cli.migrate.warning_no_backup'));
        if (!$args->flag('yes')) {
            if (!$this->input->isInteractive()) {
                $out->error($t->translate('cli.migrate.needs_yes'));

                return 1;
            }
            $answer = mb_strtolower(trim($this->input->ask($t->translate('cli.migrate.confirm') . ' ')), 'UTF-8');
            if (!in_array($answer, ['y', 'yes', 'i', 'igen'], true)) {
                $out->line($t->translate('cli.migrate.cancelled'));

                return 1;
            }
        }

        $file = null;
        if ($backup) {
            try {
                $file = $this->backup->create();
            } catch (\RuntimeException $e) {
                $out->error($t->translate('cli.backup.failed', ['error' => $e->getMessage()]));
                $out->error($t->translate('cli.migrate.not_without_backup'));

                return 1;
            }
            $out->success($t->translate('cli.backup.created', ['file' => $file, 'size' => self::size((int) filesize($file))]));
        }

        try {
            $results = $this->migrator->run(
                function (Migration $m) use ($out): void {
                    $out->line('→ ' . $m->id() . ': ' . $m->description());
                },
                function (string $line) use ($out): void {
                    $out->line('    ' . $line);
                },
            );
        } catch (MigrationException $e) {
            foreach ($e->applied as $done) {
                $out->success($done->id);
            }
            $out->error($e->reason->translate($t) . ($e->getPrevious() !== null ? ' ' . $e->getPrevious()->getMessage() : ''));
            if ($file !== null) {
                $out->error($t->translate('cli.migrate.restore', ['file' => $file]));
            }

            return 1;
        }
        foreach ($results as $result) {
            $out->success($result->id . ' (' . $result->durationMs . ' ms)');
        }
        $out->line($t->translate('cli.migrate.done', ['count' => count($results)]));

        return 0;
    }

    /** A file size for messages: `12.3 MB`, `840 KB`. */
    public static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 1, '.', '') . ' MB'
            : max(1, (int) ceil($bytes / 1024)) . ' KB';
    }
}
