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
use Campanella\Capability\Publishable;
use Campanella\Capability\TextFormat;
use Campanella\Capability\Textual;
use Campanella\Html\PlainText;
use Campanella\Media\MediaService;
use Campanella\Capability\PublishStatus;
use Campanella\Support\Slugger;
use Campanella\System\CheckStatus;
use Campanella\System\SystemCheck;
use Campanella\System\TemplateCache;
use Campanella\Http\Router;
use Campanella\Capability\Routable;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintRegistry;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Operator;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\Relation;
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

    /** How times are shown in the admin (in the site's time zone). */
    public const string DISPLAY_DATETIME = 'Y-m-d H:i';

    /**
     * The Content-Security-Policy of the admin pages: only our own scripts (no inline
     * script, no other server), so even HTML that got past the filter could not run code
     * here. Styles may be inline, because the editor (Jodit) creates style elements.
     */
    public const string CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; frame-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** The most referring items listed on the delete confirmation page. */
    public const int MAX_REFERRERS = 20;

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
        private readonly SystemCheck $system,
        private readonly TemplateCache $templateCache,
        private readonly MediaService $media,
    ) {
    }

    /** The actor of the current request (for the menu). */
    private ?Actor $actor = null;

    #[\Override]
    public function handle(Request $request, RouteMatch $route, Actor $actor): Response
    {
        $this->actor = null;
        // The editor's upload expects JSON: an expired session gets a message, not the login page.
        if ($actor->isAnonymous() && $request->path === $this->access->path('media/upload')) {
            return Response::json(['success' => false, 'message' => $this->translator->translate('media.session_expired')], 401);
        }
        if ($actor->isAnonymous()) {
            // Back to the requested admin page after logging in.
            $target = $request->path . ($request->query === [] ? '' : '?' . http_build_query($request->query));

            return Response::redirect($request->basePath . '/belepes?vissza=' . rawurlencode($target));
        }
        if (!$this->access->allows($actor)) {
            throw new HttpException(403, 'error.forbidden');
        }

        $this->actor = $actor;

        $segments = array_values(array_filter(explode('/', (string) ($route->params['subpath'] ?? '')), static fn (string $s): bool => $s !== ''));
        $response = match (true) {
            ($segments[0] ?? null) === 'system' => $this->system($request, $actor, array_slice($segments, 1)),
            ($segments[0] ?? null) === 'media' => $this->mediaUpload($request, $actor, array_slice($segments, 1)),
            default => $this->content($request, $actor, $segments),
        };

        // Admin pages are never indexed by search engines, and run only our own scripts.
        return $response
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
    }

    /**
     * The content pages: dashboard, lists, forms, actions.
     *
     * @param list<string> $segments
     */
    private function content(Request $request, Actor $actor, array $segments): Response
    {
        return match (count($segments)) {
            0 => $this->dashboard($request, $actor),
            1 => $this->listing($request, $actor, $this->contentBlueprint($segments[0])),
            2 => $segments[1] === 'new'
                ? $this->create($request, $actor, $this->contentBlueprint($segments[0]))
                : $this->edit($request, $actor, $this->find($this->contentBlueprint($segments[0]), $segments[1], $actor)),
            3 => $this->action($request, $actor, $this->find($this->contentBlueprint($segments[0]), $segments[1], $actor), $segments[2]),
            default => throw HttpException::notFound(),
        };
    }

    /**
     * /admin/system: the system check; POST /admin/system/clear-cache empties the
     * template cache. Only for the system roles (AdminAccess::allowsSystem()).
     *
     * @param list<string> $segments The path after /admin/system
     */
    private function system(Request $request, Actor $actor, array $segments): Response
    {
        if (!$this->access->allowsSystem($actor)) {
            throw new HttpException(403, 'error.forbidden');
        }
        if ($segments === ['clear-cache']) {
            if (!$request->isPost()) {
                throw new HttpException(405, 'error.method_not_allowed');
            }
            if (!$this->csrf->isValid($request)) {
                $this->flash->add(Flash::DANGER, 'auth.form_expired');
            } else {
                $this->flash->add(Flash::SUCCESS, new Message('admin.system.cache_cleared', ['count' => $this->templateCache->clear()]));
            }

            return Response::redirect($request->basePath . $this->access->path('system'), 303);
        }
        if ($segments !== []) {
            throw HttpException::notFound();
        }

        $results = $this->system->run($request);
        $groups = [];
        foreach ($results as $result) {
            $groups[$result->group][] = $result;
        }

        return $this->render('system', 'system', [
            'title' => 'admin.system.title',
            'groups' => $groups,
            'worst' => SystemCheck::worst($results)?->value,
        ]);
    }

    /**
     * POST /admin/media/upload: uploads an image (field `file`, with the CSRF token) and
     * answers in JSON, for the editor: `{success, id, url, title, width, height}`, or
     * `{success: false, message}` with 400 (form), 403, 405, 413 (too large), 422 (not an
     * accepted image) or 500 (the server could not receive the file).
     *
     * @param list<string> $segments The path after /admin/media
     */
    private function mediaUpload(Request $request, Actor $actor, array $segments): Response
    {
        $fail = fn (string $key, int $status, array $params = []): Response => Response::json([
            'success' => false,
            'message' => $this->translator->translate($key, $params),
        ], $status);
        $tooLarge = fn (): Response => $fail('media.too_large', 413, [
            'max' => rtrim(rtrim(number_format($this->media->maxUploadBytes() / 1024 / 1024, 1, '.', ''), '0'), '.'),
        ]);

        if ($segments !== ['upload']) {
            throw HttpException::notFound();
        }
        if (!$request->isPost()) {
            return $fail('error.method_not_allowed', 405);
        }
        $file = $request->file('file');
        // Over post_max_size PHP discards the whole body of a form upload, the CSRF token too.
        if ($file === null && $request->post === [] && (int) ($request->headers['content-length'] ?? 0) > 0
            && str_starts_with(strtolower($request->headers['content-type'] ?? ''), 'multipart/form-data')) {
            return $tooLarge();
        }
        if (!$this->csrf->isValid($request)) {
            return $fail('auth.form_expired', 400);
        }
        if ($file === null || in_array($file->error, [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_PARTIAL], true)) {
            return $fail('media.empty', 400);
        }
        if ($file->isTooLarge()) {
            return $tooLarge();
        }
        if (!$file->isOk()) {
            return $fail('media.server_error', 500);
        }

        try {
            $image = $this->media->uploadImage($actor, $file->path, $file->name, $request->postString('alt'));
        } catch (AccessDeniedException) {
            return $fail('error.forbidden', 403);
        } catch (ValidationException $e) {
            $message = $e->errors['file'] ?? array_values($e->errors)[0] ?? new Message('media.not_image');

            return Response::json(['success' => false, 'message' => $message->translate($this->translator)], 422);
        }

        return Response::json([
            'success' => true,
            'id' => $image->id(),
            'url' => $request->basePath . $this->media->url($image),
            'title' => (string) $image->get('title'),
            'width' => $image->get('width'),
            'height' => $image->get('height'),
        ], 201);
    }

    private function dashboard(Request $request, Actor $actor): Response
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

        // A warning bar for those who can fix it, if a requirement is not met.
        $systemError = $this->access->allowsSystem($actor)
            && SystemCheck::worst($this->system->run($request)) === CheckStatus::Error;

        return $this->render('dashboard', 'dashboard', [
            'title' => 'admin.dashboard',
            'system_error' => $systemError,
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
            'can_create' => !self::isFile($blueprint) && $this->policy->allows($actor, Operation::Create, $this->repository->create($blueprint->name)),
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
        // A file (e.g. an image) is created by uploading it, not from an empty form.
        if (self::isFile($blueprint)) {
            throw HttpException::notFound();
        }
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
        // "Create and publish": only offered (and only honoured) if the actor may publish it.
        $publish = $request->postString('_publish') === '1' && $this->canPublishNew($actor, $object);
        try {
            $created = $this->service->create($actor, $blueprint->name, $read['values'], $publish, $read['relations']);
        } catch (ValidationException $e) {
            return $this->formPage($blueprint, $object, $actor, $request->post, $e->errors, 'admin.form.invalid', 422);
        } catch (AccessDeniedException) {
            throw new HttpException(403, 'error.forbidden');
        }

        $this->flash->add(Flash::SUCCESS, new Message($publish ? 'admin.form.created_published' : 'admin.form.created', ['title' => self::titleOf($created)]));

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
        $fields = $this->form->build($object, $actor, $input, $errors, $blueprint->formOrder, $blueprint->editors);
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
            'publication' => $object->isNew() ? null : $this->publication($object, $actor),
            'can_publish_new' => $object->isNew() && $this->canPublishNew($actor, $object),
            'can_delete' => !$object->isNew() && $this->policy->allows($actor, Operation::Delete, $object),
            'upload' => $this->uploadSettings($actor),
        ]);

        return $status === 200 ? $response : new Response($response->body, $status, $response->headers);
    }

    /**
     * /admin/<blueprint>/<id>/<action>: publish, unpublish (POST only) and delete
     * (a confirmation page, then POST).
     */
    private function action(Request $request, Actor $actor, CampanellaObject $object, string $action): Response
    {
        return match ($action) {
            'publish', 'unpublish' => $this->changePublication($request, $actor, $object, $action === 'publish'),
            'convert-html' => $this->convertToHtml($request, $actor, $object),
            'delete' => $this->delete($request, $actor, $object),
            default => throw HttpException::notFound(),
        };
    }

    /**
     * Publishing (now, or at a given time: scheduled) and unpublishing. These change
     * only the publication status and time; unsaved changes in the edit form are not
     * part of the request. Like saving, they refuse to act on a version of the object
     * other than the one the editor saw.
     */
    private function changePublication(Request $request, Actor $actor, CampanellaObject $object, bool $publish): Response
    {
        if (!$object->has(Publishable::class)) {
            throw HttpException::notFound();
        }
        if (!$this->policy->allows($actor, $publish ? Operation::Publish : Operation::Unpublish, $object)) {
            throw new HttpException(403, 'error.forbidden');
        }
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $blueprint = $this->contentBlueprint($object->blueprint());
        $back = Response::redirect($request->basePath . $this->access->path($blueprint->name . '/' . $object->id()), 303);

        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $back;
        }
        if ($request->postString('_version') !== $this->versionOf($object)) {
            $this->flash->add(Flash::WARNING, 'admin.publish.conflict');

            return $back;
        }

        $at = null;
        if ($publish) {
            try {
                $at = $this->form->parseDateTime($request->postString('published_at'));
            } catch (\UnexpectedValueException $e) {
                $this->flash->add(Flash::DANGER, $e->getMessage());

                return $back;
            }
            // An empty time means now (not an earlier, possibly future, publication time).
            $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        try {
            $publish ? $this->service->publish($actor, $object, $at) : $this->service->unpublish($actor, $object);
        } catch (ValidationException $e) {
            // The stored object is invalid (e.g. a required relation lost its target): it is fixed in the form.
            $this->flash->add(Flash::DANGER, 'admin.publish.invalid');
            foreach ($e->messages($this->translator) as $text) {
                $this->flash->add(Flash::DANGER, $text);
            }

            return $back;
        } catch (AccessDeniedException) {
            throw new HttpException(403, 'error.forbidden');
        }

        $title = self::titleOf($object);
        $this->flash->add(Flash::SUCCESS, match (true) {
            !$publish => new Message('admin.publish.unpublished', ['title' => $title]),
            $at > new DateTimeImmutable('now', new DateTimeZone('UTC')) => new Message('admin.publish.scheduled', [
                'title' => $title,
                'date' => $this->form->localDateTime($at, self::DISPLAY_DATETIME),
            ]),
            default => new Message('admin.publish.published', ['title' => $title]),
        });

        return $back;
    }

    /**
     * Converts a saved plain body to a formatted (HTML) one (PlainText::toHtml(): the
     * paragraphs and line breaks are kept). Like publishing, it acts only on the version
     * of the object the editor saw, and does not include unsaved changes of the form.
     */
    private function convertToHtml(Request $request, Actor $actor, CampanellaObject $object): Response
    {
        if (!$object->has(Textual::class)) {
            throw HttpException::notFound();
        }
        if (!$this->policy->allows($actor, Operation::Update, $object)) {
            throw new HttpException(403, 'error.forbidden');
        }
        if (!$request->isPost()) {
            throw new HttpException(405, 'error.method_not_allowed');
        }
        $blueprint = $this->contentBlueprint($object->blueprint());
        $back = Response::redirect($request->basePath . $this->access->path($blueprint->name . '/' . $object->id()), 303);

        if (!$this->csrf->isValid($request)) {
            $this->flash->add(Flash::DANGER, 'auth.form_expired');

            return $back;
        }
        if ($request->postString('_version') !== $this->versionOf($object)) {
            $this->flash->add(Flash::WARNING, 'admin.publish.conflict');

            return $back;
        }
        $textual = $object->as(Textual::class);
        if ($textual->format() === TextFormat::Html) {
            return $back; // already formatted (e.g. a second click)
        }

        try {
            $this->service->update($actor, $object, [
                'body' => PlainText::toHtml($textual->body()),
                'format' => TextFormat::Html->value,
            ]);
        } catch (ValidationException $e) {
            $this->flash->add(Flash::DANGER, 'admin.publish.invalid');
            foreach ($e->messages($this->translator) as $text) {
                $this->flash->add(Flash::DANGER, $text);
            }

            return $back;
        } catch (AccessDeniedException) {
            throw new HttpException(403, 'error.forbidden');
        }
        $this->flash->add(Flash::SUCCESS, new Message('admin.form.converted', ['title' => self::titleOf($object)]));

        return $back;
    }

    /** /admin/<blueprint>/<id>/delete: the confirmation page, and deleting. */
    private function delete(Request $request, Actor $actor, CampanellaObject $object): Response
    {
        $blueprint = $this->contentBlueprint($object->blueprint());
        if (!$this->policy->allows($actor, Operation::Delete, $object)) {
            throw new HttpException(403, 'error.forbidden');
        }
        if ($request->isPost()) {
            if (!$this->csrf->isValid($request)) {
                return $this->deletePage($blueprint, $object, $actor, 'auth.form_expired', 400);
            }
            try {
                $this->service->delete($actor, $object);
            } catch (AccessDeniedException) {
                throw new HttpException(403, 'error.forbidden');
            }
            $this->flash->add(Flash::SUCCESS, new Message('admin.delete.done', ['title' => self::titleOf($object)]));

            return Response::redirect($request->basePath . $this->access->path($blueprint->name), 303);
        }

        return $this->deletePage($blueprint, $object, $actor);
    }

    private function deletePage(
        Blueprint $blueprint,
        CampanellaObject $object,
        Actor $actor,
        ?string $alert = null,
        int $status = 200,
    ): Response {
        ['items' => $referrers, 'total' => $total] = $this->referrers($object, $actor);
        $response = $this->render('delete', $blueprint->name, [
            'title' => 'admin.delete.title',
            'title_params' => ['title' => self::titleOf($object)],
            'blueprint' => $blueprint,
            'object' => $object,
            'referrers' => $referrers,
            'more_referrers' => $total - count($referrers),
            'alert' => $alert,
            'action' => $this->access->path($blueprint->name . '/' . $object->id() . '/delete'),
            'edit_path' => $this->access->path($blueprint->name . '/' . $object->id()),
            'list_path' => $this->access->path($blueprint->name),
        ]);

        return $status === 200 ? $response : new Response($response->body, $status, $response->headers);
    }

    /**
     * The objects whose relations point to the object (deleting it removes those
     * references). `required`: the relation is required and this is its only
     * target, so the referring object cannot be saved again until a new one is chosen.
     * At most MAX_REFERRERS items; `total` counts all of them.
     *
     * @return array{items: list<array{object: CampanellaObject, blueprint: Blueprint, relation: string, required: bool}>, total: int}
     */
    private function referrers(CampanellaObject $object, Actor $actor): array
    {
        $id = (int) $object->id();
        $found = [];
        $total = 0;
        foreach ($this->blueprints->all() as $name => $blueprint) {
            foreach ($blueprint->allRelations() as $relation) {
                if (!self::canTarget($relation, $object)) {
                    continue;
                }
                $query = Query::objects()->blueprint($name)->whereRelated($relation->name, $id);
                $total += $this->queries->count($query, $actor);
                $room = self::MAX_REFERRERS - count($found);
                if ($room <= 0) {
                    continue;
                }
                foreach ($this->queries->execute($query->orderBy('updated', 'DESC')->limit($room), $actor) as $source) {
                    $found[] = [
                        'object' => $source,
                        'blueprint' => $blueprint,
                        'relation' => $relation->label !== '' ? $relation->label : $relation->name,
                        'required' => $relation->required && $source->relatedIds($relation->name) === [$id],
                    ];
                }
            }
        }

        return ['items' => $found, 'total' => max($total, count($found))];
    }

    /** Whether the relation may point to the object (its target Blueprints and capabilities). */
    private static function canTarget(Relation $relation, CampanellaObject $object): bool
    {
        if ($relation->targetBlueprints !== [] && !in_array($object->blueprint(), $relation->targetBlueprints, true)) {
            return false;
        }
        foreach ($relation->targetCapabilities as $capability) {
            if (!$object->has($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The publication panel of the edit form: the state, the time (in the site's
     * time zone) and which actions the actor may take. Null for an object that is not Publishable.
     *
     * @return array{state: string, at: string, at_input: string, timezone: string, can_publish: bool, can_unpublish: bool}|null
     */
    private function publication(CampanellaObject $object, Actor $actor): ?array
    {
        if (!$object->has(Publishable::class)) {
            return null;
        }
        $publishable = $object->as(Publishable::class);
        $at = $publishable->publishedAt();
        $published = $publishable->status() === PublishStatus::Published;
        $state = match (true) {
            // Published without a time (written outside the admin) is not visible either.
            !$published || $at === null => 'draft',
            $publishable->isPublished() => 'published',
            default => 'scheduled',
        };

        return [
            'state' => $state,
            'at' => $this->form->localDateTime($at, self::DISPLAY_DATETIME),
            'at_input' => $this->form->localDateTime($at),
            'timezone' => $this->form->timezone(),
            'can_publish' => $this->policy->allows($actor, Operation::Publish, $object),
            'can_unpublish' => $published && $this->policy->allows($actor, Operation::Unpublish, $object),
        ];
    }

    /**
     * The editor's image upload, if the actor may upload images: the address, and the
     * largest file (so the browser can refuse a larger one before sending it).
     *
     * @return array{url: string, max: int, max_mb: string}|null
     */
    private function uploadSettings(Actor $actor): ?array
    {
        if ($this->blueprints->find('image') === null
            || !$this->policy->allows($actor, Operation::Create, $this->repository->create('image'))) {
            return null;
        }
        $max = $this->media->maxUploadBytes();

        return [
            'url' => $this->access->path('media/upload'),
            'max' => $max,
            'max_mb' => rtrim(rtrim(number_format($max / 1024 / 1024, 1, '.', ''), '0'), '.'),
        ];
    }

    /** Whether the Blueprint's objects are files (MediaFile): created by uploading, not by a form. */
    private static function isFile(Blueprint $blueprint): bool
    {
        return isset($blueprint->capabilities['media_file']);
    }

    /** Whether a new object may be published right when it is created. */
    private function canPublishNew(Actor $actor, CampanellaObject $object): bool
    {
        return $object->has(Publishable::class) && $this->policy->allows($actor, Operation::Publish, $object);
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
            // As the DefaultPolicy: without a publication time it is not visible.
            default => Group::all($published, new FieldCondition('published_at', Operator::LessOrEqual, $now)),
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
            'can_system' => $this->actor !== null && $this->access->allowsSystem($this->actor),
            'blueprints' => $this->blueprints->all(),
        ]));
    }
}
