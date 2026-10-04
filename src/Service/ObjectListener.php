<?php

declare(strict_types=1);

namespace Campanella\Service;

use Campanella\Model\CampanellaObject;

/**
 * Notified by the ObjectService after an operation. A minimal hook until the
 * Event / Action system; for now only deleting is reported (e.g. to delete
 * an image's file together with its object).
 */
interface ObjectListener
{
    /**
     * After the object was deleted from the database. An exception thrown here is
     * logged, not passed on: the object is already gone.
     */
    public function afterDelete(CampanellaObject $object): void;
}
