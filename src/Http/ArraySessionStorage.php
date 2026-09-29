<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * Memóriabeli munkamenet tesztekhez és parancssorhoz. Egy példány egyetlen
 * „böngészőt” játszik el: a kérések között megőrzi az adatokat.
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

    /** Hányszor cserélődött a munkamenet-azonosító (tesztekhez). */
    public function generation(): int
    {
        return $this->generation;
    }

    /** Egy új kérés előtt: a munkamenet „lezárul”, de a cookie megmarad. */
    public function endRequest(): void
    {
        $this->started = false;
    }
}
