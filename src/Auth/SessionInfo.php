<?php

declare(strict_types=1);

namespace Campanella\Auth;

/**
 * One login in progress, as the user's session list shows it (since 0.1.4).
 * The times are UTC.
 */
final readonly class SessionInfo
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastSeenAt,
        public string $ip,
        public string $userAgent,
        public bool $current,
    ) {
    }

    /** The browser's name from the User-Agent ('Firefox', 'Chrome' …), or '' if not recognized. */
    public function browser(): string
    {
        return self::browserOf($this->userAgent);
    }

    /** The operating system's name from the User-Agent ('Windows', 'Android' …), or '' if not recognized. */
    public function system(): string
    {
        return self::systemOf($this->userAgent);
    }

    /** A browser's name from a User-Agent header (a rough guess: the header can say anything). */
    public static function browserOf(string $userAgent): string
    {
        // The order matters: Edge and Opera say Chrome too, Chrome says Safari too.
        foreach ([
            'Edg/' => 'Edge', 'EdgA/' => 'Edge', 'EdgiOS/' => 'Edge',
            'OPR/' => 'Opera', 'Opera' => 'Opera',
            'SamsungBrowser/' => 'Samsung Internet',
            'Vivaldi/' => 'Vivaldi',
            'Firefox/' => 'Firefox', 'FxiOS/' => 'Firefox',
            'CriOS/' => 'Chrome', 'Chrome/' => 'Chrome', 'Chromium/' => 'Chromium',
            'Safari/' => 'Safari',
            'curl/' => 'curl',
        ] as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return '';
    }

    /** An operating system's name from a User-Agent header. */
    public static function systemOf(string $userAgent): string
    {
        foreach ([
            'Windows' => 'Windows',
            'iPhone' => 'iOS', 'iPad' => 'iPadOS',
            'Android' => 'Android',
            'CrOS' => 'ChromeOS',
            'Mac OS X' => 'macOS', 'Macintosh' => 'macOS',
            'Linux' => 'Linux',
        ] as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return '';
    }
}
