<?php

declare(strict_types=1);

namespace Campanella\Schedule;

use Campanella\Core\Container;
use Campanella\I18n\Message;

/**
 * Work the site does by itself, on a schedule (since 0.4.9): run by `php bin/campanella cron:run`,
 * which cron starts every five minutes, when its interval has passed since it last started (or
 * an administrator asked for it on System → Scheduled tasks). Never run by a visitor's request.
 *
 * The core's: SubmissionsPruneTask. A module's: ModuleContext::scheduled(), its name starting
 * with `<module>:`. Made with `new`, so it gets what it needs from the container in run().
 */
interface ScheduledTask
{
    /** Its unique name: the core's `[a-z_]`, a module's `<module>:<name>`. */
    public function name(): string;

    /** Its label: a message key (a module's: of its translations). */
    public function label(): string;

    /** How often it runs, in seconds (at least Scheduler::MIN_INTERVAL). */
    public function interval(): int;

    /**
     * What its next run would do, for System → Scheduled tasks (e.g. the sources due); [] if
     * nothing to name.
     *
     * @return list<Message>
     */
    public function pending(Container $container): array;

    /**
     * Does its work, in about `$seconds` at most (what is left waits for the next run). Its
     * result for the log, or null; an exception is a failed run (its message logged).
     */
    public function run(Container $container, float $seconds): ?Message;
}
