<?php

declare(strict_types=1);

namespace Campanella\Net;

/**
 * Which IP addresses the site may fetch from (since 0.4.9): public ones only. Not the
 * site's own machine, its network or its provider's (where a cloud's metadata service
 * answers), not documentation, reserved or multicast ranges, and not those an IPv6
 * address carries an IPv4 one of (mapped, NAT64, 6to4) unless that one is public.
 */
final class AddressPolicy
{
    /** IPv4 ranges never fetched from. */
    private const array IPV4 = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // shared address space (carrier-grade NAT)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local (cloud metadata services)
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // documentation
        '192.88.99.0/24',   // 6to4 relay
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation
        '203.0.113.0/24',   // documentation
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, and the broadcast address
    ];

    /** IPv6 ranges never fetched from (those carrying an IPv4 address are checked by it). */
    private const array IPV6 = [
        '::/128',           // unspecified
        '::1/128',          // loopback
        '::/96',            // IPv4-compatible (deprecated)
        '100::/64',         // discard
        '2001::/23',        // IETF protocol assignments (Teredo among them)
        '2001:db8::/32',    // documentation
        '3fff::/20',        // documentation
        '5f00::/16',        // segment routing
        'fc00::/7',         // unique local (private)
        'fe80::/10',        // link-local
        'fec0::/10',        // site-local (deprecated)
        'ff00::/8',         // multicast
    ];

    /** Whether the text is an IP address the site may fetch from. */
    public static function isPublic(string $ip): bool
    {
        $ip = trim($ip, '[]');
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return !self::inAny($packed, self::IPV4);
        }
        // An IPv4 address within an IPv6 one: mapped (::ffff:a.b.c.d), NAT64 (64:ff9b::/96), 6to4 (2002::/16).
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
            return self::isPublic((string) inet_ntop(substr($packed, 12, 4)));
        }
        if (str_starts_with($packed, "\x20\x02")) {
            return self::isPublic((string) inet_ntop(substr($packed, 2, 4)));
        }
        if (str_starts_with($packed, "\x00\x64\xff\x9b\x00\x01")) {
            return false; // local-use NAT64 (64:ff9b:1::/48)
        }

        return !self::inAny($packed, self::IPV6);
    }

    /** @param list<string> $ranges */
    private static function inAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $net = (string) inet_pton($network);
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (strncmp($packed, $net, $bytes) !== 0) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xff << (8 - $rest)) & 0xff;
            if ((ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
