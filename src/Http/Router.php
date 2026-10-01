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

    /** @var array<string, RouteMatch> prefix => route, longest prefix first */
    private array $prefixes = [];

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

    /**
     * A route for a path and everything below it (e.g. /admin, /admin/article/12).
     * The rest of the path after the prefix is passed as the 'subpath' parameter
     * ('' for the prefix itself, otherwise e.g. 'article/12').
     *
     * @param array<string, mixed> $params
     */
    public function prefix(string $prefix, string $handler, array $params = []): void
    {
        $this->prefixes[Request::normalizePath($prefix)] = new RouteMatch($handler, $params);
        uksort($this->prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public function match(Request $request): RouteMatch
    {
        if (isset($this->routes[$request->path])) {
            return $this->routes[$request->path];
        }
        foreach ($this->prefixes as $prefix => $route) {
            if ($request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
                return new RouteMatch($route->handler, $route->params + [
                    'subpath' => trim(substr($request->path, strlen($prefix)), '/'),
                ]);
            }
        }

        return new RouteMatch('object', ['path' => $request->path]);
    }
}
