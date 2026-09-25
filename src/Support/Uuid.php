<?php

declare(strict_types=1);

namespace Campanella\Support;

/**
 * UUID v7 (RFC 9562): az első 48 bit ezredmásodperces időbélyeg, így az
 * azonosítók időrendben nőnek, ami az indexeknek kedvez.
 */
final class Uuid
{
    public static function v7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $bytes = pack('J', $ms);                    // 8 bájt, big-endian
        $bytes = substr($bytes, 2) . random_bytes(10); // 6 bájt idő + 10 bájt véletlen

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70); // verzió: 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variáns: RFC

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
