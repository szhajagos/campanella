# Security

Campanella is built for the public, not for one server: the defaults must be
safe for someone who changes nothing (the ROADMAP's "Secure by default"
decision). From 0.1.0 on, a release is not made with a known open security
gap; what is knowingly left as it is, is recorded here, with the reason.

How to run a site safely is in the [deployment guide](deployment.md).

## Reporting a vulnerability

Please report it privately to the maintainer (not in a public issue), with the
steps to reproduce it. A fix is released as soon as possible, and the
CHANGELOG's **Security** section names it.

## What protects a site

- **Access:** every query is filtered by the access policy before it reaches
  the database (drafts and scheduled content are never fetched for visitors);
  every admin action checks the policy and a CSRF token; editors cannot delete
  or manage users; the contact form's messages are for administrators only
  (since 0.1.5).
- **Login:** the same answer for a wrong e-mail address and a wrong password;
  attempts limited per address and e-mail, per address, and per account (also
  for unknown addresses, so a locked account looks like any other); a new
  session ID on login; a blocked account or a changed password ends the
  sessions; a login lasts at most 12 hours, however active, and every login is listed
  on the profile, where it can be ended (since 0.1.4); no default account.
- **Forgotten password** (since 0.1.4): the same answer and response time for
  every address (the account is looked up and the e-mail sent after the
  response); a single-use link, valid for 60 minutes, stored only as a hash
  that also covers the account's address and password (so any change voids
  it), made from the site's set address (never the `Host` header); requests
  limited per address and per e-mail address; the new password ends every
  session.
- **Output:** Twig escapes everything; HTML texts are filtered on every save
  with an allowlist; link URLs and site paths are checked; templates run in a
  sandbox that cannot read hidden fields (password hash, e-mail address,
  roles); a strict Content-Security-Policy on every page; absolute URLs
  (canonical, sharing, sitemap) only from the site's configured address, never
  from the request's `Host` header.
- **Files:** uploaded images are recognised by their content and re-encoded;
  random names; the media folder runs no script; backups are not reachable
  from the web.
- **Installing and upgrading:** from the browser only with a long key (wrong
  keys limited) or as an administrator; backups before migrations.
- **E-mail:** the mail server's address (with its password) is set only in
  the configuration file, never shown (the System page shows its kind and host;
  error messages have the password masked); the test e-mail goes only to the
  logged-in administrator's own address, at most 5 in 15 minutes; addresses
  and headers are checked by symfony/mailer; the log never keeps the text.
- **Dependencies:** only MIT-compatible ones; `composer audit` runs in CI on
  every push.

## Security review for 0.1.4 (2026-10-10)

An independent review of the 0.1.4 changes (the absolute session lifetime,
the session list, the forgotten password, work after the response). No
critical or high-severity issue was found. Fixed before the release:

| Severity | Issue | Fix |
|---|---|---|
| Medium | A password set on the command line (`user:password`) did not void a forgotten password's link that was already sent | The link's hash covers the account's password hash and e-mail address: any change voids it, however it is made; the command now also ends the user's logins |
| Medium | On Apache's mod_php the deferred e-mail held up the browser's next request on the same connection, so its timing showed whether an address was registered | `Connection: close` with such responses; the remaining connection-close timing is an accepted risk (below), PHP-FPM recommended |
| Low | The link's token was put into the visitor's existing session, whose ID someone else could have planted | A new session ID when the token is stored |
| Low | The new password was set inside the link's locked transaction, with the listeners (session ends, e-mails): a failing listener could roll the change back silently | The link is used up in a short transaction first; the password is set after it, and the sessions are ended directly |
| Low | Every response had a `Content-Length`, which turns off PHP's own output compression | Only responses with deferred work have one, and not when PHP compresses |
| Low | Any database error looked like a missing table: a revoked login could be let through during a lock wait | `Connection::tableExists()` answers no only for "no such table" (`42S02`) |
| Low | A link sent before the account's e-mail address changed, or before it was blocked, kept working | Voided by both |
| Low | (A second look at the fixes) Logging out failed with a database error before the session was destroyed; a password set with a link could overwrite a block made in the meantime | The session is destroyed whatever happens; the account is read again, and must be active, before the password is set |
| Info | 20 wrong links from a shared address blocked valid links of others behind it | The limit on wrong links is gone: guessing one is hopeless (2^384) |

## Security review for 0.1.0 (2026-10-08)

Three independent reviews of the whole code (authentication and sessions;
output, HTML and headers; data, files and access), plus the dependencies. No
critical or high-severity issue was found. Fixed:

| Severity | Issue | Fix |
|---|---|---|
| Medium | On dual-stack servers PHP may report IPv4 visitors as `::ffff:1.2.3.4`: all of them shared one login-throttle bucket (one attacker could lock everyone out), and a proxy listed as `127.0.0.1` was not trusted | `Request::normalizeIp()`: such addresses become plain IPv4 everywhere |
| Low | Templates could read hidden fields through `get()`, `values()` or `as()` (e.g. a theme printing `author.values`), and `roles` was readable | The Twig sandbox (`TemplatePolicy`); `roles` and `account_status` are hidden fields |
| Low | A locked account answered differently from an unknown address (account enumeration) | Unknown addresses have their own account counter |
| Low | Parallel requests could exceed the per-address login limit | It is counted before the password is checked |
| Low | An administrator could set their own password on the user page without the current one | Only on the profile, with the current password |
| Low | A huge `page` number caused a 500 error (and a log entry) | Clamped: an empty page, 404 |
| Low | Without `mod_rewrite`, the root `.htaccess` served the project's files | It denies everything then (fail closed), hides dot files; `var/` has its own deny |
| Hardening | An early error page had no Content-Security-Policy; a category page listed all its children | Fixed; at most 500 children |

Before the review, the same day: an atomic throttle counter, a per-account
login limit, an overall limit on wrong install and upgrade keys, IPv6 counted
by /64, site paths without backslashes or control characters
(`Routable::isSafePath()`), backslashes encoded in `url()`, and the upgrade
page's details only for an allowed request.

Dependencies: Twig 3.30.0, symfony/html-sanitizer 7.4.20 and their
dependencies (all MIT or BSD); Bootstrap 5.3.8; Jodit 4.17.1, newer than every
fixed version of the Jodit advisories of 2026 (CVE-2026-55886, -58263, -62324,
-65841; the server filters every HTML text anyway). `composer audit` could not
reach its database from the review's environment: it runs in CI instead.

## Known and accepted (decided 2026-10-08)

- **An account can be locked for 15 minutes** by someone who sends 30 wrong
  passwords for it from several addresses. This is the price of limiting
  guesses per account; the alternative (letting fresh addresses through) would
  let a botnet guess without limit. An administrator is not affected on other
  accounts; `max_attempts_per_account` is a setting.
- **Successful logins count towards the per-address limit** (20 in 15
  minutes by default): otherwise logging in to one's own account would reset
  the counter between guesses at others. Behind one shared address (an
  office) the setting `max_attempts_per_ip` can be raised.
- **A forgotten password's requests can be used up** (since 0.1.4): 3 per
  e-mail address in an hour, counted for any address, so someone who knows a
  user's address can delay that user's link by up to an hour. Counting only
  registered addresses would tell which are registered. An administrator can
  still set a new password.
- **The forgotten password on Apache's mod_php** (since 0.1.4): the answer is
  complete for the browser at once, and its next request uses a new
  connection, but PHP closes the connection only when the deferred work (the
  account's lookup and the e-mail) is done. A script that times the close can
  tell a registered address (an e-mail is sent) from an unknown one, at the
  pace the request limits allow (3 per address an hour, 5 per IP address in
  15 minutes). PHP-FPM (and LiteSpeed) close the connection before the work:
  use it where this matters. A queue run apart from the requests would remove
  it everywhere; it may come later.
- **A forgotten password's link in the browser's history:** the link's own
  address (with the token) may stay in the history and in the web server's
  log; it works once, for 60 minutes, and only while the account's password
  and address are unchanged.
- **The installer shows the requirements** (PHP and database versions,
  missing extensions) to anyone before the site is installed: it helps the
  person installing, and there is nothing yet to protect. Once a user exists,
  the page answers 404.
- **50 wrong install or upgrade keys** from all addresses together block the
  page for 15 minutes: a third party can delay a browser installation, but
  cannot take it over; the command line still works.
- **The admin's Content-Security-Policy allows inline styles** (the editor,
  Jodit, needs them); scripts remain only the site's own.
