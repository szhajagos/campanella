<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\Capability\CapabilityRegistry;
use Campanella\Core\Config;
use Campanella\Core\Kernel;
use Campanella\Display\DisplayRegistry;
use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\Layout\LayoutRegistry;
use Campanella\Appearance\ColorSchemeRegistry;
use Twig\Environment;

/**
 * A module tried before it is turned on (since 0.4.6): if anything is wrong, it stays off.
 *
 *   1. it can be turned on: found, not broken, every module it needs on;
 *   2. every PHP file of its folder can be read by PHP (a syntax error never reaches a page);
 *   3. its folder: its layouts' and colour schemes' names, its translations' keys;
 *   4. a site of its own is made with it on (a Kernel, the others that are on too): the
 *      module is loaded, what it registered is checked as the registries check it (a name
 *      used twice, a display that is not valid), its templates are compiled, its layouts and
 *      colour schemes read;
 *   5. its tables (since 0.4.7): when it is turned on, `$prepare` makes them (a backup, then
 *      its migrations), and that site is made again; the confirmation only lists them;
 *   6. pages of that site are made with it: the front page, the search, a page that is not
 *      there; none may fail (pages that start no session, beside the admin's own).
 *
 * A PHP warning or notice on the way counts as a problem too. Nothing is saved by the
 * trial; a fatal error in it ends only that one request, and the module stays off.
 */
final class ModuleTrial
{
    /** The most PHP files of a module checked. */
    public const int MAX_FILES = 5000;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly string $rootDir,
    ) {
    }

    /**
     * The problems of turning the module on ([] if none), and what it brings: kind => names.
     *
     * @return array{problems: list<Message>, brings: array<string, list<string>>}
     */
    public function run(string $name, ?\Closure $prepare = null): array
    {
        $state = $this->registry->get($name);
        if ($state === null || $state->manifest === null || $state->status === ModuleState::BROKEN) {
            return ['problems' => $state === null ? [new Message('modules.trial.missing')] : ($state->problems ?: [new Message('modules.trial.missing')]), 'brings' => []];
        }
        $manifest = $state->manifest;
        $problems = [];
        foreach (array_keys($manifest->requires) as $needed) {
            if (!($this->registry->get($needed)?->isLoaded() ?? false)) {
                $problems[] = new Message('modules.trial.requires_off', ['module' => $needed]);
            }
        }
        if ($problems !== []) {
            return ['problems' => $problems, 'brings' => []];
        }
        $problems = [...$this->syntax($manifest), ...ModuleLoader::folderProblems($manifest)];
        if ($problems !== []) {
            return ['problems' => $problems, 'brings' => []];
        }

        $warnings = [];
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$warnings): bool {
            if (($level & (E_DEPRECATED | E_USER_DEPRECATED)) === 0) {
                $warnings[] = $message . ($file !== '' ? ' (' . basename($file) . ':' . $line . ')' : '');
            }

            return true;
        });
        try {
            [$problems, $brings] = $this->onASite($manifest, $prepare);
        } catch (\Throwable $e) {
            [$problems, $brings] = [[new Message('modules.trial.error', ['error' => mb_substr($e->getMessage(), 0, 300)])], []];
        } finally {
            restore_error_handler();
        }
        if ($warnings !== []) {
            $problems[] = new Message('modules.trial.warning', ['warning' => mb_substr(implode('; ', array_slice($warnings, 0, 3)), 0, 400)]);
        }

        return ['problems' => $problems, 'brings' => $problems === [] ? $brings : []];
    }

    /** @return list<Message> */
    private function syntax(ModuleManifest $manifest): array
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($manifest->path, \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            if (++$count > self::MAX_FILES) {
                return [new Message('modules.trial.too_many_files', ['max' => self::MAX_FILES])];
            }
            try {
                token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE);
            } catch (\ParseError $e) {
                return [new Message('modules.trial.syntax', [
                    'file' => substr($file->getPathname(), strlen($manifest->path) + 1),
                    'line' => $e->getLine(),
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ])];
            }
        }

        return [];
    }

    /**
     * @return array{0: list<Message>, 1: array<string, list<string>>}
     */
    private function onASite(ModuleManifest $manifest, ?\Closure $prepare): array
    {
        $on = array_map(static fn (ModuleManifest $m): string => $m->name, $this->registry->loadOrder());
        $kernel = new Kernel($this->rootDir, ['modules' => ['directory' => $this->registry->directory(), 'enabled' => [...$on, $manifest->name], 'load' => true], 'debug' => false]);
        $c = $kernel->container();
        $loader = $c->get(ModuleLoader::class);
        $failure = $loader->failures()[$manifest->name] ?? null;
        if ($failure !== null) {
            return [[$failure], []];
        }
        $registered = $loader->loaded()[$manifest->name] ?? null;
        if ($registered === null) {
            return [[new Message('modules.trial.not_loaded')], []];
        }
        $problems = [];
        $config = $c->get(Config::class);

        // As the registries check them, all together (the Kernel's would leave a wrong one out).
        $brings = [];
        try {
            $capabilities = new CapabilityRegistry([...array_values((array) $config->get('capabilities', [])), ...$loader->all('capabilities')]);
            $brings['capabilities'] = array_map(static fn (string $class): string => $capabilities->get($class)->name, (array) $registered['capabilities']);
        } catch (\Throwable $e) {
            $problems[] = new Message('modules.trial.capabilities', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        try {
            $displays = new DisplayRegistry(
                [...array_values((array) $config->get('displays', DisplayRegistry::BUILTIN)), ...$loader->all('displays')],
                [...array_values((array) $config->get('display_sources', DisplayRegistry::BUILTIN_SOURCES)), ...$loader->all('sources')],
                static fn (string $class): object => $c->has($class) ? $c->get($class) : new $class(),
            );
            $displays->sources();
            $own = array_flip((array) $registered['displays']);
            $brings['displays'] = array_values(array_map(static fn ($d): string => $d->name, array_filter($displays->definitions(), static fn ($d): bool => isset($own[$d->class]))));
        } catch (\Throwable $e) {
            $problems[] = new Message('modules.trial.displays', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $names = [];
        foreach ($loader->all('commands') as $command) {
            if (isset($names[$command->name()])) {
                $problems[] = new Message('modules.trial.command_twice', ['command' => $command->name()]);
            }
            $names[$command->name()] = true;
        }
        $brings['commands'] = array_map(static fn ($command): string => $command->name(), (array) $registered['commands']);
        $brings['tokens'] = array_map(static fn (string $class): string => substr($class, strrpos($class, '\\') + 1), (array) $registered['tokens']);
        $brings['events'] = array_keys((array) $registered['actions']);

        // Its templates compiled; its layouts and colour schemes read.
        $templates = [];
        if (is_string($registered['templates'])) {
            $twig = $c->get(Environment::class);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($registered['templates'], \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($registered['templates']) + 1);
                $templates[] = $relative;
                try {
                    $twig->load('@' . $manifest->name . '/' . $relative);
                } catch (\Throwable $e) {
                    $problems[] = new Message('modules.trial.template', ['file' => 'templates/' . $relative, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                }
            }
            try {
                $layouts = $c->get(LayoutRegistry::class)->all();
                $brings['layouts'] = array_values(array_filter(array_keys($layouts), static fn (string $n): bool => str_starts_with($n, $manifest->name . '_')));
                foreach ($brings['layouts'] as $layout) {
                    $twig->load($layouts[$layout]->template());
                }
            } catch (\Throwable $e) {
                $problems[] = new Message('modules.trial.layouts', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
            $schemes = $c->get(ColorSchemeRegistry::class);
            foreach ($schemes->errors() as $scheme => $error) {
                if (str_starts_with((string) $scheme, $manifest->name . '_')) {
                    $problems[] = new Message('modules.trial.scheme', ['scheme' => (string) $scheme, 'error' => mb_substr((string) $error, 0, 300)]);
                }
            }
            $brings['schemes'] = array_values(array_filter(array_keys($schemes->all()), static fn (string $n): bool => str_starts_with($n, $manifest->name . '_')));
        }
        $brings['templates'] = $templates;
        $brings['translations'] = array_keys((array) $registered['translations']);
        $brings['files'] = ModuleManager::publicFiles($manifest);

        // Its tables (since 0.4.7): made before the pages are tried, only when it is turned on
        // ($prepare: a backup, then its migrations); the confirmation only lists them.
        $pending = array_values(array_filter(
            $c->get(\Campanella\Database\Migration\Migrator::class)->pending(),
            static fn ($migration): bool => str_starts_with($migration->id(), $manifest->name . ':'),
        ));
        $brings['migrations'] = array_map(static fn ($migration): string => $migration->id(), $pending);
        $pagesLater = false;
        if ($problems === [] && $pending !== []) {
            if ($prepare === null) {
                $pagesLater = true;
            } else {
                $failed = $prepare($pending);
                if ($failed instanceof Message) {
                    $problems[] = $failed;
                } else {
                    // A site made again, with its tables.
                    $kernel = new Kernel($this->rootDir, ['modules' => ['directory' => $this->registry->directory(), 'enabled' => [...$on, $manifest->name], 'load' => true], 'debug' => false]);
                    $c = $kernel->container();
                }
            }
        }
        if ($pagesLater) {
            $brings['pages_later'] = ['1'];
        }

        // Pages of that site: none may fail.
        if ($problems === [] && !$pagesLater && !$c->get(\Campanella\Database\Installer::class)->needsUpgrade()) {
            // Pages that start no session (the admin's request has its own).
            foreach ([['/', []], [$c->get(\Campanella\Http\SitePaths::class)->get('search'), ['q' => 'campanella']], ['/campanella-module-trial-' . bin2hex(random_bytes(4)), []]] as [$path, $query]) {
                $response = $kernel->handle(new Request('GET', $path, query: $query));
                if ($response->status >= 500) {
                    $problems[] = new Message('modules.trial.page', ['path' => $path, 'status' => $response->status]);
                }
            }
        }

        $kept = [];
        foreach ($brings as $kind => $list) {
            if ($list !== []) {
                $kept[$kind] = array_values(array_map(strval(...), $list));
            }
        }

        return [$problems, $kept];
    }
}
