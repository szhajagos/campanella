<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Capability\MediaFile;
use Campanella\Model\CampanellaObject;
use Campanella\Service\ObjectListener;

/** Deletes the stored file of a deleted MediaFile object (e.g. an image). */
final class DeleteMediaFile implements ObjectListener
{
    public function __construct(private readonly MediaStorage $storage)
    {
    }

    #[\Override]
    public function afterDelete(CampanellaObject $object): void
    {
        if (!$object->has(MediaFile::class)) {
            return;
        }
        $path = $object->as(MediaFile::class)->path();
        if (!$this->storage->delete($path)) {
            error_log("Campanella: the file of the deleted object #{$object->id()} ({$path}) could not be deleted.");
        }
    }
}
