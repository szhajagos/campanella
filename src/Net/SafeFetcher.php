<?php

declare(strict_types=1);

namespace Campanella\Net;

use Campanella\I18n\Message;

/**
 * Fetches a file from a web address for the site (since 0.4.9), so that an address given in the
 * admin cannot reach what only the server can (server-side request forgery):
 *
 *   - `https://` only (`http://` too where `fetch.allow_http` says so), ports 443 and 80 only,
 *     no user name or password in the address;
 *   - the host's every address must be public (AddressPolicy); the connection is made to one of
 *     those (the name is not resolved again in between, so DNS cannot change it);
 *   - a redirect is followed by this class, each address checked again, at most MAX_REDIRECTS,
 *     never from https:// to http:// unless http:// is allowed;
 *   - `fetch.timeout` seconds, `fetch.max_bytes` of the body (as decompressed); a status other
 *     than 200 (or 304, not modified) is an error.
 *
 * A module gets it from the container (`SafeFetcher::class`). Errors are FetchExceptions, with a
 * message for the admin.
 */
final class SafeFetcher
{
    public const int MAX_REDIRECTS = 3;
    public const int MAX_URL = 2000;
    public const array PORTS = [443, 80];

    public function __construct(
        private readonly Resolver $resolver = new DnsResolver(),
        private readonly Transport $transport = new CurlTransport(),
        private readonly bool $allowHttp = false,
        private readonly int $timeout = 10,
        private readonly int $maxBytes = 5 * 1024 * 1024,
    ) {
    }

    /**
     * What is wrong with the address before anything is fetched (its form only, nothing
     * resolved); null if nothing.
     */
    public function problem(string $url): ?Message
    {
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return new Message('fetch.invalid_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return new Message('fetch.invalid_url');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $this->allowHttp)) {
            return new Message($scheme === 'http' ? 'fetch.http_not_allowed' : 'fetch.scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return new Message('fetch.userinfo');
        }
        if (isset($parts['port']) && !in_array($parts['port'], self::PORTS, true)) {
            return new Message('fetch.port', ['ports' => implode(', ', self::PORTS)]);
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (@inet_pton($host) !== false) {
            return AddressPolicy::isPublic($host) ? null : new Message('fetch.private');
        }
        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $host) !== 1) {
            return new Message('fetch.invalid_host');
        }

        return null;
    }

    /**
     * The file at the address. With what an earlier fetch gave (`$etag`, `$lastModified`), the
     * server may answer that nothing changed: `notModified`, an empty body.
     *
     * @throws FetchException
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResult
    {
        $headers = ['Accept' => '*/*'];
        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null && $lastModified !== '') {
            $headers['If-Modified-Since'] = $lastModified;
        }
        for ($redirects = 0; ; ++$redirects) {
            $problem = $this->problem($url);
            if ($problem !== null) {
                throw new FetchException($problem, $url);
            }
            [$ip, $port] = $this->address($url);
            $response = $this->transport->get($url, $ip, $port, $headers, $this->timeout, $this->maxBytes);
            $status = $response['status'];
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new FetchException(new Message('fetch.redirects', ['count' => self::MAX_REDIRECTS]));
                }
                $next = self::resolveUrl($url, $response['headers']['location'] ?? '');
                if ($next === null) {
                    throw new FetchException(new Message('fetch.invalid_url'), 'redirect');
                }
                if (str_starts_with(strtolower($url), 'https:') && str_starts_with(strtolower($next), 'http:') && !$this->allowHttp) {
                    throw new FetchException(new Message('fetch.http_not_allowed'), 'redirect');
                }
                $url = $next;
                continue;
            }
            if ($status === 304) {
                return new FetchResult($url, '', null, $etag, $lastModified, true);
            }
            if ($status !== 200) {
                throw new FetchException(new Message('fetch.status', ['status' => $status]));
            }

            return new FetchResult(
                $url,
                $response['body'],
                $response['headers']['content-type'] ?? null,
                $response['headers']['etag'] ?? null,
                $response['headers']['last-modified'] ?? null,
            );
        }
    }

    /**
     * The address to connect to, every one of the host's checked.
     *
     * @return array{0: string, 1: int}
     */
    private function address(string $url): array
    {
        $parts = (array) parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443));
        $addresses = @inet_pton($host) !== false ? [$host] : $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new FetchException(new Message('fetch.unknown_host', ['host' => $host]));
        }
        foreach ($addresses as $ip) {
            if (!AddressPolicy::isPublic($ip)) {
                throw new FetchException(new Message('fetch.private'), $host . ' ' . $ip);
            }
        }
        // IPv4 first, where there is one.
        usort($addresses, static fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return [$addresses[0], $port];
    }

    /** A redirect's address, made absolute against the one redirected from; null if none. */
    public static function resolveUrl(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . (str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[') ? '[' . $parts['host'] . ']' : $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        if (str_starts_with($location, '?')) {
            return $origin . ($parts['path'] ?? '/') . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') ?? '/';

        return $origin . ($dir === '' ? '/' : $dir) . $location;
    }
}
