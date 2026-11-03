<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Module\ModuleManager;

/**
 * `php bin/campanella module:enable <name>` (since 0.4.6): tries the module (ModuleTrial) and,
 * if nothing is wrong, turns it on; else lists the problems and exits with 1.
 */
final class ModuleEnableCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'module:enable';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.module_enable.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $name = $args[0] ?? '';
        if ($name === '') {
            $output->error($t->translate('cli.module.name_missing', ['command' => $this->name()]));

            return 1;
        }
        $problems = $container->get(ModuleManager::class)->enable($name);
        if ($problems !== []) {
            $output->error($t->translate('modules.trial_failed'));
            foreach ($problems as $problem) {
                $output->line('  ' . $t->translate($problem->key, $problem->params));
            }

            return 1;
        }
        $output->success($t->translate('modules.enabled', ['label' => $name]));

        return 0;
    }
}
