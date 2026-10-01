<?php

declare(strict_types=1);

use Campanella\Auth\Guard\HoneypotGuard;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Authorable;
use Campanella\Capability\Identifiable;
use Campanella\Capability\Publishable;
use Campanella\Capability\Routable;
use Campanella\Capability\Textual;
use Campanella\Capability\Titled;

/*
 * Base settings. Machine-specific values (database, debug) are overridden
 * by config/local.php; for a sample see config/local.php.dist
 */
return [
    'debug' => filter_var(getenv('CAMPANELLA_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'timezone' => 'Europe/Budapest',

    // The language of user-facing texts: a file in lang/ (en, hu). English is the fallback.
    'locale' => getenv('CAMPANELLA_LOCALE') ?: 'hu',

    // The active theme: a folder in themes/ whose templates override the core ones.
    // Empty: the core templates (Bootstrap 5.3). See docs/php-api/07-http-and-view.md.
    'theme' => getenv('CAMPANELLA_THEME') ?: '',

    // The admin UI: its path, and the roles that may enter it.
    'admin' => [
        'path' => '/admin',
        'roles' => ['administrator', 'editor'],
    ],

    'site' => [
        'name' => 'Campanella',
        'slogan' => 'Capability-vezérelt CMS',
    ],

    // Can also be set via environment variables (e.g. in Docker); local.php overrides them.
    'database' => [
        'host' => getenv('CAMPANELLA_DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('CAMPANELLA_DB_PORT') ?: 3306),
        'name' => getenv('CAMPANELLA_DB_NAME') ?: 'campanella',
        'user' => getenv('CAMPANELLA_DB_USER') ?: 'campanella',
        'password' => getenv('CAMPANELLA_DB_PASSWORD') ?: '',
        'prefix' => getenv('CAMPANELLA_DB_PREFIX') ?: 'cc_',
    ],

    // The capabilities available in the system. A module will later
    // register its own here.
    'capabilities' => [
        Titled::class,
        Textual::class,
        Routable::class,
        Publishable::class,
        Identifiable::class,
        Authenticatable::class,
        Authorable::class,
    ],

    // Session (login). The session only starts at login; anonymous
    // visitors get no cookie.
    'session' => [
        'name' => 'campanella_session',
        'idle_timeout' => 7200,        // logs the user out after this many seconds of inactivity
        'secure' => 'auto',            // 'auto': send the cookie over HTTPS only; true / false: forced
    ],

    // Login throttling.
    'auth' => [
        'max_attempts' => 5,           // this many failed attempts per e-mail address + IP address pair
        'max_attempts_per_ip' => 20,   // and this many per IP address
        'decay_seconds' => 900,        // within this time window (15 minutes)
        // Additional protections run before the password check (Campanella\Auth\LoginGuard).
        'guards' => [
            HoneypotGuard::class,
        ],
    ],
];
