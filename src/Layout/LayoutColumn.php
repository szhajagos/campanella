<?php

declare(strict_types=1);

namespace Campanella\Layout;

/**
 * A column of a layout's row (since 0.4.10): one region, its width of the 12 of the grid from
 * the row's breakpoint up, its place when the columns stack (below the breakpoint), and the
 * element it is: the content's is always `main`, another an `aside` (with the region's label
 * for assistive technology) or a plain `div`.
 */
final readonly class LayoutColumn
{
    public const string ASIDE = 'aside';
    public const string NONE = 'none';
    public const array LANDMARKS = [self::ASIDE, self::NONE];

    public function __construct(
        public string $region,
        public int $width = 12,
        public ?int $order = null,
        public string $landmark = self::NONE,
    ) {
    }

    /** Its element: main (the content), aside or div. */
    public function element(): string
    {
        return $this->region === LayoutDefinition::CONTENT ? 'main' : ($this->landmark === self::ASIDE ? 'aside' : 'div');
    }

    /** @return array<string, mixed> As the layout's JSON writes it */
    public function toArray(): array
    {
        return array_filter([
            'region' => $this->region,
            'width' => $this->width,
            'order' => $this->order,
            'landmark' => $this->region === LayoutDefinition::CONTENT || $this->landmark === self::NONE ? null : $this->landmark,
        ], static fn ($v): bool => $v !== null);
    }
}
