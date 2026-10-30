<?php

declare(strict_types=1);

namespace Campanella\Layout;

/**
 * The layouts there are (since 0.3.4): those of the core (`templates/layout/`) and of the
 * active theme (`themes/<name>/templates/layout/`); a theme's layout of a core one's name
 * takes its place. A layout is its JSON file and its template beside it; one whose JSON is
 * not valid, or that has no template, is left out, and errors() says why (the System page
 * shows it).
 */
final class LayoutRegistry
{
    /** The layout of a site that chose none. */
    public const string DEFAULT = 'one_column';

    /** @var array<string, LayoutDefinition>|null */
    private ?array $layouts = null;

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param list<string> $directories The template folders, the core's first: each one's `layout/`
     */
    public function __construct(private readonly array $directories)
    {
    }

    /** @return array<string, LayoutDefinition> name => layout, the core's first */
    public function all(): array
    {
        if ($this->layouts === null) {
            $this->layouts = [];
            foreach ($this->directories as $index => $directory) {
                foreach (glob(rtrim($directory, '/') . '/layout/*.json') ?: [] as $file) {
                    $name = basename($file, '.json');
                    try {
                        if (!is_file(dirname($file) . '/' . $name . '.html.twig')) {
                            throw new \InvalidArgumentException("Layout {$name}: its template layout/{$name}.html.twig is missing.");
                        }
                        $this->layouts[$name] = LayoutDefinition::fromJson($name, (string) file_get_contents($file), $index === 0 ? 'core' : 'theme');
                        unset($this->errors[$name]);
                    } catch (\InvalidArgumentException $e) {
                        $this->errors[$name] = $e->getMessage();
                    }
                }
            }
        }

        return $this->layouts;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** The layout, or the default one (or the first there is) for an unknown name. */
    public function get(string $name): LayoutDefinition
    {
        $layouts = $this->all();
        $layout = $layouts[$name] ?? $layouts[self::DEFAULT] ?? reset($layouts);
        if (!$layout instanceof LayoutDefinition) {
            throw new \RuntimeException('There is no layout: templates/layout/ is missing.');
        }

        return $layout;
    }

    /** @return array<string, string> The layouts left out: name => why */
    public function errors(): array
    {
        $this->all();

        return $this->errors;
    }

    /**
     * The regions a page's own components may be placed in (since 0.3.5): those that are
     * `page` in any layout, each once.
     *
     * @return list<string>
     */
    public function pageRegionNames(): array
    {
        $names = [];
        foreach ($this->all() as $layout) {
            foreach ($layout->regions as $name => $region) {
                if ($region->page) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * The names of the regions of every layout, each once.
     *
     * @return list<string>
     */
    public function regionNames(): array
    {
        $names = [];
        foreach ($this->all() as $layout) {
            foreach ($layout->regions as $name => $_) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }
}
