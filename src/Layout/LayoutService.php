<?php

declare(strict_types=1);

namespace Campanella\Layout;

use Campanella\Model\CampanellaObject;
use Campanella\Settings\Settings;

/**
 * Which layout a page has, and how its regions look (since 0.3.4), from the settings the
 * admin saves:
 *
 *   layout.enabled                 '1' once the site's header and footer are components
 *                                  (the upgrade to 0.3.4 makes them); before that, the
 *                                  core's base.html.twig shows its old header and footer
 *   layout.site                    the site's layout (default: one_column)
 *   layout.blueprint.<blueprint>   a Blueprint's own (the pages of its objects)
 *   layout.region.<layout>.<region> how a region shows on small screens: `collapse:md`
 */
final class LayoutService
{
    public function __construct(
        private readonly LayoutRegistry $layouts,
        private readonly Settings $settings,
    ) {
    }

    public function registry(): LayoutRegistry
    {
        return $this->layouts;
    }

    /** Whether the core's base.html.twig shows the layouts (the regions). */
    public function isEnabled(): bool
    {
        return $this->settings->get('layout.enabled') === '1';
    }

    /** The site's layout. */
    public function site(): LayoutDefinition
    {
        return $this->withLooks($this->layouts->get($this->settings->get('layout.site') ?? LayoutRegistry::DEFAULT));
    }

    /** A Blueprint's layout: its own, or the site's. */
    public function forBlueprint(string $blueprint): LayoutDefinition
    {
        $own = $this->settings->get('layout.blueprint.' . $blueprint);

        return $own !== null && $this->layouts->has($own) ? $this->withLooks($this->layouts->get($own)) : $this->site();
    }

    /** A page's layout: that of its object's Blueprint, or the site's. */
    public function forPage(?CampanellaObject $object): LayoutDefinition
    {
        return $object === null ? $this->site() : $this->forBlueprint($object->blueprint());
    }

    /** The Blueprint's own layout's name, or null if it has the site's. */
    public function blueprintChoice(string $blueprint): ?string
    {
        $own = $this->settings->get('layout.blueprint.' . $blueprint);

        return $own !== null && $this->layouts->has($own) ? $own : null;
    }

    /** The layout with the looks of its regions as saved. */
    public function withLooks(LayoutDefinition $layout): LayoutDefinition
    {
        $regions = [];
        foreach ($layout->regions as $name => $region) {
            $saved = $this->settings->get('layout.region.' . $layout->name . '.' . $name);
            [$small, $breakpoint] = explode(':', (string) $saved) + [1 => ''];
            $regions[$name] = $saved === null ? $region : $region->withLook($small, $breakpoint);
        }

        return new LayoutDefinition($layout->name, $layout->label, $regions, $layout->origin);
    }
}
