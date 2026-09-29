<?php

declare(strict_types=1);

namespace Campanella\Auth;

use Campanella\Http\Request;

/**
 * Kiegészítő belépési védelem, amely a jelszó ellenőrzése ELŐTT fut
 * (pl. honeypot, CAPTCHA, IP-tiltólista).
 *
 * A guardok a config/app.php 'auth.guards' listájából töltődnek be, és az
 * AuthService sorban futtatja őket; az első elutasítás megállítja a belépést.
 * Ha a guardnak mezőt kell az űrlapba tennie, azt a fields() adja vissza,
 * és a belépési sablon kiírja.
 */
interface LoginGuard
{
    /** @return string|null Hibaüzenet elutasításkor, null ha a kérés továbbengedhető. */
    public function check(Request $request): ?string;

    /** Az űrlapba kerülő kiegészítő HTML (vagy üres szöveg). */
    public function fields(): string;
}
