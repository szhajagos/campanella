<?php

declare(strict_types=1);

use Campanella\Auth\Guard\HoneypotGuard;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Authorable;
use Campanella\Capability\Identifiable;
use Campanella\Capability\MediaFile;
use Campanella\Capability\Publishable;
use Campanella\Capability\Routable;
use Campanella\Capability\Submitted;
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

    // The site's settings. Edited in the admin (System → Settings, since 0.1.1); these
    // values apply until they are first saved there. Other keys (e.g. a theme's own)
    // are passed to templates as they are: {{ site.<key> }}.
    // The paths of the system's own public pages (since 0.1.4). English by default;
    // any can be changed, e.g. to the site's language: ['login' => '/belepes'].
    'paths' => [
        'login' => '/login',
        'logout' => '/logout',
        'password_reset' => '/password-reset',   // the forgotten password
        'contact' => '/contact',                 // the contact form (since 0.1.5)
    ],

    'site' => [
        'name' => 'Campanella',
        'slogan' => 'Capability-vezérelt CMS',
        'description' => '',        // the default meta description
        'url' => getenv('CAMPANELLA_SITE_URL') ?: '',   // e.g. https://example.hu; needed for canonical URLs, Open Graph, sitemap.xml
        'share_image' => null,      // an image object's ID, shown when a page is shared
        'indexing' => true,         // false: search engines are asked not to index the site
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
        Submitted::class,
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
        // Smaller copies of every uploaded image (since 0.1.2), for srcset: their widths in
        // pixels. A copy is made only for a width at most 90% of the image's. []: none.
        'variants' => [320, 640, 1024, 1600],
        // The srcset images' sizes attribute: how wide the text column is (the default theme's
        // is at most 800 pixels). An image with a width of its own uses that instead.
        'sizes' => '(max-width: 800px) 100vw, 800px',
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
        'absolute_timeout' => 43200,   // and after this many seconds from logging in, however active (12 h; since 0.1.4)
        'secure' => 'auto',            // 'auto': send the cookie over HTTPS only; true / false: forced
    ],

    // E-mail (since 0.1.3): set it up in config/local.php, because the server's address
    // may hold a password. Without both dsn and from, nothing is sent.
    //   'dsn' => 'smtp://user:password@smtp.example.hu:587'   an SMTP server (STARTTLS)
    //   'dsn' => 'smtps://user:password@smtp.example.hu:465'  SMTP over TLS
    //   'dsn' => 'native://default'                            PHP's own settings (sendmail_path), like mail()
    // See docs/php-api/21-events-and-mail.md.
    'mail' => [
        'dsn' => getenv('CAMPANELLA_MAIL_DSN') ?: '',
        'from' => getenv('CAMPANELLA_MAIL_FROM') ?: '',   // the sender's address, e.g. noreply@example.hu
        'from_name' => '',                                // '': the site's name
        'log_days' => 90,                                 // the mail log keeps this many days
        'timeout' => 10,                                  // seconds to wait for the mail server
    ],

    // The contact form (since 0.1.5), at paths.contact; templates can show it anywhere
    // with {{ contact_form() }}. Its messages are under Submissions in the admin.
    'contact' => [
        'enabled' => true,
        'min_seconds' => 3,            // a form sent sooner after it was shown is taken for a bot's
        // An e-mail about each new message (since 0.1.5; needs e-mail set up): to these
        // addresses, e.g. ['info@example.hu']; empty: to the active administrators.
        'notify' => true,
        'notify_to' => [],
        // Additional protections, as for logging in (LoginGuard classes).
        'guards' => [
            HoneypotGuard::class,
        ],
    ],

    // Events (since 0.1.3): actions bound to them, event class => list of Action classes, e.g.
    //   \Campanella\Event\ObjectPublished::class => [\Campanella\Event\Action\MailAdministrators::class],
    'events' => [],

    // Login throttling.
    'auth' => [
        'max_attempts' => 5,           // this many failed attempts per e-mail address + IP address pair
        'max_attempts_per_ip' => 20,   // and this many per IP address (IPv6: per /64 network)
        'max_attempts_per_account' => 30, // and this many per account, from any address (since 0.1.0)
        'decay_seconds' => 900,        // within this time window (15 minutes)
        // The forgotten password (since 0.1.4; needs e-mail and the site's address): how
        // long the link sent is valid, in minutes. It works once, and a newer one replaces it.
        'password_reset_minutes' => 60,
        // Additional protections run before the password check (Campanella\Auth\LoginGuard).
        'guards' => [
            HoneypotGuard::class,
        ],
    ],
];
