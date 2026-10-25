<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Installer;
use Campanella\I18n\Translator;
use Campanella\Import\Importer;
use Campanella\Import\ImportException;
use Campanella\Import\ImportRun;
use Campanella\Import\ImportTrial;

/**
 * `php bin/campanella import:run <importer> <file> [--yes]` (since 0.3.1): a trial of
 * the file's first items; with `--yes` the run itself, in batches, then its log (e.g.
 * from cron). A file of any size (the admin takes a few MB). Exit code 1 if the run
 * could not start or some items failed.
 */
final class ImportRunCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'import:run';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.import_run.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $installer = $container->get(Installer::class);
        if (!$installer->isInstalled() || $installer->needsUpgrade()) {
            $output->error($t->translate('cli.search_index.needs_upgrade'));

            return 1;
        }
        $parsed = Args::parse($args);
        [$name, $file] = [$parsed->argument(0), $parsed->argument(1)];
        if ($name === null || $file === null) {
            $output->error($t->translate('cli.import_run.usage'));

            return 1;
        }
        $importers = $container->get(Importer::class);
        $importer = $importers->find($name);
        if ($importer === null) {
            $output->error($t->translate('import.error.no_importer', ['name' => $name]));

            return 1;
        }
        try {
            if (!$parsed->flag('yes')) {
                self::trial($importers->trial($importer, $file), $t, $output);
                $output->line($t->translate('cli.import_run.needs_yes'));

                return 0;
            }
            $run = $importers->start($importer, $file, $file);
            while ($run->isRunning()) {
                $run = $importers->step($run->id, 30.0);
                $output->line($t->translate('cli.import_run.progress', ['done' => $run->position, 'total' => $run->total]));
            }
        } catch (ImportException $e) {
            $output->error(ImportRun::text($e->reason, $t));

            return 1;
        }
        self::log($run, $t, $output);

        return $run->failed > 0 || $run->status !== ImportRun::DONE ? 1 : 0;
    }

    private static function trial(ImportTrial $trial, Translator $t, Output $output): void
    {
        $output->line($t->translate('import.trial.total', ['count' => $trial->total]));
        foreach ($trial->missingSources as $source) {
            $output->error($t->translate('import.trial.missing_source', ['source' => $source]));
        }
        foreach ($trial->rows as $row) {
            $output->line(sprintf('%s #%d %s – %s', match ($row['action']) {
                ImportTrial::CREATE => '+',
                ImportTrial::UPDATE => '~',
                ImportTrial::ERROR => '✘',
                default => ' ',
            }, $row['row'], $row['key'], $t->translate('import.action.' . $row['action'])));
            if ($row['error'] !== null) {
                $output->line('    ✘ ' . ($row['field'] !== '' ? $row['field'] . ': ' : '') . ImportRun::text($row['error'], $t));
            }
        }
    }

    /** A run's counts and errors. */
    public static function log(ImportRun $run, Translator $t, Output $output): void
    {
        $output->success($t->translate('import.run.summary', [
            'created' => $run->created, 'updated' => $run->updated, 'unchanged' => $run->unchanged,
            'failed' => $run->failed, 'missing' => $run->missing,
        ]));
        foreach ($run->errors as $error) {
            $output->error(sprintf('#%d %s: %s%s', $error['row'], $error['key'], $error['field'] !== '' ? $error['field'] . ': ' : '', ImportRun::text($error['message'], $t)));
        }
    }
}
