<?php

declare(strict_types=1);

namespace Campanella\Schedule;

use Campanella\Core\Container;
use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\I18n\Message;
use Campanella\Model\FieldType;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The scheduled tasks (since 0.4.9): which there are (the core's and the modules'), where each
 * stands (`scheduled_tasks`), their runs' log (`scheduled_runs`, the last LOG_SIZE), and run():
 * the tasks due, one after another, one cron:run at a time (a lock file), in about BUDGET
 * seconds (what is left waits for the next). When cron:run last started and finished is kept
 * in the `system` table, so System → Scheduled tasks can say when cron does not run.
 */
final class Scheduler
{
    public const int MIN_INTERVAL = 300;
    public const int BUDGET = 240;
    public const int LOG_SIZE = 100;

    /** After this long without a finished cron:run, the page warns. */
    public const int STALE_AFTER = 1800;

    public const string OK = 'ok';
    public const string FAILED = 'failed';
    public const string RUNNING = 'running';

    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{1,40}(:[a-z0-9][a-z0-9_-]{1,40})?$/D';

    /** @var array<string, ScheduledTask>|null */
    private ?array $tasks = null;

    /** @param Closure(): list<ScheduledTask> $taskFactory */
    public function __construct(
        private readonly Connection $db,
        private readonly Closure $taskFactory,
        private readonly string $lockFile,
    ) {
    }

    /** @return array<string, ScheduledTask> Every task, by name (the core's first) */
    public function tasks(): array
    {
        if ($this->tasks !== null) {
            return $this->tasks;
        }
        $tasks = [];
        foreach (($this->taskFactory)() as $task) {
            $name = $task->name();
            if (preg_match(self::NAME_PATTERN, $name) === 1 && !isset($tasks[$name]) && $task->interval() >= self::MIN_INTERVAL) {
                $tasks[$name] = $task;
            } else {
                error_log("Campanella: the scheduled task {$name} is left out (its name is taken or not valid, or it runs more often than every " . self::MIN_INTERVAL . ' seconds).');
            }
        }

        return $this->tasks = $tasks;
    }

    /**
     * Where each task stands, by name: next_due, requested, last_started, last_finished,
     * last_status, last_message (a Message or null), failures.
     *
     * @return array<string, array{next_due: ?string, requested: bool, last_started: ?string, last_finished: ?string, last_status: ?string, last_message: ?Message, failures: int}>
     */
    public function states(): array
    {
        $states = [];
        foreach ($this->db->fetchAll('SELECT * FROM {scheduled_tasks}') as $row) {
            $states[(string) $row['name']] = [
                'next_due' => $row['next_due'] === null ? null : (string) $row['next_due'],
                'requested' => (bool) $row['requested'],
                'last_started' => $row['last_started'] === null ? null : (string) $row['last_started'],
                'last_finished' => $row['last_finished'] === null ? null : (string) $row['last_finished'],
                'last_status' => $row['last_status'] === null ? null : (string) $row['last_status'],
                'last_message' => self::message($row['last_message']),
                'failures' => (int) $row['failures'],
            ];
        }

        return $states;
    }

    /** Whether the task is due at that time: asked for, never run, or its time has come. */
    public function isDue(string $name, ?DateTimeImmutable $now = null): bool
    {
        $state = $this->states()[$name] ?? null;

        return $state === null || $state['requested'] || $state['next_due'] === null || $state['next_due'] <= self::format($now ?? self::now());
    }

    /** Asks for the task in the next cron:run, whatever its interval; false if there is no such task. */
    public function request(string $name): bool
    {
        if (!isset($this->tasks()[$name])) {
            return false;
        }
        $this->upsert($name, ['requested' => 1]);

        return true;
    }

    /**
     * When cron:run last started and finished, and what it ran; null if it never ran.
     *
     * @return array{started: string, finished: ?string, ran: int, failed: int}|null
     */
    public function lastCron(): ?array
    {
        $values = [];
        foreach ($this->db->fetchAll("SELECT name, value FROM {system} WHERE name IN ('cron_started', 'cron_finished', 'cron_result')") as $row) {
            $values[(string) $row['name']] = (string) $row['value'];
        }
        if (!isset($values['cron_started'])) {
            return null;
        }
        $result = json_decode($values['cron_result'] ?? '', true);

        return [
            'started' => $values['cron_started'],
            'finished' => $values['cron_finished'] ?? null,
            'ran' => (int) (is_array($result) ? ($result['ran'] ?? 0) : 0),
            'failed' => (int) (is_array($result) ? ($result['failed'] ?? 0) : 0),
        ];
    }

    /** Whether cron:run has not finished for STALE_AFTER seconds (or never ran): it does not seem to run. */
    public function isStale(?DateTimeImmutable $now = null): bool
    {
        $finished = $this->lastCron()['finished'] ?? null;

        return $finished === null || $finished < self::format(($now ?? self::now())->modify('-' . self::STALE_AFTER . ' seconds'));
    }

    /**
     * The last runs, newest first.
     *
     * @return list<array{id: int, task: string, started_at: string, finished_at: ?string, status: string, message: ?Message}>
     */
    public function runs(int $limit = self::LOG_SIZE): array
    {
        $runs = [];
        foreach ($this->db->fetchAll('SELECT * FROM {scheduled_runs} ORDER BY id DESC LIMIT ' . max(1, min(self::LOG_SIZE, $limit))) as $row) {
            $runs[] = [
                'id' => (int) $row['id'],
                'task' => (string) $row['task'],
                'started_at' => (string) $row['started_at'],
                'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
                'status' => (string) $row['status'],
                'message' => self::message($row['message']),
            ];
        }

        return $runs;
    }

    /**
     * Runs the tasks due, one after another, while BUDGET seconds last. Null if another
     * cron:run is running (the lock is taken); else what ran.
     *
     * @param (Closure(string, string, ?Message): void)|null $report Each task's name, status and result, as it ends
     * @return array{ran: list<string>, failed: list<string>, left: list<string>}|null
     */
    public function run(Container $container, ?Closure $report = null, int $budget = self::BUDGET, ?DateTimeImmutable $now = null): ?array
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen($this->lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return null;
        }
        try {
            $start = microtime(true);
            $now ??= self::now();
            $this->setSystem('cron_started', self::format($now));
            $done = ['ran' => [], 'failed' => [], 'left' => []];
            foreach ($this->tasks() as $name => $task) {
                if (!$this->isDue($name, $now)) {
                    continue;
                }
                $left = $budget - (microtime(true) - $start);
                if ($left < 1) {
                    $done['left'][] = $name;
                    continue;
                }
                [$status, $message] = $this->runOne($container, $task, $left, $now);
                $done[$status === self::OK ? 'ran' : 'failed'][] = $name;
                if ($report !== null) {
                    $report($name, $status, $message);
                }
            }
            $this->prune();
            $this->setSystem('cron_result', (string) json_encode(['ran' => count($done['ran']), 'failed' => count($done['failed'])]));
            $this->setSystem('cron_finished', self::format(self::now()));

            return $done;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{0: string, 1: ?Message} */
    private function runOne(Container $container, ScheduledTask $task, float $seconds, DateTimeImmutable $now): array
    {
        $name = $task->name();
        $started = self::format(self::now());
        $this->upsert($name, ['last_started' => $started, 'last_status' => self::RUNNING]);
        $runId = $this->db->insert(CoreSchema::SCHEDULED_RUNS, ['task' => $name, 'started_at' => $started, 'status' => self::RUNNING]);
        try {
            $message = $task->run($container, $seconds);
            $status = self::OK;
        } catch (\Throwable $e) {
            error_log("Campanella: the scheduled task {$name} failed: " . $e->getMessage());
            $message = $e instanceof \Campanella\Net\FetchException ? $e->reason : new Message('cron.error', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            $status = self::FAILED;
        }
        $json = $message === null ? null : self::json($message);
        $finished = self::format(self::now());
        $this->db->update(CoreSchema::SCHEDULED_RUNS, ['finished_at' => $finished, 'status' => $status, 'message' => $json], ['id' => $runId]);
        $failures = $status === self::OK ? 0 : (($this->states()[$name]['failures'] ?? 0) + 1);
        $this->upsert($name, [
            'last_finished' => $finished,
            'last_status' => $status,
            'last_message' => $json,
            'failures' => $failures,
            'requested' => 0,
            'next_due' => self::format($now->modify('+' . $task->interval() . ' seconds')),
        ]);

        return [$status, $message];
    }

    /** @param array<string, string|int|null> $values */
    private function upsert(string $name, array $values): void
    {
        $this->db->transactional(function (Connection $db) use ($name, $values): void {
            if ($db->fetchValue('SELECT 1 FROM {scheduled_tasks} WHERE name = :name', ['name' => $name]) === null) {
                $db->insert(CoreSchema::SCHEDULED_TASKS, ['name' => $name] + $values);
            } else {
                $db->update(CoreSchema::SCHEDULED_TASKS, $values, ['name' => $name]);
            }
        });
    }

    private function prune(): void
    {
        $keep = $this->db->fetchValue('SELECT id FROM {scheduled_runs} ORDER BY id DESC LIMIT 1 OFFSET ' . (self::LOG_SIZE - 1));
        if ($keep !== null) {
            $this->db->execute('DELETE FROM {scheduled_runs} WHERE id < :id', ['id' => (int) $keep]);
        }
    }

    private function setSystem(string $name, string $value): void
    {
        $this->db->transactional(function (Connection $db) use ($name, $value): void {
            $db->delete(CoreSchema::SYSTEM, ['name' => $name]);
            $db->insert(CoreSchema::SYSTEM, ['name' => $name, 'value' => $value]);
        });
    }

    private static function json(Message $message): string
    {
        return (string) json_encode(['key' => $message->key, 'params' => $message->params], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function message(mixed $json): ?Message
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || !is_string($data['key'] ?? null)) {
            return null;
        }
        $params = array_filter((array) ($data['params'] ?? []), static fn ($v): bool => is_string($v) || is_int($v) || is_float($v));

        /** @var array<string, string|int|float> $params */
        return new Message($data['key'], $params);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(FieldType::STORAGE_DATE_FORMAT);
    }
}
