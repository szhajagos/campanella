<?php

declare(strict_types=1);

use Campanella\Auth\Guard\HoneypotGuard;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Authorable;
use Campanella\Capability\Identifiable;
use Campanella\Capability\MediaFile;
use Campanella\Capability\Publishable;
use Campanella\Capability\Routable;
use Campanella\Capability\Textual;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\Keyed;
use Campanella\Capability\Link;
use Campanella\Capability\Titled;
use Campanella\Capability\Weighted;

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

    // The admin UI: its path, the roles that may enter it, and the roles that
    // may open its system page (versions, extensions, settings of the server).
    'admin' => [
        'path' => '/admin',
        'roles' => ['administrator', 'editor'],
        'system_roles' => ['administrator'],
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

    // The site's own migrations (class names), run after Campanella's own.
    // See docs/php-api/17-migrations.md.
    'migrations' => [],

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
        MediaFile::class,
        Weighted::class,
        Hierarchical::class,
        Keyed::class,
        Link::class,
    ],

    // Uploaded files (images). The folder is under public/, so the files are served
    // directly by the web server; a .htaccess there forbids running scripts.
    'media' => [
        'directory' => 'public/media',      // relative to the project root
        'url' => '/media',                  // the folder's address on the site
        'max_bytes' => 10 * 1024 * 1024,    // the largest file accepted (also limited by php.ini)
        'max_pixels' => 25_000_000,         // the largest image (width × height); also limited by memory_limit
        'max_dimension' => 2560,            // larger images are scaled down to this width or height
        'quality' => 85,                    // JPEG and WebP quality when re-encoding (1–100)
        'memory_limit' => '320M',           // memory_limit is raised to this while processing an image, if allowed
        // A type the server cannot re-encode (e.g. GD built without WebP) is refused, so no
        // image is stored with its metadata (e.g. GPS position). true: stored as uploaded.
        'store_unprocessed' => false,
    ],

    // Behind a reverse proxy (load balancer, CDN, the web server of a Docker host): the
    // proxies whose X-Forwarded-For and X-Forwarded-Proto headers are believed, as IP
    // addresses or CIDR ranges, e.g. ['127.0.0.1', '10.0.0.0/8']. Empty: none, the
    // headers are ignored (anyone could send them). See docs/deployment.md.
    'trusted_proxies' => [],

    // Security headers (see docs/deployment.md).
    'security' => [
        // null: the built-in Content-Security-Policy of the public site (only the site's own
        // scripts, styles and images). A string replaces it, e.g. for a theme's font
        // service; every source added is trusted with the visitors' pages.
        'content_security_policy' => null,
        // HSTS: browsers use only HTTPS for this many seconds (e.g. 31536000: a year).
        // 0: off. Turn it on only once HTTPS works everywhere: it cannot be taken back
        // for the visitors who already got it.
        'hsts' => 0,
        'hsts_subdomains' => false,
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
