<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Database\DatabaseBackup;
use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\MigrationException;
use Campanella\Database\Migration\Migrator;
use Campanella\Database\Sync\SchemaSync;
use Campanella\Database\Sync\SyncPlan;
use Campanella\Database\Sync\SyncStepKind;
use Campanella\I18n\Translator;

/**
 * Bringing an existing database up to date from the command line, for `migrate`
 * and `install`: the additive changes of the definitions (SchemaSync), then the
 * pending migrations, then the additive changes again (a migration may have
 * made them possible).
 *
 * It lists what will happen; for migrations and `--prune` it asks (unless
 * `--yes`) and makes a backup first (unless `--no-backup`). Additive changes
 * alone need neither: they lose nothing.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly DatabaseBackup $backup,
        private readonly Translator $t,
        private readonly Input $input,
        private readonly Output $output,
        private readonly ?SchemaSync $sync = null,
    ) {
    }

    /** @return int Exit code */
    public function run(Args $args): int
    {
        $t = $this->t;
        $out = $this->output;
        $prune = $args->flag('prune');
        $plan = $this->sync?->plan($prune) ?? new SyncPlan();
        $pending = $this->migrator->pending();

        foreach ($plan->notes() as $note) {
            $out->line($note->message->translate($t));
        }
        if ($pending === [] && !$plan->hasWork()) {
            $this->reportBlocked($plan);
            $out->line($t->translate('cli.migrate.none'));

            return $plan->blocked() === [] ? 0 : 1;
        }
        if ($plan->hasWork()) {
            $out->line($t->translate('cli.sync.planned'));
            foreach ($plan->work() as $step) {
                $out->line('  ' . $step->message->translate($t));
            }
        }
        if ($pending !== []) {
            $out->line($t->translate('cli.migrate.pending', ['count' => count($pending)]));
            foreach ($pending as $migration) {
                $out->line('  ' . $migration->id() . '  ' . $migration->description());
            }
        }
        $this->reportBlocked($plan);
        if ($args->flag('dry-run')) {
            return 0;
        }

        // Asking and a backup: for what cannot be undone (migrations, deleting data).
        $risky = $pending !== [] || $plan->hasPrune();
        $file = null;
        if ($risky) {
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
        }

        try {
            $this->applySync($prune);
            $results = $this->migrator->run(
                function (Migration $m) use ($out): void {
                    $out->line('→ ' . $m->id() . ': ' . $m->description());
                },
                function (string $line) use ($out): void {
                    $out->line('    ' . $line);
                },
            );
            $after = $this->applySync($prune);
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
        if ($results !== []) {
            $out->line($t->translate('cli.migrate.done', ['count' => count($results)]));
        }
        if ($after->blocked() !== []) {
            $this->reportBlocked($after);

            return 1;
        }

        return 0;
    }

    /** Applies the additive changes, reporting each. */
    private function applySync(bool $prune): SyncPlan
    {
        $plan = $this->sync?->apply($prune) ?? new SyncPlan();
        foreach ($plan->work() as $step) {
            $this->output->success($step->message->translate($this->t));
        }

        return $plan;
    }

    private function reportBlocked(SyncPlan $plan): void
    {
        foreach ($plan->steps as $step) {
            if ($step->kind === SyncStepKind::Blocked) {
                $this->output->error($step->message->translate($this->t));
            }
        }
    }

    /** A file size for messages: `12.3 MB`, `840 KB`. */
    public static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 1, '.', '') . ' MB'
            : max(1, (int) ceil($bytes / 1024)) . ' KB';
    }
}
