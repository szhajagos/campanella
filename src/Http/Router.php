<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * A kérést egy erőforráshoz rendeli.
 *
 * Sorrend:
 *   1. a config/routes.php statikus útvonalai (pl. /hirek → Query);
 *   2. minden más út egy Routable objektumé lehet (→ ObjectController).
 *
 * A Router nem kérdez adatbázist: azt, hogy egy út mögött tényleg van-e
 * objektum, az ObjectController dönti el (jogosultsággal együtt).
 */
final class Router
{
    /** @var array<string, RouteMatch> */
    private array $routes = [];

    /** @param array<string, array{0: string, 1?: array<string, mixed>}> $routes útvonal => [handler, paraméterek] */
    public function __construct(array $routes = [])
    {
        foreach ($routes as $path => $route) {
            $this->add($path, $route[0], $route[1] ?? []);
        }
    }

    /** @param array<string, mixed> $params */
    public function add(string $path, string $handler, array $params = []): void
    {
        $this->routes[Request::normalizePath($path)] = new RouteMatch($handler, $params);
    }

    public function match(Request $request): RouteMatch
    {
        return $this->routes[$request->path]
            ?? new RouteMatch('object', ['path' => $request->path]);
    }
}
