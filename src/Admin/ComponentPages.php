<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Admin\Form\ObjectForm;
use Campanella\Capability\Component;
use Campanella\Capability\Keyed;
use Campanella\Capability\Titled;
use Campanella\Controller\AdminController;
use Campanella\Display\ComponentService;
use Campanella\Display\DisplayDefinition;
use Campanella\Display\DisplayException;
use Campanella\Display\DisplayRegistry;
use Campanella\Display\DisplayRenderer;
use Campanella\Http\Flash;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Layout\Conditions;
use Campanella\Layout\LayoutService;
use Campanella\Lists\ListRegistry;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Security\Csrf;
use Campanella\Service\ObjectService;
use Closure;

/**
 * The components in the admin (since 0.3.3), under /admin/component, for the system
 * roles (where something appears on the site is a right above an editor's):
 *
 *   /admin/component                  the list, with the ones that no longer work marked
 *   /admin/component/new/source       1. the source: a system source, the page's object, a list, an object
 *   /admin/component/new/display      2. the displays that can show it (?source=…)
 *   /admin/component/new              3. the form: title, machine name, who sees it, the display's settings
 *   /admin/component/<id>             editing one (?source=…&display=…: with another source or display)
 *   /admin/component/<id>/source      … choosing its source again, and /display its display
 *   /admin/component/<id>/delete      deleting one
 *   /admin/component/regions          the regions of a layout with their components (since 0.3.4,
 *                                     ?layout=…): POST …/regions/add adds one, and POST
 *                                     /admin/component/<id>/move-up, /move-down, /unplace
 *                                     order one or take it out of its region
 *
 * The form (3.) also says where the component shows (since 0.3.4): its region, and its
 * conditions (ComponentService::place()).
 */
final class ComponentPages
{
    /** The most objects offered when choosing an object as the source. */
    public const int MAX_OBJECTS = 50;

    public function __construct(
        private readonly ComponentService $components,
        private readonly DisplayRegistry $displays,
        private readonly ObjectForm $form,
        private readonly ObjectRepository $repository,
        private readonly ObjectService $objects,
        private readonly QueryEngine $queries,
        private readonly BlueprintRegistry $blueprints,
        private readonly ListRegistry $lists,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly AdminAccess $access,
        private readonly ?LayoutService $layouts = null,
        private readonly ?Translator $translator = null,
    ) {
    }

    /** Whether there are components on this site (ComponentService::isAvailable()). */
    public function isAvailable(): bool
    {
        return $this->components->isAvailable();
    }

    /**
     * @param list<string> $segments The path after /admin/component
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function handle(Request $request, Actor $actor, array $segments, Closure $render): Response
    {
        if (!$this->access->allowsSystem($actor)) {
            throw new HttpException(403, 'error.forbidden');
        }
        if ($segments === []) {
            return $this->listing($render, $actor);
        }
        if ($segments[0] === 'regions' && $this->layouts !== null) {
            return match ($segments[1] ?? null) {
                null => $this->regionsPage($request, $actor, $render),
                'add' => $this->addToRegion($request, $actor),
                default => throw HttpException::notFound(),
            };
        }
        $component = $segments[0] === 'new' ? null : $this->find($segments[0], $actor);

        return match ($segments[1] ?? null) {
            null => $this->formPage($request, $actor, $render, $component),
            'source' => $this->sourcePage($request, $actor, $render, $component),
            'display' => $this->displayPage($request, $render, $component),
            'delete' => $component !== null ? $this->delete($request, $actor, $render, $component) : throw HttpException::notFound(),
            'move-up', 'move-down', 'unplace' => $component !== null && $this->layouts !== null ? $this->reorder($request, $actor, $component, $segments[1]) : throw HttpException::notFound(),
            default => throw HttpException::notFound(),
        };
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function listing(Closure $render, Actor $actor): Response
    {
        $items = [];
        foreach ($this->queries->execute(Query::objects()->blueprint(ComponentService::BLUEPRINT)->orderBy('title')->limit(500), $actor) as $component) {
            $definition = $component->as(Component::class);
            $items[] = [
                'object' => $component,
                'key' => $component->as(Keyed::class)->key(),
                'source' => $this->describe($definition->source(), $actor),
                'display' => $this->displayLabel($definition->display()),
                'problem' => $this->components->problem($component),
                'region' => $definition->region() === null ? null : ($this->regionChoices()[$definition->region()] ?? $definition->region()),
            ];
        }

        return $render('components', 'component', ['title' => 'components.title', 'items' => $items, 'regions_url' => $this->layouts !== null]);
    }

    /**
     * 1. The source: the system sources, the page's object, the lists, and an object of a
     * Blueprint (searched by its title).
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function sourcePage(Request $request, Actor $actor, Closure $render, ?CampanellaObject $component): Response
    {
        $blueprints = [];
        foreach ($this->blueprints->all() as $name => $blueprint) {
            if (!AdminController::ownPages($blueprint)) {
                $blueprints[$name] = $blueprint->label;
            }
        }
        $chosen = $request->queryString('blueprint');
        $search = mb_substr(trim($request->queryString('q')), 0, 100, 'UTF-8');
        $found = [];
        if (isset($blueprints[$chosen])) {
            $query = Query::objects()->blueprint($chosen)->orderBy('updated', 'DESC')->limit(self::MAX_OBJECTS);
            if ($search !== '') {
                $query = $query->where('title', 'LIKE', '%' . addcslashes($search, '%_\\') . '%');
            }
            foreach ($this->queries->execute($query, $actor) as $object) {
                $found[] = ['id' => (int) $object->id(), 'title' => self::titleOf($object)];
            }
        }
        $systems = [];
        foreach ($this->displays->sources() as $name => $source) {
            $systems[] = ['source' => 'system:' . $name, 'label' => $source->label()];
        }
        $lists = [];
        foreach ($this->lists->all() as $list) {
            $lists[] = ['source' => 'list:' . $list->name, 'label' => $list->title, 'name' => $list->name];
        }

        return $render('component_source', 'component', [
            'title' => 'components.choose_source',
            'component' => $component,
            'base' => $this->base($component),
            'systems' => $systems,
            'lists' => $lists,
            'blueprints' => $blueprints,
            'chosen' => isset($blueprints[$chosen]) ? $chosen : '',
            'search' => $search,
            'found' => $found,
            'max' => self::MAX_OBJECTS,
        ]);
    }

    /**
     * 2. The displays the chosen source can be shown with.
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function displayPage(Request $request, Closure $render, ?CampanellaObject $component): Response
    {
        $source = $this->source($request);
        $offered = $this->components->displaysFor($source);

        return $render('component_display', 'component', [
            'title' => 'components.choose_display',
            'component' => $component,
            'base' => $this->base($component),
            'source' => $source,
            'source_label' => $this->describe($source, null),
            'displays' => array_map(static fn (DisplayDefinition $d): array => ['name' => $d->name, 'label' => $d->label], $offered),
        ]);
    }

    /**
     * 3. The form: the component's own fields, and the display's settings. Saves it on POST.
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function formPage(Request $request, Actor $actor, Closure $render, ?CampanellaObject $component): Response
    {
        $object = $component ?? $this->repository->create(ComponentService::BLUEPRINT);
        $current = $component?->as(Component::class);
        $source = $request->query['source'] ?? $request->post['source'] ?? $current?->source();
        $display = $request->query['display'] ?? $request->post['display'] ?? $current?->display();
        if (!is_string($source) || !is_string($display) || $source === '' || $display === '') {
            return Response::redirect($request->basePath . $this->access->path($this->base($component) . '/source'), 303);
        }
        $changed = $current === null || $source !== $current->source() || $display !== $current->display();
        $settings = $changed ? [] : $current->settings();
        $errors = [];
        $input = null;
        $status = 200;
        $place = $this->placementForm($current);

        if ($request->isPost()) {
            $place = $this->postedPlacement($request);
            $read = $this->form->read($object, $request->post, $actor);
            // The order within a region is set on the regions' page (0.3.4), not here.
            unset($read['values']['weight'], $read['errors']['weight']);
            $settings = is_array($request->post['s'] ?? null) ? array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $request->post['s']) : [];
            $input = ['f' => is_array($request->post['f'] ?? null) ? $request->post['f'] : []];
            $errors = $read['errors'];
            $where = Conditions::fromText($place['paths'], $place['blueprints'], $place['front'], $place['except']);
            if ($this->layouts !== null) {
                $errors += $this->components->placementProblems($place['region'] === '' ? null : $place['region'], $where);
            }
            if (!$this->csrf->isValid($request)) {
                $errors['form'] = new Message('auth.form_expired');
                $status = 400;
            } elseif ($errors === []) {
                try {
                    $oldRegion = $current?->region();
                    if ($component === null) {
                        $component = $this->components->create($actor, $read['values'], $source, $display, $settings);
                    } else {
                        $this->components->update($actor, $component, $read['values'], $source, $display, $settings);
                    }
                    if ($this->layouts !== null) {
                        // A component put into another region goes to its end.
                        $region = $place['region'] === '' ? null : $place['region'];
                        $weight = $region !== null && $region !== $oldRegion ? $this->components->nextWeight($region, $actor) : null;
                        $this->components->place($actor, $component, $region, $where, $weight);
                    }
                    $this->flash->add(Flash::SUCCESS, new Message('components.saved', ['title' => self::titleOf($component)]));

                    return Response::redirect($request->basePath . $this->access->path('component'), 303);
                } catch (ValidationException $e) {
                    $errors = $e->errors;
                }
            }
            $status = $status === 200 ? 422 : $status;
        }

        try {
            $definition = $this->displays->definition($display);
        } catch (DisplayException) {
            $definition = null;
            $errors['display'] ??= new Message('components.display_missing', ['display' => $display]);
        }
        $settingErrors = array_intersect_key($errors, $definition->settings ?? []);
        $placeErrors = array_intersect_key($errors, array_flip(['region', 'paths', 'except', 'blueprints']));
        $values = $request->isPost() ? $settings : ($definition === null ? [] : $this->defaults($definition, $settings));
        $response = $render('component_form', 'component', [
            'title' => $component === null ? 'components.new' : 'components.edit',
            'component' => $component,
            'base' => $this->base($component),
            'source' => $source,
            'display' => $display,
            'source_label' => $this->describe($source, $actor),
            'display_label' => $definition->label ?? $display,
            'fields' => array_values(array_filter(
                $this->form->build($object, $actor, $input, $errors, $this->blueprints->get(ComponentService::BLUEPRINT)->formOrder),
                static fn ($field): bool => $field->name !== 'weight',
            )),
            'settings' => $definition === null ? [] : $this->form->settings($definition->settings, $values, $settingErrors),
            'errors' => array_diff_key($errors, $settingErrors, $placeErrors, $object->fields()),
            'place' => $this->layouts === null ? null : $place + ['errors' => $placeErrors, 'regions' => $this->regionChoices(), 'pages' => $this->pageBlueprints()],
            'problem' => $component === null || $changed ? null : $this->components->problem($component),
        ]);

        return $status === 200 ? $response : new Response($response->body, $status, $response->headers);
    }

    /** @param Closure(string, string, array<string, mixed>): Response $render */
    private function delete(Request $request, Actor $actor, Closure $render, CampanellaObject $component): Response
    {
        if ($request->isPost()) {
            if (!$this->csrf->isValid($request)) {
                $this->flash->add(Flash::DANGER, 'auth.form_expired');
            } else {
                $this->objects->delete($actor, $component);
                $this->flash->add(Flash::SUCCESS, new Message('components.deleted', ['title' => self::titleOf($component)]));
            }

            return Response::redirect($request->basePath . $this->access->path('component'), 303);
        }

        return $render('component_delete', 'component', [
            'title' => 'components.delete',
            'component' => $component,
            'key' => $component->as(Keyed::class)->key(),
        ]);
    }

    /**
     * The regions of a layout with their components (since 0.3.4): the site's layout, or the
     * one asked for (?layout=). Each region's components in their order, with their
     * conditions in words, and the components that can be added to it.
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    private function regionsPage(Request $request, Actor $actor, Closure $render): Response
    {
        $layouts = $this->layouts ?? throw HttpException::notFound();
        $asked = $request->queryString('layout');
        $layout = $layouts->registry()->has($asked) ? $layouts->registry()->get($asked) : $layouts->site();
        $all = $this->queries->execute(Query::objects()->blueprint(ComponentService::BLUEPRINT)->orderBy('title')->limit(500), $actor)->items;
        $regions = [];
        foreach ($layout->regions as $name => $region) {
            $placed = $this->components->inRegion($name, $actor);
            $ids = array_map(static fn (CampanellaObject $c): int => (int) $c->id(), $placed);
            $regions[] = [
                'name' => $name,
                'label' => $region->label,
                'components' => array_map(fn (CampanellaObject $c): array => [
                    'object' => $c,
                    'display' => $this->displayLabel($c->as(Component::class)->display()),
                    'where' => $this->whereText($c->as(Component::class)->conditions()),
                    'problem' => $this->components->problem($c),
                ], $placed),
                'addable' => array_values(array_map(static fn (CampanellaObject $c): array => [
                    'id' => (int) $c->id(),
                    'title' => self::titleOf($c),
                    'region' => $c->as(Component::class)->region(),
                ], array_filter($all, static fn (CampanellaObject $c): bool => !in_array((int) $c->id(), $ids, true)))),
            ];
        }

        return $render('component_regions', 'regions', [
            'title' => 'regions.title',
            'layout' => ['name' => $layout->name, 'label' => $layout->label],
            'layouts' => array_map(static fn ($l): array => ['name' => $l->name, 'label' => $l->label], array_values($layouts->registry()->all())),
            'regions' => $regions,
            'region_labels' => $this->regionChoices(),
            'site_layout' => $layouts->site()->name,
        ]);
    }

    /** POST: adds a component to a region, at its end (it leaves the region it was in). */
    private function addToRegion(Request $request, Actor $actor): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $back = $this->regionsPath($request);
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return Response::redirect($back, 303);
        }
        $component = $this->find($request->postString('component'), $actor);
        $region = $request->postString('region');
        try {
            $this->components->place($actor, $component, $region, $component->as(Component::class)->conditions(), $this->components->nextWeight($region, $actor));
            $label = $this->regionChoices()[$region] ?? $region;
            $this->flash->add(Flash::SUCCESS, new Message('regions.added', ['title' => self::titleOf($component), 'region' => $this->translator?->translate($label) ?? $label]));
        } catch (ValidationException $e) {
            $this->flash->add(Flash::DANGER, array_values($e->errors)[0] ?? 'admin.form.invalid');
        }

        return Response::redirect($back, 303);
    }

    /** POST: moves a component up or down in its region, or takes it out. */
    private function reorder(Request $request, Actor $actor, CampanellaObject $component, string $action): Response
    {
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $back = $this->regionsPath($request);
        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return Response::redirect($back, 303);
        }
        if ($action === 'unplace') {
            $this->components->place($actor, $component, null, $component->as(Component::class)->conditions());
            $this->flash->add(Flash::SUCCESS, new Message('regions.removed', ['title' => self::titleOf($component)]));
        } else {
            $this->components->move($actor, $component, $action === 'move-up' ? -1 : 1);
        }

        return Response::redirect($back, 303);
    }

    /** Back to the regions page, of the layout the form was sent from. */
    private function regionsPath(Request $request): string
    {
        $layout = $request->postString('layout');
        $known = $this->layouts !== null && $this->layouts->registry()->has($layout);

        return $request->basePath . $this->access->path('component/regions') . ($known ? '?layout=' . rawurlencode($layout) : '');
    }

    /**
     * The regions a component can be placed in: name => label (that of the first layout that has it).
     *
     * @return array<string, string>
     */
    private function regionChoices(): array
    {
        $choices = [];
        foreach ($this->layouts?->registry()->all() ?? [] as $layout) {
            foreach ($layout->regions as $name => $region) {
                $choices[$name] ??= $region->label;
            }
        }

        return $choices;
    }

    /** @return array<string, string> The Blueprints whose objects have pages: name => label */
    private function pageBlueprints(): array
    {
        $pages = [];
        foreach ($this->blueprints->all() as $name => $blueprint) {
            if (isset($blueprint->capabilities['routable'])) {
                $pages[$name] = $blueprint->label;
            }
        }

        return $pages;
    }

    /** @return array{region: string, paths: string, except: string, blueprints: list<string>, front: bool} */
    private function placementForm(?Component $current): array
    {
        $where = $current?->conditions() ?? Conditions::everywhere();

        return [
            'region' => (string) $current?->region(),
            'paths' => implode("\n", $where->paths),
            'except' => implode("\n", $where->except),
            'blueprints' => $where->blueprints,
            'front' => $where->front,
        ];
    }

    /** @return array{region: string, paths: string, except: string, blueprints: list<string>, front: bool} */
    private function postedPlacement(Request $request): array
    {
        $sent = is_array($request->post['place'] ?? null) ? $request->post['place'] : [];
        $text = static fn (string $key): string => is_string($sent[$key] ?? null) ? mb_substr($sent[$key], 0, 10000, 'UTF-8') : '';
        $blueprints = is_array($sent['blueprints'] ?? null) ? array_values(array_filter($sent['blueprints'], 'is_string')) : [];

        return [
            'region' => $text('region'),
            'paths' => $text('paths'),
            'except' => $text('except'),
            'blueprints' => array_slice($blueprints, 0, Conditions::MAX + 1),
            'front' => ($sent['front'] ?? null) === '1',
        ];
    }

    /**
     * A component's conditions in words, for the regions page: null if everywhere.
     *
     * @return list<array{key: string, params: array<string, string>}>|null
     */
    private function whereText(Conditions $where): ?array
    {
        if ($where->isEverywhere()) {
            return null;
        }
        $parts = [];
        if ($where->front) {
            $parts[] = ['key' => 'regions.where.front', 'params' => []];
        }
        if ($where->paths !== []) {
            $parts[] = ['key' => 'regions.where.paths', 'params' => ['paths' => implode(', ', $where->paths)]];
        }
        if ($where->blueprints !== []) {
            $labels = $this->pageBlueprints();
            $names = array_map(fn (string $name): string => isset($labels[$name]) ? ($this->translator?->translate($labels[$name]) ?? $labels[$name]) : $name, $where->blueprints);
            $parts[] = ['key' => 'regions.where.blueprints', 'params' => ['blueprints' => implode(', ', $names)]];
        }
        if ($where->except !== []) {
            $parts[] = ['key' => 'regions.where.except', 'params' => ['paths' => implode(', ', $where->except)]];
        }

        return $parts;
    }

    /**
     * The settings to show in the form: the stored ones, the defaults for the rest.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function defaults(DisplayDefinition $definition, array $stored): array
    {
        $values = [];
        foreach ($definition->settings as $name => $field) {
            $values[$name] = array_key_exists($name, $stored) ? $stored[$name] : $field->default;
        }

        return $values;
    }

    /** A source in words, for the admin: "Main menu (Menu)", "The visitor", "List: Events". */
    private function describe(string $source, ?Actor $actor): string
    {
        try {
            [$kind, $name] = DisplayRenderer::parseSource($source);
        } catch (DisplayException) {
            return $source;
        }

        return match ($kind) {
            'current' => 'components.source.current',
            'list' => $this->lists->find($name)->title ?? $source,
            'object' => ($object = $this->repository->find((int) $name)) === null ? $source : self::titleOf($object) . ' (#' . $name . ')',
            default => $this->displays->hasSource($name) ? $this->displays->source($name)->label() : $source,
        };
    }

    private function displayLabel(string $display): string
    {
        return $this->displays->has($display) ? $this->displays->definition($display)->label : $display;
    }

    private function source(Request $request): string
    {
        $source = $request->queryString('source');
        try {
            DisplayRenderer::parseSource($source);
        } catch (DisplayException) {
            throw HttpException::notFound();
        }

        return $source;
    }

    /** The admin path of the component's steps: `component/new` or `component/12`. */
    private function base(?CampanellaObject $component): string
    {
        return 'component/' . ($component === null ? 'new' : (int) $component->id());
    }

    private function find(string $id, Actor $actor): CampanellaObject
    {
        if (!ctype_digit($id)) {
            throw HttpException::notFound();
        }

        return $this->queries->first(Query::objects()->blueprint(ComponentService::BLUEPRINT)->where('id', '=', (int) $id), $actor)
            ?? throw HttpException::notFound();
    }

    private static function titleOf(CampanellaObject $object): string
    {
        $title = $object->has(Titled::class) ? $object->as(Titled::class)->title() : '';

        return $title !== '' ? $title : '#' . $object->id();
    }
}
