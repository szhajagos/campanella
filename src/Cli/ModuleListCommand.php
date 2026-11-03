<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Module\ModuleRegistry;
use Campanella\Module\ModuleState;

/**
 * `php bin/campanella module:list` (since 0.4.6): the modules of the modules folder, their
 * versions and where each stands, with the reasons when one cannot be used. Exits with 1 if a
 * module is broken.
 */
final class ModuleListCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'module:list';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.module_list.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $modules = $container->get(ModuleRegistry::class);
        $all = $modules->all();
        if ($all === []) {
            $output->line($t->translate('modules.empty', ['folder' => basename($modules->directory())]));

            return 0;
        }
        $broken = false;
        foreach ($all as $state) {
            $broken = $broken || $state->status === ModuleState::BROKEN;
            $output->line(mb_str_pad($state->name, 24) . mb_str_pad($state->manifest->version ?? '–', 12) . $t->translate('modules.status.' . $state->status));
            foreach ($state->problems as $problem) {
                $output->line('    ' . $t->translate($problem->key, $problem->params));
            }
        }

        return $broken ? 1 : 0;
    }
}
