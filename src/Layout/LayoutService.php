<?php

declare(strict_types=1);

namespace Campanella\Layout;

use Campanella\Capability\Composed;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ValidationException;
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
 *
 * A Composed object (since 0.3.5) may have a layout of its own, which comes first.
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

    /**
     * A page's layout: its object's own (a Composed object's, since 0.3.5), that of its
     * Blueprint, or the site's.
     */
    public function forPage(?CampanellaObject $object): LayoutDefinition
    {
        if ($object === null) {
            return $this->site();
        }
        $own = $object->has(Composed::class) ? $object->as(Composed::class)->layout() : null;

        return $own !== null && $this->layouts->has($own) ? $this->withLooks($this->layouts->get($own)) : $this->forBlueprint($object->blueprint());
    }

    /** The Blueprint's own layout's name, or null if it has the site's. */
    public function blueprintChoice(string $blueprint): ?string
    {
        $own = $this->settings->get('layout.blueprint.' . $blueprint);

        return $own !== null && $this->layouts->has($own) ? $own : null;
    }

    /**
     * Saves the admin's choices (since 0.3.4): the site's layout, the Blueprints' own (a
     * Blueprint not given keeps its choice; '' gives it the site's), and the regions' looks
     * on small screens (a look equal to the layout's own default is not stored). All or
     * nothing: a layout or region that does not exist, or a look that is not valid, is a
     * problem (`site`, `blueprint.<name>`, `look.<layout>.<region>`).
     *
     * @param array<string, string> $blueprints Blueprint => layout name, or '' for the site's
     * @param array<string, array<string, string>> $looks layout => region => `collapse:md`
     * @throws ValidationException
     */
    public function save(?string $site, array $blueprints = [], array $looks = []): void
    {
        $values = [];
        $problems = [];
        if ($site !== null) {
            $this->layouts->has($site) ? $values['layout.site'] = $site : $problems['site'] = new Message('layouts.unknown');
        }
        foreach ($blueprints as $blueprint => $layout) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', (string) $blueprint) !== 1) {
                $problems['blueprint'] = new Message('layouts.unknown');
            } elseif ($layout === '' || $this->layouts->has($layout)) {
                $values['layout.blueprint.' . $blueprint] = $layout === '' ? null : $layout;
            } else {
                $problems['blueprint.' . $blueprint] = new Message('layouts.unknown');
            }
        }
        foreach ($looks as $name => $regions) {
            $layout = $this->layouts->has((string) $name) ? $this->layouts->get((string) $name) : null;
            foreach ($regions as $region => $look) {
                $key = 'look.' . $name . '.' . $region;
                $default = $layout?->regions[$region] ?? null;
                [$small, $breakpoint] = explode(':', $look, 2) + [1 => ''];
                if ($default === null) {
                    $problems[$key] = new Message('layouts.unknown');
                } elseif (!in_array($small, RegionDefinition::LOOKS, true) || !in_array($breakpoint, RegionDefinition::BREAKPOINTS, true)) {
                    $problems[$key] = new Message('layouts.look_invalid');
                } else {
                    // An "always" look has no breakpoint: compared without it.
                    $same = $small === $default->small && ($small === RegionDefinition::ALWAYS || $breakpoint === $default->breakpoint);
                    $values['layout.region.' . $name . '.' . $region] = $same ? null : $small . ':' . $breakpoint;
                }
            }
        }
        if ($problems !== []) {
            throw new ValidationException($problems);
        }
        if ($values !== []) {
            $this->settings->set($values);
        }
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
