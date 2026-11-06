<?php

declare(strict_types=1);

namespace CampanellaFeeds;

use Campanella\Core\Container;
use Campanella\I18n\Message;
use Campanella\Schedule\Scheduler;

/**
 * The module's scheduled task: every five minutes, the sources that are due (each at its own
 * interval), one after another while the time lasts.
 */
final class FetchTask implements \Campanella\Schedule\ScheduledTask
{
    public function name(): string
    {
        return 'feeds:fetch';
    }

    public function label(): string
    {
        return 'feeds.task';
    }

    public function interval(): int
    {
        return Scheduler::MIN_INTERVAL;
    }

    public function pending(Container $container): array
    {
        $due = $container->get(SourceStore::class)->due(Scheduler::now());

        return $due === [] ? [new Message('feeds.pending_none')] : [new Message('feeds.pending', ['count' => count($due), 'sources' => implode(', ', array_map(static fn (Source $s): string => $s->importer . ' (' . $s->host() . ')', array_slice($due, 0, 5)))])];
    }

    public function run(Container $container, float $seconds): Message
    {
        $feeds = $container->get(Feeds::class);
        $until = microtime(true) + $seconds;
        $done = ['ok' => 0, 'failed' => 0, 'left' => 0];
        foreach ($container->get(SourceStore::class)->due(Scheduler::now()) as $source) {
            $left = $until - microtime(true);
            if ($left < 2) {
                ++$done['left'];
                continue;
            }
            $feeds->process($source, $left);
            $after = $container->get(SourceStore::class)->find($source->id);
            ++$done[$after?->lastStatus === Source::FAILED ? 'failed' : 'ok'];
        }

        return new Message('feeds.task_result', $done);
    }
}
