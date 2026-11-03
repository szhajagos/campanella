<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\Access\Actor;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Closure;

/**
 * A module's page in the admin (since 0.4.7), under /admin/module/<name>/…, registered with
 * ModuleContext::adminPage(). The admin checks who may open it (the system roles, or those
 * who may enter the admin) and, for a POST, the CSRF token, before handle() is called.
 *
 * `$render(string $template, array $context)` renders the module's template
 * (`@<name>/<template>`, which extends `@core/admin/base.html.twig`) in the admin's frame.
 */
interface AdminPage
{
    /**
     * @param list<string> $segments The path after /admin/module/<name>
     * @param Closure(string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, Actor $actor, array $segments, Closure $render): Response;
}
