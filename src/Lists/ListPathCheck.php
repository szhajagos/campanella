<?php

declare(strict_types=1);

namespace Campanella\Lists;

use Campanella\Admin\AdminAccess;
use Campanella\Database\Connection;
use Campanella\Database\Schema\SchemaReader;
use Campanella\Http\Request;
use Campanella\Http\Router;

/**
 * Whether a list's path is taken (since 0.2.5; a class of its own since 0.2.6, for
 * the editor and the import): by another route (a page, another list), the admin,
 * a public folder (`/assets`, `/themes`, `/media`) or an object. The list's own
 * route is not "taken".
 */
final class ListPathCheck
{
    public function __construct(
        private readonly Router $router,
        private readonly AdminAccess $access,
        private readonly Connection $db,
    ) {
    }

    public function isTaken(string $path, string $name): bool
    {
        $route = $this->router->match(new Request('GET', $path));
        $ownRoute = $route->handler === 'list' && ($route->params['list'] ?? null) === $name;
        if ($this->router->isRouted($path) && !$ownRoute) {
            return true;
        }
        if ($this->access->matches(new Request('GET', $path)) || preg_match('#^/(assets|themes|media)(/|$)#', $path) === 1) {
            return true;
        }
        if ((new SchemaReader($this->db))->tableExists('cap_routable')) {
            return $this->db->fetchValue('SELECT 1 FROM {cap_routable} WHERE path = :p', ['p' => $path]) !== null;
        }

        return false;
    }
}
