<?php

declare(strict_types=1);

namespace Campanella\Appearance;

/**
 * The colour schemes there are (since 0.3.6): those of the core (`templates/scheme/`) and of
 * the active theme (`themes/<name>/templates/scheme/`); a theme's scheme of a core one's
 * name takes its place. A scheme whose JSON is not valid, or that is hard to read, is left
 * out, and errors() says why (the System page shows it).
 */
final class ColorSchemeRegistry
{
    /** The scheme of a site that chose none: the look of the core before 0.3.6. */
    public const string DEFAULT = 'default';

    /** @var array<string, ColorScheme>|null */
    private ?array $schemes = null;

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param list<string> $directories The template folders, the core's first: each one's `scheme/`
     */
    public function __construct(private readonly array $directories)
    {
    }

    /** @return array<string, ColorScheme> name => scheme, the core's first */
    public function all(): array
    {
        if ($this->schemes === null) {
            $this->schemes = [];
            foreach ($this->directories as $index => $directory) {
                foreach (glob(rtrim($directory, '/') . '/scheme/*.json') ?: [] as $file) {
                    $name = basename($file, '.json');
                    try {
                        $this->schemes[$name] = ColorScheme::fromJson($name, (string) file_get_contents($file), $index === 0 ? 'core' : 'theme');
                        unset($this->errors[$name]);
                    } catch (\InvalidArgumentException $e) {
                        // A theme's broken scheme does not hide the core's of its name.
                        $this->errors[$name] = $e->getMessage();
                    }
                }
            }
        }

        return $this->schemes;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** The scheme, or the default one (or the first there is) for an unknown name. */
    public function get(string $name): ColorScheme
    {
        $schemes = $this->all();
        $scheme = $schemes[$name] ?? $schemes[self::DEFAULT] ?? reset($schemes);
        if (!$scheme instanceof ColorScheme) {
            throw new \RuntimeException('There is no colour scheme: templates/scheme/ is missing.');
        }

        return $scheme;
    }

    /** @return array<string, string> The schemes left out: name => why */
    public function errors(): array
    {
        $this->all();

        return $this->errors;
    }
}
