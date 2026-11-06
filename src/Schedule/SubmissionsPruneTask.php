<?php

declare(strict_types=1);

namespace Campanella\Schedule;

use Campanella\Core\Container;
use Campanella\I18n\Message;
use Campanella\Security\Throttle;
use Campanella\Service\SubmissionService;

/**
 * The core's daily task (since 0.4.9): the contact form's messages older than
 * `contact.keep_days` deleted, and the expired throttling rows (as `submissions:prune`).
 */
final class SubmissionsPruneTask implements ScheduledTask
{
    #[\Override]
    public function name(): string
    {
        return 'submissions_prune';
    }

    #[\Override]
    public function label(): string
    {
        return 'cron.task.submissions_prune';
    }

    #[\Override]
    public function interval(): int
    {
        return 86400;
    }

    #[\Override]
    public function pending(Container $container): array
    {
        return [];
    }

    #[\Override]
    public function run(Container $container, float $seconds): Message
    {
        $submissions = $container->get(SubmissionService::class);
        $deleted = 0;
        $until = microtime(true) + $seconds;
        do {
            $batch = $submissions->prune();
            $deleted += $batch;
        } while ($batch === SubmissionService::PRUNE_BATCH && microtime(true) < $until);
        $container->get(Throttle::class)->purge();

        return new Message('cron.result.submissions_prune', ['count' => $deleted, 'days' => $submissions->keepDays()]);
    }
}
