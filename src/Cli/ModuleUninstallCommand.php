<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Module\ModuleManager;

/**
 * `php bin/campanella module:uninstall <name> --yes` (since 0.4.7): removes the data of a module
 * that is off, for good, after a backup (ModuleManager::uninstall()). Without --yes it only says
 * what it would do.
 */
final class ModuleUninstallCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'module:uninstall';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.module_uninstall.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $name = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')))[0] ?? '';
        if ($name === '') {
            $output->error($t->translate('cli.module.name_missing', ['command' => $this->name()]));

            return 1;
        }
        if (!in_array('--yes', $args, true)) {
            $output->line($t->translate('modules.uninstall_warning'));
            $output->line($t->translate('cli.module_uninstall.confirm', ['name' => $name]));

            return 1;
        }
        $problem = $container->get(ModuleManager::class)->uninstall($name);
        if ($problem !== null) {
            $output->error($t->translate($problem->key, $problem->params));

            return 1;
        }
        $output->success($t->translate('modules.uninstalled', ['label' => $name]));

        return 0;
    }
}
