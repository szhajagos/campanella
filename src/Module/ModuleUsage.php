<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\Access\Actor;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Display\ComponentService;
use Campanella\Display\DisplayRegistry;
use Campanella\I18n\Message;
use Campanella\Model\BlueprintRegistry;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Settings\Settings;

/**
 * What of the site uses a module that is on (since 0.4.7): turned off, these would break, so
 * a module is not turned off while anything uses it.
 *
 *   - a Blueprint with one of its capabilities;
 *   - a component with one of its displays, or showing one of its system sources;
 *   - its layout as the site's or a Blueprint's;
 *   - its colour scheme as the site's.
 *
 * A page whose own layout is the module's (stored with the page, not queried) shows its
 * Blueprint's layout while the module is off, as for any layout that is gone.
 */
final class ModuleUsage
{
    public function __construct(
        private readonly ModuleLoader $loader,
        private readonly BlueprintRegistry $blueprints,
        private readonly CapabilityRegistry $capabilities,
        private readonly DisplayRegistry $displays,
        private readonly QueryEngine $queries,
        private readonly Settings $settings,
    ) {
    }

    /** @return list<Message> What uses it; [] if nothing (or it is not loaded) */
    public function of(string $name): array
    {
        $registered = $this->loader->loaded()[$name] ?? null;
        if ($registered === null) {
            return [];
        }
        $uses = [];

        // Its capabilities in Blueprints.
        $capabilities = [];
        foreach ((array) $registered['capabilities'] as $class) {
            if ($this->capabilities->has($class)) {
                $capabilities[] = $this->capabilities->get($class)->name;
            }
        }
        foreach ($this->blueprints->all() as $blueprint) {
            $used = array_intersect($capabilities, array_keys($blueprint->capabilities));
            if ($used !== []) {
                $uses[] = new Message('modules.usage.blueprint', ['blueprint' => $blueprint->name, 'capabilities' => implode(', ', $used)]);
            }
        }

        // Its displays and sources in components.
        $own = array_flip(array_map(strval(...), (array) $registered['displays']));
        $displays = array_values(array_map(static fn ($d): string => $d->name, array_filter($this->displays->definitions(), static fn ($d): bool => isset($own[$d->class]))));
        $sources = [];
        foreach ((array) $registered['sources'] as $class) {
            foreach ($this->displays->sources() as $source) {
                if ($source::class === $class) {
                    $sources[] = 'system:' . $source->name();
                }
            }
        }
        if (($displays !== [] || $sources !== []) && $this->blueprints->find(ComponentService::BLUEPRINT) !== null) {
            $titles = [];
            foreach ([['component_display', $displays], ['component_source', $sources]] as [$field, $values]) {
                if ($values === []) {
                    continue;
                }
                $query = Query::objects()->blueprint(ComponentService::BLUEPRINT)->where($field, 'IN', $values)->limit(20);
                foreach ($this->queries->execute($query, Actor::system()) as $component) {
                    $titles[(int) $component->id()] = (string) $component->get('title');
                }
            }
            if ($titles !== []) {
                $uses[] = new Message('modules.usage.components', ['components' => implode(', ', array_slice($titles, 0, 10))]);
            }
        }

        // Its layouts and colour schemes, by their names.
        $prefix = $name . '_';
        foreach ($this->settings->all('layout.') as $setting => $layout) {
            if (($setting === 'layout.site' || str_starts_with($setting, 'layout.blueprint.')) && str_starts_with($layout, $prefix)) {
                $uses[] = new Message('modules.usage.layout', ['layout' => $layout]);
            }
        }
        $scheme = $this->settings->get('appearance.scheme');
        if ($scheme !== null && str_starts_with($scheme, $prefix)) {
            $uses[] = new Message('modules.usage.scheme', ['scheme' => $scheme]);
        }

        return $uses;
    }
}
