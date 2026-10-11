<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\I18n\Translator;
use Campanella\Security\Throttle;
use Campanella\Service\SubmissionService;

/**
 * `php bin/campanella submissions:prune`: deletes the contact form's messages older
 * than `contact.keep_days`, and the expired throttling rows (since 0.1.5). The site does
 * this by itself after each new message and when an administrator opens the dashboard
 * or the messages; on a quiet site, run it from cron (e.g. daily) to keep the promise
 * the form makes.
 */
final class SubmissionsPruneCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'submissions:prune';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.submissions_prune.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $submissions = $container->get(SubmissionService::class);
        $deleted = 0;
        do {
            $batch = $submissions->prune();
            $deleted += $batch;
        } while ($batch === SubmissionService::PRUNE_BATCH);
        $container->get(Throttle::class)->purge();
        $output->success($t->translate('cli.submissions_prune.done', ['count' => $deleted, 'days' => $submissions->keepDays()]));

        return 0;
    }
}
