<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Model\BlueprintRegistry;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\View\Presentation;

/**
 * The admin UI (/admin…). Only users with an admin role may enter
 * (AdminAccess); what they may do with an object is decided by the AccessPolicy.
 *
 * Its templates are always the core ones (@core/admin/…), independent of the
 * public theme, so a broken theme cannot lock anyone out of the admin.
 */
final class AdminController implements Controller
{
    public function __construct(
        private readonly AdminAccess $access,
        private readonly QueryEngine $queries,
        private readonly BlueprintRegistry $blueprints,
        private readonly Presentation $presentation,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        if ($actor->isAnonymous()) {
            return Response::redirect($request->basePath . '/belepes?vissza=' . rawurlencode($this->access->path()));
        }
        if (!$this->access->allows($actor)) {
            throw new HttpException(403, 'error.forbidden');
        }

        $subpath = (string) ($route->params['subpath'] ?? '');
        $response = match ($subpath) {
            '' => $this->dashboard($actor),
            default => throw HttpException::notFound(),
        };

        // Admin pages are never indexed by search engines.
        return $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function dashboard(Actor $actor): Response
    {
        $counts = [];
        foreach ($this->blueprints->all() as $name => $blueprint) {
            $counts[] = [
                'name' => $name,
                'label' => $blueprint->label,
                'count' => $this->queries->count(Query::objects()->blueprint($name), $actor),
            ];
        }
        $recent = $this->queries->execute(Query::objects()->orderBy('updated', 'DESC')->limit(10), $actor);

        return Response::html($this->presentation->render('@core/admin/dashboard.html.twig', [
            'title' => 'admin.dashboard',
            'counts' => $counts,
            'recent' => $recent,
            'blueprints' => $this->blueprints->all(),
        ]));
    }
}
