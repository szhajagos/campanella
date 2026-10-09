<?php

declare(strict_types=1);

namespace Campanella\Media;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The folder of the uploaded files (public/media by default), served directly
 * by the web server.
 *
 * Files are stored as YYYY/MM/<24 random hex characters>.<extension>: the name
 * says nothing about the upload, cannot collide, and cannot be guessed. The
 * smaller copies of an image are beside it: <name>-<width>.<extension> (since 0.1.2). Paths
 * are always checked against that form, so no other file can be read or deleted
 * through this class. A .htaccess in the folder forbids running anything as code
 * (written if missing; a copy ships in public/media/).
 */
final class MediaStorage
{
    /** A stored file's path relative to the folder (a smaller copy's too: `-640` before the extension). */
    public const string PATH_PATTERN = '#^\d{4}/\d{2}/[0-9a-f]{24}(-[1-9]\d{1,4})?\.(jpg|png|webp|gif)\z#';

    public const string HTACCESS = <<<'HTACCESS'
        # Uploaded files are never run as code: only served as they are.
        Options -Indexes -ExecCGI
        <IfModule mod_php.c>
            php_flag engine off
        </IfModule>
        <IfModule mod_php7.c>
            php_flag engine off
        </IfModule>
        RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht .phps .cgi .pl .py .sh
        RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht .phps
        <FilesMatch "(?i)\.(php\d?|phtml|phar|pht|phps|cgi|pl|py|sh|shtml|html?|svg)$">
            Require all denied
        </FilesMatch>
        <IfModule mod_headers.c>
            Header set X-Content-Type-Options "nosniff"
            Header set Content-Security-Policy "default-src 'none'; sandbox"
        </IfModule>

        HTACCESS;

    public function __construct(
        private readonly string $directory,
        private readonly string $urlPrefix = '/media',
    ) {
    }

    /**
     * Stores the content under a new name and returns its relative path.
     *
     * @throws \RuntimeException if the folder or the file cannot be written
     */
    public function store(string $bytes, string $extension, ?DateTimeImmutable $now = null): string
    {
        if (!in_array($extension, ['jpg', 'png', 'webp', 'gif'], true)) {
            throw new \InvalidArgumentException("Not an accepted extension: {$extension}");
        }
        $this->ensureDirectory();
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $folder = $now->format('Y/m');
        $dir = $this->directory . '/' . $folder;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("The media folder {$dir} cannot be created.");
        }

        $relative = $folder . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
        $this->write($relative, $bytes);

        return $relative;
    }

    /**
     * Stores a smaller copy of a stored image beside it (`…-640.jpg`) and returns its
     * relative path. An existing copy is replaced. Since 0.1.2.
     *
     * @throws \InvalidArgumentException if $relativePath is not a stored original's path
     * @throws \RuntimeException if the file cannot be written
     */
    public function storeVariant(string $relativePath, int $width, string $bytes): string
    {
        $this->path($relativePath);
        if (str_contains(basename($relativePath), '-') || $width < 1) {
            throw new \InvalidArgumentException("Not an original's path: {$relativePath}");
        }
        $variant = \Campanella\Capability\MediaFile::variantPath($relativePath, $width);
        $this->write($variant, $bytes);

        return $variant;
    }

    /**
     * Deletes the smaller copies of a stored image (any width); returns how many. Since 0.1.2.
     */
    public function deleteVariants(string $relativePath): int
    {
        $path = $this->path($relativePath);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $deleted = 0;
        foreach (@scandir(dirname($path)) ?: [] as $file) {
            if (preg_match('/^' . preg_quote($name, '/') . '-[1-9]\d{1,4}\.' . preg_quote($extension, '/') . '\z/', $file) === 1
                && @unlink(dirname($path) . '/' . $file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Written under a temporary name and renamed, so a half-written file is never served. */
    private function write(string $relative, string $bytes): void
    {
        $target = $this->path($relative);
        $temporary = $target . '.part';
        if (@file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException("The file {$target} cannot be written.");
        }
        @chmod($target, 0644);
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
            throw new \InvalidArgumentException("Not a media path: {$relativePath}");
        }

        return $this->directory . '/' . $relativePath;
    }

    /** The file's address on the site, relative to the site root (e.g. `/media/2026/10/….jpg`). */
    public function url(string $relativePath): string
    {
        $this->path($relativePath); // validates

        return rtrim($this->urlPrefix, '/') . '/' . $relativePath;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** Whether files can be stored: the folder exists (or can be created) and is writable. */
    public function isWritable(): bool
    {
        return is_dir($this->directory) ? is_writable($this->directory) : is_writable(dirname($this->directory));
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("The media folder {$this->directory} cannot be created.");
        }
        $htaccess = $this->directory . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, self::HTACCESS);
        }
    }
}
