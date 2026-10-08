# Running Campanella on a public server

This guide is for putting a Campanella site on the internet. The
[README](../README.md) covers installing it; here is what makes the
difference between a site that works and a site that is safe to leave
running: the web root, HTTPS, the settings, file permissions, backups and
upgrades. A checklist closes it.

The admin's **System** page (`/admin/system`, for administrators) checks most
of these on the running server: look at it after every step.

## 1. The web root is the `public/` folder

Only `public/` is meant to be reachable from the web. Everything else
(`config/` with the database password, `src/`, `vendor/`, `var/` with the
backups) must stay out of reach.

**Recommended: the web server's document root is `public/`.** Then the other
folders cannot be requested at all, whatever the web server's other settings
are. The System page shows *Web root: public/* in green.

Apache (the `.htaccess` in `public/` routes the requests to `index.php`;
`mod_rewrite` must be enabled):

```apache
<VirtualHost *:443>
    ServerName example.hu
    DocumentRoot /var/www/campanella/public

    <Directory /var/www/campanella/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/example.hu/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/example.hu/privkey.pem
</VirtualHost>

# Plain HTTP only redirects to HTTPS.
<VirtualHost *:80>
    ServerName example.hu
    Redirect permanent / https://example.hu/
</VirtualHost>
```

nginx with PHP-FPM (nginx does not read `.htaccess`: the rules are here):

```nginx
server {
    listen 443 ssl;
    server_name example.hu;
    root /var/www/campanella/public;
    index index.php;
    client_max_body_size 20m;          # at least the largest image upload

    ssl_certificate     /etc/letsencrypt/live/example.hu/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.hu/privkey.pem;

    # Existing files (CSS, JavaScript, uploaded images) directly, everything else via index.php.
    location / {
        try_files $uri /index.php$is_args$args;
    }

    # Only index.php runs; no other PHP file, not even one uploaded by mistake.
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ \.php$ { return 404; }

    # No hidden files (.htaccess, .git …).
    location ~ /\. { deny all; }
}

server {
    listen 80;
    server_name example.hu;
    return 301 https://example.hu$request_uri;
}
```

The included `Dockerfile` already sets the web root to `public/`.

**If the web root cannot be changed** (some shared hosts), the whole project
goes into the web root, and the `.htaccess` in the project root routes every
request under `public/`. This protects the other folders only while Apache
honours `.htaccess` files (`mod_rewrite`, `AllowOverride All`): a server
change that turns them off would expose them. The System page warns about
this setup (*Web root: the project folder*). Check after installing that
`https://example.hu/composer.json` and `…/config/app.php` show the "page not
found" page, not the file.

## 2. HTTPS

The login cookie is sent only over HTTPS (`session.secure` is `auto`), so
without HTTPS nobody can log in safely. Get a certificate (e.g. Let's
Encrypt), and redirect plain HTTP to HTTPS in the web server (above).

### Behind a proxy

Often PHP does not see HTTPS itself: a proxy in front of it (a load balancer,
a CDN, the web server of a Docker host) ends the encrypted connection and
forwards the request in plain HTTP. The proxy tells the truth in the
`X-Forwarded-Proto` and `X-Forwarded-For` headers, but anyone can send those,
so Campanella believes them only from the proxies listed in `trusted_proxies`
(in `config/local.php`):

```php
'trusted_proxies' => ['172.18.0.1'],              // one address
'trusted_proxies' => ['10.0.0.0/8', 'fd00::/8'],  // or CIDR ranges
```

From a listed proxy, `X-Forwarded-Proto: https` makes the request secure
(so the login cookie gets `Secure`), and the visitor's address is taken from
`X-Forwarded-For` (the last address that is not a listed proxy): login
throttling then counts the visitor, not the proxy. List only addresses you
control; never `0.0.0.0/0`.

**Which address?** Open the System page over `https://`. Its *Proxy* line
shows the address the request came from (the proxy's, if there is one) and
which proxy headers arrived. If they arrive from an address that is not
listed, it warns and gives the line to add; once it is listed, the line is
green and *Connection* shows HTTPS. If no proxy header arrives at all while
you use `https://`, the proxy passes on HTTPS in some other way: ask your
host.

## 3. Settings

`config/local.php` holds the server's own settings. On a public server:

- `'debug' => false` (the default since 0.1.0). Debug mode shows error
  details, file paths included, to every visitor. The System page warns while
  it is on.
- The database user needs rights on Campanella's own database only.
- `upgrade.key` only while you need it for an upgrade, then remove it.

### Security headers

Every page gets:

| Header | Value |
|---|---|
| `Content-Security-Policy` | Public pages: only the site's own scripts, styles and images (no inline script, no other server; images of other sites only if the HTML filter allows them). The admin has its own, similar policy |
| `Permissions-Policy` | No camera, microphone, location, payment or USB |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Referrer-Policy` | `same-origin` |

The Content-Security-Policy means that even if a script got into a page
(e.g. through a bug), the browser would not run it. A theme that needs
something from another server (a web font service, analytics) can replace
the policy:

```php
'security' => [
    'content_security_policy' => "default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; object-src 'none'; base-uri 'self'; "
        . "form-action 'self'; frame-ancestors 'self'",
],
```

Every source you add is trusted with your visitors' pages: add only what the
theme needs, and never `'unsafe-inline'` or `'unsafe-eval'` for scripts. The
System page marks a replaced policy with a warning, as a reminder.

**HSTS** (`Strict-Transport-Security`) tells browsers to use only HTTPS for
the site for a given time. It is off by default, because it cannot be taken
back: a browser that got it refuses plain HTTP until it expires, even if your
certificate breaks. Turn it on once HTTPS works everywhere:

```php
'security' => ['hsts' => 31536000],   // a year; 'hsts_subdomains' => true only if every subdomain has HTTPS
```

## 4. File permissions

The web server's user (e.g. `www-data`) needs to **write** only:

- `var/cache/` (compiled templates),
- `var/backups/` (backups made by the upgrade page),
- `public/media/` (uploaded images).

Everything else should be readable but not writable by it, so that even a
flaw could not change the code. `config/local.php` holds the database
password: readable by the web server's user and yours only (e.g. `0640`).

The System page checks the writable folders. `public/media/` gets a
`.htaccess` that forbids running scripts there (on nginx, the configuration
above runs no PHP file but `index.php`).

## 5. PHP settings

- `display_errors = Off`, `log_errors = On` (errors go to the log, not to
  the visitors), `expose_php = Off`.
- `upload_max_filesize` and `post_max_size` above the largest image you want
  to upload (the System page shows the effective limit), and `memory_limit`
  for processing large images ([media settings](php-api/16-media.md#settings-configappphp-media)).
- OPcache on (faster; the System page shows it).

## 6. Backups

A backup has two parts:

- **The database:** `php bin/campanella db:backup` writes it into
  `var/backups/` (a `.sql.gz` readable by its owner only). The upgrade page
  makes one before every upgrade too.
- **The uploaded images:** the `public/media/` folder.

Copy both regularly to another machine: a backup on the same server is lost
with the server. A daily job, e.g. with cron:

```cron
30 3 * * * cd /var/www/campanella && php bin/campanella db:backup >/dev/null
```

Old backups are not deleted automatically: remove the ones you no longer need.

## 7. Upgrades

1. Make a backup (above).
2. Upload the new version (or `git pull`; `composer install --no-dev` if the
   dependencies changed).
3. Run `php bin/campanella install`, or open `/admin/upgrade` without a
   command line. Until then the site answers 503.
4. Read the version's section in the [CHANGELOG](../CHANGELOG.md): extra steps
   are at its top.

An installation older than 0.0.6 is upgraded to 0.0.7 first.

## Checklist before going live

- [ ] The web root is `public/` (or the `.htaccess` test in section 1 passes).
- [ ] HTTPS works, plain HTTP redirects to it; behind a proxy,
      `trusted_proxies` is set and the System page shows *HTTPS*.
- [ ] `debug` is `false`.
- [ ] `upgrade.key` is not set (unless an upgrade needs it right now).
- [ ] Only `var/cache/`, `var/backups/` and `public/media/` are writable by the
      web server; `config/local.php` is not readable by others.
- [ ] `display_errors` is off.
- [ ] Backups run, and are copied to another machine.
- [ ] The administrators have strong passwords; there is no test account.
- [ ] The System page shows no error and no warning you have not decided about.
