<?php

declare(strict_types=1);

namespace Campanella\Net;

/**
 * One HTTP GET to an address already checked (since 0.4.9), for SafeFetcher: CurlTransport; a
 * test gives its own. It never follows a redirect, and stops at `$maxBytes` of the body.
 */
interface Transport
{
    /**
     * @param string $ip The address to connect to, whatever the URL's host resolves to now
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string} Headers by lower-case name
     * @throws FetchException if it fails, takes too long or the body is larger
     */
    public function get(string $url, string $ip, int $port, array $headers, int $timeout, int $maxBytes): array;
}
