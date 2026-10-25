<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Import\Importer;
use Campanella\Import\ImportException;

/**
 * `php bin/campanella import:undo <run> [--yes]` (since 0.3.1): undoes a run of an
 * importer: deletes the objects it made, after a backup. Without `--yes` it only
 * says how many there are.
 */
final class ImportUndoCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'import:undo';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.import_undo.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $parsed = Args::parse($args);
        $id = $parsed->argument(0);
        $importers = $container->get(Importer::class);
        $run = $id !== null && ctype_digit($id) ? $importers->run((int) $id) : null;
        if ($run === null) {
            $output->error($t->translate('import.error.no_run'));

            return 1;
        }
        if (!$parsed->flag('yes')) {
            $output->line($t->translate('cli.import_undo.would', ['count' => $run->created, 'run' => $run->id]));

            return 0;
        }
        try {
            $deleted = $importers->undo($run->id);
        } catch (ImportException $e) {
            $output->error($e->reason->translate($t));

            return 1;
        }
        $output->success($t->translate('import.run.undone', ['count' => $deleted]));

        return 0;
    }
}
