<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Capability\Downloadable;
use Campanella\Capability\Restricted;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Service\ObjectService;
use Campanella\Support\Slugger;

/**
 * Uploading files to download (since 0.3.2): checks the file by its content
 * (FileInspector), stores it (FileStorage) and creates its object, by default of the
 * `file` Blueprint, through the ObjectService, so access control applies as for any
 * object. Who may download it is the object's Restricted setting.
 *
 * Files are available only if the Blueprint exists and has the Downloadable
 * capability (isAvailable()): e.g. not if the site made a Blueprint of that name in
 * the admin before 0.3.2 (the one made there keeps its name, and files stay off
 * until `files.blueprint` names another).
 */
final class FileService
{
    /** The longest download name, without its extension. */
    public const int MAX_NAME = 80;

    public function __construct(
        private readonly ObjectService $objects,
        private readonly ObjectRepository $repository,
        private readonly AccessPolicy $policy,
        private readonly FileInspector $inspector,
        private readonly FileStorage $storage,
        private readonly ?BlueprintRegistry $blueprints = null,
        private readonly string $blueprint = 'file',
        private readonly string $urlPrefix = '/files',
    ) {
    }

    /** The Blueprint of the files (`file`). */
    public function blueprint(): string
    {
        return $this->blueprint;
    }

    /** Whether files can be uploaded on this site: the Blueprint exists and is one of files. */
    public function isAvailable(): bool
    {
        $blueprint = $this->blueprints?->find($this->blueprint);

        return $this->blueprints === null || isset($blueprint?->capabilities['downloadable']);
    }

    /**
     * @param string $file The uploaded file (e.g. the temporary file of a PHP upload)
     * @param string $originalName The name the uploader gave it: the title, and the download's name
     * @param string $visibleTo Who may download it: Restricted::PUBLIC, USERS or ROLES
     * @param list<string> $roles With ROLES: whose users
     * @throws AccessDeniedException if the actor may not create files (checked before anything else)
     * @throws ValidationException if the file is not accepted (on the `file` field) or the visibility is not valid
     * @throws \RuntimeException if files are not available (isAvailable()) or the file cannot be stored
     */
    public function upload(Actor $actor, string $file, string $originalName, string $visibleTo = Restricted::PUBLIC, array $roles = [], string $title = ''): CampanellaObject
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException("Files are not available: the Blueprint '{$this->blueprint}' is not one of files.");
        }
        if (!$this->policy->allows($actor, Operation::Create, $this->repository->create($this->blueprint))) {
            throw AccessDeniedException::for($actor, Operation::Create, $this->blueprint);
        }
        $inspected = $this->inspector->inspect($file, $originalName);
        $path = $this->storage->store($inspected['bytes'], $inspected['extension']);

        try {
            $object = $this->repository->create($this->blueprint);
            $values = [
                'title' => trim($title) !== '' ? mb_substr(trim($title), 0, 200, 'UTF-8') : self::titleFrom($originalName),
                'download_path' => $path,
                'download_name' => self::downloadName($originalName, $inspected['extension']),
                'download_type' => $inspected['mime'],
                'download_size' => strlen($inspected['bytes']),
                'download_hash' => hash('sha256', $inspected['bytes']),
            ];
            if ($object->has(Restricted::class)) {
                $object->as(Restricted::class)->restrict($visibleTo, $roles);
                $values['visible_to'] = $object->get('visible_to');
                $values['visible_roles'] = $object->get('visible_roles');
            }

            return $this->objects->create($actor, $this->blueprint, $values);
        } catch (\Throwable $e) {
            // No file without its object.
            $this->storage->delete($path);
            throw $e;
        }
    }

    /** The address a file is downloaded from, relative to the site root: `/files/12/aprilisi-hirlevel.pdf`. */
    public function url(CampanellaObject $file): string
    {
        $download = $file->as(Downloadable::class);

        return rtrim($this->urlPrefix, '/') . '/' . (int) $file->id() . '/' . rawurlencode($download->name());
    }

    /** The absolute path of a file object's stored file. */
    public function path(CampanellaObject $file): string
    {
        return $this->storage->path($file->as(Downloadable::class)->path());
    }

    /** The space post_max_size needs beyond the file: the other form fields and the multipart framing. */
    public const int FORM_MARGIN = 64 * 1024;

    /**
     * The largest file that can be uploaded, in bytes: the files.max_bytes setting, or
     * less if PHP's upload_max_filesize or post_max_size is lower.
     */
    public function maxUploadBytes(): int
    {
        $limits = [$this->inspector->maxBytes()];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ImageProcessor::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $setting === 'post_max_size' ? max(0, $bytes - self::FORM_MARGIN) : $bytes;
            }
        }

        return min($limits);
    }

    /** The files.max_bytes setting (PHP's limits may allow less: maxUploadBytes()). */
    public function maxBytesSetting(): int
    {
        return $this->inspector->maxBytes();
    }

    /** @return list<string> The accepted extensions. */
    public function types(): array
    {
        return $this->inspector->types();
    }

    /** The original file name without its extension, as a readable title. */
    public static function titleFrom(string $originalName): string
    {
        $name = trim(pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_FILENAME));

        return $name === '' ? 'file' : MediaService::titleFrom($originalName);
    }

    /**
     * The name the file is downloaded with: the uploaded name in plain letters, digits
     * and hyphens (`Áprilisi hírlevél.PDF` → `aprilisi-hirlevel.pdf`), with the
     * extension of what the file really is.
     */
    public static function downloadName(string $originalName, string $extension): string
    {
        $name = pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_FILENAME);
        $slug = Slugger::slugify($name);
        $slug = $slug === 'n-a' ? 'file' : rtrim(substr($slug, 0, self::MAX_NAME), '-');

        return $slug . '.' . $extension;
    }
}
