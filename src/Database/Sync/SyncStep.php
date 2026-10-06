<?php

declare(strict_types=1);

namespace Campanella\Database\Sync;

use Campanella\I18n\Message;
use Closure;

/** One step of a SyncPlan: what it does (a Message), and the work itself. */
final readonly class SyncStep
{
    /** @param (Closure(): void)|null $work Null for Blocked and Note steps */
    public function __construct(
        public SyncStepKind $kind,
        public Message $message,
        private ?Closure $work = null,
    ) {
    }

    /** Whether it changes the database (not Blocked, not Note). */
    public function isWork(): bool
    {
        return $this->work !== null;
    }

    /** @internal Run by SchemaSync::apply() */
    public function run(): void
    {
        if ($this->work !== null) {
            ($this->work)();
        }
    }
}
