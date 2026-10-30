<?php

declare(strict_types=1);

namespace Campanella\Appearance;

use Campanella\Access\Actor;
use Campanella\Controller\Controller;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;

/**
 * The site's colour scheme as CSS (since 0.3.6), at `/color-scheme.css`. Linked with its
 * version (`?v=`, see Appearance::version()), so a browser keeps it for a year and gets the
 * new one when the scheme changes; without it, or with an old one, for five minutes.
 */
final class AppearanceController implements Controller
{
    public const string PATH = '/color-scheme.css';

    public function __construct(private readonly Appearance $appearance)
    {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $current = ($request->query['v'] ?? null) === $this->appearance->version();

        return new Response($this->appearance->css(), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
