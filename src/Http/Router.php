<?php

declare(strict_types=1);

namespace Campanella\Http;

/**
 * Maps the request to a resource.
 *
 * Order:
 *   1. the static routes of config/routes.php (e.g. /hirek → Query);
 *   2. any other path may belong to a Routable object (→ ObjectController).
 *
 * The Router does not query the database: whether there really is an object
 * behind a path is decided by ObjectController (together with access control).
 */
final class Router
{
    /** @var array<string, RouteMatch> */
    private array $routes = [];

    /** @param array<string, array{0: string, 1?: array<string, mixed>}> $routes path => [handler, parameters] */
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
