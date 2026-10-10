# 11. Users and login

*Since 0.0.3.*

A user is an ordinary object too: the `user` Blueprint consists of the
`Titled` (name) and `Authenticatable` capabilities. The latter brings
`Identifiable` (e-mail address) with it. The logged-in user takes effect in the
access control policies as an `Actor`, with its roles.

```
user = Titled + Identifiable + Authenticatable
         name    e-mail         password hash, account status, roles
```

## Capabilities

### Identifiable

`Campanella\Capability\Identifiable` · name: `identifiable` · table: `cap_identifiable`

| Field | Type | Storage | |
|---|---|---|---|
| `email` | String(254) | Table | required, unique, hidden |

| Method | Description |
|---|---|
| `email(): string` | |
| `setEmail(string $email): void` | Sets it normalized |
| `static normalize(string $email): string` | Lowercase form without whitespace: `' Anna@Example.HU '` → `'anna@example.hu'` |
| `prepareForSave()` | Normalizes before save |
| `validate()` | For an invalid format: `['email' => new Message('validation.invalid_email')]` ("invalid e-mail address") |

### Authenticatable

`Campanella\Capability\Authenticatable` · name: `authenticatable` · table: `cap_authenticatable` · depends on: `Identifiable`

| Field | Type | Storage | |
|---|---|---|---|
| `password_hash` | String | Table | required, hidden |
| `account_status` | String | Table | indexed, default: `active`; hidden (since 0.1.0) |
| `roles` | String, multi-valued (`Field::UNLIMITED`, 32 characters) | Table (`field_values`) | e.g. `administrator`, `editor`; queryable: `->where('roles', '=', 'editor')`; hidden from templates (since 0.1.0). Since 0.0.6; before, a StringList in a column (the migration `core:0006_roles_multi_value` moves them) |

| Method / constant | Description |
|---|---|
| `MIN_PASSWORD_LENGTH` (10), `MAX_PASSWORD_BYTES` (72) | Password rule. The 72-byte upper limit is a bcrypt limitation |
| `setPassword(string $password): void` | Hashes it (`password_hash`, `PASSWORD_DEFAULT`). On a rule violation: `ValidationException` (with the `password` key) |
| `static checkPassword(string $password): void` | Only the rules, without setting it (since 0.1.4); `ValidationException` as above |
| `verifyPassword(string $password): bool` | `password_verify` |
| `needsRehash(): bool`, `rehash(string $password): void` | If PHP switches to a stronger default, the hash is upgraded automatically on login |
| `status(): AccountStatus`, `isActive(): bool`, `block()`, `activate()` | Account status |
| `roles(): list<string>`, `setRoles(array $roles)`, `hasRole(string $role): bool` | Roles (`^[a-z][a-z0-9_]{0,31}$`); `setRoles()` trims them and leaves out empty and repeated ones |
| `validate()` | Returns an error for an invalid role name |

`AccountStatus` (`enum: string`): `Active = 'active'`, `Blocked = 'blocked'`.

**Hidden** (`hidden`) fields (`password_hash`, `email`) are not accessible from
templates as `{{ user.email }}`; in PHP, `get()` reads them. This way a template
cannot accidentally expose the e-mail address or the hash.

### Authorable

`Campanella\Capability\Authorable` · name: `authorable` · no table of its own

| Relation | Cardinality | Target |
|---|---|---|
| `author` | One | An object with the `Identifiable` capability |

| Method | Description |
|---|---|
| `authorId(): ?int` | |
| `setAuthor(CampanellaObject\|int $user): void` | |

`ObjectService::create()` automatically sets the logged-in user
(`ActorKind::User`) as the author if no author has been given yet. On the
article page the `_relations` partial renders it as "Szerző: …" ("Author: …").

## Roles (DefaultPolicy)

| Role | Can view | Can create, update, publish | Can delete | Can manage users |
|---|---|---|---|---|
| `administrator` | everything | everything | yes | yes |
| `editor` | everything, drafts included | content | no | no |
| (none, or anonymous) | whatever is not Publishable, or is published | no | no | no |

Constants: `Actor::ADMINISTRATOR`, `DefaultPolicy::EDITOR`.

## AuthService

`Campanella\Auth\AuthService` · **Public** · container: `AuthService::class`

| Method | Description |
|---|---|
| `attempt(Request $request, string $email, string $password): LoginResult` | Login attempt: guards, throttling, password, account status; on success it also logs the user in |
| `login(Request $request, CampanellaObject $user): void` | Logs in without checks (e.g. after a second factor). New session ID, new CSRF token |
| `refresh(Request $request, CampanellaObject $user): void` | After the user changed their own password: this session goes on with a new ID and stamp (since 0.1.0), but not longer: its login time stays |
| `logout(): void` | Destroys the session |
| `currentUser(Request $request): ?CampanellaObject` | The logged-in user. Does not start a session for anonymous visitors. Logs out a blocked or deleted account, and a login older than the absolute lifetime |
| `sessions(Request $request): list<SessionInfo>` | The logged-in user's logins in progress, this one marked (since 0.1.4) |
| `endSession(Request $request, int $id): bool` | Ends one of the logged-in user's **other** logins; false for an unknown ID, someone else's login, or this one (since 0.1.4) |
| `endOtherSessions(Request $request): int` | Ends every login of the logged-in user but this one; returns how many (since 0.1.4) |
| `currentActor(Request $request): Actor` | The same as an `Actor`; the `Kernel` passes this to controllers |
| `static actorFor(CampanellaObject $user): Actor` | `ActorKind::User`, ID, roles, name |
| `findUserByEmail(string $email): ?CampanellaObject` | |
| `guards(): list<LoginGuard>` | The configured guards |
| `absoluteTimeout(): int` | The longest a login lasts, in seconds; 0: no limit (since 0.1.4) |
| `ABSOLUTE_TIMEOUT` | `43200` (12 hours): the default of the constructor's `int $absoluteTimeout` argument (the Kernel passes `session.absolute_timeout`); after it, `?SessionRegistry $sessions = null` (since 0.1.4; null: logins are not recorded) |
| `GENERIC_ERROR` | The message key `'auth.invalid_credentials'` ("Invalid e-mail address or password."; see [chapter 12](12-translation.md)) |

**Security behavior:**

- A wrong e-mail address and a wrong password produce the same message, and
  nearly the same response time (a password hash runs even for a non-existent
  account). So from the outside it cannot be told whether an account exists.
- Every attempt is counted by the `Throttle` before the password is checked
  (atomically, so parallel requests cannot slip through): by default 5 per
  e-mail address and IP address pair, 20 per IP address, and 30 per account
  from any address (`max_attempts_per_account`, since 0.1.0; an unknown
  address has its own counter, so a locked account cannot be told from a
  non-existent one), within 15 minutes. An IPv6 address counts by its /64
  network, an IPv4-mapped one as plain IPv4 (`static clientKey(string $ip)`).
  On success the pair's and the account's counters are cleared, the address's
  is not (see [the accepted risks](../security.md#known-and-accepted-decided-2026-10-08)).
- A blocked account cannot log in even with the correct password
  (key `auth.account_blocked`, "The account is blocked."), and its existing
  session ends on the next request.
- **A changed password ends the user's other sessions** (since 0.1.0): the
  session holds a stamp of the password hash (`SESSION_STAMP`, a hash of the
  hash, not the hash itself), checked on every request. A session from before
  0.1.0 gets its stamp on its next request.
- **A login lasts at most 12 hours** (since 0.1.4), however actively it is
  used: the session holds the time of logging in (`SESSION_LOGIN_AT`), and
  after `session.absolute_timeout` seconds the user is logged out, with a new
  session ID. Besides that, 2 hours without activity end it
  (`session.idle_timeout`). A stolen session cookie is thus usable for a
  limited time. A session from before 0.1.4 starts counting on its next
  request. Changing one's own password (`refresh()`) does not lengthen it.
- **Every login is recorded and can be ended** (since 0.1.4, see
  [SessionRegistry](#sessionregistry)): on every request the login's token is
  looked up, and a login ended from the profile is logged out (with a new
  session ID) on its next request. Changing one's own password ends the
  others' rows too; a password set by an administrator ends all of them
  (the Kernel listens to `PasswordChanged`).

`LoginResult` (`final readonly class`): `$success`, `$user`, `$error` (a
message key, or a ready-made text), `$errorParams`;
`static success(CampanellaObject $user)`,
`static failure(string $error, array $params = [])`. The `AuthController`
translates the error for display.

## Extension: LoginGuard

`Campanella\Auth\LoginGuard` · **Public** · `interface`

Additional protection that runs **before** the password check: honeypot,
CAPTCHA, IP blocklist, etc.

| Method | Description |
|---|---|
| `check(Request $request): ?string` | An error message key (or text) to reject, `null` to let through |
| `fields(): string` | Additional HTML for the form (or an empty string) |

The list of guards is in the `auth.guards` key of `config/app.php`; they run in
order, and the first rejection stops the login.

```php
'auth' => [
    'guards' => [
        HoneypotGuard::class,
        App\Auth\CaptchaGuard::class,   // custom guard
    ],
],
```

### HoneypotGuard

`Campanella\Auth\Guard\HoneypotGuard` · enabled by default

A `website` field invisible to humans. If a bot fills it in, the request is
rejected with the same message as for a wrong password. `FIELD = 'website'`;
`fields()` returns the HTML for the form, which screen readers and keyboard
navigation skip as well. The field is hidden by an inline style, so it stays
invisible regardless of the theme's CSS; if it still showed up in a custom
template ("Weboldal", "Website"), it must be left empty.

### Two-factor authentication (later)

Checking the password (`attempt()`) and actually logging in (`login()`) are
separate steps. A future two-factor authentication will be a capability of its
own on the user (e.g. holding the TOTP key) and will fit between the two
steps: after a successful password check it will not log in immediately but
ask for the second factor. See the [ROADMAP](../../ROADMAP.md).

## SessionRegistry

`Campanella\Auth\SessionRegistry` · **Public** · since 0.1.4 · container: `SessionRegistry::class`

The logins in progress, one row each in the `sessions` table (schema version
10), so a user can see on their profile where they are logged in, and end any
of them. `AuthService` uses it; it is rarely needed directly.

- A login gets a random token (256 bits) kept in its server-side session
  (`AuthService::SESSION_TOKEN`); the table holds only the token's SHA-256
  hash, **never the session ID**. A row deleted means the login has ended:
  its next request is anonymous.
- Stored for the user's own list: when they logged in, when they were last
  active (written at most once a minute, `TOUCH_INTERVAL`), the last IP
  address and the browser's User-Agent (control characters removed, at most
  255 characters). Nothing is kept after a login ends: logging out, ending it
  from the profile, a changed password or deleting the user deletes its row;
  rows past the idle or the absolute lifetime are deleted at the next login.
- A login from before 0.1.4 is recorded on its next request, with the time it
  logged in.
- Before the upgrade that creates the table every method does nothing (lookups
  answer "unknown"), so one can log in and run the upgrade.

| Member | Description |
|---|---|
| `__construct(Connection $db, int $idleTimeout = 7200, int $absoluteTimeout = 43200)` | The Kernel passes `session.idle_timeout` and `session.absolute_timeout` |
| `isAvailable(): bool` | Whether the table exists |
| `start(int $userId, Request $request, ?int $loggedInAt = null): ?string` | Records a login; returns its token (null before the upgrade). Deletes the expired rows |
| `touch(string $token, int $userId, Request $request): ?bool` | A request of the login: true if it is recorded for the user (updates its last activity and IP address), false if it was ended, null if unknown |
| `end(string $token): void` | Ends the login with the token |
| `endById(int $userId, int $id): bool` | Ends one of the user's logins by its ID |
| `endAll(int $userId, ?string $except = null): int` | Ends the user's logins, except the one with the token |
| `forUser(int $userId, ?string $current = null): list<SessionInfo>` | The user's logins in progress, the most recently active first; the one with `$current` token is marked |
| `cleanup(): int` | Deletes the rows past the idle or the absolute lifetime |

`Campanella\Auth\SessionInfo` · **Public** · `final readonly class`: `id`,
`createdAt`, `lastSeenAt` (UTC `DateTimeImmutable`), `ip`, `userAgent`,
`current` (the request's own login); `browser(): string` and `system(): string`
(e.g. `Firefox`, `Windows`; `''` if not recognized), also as
`static browserOf(string $userAgent)` and `static systemOf(string $userAgent)`.
The User-Agent is whatever the browser sent: the names are a guess for
people, never a basis for a decision.

## Session

`Campanella\Http\Session` · **Public** · container: `Session::class`

A lazily started session with an idle timeout.

| Method | Description |
|---|---|
| `__construct(SessionStorage $storage, int $idleTimeout = 7200)` | |
| `resume(Request $request): bool` | Resumes only if the request carried a session cookie; ends an expired one |
| `start(Request $request): void` | Starts it for writing (login, CSRF token) |
| `isStarted(): bool` | |
| `get(string $key, mixed $default = null)`, `set(string $key, mixed $value)`, `remove(string $key)` | `set()` throws `LogicException` on a session that has not been started |
| `regenerate(): void`, `destroy(): void` | New ID, or deletion together with the cookie |

Anonymous visitors get no cookie. A session starts only on the login page
and the forgotten password's pages (because of the CSRF token, and the
link's token, since 0.1.4) and after login. If the request has a session, the
`Kernel` adds a `Cache-Control: private, no-store` header to the response so
that intermediate caches do not store the personalized page.

### SessionStorage

`Campanella\Http\SessionStorage` · **Public** · `interface`: `exists()`,
`start()`, `isStarted()`, `get()`, `set()`, `remove()`, `regenerate()`, `destroy()`.

| Implementation | Use |
|---|---|
| `NativeSessionStorage(string $name = 'campanella_session', bool\|string $secure = 'auto', int $lifetime = 7200)` | Production: PHP's session handling. HttpOnly and SameSite=Lax cookie, strict mode, Secure cookie over HTTPS (`'auto'`); for an installation in a subdirectory the cookie path is that subdirectory |
| `ArraySessionStorage` | For tests: in memory. `generation()` is the regeneration counter, `endRequest()` simulates the end of a request |

## CSRF

`Campanella\Security\Csrf` · **Public** · container: `Csrf::class`

| Method | Description |
|---|---|
| `token(Request $request): string` | The session's token (64 hexadecimal characters); starts a session if needed |
| `isValid(Request $request): bool` | Whether the POST `_csrf` field matches (`hash_equals`) |
| `rotate(): void` | New token; runs automatically on login |

In templates, in every POST form: `{{ csrf_field() }}`. The field name is the
`FIELD` constant (`_csrf`).

## Throttle

`Campanella\Security\Throttle` · **Public** · container: `Throttle::class`

Attempt throttling per key, with a time window, in the `cc_throttle` table.
Only the SHA-256 hash of the key is stored.

| Method | Description |
|---|---|
| `tooManyAttempts(string $key, int $maxAttempts): bool` | |
| `hit(string $key, int $decaySeconds): int` | Records an attempt; returns the count |
| `availableIn(string $key): int` | Seconds until another attempt is allowed |
| `clear(string $key): void` | |

Other features (e.g. a future contact form) can use it too.

`Campanella\Security\FileThrottle` · **Public** · since 0.1.0: the same
methods (`tooManyAttempts()`, `hit()`, `availableIn()`, `clear()`), in a JSON
file (`__construct(string $file)`), locked while it is written: for where the
database cannot be used yet (the installer). Only hashes of the keys are
stored; if the file cannot be written, attempts are not limited.

## Web interface

`Campanella\Controller\AuthController` · handler: `auth`

| Route | Description |
|---|---|
| `GET /login` | Login form (`page/login.html.twig`). Redirects a logged-in user |
| `POST /login` | Login. Success: 303 redirect to the page given in the `return` field (or the front page). Failure: the form again, with 422 (with 400 for an expired CSRF token) |
| `POST /logout` | Logout (with CSRF token), then a redirect to the front page. A GET request does not log out |
| `GET /password-reset`, `POST /password-reset` | The forgotten password (since 0.1.4): see [below](#the-forgotten-password) |

**The paths are settings** (since 0.1.4; until then `/belepes` and `/kilepes`
with a `vissza` field). English by default, any can be changed, e.g. to the
site's language:

```php
// config/local.php
'paths' => ['login' => '/belepes', 'logout' => '/kilepes', 'password_reset' => '/elfelejtett-jelszo'],
```

`Campanella\Http\SitePaths` · **Public** · container: `SitePaths::class`

| Member | |
|---|---|
| `__construct(array $paths = [])` | The `paths` setting over `DEFAULTS` (`login` => `/login`, `logout` => `/logout`, `password_reset` => `/password-reset`). `InvalidArgumentException` for an unknown name, an unusable path (`/`, spaces, `?`, `#`, backslashes) or two names with one path |
| `get(string $name): string` | A page's path |
| `all(): array` | name => path |
| `login(string $return = ''): string` | The login page that sends back afterwards: `/login?return=%2Fadmin` |

Templates use `path('login')`, `path('logout')`, `path('password_reset')` (with the installation's
folder). The Kernel adds the routes; `robots.txt` lists them as not to crawl.
A configured path is not a security measure in itself (the login is protected
by its throttling), but keeps the noise of bots trying `/login` away.

`static safeTarget(string $target): string`: the redirect target can only be a
path within the site (`/…`, but not `//…`). Anything else is replaced with
`'/'`, so the login cannot be used to redirect to a foreign site.

In templates: `{{ current_user() }}` is the logged-in user (or `null`),
`{{ csrf_field() }}` is the hidden token field.

## The forgotten password

*Since 0.1.4.* `Campanella\Auth\PasswordReset` · **Public** · container: `PasswordReset::class`

A link by e-mail with which a user sets a new password. Offered (the login
page's *Forgot your password?* link; otherwise `/password-reset` answers 404)
only while e-mail is set up ([chapter 21](21-events-and-mail.md#settings)) and
the site's address is set ([chapter 20](20-site.md#the-sites-settings-sitesettings)):
the link is made from that address, **never from the request's `Host`
header**, which anyone can send (an attacker could otherwise have the link
point to their own site).

| Step | |
|---|---|
| `GET /password-reset` | The form: an e-mail address (with the login guards' fields, e.g. the honeypot) |
| `POST /password-reset` | 303 to `/password-reset?sent=1`: *if this address belongs to an account, the link has been sent*, **the same answer for every address** (registered, unknown, blocked). An invalid address: 422; too many requests: 429. A rejected guard (a bot): the usual answer, nothing sent |
| the e-mail | `mail/password_reset.txt.twig`: the link `https://example.hu/password-reset?token=…`, valid for 60 minutes |
| `GET /password-reset?token=…` | The token moves into the session (with a new session ID, so a session ID planted by someone else cannot see it), and a 303 to `/password-reset`: it does not stay in the address bar, and later requests (their `Referer`, the web server's log) do not carry it. The link's own address may still be in the browser's history and the web server's log: it works once, and only for a short time |
| `GET /password-reset` | The new password's form (twice); a link no longer valid: 410, with *Request a new link* (`?again=1`) |
| `POST /password-reset` | The new password: 303 to the login page (`?reset=1`: *Your new password is set*). A password breaking the rules: 422, the link still works |

**Security:**

- The **same answer and the same response time** for every address: the
  account is looked up, the link made and the e-mail sent **after the
  response** ([Deferred](09-system.md#deferred)).
- The token is 96 hexadecimal characters: a **selector** (128 bits) that
  finds the row, and a **verifier** (256 bits) kept only as an HMAC-SHA-256
  hash (`password_resets` table, schema version 11), compared in constant
  time. Who reads the table cannot use it.
- The hash also covers the account's **e-mail address and password hash**:
  a link stops working when either changes, however it changes (the profile,
  an administrator, the command line, custom code saving the user).
- Valid for `auth.password_reset_minutes` (60) minutes; **once**: it is used
  up in a short locked transaction before the password is set, so two
  parallel requests cannot both use it, and nothing slow runs under the lock.
  A newer link replaces the older.
- **Limits** (`Throttle`): 5 requests per IP address (IPv6: /64) in 15
  minutes, 3 per e-mail address in an hour, whether it is registered or not.
  Wrong links are not limited: guessing one is hopeless (2^384), and a limit
  per address would let others behind a shared address block a valid link.
- A **blocked account** gets no link, and blocking voids its link for good
  (also from the command line).
- The new password **ends every session** of the user, everywhere (the
  stamp, and the [session list](#sessionregistry), directly); they log in
  with it. It dispatches `PasswordChanged` with `byReset` (no e-mail by
  default; see [MailUser](21-events-and-mail.md#actions)).

Why 60 minutes: e-mail is often slow (greylisting delays the first message
from a sender by 5–15 minutes), and the link is single-use, 256 bits strong
and stored only as a hash, so a longer window adds little risk (Laravel's
default is 60 minutes too). A shorter one is a setting.

| Member | |
|---|---|
| `isAvailable(): bool` | E-mail set up, the site's address set, the table exists |
| `minutes(): int` | How long a link is valid |
| `request(Request $request, string $email): ?Message` | Counts the request; then, after the response, e-mails a link if the address belongs to an active account. Returns an error that does not depend on the account (`auth.reset.invalid_email`, `auth.reset.too_many`), or null |
| `issue(CampanellaObject $user): string` | Makes a link for the user (the previous one stops working); returns its token. Deletes the expired links |
| `verify(string $token): ?CampanellaObject` | The link's user, or null (wrong, expired, used, voided, inactive account) |
| `complete(string $token, string $password): ?CampanellaObject` | Checks the password's rules (`ValidationException` on `password`: the link stays usable), uses up the link, sets the new password (`UserService::resetPassword()`) and ends the user's sessions; null if the link is not usable |
| `forget(int $userId): void` | Voids the user's link (the Kernel calls it on every `PasswordChanged`) |
| `static forgetIn(Connection $db, int $userId): void` | The same where the service is not at hand (`UserService` on blocking, the command line) |

Constants: `DEFAULT_MINUTES` (60), `MAX_PER_IP` (5), `IP_DECAY_SECONDS` (900),
`MAX_PER_ADDRESS` (3), `ADDRESS_DECAY_SECONDS` (3600). `AuthController::RESET_TOKEN`: the
session key of the token between the link and the new password.

Template: `page/password_reset.html.twig`, with `step` (`request`, `sent`,
`new`, `invalid`).

## In the admin

*Since 0.1.0.* Users are managed in the browser too
([chapter 13](13-admin.md#users-and-the-profile)): administrators list, create
and edit users (name, e-mail address, roles, status) and set new passwords;
everyone logged in has a profile page with their own name and password.
A forgotten password by e-mail comes later (it needs e-mail sending).

### UserService

`Campanella\Service\UserService` · **Public** · container: `UserService::class`

The rules of managing users; the admin pages only read the forms. Since 0.1.3
it dispatches `UserCreated` and `PasswordChanged` after saving (constructor's
last argument: `?EventDispatcher $events = null`; [chapter 21](21-events-and-mail.md#events)).

| Method | Description |
|---|---|
| `canManage(Actor $actor): bool` | Whether the AccessPolicy lets the actor create and update users (by default: administrators) |
| `assignableRoles(?CampanellaObject $user = null): list<string>` | The roles offered: `administrator`, the `admin.roles` and `admin.system_roles` settings, and the user's own roles (so an unknown one is not lost) |
| `all(Actor $actor): list<CampanellaObject>`, `find(Actor $actor, int $id): ?CampanellaObject` | |
| `create(Actor $actor, string $name, string $email, string $password, array $roles): CampanellaObject` | |
| `update(Actor $actor, CampanellaObject $user, string $name, string $email, array $roles, bool $active): void` | |
| `setPassword(Actor $actor, CampanellaObject $user, string $password): void` | An administrator sets someone else's password; their sessions end. One's own: only `changeOwnPassword()` (`users.own_password_profile`) |
| `resetPassword(CampanellaObject $user, string $password): void` | A new password with a forgotten password's link (`PasswordReset`; since 0.1.4); every session of the user ends |
| `updateProfile(CampanellaObject $user, string $name): void` | One's own name |
| `changeOwnPassword(CampanellaObject $user, string $current, string $new): void` | With the current password; wrong ones are limited (5 in 15 minutes, `Throttle`) |
| `activeAdministrators(int $except = 0): int` | |
| `static nameOf(CampanellaObject $user): string` | The name, or the e-mail address |

Rules (`ValidationException`, `AccessDeniedException`):

- **The last active administrator** can neither be blocked nor lose the role
  (`users.last_admin`): the site always has someone who can manage it.
- **Nobody can block themselves** (`users.self_block`).
- Only the offered roles can be given (`validation.invalid_role`); the e-mail
  address is unique (`validation.taken`); the password rules are
  `Authenticatable`'s.
- Users are not deleted, only blocked: their content keeps its author.

## Command line

| Command | Description |
|---|---|
| `user:create <e-mail> [--name="Name"] [--role=administrator,editor]` | New user. The command prompts for the password, hidden, twice |
| `user:password <e-mail>` | New password |
| `user:password <e-mail> --block` / `--activate` | Blocks or re-enables the account |
| `user:list` | List of users; `--role=editor`: only those with the role (since 0.0.6) |

The system never creates a default account or password; the first
administrator must be created with this:

```bash
php bin/campanella user:create you@example.com --name="Your Name" --role=administrator
```

If the input is not a terminal (e.g. from a script: `echo "password" | php bin/campanella user:create …`),
the command reads the password once.

Helper classes: `Campanella\Cli\Input` (`ask()`, `secret()`, `isInteractive()`),
`Campanella\Cli\Args` (`static parse(array $args)`, `argument()`, `option()`,
`flag()`), `Campanella\Cli\PasswordPrompt` (`static ask(Input, Output): ?string`).
The command classes (`UserCreateCommand`, `UserPasswordCommand`, `UserListCommand`)
are **internal**.

## Configuration

| Key | Default | Description |
|---|---|---|
| `session.name` | `'campanella_session'` | The cookie name |
| `session.idle_timeout` | `7200` | Idle timeout in seconds |
| `session.absolute_timeout` | `43200` | The longest a login lasts, in seconds, however actively it is used (12 hours; since 0.1.4). `0`: no limit |
| `session.secure` | `'auto'` | `'auto'`: Secure only over HTTPS; `true` / `false`: forced |
| `auth.max_attempts` | `5` | Failed attempts per e-mail address and IP address pair |
| `auth.max_attempts_per_ip` | `20` | Failed attempts per IP address |
| `auth.decay_seconds` | `900` | The time window |
| `auth.password_reset_minutes` | `60` | How long a forgotten password's link is valid (since 0.1.4) |
| `auth.guards` | `[HoneypotGuard::class]` | LoginGuard classes |

**Behind a proxy:** HTTPS detection relies on the `HTTPS` server variable. If
the web host serves PHP over HTTP behind a reverse proxy, set
`session.secure` to `true`; otherwise the cookie is sent without the Secure flag.
`Request::$ip` is `REMOTE_ADDR`; behind a proxy this may be the proxy's address,
in which case the IP-based throttling applies to everyone collectively.
