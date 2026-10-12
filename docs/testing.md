# Testing

Campanella has four kinds of automated checks. All of them run in CI on every
push ([`.github/workflows/ci.yml`](../.github/workflows/ci.yml)); each can be
run locally too.

| Check | Command | What it covers |
|---|---|---|
| Tests | `composer test` | The PHP code, on a real database (table prefix `test_`) |
| Static analysis and docs | `composer analyse`, `composer docs:check`, `composer docs:links`, `composer lang:check` | Types (PHPStan level 8); every public class and method documented; links; translations |
| Upgrade test | `tests/upgrade/run.sh <0.1.0 source>` | A real upgrade from 0.1.0: nothing is lost (since 0.2.0; see [The upgrade test](php-api/17-migrations.md#the-upgrade-test)) |
| Browser tests | `tests/browser/run.sh` | The site in a real browser, its protections first (since 0.2.0; below) |
| Release package | `tests/package/check.sh` | The installation package as a site owner gets it: its contents, installing it from the browser on a minimal PHP, the browser tests on it (since 0.2.0; below) |

The database of all of them comes from the `CAMPANELLA_DB_*` environment
variables (`HOST`, `PORT`, `NAME`, `USER`, `PASSWORD`), each with its own table
prefix, so they never touch a site's own tables.

## The browser tests

What the 0.1.x series was checked by hand, played out in headless Chromium
([Playwright](https://playwright.dev/python/)) against a fresh installation.
They serve security first: a protection that silently stops working (a missing
CSRF check, a session that survives a password reset, a message shown as HTML)
fails the build.

```bash
pip install -r tests/browser/requirements.txt
python3 -m playwright install chromium     # once; on Linux add --with-deps for the system libraries
CAMPANELLA_DB_HOST=127.0.0.1 CAMPANELLA_DB_PASSWORD=… tests/browser/run.sh
```

Options: `--keep` keeps the temporary site, its tables (prefix `brw_`), the web
server's log and the caught e-mails, and prints where they are; `--headed` shows
the browser. The ports: `CAMPANELLA_TEST_PORT` (8099) for the site and
`CAMPANELLA_TEST_SMTP_PORT` (2599) for the mail catcher.

`run.sh` copies the working tree (the files `git ls-files` lists, with
`vendor/`) to a temporary folder with a `config/local.php` of its own (debug off,
as on a live site), installs it with the sample content and three users, then
starts a mail catcher (`tests/browser/mailcatcher.py`: a minimal SMTP server
that saves every message as an `.eml` file) and PHP's built-in web server, and
runs `tests/browser/test_site.py`. The test site trusts `127.0.0.1` as a proxy:
each browser context sends an `X-Forwarded-For` address of its own, so every
context is a different visitor for the limits that count by address.

What they check:

| Area | Checks |
|---|---|
| Every page | No JavaScript error, no Content-Security-Policy violation, no missing stylesheet, script or image, no `alert()` that ran |
| Headers | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and a Content-Security-Policy without inline scripts on the public pages; the admin redirects to the login page |
| Logging in and out | A wrong password and an unknown address get the same answer; the honeypot and a missing CSRF token are refused; a new session id on logging in (no session fixation), an HttpOnly, SameSite session cookie; a GET to the logout address does nothing; after logging out the old session cookie is worthless |
| Lock-out | After 5 wrong passwords the right one is refused, but only for that account from that address; one address is refused after 20 attempts at any accounts |
| Sessions | The profile lists the user's own sessions only; ending one, and ending every other, logs those out and keeps the current one |
| Forgotten password | The same answer for an unknown address, and no e-mail; the e-mail's link works once, its token leaves the address bar, a short password is refused; the new password ends the sessions of before and replaces the old one |
| Contact form | Too quick a sending is shown again, and not saved; the honeypot is told "sent" and nothing is saved or e-mailed; a missing CSRF token is refused without showing the text again; a real message is saved, e-mailed to the administrator with `Reply-To` the sender and none of the sender's text in the subject; markup in it stays text in the admin |
| Admin | Every list and form opens for the administrator; a new article is saved, one without a CSRF token is not; the profile is saved; the System page shows the version; an editor gets 403 on the administrators' pages (users, Submissions, System) |

A new feature with a protection of its own adds its check to `test_site.py`, in
the area it belongs to. The test users and passwords exist only on the temporary
site, which is deleted afterwards.

Playwright (Apache-2.0) is a development tool only: it is not part of the
release package (`/tests` is excluded from it) and not needed to run a site.

## The release package

The zip attached to a GitHub release is what most site owners install. CI
checks it on every push, built exactly as the release builds it, and a
release is published only if the check passes (the `package` job).

```bash
tests/package/check.sh             # needs network for composer install
tests/package/check.sh --offline   # vendor/ copied from this folder instead
```

1. **Build** (`tests/package/build.sh <folder>`, which the release uses too):
   the files git tracks or would track, without those `.gitattributes` marks
   `export-ignore` (the tests, the CI settings, PHPStan's), plus
   `composer install --no-dev --optimize-autoloader`; zipped, with its
   SHA-256. The zip is then unpacked into an empty folder: the clean host.
2. **Contents** (`tests/package/contents.php`): what a site needs is there
   (`vendor/`, the `.htaccess` files, Bootstrap and Jodit, the docs);
   nothing that must not be: no tests, no `config/local.php`, no uploads,
   backups, caches, `.env` or version-control folders, no development
   package; every dependency MIT, BSD, ISC or Apache-2.0 (MIT-compatible); LF line endings; every PHP
   file's syntax.
3. **Installing from the browser** (`tests/package/install_flow.py`), as a
   site owner without a command line does: without an install key the page
   says how to set one and offers no form; with it, a wrong key is refused,
   the right one installs the site and logs the administrator in; the System
   page shows no error; `/install` answers 404 afterwards; logging out and in
   again works.
4. **The browser tests** on another copy of the package
   (`tests/browser/run.sh --site <folder>`).

In CI the PHP has only the extensions Campanella needs (`ctype`, `dom`,
`json`, `mbstring`, `pdo_mysql`, `session`, `gd`) and what Composer needs:
none of the recommended ones, as on a bare web host.

