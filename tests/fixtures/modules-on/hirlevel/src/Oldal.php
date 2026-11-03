<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\Access\Actor;
use Campanella\Controller\Controller;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;

/** A public page of a module (a test): /hirlevel. */
final class Oldal implements Controller
{
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        return Response::html('<p>A hírlevél oldala</p>');
    }
}
