<?php

declare(strict_types=1);

namespace Campanella\Net;

/**
 * A host name's addresses from the system's resolver (since 0.4.9): its A and AAAA records.
 */
final class DnsResolver implements Resolver
{
    #[\Override]
    public function resolve(string $host): array
    {
        $addresses = [];
        foreach (@gethostbynamel($host) ?: [] as $ip) {
            $addresses[] = $ip;
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
