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
