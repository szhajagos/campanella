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
    'site.nav.toggle' => 'Menü megnyitása',

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

    // Validation messages (ValidationException)
    'validation.required' => 'kötelező mező',
    'validation.too_many_values' => 'legfeljebb {max} érték adható meg',
    'validation.value_too_long' => 'egy érték legfeljebb {max} karakter lehet',
    'validation.taken' => 'ez az érték már foglalt ({value})',
    'validation.unique_conflict' => 'egyedi kulcs ütközés',
    'validation.relation_required' => 'kötelező kapcsolat',
    'validation.too_many_relations' => 'legfeljebb {max} kapcsolat adható meg',
    'validation.self_reference' => 'az objektum nem mutathat önmagára',
    'validation.target_missing' => 'a cél (#{id}) nem létezik',
    'validation.target_blueprint' => 'a cél (#{id}) {blueprint} típusú, de csak ez lehet: {allowed}',
    'validation.target_capability' => 'a célnak (#{id}) nincs ilyen capability-je: {missing}',
    'validation.invalid_email' => 'érvénytelen e-mail-cím',
    'validation.password_too_short' => 'legalább {min} karakter legyen',
    'validation.password_too_long' => 'legfeljebb {max} bájt lehet',
    'validation.invalid_role' => 'érvénytelen szerepkörnév: {role}',

    // Command line (bin/campanella)
    'cli.usage' => 'Használat: php bin/campanella <parancs>',
    'cli.install.description' => 'Létrehozza az adatbázistáblákat (--sql: csak kiírja az SQL-t)',
    'cli.install.server' => 'Adatbázis-szerver: {version}',
    'cli.install.done' => 'Kész. Példatartalomhoz: php bin/campanella seed',
    'cli.seed.description' => 'Példatartalmat hoz létre (ismételten futtatható)',
    'cli.seed.categories' => '{path} → kategóriák: {categories}',
    'cli.seed.draft' => 'piszkozat',
    'cli.seed.done' => 'Kész: {total} objektum, ebből {public} nyilvánosan látható.',
    'cli.status.description' => 'Verzió, capability-k, Blueprintek és objektumszám',
    'cli.status.capabilities' => 'Capability-k:',
    'cli.status.blueprints' => 'Blueprintek:',
    'cli.status.fields' => 'mezők',
    'cli.status.table' => 'tábla',
    'cli.status.not_installed' => 'Az adatbázis még nincs telepítve (php bin/campanella install).',
    'cli.status.needs_upgrade' => 'Az adatbázis sémája ({database}) régebbi a kódnál ({code}): futtasd a php bin/campanella install parancsot.',
    'cli.status.objects' => 'Objektumok: {total} összesen, ebből {public} nyilvánosan látható.',
    'cli.user.not_found' => 'Nincs ilyen felhasználó: {email}',
    'cli.user.active' => 'aktív',
    'cli.user.blocked' => 'letiltva',
    'cli.user.roles' => 'szerepkörök: {roles}',
    'cli.user_create.description' => 'Felhasználó létrehozása: <e-mail> [--name="Név"] [--role=administrator,editor]',
    'cli.user_create.missing_email' => 'Add meg az e-mail-címet: php bin/campanella user:create <e-mail> [--name="Név"] [--role=administrator]',
    'cli.user_create.exists' => 'Már van felhasználó ezzel az e-mail-címmel: {email}',
    'cli.user_create.created' => 'Létrehozva: #{id} {name} <{email}>',
    'cli.user_password.description' => 'Jelszó beállítása: <e-mail> (vagy --block / --activate)',
    'cli.user_password.done' => 'Az új jelszó beállítva: {email}',
    'cli.user_list.description' => 'Felhasználók listája',
    'cli.user_list.empty' => 'Még nincs felhasználó. Létrehozás: php bin/campanella user:create <e-mail> --role=administrator',
    'cli.password.prompt' => 'Jelszó (legalább {min} karakter):',
    'cli.password.again' => 'Jelszó még egyszer:',
    'cli.password.mismatch' => 'A két jelszó nem egyezik.',
    'cli.password.too_short' => 'A jelszó legalább {min} karakter legyen.',
];
