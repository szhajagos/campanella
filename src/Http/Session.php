<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * Session with lazy start and an idle timeout.
 *
 *  - resume(): only resumes a session if the request brought a cookie.
 *    This way anonymous visitors get no cookie.
 *  - start(): needed when we want to write (login, CSRF token).
 *  - If more time has passed since the last activity than the timeout, the
 *    session ends (we do not rely on the web host's garbage collection).
 */
final class Session
{
    private const string LAST_ACTIVITY = '_last_activity';

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly int $idleTimeout = 7200,
    ) {
    }

    /** Resumes the existing session; true if there is a valid session. */
    public function resume(Request $request): bool
    {
        if ($this->storage->isStarted()) {
            return true;
        }
        if (!$this->storage->exists($request)) {
            return false;
        }
        $this->storage->start($request);

        $last = $this->storage->get(self::LAST_ACTIVITY);
        if (is_int($last) && $last + $this->idleTimeout < time()) {
            $this->storage->destroy();

            return false;
        }
        $this->storage->set(self::LAST_ACTIVITY, time());

        return true;
    }

    /** Starts (or resumes) the session for writing. */
    public function start(Request $request): void
    {
        if (!$this->resume($request)) {
            $this->storage->start($request);
            $this->storage->set(self::LAST_ACTIVITY, time());
        }
    }

    public function isStarted(): bool
    {
        return $this->storage->isStarted();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->storage->isStarted() ? ($this->storage->get($key) ?? $default) : $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (!$this->storage->isStarted()) {
            throw new \LogicException('Session is not started (Session::start()).');
        }
        $this->storage->set($key, $value);
    }

    public function remove(string $key): void
    {
        if ($this->storage->isStarted()) {
            $this->storage->remove($key);
        }
    }

    public function regenerate(): void
    {
        $this->storage->regenerate();
    }

    public function destroy(): void
    {
        $this->storage->destroy();
    }
}
