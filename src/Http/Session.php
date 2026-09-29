<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * Munkamenet lusta indítással és tétlenségi időkorláttal.
 *
 *  - resume(): csak akkor folytat munkamenetet, ha a kérés hozott cookie-t.
 *    A névtelen látogatók így nem kapnak cookie-t.
 *  - start(): akkor kell, ha írni akarunk (belépés, CSRF-token).
 *  - Ha az utolsó tevékenység óta több idő telt el, mint az időkorlát, a
 *    munkamenet megszűnik (a tárhelyek szemétgyűjtésére nem hagyatkozunk).
 */
final class Session
{
    private const string LAST_ACTIVITY = '_last_activity';

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly int $idleTimeout = 7200,
    ) {
    }

    /** Folytatja a meglévő munkamenetet; igaz, ha van érvényes munkamenet. */
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

    /** Munkamenet indítása (vagy folytatása) íráshoz. */
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
            throw new \LogicException('A munkamenet nincs elindítva (Session::start()).');
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
