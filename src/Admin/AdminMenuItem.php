<?php

declare(strict_types=1);

namespace Campanella\Admin;

/** One link of the admin's menu (since 0.3.3). */
final readonly class AdminMenuItem
{
    /**
     * @param string $name What the page calls itself (`active`), to mark the link: `article`, `settings`
     * @param string $label A message key or text
     * @param string $path Under the admin: `article`, `system/settings` ('' for the dashboard)
     * @param int $badge A number to show beside it (e.g. the unread messages); 0: none
     * @param string $badgeLabel What the number counts, for screen readers (a message key)
     * @param bool $sub Indented, under the item before it
     * @param bool $plural The label's plural (`<label>.plural`), if the language has one: a Blueprint's
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $path,
        public int $badge = 0,
        public string $badgeLabel = '',
        public bool $sub = false,
        public bool $plural = false,
    ) {
    }
}
