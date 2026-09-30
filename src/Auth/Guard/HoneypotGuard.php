<?php

declare(strict_types=1);

namespace Campanella\Auth\Guard;

use Campanella\Auth\AuthService;
use Campanella\Auth\LoginGuard;
use Campanella\Http\Request;

/**
 * Honeypot: a field invisible to humans that automatic form-filling bots
 * typically fill in. If it is not empty, the request is rejected with the
 * same message as a wrong password, so the bot cannot learn what gave it
 * away.
 */
final class HoneypotGuard implements LoginGuard
{
    public const string FIELD = 'website';

    #[\Override]
    public function check(Request $request): ?string
    {
        return $request->postString(self::FIELD) === '' ? null : AuthService::GENERIC_ERROR;
    }

    #[\Override]
    public function fields(): string
    {
        // Also hidden from screen readers and keyboard navigation. It is hidden
        // with an inline style so it does not depend on the theme's CSS (or on
        // the browser keeping an older CSS in its cache).
        return sprintf(
            '<div class="hp" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label for="hp-%1$s">Weboldal</label>'
            . '<input type="text" id="hp-%1$s" name="%1$s" value="" tabindex="-1" autocomplete="off"></div>',
            self::FIELD,
        );
    }
}
