<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Capability\Downloadable;
use Campanella\Model\CampanellaObject;
use Campanella\Service\ObjectListener;

/** Deletes the stored file of a deleted Downloadable object (a file to download; since 0.3.2). */
final class DeleteStoredFile implements ObjectListener
{
    public function __construct(private readonly FileStorage $storage)
    {
    }

    #[\Override]
    public function afterDelete(CampanellaObject $object): void
    {
        if (!$object->has(Downloadable::class)) {
            return;
        }
        $path = $object->as(Downloadable::class)->path();
        if (!$this->storage->delete($path)) {
            error_log("Campanella: the file of the deleted object #{$object->id()} ({$path}) could not be deleted.");
        }
    }
}
