<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\Access\Actor;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Module\AdminPage;
use Closure;

/** The admin page of a module (a test): /admin/module/hirlevel. */
final class Admin implements AdminPage
{
    public function handle(Request $request, Actor $actor, array $segments, Closure $render): Response
    {
        if ($request->isPost()) {
            return Response::redirect('/admin/module/hirlevel?sent=1', 303);
        }

        return $render('admin.html.twig', ['title' => 'hirlevel.admin', 'segments' => $segments]);
    }
}
