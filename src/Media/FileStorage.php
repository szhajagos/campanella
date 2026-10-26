<?php

declare(strict_types=1);

namespace Campanella\Media;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The folder of the uploaded files to download (since 0.3.2): `var/files` by
 * default, outside the web root, so the web server never serves them itself: every
 * download goes through PHP (FileController), which checks who may see the file.
 * Public and private files live here alike; making one private moves nothing.
 *
 * Files are stored as YYYY/MM/<24 random hex characters>.<extension>, as images are.
 * Paths are always checked against that form, so no other file can be read or
 * deleted through this class. A .htaccess in the folder denies every request, in
 * case the folder is reachable from the web after all.
 */
final class FileStorage
{
    /** A stored file's path relative to the folder. */
    public const string PATH_PATTERN = '#^\d{4}/\d{2}/[0-9a-f]{24}\.(pdf|docx|xlsx|pptx|odt|ods|odp|txt|csv|zip)\z#';

    public const string HTACCESS = <<<'HTACCESS'
        # Files to download are served by Campanella only (after checking who may see them).
        Require all denied

        HTACCESS;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Stores the content under a new name and returns its relative path.
     *
     * @throws \InvalidArgumentException for an extension that cannot be stored
     * @throws \RuntimeException if the folder or the file cannot be written
     */
    public function store(string $bytes, string $extension, ?DateTimeImmutable $now = null): string
    {
        if (!isset(FileInspector::KNOWN[$extension])) {
            throw new \InvalidArgumentException("Not an accepted extension: {$extension}");
        }
        $this->ensureDirectory();
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $folder = $now->format('Y/m');
        $dir = $this->directory . '/' . $folder;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("The files folder {$dir} cannot be created.");
        }
        $relative = $folder . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
        $target = $this->path($relative);
        $temporary = $target . '.part';
        if (@file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException("The file {$target} cannot be written.");
        }
        @chmod($target, 0640);

        return $relative;
    }

    /** Deletes a stored file; false if it did not exist or could not be deleted. */
    public function delete(string $relativePath): bool
    {
        $path = $this->path($relativePath);

        return is_file($path) && @unlink($path);
    }

    /**
     * The absolute path of a stored file.
     *
     * @throws \InvalidArgumentException for anything that is not a stored file's path (e.g. `../config`)
     */
    public function path(string $relativePath): string
    {
        if (preg_match(self::PATH_PATTERN, $relativePath) !== 1) {
            throw new \InvalidArgumentException("Not a stored file's path: {$relativePath}");
        }

        return $this->directory . '/' . $relativePath;
    }

    public function exists(string $relativePath): bool
    {
        return preg_match(self::PATH_PATTERN, $relativePath) === 1 && is_file($this->path($relativePath));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** Whether files can be stored: the folder exists (or can be created) and is writable. */
    public function isWritable(): bool
    {
        if (is_dir($this->directory)) {
            return is_writable($this->directory);
        }
        $parent = dirname($this->directory);

        return is_dir($parent) && is_writable($parent);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("The files folder {$this->directory} cannot be created.");
        }
        $htaccess = $this->directory . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, self::HTACCESS);
        }
    }
}
