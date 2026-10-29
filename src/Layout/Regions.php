<?php

declare(strict_types=1);

namespace Campanella\Layout;

use Campanella\Display\ComponentService;
use Campanella\Display\DisplayContext;
use Campanella\View\Presentation;
use Closure;

/**
 * Renders a layout's regions (since 0.3.4): each region's components, in their order, those
 * the visitor may see and whose conditions hold here, wrapped as the region shows on small
 * screens (`layout/_region.html.twig`, which a theme can override). The components of all
 * the layout's regions are read with the first region of a page, in one query.
 */
final class Regions
{
    /** @var array<string, array<string, list<\Campanella\Model\CampanellaObject>>> */
    private array $loaded = [];

    /**
     * @param Closure(): ComponentService $components Lazy
     * @param Closure(): Presentation $presentation Lazy
     */
    public function __construct(
        private readonly Closure $components,
        private readonly Closure $presentation,
    ) {
    }

    /**
     * A region of the layout, rendered; '' if it has no component that shows here.
     *
     * @param array{row?: bool, class?: string} $options `row`: its components side by side (from
     *        the region's breakpoint up); `class`: CSS classes of its wrapper
     */
    public function render(LayoutDefinition $layout, string $region, DisplayContext $context, array $options = []): string
    {
        $definition = $layout->regions[$region] ?? null;
        if ($definition === null) {
            return '';
        }
        $items = [];
        foreach ($this->components($layout, $context)[$region] ?? [] as $component) {
            $html = ($this->components)()->render($component, $context);
            if (trim($html) !== '') {
                $items[] = $html;
            }
        }
        if ($items === []) {
            return '';
        }
        $class = is_string($options['class'] ?? null) ? (string) preg_replace('/[^A-Za-z0-9_ -]/', '', $options['class']) : '';

        return ($this->presentation)()->renderFirst(['layout/_region.html.twig'], [
            'region' => $definition,
            'items' => $items,
            'row' => ($options['row'] ?? false) === true,
            'extra_class' => trim($class),
            'id' => 'region-' . $region,
        ]);
    }

    /** Forgets what was read (at the start of every request). */
    public function reset(): void
    {
        $this->loaded = [];
    }

    /** @return array<string, list<\Campanella\Model\CampanellaObject>> */
    private function components(LayoutDefinition $layout, DisplayContext $context): array
    {
        $key = $layout->name . "\0" . $context->path . "\0" . ($context->current?->id() ?? '') . "\0" . $context->actor->id . "\0" . implode(',', $context->actor->roles);

        return $this->loaded[$key] ??= ($this->components)()->forRegions(array_keys($layout->regions), $context);
    }
}
