<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;

/**
 * Controllers are thin: they receive the request, call the Model/Service
 * layer, and pass the result to the View.
 */
interface Controller
{
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response;
}
