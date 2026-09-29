<?php

declare(strict_types=1);

namespace Campanella\Auth\Guard;

use Campanella\Auth\AuthService;
use Campanella\Auth\LoginGuard;
use Campanella\Http\Request;

/**
 * Honeypot: egy ember számára láthatatlan mező, amelyet az automatikus
 * űrlapkitöltő robotok jellemzően kitöltenek. Ha nem üres, a kérést
 * elutasítja, ugyanazzal az üzenettel, mint a hibás jelszót, hogy a robot
 * ne tudja meg, mi buktatta le.
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
        // Képernyőolvasók és billentyűzetes navigáció elől is rejtett. A rejtés
        // beágyazott stílussal történik, hogy ne függjön a téma CSS-étől (vagy
        // attól, hogy a böngésző egy régebbi CSS-t tart gyorsítótárban).
        return sprintf(
            '<div class="hp" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label for="hp-%1$s">Weboldal</label>'
            . '<input type="text" id="hp-%1$s" name="%1$s" value="" tabindex="-1" autocomplete="off"></div>',
            self::FIELD,
        );
    }
}
