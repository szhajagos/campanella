<?php

declare(strict_types=1);

/*
 * User-facing texts, Hungarian. The keys are the same as in en.php.
 */
return [
    // Site frame (templates/base.html.twig)
    'site.nav.label' => 'Főmenü',
    'site.nav.home' => 'Főoldal',
    'site.nav.news' => 'Hírek',
    'site.nav.categories' => 'Kategóriák',
    'site.nav.about' => 'Rólunk',

    // Content lists
    'content.empty' => 'Nincs megjeleníthető tartalom.',
    'content.more' => 'Tovább',
    'content.pager' => 'Lapozó',

    // Login
    'auth.login' => 'Belépés',
    'auth.logout' => 'Kilépés',
    'auth.email' => 'E-mail-cím',
    'auth.password' => 'Jelszó',
    'auth.invalid_credentials' => 'Hibás e-mail-cím vagy jelszó.',
    'auth.account_blocked' => 'A fiók le van tiltva.',
    'auth.too_many_attempts' => 'Túl sok sikertelen próbálkozás. Próbáld újra {minutes} perc múlva.',
    'auth.form_expired' => 'Az űrlap lejárt. Kérjük, próbáld újra.',

    // Error pages
    'error.title' => 'Hiba történt',
    'error.not_found_title' => 'Az oldal nem található',
    'error.back_home' => 'Vissza a főoldalra',
    'error.not_found' => 'Az oldal nem található.',
    'error.not_installed' => 'A Campanella még nincs telepítve. Futtasd: php bin/campanella install',
    'error.needs_upgrade' => 'Az adatbázis frissítésre szorul (új verzió). Futtasd: php bin/campanella install',
    'error.internal' => 'Belső hiba történt.',
];
