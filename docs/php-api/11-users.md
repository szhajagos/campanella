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
| `account_status` | String | Table | indexed, default: `active` |
| `roles` | String, multi-valued (`Field::UNLIMITED`, 32 characters) | Table (`field_values`) | e.g. `administrator`, `editor`; queryable: `->where('roles', '=', 'editor')`. Since 0.0.6; before, a StringList in a column (the migration `core:0006_roles_multi_value` moves them) |

| Method / constant | Description |
|---|---|
| `MIN_PASSWORD_LENGTH` (10), `MAX_PASSWORD_BYTES` (72) | Password rule. The 72-byte upper limit is a bcrypt limitation |
| `setPassword(string $password): void` | Hashes it (`password_hash`, `PASSWORD_DEFAULT`). On a rule violation: `ValidationException` (with the `password` key) |
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
| `logout(): void` | Destroys the session |
| `currentUser(Request $request): ?CampanellaObject` | The logged-in user. Does not start a session for anonymous visitors. Logs out a blocked or deleted account |
| `currentActor(Request $request): Actor` | The same as an `Actor`; the `Kernel` passes this to controllers |
| `static actorFor(CampanellaObject $user): Actor` | `ActorKind::User`, ID, roles, name |
| `findUserByEmail(string $email): ?CampanellaObject` | |
| `guards(): list<LoginGuard>` | The configured guards |
| `GENERIC_ERROR` | The message key `'auth.invalid_credentials'` ("Invalid e-mail address or password."; see [chapter 12](12-translation.md)) |

**Security behavior:**

- A wrong e-mail address and a wrong password produce the same message, and
  nearly the same response time (a password hash runs even for a non-existent
  account). So from the outside it cannot be told whether an account exists.
- After a failed attempt the `Throttle` counts: by default 5 attempts per
  e-mail address and IP address pair, and 20 attempts per IP address, within
  15 minutes. On success the counter of the e-mail and IP pair is reset.
- A blocked account cannot log in even with the correct password
  (key `auth.account_blocked`, "The account is blocked."), and its existing
  session ends on the next request.

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
(because of the CSRF token) and after login. If the request has a session, the
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
| `GET /belepes` | Login form (`page/login.html.twig`). Redirects a logged-in user |
| `POST /belepes` | Login. Success: 303 redirect to the page given in the `vissza` field (or the front page). Failure: the form again, with 422 (with 400 for an expired CSRF token) |
| `POST /kilepes` | Logout (with CSRF token), then a redirect to the front page. A GET request does not log out |

`static safeTarget(string $target): string`: the redirect target can only be a
path within the site (`/…`, but not `//…`). Anything else is replaced with
`'/'`, so the login cannot be used to redirect to a foreign site.

In templates: `{{ current_user() }}` is the logged-in user (or `null`),
`{{ csrf_field() }}` is the hidden token field.

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
| `session.secure` | `'auto'` | `'auto'`: Secure only over HTTPS; `true` / `false`: forced |
| `auth.max_attempts` | `5` | Failed attempts per e-mail address and IP address pair |
| `auth.max_attempts_per_ip` | `20` | Failed attempts per IP address |
| `auth.decay_seconds` | `900` | The time window |
| `auth.guards` | `[HoneypotGuard::class]` | LoginGuard classes |

**Behind a proxy:** HTTPS detection relies on the `HTTPS` server variable. If
the web host serves PHP over HTTP behind a reverse proxy, set
`session.secure` to `true`; otherwise the cookie is sent without the Secure flag.
`Request::$ip` is `REMOTE_ADDR`; behind a proxy this may be the proxy's address,
in which case the IP-based throttling applies to everyone collectively.
