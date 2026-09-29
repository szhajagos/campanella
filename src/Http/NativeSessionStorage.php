<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * A PHP beépített munkamenet-kezelése, biztonságos beállításokkal:
 * HttpOnly és SameSite=Lax cookie, szigorú mód (idegen azonosítót nem
 * fogad el), HTTPS-en Secure cookie.
 */
final class NativeSessionStorage implements SessionStorage
{
    /**
     * @param bool|string $secure true / false, vagy 'auto': a kérés HTTPS-e alapján
     */
    public function __construct(
        private readonly string $name = 'campanella_session',
        private readonly bool|string $secure = 'auto',
        private readonly int $lifetime = 7200,
    ) {
    }

    #[\Override]
    public function exists(Request $request): bool
    {
        return isset($request->cookies[$this->name]) && $request->cookies[$this->name] !== '';
    }

    #[\Override]
    public function start(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $this->lifetime);
        session_name($this->name);
        session_set_cookie_params($this->cookieParams($request));
        session_start();
    }

    #[\Override]
    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    #[\Override]
    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    #[\Override]
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    #[\Override]
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    #[\Override]
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    #[\Override]
    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $params = session_get_cookie_params();
        $_SESSION = [];
        session_destroy();
        if (!headers_sent()) {
            setcookie($this->name, '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /** @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: 'Lax'} */
    private function cookieParams(Request $request): array
    {
        return [
            'lifetime' => 0,
            'path' => $request->basePath !== '' ? $request->basePath . '/' : '/',
            'secure' => $this->secure === 'auto' ? $request->secure : (bool) $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
