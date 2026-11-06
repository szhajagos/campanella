<?php

declare(strict_types=1);

namespace CampanellaFeeds;

use Campanella\I18n\Message;
use Campanella\Import\ImportException;
use Campanella\Import\Importer;
use Campanella\Import\ImporterDefinition;
use Campanella\Import\ImportRun;
use Campanella\Import\ImportTrial;
use Campanella\Net\FetchException;
use Campanella\Net\SafeFetcher;
use Campanella\Schedule\Scheduler;
use DateTimeImmutable;

/**
 * Fetching a source and importing its file: by its importer, as an uploaded file is (a run in
 * the importer's log, which can be undone), only when the file changed (the server says so, or
 * its content's hash). A run longer than the time given goes on in the next cron:run.
 *
 * After a failure the source is tried again at its interval; after FAILURES_BEFORE_BACKOFF in a
 * row, at twice the interval for each more, at most once a day; the first success ends it.
 */
final class Feeds
{
    public const int FAILURES_BEFORE_BACKOFF = 3;
    public const int MAX_BACKOFF_MINUTES = 1440;

    public function __construct(
        private readonly SourceStore $sources,
        private readonly SafeFetcher $fetcher,
        private readonly Importer $importer,
    ) {
    }

    /** The source's importer, or a message why there is none. */
    public function importerOf(Source $source): ImporterDefinition|Message
    {
        $definition = $this->importer->find($source->importer);
        if ($definition === null) {
            return new Message('feeds.error.no_importer', ['importer' => $source->importer]);
        }

        return self::importerProblem($source->kind, $definition) ?? $definition;
    }

    /** Why the importer cannot read what the source gives: a feed needs JSON, its items at `items`. */
    public static function importerProblem(string $kind, ImporterDefinition $definition): ?Message
    {
        return $kind === 'feed' && ($definition->format !== 'json' || $definition->items !== 'items')
            ? new Message('feeds.error.feed_importer', ['keys' => implode(', ', FeedReader::KEYS)])
            : null;
    }

    /** What the first items of the file would become (nothing changed): fetched now, for the admin. */
    public function trial(Source $source): ImportTrial|Message
    {
        $definition = $this->importerOf($source);
        if ($definition instanceof Message) {
            return $definition;
        }
        try {
            $file = $this->download($source, $definition);
            try {
                return $this->importer->trial($definition, $file);
            } finally {
                @unlink($file);
            }
        } catch (FetchException $e) {
            return $e->reason;
        } catch (ImportException $e) {
            return $e->reason;
        }
    }

    /**
     * Its work in a cron:run: a run of its going on continued, or the file fetched and, if it
     * changed, imported; in about `$seconds`. What happened, also recorded with the source.
     */
    public function process(Source $source, float $seconds, ?DateTimeImmutable $now = null): Message
    {
        $now ??= Scheduler::now();
        $until = microtime(true) + max(1.0, $seconds);
        try {
            $definition = $this->importerOf($source);
            if ($definition instanceof Message) {
                throw new ImportException($definition);
            }
            // A run of its own going on: continued.
            if ($source->runId !== null && $source->lastStatus === Source::RUNNING) {
                $run = $this->importer->run($source->runId);
                if ($run !== null && $run->status === ImportRun::RUNNING) {
                    return $this->stepped($source, $this->importer->step($run->id, max(1.0, $until - microtime(true))), $now);
                }
            }
            $result = $this->fetcher->fetch($source->url, $source->etag, $source->lastModified);
            $fetched = ['last_fetched' => Scheduler::format($now), 'etag' => $result->etag ?? $source->etag, 'last_modified' => $result->lastModified ?? $source->lastModified];
            $hash = hash('sha256', $result->body);
            if ($result->notModified || $hash === $source->contentHash) {
                $message = new Message('feeds.result.unchanged');
                $this->sources->record($source, $fetched + $this->succeeded($source, Source::UNCHANGED, $message, $now));

                return $message;
            }
            $file = $this->save($this->forImporter($source, $result->body), $definition);
            try {
                $run = $this->importer->start($definition, $file, $source->host());
            } finally {
                @unlink($file);
            }
            $this->sources->record($source, $fetched + ['content_hash' => $hash, 'run_id' => $run->id]);
            $run = $this->importer->step($run->id, max(1.0, $until - microtime(true)));

            return $this->stepped($this->sources->find($source->id) ?? $source, $run, $now);
        } catch (FetchException|ImportException $e) {
            $message = $e->reason;
        } catch (\Throwable $e) {
            error_log("Campanella feeds: the source {$source->id} ({$source->importer}) failed: " . $e->getMessage());
            $message = new Message('feeds.error.failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $failures = $source->failures + 1;
        $this->sources->record($source, [
            'last_status' => Source::FAILED,
            'last_message' => SourceStore::json($message),
            'failures' => $failures,
            'next_due' => Scheduler::format($now->modify('+' . self::nextMinutes($source->everyMinutes, $failures) . ' minutes')),
        ]);

        return $message;
    }

    /** The minutes until the next try: the interval, longer after FAILURES_BEFORE_BACKOFF failures in a row. */
    public static function nextMinutes(int $everyMinutes, int $failures): int
    {
        $over = $failures - self::FAILURES_BEFORE_BACKOFF + 1;

        return $over <= 0 ? $everyMinutes : max($everyMinutes, min(self::MAX_BACKOFF_MINUTES, $everyMinutes * (2 ** min(10, $over))));
    }

    /** @return array<string, string|int|null> */
    private function succeeded(Source $source, string $status, Message $message, DateTimeImmutable $now): array
    {
        return [
            'last_status' => $status,
            'last_message' => SourceStore::json($message),
            'failures' => 0,
            'next_due' => Scheduler::format($now->modify('+' . $source->everyMinutes . ' minutes')),
        ];
    }

    private function stepped(Source $source, ImportRun $run, DateTimeImmutable $now): Message
    {
        if ($run->status === ImportRun::RUNNING) {
            $message = new Message('feeds.result.running', ['position' => $run->position, 'total' => $run->total]);
            $this->sources->record($source, ['last_status' => Source::RUNNING, 'last_message' => SourceStore::json($message), 'run_id' => $run->id]);

            return $message;
        }
        $message = new Message('feeds.result.imported', ['created' => $run->created, 'updated' => $run->updated, 'unchanged' => $run->unchanged, 'failed' => $run->failed]);
        $this->sources->record($source, ['run_id' => $run->id] + $this->succeeded($source, Source::OK, $message, $now));

        return $message;
    }

    /** The file fetched now, saved for the importer; its path. */
    private function download(Source $source, ImporterDefinition $definition): string
    {
        return $this->save($this->forImporter($source, $this->fetcher->fetch($source->url)->body), $definition);
    }

    /** What the importer reads: the file as it is, or a feed made into JSON (FeedReader). */
    private function forImporter(Source $source, string $body): string
    {
        return $source->kind === 'feed' ? FeedReader::toJson($body) : $body;
    }

    private function save(string $body, ImporterDefinition $definition): string
    {
        $file = tempnam(sys_get_temp_dir(), 'campanella-feed-');
        if ($file === false || file_put_contents($file, $body) === false) {
            throw new ImportException(new Message('import.error.unwritable'));
        }
        $named = $file . '.' . $definition->format;
        rename($file, $named);

        return $named;
    }
}
