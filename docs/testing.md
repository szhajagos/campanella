# Testing

Campanella has five kinds of automated checks. All of them run in CI on every
push ([`.github/workflows/ci.yml`](../.github/workflows/ci.yml)); each can be
run locally too.

| Check | Command | What it covers |
|---|---|---|
| Tests | `composer test` | The PHP code, on a real database (table prefix `test_`) |
| Static analysis and docs | `composer analyse`, `composer docs:check`, `composer docs:links`, `composer lang:check` | Types (PHPStan level 8); every public class and method documented; links; translations |
| Upgrade test | `tests/upgrade/run.sh <old source>` | A real upgrade from 0.1.0, 0.1.5, 0.2.0, 0.2.9, 0.3.0, 0.3.1, 0.3.2, 0.3.3 or 0.3.4 (from 0.2.9 on with a content model): nothing is lost (since 0.2.0; see [The upgrade test](php-api/17-migrations.md#the-upgrade-test)) |
| Performance check | `tests/performance/run.sh` | 10 000 objects; the busiest pages answer within their limits of time and SQL queries (since 0.3.0; [below](#the-performance-check)) |
| Browser tests | `tests/browser/run.sh` | The site in a real browser, its protections first (since 0.2.0; below) |
| Release package | `tests/package/check.sh` | The installation package as a site owner gets it: its contents, installing it from the browser on a minimal PHP, the browser tests on it (since 0.2.0; below) |
| Guide's screenshots | `tests/guide/screenshots.sh [hu\|en]` | Not a test: makes the [guide](guide/README.md)'s screenshots from a fresh site in each language, installed from the browser as the guide tells (since 0.2.1). Run it after a change of the admin's look, and commit the changed images |

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
`CAMPANELLA_TEST_SMTP_PORT` (2599) for the mail catcher. With
`CAMPANELLA_TEST_GDB=1` and `gdb` installed (as on CI, since 0.3.2) the web
server runs under gdb: if PHP itself crashes (a segmentation fault: a fault of
PHP, not of a PHP script), the end of the server's log shows the last requests
and the backtrace.

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
| Admin | Every list and form opens for the administrator; a new article is saved, one without a CSRF token is not; the profile is saved; the System page shows the version; an editor gets 403 on the administrators' pages (users, Submissions, System, Components) |
| Search and importers | The header's search finds and marks the words, never a draft; an importer made in the admin, its trial, its run going on by itself to its end |
| Components (since 0.3.3) | A component made in the three steps (a menu as navigation, for logged-in users): the Appearance group in the menu, a setting out of range refused, the saved one listed; an editor gets 403 on the components' pages |
| Regions and layouts (since 0.3.4) | From the Appearance menu: a component added to the footer region on the Regions page, then taken out; the site's layout and a region's look saved on the Layouts page, and set back. The header is made of components on every page |
| Colour schemes (since 0.3.6) | From the Appearance menu: a scheme and the dark mode saved; the admin and the site in dark, the site in the scheme's accent; set back |
| Colour mode switch (since 0.3.6) | A visitor's switch in the navigation: dark at once (without loading the page), kept on the next page; without JavaScript the form makes it light and leads back |
| A page's components (since 0.3.5) | An editor, from the About us page's form: only the regions meant for a page, a new component in the three steps (its region kept, no conditions), shown on that page only, then deleted |
| Files (since 0.3.2) | Two files uploaded on the Files list, one for the logged-in users; the media library lists them; the editor's file picker links the selected text to one; a visitor downloads the public one (an attachment, with the download's CSP) and gets 404 for the other, which a logged-in editor can download |

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

## The performance check

*Since 0.3.0.* How the pages that grow with a site's content (a list, the
admin's list, the search) answer when there is a lot of it. In CI on MariaDB
10.6 and MySQL 8.0; locally:

```bash
bash tests/performance/run.sh                  # --objects N: fewer, without the limits; --offline; --keep
php tests/performance/measure.php <site>       # again, on a site kept with --keep
```

1. **The site:** this code installed into a temporary folder, with the sample
   content (`seed`); the tables get the prefix `perf_`, dropped before and after.
2. **The content** (`fixture.php`): a content model imported as System →
   Content model imports it: a Blueprint `perf_event` with a queryable date,
   choice, decimal and yes/no field, a multi-valued text field and a relation
   to a category (one of 30), its 4 admin columns; a list of it with 4
   visitors' filters (20 a page), another as a table (50 a page). Then 10 000
   objects saved one by one as the admin saves them (search index, relation,
   path; one in ten a draft), their texts of 3 paragraphs made from a fixed
   vocabulary with a fixed seed, so every run has the same data.
3. **The pages** (`measure.php`), each requested once to warm up, then 7 times
   as new requests (a new Kernel, container and connection): the list's first
   and 100th page, with one and with all 4 filters, the table; the admin's
   list unsorted, sorted by an own column, with 4 filters, with a title search
   on page 5; the search for a common word, two words, a rare word, a word's
   beginning on page 20; an event's own page. Checked: the **median time**
   against the page's limit (500 ms, the event's page 300 ms), and the
   **number of SQL statements** the request sent (the server's count) against
   75 for the site's pages and 60 for the admin's (since 0.3.4 the site's header
   and footer are components: about 15 statements, [below](#the-layouts-since-034)).
   The time depends on the machine, so its limit leaves room for a slow
   one; the count does not, and a page that loads something once per object
   shown goes over it at once.

### The results for 0.3.0

Measured on 2026-10-24 (a development container; PHP 8.4, the medians):

| Page | MariaDB 10.11 | MySQL 8.0 | SQL statements |
|---|---|---|---|
| A list, first page | 101 ms | 119 ms | 47 |
| A list, page 100 | 120 ms | 112 ms | 47 |
| A list, all 4 filters | 133 ms | 41 ms | 47 |
| A list as a table, 50 a page | 107 ms | 55 ms | 47 |
| Admin list | 54 ms | 55 ms | 43 |
| Admin list, sorted by a column | 53 ms | 52 ms | 43 |
| Admin list, 4 filters | 29 ms | 29 ms | 43 |
| Search, a common word | 69 ms | 114 ms | 46 |
| Search, a rare word | 23 ms | 35 ms | 46 |
| An event's page | 14 ms | 22 ms | 46 |

What they show:

- **No page loads per object shown.** The number of statements is the same
  with 300 objects as with 10 000, and with 20 objects on a page as with 50:
  the objects of a page, their fields, capabilities and relations, and the
  related objects are loaded in a few queries each, by their IDs.
- **The slowest is a list that matches most of the objects** (about 9 000 of
  the 10 000): its count and its page each read every match, about 40 ms each,
  because whether a visitor may see an object (published, its date passed) is
  checked row by row. An index on the sorted field does not change that (tried
  on `nap`); narrower conditions or filters make it faster.
- The rest of a request's time (about 15 ms) is starting the program and
  rendering the page, the same for every page.

### The layouts (since 0.3.4)

The site's pages show their header and footer from components (the upgrade to
0.3.4 makes them): about 15 statements more on every page of the site, the same
whatever the page shows: the regions' components in one query and their
capabilities (about 10), the footer's snippet with its rights (5), the main
menu. Measured on 2026-10-29 (MariaDB 10.11, PHP 8.3): a list's page 62
statements (47 before), the search 61 (46), an event's page 61 (46); the times
did not change noticeably. Later: a page's components kept between requests.

The full measurement (100 000 objects, many visitors at once, the cache) is
planned for 1.0.
