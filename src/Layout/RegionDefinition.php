<?php

declare(strict_types=1);

namespace Campanella\Layout;

/**
 * A region of a layout (since 0.3.4): its name, its label, how it shows on small screens
 * (its default, from the layout's JSON; the admin can change it), and whether a page's own
 * components may be placed in it (`page`, since 0.3.5).
 */
final readonly class RegionDefinition
{
    /** Always shown. */
    public const string ALWAYS = 'always';
    /** Below the breakpoint collapsed: a button opens it. */
    public const string COLLAPSE = 'collapse';
    /** Below the breakpoint hidden. */
    public const string HIDE = 'hide';

    public const array LOOKS = [self::ALWAYS, self::COLLAPSE, self::HIDE];

    /** Bootstrap's breakpoints. */
    public const array BREAKPOINTS = ['sm', 'md', 'lg', 'xl'];

    public function __construct(
        public string $name,
        public string $label,
        public string $small = self::ALWAYS,
        public string $breakpoint = 'md',
        // Since 0.3.5: a page's own components may be placed in it (the content, the sidebar).
        public bool $page = false,
    ) {
    }

    /** The same region with another look (an invalid one is ignored). */
    public function withLook(string $small, string $breakpoint): self
    {
        return in_array($small, self::LOOKS, true) && in_array($breakpoint, self::BREAKPOINTS, true)
            ? new self($this->name, $this->label, $small, $breakpoint, $this->page)
            : $this;
    }
}
