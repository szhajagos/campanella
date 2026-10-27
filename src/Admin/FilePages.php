<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Capability\Downloadable;
use Campanella\Capability\MediaFile;
use Campanella\Capability\Restricted;
use Campanella\Http\Flash;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\I18n\Message;
use Campanella\I18n\Translator;
use Campanella\Media\FileInspector;
use Campanella\Media\FileService;
use Campanella\Media\ImageProcessor;
use Campanella\Media\MediaService;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Security\Csrf;
use Closure;

/**
 * The admin's pages of the files to download (since 0.3.2), under /admin/media:
 *
 *  - `/admin/media`: the media library, the images and the files together, newest
 *    first, with a filter by kind and a search by title, and one upload form for both
 *    (an image becomes an image, anything else a file);
 *  - `POST /admin/media/file-upload`: uploading a file (the Files list's form);
 *    `POST /admin/media/add`: uploading either (the library's form). With
 *    `Accept: application/json` (admin-upload.js) the answer is JSON, otherwise a
 *    redirect back with a one-time message;
 *  - `GET /admin/media/files?q=&page=`: the files for the editor's file picker, as JSON.
 *
 * And the parts of the generic pages that are a file's: the upload settings of the
 * Files list, the edit form's side panel.
 *
 * @internal
 */
final class FilePages
{
    /** The items per page of the library and of the file picker. */
    public const int PER_PAGE = 30;
    public const int BROWSE_PER_PAGE = 20;

    /** @param list<string> $roles The roles a file can be limited to: the site's own (`users.roles`) */
    public function __construct(
        private readonly FileService $files,
        private readonly MediaService $media,
        private readonly QueryEngine $queries,
        private readonly BlueprintRegistry $blueprints,
        private readonly ObjectRepository $repository,
        private readonly AccessPolicy $policy,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Translator $translator,
        private readonly AdminAccess $access,
        private readonly array $roles = [],
    ) {
    }

    /** Whether the actor may upload files (and files are available on the site). */
    public function canUpload(Actor $actor): bool
    {
        return $this->files->isAvailable() && $this->blueprints->find($this->files->blueprint()) !== null
            && $this->policy->allows($actor, Operation::Create, $this->repository->create($this->files->blueprint()));
    }

    /** Whether there are files on this site (their Blueprint exists, and is one of files). */
    public function canBrowse(): bool
    {
        return $this->files->isAvailable() && $this->blueprints->find($this->files->blueprint()) !== null;
    }

    private function canUploadImages(Actor $actor): bool
    {
        return $this->blueprints->find('image') !== null
            && $this->policy->allows($actor, Operation::Create, $this->repository->create('image'));
    }

    /**
     * The upload form's settings for the Files list (or the library: `$library`), if the
     * actor may upload: the address, the largest file, the accepted types, the roles.
     *
     * @return array{url: string, max: int, max_mb: string, accept: string, types: string, files: bool, images: bool, roles: list<array{value: string, label: string}>}|null
     */
    public function uploadSettings(Actor $actor, bool $library = false): ?array
    {
        $files = $this->canUpload($actor);
        $images = $library && $this->canUploadImages($actor);
        if (!$files && !$images) {
            return null;
        }
        $max = max($files ? $this->files->maxUploadBytes() : 0, $images ? $this->media->maxUploadBytes() : 0);
        $accept = [];
        foreach ($files ? $this->files->types() : [] as $type) {
            $accept[] = '.' . $type;
            $accept[] = FileInspector::KNOWN[$type];
        }
        if ($images) {
            array_push($accept, 'image/jpeg', 'image/png', 'image/webp', 'image/gif');
        }

        return [
            'url' => $this->access->path($library ? 'media/add' : 'media/file-upload'),
            'max' => $max,
            'max_mb' => self::megabytes($max),
            'accept' => implode(',', array_values(array_unique($accept))),
            'types' => implode(', ', array_map(static fn (string $t): string => strtoupper($t), $files ? $this->files->types() : [])),
            'files' => $files,
            'images' => $images,
            'roles' => self::roleOptions($this->roles, $this->translator),
        ];
    }

    /**
     * POST /admin/media/file-upload, /admin/media/add: one file (field `file`, with
     * `visible_to` and `visible_roles[]` for a file). JSON: `{success, id, kind, url,
     * title, edit}` with 201, or `{success: false, message}` with 400, 403, 405, 413, 422
     * or 500; otherwise a redirect to the list it came from.
     */
    public function upload(Request $request, Actor $actor, bool $library): Response
    {
        [$status, $data, $error] = $this->result($request, $actor, $library);
        if (self::wantsJson($request) || !$request->isPost()) {
            return Response::json($data, $status);
        }
        $this->flash->add(
            $data['success'] ? Flash::SUCCESS : Flash::DANGER,
            $error ?? new Message('media.uploaded', ['title' => (string) $data['title']]),
        );

        return Response::redirect($request->basePath . $this->access->path($library ? 'media' : $this->files->blueprint()), 303);
    }

    /** @return array{int, array<string, mixed>, ?Message} */
    private function result(Request $request, Actor $actor, bool $library): array
    {
        $fail = fn (string $key, int $status, array $params = []): array => [$status, [
            'success' => false,
            'message' => $this->translator->translate($key, $params),
        ], new Message($key, $params)];
        $max = max($this->files->maxUploadBytes(), $library ? $this->media->maxUploadBytes() : 0);
        $tooLarge = fn (): array => $fail('file.too_large', 413, ['max' => self::megabytes($max)]);

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
            return $fail('file.empty', 400);
        }
        if ($file->isTooLarge()) {
            return $tooLarge();
        }
        if (!$file->isOk()) {
            return $fail('media.server_error', 500);
        }

        try {
            // The library takes images too: what is an image becomes one.
            if ($library && $this->canUploadImages($actor) && @getimagesize($file->path) !== false) {
                $object = $this->media->uploadImage($actor, $file->path, $file->name);
                $kind = 'image';
                $url = $this->media->url($object);
            } else {
                if (!$this->files->isAvailable()) {
                    return $fail('error.forbidden', 403);
                }
                $roles = is_array($request->post['visible_roles'] ?? null) ? array_values(array_filter($request->post['visible_roles'], is_string(...))) : [];
                $scope = $request->postString('visible_to');
                $object = $this->files->upload($actor, $file->path, $file->name, $scope !== '' ? $scope : Restricted::PUBLIC, $roles);
                $kind = 'file';
                $url = $this->files->url($object);
            }
        } catch (AccessDeniedException) {
            return $fail('error.forbidden', 403);
        } catch (ValidationException $e) {
            $message = $e->errors['file'] ?? array_values($e->errors)[0] ?? new Message('file.not_recognized', ['types' => '']);

            return [422, ['success' => false, 'message' => $message->translate($this->translator)], $message];
        }

        return [201, [
            'success' => true,
            'id' => $object->id(),
            'kind' => $kind,
            'url' => $request->basePath . $url,
            'title' => (string) $object->get('title'),
            'edit' => $request->basePath . $this->access->path($object->blueprint() . '/' . $object->id()),
        ], null];
    }

    /**
     * GET /admin/media/files?q=&page=: the files for the editor's file picker, newest first,
     * those whose title contains `q`, as JSON: `{success, items: [{id, title, url, name, type,
     * size, restricted}], page, pages, total}`. Only the files the actor may see.
     */
    public function browse(Request $request, Actor $actor): Response
    {
        if (!$this->files->isAvailable() || $this->blueprints->find($this->files->blueprint()) === null) {
            return Response::json(['success' => false, 'message' => $this->translator->translate('error.not_found')], 404);
        }
        $page = max(1, $request->queryInt('page', 1));
        $query = $this->search(Query::objects()->blueprint($this->files->blueprint()), $request->queryString('q'))
            ->orderBy('created', 'desc')->orderBy('id', 'desc');
        $result = $this->queries->execute($query->page($page, self::BROWSE_PER_PAGE), $actor, withTotal: true);
        $total = (int) $result->total;
        $items = [];
        foreach ($result->items as $file) {
            $download = $file->as(Downloadable::class);
            $items[] = [
                'id' => (int) $file->id(),
                'title' => (string) $file->get('title'),
                'url' => $request->basePath . $this->files->url($file),
                'name' => $download->name(),
                'type' => $this->typeName($download->extension()),
                'size' => self::size($download->size(), $this->translator),
                'restricted' => $file->has(Restricted::class) && !$file->as(Restricted::class)->isPublic(),
            ];
        }

        return Response::json([
            'success' => true,
            'items' => $items,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::BROWSE_PER_PAGE)),
            'total' => $total,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /admin/media: the media library.
     *
     * @param Closure(string, string, array<string, mixed>): Response $render
     */
    public function library(Request $request, Actor $actor, Closure $render): Response
    {
        $kinds = [];
        if ($this->blueprints->find('image') !== null) {
            $kinds['image'] = 'image';
        }
        if ($this->files->isAvailable() && $this->blueprints->find($this->files->blueprint()) !== null) {
            $kinds['file'] = $this->files->blueprint();
        }
        $kind = $request->queryString('kind');
        $kind = isset($kinds[$kind]) ? $kind : '';
        $search = trim(mb_substr($request->queryString('q'), 0, 100, 'UTF-8'));
        $page = max(1, $request->queryInt('page', 1));
        $items = [];
        $total = 0;
        if ($kinds !== []) {
            $query = $this->search(
                Query::objects()->where('blueprint', 'IN', $kind === '' ? array_values($kinds) : [$kinds[$kind]]),
                $search,
            )->orderBy('created', 'desc')->orderBy('id', 'desc');
            $result = $this->queries->execute($query->page($page, self::PER_PAGE), $actor, withTotal: true);
            $total = (int) $result->total;
            foreach ($result->items as $object) {
                $items[] = $this->item($object, $actor);
            }
        }

        return $render('media', 'media', [
            'title' => 'admin.library.title',
            'items' => $items,
            'kinds' => array_keys($kinds),
            'kind' => $kind,
            'q' => $search,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'upload' => $this->uploadSettings($actor, true),
        ]);
    }

    /**
     * The side panel of a file's edit form: its type, size, download name and address.
     *
     * @return array{url: string, name: string, type: string, size: int, extension: string}
     */
    public function panel(CampanellaObject $file): array
    {
        $download = $file->as(Downloadable::class);

        return [
            'url' => $this->files->url($file),
            'name' => $download->name(),
            'type' => $this->typeName($download->extension()),
            'extension' => $download->extension(),
            'size' => $download->size(),
        ];
    }

    /**
     * The roles offered in an upload form, by their translated names.
     *
     * @param list<string> $roles
     * @return list<array{value: string, label: string}>
     */
    public static function roleOptions(array $roles, Translator $translator): array
    {
        return array_map(static function (string $role) use ($translator): array {
            $label = $translator->translate('role.' . $role);

            return ['value' => $role, 'label' => $label === 'role.' . $role ? $role : $label];
        }, $roles);
    }

    /** @return array<string, mixed> One row of the library. */
    private function item(CampanellaObject $object, Actor $actor): array
    {
        $item = [
            'id' => (int) $object->id(),
            'title' => (string) $object->get('title'),
            'created' => $object->created(),
            'edit' => $this->policy->allows($actor, Operation::Update, $object) ? $this->access->path($object->blueprint() . '/' . $object->id()) : null,
        ];
        if ($object->has(MediaFile::class)) {
            $file = $object->as(MediaFile::class);

            return $item + [
                'kind' => 'image',
                'thumb' => $this->media->thumbnailUrl($object),
                'type' => ImageProcessor::NAMES[$file->mimeType()] ?? $file->mimeType(),
                'size' => $file->size(),
                'width' => $file->width(),
                'height' => $file->height(),
                'alt' => $object->hasField('alt') ? (string) $object->get('alt') : '',
                'visible_to' => null,
            ];
        }
        $download = $object->as(Downloadable::class);

        return $item + [
            'kind' => 'file',
            'url' => $this->files->url($object),
            'extension' => $download->extension(),
            'type' => $this->typeName($download->extension()),
            'size' => $download->size(),
            'visible_to' => $object->has(Restricted::class) ? $object->as(Restricted::class)->scope() : Restricted::PUBLIC,
            'roles' => $object->has(Restricted::class) ? $object->as(Restricted::class)->roles() : [],
        ];
    }

    private function search(Query $query, string $search): Query
    {
        $search = trim(mb_substr($search, 0, 100, 'UTF-8'));

        return $search === '' ? $query : $query->where('title', 'LIKE', '%' . addcslashes($search, '%_\\') . '%');
    }

    private static function wantsJson(Request $request): bool
    {
        return str_contains(strtolower($request->headers['accept'] ?? ''), 'application/json');
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.');
    }

    /** A file type's name for people: `PDF`, `Word`, `OpenDocument text` (`file.type.<extension>`). */
    public function typeName(string $extension): string
    {
        $key = 'file.type.' . $extension;
        $name = $this->translator->translate($key);

        return $name === $key ? strtoupper($extension) : $name;
    }

    /** A size for the file picker, as the `file_size` filter shows it. */
    private static function size(int $bytes, Translator $translator): string
    {
        [$key, $value] = match (true) {
            $bytes < 1024 => ['format.bytes', (float) $bytes],
            $bytes < 1024 * 1024 => ['format.kb', $bytes / 1024],
            default => ['format.mb', $bytes / 1024 / 1024],
        };
        $decimals = $key !== 'format.bytes' && $value < 10 ? 1 : 0;
        $number = number_format($value, $decimals, $translator->translate('format.decimal_point'), "\u{a0}");
        if ($decimals === 1 && str_ends_with($number, '0')) {
            $number = substr($number, 0, -2);
        }

        return $translator->translate($key, ['n' => $number]);
    }
}
