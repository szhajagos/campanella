# 32. Modules

*Since 0.4.6.* A **module** brings new things to a site: capabilities,
Blueprints, displays, templates, translations, later its own tables and pages.
It is a folder in `modules/` (`modules.directory`), put there by hand (FTP, git,
Composer): **a module is never uploaded through the browser**, because it is
program code with every right, and an account taken over must not be able to
add code to the server.

This chapter grows with the steps of 0.5.0. In 0.4.6 (part 1) a site finds and
checks its modules and lists them; turning them on and what a module registers
come with part 2.

## A module's folder

```
modules/newsletter/
  module.json        its description (below)
  src/Module.php     its entry point: the namespace's Module class
  src/…              its classes (PSR-4, under its own namespace)
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

## In the admin: ModulePages

`Campanella\Admin\ModulePages` · **Internal**, for the system roles

| Path | |
|---|---|
| `/admin/system/modules` | *System → Modules*: every module, its version, the Campanella versions and the modules it needs, where it stands and why |

On the command line: `php bin/campanella module:list` (`ModuleListCommand`,
**Internal**), the same; exit code 1 if a module is broken.
