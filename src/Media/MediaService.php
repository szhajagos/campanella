<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\Operation;
use Campanella\Capability\MediaFile;
use Campanella\Database\Connection;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Service\ObjectService;

/**
 * Uploading images: checks the file (ImageProcessor), stores it and its smaller
 * copies (MediaStorage) and creates its object (by default of the `image`
 * Blueprint) through the ObjectService, so access control applies as for any
 * object. Since 0.1.2 it also makes the missing copies of images uploaded before
 * (makeVariants()).
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
        private readonly ?QueryEngine $queries = null,
        private readonly ?Connection $db = null,
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
            $widths = [];
            foreach ($image->variants as $variant) {
                $this->storage->storeVariant($path, $variant->width, $variant->bytes);
                $widths[] = $variant->width;
            }

            return $this->objects->create($actor, $this->blueprint, [
                'title' => self::titleFrom($originalName),
                'alt' => trim($alt),
                'file_path' => $path,
                'mime_type' => $image->mimeType,
                'file_size' => strlen($image->bytes),
                'width' => $image->width,
                'height' => $image->height,
                'file_hash' => hash('sha256', $image->bytes),
                // Not re-encoded (no GD for the type): no copies can be made, now or later.
                'variants' => MediaFile::formatWidths($widths),
            ]);
        } catch (\Throwable $e) {
            // No file without its object.
            $this->storage->deleteVariants($path);
            $this->storage->delete($path);
            throw $e;
        }
    }

    /**
     * The images whose smaller copies were not made yet (uploaded before 0.1.2), oldest
     * first, after an ID.
     *
     * @return list<CampanellaObject>
     */
    public function withoutVariants(int $limit = 50, int $afterId = 0): array
    {
        if ($this->queries === null) {
            return [];
        }

        return $this->queries->execute(
            Query::objects()->having(MediaFile::class)->where('variants', 'IS NULL')->where('id', '>', $afterId)->orderBy('id')->limit(max(1, $limit)),
            Actor::system(),
        )->items;
    }

    /**
     * Every image after an ID, by ID (e.g. to make all copies again).
     *
     * @return list<CampanellaObject>
     */
    public function images(int $afterId = 0, int $limit = 100): array
    {
        if ($this->queries === null) {
            return [];
        }

        return $this->queries->execute(
            Query::objects()->having(MediaFile::class)->where('id', '>', $afterId)->orderBy('id')->limit(max(1, $limit)),
            Actor::system(),
        )->items;
    }

    /** How many images have no copies made yet. */
    public function countWithoutVariants(): int
    {
        return $this->queries?->count(Query::objects()->having(MediaFile::class)->where('variants', 'IS NULL'), Actor::system()) ?? 0;
    }

    /**
     * Makes (again) the smaller copies of a stored image, at the configured widths, and
     * records them on the object (with a Connection only that field, so the image's
     * modification time stays). The copies are made in memory first: the old ones are
     * replaced only then, and the widths recorded are those written. An image whose file
     * is missing or cannot be decoded is recorded as having none (it is not tried
     * again); one too large for the memory now is left as it is (tried again later).
     * Returns the widths made.
     *
     * @return list<int>
     */
    public function makeVariants(CampanellaObject $image): array
    {
        $file = $image->as(MediaFile::class);
        try {
            $variants = $this->processor->variantsOf($this->storage->path($file->path()));
        } catch (ValidationException | \InvalidArgumentException $e) {
            error_log("Campanella: no smaller copies of image #{$image->id()} ({$file->path()}): " . $e->getMessage());
            if ($e instanceof ValidationException && ($e->errors['file'] ?? null)?->key === 'media.too_many_pixels') {
                return [];
            }
            $variants = [];
        }
        $this->storage->deleteVariants($file->path());
        $widths = [];
        try {
            foreach ($variants as $variant) {
                $this->storage->storeVariant($file->path(), $variant->width, $variant->bytes);
                $widths[] = $variant->width;
            }
        } catch (\RuntimeException $e) {
            error_log("Campanella: a smaller copy of image #{$image->id()} ({$file->path()}) could not be written: " . $e->getMessage());
        }
        $image->set('variants', MediaFile::formatWidths($widths));
        if ($this->db !== null && !$image->isNew()) {
            $this->db->update('cap_media_file', ['variants' => $image->get('variants')], ['object_id' => (int) $image->id()]);
        } else {
            $this->repository->save($image);
        }

        return $widths;
    }

    /**
     * Makes the missing copies, oldest image first, until the time is up (a request must
     * not run out of max_execution_time). Returns [made, left].
     *
     * @return array{0: int, 1: int}
     */
    public function makeMissingVariants(float $seconds = 20.0, int $limit = 1000): array
    {
        $start = microtime(true);
        $made = 0;
        foreach ($this->withoutVariants($limit) as $image) {
            if (microtime(true) - $start > $seconds) {
                break;
            }
            $this->makeVariants($image);
            $made++;
        }

        return [$made, $this->countWithoutVariants()];
    }

    /** The space post_max_size needs beyond the file: the other form fields and the multipart framing. */
    public const int FORM_MARGIN = 64 * 1024;

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
                // The request carries the other fields too (FORM_MARGIN).
                $limits[] = $setting === 'post_max_size' ? max(0, $bytes - self::FORM_MARGIN) : $bytes;
            }
        }

        return min($limits);
    }

    /** The address of an image object's file on the site (e.g. `/media/2026/10/….jpg`). */
    public function url(CampanellaObject $object): string
    {
        return $this->storage->url((string) $object->get('file_path'));
    }

    /** The address of an image's smallest copy (e.g. for the admin's thumbnails), or of the image itself. Since 0.1.2. */
    public function thumbnailUrl(CampanellaObject $object): string
    {
        $file = $object->as(MediaFile::class);
        $widths = $file->variantWidths();

        return $this->storage->url($widths === [] ? $file->path() : MediaFile::variantPath($file->path(), $widths[0]));
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
