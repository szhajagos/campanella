<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * The session store. In production PHP's own session handling
 * (NativeSessionStorage), in tests an in-memory variant
 * (ArraySessionStorage).
 */
interface SessionStorage
{
    /** Did the request bring a session (cookie)? */
    public function exists(Request $request): bool;

    public function start(Request $request): void;

    public function isStarted(): bool;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** A new session ID, keeping the data (on login). */
    public function regenerate(): void;

    /** Deletes the session and the cookie (on logout). */
    public function destroy(): void;
}
