<?php

declare(strict_types=1);

namespace Campanella\Menu;

/**
 * One item of a built menu, ready for a template (MenuBuilder).
 *
 * `href` is the final address (a site path already with the installation's
 * base path); `current`: it leads to the page being shown; `active`: it, or
 * an item below it, is current (e.g. to highlight a dropdown's parent).
 */
final readonly class MenuEntry
{
    /** @param list<MenuEntry> $children */
    public function __construct(
        public int $id,
        public string $title,
        public string $href,
        public bool $current = false,
        public bool $active = false,
        public bool $external = false,
        public array $children = [],
    ) {
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }
}
