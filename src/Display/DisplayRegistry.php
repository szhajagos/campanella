<?php

declare(strict_types=1);

namespace Campanella\Display;

use Closure;

/**
 * The displays and system sources known to the system (since 0.3.3), from
 * config/app.php (`displays`, `display_sources`), as the capabilities are. A module
 * (0.5.0) will add its own there.
 *
 * Displays and sources are made when first used, by the factory: the Kernel's gives
 * those that need services (the navigation needs the MenuBuilder) from the container,
 * the others are made with `new`.
 */
final class DisplayRegistry
{
    /** The core's displays, in the order the admin offers them. */
    public const array BUILTIN = [
        Builtin\FullDisplay::class,
        Builtin\TeaserDisplay::class,
        Builtin\LinkDisplay::class,
        Builtin\InfoBoxDisplay::class,
        Builtin\NavigationDisplay::class,
        Builtin\ImageDisplay::class,
        Builtin\DownloadDisplay::class,
        Builtin\CardsDisplay::class,
        Builtin\TableDisplay::class,
        Builtin\LoginButtonDisplay::class,
        Builtin\AdminButtonDisplay::class,
        Builtin\LoginFormDisplay::class,
        Builtin\UserCardDisplay::class,
        Builtin\SearchBoxDisplay::class,
        Builtin\ColorModeSwitchDisplay::class,
        Builtin\BrandingDisplay::class,
        Builtin\BreadcrumbsDisplay::class,
    ];

    /** The core's system sources. */
    public const array BUILTIN_SOURCES = [
        Source\VisitorSource::class,
        Source\SearchSource::class,
        Source\SiteSource::class,
        Source\BreadcrumbsSource::class,
    ];

    /** @var array<string, DisplayDefinition> */
    private array $definitions = [];

    /** @var array<string, Display> */
    private array $displays = [];

    /** @var array<string, SystemSource>|null */
    private ?array $sources = null;

    /** @var Closure(class-string): object */
    private readonly Closure $factory;

    /**
     * @param list<class-string<Display>> $displays
     * @param list<class-string<SystemSource>> $sourceClasses
     * @param (Closure(class-string): object)|null $factory Makes a display or source of a class
     * @throws DisplayException for an invalid display, or a name used twice
     */
    public function __construct(array $displays = [], private readonly array $sourceClasses = [], ?Closure $factory = null)
    {
        $this->factory = $factory ?? static fn (string $class): object => new $class();
        foreach ($displays as $class) {
            $definition = DisplayDefinition::fromClass($class);
            if (isset($this->definitions[$definition->name])) {
                throw new DisplayException("Display '{$definition->name}' is already registered.");
            }
            $this->definitions[$definition->name] = $definition;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /** @throws DisplayException for an unknown display */
    public function definition(string $name): DisplayDefinition
    {
        return $this->definitions[$name] ?? throw new DisplayException("Unknown display: {$name}");
    }

    /** @return array<string, DisplayDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @throws DisplayException for an unknown display */
    public function display(string $name): Display
    {
        if (!isset($this->displays[$name])) {
            $definition = $this->definition($name);
            $display = ($this->factory)($definition->class);
            if (!$display instanceof Display) {
                throw new DisplayException("The factory did not make a Display for {$definition->class}.");
            }
            $this->displays[$name] = $display;
        }

        return $this->displays[$name];
    }

    /** Whether the display can show this subject (false for an unknown display). */
    public function accepts(string $name, Subject $subject): bool
    {
        return $this->has($name) && $this->definitions[$name]->allows($subject) && $this->display($name)->accepts($subject);
    }

    /**
     * The displays that can show this subject, in their order.
     *
     * @return list<DisplayDefinition>
     */
    public function for(Subject $subject): array
    {
        return array_values(array_filter($this->definitions, fn (DisplayDefinition $d): bool => $this->accepts($d->name, $subject)));
    }

    /**
     * @return array<string, SystemSource> name => source
     * @throws DisplayException for an invalid source, or a name used twice
     */
    public function sources(): array
    {
        if ($this->sources === null) {
            $sources = [];
            foreach ($this->sourceClasses as $class) {
                $source = ($this->factory)($class);
                if (!$source instanceof SystemSource) {
                    throw new DisplayException("{$class} is not a SystemSource.");
                }
                $name = $source->name();
                if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1 || isset($sources[$name])) {
                    throw new DisplayException("Invalid or repeated system source name: {$name}");
                }
                $sources[$name] = $source;
            }
            $this->sources = $sources;
        }

        return $this->sources;
    }

    public function hasSource(string $name): bool
    {
        return isset($this->sources()[$name]);
    }

    /** @throws DisplayException for an unknown source */
    public function source(string $name): SystemSource
    {
        return $this->sources()[$name] ?? throw new DisplayException("Unknown system source: {$name}");
    }
}
