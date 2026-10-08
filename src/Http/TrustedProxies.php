<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * Behind a reverse proxy (a load balancer, a CDN, a Docker host's web server) the
 * request arrives from the proxy: PHP sees the proxy's address and plain HTTP. The
 * proxy tells the truth in `X-Forwarded-For` and `X-Forwarded-Proto`, but anyone
 * can send those headers, so they are believed only from the proxies listed in the
 * `trusted_proxies` setting (IP addresses or CIDR ranges, IPv4 or IPv6). Since 0.1.0.
 *
 *     'trusted_proxies' => ['127.0.0.1', '10.0.0.0/8', 'fd00::/8'],
 *
 * The visitor's address is the last address in `X-Forwarded-For` that is not a
 * trusted proxy (the ones before it could be made up by the visitor). Empty list
 * (the default): the headers are ignored.
 */
final class TrustedProxies
{
    /** @var list<string> */
    private readonly array $ranges;

    /** @param list<mixed> $ranges IP addresses or CIDR ranges */
    public function __construct(array $ranges = [])
    {
        $valid = [];
        foreach ($ranges as $range) {
            if (!is_string($range) || !self::isValidRange(trim($range))) {
                throw new \InvalidArgumentException('trusted_proxies: not an IP address or CIDR range: ' . (is_string($range) ? $range : get_debug_type($range)));
            }
            $valid[] = trim($range);
        }
        $this->ranges = $valid;
    }

    /** @return list<string> */
    public function ranges(): array
    {
        return $this->ranges;
    }

    /** The request as the visitor sent it: their address, and whether they used HTTPS. */
    public function apply(Request $request): Request
    {
        if ($this->ranges === [] || !$this->isTrusted($request->ip)) {
            return $request;
        }

        $ip = $request->ip;
        $forwarded = array_reverse(array_map('trim', explode(',', $request->headers['x-forwarded-for'] ?? '')));
        foreach ($forwarded as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                break; // a broken chain: the last good address stays
            }
            $ip = $address;
            if (!$this->isTrusted($address)) {
                break;
            }
        }

        $secure = $request->secure;
        $proto = strtolower(trim(explode(',', $request->headers['x-forwarded-proto'] ?? '')[0]));
        if ($proto === 'https') {
            $secure = true;
        } elseif ($proto === 'http') {
            $secure = false;
        }

        return $request->withClient($ip, $secure);
    }

    public function isTrusted(string $ip): bool
    {
        foreach ($this->ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function inRange(string $ip, string $range): bool
    {
        $parts = explode('/', $range, 2);
        $network = $parts[0];
        $bits = $parts[1] ?? null;
        $address = @inet_pton($ip);
        $base = @inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $length = strlen($address) * 8;
        $bits = $bits === null ? $length : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = 0xff << (8 - $rest) & 0xff;

        return (ord($address[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }

    private static function isValidRange(string $range): bool
    {
        $parts = explode('/', $range, 2);
        $network = $parts[0];
        $bits = $parts[1] ?? null;
        $packed = @inet_pton($network);
        if ($packed === false) {
            return false;
        }

        return $bits === null || (ctype_digit($bits) && (int) $bits <= strlen($packed) * 8);
    }
}
