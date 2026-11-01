<?php

declare(strict_types=1);

namespace Campanella\Appearance;

/**
 * The colour schemes there are (since 0.3.6): those of the core (`templates/scheme/`) and of
 * the active theme (`themes/<name>/templates/scheme/`); a theme's scheme of a core one's
 * name takes its place. Since 0.4.2 also those made in the admin (ColorSchemeStore, origin
 * `admin`), after them; one cannot take a file's scheme's name. A scheme whose JSON is not valid, or that is hard to read, is left
 * out, and errors() says why (the System page shows it).
 */
final class ColorSchemeRegistry
{
    /** The scheme of a site that chose none: the look of the core before 0.3.6. */
    public const string DEFAULT = 'default';

    /** @var array<string, ColorScheme>|null */
    private ?array $schemes = null;

    /** @var array<string, ColorScheme>|null The files' schemes (the core's and the theme's) */
    private ?array $files = null;

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param list<string> $directories The template folders, the core's first: each one's `scheme/`
     */
    public function __construct(
        private readonly array $directories,
        private readonly ?ColorSchemeStore $store = null,
    ) {
    }

    /** @return array<string, ColorScheme> name => scheme, the core's first */
    public function all(): array
    {
        if ($this->schemes === null) {
            $this->schemes = $this->files();
            foreach ($this->stored() as $name => $json) {
                if (isset($this->schemes[$name])) {
                    $this->errors[$name] = "Colour scheme {$name}: a scheme of the core or the theme has this name.";
                    continue;
                }
                try {
                    $this->schemes[$name] = ColorScheme::fromJson($name, $json, 'admin');
                } catch (\InvalidArgumentException $e) {
                    $this->errors[$name] = $e->getMessage();
                }
            }
        }

        return $this->schemes;
    }

    /**
     * The files' schemes only (since 0.4.2: without reading the database, so a page whose
     * scheme is a file's sends no statement for it).
     *
     * @return array<string, ColorScheme>
     */
    private function files(): array
    {
        if ($this->files === null) {
            $this->files = [];
            foreach ($this->directories as $index => $directory) {
                foreach (glob(rtrim($directory, '/') . '/scheme/*.json') ?: [] as $file) {
                    $name = basename($file, '.json');
                    try {
                        $this->files[$name] = ColorScheme::fromJson($name, (string) file_get_contents($file), $index === 0 ? 'core' : 'theme');
                        unset($this->errors[$name]);
                    } catch (\InvalidArgumentException $e) {
                        // A theme's broken scheme does not hide the core's of its name.
                        $this->errors[$name] = $e->getMessage();
                    }
                }
            }
        }

        return $this->files;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** The scheme, or the default one (or the first there is) for an unknown name. */
    public function get(string $name): ColorScheme
    {
        $files = $this->files();
        if (isset($files[$name])) {
            return $files[$name];
        }
        $schemes = $this->all();
        $scheme = $schemes[$name] ?? $schemes[self::DEFAULT] ?? reset($schemes);
        if (!$scheme instanceof ColorScheme) {
            throw new \RuntimeException('There is no colour scheme: templates/scheme/ is missing.');
        }

        return $scheme;
    }

    /** Whether the name is a scheme's of the core or the theme (a file), not one made in the admin. */
    public function isFromFile(string $name): bool
    {
        return isset($this->all()[$name]) && $this->all()[$name]->origin !== 'admin';
    }

    /** Forgets the schemes read (after one is saved or deleted in the admin). */
    public function reset(): void
    {
        $this->schemes = null;
        $this->files = null;
        $this->errors = [];
    }

    /** @return array<string, string> */
    private function stored(): array
    {
        try {
            return $this->store?->load() ?? [];
        } catch (\Throwable) {
            return []; // e.g. no database yet
        }
    }

    /** @return array<string, string> The schemes left out: name => why */
    public function errors(): array
    {
        $this->all();

        return $this->errors;
    }
}
