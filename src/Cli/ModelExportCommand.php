<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Installer;
use Campanella\I18n\Translator;
use Campanella\Transfer\ModelExport;
use Campanella\Transfer\ModelFile;

/**
 * `php bin/campanella model:export [file] [--force]` (since 0.2.6): the content
 * model (the Blueprints and lists made in the admin) as a JSON file, or on the
 * standard output without a file. An existing file is overwritten only with
 * `--force`.
 */
final class ModelExportCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'model:export';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.model_export.description';
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
        $export = $container->get(ModelExport::class);
        $model = $export->model();
        $json = ModelFile::encode($model);
        $file = $parsed->argument(0);
        if ($file === null) {
            $output->raw($json);

            return 0;
        }
        if (file_exists($file) && !$parsed->flag('force')) {
            $output->error($t->translate('cli.model_export.exists', ['file' => $file]));

            return 1;
        }
        if (@file_put_contents($file, $json) === false) {
            $output->error($t->translate('cli.model_export.failed', ['file' => $file]));

            return 1;
        }
        $output->success($t->translate('cli.model_export.written', [
            'file' => $file,
            'blueprints' => count($model['blueprints']),
            'lists' => count($model['lists']),
        ]));

        return 0;
    }
}
