<?php

declare(strict_types=1);

namespace Campanella\Net;

/**
 * A host name's IP addresses (since 0.4.9), for SafeFetcher: DnsResolver; a test gives its own.
 */
interface Resolver
{
    /** @return list<string> Its IPv4 and IPv6 addresses; [] if it has none */
    public function resolve(string $host): array;
}
