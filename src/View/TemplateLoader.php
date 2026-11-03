<?php

declare(strict_types=1);

namespace Campanella\View;

use Twig\Loader\FilesystemLoader;

/**
 * Twig's file loader, with a cache key that depends on the template's content.
 *
 * Twig finds a template's compiled copy by its cache key, and with `auto_reload`
 * it compiles again only if the file's modification time is later than the copy's.
 * Upload tools (ZIP extraction, many FTP clients) often keep the files' original
 * times, so a changed template could look older than its compiled copy and the old
 * one would keep being served. Here the key holds a hash of the content: a changed
 * template has a new key, so it is compiled again whatever its file time.
 *
 * The cost is reading the templates a request uses (a few small files) and hashing
 * them (xxh128), once per template and request.
 */
final class TemplateLoader extends FilesystemLoader
{
    /** @var array<string, string> */
    private array $keys = [];

    /** @var array<string, string> module => its templates' folder (since 0.4.6) */
    private array $moduleLayouts = [];

    /**
     * A module's layouts (since 0.4.6): `layout/<module>_….html.twig` found in its templates'
     * folder, only when no other path has that name; a module never replaces another template.
     */
    public function addModuleLayouts(string $module, string $dir): void
    {
        $this->moduleLayouts[$module] = rtrim($dir, '/');
    }

    #[\Override]
    protected function findTemplate(string $name, bool $throw = true): ?string
    {
        $found = parent::findTemplate($name, false);
        if ($found !== null || $this->moduleLayouts === []) {
            return $found ?? parent::findTemplate($name, $throw);
        }
        if (preg_match('#^layout/[a-z][a-z0-9_]*\.html\.twig$#D', $name) === 1) {
            foreach ($this->moduleLayouts as $module => $dir) {
                if (str_starts_with($name, 'layout/' . $module . '_') && is_file($dir . '/' . $name)) {
                    return $dir . '/' . $name;
                }
            }
        }

        return parent::findTemplate($name, $throw);
    }

    #[\Override]
    public function getCacheKey(string $name): string
    {
        $key = parent::getCacheKey($name);
        $path = $this->findTemplate($name, false);
        if (!is_string($path)) {
            return $key;
        }
        if (!isset($this->keys[$path])) {
            $hash = @hash_file('xxh128', $path);
            $this->keys[$path] = $hash === false ? '' : $hash;
        }

        return $key . '#' . $this->keys[$path];
    }
}
