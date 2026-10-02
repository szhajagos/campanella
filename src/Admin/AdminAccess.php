<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Http\Request;

/**
 * Who may enter the admin UI, and where it lives.
 *
 * Entering is decided by role (by default administrator and editor). What the
 * user may do inside (create, edit, delete…) is still decided by the
 * AccessPolicy, object by object.
 */
final readonly class AdminAccess
{
    /**
     * @param list<string> $roles The roles that may enter
     * @param list<string> $systemRoles The roles that may open the system page (since 0.0.5)
     */
    public function __construct(
        public string $path = '/admin',
        public array $roles = [Actor::ADMINISTRATOR, 'editor'],
        public array $systemRoles = [Actor::ADMINISTRATOR],
    ) {
        if (preg_match('#^/[a-z0-9][a-z0-9_/-]*$#', $path) !== 1 || str_ends_with($path, '/')) {
            throw new \InvalidArgumentException("Invalid admin path: {$path}");
        }
    }

    public function allows(Actor $actor): bool
    {
        foreach ($this->roles as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the actor may open the system page (versions, extensions, settings):
     * it must be able to enter the admin and have one of the system roles.
     */
    public function allowsSystem(Actor $actor): bool
    {
        if (!$this->allows($actor)) {
            return false;
        }
        foreach ($this->systemRoles as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /** The path of an admin page, relative to the site root (without the URL prefix). */
    public function path(string $subpath = ''): string
    {
        $subpath = trim($subpath, '/');

        return $subpath === '' ? $this->path : $this->path . '/' . $subpath;
    }

    /** Whether the request is for an admin page. */
    public function matches(Request $request): bool
    {
        return $request->path === $this->path || str_starts_with($request->path, $this->path . '/');
    }
}
