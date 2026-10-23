<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Installer;
use Campanella\I18n\Translator;
use Campanella\Transfer\ImportItem;
use Campanella\Transfer\ImportPlan;
use Campanella\Transfer\ModelFile;
use Campanella\Transfer\ModelImport;

/**
 * `php bin/campanella model:import <file> [--yes]` (since 0.2.6): shows what
 * importing the content model file would do; with `--yes` it does it, if there
 * are no errors (a backup first, when objects or values are affected). Nothing
 * on the site is deleted.
 */
final class ModelImportCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'model:import';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.model_import.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        if (!$container->get(Installer::class)->isInstalled()) {
            $output->error($t->translate('admin.system.schema_missing'));

            return 1;
        }
        $parsed = Args::parse($args);
        $file = $parsed->argument(0);
        if ($file === null) {
            $output->error($t->translate('cli.model_import.usage'));

            return 1;
        }
        $size = is_file($file) ? filesize($file) : false;
        if ($size === false || !is_readable($file)) {
            $output->error($t->translate('cli.model_import.unreadable', ['file' => $file]));

            return 1;
        }
        if ($size > ModelFile::MAX_BYTES) {
            $output->error($t->translate('transfer.error.too_large'));

            return 1;
        }
        try {
            $model = ModelFile::decode((string) file_get_contents($file));
        } catch (\InvalidArgumentException $e) {
            $output->error($t->translate('transfer.error.file', ['reason' => $e->getMessage()]));

            return 1;
        }

        $import = $container->get(ModelImport::class);
        $plan = $import->plan($model);
        self::print($plan, $t, $output);
        if ($plan->hasErrors()) {
            $output->error($t->translate('transfer.has_errors'));

            return 1;
        }
        if (!$plan->hasChanges()) {
            $output->success($t->translate('transfer.nothing'));

            return 0;
        }
        if (!$parsed->flag('yes')) {
            $output->line($t->translate('cli.model_import.needs_yes'));

            return 0;
        }
        try {
            $backup = $import->apply($plan);
        } catch (\RuntimeException $e) {
            $output->error($t->translate('transfer.failed', ['error' => $e->getMessage()]));

            return 1;
        }
        if ($backup !== null) {
            $output->line($t->translate('cli.model_import.backup', ['file' => $backup]));
        }
        $output->success($t->translate('transfer.done', ['blueprints' => count($plan->blueprints), 'lists' => count($plan->lists)]));

        return 0;
    }

    private static function print(ImportPlan $plan, Translator $t, Output $output): void
    {
        foreach ($plan->errors as $error) {
            $output->error($error->translate($t));
        }
        foreach ($plan->items as $item) {
            $output->line(sprintf(
                '%s %s: %s (%s) – %s',
                match ($item->action) {
                    ImportItem::CREATE => '+',
                    ImportItem::UPDATE => '~',
                    ImportItem::ERROR => '✘',
                    default => ' ',
                },
                $t->translate('transfer.kind.' . $item->kind),
                $item->label,
                $item->name,
                $t->translate('transfer.action.' . $item->action),
            ));
            foreach ($item->changes as $change) {
                $output->line('    ' . $change->translate($t));
            }
            foreach ($item->warnings as $warning) {
                $output->line('    ! ' . $warning->translate($t));
            }
            if ($item->error !== null) {
                $output->line('    ✘ ' . $item->error->translate($t));
            }
        }
        if ($plan->database !== []) {
            $output->line();
            $output->line($t->translate('transfer.database'));
            foreach ($plan->database as $step) {
                $output->line('  - ' . $step->translate($t));
            }
        }
        if ($plan->backup) {
            $output->line($t->translate('transfer.backup_first'));
        }
    }
}
