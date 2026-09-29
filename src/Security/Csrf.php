<?php

declare(strict_types=1);

namespace Campanella\Security;

use Campanella\Http\Request;
use Campanella\Http\Session;

/**
 * CSRF-védelem: munkamenetenként egy véletlen token, amelyet minden
 * módosító űrlap (POST) visszaküld. Más oldalról indított kérés nem ismeri.
 *
 * Sablonban: {{ csrf_field() }}
 */
final class Csrf
{
    public const string FIELD = '_csrf';
    private const string SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    /** A token (szükség esetén munkamenetet indít). */
    public function token(Request $request): string
    {
        $this->session->start($request);
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(Request $request): bool
    {
        if (!$this->session->resume($request)) {
            return false;
        }
        $expected = $this->session->get(self::SESSION_KEY);
        $given = $request->postString(self::FIELD);

        return is_string($expected) && $expected !== '' && hash_equals($expected, $given);
    }

    /** Új token (belépés után, hogy a belépés előtti token ne legyen használható). */
    public function rotate(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
