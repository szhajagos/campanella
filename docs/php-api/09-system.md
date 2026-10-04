# 9. System

## Kernel

`Campanella\Core\Kernel` · **Public** · `final class`

Assembles the system and serves a request.

| Method | Description |
|---|---|
| `__construct(string $rootDir)` | The project root |
| `rootDir(): string` | |
| `container(): Container` | The service container (built on first call) |
| `handle(Request $request): Response` | Routing, controller, error handling |

Error handling in `handle()`:

| Exception | Response |
|---|---|
| `HttpException` | Error page with the exception's status |
| Database error, and the system is not installed yet | 503, with installation instructions |
| Database error, and the schema is older than the code | 503, telling you to run the `install` command |
| Anything else | 500; in debug mode with the exception message. Details go to the PHP error log |

Custom entry point (e.g. for a test):

```php
$kernel = new Kernel('/path/to/project');
$response = $kernel->handle(new Request('GET', '/hirek'));
```

## Container

`Campanella\Core\Container` · **Public** · `final class`

A minimal service container without "magic" autowiring: services are created
by explicit factory functions in the `Kernel`, on first request, once.

| Method | Description |
|---|---|
| `set(string $id, Closure $factory): void` | Factory function: `fn (Container $c) => …`. Overrides any previous one |
| `instance(string $id, mixed $service): void` | A ready-made instance |
| `get(string $id): mixed` | `OutOfBoundsException` if unknown |
| `has(string $id): bool` | |

### Service IDs

| ID | Service |
|---|---|
| `Config::class` | Configuration |
| `Connection::class` | Database connection |
| `CapabilityRegistry::class`, `BlueprintRegistry::class` | Registries |
| `ObjectRepository::class` | Storage |
| `AccessPolicy::class` | Access control policy (`DefaultPolicy` by default) |
| `QueryEngine::class` | Queries |
| `RelationLoader::class` | Relation loading |
| `Session::class`, `Csrf::class`, `Throttle::class`, `AuthService::class` | Session, CSRF, throttling, login |
| `ObjectService::class` | Operations |
| `Installer::class` | Installer |
| `Twig\Environment::class`, `Presentation::class` | Rendering |
| `Router::class` | Routing |
| `HtmlSanitizer::class` | The HTML filter ([chapter 15](15-html.md)) |
| `MediaService::class`, `ImageProcessor::class`, `MediaStorage::class` | Uploaded images ([chapter 16](16-media.md)) |
| `TemplateCache::class`, `SystemCheck::class` | The template cache folder, the system check ([chapter 14](14-system-check.md)) |
| `controller.object`, `controller.query`, `controller.auth`, `controller.admin` | Controllers; following the `controller.<handler>` pattern |

Replacing a service, for example with a custom access control policy:

```php
$kernel->container()->set(AccessPolicy::class, static fn (): AccessPolicy => new ModeratorPolicy());
```

New controller: a `controller.<name>` entry in the container, and a route in
`config/routes.php` whose handler is `<name>`.

## Config

`Campanella\Core\Config` · **Public** · `final class` · container: `Config::class`

| Method | Description |
|---|---|
| `__construct(array $values)` | |
| `static load(string $configDir): self` | `config/app.php` and, if it exists, `config/local.php` (which overrides it) |
| `get(string $key, mixed $default = null): mixed` | Dotted key: `get('database.host')` |
| `all(): array` | |

### Configuration files

| File | Contents |
|---|---|
| `config/app.php` | Default settings, capability list |
| `config/local.php` | Machine-specific settings; not under version control. Template: `local.php.dist` |
| `config/blueprints.php` | Blueprints |
| `config/html.php` | The HTML allowlist ([chapter 15](15-html.md#configuration-confightmlphp)) |
| `config/routes.php` | Static routes |
| `config/queries.php` | Named queries |

### Keys (`config/app.php`)

| Key | Default | Environment variable |
|---|---|---|
| `debug` | `false` | `CAMPANELLA_DEBUG` |
| `timezone` | `'Europe/Budapest'` | |
| `locale` | `'hu'` | `CAMPANELLA_LOCALE` ([chapter 12](12-translation.md#choosing-the-language)) |
| `theme` | `''` (no theme) | `CAMPANELLA_THEME` ([chapter 7](07-http-and-view.md#theme)) |
| `admin.path`, `admin.roles` | `'/admin'`, `['administrator', 'editor']` | ([chapter 13](13-admin.md#access)) |
| `media.*` | see [chapter 16](16-media.md#settings-configappphp-media) | Uploaded images |
| `admin.system_roles` | `['administrator']` | Who may open the System page ([chapter 14](14-system-check.md)) |
| `site.name`, `site.slogan` | `'Campanella'`, … | |
| `database.host` | `'localhost'` | `CAMPANELLA_DB_HOST` |
| `database.port` | `3306` | `CAMPANELLA_DB_PORT` |
| `database.name` | `'campanella'` | `CAMPANELLA_DB_NAME` |
| `database.user` | `'campanella'` | `CAMPANELLA_DB_USER` |
| `database.password` | `''` | `CAMPANELLA_DB_PASSWORD` |
| `database.prefix` | `'cc_'` | `CAMPANELLA_DB_PREFIX` |
| `capabilities` | the seven built-in ones | |
| `session.*`, `auth.*` | see [chapter 11](11-users.md#configuration) | |

Precedence: the `app.php` default < environment variable < `local.php`.

`public/index.php` also honors the `CAMPANELLA_ROOT` environment variable: if
set, it is the project root (by default the parent directory of `public/`).

## Version

`Campanella\Core\Version` · **Public**

`CAMPANELLA = '0.0.4'` (the system version) and `SCHEMA = '5'` (the database
schema version; incremented for a new table or migration).

## CLI

`php bin/campanella <command>`

| Command | Description |
|---|---|
| `install` | Creates the tables; `--sql`: only prints the SQL |
| `seed` | Sample content. Can be run repeatedly: whatever already exists by path is not created again |
| `status` | Version, the system check ([chapter 14](14-system-check.md)), capabilities, Blueprints, object count; reports if the database needs an upgrade. Exits with `1` if a check reports an error |
| `html:sanitize` | Filters the stored HTML texts with the allowlist; `--dry-run`: only lists them ([chapter 15](15-html.md#texts-stored-earlier-htmlsanitize)) |
| `user:create`, `user:password`, `user:list` | User management ([chapter 11](11-users.md#command-line)) |
| `help` | Command list |

### Custom command

`Campanella\Cli\Command` · **Public** · `interface`

```php
final class HelloCommand implements Command
{
    public function name(): string { return 'hello'; }

    public function description(): string { return 'cli.hello.description'; }   // a message key

    /** @param list<string> $args */
    public function run(Container $container, array $args, Output $output): int
    {
        $output->success('Hello, ' . ($args[0] ?? 'world') . '!');

        return 0;   // exit code
    }
}
```

`description()` returns a message key, which the help screen translates. For
other output, take the `Translator` from the container
(`$container->get(Translator::class)->translate('cli.…', [...])`); the
command-line texts are under `cli.` in `lang/` (since 0.0.4). A
`ValidationException` that a command does not catch is printed by the
`Console`, translated, one line per field.

In 0.0.1 the list of commands lives in the `Console` constructor; with the
module system it will become registrable.

| Class | Description |
|---|---|
| `Console` · **Internal** | `__construct(Kernel $kernel, Output $output = new Output())`, `run(array $argv): int` |
| `Output` · **Public** | `__construct($stream = STDOUT, $errors = STDERR)`; `line(string $text = '')`, `success(string $text)` (with a ✔ mark), `error(string $text)` (with a ✘ mark, to the error stream) |
| `InstallCommand`, `SeedCommand`, `StatusCommand`, `HtmlSanitizeCommand` · **Internal** | The built-in commands |

## Helper classes

`Campanella\Support` · **Public**

| Method | Description |
|---|---|
| `Slugger::slugify(string $text): string` | URL-friendly text with its own transliteration table: `'Árvíztűrő tükörfúrógép'` → `'arvizturo-tukorfurogep'`. Returns `'n-a'` instead of an empty result |
| `Uuid::v7(): string` | Time-ordered UUID (RFC 9562) |
| `Uuid::isValid(string $uuid): bool` | Format check |
