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
        $component = $segments[0] === 'new' ? null : $this->find($segments[0], $actor);

        return match ($segments[1] ?? null) {
            null => $this->formPage($request, $actor, $render, $component),
            'source' => $this->sourcePage($request, $actor, $render, $component),
            'display' => $this->displayPage($request, $render, $component),
            'delete' => $component !== null ? $this->delete($request, $actor, $render, $component) : throw HttpException::notFound(),
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
            ];
        }

        return $render('components', 'component', ['title' => 'components.title', 'items' => $items]);
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

        if ($request->isPost()) {
            $read = $this->form->read($object, $request->post, $actor);
            $settings = is_array($request->post['s'] ?? null) ? array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $request->post['s']) : [];
            $input = ['f' => is_array($request->post['f'] ?? null) ? $request->post['f'] : []];
            $errors = $read['errors'];
            if (!$this->csrf->isValid($request)) {
                $errors['form'] = new Message('auth.form_expired');
                $status = 400;
            } elseif ($errors === []) {
                try {
                    if ($component === null) {
                        $component = $this->components->create($actor, $read['values'], $source, $display, $settings);
                    } else {
                        $this->components->update($actor, $component, $read['values'], $source, $display, $settings);
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
        $values = $request->isPost() ? $settings : ($definition === null ? [] : $this->defaults($definition, $settings));
        $response = $render('component_form', 'component', [
            'title' => $component === null ? 'components.new' : 'components.edit',
            'component' => $component,
            'base' => $this->base($component),
            'source' => $source,
            'display' => $display,
            'source_label' => $this->describe($source, $actor),
            'display_label' => $definition->label ?? $display,
            'fields' => $this->form->build($object, $actor, $input, $errors, $this->blueprints->get(ComponentService::BLUEPRINT)->formOrder),
            'settings' => $definition === null ? [] : $this->form->settings($definition->settings, $values, $settingErrors),
            'errors' => array_diff_key($errors, $settingErrors, $object->fields()),
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
