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
- **Login:** the same answer for a wrong e-mail address, a wrong password and
  a blocked account (since 0.2.0); attempts limited per address and e-mail,
  per address (IPv6: per /48 since 0.2.0), and per account (also for unknown
  addresses, so a locked account looks like any other); a new session ID on
  login; blocking an account (at once, since 0.2.0) or a changed password ends
  the sessions; a login lasts at most 12 hours, however active, and every login is listed
  on the profile, where it can be ended (since 0.1.4); no default account.
- **Forgotten password** (since 0.1.4): the same answer and response time for
  every address (the account is looked up and the e-mail sent after the
  response); a single-use link, valid for 60 minutes, stored only as a hash
  that also covers the account's address and password (so any change voids
  it), made from the site's set address (never the `Host` header); requests
  limited per address and per e-mail address; the new password ends every
  session.
- **Contact form** (since 0.1.5): a CSRF token, a honeypot, a time signed
  with a secret only the session knows (a form sent within 3 seconds is taken
  for a bot's), 5 messages per IP address (IPv6: /48) and 3 per e-mail
  address in an hour, at most 50 notification e-mails an hour, length limits; the messages are for
  administrators only, never handed to the site's templates, shown in the
  admin as plain text, and deleted after 180 days; the sender's IP address is
  not stored with them. Throttling counters keep IP and e-mail addresses only
  as keyed hashes, deleted after their time window.
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
- **The content model** (since 0.2.3): Blueprints, fields and lists are made
  only by the system roles; a machine name has a strict pattern before it
  becomes a table or a column name, and every one is quoted; the schema
  changes only after the whole model is checked again, with a backup first
  when data is touched; an imported file is checked like the editors' input
  (since 0.3.0 its choice keys and relation targets too), carries no object
  IDs, and is applied only as planned.
- **Search, lists and filters** (since 0.2.5): only what is published, for
  everyone; a visitor's filter only on the fields the list offers, each value
  checked by its type, a relation's only among the targets the visitor may
  see; the search's words are letters and digits only (no operators), at
  most 8, and 30 searches a minute per network (since 0.3.0).
- **Redirects** (since 0.2.7): only to a path of the same site, made by the
  system roles or by a page's change of path; never in the admin; loops lead
  nowhere; the header carries only ASCII (since 0.3.0).
- **Dependencies:** only MIT-compatible ones; `composer audit` runs in CI on
  every push.
- **Tests of the protections** (since 0.2.0): besides the PHP tests, the
  browser tests check most of the above in a real browser on every push (the
  login's answers and limits, the session cookie, the sessions, the forgotten
  password, the contact form, the Content-Security-Policy, the administrators'
  pages): see [Testing](testing.md#the-browser-tests).

## Security review for 0.3.0 (2026-10-24)

A review of what 0.2.2–0.2.9 added: the Blueprints and fields made in the
admin, the changes of the schema, the lists and the visitors' filters, the
content model's export and import, the search, the redirects, the admin's own
columns and the editor's image picker, and where they meet the existing
protections (access, CSRF, the Content-Security-Policy, the HTML filter). Each
area was read and then attacked by tests (`tests/run.php`, "Security review
(0.3.0)", besides the tests of each release). No critical or high issue was
found. Fixed:

| Severity | Issue | Fix |
|---|---|---|
| Low | The search had no limit: anyone could send query after query, the one page where a visitor's input starts a search of the whole site | At most 30 searches a minute per network (as the login counts addresses), then 429 with `Retry-After` |
| Low | An imported Blueprint's choice keys were not held to the editor's pattern: a key of any characters and length was stored (always escaped where shown, but it could exceed its column or break an address) | The pattern (1–64 letters, digits, `_ . -`) and at most 200 choices, checked for every stored definition |
| Low | An imported Blueprint's relation could point to users or the forms' messages (the editor never offers them; the messages stayed hidden by the policy), or to a Blueprint the site does not have | Refused by the import, as by the editor |
| Low | A redirect to a path with accented letters put them into the `Location` header as they are (browsers read it, but a header is ASCII) | Percent-encoded |
| Low | An absurdly large page number of the search, or of the redirects' list, ended in an error (500) instead of an empty page | 404 / an empty page, as the other lists already did |

Checked and found sound, among others:

- **Names that become SQL:** a Blueprint's and a field's machine names
  (`^[a-z][a-z0-9_]{0,39}$`, the reserved ones refused, a capability's names
  refused) are the only parts of a table or column name, and every identifier
  is quoted; values always go as parameters. A list's conditions, sort orders
  and columns, the admin's columns and filters, and the visitors' filters
  take only names the Blueprint has, checked again whenever they are loaded.
- **Nothing unpublished through a list:** the list's own conditions, its
  `published` scope, the visitor's filters and the Query engine's access
  conditions all apply together; a relation's filter offers only the targets
  the visitor may see and takes no other value, so it cannot tell whether a
  hidden object has links. The search filters by the publication status in
  the database and loads the page's objects through the Query engine again.
- **The content model's import:** at most 1 MB and 16 levels of JSON; every
  item through the same checks as the editors'; no object IDs (a list's
  relations name their objects); all or nothing; the fingerprint stops a
  confirmation of a plan the site has changed since; the uploaded file is
  never stored; a backup first when objects are affected.
- **The schema's changes:** only through the SchemaSync, after the whole model
  is defined again; a blocked step stops the change; a failure puts back the
  previous definition; never while an upgrade is pending.
- **Redirects:** a manual one only from a path no page has to a path of the
  site (`//`, `\`, white space, `?` and `#` refused), an automatic one only
  from an object's own old path; never in the admin; at most 5 in a row, a
  loop is a 404.
- **The admin's new pages and endpoints:** the system roles only (System),
  the admin's users only (the image picker, which answers an expired session
  in JSON); a CSRF token on every POST; the Content-Security-Policy without
  `unsafe-eval` (the browser tests caught a test of ours that needed it); the
  image picker builds its dialog from the answer with DOM methods, never HTML.
- **Output:** every new template escapes; a choice's label, a field's label
  and a list's title go through Twig's escaping; formatted fields pass the HTML
  filter on save, also own fields of the `html` type; a web address field
  links only `http(s)` addresses.
- **Dependencies:** unchanged since the review of 0.2.0 (Twig 3.30.0,
  symfony/mailer and symfony/mime 7.4.19, symfony/html-sanitizer 7.4.20,
  Bootstrap 5.3.8, Jodit 4.17.1); `composer audit` passes in CI.

## Security review for 0.2.0 (2026-10-11)

A review of the whole 0.1.x layer together: sessions and the session list,
the forgotten password, the contact form, e-mail, the work after the
response, throttling, trusted proxies, and the upgrade. The reviews of 0.1.4
and 0.1.5 each looked at their own changes; this one looked for what only
shows where the parts meet: every way a password, an e-mail address or an
account's status can change, and whether each one ends the logins and voids
the links; every limit that counts by address, and whether they count alike;
what the session holds for a visitor who never logs in. No critical or high
issue was found. Fixed:

| Severity | Issue | Fix |
|---|---|---|
| Medium | The upgrade's backup was made after the new tables and the new schema version were written: restoring it did not bring back the old version's database (found while writing the [upgrade guide](upgrading.md#going-back)) | The backup first, before anything is written; a schema upgrade is always backed up; the upgrade test checks it |
| Low | Blocking an account in the admin ended its logins only at their next request: a login that made none until the account was activated again went on. The command line ended them at once | The admin ends them at once too |
| Low | Logins and the forgotten password counted an IPv6 address by its /64 for their per-address limits, while one connection often has a whole /48 (the contact form already counted by /48): one such connection could try 65,536 times as many passwords across the accounts | The per-address limits count by /48 (`Throttle::network()`); the per-account limit per address stays /64 |
| Low | A blocked account with the right password got "The account is blocked", which told whoever tried that the password was right | The same answer as a wrong password |

Checked and found sound, among others:

- **Every change of a password ends the other logins and voids the link:**
  one's own (the profile; this login goes on), an administrator's, the
  command line's, the forgotten password's (every login), and a rehash on
  login (the stamp changes); a changed e-mail address voids the link.
- **The session:** a new ID on logging in and when a reset link's token is
  stored; the CSRF token is renewed on logging in; logging out destroys the
  session even if the database fails; the absolute lifetime survives one's own
  password change; a login ended from the list cannot come back.
- **Work after the response** (`Deferred`): the session is written and
  closed first, so the next request does not wait; the forgotten password
  queues its work for every address alike, so the response's headers and
  time do not tell; a failure is logged, the rest still runs.
- **Throttling:** atomic counters; only keyed hashes; the address from
  `X-Forwarded-For` only behind a trusted proxy, and only the last address
  that is not one (what the visitor writes before it is ignored); the guards
  (the honeypot) before any counting.
- **E-mail:** no header injection (the subject holds nothing a visitor
  wrote, addresses are checked, names are encoded by symfony/mime); the log
  keeps no text and no link.
- **Dependencies:** Twig 3.30.0, symfony/mailer, symfony/mime 7.4.19,
  symfony/html-sanitizer 7.4.20 and their dependencies (all MIT or BSD);
  Bootstrap 5.3.8; Jodit 4.17.1. The advisories of 2026 for them are fixed
  in these versions: Twig's sandbox and code-injection advisories (all
  affecting 3.26.x and earlier, e.g. CVE-2026-48805, CVE-2026-49981,
  CVE-2026-46640), symfony's of
  May 2026 (fixed in 7.4.12: CVE-2026-45064, -45067, -45068, -45070), and
  Jodit's prototype pollution (CVE-2026-54756, fixed in 4.12.18).
  `composer audit` passes in CI.

The accepted risks below were weighed again; they stand, with three added
(marked 2026-10-11).

## Security review for 0.1.5 (2026-10-11)

An independent review of the 0.1.5 changes (the contact form, its messages,
the notification, data protection). It found no way for editors or visitors
to read the messages, and no stored XSS or e-mail header injection. Fixed
before the release:

| Severity | Issue | Fix |
|---|---|---|
| Medium | The form's time was signed with the CSRF token, which the page shows: a bot could forge an old time | A secret kept only in the session |
| Medium (privacy) | The throttling counters kept IP and e-mail addresses as plain SHA-256 hashes (recoverable by trying every address), and never deleted the expired ones | A keyed hash (a secret of the installation); expired rows are deleted |
| Low–medium | Limits only per IP address (IPv6 /64) and per e-mail address: one IPv6 /48 could flood the administrators' mailboxes | At most 50 notification e-mails an hour on the whole site (a limit on the messages themselves would let a few addresses close the form for everyone: the second look at the fixes found this), IPv6 counted per /48, `+tags` of addresses ignored |
| Low | Old messages were deleted only after a new one or when the list was opened | Also when the dashboard is opened, and a `submissions:prune` command for cron |
| Low | `sitemap.xml` listed `/contact` while the form was off | Only while it is on |
| Low | The browser counts a line break as one character, the server counted two; a value was cut before it was checked | Line breaks normalized first; nothing is cut before the check |
| Low | Error pages of a visitor with a session (e.g. a theme showing `contact_form()` everywhere) had no `Cache-Control: private` | Every response with a session gets it |
| Info | Without a valid CSRF token, what was sent was shown again: another site could put its text on the contact page | Nothing is shown again then |
| Info | The sender's e-mail address was lowercased whole | Only its domain |

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

Weighed again for 0.2.0 on 2026-10-11: each still stands.

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
- **A contact form sender's address can be used up** (since 0.1.5): 3
  messages per e-mail address in an hour, so someone who knows an address can
  keep its owner from writing for an hour. Without it, one address could be
  used to flood the site. The owner can still write by e-mail.
- **The contact form's limits per IP address** are per /48 for IPv6, so
  visitors sharing one (a company, a mobile operator) share the 5 messages an
  hour.
- **The installer shows the requirements** (PHP and database versions,
  missing extensions) to anyone before the site is installed: it helps the
  person installing, and there is nothing yet to protect. Once a user exists,
  the page answers 404.
- **50 wrong install or upgrade keys** from all addresses together block the
  page for 15 minutes: a third party can delay a browser installation, but
  cannot take it over; the command line still works.
- **The admin's Content-Security-Policy allows inline styles** (the editor,
  Jodit, needs them); scripts remain only the site's own.
- **Forms start a session for visitors** (2026-10-11): the CSRF token of
  the login, the forgotten password and the contact form lives in a session,
  so each visit of such a page without a cookie creates one (a file in PHP's
  session folder). A flood of such visits can fill that folder; limit the
  request rate at the web server or the proxy where this matters. Logged-out
  pages without a form start none.
- **`X-Forwarded-Proto` is read from its first value** (2026-10-11), as
  most frameworks do. Behind a chain of proxies that append to the header
  rather than set it, a visitor could add a value of their own; the worst
  they get is a response marked for HTTPS on their own plain HTTP request.
  The proxies should set the header (nginx: `proxy_set_header
  X-Forwarded-Proto $scheme;`).
- **The browser installer's limit file** (2026-10-11):
  `var/cache/install-throttle.json` keeps the wrong install keys' counters
  with a plain SHA-256 of the visitor's address, for 15 minutes, only while
  the site is not installed (there is no database yet for a keyed hash).
  `var/` is not reachable from the web.
