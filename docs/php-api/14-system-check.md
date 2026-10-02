# 14. System check

Whether the server meets Campanella's requirements: versions, PHP
extensions, writable folders, settings, limits and caches (since 0.0.5). The
same checks are shown in two places:

- the admin's **System** page (`/admin/system`), for the roles in
  `admin.system_roles` (by default only `administrator`;
  [chapter 13](13-admin.md#the-system-page));
- `php bin/campanella status`, which exits with `1` if a check reports an
  error, so it can also be used in scripts before or after an upgrade.

The results describe the server, so they are shown to administrators only, and
they never contain secrets: no database password, environment variables or
session data. An unreachable database is reported without its connection
details (those are in the server's error log).

## What is checked

| Group | Checks |
|---|---|
| Versions | Campanella; PHP (at least `SystemCheck::MIN_PHP`, 8.3.0); the database server (MariaDB 10.6+, MySQL 8.0+: `MIN_DATABASE`); the database schema against `Version::SCHEMA` (if they differ: run `install`) |
| Required PHP extensions | `REQUIRED_EXTENSIONS`: `ctype`, `dom` (the HTML filter), `json`, `mbstring`, `pdo`, `pdo_mysql`, `session`; missing: error |
| Recommended PHP extensions | `RECOMMENDED_EXTENSIONS`: `gd` (image processing, 0.0.5), `fileinfo` (recognising uploaded files), `opcache` (speed); missing: warning |
| Writable folders | `var/cache` |
| Settings | Debug mode (on: warning), language, time zone, theme, admin path; on the web also whether the server sees HTTPS (if not: warning, the login cookie is not marked `Secure`) |
| PHP limits (web only) | `upload_max_filesize`, `post_max_size` (smaller than the upload limit: warning), `memory_limit`, `max_execution_time` |
| Caches | opcache (web only; if it does not check file times: warning, PHP must be restarted after an upgrade); the template cache (files and size; not writable: warning) |

The web-only checks are left out on the command line, because the
command-line PHP often has different settings than the web server's (e.g. no
opcache, other limits).

## SystemCheck

`Campanella\System\SystemCheck` · **Public** · `final class` · container: `SystemCheck::class`

| Member | Description |
|---|---|
| `__construct(Config $config, Connection $db, Installer $installer, TemplateCache $templates, string $rootDir)` | |
| `run(?Request $request = null): list<CheckResult>` | Every check. With a request (on the web) also the web-only ones |
| `add(Closure $check): void` | Adds a check: `fn (?Request $request): iterable<CheckResult>` (the request is null on the command line) |
| `static worst(array $results): ?CheckStatus` | `Error` if any result is an error, else `Warning` if any is a warning, else `Ok`/`Info`; null for no results |
| `static parseDatabaseVersion(string $version): ?array` | `SELECT VERSION()` text → `[product, version]`, e.g. `'10.11.6-MariaDB-0+deb12u1'` → `['MariaDB', '10.11.6']` |
| `MIN_PHP`, `MIN_DATABASE`, `REQUIRED_EXTENSIONS`, `RECOMMENDED_EXTENSIONS` | The requirements (see above) |

A feature can add its own check, e.g. for a folder it writes:

```php
use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\System\CheckResult;
use Campanella\System\CheckStatus;
use Campanella\System\SystemCheck;

$kernel->container()->get(SystemCheck::class)->add(static fn (?Request $request): array => [
    is_writable($exportDir)
        ? new CheckResult('admin.system.group.folders', 'var/export', CheckStatus::Ok)
        : new CheckResult('admin.system.group.folders', 'var/export', CheckStatus::Error, '', new Message('export.not_writable')),
]);
```

## CheckResult and CheckStatus

`Campanella\System\CheckResult` · **Public** · `final readonly class`

| Property | Description |
|---|---|
| `string $group` | The group, a message key (`admin.system.group.versions`); results of the same group are shown together |
| `string $label` | What was checked: a message key, or a plain name (`pdo_mysql`) |
| `CheckStatus $status` | The verdict |
| `string $value` | The value found: a message key (`admin.system.on`) or a plain value (a version, a size) |
| `?Message $hint` | An explanation or what to do (e.g. "Run: php bin/campanella install") |

Groups, labels and values go through the translator, so a plain text passes
through unchanged ([chapter 12](12-translation.md#translator)).

`Campanella\System\CheckStatus` · **Public** · `enum`: `Ok`, `Info` (only a
value, no verdict), `Warning` (works, but should be looked at), `Error` (a
requirement is not met).

## TemplateCache

`Campanella\System\TemplateCache` · **Public** · `final class` · container: `TemplateCache::class`

The folder of the compiled Twig templates: `var/cache/twig/<version>`. A
folder per Campanella version, because upload tools (ZIP extraction, many FTP
clients) often keep the files' original modification times: an uploaded
template can then look older than its compiled copy, and Twig's
`auto_reload` would keep serving the old one. A new version therefore starts
with an empty folder; within a version, a changed template is still noticed
by `auto_reload`.

| Member | Description |
|---|---|
| `__construct(string $baseDir, string $version)` | `InvalidArgumentException` for a version with characters other than letters, digits, `.`, `+`, `-` |
| `directory(): string` | The folder of the current version |
| `twigCache(): string\|false` | The folder for Twig's `cache` option, created if needed; `false` if not writable (Twig then runs without a cache: slower, but it works) |
| `usage(): array{files: int, bytes: int}` | The compiled templates of every version |
| `clear(): int` | Deletes the compiled templates of every version (older versions' folders too); returns the number of files deleted. They are compiled again on their next use |

On the System page: the **Clear the template cache** button (POST with a CSRF
token).
