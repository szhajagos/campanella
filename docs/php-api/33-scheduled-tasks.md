# 33. Scheduled tasks and fetching from the web

*Since 0.4.9.* Two things of the core that a module builds on (the feeds module
does both): **work the site does by itself, on a schedule**, and **fetching a
file from a web address** without letting the address reach what only the
server can.

## Scheduled tasks

A **scheduled task** is work the site does by itself: deleting old contact
messages, fetching a feed. It never runs in a visitor's request (it would slow
down their page, and a visitor could start it at will). The server's cron
starts one command every five minutes:

```
*/5 * * * * php /path/to/site/bin/campanella cron:run
```

`cron:run` (`CronRunCommand`) runs the tasks that are due, one after another:

- a task is **due** when its interval has passed since it last started, when it
  has never run, or when an administrator asked for it (*System → Scheduled
  tasks*, “In the next run”: nothing runs in the admin's request either);
- **one at a time**: a lock file (`var/cache/cron.lock`); started while another
  runs, it does nothing;
- in about `Scheduler::BUDGET` (240) seconds: a task gets the time left
  (`run($container, $seconds)`), and what does not fit waits for the next run;
- a task that throws is a failed run, its error logged (`error_log`) and shown
  in the admin; the others go on. Exit code 1 if one failed.
- not while the site needs an upgrade.

### `ScheduledTask`

| Method | |
|---|---|
| `name(): string` | Its unique name: the core's `[a-z_]` (`submissions_prune`), a module's `<module>:<name>` (`Scheduler::NAME_PATTERN`) |
| `label(): string` | A message key (a module's: of its translations) |
| `interval(): int` | Seconds between two runs, at least `Scheduler::MIN_INTERVAL` (300) |
| `pending(Container $container): array` | What its next run would do (a list of `Message`s), shown on *System → Scheduled tasks*: e.g. the sources that are due; `[]` if nothing to name |
| `run(Container $container, float $seconds): ?Message` | Does its work in about that many seconds; its result for the log, or null. An exception is a failed run |

A task is made with `new` (no constructor arguments), so it takes what it needs
from the container in `run()` and `pending()`. The core's own:
`SubmissionsPruneTask` (daily, as `submissions:prune`). A module registers its
own with `ModuleContext::scheduled()` ([chapter 32](32-modules.md)):

```php
final class FetchTask implements \Campanella\Schedule\ScheduledTask
{
    public function name(): string { return 'feeds:fetch'; }
    public function label(): string { return 'feeds.task'; }
    public function interval(): int { return 300; }
    public function pending(Container $container): array { return []; }
    public function run(Container $container, float $seconds): ?Message
    {
        // … at most $seconds
        return new Message('feeds.task_result', ['ok' => 1, 'failed' => 0, 'left' => 0]);
    }
}
```

A task with a name that is taken or not valid, or that runs more often than
every five minutes, is left out (logged).

### `Scheduler`

The tasks (the core's and the modules' that are on), where each stands (the
`scheduled_tasks` table), and their runs' log (`scheduled_runs`, the last
`LOG_SIZE`: 100). When `cron:run` last started and finished is in the `system`
table.

| Method | |
|---|---|
| `tasks(): array` | Every task, by name |
| `states(): array` | Where each stands, by name: `next_due`, `requested`, `last_started`, `last_finished`, `last_status` (`ok`, `failed`, `running`), `last_message` (a `Message`), `failures` (in a row) |
| `isDue(string $name, ?DateTimeImmutable $now = null): bool` | Whether it runs in the next `cron:run` |
| `request(string $name): bool` | Asks for it in the next `cron:run`, whatever its interval |
| `lastCron(): ?array` | `started`, `finished`, how many `ran` and `failed`; null if it never ran |
| `isStale(?DateTimeImmutable $now = null): bool` | Whether `cron:run` has not finished for `STALE_AFTER` (30 minutes): cron does not seem to run, and the admin warns |
| `runs(int $limit = 100): array` | The last runs, newest first |
| `run(Container $container, ?Closure $report = null, int $budget = 240, ?DateTimeImmutable $now = null): ?array` | What `cron:run` does; null if another runs |
| `now()`, `format(DateTimeImmutable $time)` | The time in UTC, and as stored |

### *System → Scheduled tasks*

`CronPages` (`/admin/system/cron`, the system roles; its `handle()` is called
by the admin): when `cron:run` last ran and how it went, a warning when it does
not seem to run, the line for the server's crontab with the site's own path,
each task (where it comes from, how often, its last run and result, when it is
due, what it would do), a button to ask for one in the next run, and the last
100 runs.

## Fetching from a web address: `SafeFetcher`

An address typed in the admin (a feed's, say) is fetched by the server, from
inside its network. Unchecked, it could reach what only the server can: the
site's own database port, an admin interface on the local network, a cloud's
metadata service at `169.254.169.254` with its keys (*server-side request
forgery*). `SafeFetcher` (from the container) fetches only what any visitor's
browser could:

- `https://` only; `http://` too where `fetch.allow_http` says so
  (`config/local.php`). Ports 443 and 80 only; no user name or password in the
  address.
- The host is resolved, and **every** address it has must be public
  (`AddressPolicy::isPublic()`): not loopback, private, link-local, shared
  (carrier-grade NAT), documentation, reserved or multicast ranges, nor an IPv6
  address carrying such an IPv4 one (mapped, NAT64, 6to4).
- The connection is made **to the address checked** (`CurlTransport` gives it
  to curl with `CURLOPT_RESOLVE`), so the name cannot resolve to another one in
  between (DNS rebinding).
- A redirect is followed by `SafeFetcher` itself, at most `MAX_REDIRECTS` (3),
  the new address checked again; never from `https://` to `http://` unless
  `http://` is allowed.
- `fetch.timeout` seconds (10), `fetch.max_bytes` of the body (5 MB) as it is
  decompressed. No cookies, no proxy of the environment, the certificate always
  checked.

| Method | |
|---|---|
| `problem(string $url): ?Message` | What is wrong with the address's form (nothing resolved): for a form's check |
| `fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResult` | The file; with what an earlier fetch gave, the server can answer that nothing changed (`notModified`) |
| `resolveUrl(string $base, string $location): ?string` | A redirect's address made absolute |

`FetchResult`: `url` (after the redirects), `body`, `contentType`, `etag`,
`lastModified`, `notModified`. A refusal or failure is a `FetchException`, its
`reason` a `Message` for the admin (`fetch.private`, `fetch.too_large`,
`fetch.status` …).

```php
$fetcher = $container->get(\Campanella\Net\SafeFetcher::class);
$problem = $fetcher->problem($url);          // in the form
try {
    $file = $fetcher->fetch($url, $etag, $lastModified);
} catch (\Campanella\Net\FetchException $e) {
    $message = $e->reason;
}
```

It needs PHP's curl extension (`CurlTransport::available()`). How the name is
resolved (`Resolver::resolve()`, by default `DnsResolver`) and how the request
is made (`Transport::get()`, by default `CurlTransport`) are given to its
constructor: a test gives its own, without a network.
