<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;

/**
 * A controllerek vékonyak: fogadják a kérést, meghívják a Model/Service
 * réteget, és az eredményt átadják a View-nak.
 */
interface Controller
{
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response;
}
