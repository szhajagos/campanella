<?php

declare(strict_types=1);

namespace Campanella\Display;

use Campanella\Model\CampanellaObject;

/**
 * The object the page being rendered shows (since 0.3.3): the controller of an object's
 * page sets it, so the `current` source and the breadcrumbs know it. Forgotten at the
 * start of every request.
 */
final class CurrentPage
{
    private ?CampanellaObject $object = null;

    public function set(?CampanellaObject $object): void
    {
        $this->object = $object;
    }

    public function object(): ?CampanellaObject
    {
        return $this->object;
    }
}
