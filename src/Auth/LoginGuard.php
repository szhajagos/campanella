<?php

declare(strict_types=1);

namespace Campanella\Auth;

use Campanella\Http\Request;

/**
 * An additional login protection that runs BEFORE the password check
 * (e.g. honeypot, CAPTCHA, IP blocklist).
 *
 * Guards are loaded from the 'auth.guards' list in config/app.php, and
 * AuthService runs them in order; the first rejection stops the login.
 * If a guard needs to add a field to the form, fields() returns it and
 * the login template outputs it.
 */
interface LoginGuard
{
    /** @return string|null Error message on rejection, null if the request may proceed. */
    public function check(Request $request): ?string;

    /** Additional HTML to put into the form (or an empty string). */
    public function fields(): string;
}
