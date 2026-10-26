<?php

declare(strict_types=1);

namespace Campanella\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param string|null $file A file sent as the body instead of $body, read in pieces (since 0.3.2)
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
        public readonly ?string $file = null,
    ) {
    }

    /**
     * A file as the response's body (e.g. a download), sent in pieces rather than read
     * into the memory; its Content-Length is set. Since 0.3.2.
     *
     * @param array<string, string> $headers
     */
    public static function file(string $path, array $headers): self
    {
        return new self('', 200, $headers + ['Content-Length' => (string) filesize($path)], $path);
    }

    /** The body's length in bytes: the file's with a file body. Since 0.3.2. */
    public function length(): int
    {
        return $this->file !== null ? (int) @filesize($this->file) : strlen($this->body);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /**
     * A JSON response (e.g. for the editor's image upload). Never cached.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /** A new response with the given header added (or overwritten). */
    public function withHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, [$name => $value] + $this->headers, $this->file);
    }

    public function send(): void
    {
        if ($this->file !== null && !headers_sent()) {
            // A file keeps its Content-Length: PHP's own compression would change its length.
            @ini_set('zlib.output_compression', '0');
        }
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: same-origin');
        }
        if ($this->file === null) {
            echo $this->body;

            return;
        }
        $handle = @fopen($this->file, 'rb');
        if ($handle === false) {
            return;
        }
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            if (ob_get_level() === 0) {
                flush();
            }
        }
        fclose($handle);
    }
}
