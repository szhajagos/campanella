<?php

declare(strict_types=1);

namespace Campanella\Support;

/**
 * UUID v7 (RFC 9562): the first 48 bits are a millisecond timestamp, so
 * the IDs grow in chronological order, which benefits indexes.
 */
final class Uuid
{
    public static function v7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $bytes = pack('J', $ms);                    // 8 bytes, big-endian
        $bytes = substr($bytes, 2) . random_bytes(10); // 6 bytes of time + 10 random bytes

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70); // version: 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant: RFC

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function isValid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) === 1;
    }
}
