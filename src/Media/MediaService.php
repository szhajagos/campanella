<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Service\ObjectService;

/**
 * Uploading images: checks the file (ImageProcessor), stores it (MediaStorage)
 * and creates its object (by default of the `image` Blueprint) through the
 * ObjectService, so access control applies as for any object.
 */
final class MediaService
{
    public function __construct(
        private readonly ObjectService $objects,
        private readonly ObjectRepository $repository,
        private readonly AccessPolicy $policy,
        private readonly ImageProcessor $processor,
        private readonly MediaStorage $storage,
        private readonly string $blueprint = 'image',
    ) {
    }

    /**
     * @param string $file The uploaded file (e.g. the temporary file of a PHP upload)
     * @param string $originalName The name the uploader gave it; becomes the title (without its extension)
     * @throws AccessDeniedException if the actor may not create images (checked before any processing)
     * @throws ValidationException if the file is not an accepted image (on the `file` field)
     */
    public function uploadImage(Actor $actor, string $file, string $originalName, string $alt = ''): CampanellaObject
    {
        if (!$this->policy->allows($actor, Operation::Create, $this->repository->create($this->blueprint))) {
            throw AccessDeniedException::for($actor, Operation::Create, $this->blueprint);
        }
        $image = $this->processor->process($file);
        $path = $this->storage->store($image->bytes, $image->extension);

        try {
            return $this->objects->create($actor, $this->blueprint, [
                'title' => self::titleFrom($originalName),
                'alt' => trim($alt),
                'file_path' => $path,
                'mime_type' => $image->mimeType,
                'file_size' => strlen($image->bytes),
                'width' => $image->width,
                'height' => $image->height,
                'file_hash' => hash('sha256', $image->bytes),
            ]);
        } catch (\Throwable $e) {
            // No file without its object.
            $this->storage->delete($path);
            throw $e;
        }
    }

    /**
     * The largest file that can be uploaded, in bytes: the max_bytes setting, or less if
     * PHP's upload_max_filesize or post_max_size is lower (PHP refuses larger ones first).
     */
    public function maxUploadBytes(): int
    {
        $limits = [$this->processor->maxBytes()];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ImageProcessor::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    /** The address of an image object's file on the site (e.g. `/media/2026/10/….jpg`). */
    public function url(CampanellaObject $object): string
    {
        return $this->storage->url((string) $object->get('file_path'));
    }

    /** The original file name without its extension, as a readable title (`IMG_2041`, `nyaralás`). */
    public static function titleFrom(string $originalName): string
    {
        $name = basename(str_replace('\\', '/', $originalName));
        $name = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name);
        // Control characters, and the invisible ones that change the text's direction
        // (e.g. U+202E, which makes "gpj.exe" display as "exe.jpg").
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $name));
        $name = mb_substr($name, 0, 200, 'UTF-8');

        return $name !== '' ? $name : 'image';
    }
}
