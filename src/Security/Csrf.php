<?php

declare(strict_types=1);

namespace Campanella\Security;

use Campanella\Http\Request;
use Campanella\Http\Session;

/**
 * CSRF protection: one random token per session, which every modifying
 * form (POST) sends back. A request started from another site does not know it.
 *
 * In a template: {{ csrf_field() }}
 */
final class Csrf
{
    public const string FIELD = '_csrf';
    private const string SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    /** The token (starts a session if needed). */
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

    /** A new token (after login, so the token from before login cannot be used). */
    public function rotate(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
