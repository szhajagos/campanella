<?php

declare(strict_types=1);

namespace Campanella\View;

/**
 * The active theme. A theme is a folder that overrides templates and adds assets:
 *
 *   themes/<name>/templates/   templates; one with the same name as a core
 *                              template (templates/…) takes precedence over it
 *   public/themes/<name>/      the theme's CSS, images, scripts ({{ theme_asset() }})
 *
 * A theme only contains what it changes; everything else comes from the core
 * templates. A theme template can also extend the core version of itself:
 * {% extends '@core/base.html.twig' %}. Without a theme (the default), the
 * core templates are used, which are built on Bootstrap 5.3.
 */
final readonly class Theme
{
    private function __construct(
        public string $name,
        public ?string $templateDir,
    ) {
    }

    /** No theme: only the core templates. */
    public static function none(): self
    {
        return new self('', null);
    }

    /**
     * The theme of the given name under the project root; an empty name means no theme.
     *
     * @throws \LogicException for an invalid name or a missing templates folder
     */
    public static function fromRoot(string $root, string $name): self
    {
        if ($name === '') {
            return self::none();
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $name) !== 1) {
            throw new \LogicException("Invalid theme name: {$name}");
        }
        $dir = $root . '/themes/' . $name . '/templates';
        if (!is_dir($dir)) {
            throw new \LogicException("Theme '{$name}' not found: the folder {$dir} does not exist.");
        }

        return new self($name, $dir);
    }

    public function isActive(): bool
    {
        return $this->templateDir !== null;
    }

    /** The URL path of a theme asset (relative to the site root), e.g. 'themes/dark/style.css'. */
    public function assetPath(string $path): string
    {
        if (!$this->isActive()) {
            throw new \LogicException('theme_asset() was called, but no theme is active.');
        }

        return 'themes/' . $this->name . '/' . ltrim($path, '/');
    }
}
