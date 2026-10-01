<?php

declare(strict_types=1);

namespace Campanella\Controller;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Admin\AdminAccess;
use Campanella\Admin\Form\ObjectForm;
use Campanella\Http\Flash;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Security\Csrf;
use Campanella\Service\ObjectService;
use Campanella\Http\HttpException;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\RouteMatch;
use Campanella\Capability\PublishStatus;
use Campanella\Support\Slugger;
use Campanella\Http\Router;
use Campanella\Capability\Routable;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintRegistry;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Operator;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\RelationLoader;
use Campanella\View\Presentation;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The admin UI (/admin…). Only users with an admin role may enter
 * (AdminAccess); what they may do with an object is decided by the AccessPolicy.
 *
 * Its templates are always the core ones (@core/admin/…), independent of the
 * public theme, so a broken theme cannot lock anyone out of the admin.
 */
final class AdminController implements Controller
{
    public const int PER_PAGE = 20;

    /** Status filters of the list (for Publishable Blueprints). */
    public const array STATUSES = ['draft', 'published', 'scheduled'];

    public function __construct(
        private readonly AdminAccess $access,
        private readonly QueryEngine $queries,
        private readonly BlueprintRegistry $blueprints,
        private readonly Presentation $presentation,
        private readonly RelationLoader $relations,
        private readonly ObjectRepository $repository,
        private readonly ObjectService $service,
        private readonly AccessPolicy $policy,
        private readonly ObjectForm $form,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Translator $translator,
        private readonly Router $router,
    ) {
    }

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        if ($actor->isAnonymous()) {
            // Back to the requested admin page after logging in.
            $target = $request->path . ($request->query === [] ? '' : '?' . http_build_query($request->query));

            return Response::redirect($request->basePath . '/belepes?vissza=' . rawurlencode($target));
        }
        if (!$this->access->allows($actor)) {
            throw new HttpException(403, 'error.forbidden');
        }

        $segments = array_values(array_filter(explode('/', (string) ($route->params['subpath'] ?? '')), static fn (string $s): bool => $s !== ''));
        $response = match (count($segments)) {
            0 => $this->dashboard($actor),
            1 => $this->listing($request, $actor, $this->contentBlueprint($segments[0])),
            2 => $segments[1] === 'new'
                ? $this->create($request, $actor, $this->contentBlueprint($segments[0]))
                : $this->edit($request, $actor, $this->find($this->contentBlueprint($segments[0]), $segments[1], $actor)),
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

        return $this->render('dashboard', 'dashboard', [
            'title' => 'admin.dashboard',
            'counts' => $counts,
            'recent' => $recent,
        ]);
    }

    /**
     * The list of a Blueprint's objects: /admin/<blueprint>?q=…&status=…&sort=…&dir=…&page=…
     */
    private function listing(Request $request, Actor $actor, Blueprint $blueprint): Response
    {
        $titled = isset($blueprint->capabilities['titled']);
        $publishable = isset($blueprint->capabilities['publishable']);

        $sortable = array_values(array_filter([
            $titled ? 'title' : null,
            'updated',
            'created',
            $publishable ? 'published_at' : null,
        ]));
        $sort = in_array($request->queryString('sort'), $sortable, true) ? $request->queryString('sort') : 'updated';
        $dir = strtolower($request->queryString('dir')) === 'asc' ? 'asc' : 'desc';
        $search = $titled ? trim($request->queryString('q')) : '';
        $status = $publishable && in_array($request->queryString('status'), self::STATUSES, true)
            ? $request->queryString('status') : '';
        $page = max(1, $request->queryInt('page', 1));

        $query = Query::objects()->blueprint($blueprint->name);
        if ($search !== '') {
            $query = $query->where('title', 'LIKE', '%' . addcslashes($search, '%_\\') . '%');
        }
        if ($status !== '') {
            $query = $query->whereCondition(self::statusCondition($status));
        }
        $result = $this->queries->execute(
            $query->orderBy($sort, $dir)->page($page, self::PER_PAGE),
            $actor,
            withTotal: true,
        );
        if (isset($blueprint->capabilities['authorable'])) {
            $this->relations->resolve($result, $actor);
        }

        $editable = [];
        foreach ($result as $item) {
            $editable[(int) $item->id()] = $this->policy->allows($actor, Operation::Update, $item);
        }

        return $this->render('list', $blueprint->name, [
            'editable' => $editable,
            'can_create' => $this->policy->allows($actor, Operation::Create, $this->repository->create($blueprint->name)),
            'title' => $blueprint->label,
            'blueprint' => $blueprint,
            'result' => $result,
            'filters' => ['q' => $search, 'status' => $status, 'sort' => $sort, 'dir' => $dir],
            'sortable' => $sortable,
            'statuses' => $publishable ? self::STATUSES : [],
            'searchable' => $titled,
            'has_author' => isset($blueprint->capabilities['authorable']),
            'path' => $this->access->path($blueprint->name),
        ]);
    }

    /** /admin/<blueprint>/new: the empty form, and creating the object from it. */
    private function create(Request $request, Actor $actor, Blueprint $blueprint): Response
    {
        $object = $this->repository->create($blueprint->name);
        if (!$this->policy->allows($actor, Operation::Create, $object)) {
            throw new HttpException(403, 'error.forbidden');
        }
        if (!$request->isPost()) {
            return $this->formPage($blueprint, $object, $actor);
        }
        if (!$this->csrf->isValid($request)) {
            return $this->formPage($blueprint, $object, $actor, $request->post, [], 'auth.form_expired', 400);
        }

        $read = $this->form->read($object, $request->post, $actor);
        $errors = $read['errors'] + $this->reservedPath($read['values']);
        if ($errors !== []) {
            return $this->formPage($blueprint, $object, $actor, $request->post, $errors, 'admin.form.invalid', 422);
        }
        try {
            $created = $this->service->create($actor, $blueprint->name, $read['values'], relations: $read['relations']);
        } catch (ValidationException $e) {
            return $this->formPage($blueprint, $object, $actor, $request->post, $e->errors, 'admin.form.invalid', 422);
        } catch (AccessDeniedException) {
            throw new HttpException(403, 'error.forbidden');
        }

        $this->flash->add(Flash::SUCCESS, new Message('admin.form.created', ['title' => self::titleOf($created)]));

        return Response::redirect($request->basePath . $this->access->path($blueprint->name . '/' . $created->id()), 303);
    }

    /**
     * /admin/<blueprint>/<id>: the form of an existing object, and saving it.
     *
     * Concurrent edits: the form carries a version token of the object's stored
     * state (versionOf()); if the object was saved by someone else in the
     * meantime, the form is shown again with a warning instead of silently
     * overwriting the other change. Saving again then overwrites it deliberately.
     */
    private function edit(Request $request, Actor $actor, CampanellaObject $object): Response
    {
        $blueprint = $this->contentBlueprint($object->blueprint());
        if (!$this->policy->allows($actor, Operation::Update, $object)) {
            throw new HttpException(403, 'error.forbidden');
        }
        if (!$request->isPost()) {
            return $this->formPage($blueprint, $object, $actor);
        }
        // The submitted version is kept on every re-render except the conflict one, so a
        // stale form never becomes "fresh" by failing for another reason (e.g. CSRF).
        $version = $request->postString('_version');
        if ($version !== $this->versionOf($object)) {
            return $this->formPage($blueprint, $object, $actor, $request->post, [], 'admin.form.conflict', 409);
        }
        if (!$this->csrf->isValid($request)) {
            return $this->formPage($blueprint, $object, $actor, $request->post, [], 'auth.form_expired', 400, $version);
        }

        $read = $this->form->read($object, $request->post, $actor);
        $errors = $read['errors'] + $this->reservedPath($read['values']);
        if ($errors !== []) {
            return $this->formPage($blueprint, $object, $actor, $request->post, $errors, 'admin.form.invalid', 422, $version);
        }
        try {
            $this->service->update($actor, $object, $read['values'], $read['relations']);
        } catch (ValidationException $e) {
            // The object was changed in memory by the failed save: show the stored one.
            $stored = $this->repository->find((int) $object->id()) ?? $object;

            return $this->formPage($blueprint, $stored, $actor, $request->post, $e->errors, 'admin.form.invalid', 422, $version);
        } catch (AccessDeniedException) {
            throw new HttpException(403, 'error.forbidden');
        }

        $this->flash->add(Flash::SUCCESS, new Message('admin.form.saved', ['title' => self::titleOf($object)]));

        return Response::redirect($request->basePath . $this->access->path($blueprint->name . '/' . $object->id()), 303);
    }

    /**
     * @param array<string, mixed>|null $input Submitted values to show again
     * @param array<string, Message> $errors
     */
    private function formPage(
        Blueprint $blueprint,
        CampanellaObject $object,
        Actor $actor,
        ?array $input = null,
        array $errors = [],
        ?string $alert = null,
        int $status = 200,
        ?string $version = null,
    ): Response {
        /** @var array{f?: array<string, mixed>, r?: array<string, mixed>}|null $input */
        $fields = $this->form->build($object, $actor, $input, $errors, $blueprint->formOrder);
        // Errors that do not belong to a form field (e.g. a unique key of a capability).
        $shown = array_map(static fn ($f): string => $f->name, $fields);
        $other = array_diff_key($errors, array_flip($shown));

        $response = $this->render('form', $blueprint->name, [
            // An object's own title is shown as it is (never treated as a message key).
            'title' => $object->isNew() ? 'admin.form.new' : null,
            'title_params' => ['type' => mb_strtolower($this->translator->translate($blueprint->label), 'UTF-8')],
            'title_text' => $object->isNew() ? null : self::titleOf($object),
            'version' => $object->isNew() ? null : ($version ?? $this->versionOf($object)),
            'blueprint' => $blueprint,
            'object' => $object,
            'fields' => $fields,
            'alert' => $alert,
            'other_errors' => $other,
            'action' => $this->access->path($blueprint->name . '/' . ($object->isNew() ? 'new' : (string) $object->id())),
            'list_path' => $this->access->path($blueprint->name),
        ]);

        return $status === 200 ? $response : new Response($response->body, $status, $response->headers);
    }

    /** An object of the Blueprint by ID, if the actor may see it; otherwise 404. */
    private function find(Blueprint $blueprint, string $id, Actor $actor): CampanellaObject
    {
        if (!ctype_digit($id) || (string) (int) $id !== $id) {
            throw HttpException::notFound();
        }

        return $this->queries->first(Query::objects()->blueprint($blueprint->name)->where('id', '=', (int) $id), $actor)
            ?? throw HttpException::notFound();
    }

    /**
     * A token of the object's stored state: its fields, relations and modification
     * time. Any save by someone else changes it, even within the same second.
     */
    private function versionOf(CampanellaObject $object): string
    {
        $state = [];
        foreach ($object->fields() as $name => $field) {
            $state['f'][$name] = $field->toStorage($object->get($name));
        }
        foreach ($object->relations() as $name => $_) {
            $state['r'][$name] = $object->relatedIds($name);
        }
        $state['u'] = $object->updated()->format('U');

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * A path that a fixed route, the admin, or a public folder already uses cannot be
     * an object's path (the object would be unreachable). The path is checked as
     * Routable will store it: given, or made from the title.
     *
     * @param array<string, mixed> $values
     * @return array<string, Message>
     */
    private function reservedPath(array $values): array
    {
        if (!array_key_exists('path', $values)) {
            return [];
        }
        $path = (string) $values['path'];
        if ($path === '') {
            $path = '/' . Slugger::slugify((string) ($values['title'] ?? ''));
        }
        $path = Routable::normalize($path);

        $reserved = $this->router->isRouted($path) || $this->access->matches(new Request('GET', $path))
            || preg_match('#^/(assets|themes)(/|$)#', $path) === 1;

        return $reserved ? ['path' => new Message('validation.path_reserved', ['path' => $path])] : [];
    }

    private static function titleOf(CampanellaObject $object): string
    {
        $title = $object->hasField('title') ? (string) $object->get('title') : '';

        return $title !== '' ? $title : '#' . ($object->id() ?? '');
    }

    /** The condition of a status filter, consistent with the DefaultPolicy's notion of "published". */
    private static function statusCondition(string $status): Group
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $published = new FieldCondition('status', Operator::Equals, PublishStatus::Published);

        return match ($status) {
            'draft' => Group::all(new FieldCondition('status', Operator::Equals, PublishStatus::Draft)),
            'scheduled' => Group::all($published, new FieldCondition('published_at', Operator::GreaterThan, $now)),
            default => Group::all($published, Group::any(
                new FieldCondition('published_at', Operator::IsNull),
                new FieldCondition('published_at', Operator::LessOrEqual, $now),
            )),
        };
    }

    /** A Blueprint managed in the admin as content (users are managed from the command line for now). */
    private function contentBlueprint(string $name): Blueprint
    {
        $blueprint = $this->blueprints->find($name);
        if ($blueprint === null || isset($blueprint->capabilities['authenticatable'])) {
            throw HttpException::notFound();
        }

        return $blueprint;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, string $active, array $context): Response
    {
        $menu = [];
        foreach ($this->blueprints->all() as $name => $blueprint) {
            if (!isset($blueprint->capabilities['authenticatable'])) {
                $menu[] = ['name' => $name, 'label' => $blueprint->label];
            }
        }

        return Response::html($this->presentation->render("@core/admin/{$template}.html.twig", $context + [
            'active' => $active,
            'menu' => $menu,
            'blueprints' => $this->blueprints->all(),
        ]));
    }
}
