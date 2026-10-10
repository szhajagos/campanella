<?php

declare(strict_types=1);

namespace Campanella\Http;

use Campanella\Capability\Routable;

/**
 * The addresses of the system's own public pages (since 0.1.4): logging in and out
 * and the forgotten password. English by default, any of them can be changed in the
 * configuration, e.g. to the site's language:
 *
 *     'paths' => ['login' => '/belepes', 'logout' => '/kilepes', 'password_reset' => '/elfelejtett-jelszo'],
 *
 * Templates get them with path('login'). The admin's own address is `admin.path`
 * (AdminAccess).
 */
final class SitePaths
{
    public const array DEFAULTS = [
        'login' => '/login',
        'logout' => '/logout',
        'password_reset' => '/password-reset',
    ];

    /** @var array<string, string> */
    private readonly array $paths;

    /**
     * @param array<mixed> $paths The `paths` setting: name => path (the missing ones: DEFAULTS)
     * @throws \InvalidArgumentException for an unknown name, an unusable path, or two names with one path
     */
    public function __construct(array $paths = [])
    {
        $result = self::DEFAULTS;
        foreach ($paths as $name => $path) {
            if (!is_string($name) || !isset(self::DEFAULTS[$name])) {
                throw new \InvalidArgumentException('paths: unknown name: ' . var_export($name, true) . ' (known: ' . implode(', ', array_keys(self::DEFAULTS)) . ')');
            }
            if (!is_string($path) || !Routable::isSafePath(trim($path)) || Request::normalizePath($path) === '/') {
                throw new \InvalidArgumentException("paths.{$name}: not a usable path: " . var_export($path, true));
            }
            $result[$name] = Request::normalizePath($path);
        }
        if (count(array_unique($result)) !== count($result)) {
            throw new \InvalidArgumentException('paths: two pages cannot have the same path');
        }
        $this->paths = $result;
    }

    /** A page's path, e.g. `/login`. */
    public function get(string $name): string
    {
        return $this->paths[$name] ?? throw new \InvalidArgumentException("Unknown path: {$name}");
    }

    /** @return array<string, string> name => path */
    public function all(): array
    {
        return $this->paths;
    }

    /** The login page that sends back to a path of the site afterwards (`/login?return=%2Fadmin`). */
    public function login(string $return = ''): string
    {
        return $this->get('login') . ($return === '' ? '' : '?return=' . rawurlencode($return));
    }
}
