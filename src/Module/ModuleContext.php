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
     * Everything registered.
     *
     * @return array{capabilities: list<class-string<Capability>>, displays: list<class-string<Display>>, sources: list<class-string<SystemSource>>, tokens: list<class-string<TokenProvider>>, actions: array<class-string<Event>, list<class-string<Action>>>, listeners: list<array{0: class-string<Event>, 1: Closure}>, commands: list<Command>, services: array<class-string, Closure>}
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
