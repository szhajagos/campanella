<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\Capability\Capability;
use Campanella\Cli\Command;
use Campanella\Display\Display;
use Campanella\Display\SystemSource;
use Campanella\Event\Action;
use Campanella\Event\Event;
use Campanella\Token\TokenProvider;
use Closure;

/**
 * What a module registers, in its register() (since 0.4.6). Every class must be the module's
 * own (under its namespace) and of the right kind; anything else is a ModuleException, and
 * the module is not loaded. The rest comes from its folder by itself:
 *
 *   templates/          its templates as `@<name>/…`; `templates/layout/<name>_*.json` its
 *                       layouts, `templates/scheme/<name>_*.json` its colour schemes (their
 *                       names start with the module's; a module never replaces the core's)
 *   lang/<locale>.php   its translations, keys starting with `<name>.`
 *   public/             its files, copied to public/modules/<name>/ when it is turned on
 *                       ({{ module_asset('<name>', 'style.css') }})
 *
 * It cannot replace a service of the core: service() takes the module's own classes only.
 */
final class ModuleContext
{
    /** @var list<class-string<Capability>> */
    private array $capabilities = [];

    /** @var list<class-string<Display>> */
    private array $displays = [];

    /** @var list<class-string<SystemSource>> */
    private array $sources = [];

    /** @var list<class-string<TokenProvider>> */
    private array $tokenProviders = [];

    /** @var array<class-string<Event>, list<class-string<Action>>> */
    private array $actions = [];

    /** @var list<array{0: class-string<Event>, 1: Closure}> */
    private array $listeners = [];

    /** @var list<Command> */
    private array $commands = [];

    /** @var array<class-string, Closure> */
    private array $services = [];

    /** @var list<\Campanella\Database\Migration\Migration> */
    private array $migrations = [];

    /** @var list<array{path: string, controller: string, prefix: bool}> */
    private array $routes = [];

    /** @var list<\Campanella\Model\CustomFieldType> */
    private array $fieldTypes = [];

    /** @var array{class: string, label: string, group: string, editors: bool}|null */
    private ?array $adminPage = null;

    /** Paths a module's route can never have (the system's folders). */
    private const array RESERVED_PATHS = ['/assets', '/themes', '/media', '/modules', '/files', '/install'];

    public function __construct(public readonly ModuleManifest $manifest)
    {
    }

    /** @param string $class A capability class of the module */
    public function capability(string $class): self
    {
        $this->capabilities[] = $this->own($class, Capability::class);

        return $this;
    }

    /** @param string $class A display class of the module (with its #[AsDisplay]) */
    public function display(string $class): self
    {
        $this->displays[] = $this->own($class, Display::class);

        return $this;
    }

    /** @param string $class A system source class of the module */
    public function displaySource(string $class): self
    {
        $this->sources[] = $this->own($class, SystemSource::class);

        return $this;
    }

    /** @param string $class A TokenProvider class of the module */
    public function tokens(string $class): self
    {
        $this->tokenProviders[] = $this->own($class, TokenProvider::class);

        return $this;
    }

    /**
     * An action of the module run on an event (of the core or of the module).
     *
     * @param string $event An Event class
     * @param string $action An Action class of the module
     */
    public function on(string $event, string $action): self
    {
        if (!is_a($event, Event::class, true)) {
            throw new ModuleException("Not an event class: {$event}");
        }
        $this->actions[$event][] = $this->own($action, Action::class);

        return $this;
    }

    /**
     * A listener of the module on an event.
     *
     * @param string $event An Event class
     */
    public function listen(string $event, Closure $listener): self
    {
        if (!is_a($event, Event::class, true)) {
            throw new ModuleException("Not an event class: {$event}");
        }
        $this->listeners[] = [$event, $listener];

        return $this;
    }

    /** A command of bin/campanella; its name must start with `<module>:`. */
    public function command(Command $command): self
    {
        $this->own($command::class, Command::class);
        if (!str_starts_with($command->name(), $this->manifest->name . ':') || preg_match('/^[a-z0-9_:-]{3,80}$/D', $command->name()) !== 1) {
            throw new ModuleException("A command's name must start with {$this->manifest->name}: ({$command->name()})");
        }
        $this->commands[] = $command;

        return $this;
    }

    /**
     * A service of the module's own class, made by the factory when first needed
     * (`$factory(Container $c)`); e.g. a display that needs other services.
     *
     * @param string $class A class of the module
     * @param Closure(\Campanella\Core\Container): object $factory
     */
    public function service(string $class, Closure $factory): self
    {
        $this->services[$this->own($class, null)] = $factory;

        return $this;
    }

    /**
     * A migration of the module's own tables (since 0.4.7; chapter 17's rules): its ID starts
     * with `<module>:`. Run, after a backup, when the module is turned on, and when a newer
     * version of the module brings a new one (the site's upgrade, as the core's).
     *
     * @param string $class A Migration class of the module
     */
    public function migration(string $class): self
    {
        $migration = new ($this->own($class, \Campanella\Database\Migration\Migration::class))();
        if (!str_starts_with($migration->id(), $this->manifest->name . ':')) {
            throw new ModuleException("A migration's ID must start with {$this->manifest->name}: ({$migration->id()})");
        }
        $this->migrations[] = $migration;

        return $this;
    }

    /**
     * A field type of the module's own (since 0.4.8): a CustomFieldType of the module, built on a
     * core type, its name starting with `<module>_`.
     *
     * @param string $class A CustomFieldType class of the module
     */
    public function fieldType(string $class): self
    {
        $type = new ($this->own($class, \Campanella\Model\CustomFieldType::class))();
        if (!str_starts_with($type->name(), $this->manifest->name . '_')) {
            throw new ModuleException("A field type's name must start with {$this->manifest->name}_ ({$type->name()})");
        }
        $this->fieldTypes[] = $type;

        return $this;
    }

    /**
     * A public page of the module (since 0.4.7): its Controller (made by the container, so a
     * service() of it can have its own services) at the path, or under it with `$prefix`. It
     * is routed after the system's routes and only where nothing else is routed (never the
     * admin's, a system page's or another module's place); the controller checks who may see
     * what (an Actor is given), and a POST's CSRF token (Csrf).
     *
     * @param string $controller A Controller class of the module
     */
    public function route(string $path, string $controller, bool $prefix = false): self
    {
        if (preg_match('#^/[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*){0,5}$#D', $path) !== 1) {
            throw new ModuleException("Not a path for a module's page: {$path}");
        }
        foreach (self::RESERVED_PATHS as $reserved) {
            if ($path === $reserved || str_starts_with($path, $reserved . '/')) {
                throw new ModuleException("A module's page cannot be under {$reserved}: {$path}");
            }
        }
        $this->routes[] = ['path' => $path, 'controller' => $this->own($controller, \Campanella\Controller\Controller::class), 'prefix' => $prefix];

        return $this;
    }

    /**
     * The module's page in the admin (since 0.4.7): /admin/module/<name>/…, an AdminPage of
     * the module, with an item in the admin's menu (its label: a key of its translations, in
     * a group of AdminMenu, by default System). For the system roles, or (`$editors`) for
     * everyone who may enter the admin.
     *
     * @param string $class An AdminPage class of the module
     */
    public function adminPage(string $class, string $label, string $group = 'system', bool $editors = false): self
    {
        if ($this->adminPage !== null) {
            throw new ModuleException('A module has one admin page (with its own paths under it).');
        }
        if (!in_array($group, ['content', 'media', 'audience', 'appearance', 'structure', 'system'], true)) {
            throw new ModuleException("Not a group of the admin's menu: {$group}");
        }
        $this->adminPage = ['class' => $this->own($class, AdminPage::class), 'label' => $label, 'group' => $group, 'editors' => $editors];

        return $this;
    }

    /**
     * Everything registered.
     *
     * @return array{capabilities: list<class-string<Capability>>, displays: list<class-string<Display>>, sources: list<class-string<SystemSource>>, tokens: list<class-string<TokenProvider>>, actions: array<class-string<Event>, list<class-string<Action>>>, listeners: list<array{0: class-string<Event>, 1: Closure}>, commands: list<Command>, services: array<class-string, Closure>, migrations: list<\Campanella\Database\Migration\Migration>, routes: list<array{path: string, controller: string, prefix: bool}>, field_types: list<\Campanella\Model\CustomFieldType>, admin: array{class: string, label: string, group: string, editors: bool}|null}
     */
    public function registered(): array
    {
        return [
            'capabilities' => $this->capabilities,
            'displays' => $this->displays,
            'sources' => $this->sources,
            'tokens' => $this->tokenProviders,
            'actions' => $this->actions,
            'listeners' => $this->listeners,
            'commands' => $this->commands,
            'services' => $this->services,
            'migrations' => $this->migrations,
            'routes' => $this->routes,
            'field_types' => $this->fieldTypes,
            'admin' => $this->adminPage,
        ];
    }

    /**
     * @template T of object
     * @param class-string<T>|null $kind
     * @return class-string<T>
     */
    private function own(string $class, ?string $kind): string
    {
        if (!str_starts_with($class, $this->manifest->namespace)) {
            throw new ModuleException("Not a class of the module ({$this->manifest->namespace}): {$class}");
        }
        if (!class_exists($class) || ($kind !== null && !is_a($class, $kind, true))) {
            throw new ModuleException($kind === null ? "No such class: {$class}" : "Not a {$kind}: {$class}");
        }

        /** @var class-string<T> $class */
        return $class;
    }
}
