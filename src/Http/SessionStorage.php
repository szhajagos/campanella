<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * A munkamenet tárolója. Élesben a PHP saját munkamenet-kezelése
 * (NativeSessionStorage), tesztekben egy memóriabeli változat
 * (ArraySessionStorage).
 */
interface SessionStorage
{
    /** Hozott-e a kérés munkamenetet (cookie-t)? */
    public function exists(Request $request): bool;

    public function start(Request $request): void;

    public function isStarted(): bool;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Új munkamenet-azonosító, az adatok megtartásával (belépéskor). */
    public function regenerate(): void;

    /** A munkamenet és a cookie törlése (kilépéskor). */
    public function destroy(): void;
}
