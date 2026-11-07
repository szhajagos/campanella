<?php

declare(strict_types=1);

namespace Campanella\Layout;

/**
 * A row of a layout (since 0.4.10): 1 to MAX_COLUMNS columns side by side from its breakpoint
 * up (stacked below it, in their `order`), in the site's width or the full width of the
 * window. Its Bootstrap classes are made here from its numbers: nothing of a layout's JSON
 * goes into the page as it is.
 */
final readonly class LayoutRow
{
    public const int MAX_COLUMNS = 4;
    public const int GRID = 12;

    /** @param list<LayoutColumn> $columns */
    public function __construct(
        public array $columns,
        public string $breakpoint = 'lg',
        public bool $full = false,
    ) {
    }

    /** Whether the content (the page's main part) is in it. */
    public function hasContent(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->region === LayoutDefinition::CONTENT) {
                return true;
            }
        }

        return false;
    }

    /** The row's own classes: its container, its spacing; the content's row grows (the footer at the bottom). */
    public function containerClass(): string
    {
        return ($this->full ? 'container-fluid' : 'container site-container')
            . ($this->hasContent() ? ' flex-grow-1 py-4 py-md-5' : ' py-3');
    }

    /** A column's classes in a row of several (`col-lg-8 order-2 order-lg-0`); a column hidden on small screens is too. */
    public function columnClass(LayoutColumn $column, ?RegionDefinition $region = null): string
    {
        $classes = ['col-' . $this->breakpoint . '-' . $column->width];
        if ($column->order !== null) {
            $classes[] = 'order-' . $column->order;
            $classes[] = 'order-' . $this->breakpoint . '-0';
        }
        if ($region !== null && $region->small === RegionDefinition::HIDE) {
            $classes[] = 'd-none';
            $classes[] = 'd-' . $region->breakpoint . '-block';
        }

        return implode(' ', $classes);
    }

    /** @return array<string, mixed> As the layout's JSON writes it */
    public function toArray(): array
    {
        $row = ['columns' => array_map(static fn (LayoutColumn $c): array => $c->toArray(), $this->columns)];
        if ($this->breakpoint !== 'lg') {
            $row = ['breakpoint' => $this->breakpoint] + $row;
        }
        if ($this->full) {
            $row = ['full' => true] + $row;
        }

        return $row;
    }
}
