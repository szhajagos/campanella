<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Capability\Component;
use Campanella\Capability\Composed;
use Campanella\Capability\Titled;
use Campanella\Display\ComponentService;
use Campanella\Display\DisplayRegistry;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Layout\LayoutService;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ValidationException;
use Campanella\Security\Csrf;
use Campanella\Service\ObjectService;
use Closure;

/**
 * A page's own components in the admin (since 0.3.5), under the page's address, for those
 * who may change the page (editors too):
 *
 *   /admin/<blueprint>/<id>/components                  its layout, its regions with their components
 *   POST …/components/layout                            its own layout
 *   …/components/new/source, /display, /new             a new component of the page (ComponentPages)
 *   POST …/components/<component>/move-up, /move-down   its order within the region
 *
 * A component is changed and deleted at its own address (`/admin/component/<id>`), which
 * leads back here.
 */
final class PageComponentPages
{
    public function __construct(
        private readonly ComponentService $components,
        private readonly ComponentPages $pages,
        private readonly LayoutService $layouts,
        private readonly ObjectService $objects,
        private readonly DisplayRegistry $displays,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
    ) {
    }

    /** Whether the object's page can have components of its own (it is Composed, and there are components). */
    public function isFor(CampanellaObject $object): bool
    {
        return !$object->isNew() && $object->has(Composed::class) && $this->components->isAvailable();
    }

    /** How many components of its own the page has (for its form's link). */
    public function count(CampanellaObject $page, Actor $actor): int
    {
        return array_sum(array_map(count(...), $this->components->ofPage($page, $actor)));
    }

    /**
     * @param list<string> $segments The path after /admin/<blueprint>/<id>/components
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, Actor $actor, CampanellaObject $page, array $segments, Closure $render): Response
    {
        if (!$this->isFor($page)) {
            throw HttpException::notFound();
        }
        if ($this->components->pageProblem($actor, $page) !== null) {
            throw new HttpException(403, 'error.forbidden');
        }
        if (($segments[0] ?? null) === 'new') {
            return $this->pages->handle($request, $actor, $segments, $render, $page);
        }

        return match (true) {
            $segments === [] => $this->overview($request, $actor, $page, $render),
            $segments === ['layout'] => $this->saveLayout($request, $actor, $page),
            count($segments) === 2 && ctype_digit($segments[0]) && in_array($segments[1], ['move-up', 'move-down'], true)
                => $this->move($request, $actor, $page, (int) $segments[0], $segments[1] === 'move-up' ? -1 : 1),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function overview(Request $request, Actor $actor, CampanellaObject $page, Closure $render): Response
    {
        $layout = $this->layouts->forPage($page);
        $own = $this->components->ofPage($page, $actor);
        $regions = [];
        foreach ($layout->regions as $name => $region) {
            if (!$region->page) {
                continue;
            }
            $regions[] = [
                'name' => $name,
                'label' => $region->label,
                'components' => array_map(fn (CampanellaObject $c): array => [
                    'object' => $c,
                    'display' => $this->displayLabel($c->as(Component::class)->display()),
                    'problem' => $this->components->problem($c),
                ], $own[$name] ?? []),
            ];
        }
        // Its own components in a region its layout has not (e.g. after changing the layout): shown, to be moved.
        $elsewhere = [];
        foreach ($own as $region => $list) {
            if (!isset($layout->regions[$region]) || !$layout->regions[$region]->page) {
                foreach ($list as $c) {
                    $elsewhere[] = ['object' => $c, 'region' => $region];
                }
            }
        }

        return $render('page_components', $page->blueprint(), [
            'title' => 'page_components.title',
            'page' => $page,
            'page_title' => self::titleOf($page),
            'base' => ComponentPages::pagePath($page),
            'edit_path' => $page->blueprint() . '/' . (int) $page->id(),
            'layout' => ['name' => $layout->name, 'label' => $layout->label],
            'own_layout' => $page->as(Composed::class)->layout(),
            'layouts' => array_map(static fn ($l): array => ['name' => $l->name, 'label' => $l->label], array_values($this->layouts->registry()->all())),
            'inherited' => $this->layouts->forBlueprint($page->blueprint())->label,
            'regions' => $regions,
            'elsewhere' => $elsewhere,
        ]);
    }

    /** POST: the page's own layout ('' for its Blueprint's or the site's). */
    private function saveLayout(Request $request, Actor $actor, CampanellaObject $page): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $back = Response::redirect($request->basePath . $this->access->path(ComponentPages::pagePath($page)), 303);
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $back;
        }
        $layout = $request->postString('layout');
        if ($layout !== '' && !$this->layouts->registry()->has($layout)) {
            $this->flash->add(Flash::DANGER, 'layouts.unknown');

            return $back;
        }
        try {
            $this->objects->update($actor, $page, ['page_layout' => $layout === '' ? null : $layout]);
            $this->flash->add(Flash::SUCCESS, 'page_components.layout_saved');
        } catch (ValidationException $e) {
            $this->flash->add(Flash::DANGER, array_values($e->errors)[0] ?? 'admin.form.invalid');
        }

        return $back;
    }

    /** POST: one of the page's own up or down within its region. */
    private function move(Request $request, Actor $actor, CampanellaObject $page, int $id, int $by): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $component = null;
        foreach ($this->components->ofPage($page, $actor) as $list) {
            foreach ($list as $candidate) {
                if ((int) $candidate->id() === $id) {
                    $component = $candidate;
                }
            }
        }
        if ($component === null) {
            throw HttpException::notFound();
        }
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');
        } else {
            $this->components->move($actor, $component, $by);
        }

        return Response::redirect($request->basePath . $this->access->path(ComponentPages::pagePath($page)) . '#region-' . $component->as(Component::class)->region(), 303);
    }

    private function displayLabel(string $display): string
    {
        return $this->displays->has($display) ? $this->displays->definition($display)->label : $display;
    }

    private static function titleOf(CampanellaObject $object): string
    {
        $title = $object->has(Titled::class) ? $object->as(Titled::class)->title() : '';

        return $title !== '' ? $title : '#' . $object->id();
    }
}
