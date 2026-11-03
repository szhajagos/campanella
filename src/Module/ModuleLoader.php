<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\I18n\Message;

/**
 * Loads the modules that are on (since 0.4.6), once per request: each in ModuleRegistry's
 * order, its namespace given to PHP's autoloader, its entry point made and its register()
 * run with a ModuleContext of its own. A module that fails (its entry point is not a Module,
 * register() throws, or something of its folder is not as it must be) is left out whole, its
 * error logged and kept for the admin (failures()), and the site goes on without it.
 *
 * What the loaded modules bring, together: the classes registered (ModuleContext), and from
 * their folders their templates' folders, the folders of their layouts and colour schemes,
 * and their translations.
 */
final class ModuleLoader
{
    /** @var array<string, true> The namespaces given to the autoloader, in this PHP process */
    private static array $autoloaded = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $loaded = null;

    /** @var array<string, Message> */
    private array $failures = [];

    /** @param bool $load false: nothing is loaded (the command line's module:* commands, so a module cannot stop them) */
    public function __construct(private readonly ModuleRegistry $registry, private readonly bool $load = true)
    {
    }

    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }

    /**
     * What each loaded module brings, by its name: ModuleContext::registered(), and
     * `templates` (its templates' folder, or null), `layouts` (whether its layouts and
     * colour schemes count), `translations` (locale => key => text).
     *
     * @return array<string, array<string, mixed>>
     */
    public function loaded(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $this->loaded = [];
        foreach ($this->load ? $this->registry->loadOrder() : [] as $manifest) {
            // A module whose needed module failed is not loaded either.
            foreach (array_keys($manifest->requires) as $needed) {
                if (!isset($this->loaded[$needed])) {
                    $this->failures[$manifest->name] = new Message('modules.problem.requires_off', ['module' => $needed]);
                    continue 2;
                }
            }
            try {
                $this->loaded[$manifest->name] = $this->load($manifest);
            } catch (\Throwable $e) {
                error_log("Campanella: the module {$manifest->name} is not loaded: " . $e->getMessage());
                $this->failures[$manifest->name] = new Message('modules.problem.load', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }

        return $this->loaded;
    }

    /**
     * The modules that are on but could not be loaded in this request, and why.
     *
     * @return array<string, Message>
     */
    public function failures(): array
    {
        $this->loaded();

        return $this->failures;
    }

    /**
     * One kind of what the loaded modules registered, in their order.
     *
     * @return list<mixed>
     */
    public function all(string $kind): array
    {
        $all = [];
        foreach ($this->loaded() as $registered) {
            array_push($all, ...array_values((array) ($registered[$kind] ?? [])));
        }

        return $all;
    }

    /**
     * The events' actions of the loaded modules, as the `events` setting writes them.
     *
     * @return array<string, list<string>>
     */
    public function actions(): array
    {
        $actions = [];
        foreach ($this->loaded() as $registered) {
            foreach ((array) $registered['actions'] as $event => $list) {
                $actions[(string) $event] = [...($actions[(string) $event] ?? []), ...array_values(array_map(strval(...), (array) $list))];
            }
        }

        return $actions;
    }

    /**
     * The translations of the loaded modules, merged under the core's (which win).
     *
     * @param array<string, array<string, string>> $core locale => key => text
     * @return array<string, array<string, string>>
     */
    public function translations(array $core): array
    {
        foreach ($this->loaded() as $registered) {
            foreach ((array) $registered['translations'] as $locale => $messages) {
                $core[$locale] = ($core[$locale] ?? []) + $messages;
            }
        }

        return $core;
    }

    /**
     * The templates' folders of the loaded modules, by name.
     *
     * @return array<string, string>
     */
    public function templateDirs(): array
    {
        $dirs = [];
        foreach ($this->loaded() as $name => $registered) {
            if (is_string($registered['templates'])) {
                $dirs[$name] = $registered['templates'];
            }
        }

        return $dirs;
    }

    /**
     * The folders whose layout/ and scheme/ count: those of the loaded modules whose
     * layouts' and schemes' names all start with the module's.
     *
     * @return list<string>
     */
    public function layoutDirs(): array
    {
        $dirs = [];
        foreach ($this->loaded() as $registered) {
            if ($registered['layouts'] === true && is_string($registered['templates'])) {
                $dirs[] = $registered['templates'];
            }
        }

        return $dirs;
    }

    /**
     * The problems of what a module's folder brings (its layouts' and schemes' names, its
     * translations' keys): for ModuleTrial, and to leave them out.
     *
     * @return list<Message>
     */
    public static function folderProblems(ModuleManifest $manifest): array
    {
        $problems = [];
        $templates = $manifest->path . '/templates';
        foreach (['layout', 'scheme'] as $kind) {
            foreach (glob($templates . '/' . $kind . '/*.json') ?: [] as $file) {
                if (!str_starts_with(basename($file, '.json'), $manifest->name . '_')) {
                    $problems[] = new Message('modules.problem.' . $kind . '_name', ['file' => $kind . '/' . basename($file), 'prefix' => $manifest->name . '_']);
                }
            }
        }
        foreach (glob($manifest->path . '/lang/*.php') ?: [] as $file) {
            $locale = basename($file, '.php');
            if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/D', $locale) !== 1) {
                $problems[] = new Message('modules.problem.lang_file', ['file' => 'lang/' . basename($file)]);
                continue;
            }
            $messages = self::requireIsolated($file);
            if (!is_array($messages)) {
                $problems[] = new Message('modules.problem.lang_file', ['file' => 'lang/' . basename($file)]);
                continue;
            }
            foreach ($messages as $key => $text) {
                if (!is_string($key) || !str_starts_with($key, $manifest->name . '.') || !is_string($text)) {
                    $problems[] = new Message('modules.problem.lang_key', ['file' => 'lang/' . basename($file), 'key' => mb_substr((string) $key, 0, 80), 'prefix' => $manifest->name . '.']);
                    break;
                }
            }
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private function load(ModuleManifest $manifest): array
    {
        self::autoload($manifest);
        $class = $manifest->entryClass();
        if (!class_exists($class) || !is_a($class, Module::class, true)) {
            throw new ModuleException("Its entry point {$class} is not a " . Module::class . '.');
        }
        $context = new ModuleContext($manifest);
        (new $class())->register($context);
        $registered = $context->registered();

        // Its folder: templates, layouts and schemes with its prefix, translations with its keys.
        $templates = is_dir($manifest->path . '/templates') ? $manifest->path . '/templates' : null;
        $folder = self::folderProblems($manifest);
        $layoutProblems = array_filter($folder, static fn (Message $m): bool => in_array($m->key, ['modules.problem.layout_name', 'modules.problem.scheme_name'], true));
        if ($layoutProblems !== []) {
            error_log("Campanella: the module {$manifest->name}'s layouts and colour schemes are left out: their names must start with {$manifest->name}_.");
        }
        $translations = [];
        if (count($folder) === count($layoutProblems)) {
            foreach (glob($manifest->path . '/lang/*.php') ?: [] as $file) {
                /** @var array<string, string> $messages */
                $messages = self::requireIsolated($file);
                $translations[basename($file, '.php')] = $messages;
            }
        } else {
            error_log("Campanella: the module {$manifest->name}'s translations are left out: their keys must start with {$manifest->name}.");
        }

        return $registered + ['templates' => $templates, 'layouts' => $layoutProblems === [], 'translations' => $translations];
    }

    /** The module's namespace for PHP's autoloader: its source folder, PSR-4. */
    private static function autoload(ModuleManifest $manifest): void
    {
        $prefix = $manifest->namespace;
        if (isset(self::$autoloaded[$prefix])) {
            return;
        }
        self::$autoloaded[$prefix] = true;
        $dir = $manifest->sourceDir;
        spl_autoload_register(static function (string $class) use ($prefix, $dir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            if (preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative) !== 1) {
                return;
            }
            $file = $dir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /** A file's return value, without the caller's variables. */
    private static function requireIsolated(string $file): mixed
    {
        return (static fn (): mixed => require $file)();
    }
}
