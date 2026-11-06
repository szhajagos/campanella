<?php

declare(strict_types=1);

namespace CampanellaFeeds;

use Campanella\I18n\Message;

/** A source: the web address an importer imports from, how often, and where it stands. */
final readonly class Source
{
    /** How often a source can be fetched, in minutes. */
    public const array EVERY = [15, 60, 360, 1440];

    /** What its address gives: a file the importer reads (CSV, JSON), or an RSS or Atom feed. */
    public const array KINDS = ['file', 'feed'];

    public const string OK = 'ok';
    public const string UNCHANGED = 'unchanged';
    public const string RUNNING = 'running';
    public const string FAILED = 'failed';

    public function __construct(
        public int $id,
        public string $importer,
        public string $url,
        public string $kind,
        public int $everyMinutes,
        public bool $enabled,
        public ?string $etag,
        public ?string $lastModified,
        public ?string $contentHash,
        public ?int $runId,
        public ?string $nextDue,
        public ?string $lastFetched,
        public ?string $lastStatus,
        public ?Message $lastMessage,
        public int $failures,
    ) {
    }

    /** The host of its address, to name it. */
    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }
}
