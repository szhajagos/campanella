<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Module\ModuleManager;

/**
 * `php bin/campanella module:disable <name>` (since 0.4.6): turns the module off, without
 * loading any module (so one that stops the site can be turned off here too); not while a
 * module that is on needs it.
 */
final class ModuleDisableCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'module:disable';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.module_disable.description';
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
        $problem = $container->get(ModuleManager::class)->disable($name);
        if ($problem !== null) {
            $output->error($t->translate($problem->key, $problem->params));

            return 1;
        }
        $output->success($t->translate('modules.disabled', ['label' => $name]));

        return 0;
    }
}
