<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * A HTTP-kérés megváltoztathatatlan ábrázolása.
 *
 * A `path` mindig a telepítés gyökeréhez képest értendő, így a rendszer
 * alkönyvtárba telepítve (pl. example.hu/campanella/) is működik.
 */
final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
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
        );
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

    /** Egy POST-mező szövegként (nem szöveges érték esetén üres szöveg). */
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

    /** Az URL-előtag. Ha a gyökér .htaccess irányít a public/ mappába, azt elrejti. */
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
