<?php

declare(strict_types=1);

namespace Campanella\Database\Sync;

/** What the SchemaSync would do (plan()) or did (apply()), in order. */
final readonly class SyncPlan
{
    /** @param list<SyncStep> $steps */
    public function __construct(public array $steps = [])
    {
    }

    /** Whether anything would change the database. */
    public function hasWork(): bool
    {
        return $this->work() !== [];
    }

    /** @return list<SyncStep> */
    public function work(): array
    {
        return array_values(array_filter($this->steps, static fn (SyncStep $s): bool => $s->isWork()));
    }

    /** @return list<SyncStep> What cannot be done automatically */
    public function blocked(): array
    {
        return $this->ofKind(SyncStepKind::Blocked);
    }

    /** @return list<SyncStep> */
    public function notes(): array
    {
        return $this->ofKind(SyncStepKind::Note);
    }

    /** Whether a step moves data between the JSON data and a table (since 0.2.2). */
    public function hasMove(): bool
    {
        return $this->ofKind(SyncStepKind::MoveData) !== [];
    }

    /**
     * Whether a step changes data beyond adding: moves it, changes a column's type or fills
     * it, or deletes it (since 0.2.4). These are done after a backup.
     */
    public function changesData(): bool
    {
        return $this->hasMove() || $this->hasPrune() || $this->ofKind(SyncStepKind::ChangeColumn) !== [];
    }

    public function hasPrune(): bool
    {
        return $this->ofKind(SyncStepKind::PruneCapability) !== [];
    }

    /** @return list<SyncStep> */
    private function ofKind(SyncStepKind $kind): array
    {
        return array_values(array_filter($this->steps, static fn (SyncStep $s): bool => $s->kind === $kind));
    }
}
