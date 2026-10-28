<?php

declare(strict_types=1);

namespace Campanella\Display;

use Campanella\Access\Actor;
use Campanella\Model\CampanellaObject;
use Closure;

/**
 * Where a display is shown (since 0.3.3): to whom, on which page. A display reads only
 * this from the request, so what it shows depends only on these (the cache of 0.5.0
 * will key components' HTML by them).
 */
final class DisplayContext
{
    private ?CampanellaObject $user = null;

    private bool $userLoaded = false;

    /**
     * @param Actor $actor The visitor, with their roles
     * @param string $path The path of the page (without the installation's folder)
     * @param CampanellaObject|null $current The page's object (an article on its own page), if any
     * @param (Closure(): ?CampanellaObject)|null $loadUser The logged-in user's object (read only if needed)
     * @param string $search The search's words, on the search page ('' elsewhere)
     * @param string $basePath The installation's URL prefix
     */
    public function __construct(
        public readonly Actor $actor,
        public readonly string $path = '/',
        public readonly ?CampanellaObject $current = null,
        private readonly ?Closure $loadUser = null,
        public readonly string $search = '',
        public readonly string $basePath = '',
    ) {
    }

    /** The logged-in user's object, or null for an anonymous visitor. */
    public function user(): ?CampanellaObject
    {
        if (!$this->userLoaded) {
            $this->userLoaded = true;
            $this->user = $this->actor->isAnonymous() || $this->loadUser === null ? null : ($this->loadUser)();
        }

        return $this->user;
    }
}
