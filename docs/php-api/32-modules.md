# 32. Modules

*Since 0.4.6.* A **module** brings new things to a site: capabilities,
Blueprints, displays, templates, translations, later its own tables and pages.
It is a folder in `modules/` (`modules.directory`), put there by hand (FTP, git,
Composer): **a module is never uploaded through the browser**, because it is
program code with every right, and an account taken over must not be able to
add code to the server.

This chapter grows with the steps of 0.5.0. Since 0.4.6 a site finds and checks
its modules, tries one before it is turned on, and loads those that are on; a
module's own tables and pages come with 0.4.7, its own field types with 0.4.8.

## A module's folder

```
modules/newsletter/
  module.json        its description (below)
  src/Module.php     its entry point: the namespace's Module class
  src/…              its classes (PSR-4, under its own namespace)
  templates/         its templates, as @newsletter/…
  templates/layout/newsletter_*.json, .html.twig   its layouts
  templates/scheme/newsletter_*.json               its colour schemes
  lang/hu.php, en.php  its translations: keys starting with "newsletter."
  public/            its files (CSS, JS, images), copied to public/modules/newsletter/
```

```php
namespace Newsletter;

use Campanella\Module\ModuleContext;

final class Module implements \Campanella\Module\Module
{
    public function register(ModuleContext $context): void
    {
        $context->capability(Subscribable::class)
            ->display(SignUpDisplay::class)
            ->tokens(NewsletterTokens::class)
            ->on(\Campanella\Event\ObjectPublished::class, SendLetter::class)
            ->command(new SendCommand());
    }
}
```

### module.json: ModuleManifest

`Campanella\Module\ModuleManifest` · **Public** · `final readonly class`

```json
{
  "name": "newsletter",
  "label": "Newsletter",
  "description": "Subscribing, and the letters sent.",
  "version": "1.0.0",
  "campanella": "^0.4.6",
  "requires": {"other_module": "^1.0"},
  "autoload": {"Newsletter\\": "src/"}
}
```

| Key | |
|---|---|
| `name` | The folder's name: small letters, digits, `_`, starting with a letter, 2–41 characters (`NAME_PATTERN`) |
| `label`, `description` | Shown in the admin as text (escaped): at most 100 and 500 characters; `description` is optional |
| `version` | The module's version (`Semver`, below) |
| `campanella` | The Campanella versions it works with, a constraint: e.g. `^0.4.6` (0.4.6 up to, not with, 0.5.0) |
| `requires` | Optional: the other modules it needs, name => constraint |
| `autoload` | Exactly one namespace of its own (not `Campanella\`) and its folder inside the module; that folder has `Module.php` |

Other keys are left for later versions. At most 64 kB (`MAX_BYTES`).

| Member | |
|---|---|
| `static read(string $path): self\|Message` | The module in that folder (an absolute, real path), or why it cannot be used (a `modules.problem.*` message) |
| `entryClass(): string` | The namespace's `Module` |
| `$name`, `$label`, `$description`, `$version`, `$campanella`, `$requires`, `$namespace`, `$sourceDir`, `$path` | As read; `$sourceDir` and `$path` absolute |

## Versions: Semver

`Campanella\Module\Semver` · **Public** · `final class`

A version is `MAJOR.MINOR.PATCH`, optionally with a pre-release (`1.2.0-beta.1`,
before `1.2.0`). A constraint is alternatives separated by `||`, each conditions
separated by spaces, all of which must hold:

| Constraint | Means |
|---|---|
| `1.2.3`, `=1.2.3` | Exactly that (`1.2`: any 1.2.x) |
| `>=1.2`, `>1.2`, `<=1.2`, `<2.0` | Compared (a missing part is 0) |
| `^1.2.3` | `>=1.2.3 <2.0.0`; below 1.0 the minor counts as major: `^0.4.6` is `>=0.4.6 <0.5.0`, `^0.0.3` is `>=0.0.3 <0.0.4` |
| `~1.2.3` | `>=1.2.3 <1.3.0` (`~1.2` the same as `~1.2.0`) |
| `*` | Any |

A pre-release satisfies a constraint only if a condition names one of the same
`MAJOR.MINOR.PATCH`, so `^0.4.6` does not let `0.5.0-alpha` in.

| Method | |
|---|---|
| `static isVersion(string $version): bool`, `static isConstraint(string $constraint): bool` | Whether it can be read |
| `static compare(string $a, string $b): int` | `-1`, `0`, `1`; `InvalidArgumentException` for a text that is not a version |
| `static satisfies(string $version, string $constraint): bool` | False if either cannot be read |

## The modules of a site: ModuleRegistry and ModuleState

`Campanella\Module\ModuleRegistry` · **Public** · `final class`, a service

Every folder of the modules folder (at most `MAX_MODULES`, 100), read and
checked; nothing of a module is loaded until it is. A module **cannot be used**
(`broken`) if

- its folder's name is not a module's name, or it is a link out of the modules
  folder (its source folder cannot lead out of the module either);
- its `module.json` is missing, too large, not valid, or its name is not the
  folder's;
- it is not for this Campanella version;
- its namespace is another module's too;
- a module it needs is missing, of a version it does not accept, or cannot be
  used; or the modules need each other in a circle.

A module is **on** if the settings say so (`modules.enabled`, by the admin,
since part 2), nothing above is wrong, `config/local.php` does not turn it off,
and every module it needs is on. `modules.disabled` in `config/local.php` always
wins: a module that stops the site is turned off without the admin, and the
modules that need it are not loaded either until it is back (`not_loaded`;
they stay on, and come back with it).

| Member | |
|---|---|
| `__construct(string $directory, string $coreVersion, list<string> $enabled = [], list<string> $disabled = [])` | The Kernel's: the folder of `modules.directory`, `Version::CAMPANELLA`, the setting `modules.enabled`, the configuration's `modules.disabled` |
| `all(): array<string, ModuleState>` | Every module found, by name, in name order |
| `get(string $name): ?ModuleState` | |
| `loadOrder(): list<ModuleManifest>` | Those to load, each after the modules it needs |
| `directory(): string` | |
| `static namesOf(?string $setting): list<string>` | The names in the setting's text (`a,b`) |

`Campanella\Module\ModuleState` · **Public** · `final readonly class`: `$name`,
`$manifest` (null if it could not be read), `$status` (`broken`, `off`, `on`,
`not_loaded`), `$problems` (`list<Message>`, why), `$enabled` (whether the
settings turn it on), `isLoaded(): bool`.

## What a module registers: Module and ModuleContext

`Campanella\Module\Module` · **Public** · `interface`: `register(ModuleContext $context): void`,
the entry point's. It runs on every request while the module is on: it only
registers (no database, no output).

`Campanella\Module\ModuleContext` · **Public** · `final class`. Every class
given must be the module's own (under its namespace) and of the right kind,
else a `ModuleException` (**Public**), and the module is not loaded. Each
method returns the context.

| Method | |
|---|---|
| `capability(string $class)` | A capability (chapter 3) |
| `display(string $class)`, `displaySource(string $class)` | A display (with its `#[AsDisplay]`) and a system source (chapter 28); a display's `templates()` can give its own `@<module>/…` templates |
| `tokens(string $class)` | A `TokenProvider` (chapter 30) |
| `on(string $event, string $action)` | An `Action` of the module on an event (chapter 21) |
| `listen(string $event, Closure $listener)` | A listener on an event |
| `command(Command $command)` | A command of `bin/campanella`; its name starts with `<module>:` |
| `service(string $class, Closure $factory)` | A service of the module's own class, for the container (e.g. a display that needs other services); never over the core's |
| `registered(): array` | Everything registered (for ModuleLoader) |

What comes from the folder by itself: the templates as `@<module>/…` (they
never replace the core's or a theme's: only a theme overrides templates), the
layouts and colour schemes whose names start with `<module>_`, the translations
whose keys start with `<module>.` (the core's keys win), and the files of
`public/` (never a PHP file, a hidden file or a link), copied to
`public/modules/<module>/` when it is turned on (`module_asset()`, chapter 7).
`public/modules/.htaccess` runs nothing there and serves no HTML.

## Loading: ModuleLoader

`Campanella\Module\ModuleLoader` · **Public** · `final class`, a service

Once per request, the modules that are on in ModuleRegistry's order: each one's
namespace given to PHP's autoloader (PSR-4, its source folder), its entry point
made and its `register()` run. **A module that fails** (its entry point is not a
`Module`, `register()` throws, a class is not its own) is left out whole, its
error logged and shown on *System → Modules*; the site goes on without it, and
so do the modules that need it. A layout or colour scheme without the module's
prefix leaves them all out; a translation key without it leaves its
translations out. The Kernel gives the registries what the modules brought;
if one is not valid there all the same (e.g. a capability's name the core
has), the registry is made without the modules' and the error is logged.

| Member | |
|---|---|
| `__construct(ModuleRegistry $registry, bool $load = true)` | `false` (`modules.load`): nothing loaded, for the command line's `module:*` |
| `loaded(): array` | Each loaded module's registrations, by name |
| `failures(): array<string, Message>` | Those on but not loaded in this request, and why |
| `all(string $kind): list<mixed>` | One kind of registration (`capabilities`, `displays`, `sources`, `tokens`, `listeners`, `commands`) of them all |
| `actions(): array`, `translations(array $core): array`, `templateDirs(): array`, `layoutDirs(): list<string>` | For the Kernel |
| `registry(): ModuleRegistry` | |
| `static folderProblems(ModuleManifest $manifest): list<Message>` | Its layouts' and schemes' names, its translations' files and keys |

## Before it is turned on: ModuleTrial

`Campanella\Module\ModuleTrial` · **Public** · `final class`, a service

`run(string $name): array{problems: list<Message>, brings: array<string, list<string>>}`.
A module is turned on only if this finds nothing wrong:

1. it is there, not broken, every module it needs on;
2. every PHP file of its folder can be read by PHP (`MAX_FILES`, 5000);
3. its folder (`ModuleLoader::folderProblems()`);
4. a site of its own is made with it on (a `Kernel` with the others that are on
   too): it is loaded, what it registered is checked as the registries check it,
   all together (a name used twice, a display not valid, a command twice), its
   templates compiled, its layouts and colour schemes read;
5. that site's front page, search and a page that is not there are made; none may
   answer 500.

A PHP warning or notice on the way is a problem too. `brings` says what it
brings (its capabilities, displays, commands, tokens, events, layouts, schemes,
templates, translations, files). The trial saves nothing; a fatal error in it
(e.g. a class missing a method) ends that one request, and the module stays off.

## Turning on and off: ModuleManager

`Campanella\Module\ModuleManager` · **Public** · `final class`, a service

| Method | |
|---|---|
| `enable(string $name): list<Message>` | The trial, then its files copied (a fresh copy beside the old one until complete), then the setting `modules.enabled`; the problems, or `[]` |
| `disable(string $name): ?Message` | Not while a module that is on needs it (`modules.needed_by`); out of the setting, its copied files removed; its data stays |
| `static publicFiles(ModuleManifest $manifest): list<string>` | Its `public/` files that are copied |

## In the admin: ModulePages

`Campanella\Admin\ModulePages` · **Internal**, for the system roles

| Path | |
|---|---|
| `/admin/system/modules` | *System → Modules*: every module, its version, the Campanella versions and the modules it needs, where it stands and why, a module that failed to load; *Turn on…*, *Turn off* |
| `/admin/system/modules/<name>/enable` | The trial's result, what it brings and the warning that a module is code with every right; POST (CSRF) tries it again and turns it on (422 with the problems) |
| `POST /admin/system/modules/<name>/disable` | Turns it off (CSRF) |

On the command line (`ModuleListCommand`, `ModuleEnableCommand`,
`ModuleDisableCommand`, **Internal**): `module:list` (exit code 1 if a module is
broken), `module:enable <name>` (the trial, then on; 1 with the problems),
`module:disable <name>`. The `module:*` commands load no module, so a module
that stops the site cannot stop them; the modules' own commands are listed by
`bin/campanella` too.
