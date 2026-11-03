<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\I18n\Message;

/**
 * The modules of a site (since 0.4.6): the folders of the modules folder (`modules/` by default,
 * `modules.directory`), each read and checked, and where each stands (ModuleState).
 *
 * A module is never uploaded through the browser: it is a folder put there by hand (FTP, git,
 * Composer). Nothing of a module is loaded until it is checked:
 *
 *   - its folder: a name of NAME_PATTERN, not a link out of the modules folder;
 *   - its module.json (ModuleManifest), and the Campanella versions it works with;
 *   - the modules it needs: there, not broken, of a version it accepts, no circle between them;
 *     no two modules with the same namespace.
 *
 * A module is on if the settings say so (`modules.enabled`, by the admin or `module:enable`),
 * nothing above is wrong, config/local.php does not turn it off (`modules.disabled`, which
 * always wins: a module that stops the site can be turned off without the admin), and every
 * module it needs is on. loadOrder() gives those to load, each after the modules it needs.
 */
final class ModuleRegistry
{
    /** The most modules read from the folder. */
    public const int MAX_MODULES = 100;

    /** @var array<string, ModuleState>|null */
    private ?array $states = null;

    /**
     * @param list<string> $enabled The modules turned on (the settings)
     * @param list<string> $disabled The modules config/local.php turns off
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $coreVersion,
        private readonly array $enabled = [],
        private readonly array $disabled = [],
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return array<string, ModuleState> Every module found, by name, in name order */
    public function all(): array
    {
        return $this->states ??= $this->read();
    }

    public function get(string $name): ?ModuleState
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * The modules to load, each after the modules it needs.
     *
     * @return list<ModuleManifest>
     */
    public function loadOrder(): array
    {
        $states = $this->all();
        $order = [];
        $visit = static function (string $name) use (&$visit, &$order, $states): void {
            if (isset($order[$name]) || !($states[$name] ?? null)?->isLoaded()) {
                return;
            }
            $manifest = $states[$name]->manifest;
            \assert($manifest !== null);
            foreach (array_keys($manifest->requires) as $needed) {
                $visit($needed);
            }
            $order[$name] = $manifest;
        };
        foreach (array_keys($states) as $name) {
            $visit($name);
        }

        return array_values($order);
    }

    /**
     * The names of the enabled modules in a setting's text (`a,b`), the known form only.
     *
     * @return list<string>
     */
    public static function namesOf(?string $setting): array
    {
        $names = [];
        foreach (explode(',', (string) $setting) as $name) {
            $name = trim($name);
            if (preg_match(ModuleManifest::NAME_PATTERN, $name) === 1) {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    /** @return array<string, ModuleState> */
    private function read(): array
    {
        $root = realpath($this->directory);
        if ($root === false || !is_dir($root)) {
            return [];
        }
        $manifests = [];
        $problems = [];
        $folders = scandir($root) ?: [];
        sort($folders);
        foreach (array_slice(array_values(array_filter($folders, static fn (string $f): bool => $f[0] !== '.' && is_dir($root . '/' . $f))), 0, self::MAX_MODULES) as $folder) {
            if (preg_match(ModuleManifest::NAME_PATTERN, $folder) !== 1) {
                $problems[$folder] = [new Message('modules.problem.folder')];
                continue;
            }
            $path = realpath($root . '/' . $folder);
            if ($path === false || dirname($path) !== $root) {
                $problems[$folder] = [new Message('modules.problem.link')];
                continue;
            }
            $read = ModuleManifest::read($path);
            if ($read instanceof Message) {
                $problems[$folder] = [$read];
                continue;
            }
            $manifests[$folder] = $read;
            $problems[$folder] = [];
            if (!Semver::satisfies($this->coreVersion, $read->campanella)) {
                $problems[$folder][] = new Message('modules.problem.core_version', ['needs' => $read->campanella, 'version' => $this->coreVersion]);
            }
        }
        // No two with one namespace.
        $owners = [];
        foreach ($manifests as $name => $manifest) {
            $owners[strtolower($manifest->namespace)][] = $name;
        }
        foreach ($owners as $names) {
            if (count($names) > 1) {
                foreach ($names as $name) {
                    $problems[$name][] = new Message('modules.problem.namespace_taken', ['others' => implode(', ', array_diff($names, [$name]))]);
                }
            }
        }
        // The modules they need: there and of a version they accept.
        foreach ($manifests as $name => $manifest) {
            foreach ($manifest->requires as $needed => $constraint) {
                if (!isset($manifests[$needed])) {
                    $problems[$name][] = new Message('modules.problem.requires_missing', ['module' => $needed, 'needs' => $constraint]);
                } elseif (!Semver::satisfies($manifests[$needed]->version, $constraint)) {
                    $problems[$name][] = new Message('modules.problem.requires_version', ['module' => $needed, 'needs' => $constraint, 'version' => $manifests[$needed]->version]);
                }
            }
        }
        // No circle.
        foreach (array_keys($manifests) as $name) {
            if ($this->inCircle($name, $manifests)) {
                $problems[$name][] = new Message('modules.problem.circle');
            }
        }
        // A module whose needed module cannot be used cannot be used either (until nothing changes).
        do {
            $changed = false;
            foreach ($manifests as $name => $manifest) {
                if ($problems[$name] !== []) {
                    continue;
                }
                foreach (array_keys($manifest->requires) as $needed) {
                    if (($problems[$needed] ?? []) !== []) {
                        $problems[$name][] = new Message('modules.problem.requires_broken', ['module' => $needed]);
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        // Where each stands.
        $enabled = array_flip($this->enabled);
        $disabled = array_flip($this->disabled);
        $states = [];
        foreach ($problems as $name => $list) {
            $name = (string) $name;
            $manifest = $manifests[$name] ?? null;
            $on = isset($enabled[$name]);
            $states[$name] = $list !== []
                ? new ModuleState($name, $manifest, ModuleState::BROKEN, $list, $on)
                : new ModuleState($name, $manifest, $on ? ModuleState::ON : ModuleState::OFF, [], $on);
        }
        foreach ($states as $name => $state) {
            if ($state->status === ModuleState::ON && isset($disabled[$name])) {
                $states[$name] = new ModuleState($name, $state->manifest, ModuleState::NOT_LOADED, [new Message('modules.problem.disabled_locally')], true);
            }
        }
        do {
            $changed = false;
            foreach ($states as $name => $state) {
                if ($state->status !== ModuleState::ON || $state->manifest === null) {
                    continue;
                }
                foreach (array_keys($state->manifest->requires) as $needed) {
                    if (!($states[$needed] ?? null)?->isLoaded()) {
                        $states[$name] = new ModuleState($name, $state->manifest, ModuleState::NOT_LOADED, [new Message('modules.problem.requires_off', ['module' => $needed])], true);
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
        ksort($states);

        return $states;
    }

    /** @param array<string, ModuleManifest> $manifests */
    private function inCircle(string $start, array $manifests): bool
    {
        $seen = [];
        $queue = array_keys($manifests[$start]->requires);
        while ($queue !== []) {
            $name = array_shift($queue);
            if ($name === $start) {
                return true;
            }
            if (isset($seen[$name]) || !isset($manifests[$name])) {
                continue;
            }
            $seen[$name] = true;
            array_push($queue, ...array_keys($manifests[$name]->requires));
        }

        return false;
    }
}
