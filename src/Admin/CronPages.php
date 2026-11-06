<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Core\Container;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\Schedule\Scheduler;
use Campanella\Security\Csrf;
use Closure;

/**
 * The scheduled tasks in the admin (since 0.4.9), System → Scheduled tasks, for the system roles:
 *
 *   /admin/system/cron                       when cron:run last ran (a warning if it does not
 *                                            seem to), the line for cron, each task: where it
 *                                            stands, when it is due, what its next run would
 *                                            do; the last runs
 *   POST /admin/system/cron/<task>/request   the task in the next cron:run, whatever its
 *                                            interval (nothing runs in the admin's request)
 */
final class CronPages
{
    public const string PATH = 'system/cron';

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Container $container,
        private readonly string $root,
        private readonly ?Csrf $csrf = null,
        private readonly ?Flash $flash = null,
        private readonly ?AdminAccess $access = null,
    ) {
    }

    /**
     * @param list<string> $segments The path after /admin/system/cron
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, array $segments, Closure $render): Response
    {
        if ($segments === [] && !$request->isPost()) {
            return $this->listing($render);
        }
        if (count($segments) !== 2 || $segments[1] !== 'request') {
            throw HttpException::notFound();
        }
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $task = $this->scheduler->tasks()[$segments[0]] ?? null;
        if ($task === null) {
            throw HttpException::notFound();
        }
        if (!$this->csrf?->isValid($request)) {
            $this->flash?->add(Flash::DANGER, 'auth.form_expired');
        } else {
            $this->scheduler->request($task->name());
            $this->flash?->add(Flash::SUCCESS, new Message('cron.requested', ['task' => $task->name()]));
        }

        return Response::redirect($request->basePath . ($this->access?->path(self::PATH) ?? '/admin/' . self::PATH), 303);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function listing(Closure $render): Response
    {
        $states = $this->scheduler->states();
        $now = Scheduler::now();
        $tasks = [];
        $labels = [];
        foreach ($this->scheduler->tasks() as $name => $task) {
            $state = $states[$name] ?? null;
            try {
                $pending = $task->pending($this->container);
            } catch (\Throwable $e) {
                $pending = [new Message('cron.error', ['error' => mb_substr($e->getMessage(), 0, 300)])];
            }
            $labels[$name] = $task->label();
            $tasks[] = [
                'name' => $name,
                'label' => $task->label(),
                'source' => str_contains($name, ':') ? strstr($name, ':', true) : null,
                'interval' => self::interval($task->interval()),
                'state' => $state,
                'due' => $this->scheduler->isDue($name, $now),
                'pending' => $pending,
            ];
        }

        return $render('cron', 'cron', [
            'title' => 'cron.title',
            'cron' => $this->scheduler->lastCron(),
            'stale' => $this->scheduler->isStale($now),
            'line' => '*/5 * * * * php ' . $this->root . '/bin/campanella cron:run',
            'tasks' => $tasks,
            'labels' => $labels,
            'runs' => $this->scheduler->runs(),
        ]);
    }

    /** An interval for people: minutes, hours or days. */
    private static function interval(int $seconds): Message
    {
        return match (true) {
            $seconds === 86400 => new Message('cron.every_day'),
            $seconds === 3600 => new Message('cron.every_hour'),
            $seconds % 86400 === 0 => new Message('cron.every_days', ['count' => intdiv($seconds, 86400)]),
            $seconds % 3600 === 0 => new Message('cron.every_hours', ['count' => intdiv($seconds, 3600)]),
            default => new Message('cron.every_minutes', ['count' => intdiv($seconds, 60)]),
        };
    }
}
