<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * An immutable representation of the HTTP request.
 *
 * `path` is always relative to the installation root, so the system also
 * works when installed in a subdirectory (e.g. example.hu/campanella/).
 */
final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, UploadedFile> $files Uploaded files by field name (single-file fields; since 0.0.5)
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $post = [],
        public string $basePath = '',
        public array $headers = [],
        public array $cookies = [],
        public string $ip = '',
        public bool $secure = false,
        public array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?? '/'));
        $basePath = self::detectBasePath((string) ($_SERVER['SCRIPT_NAME'] ?? ''), $path);

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        // Not an HTTP_ variable, but needed to recognise a body PHP discarded (post_max_size).
        foreach (['CONTENT_LENGTH' => 'content-length', 'CONTENT_TYPE' => 'content-type'] as $server => $header) {
            if (isset($_SERVER[$server])) {
                $headers[$header] = (string) $_SERVER[$server];
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath($path),
            $_GET,
            $_POST,
            $basePath,
            $headers,
            $cookies,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ($https !== '' && strtolower($https) !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443,
            self::uploadedFiles($_FILES),
        );
    }

    /** A posted file field, if any. */
    public function file(string $name): ?UploadedFile
    {
        return $this->files[$name] ?? null;
    }

    /**
     * The single-file fields of $_FILES. A file that arrived is only accepted if PHP
     * confirms it was uploaded (is_uploaded_file()); a failed upload keeps its error code.
     *
     * @param array<mixed> $files
     * @return array<string, UploadedFile>
     */
    private static function uploadedFiles(array $files): array
    {
        $result = [];
        foreach ($files as $field => $file) {
            if (!is_array($file) || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)) {
                continue; // multiple files under one name are not supported
            }
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_OK && !is_uploaded_file($file['tmp_name'])) {
                continue;
            }
            $result[(string) $field] = new UploadedFile($file['name'], $file['tmp_name'], (int) ($file['size'] ?? 0), $error);
        }

        return $result;
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/index.php' ? '/' : $path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** A POST field as a string (an empty string for a non-string value). */
    public function postString(string $name): string
    {
        $value = $this->post[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    public function queryString(string $name): string
    {
        $value = $this->query[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    public function queryInt(string $name, int $default = 0): int
    {
        $value = $this->query[$name] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** The URL prefix. If the root .htaccess routes into the public/ folder, it hides that. */
    private static function detectBasePath(string $scriptName, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($base === '' || $base === '.') {
            return '';
        }
        if (str_starts_with($path, $base . '/') || $path === $base) {
            return $base;
        }
        if (str_ends_with($base, '/public')) {
            return substr($base, 0, -strlen('/public'));
        }

        return '';
    }
}
