<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Installer;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Schedule\Scheduler;

/**
 * `php bin/campanella cron:run` (since 0.4.9): the scheduled tasks that are due (Scheduler), the
 * core's and the modules'. For cron, every five minutes:
 *
 *     0-59/5 * * * * php /path/to/site/bin/campanella cron:run
 *
 * One runs at a time: started while another runs, it does nothing. Exit code 1 if a task failed.
 */
final class CronRunCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'cron:run';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.cron_run.description';
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
        $scheduler = $container->get(Scheduler::class);
        $done = $scheduler->run($container, static function (string $name, string $status, ?Message $message) use ($output, $t, $scheduler): void {
            $task = $scheduler->tasks()[$name] ?? null;
            $line = ($task !== null ? $t->translate($task->label()) : $name) . ' (' . $name . ')' . ($message !== null ? ': ' . $message->translate($t) : '');
            if ($status === Scheduler::OK) {
                $output->success($line);
            } else {
                $output->error($line);
            }
        });
        if ($done === null) {
            $output->line($t->translate('cli.cron_run.locked'));

            return 0;
        }
        if ($done['ran'] === [] && $done['failed'] === []) {
            $output->line($t->translate('cli.cron_run.nothing'));
        }
        if ($done['left'] !== []) {
            $output->line($t->translate('cli.cron_run.left', ['tasks' => implode(', ', $done['left'])]));
        }

        return $done['failed'] === [] ? 0 : 1;
    }
}
