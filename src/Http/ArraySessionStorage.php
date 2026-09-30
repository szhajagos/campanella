<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * In-memory session for tests and the command line. One instance plays a
 * single "browser": it keeps the data between requests.
 */
final class ArraySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    private array $data = [];
    private bool $started = false;
    private bool $exists = false;
    private int $generation = 0;

    #[\Override]
    public function exists(Request $request): bool
    {
        return $this->exists;
    }

    #[\Override]
    public function start(Request $request): void
    {
        $this->started = true;
        $this->exists = true;
    }

    #[\Override]
    public function isStarted(): bool
    {
        return $this->started;
    }

    #[\Override]
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    #[\Override]
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    #[\Override]
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    #[\Override]
    public function regenerate(): void
    {
        $this->generation++;
    }

    #[\Override]
    public function destroy(): void
    {
        $this->data = [];
        $this->started = false;
        $this->exists = false;
    }

    /** How many times the session ID was replaced (for tests). */
    public function generation(): int
    {
        return $this->generation;
    }

    /** Before a new request: the session is "closed", but the cookie remains. */
    public function endRequest(): void
    {
        $this->started = false;
    }
}
