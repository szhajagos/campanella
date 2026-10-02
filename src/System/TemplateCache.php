<?php

declare(strict_types=1);

namespace Campanella\System;

/**
 * The folder of the compiled Twig templates: var/cache/twig/<version>.
 *
 * A folder per Campanella version, because upload tools (ZIP extraction, many
 * FTP clients) often keep the files' original modification times: an uploaded
 * template can then look older than its compiled copy, and Twig's auto_reload
 * would keep serving the old one. A new version always starts with an empty folder.
 */
final class TemplateCache
{
    public function __construct(
        private readonly string $baseDir,
        private readonly string $version,
    ) {
        if (preg_match('/^[0-9A-Za-z.+-]+$/', $version) !== 1) {
            throw new \InvalidArgumentException("Invalid version for the template cache folder: {$version}");
        }
    }

    /** The folder of the current version (not necessarily existing yet). */
    public function directory(): string
    {
        return rtrim($this->baseDir, '/') . '/' . $this->version;
    }

    /**
     * The folder for Twig's `cache` option: created if needed; false if it is
     * not writable (Twig then runs without a cache: slower, but it works).
     */
    public function twigCache(): string|false
    {
        $dir = $this->directory();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : false;
    }

    /**
     * The compiled templates of every version: the number of files and their total size in bytes.
     *
     * @return array{files: int, bytes: int}
     */
    public function usage(): array
    {
        $files = 0;
        $bytes = 0;
        foreach ($this->files() as $file) {
            $files++;
            $bytes += (int) $file->getSize();
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Deletes the compiled templates of every version (also older versions' folders).
     * Templates are compiled again on their next use.
     *
     * @return int The number of files deleted
     */
    public function clear(): int
    {
        if (!is_dir($this->baseDir)) {
            return 0;
        }
        $deleted = 0;
        $dirs = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                $dirs[] = $item->getPathname();
            } elseif (@unlink($item->getPathname())) {
                $deleted++;
            }
        }
        foreach ($dirs as $dir) {
            @rmdir($dir);
        }
        // Twig keeps compiled classes in memory only for the current request, so
        // the next request compiles the templates again.
        return $deleted;
    }

    /** @return iterable<\SplFileInfo> */
    private function files(): iterable
    {
        if (!is_dir($this->baseDir)) {
            return [];
        }

        return new \CallbackFilterIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS)),
            static fn (\SplFileInfo $file): bool => $file->isFile(),
        );
    }
}
