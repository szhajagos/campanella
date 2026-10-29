<?php

declare(strict_types=1);

namespace Campanella\Display;

use Campanella\Access\Actor;
use Campanella\Capability\Component;
use Campanella\I18n\Message;
use Campanella\Layout\Conditions;
use Campanella\Layout\LayoutRegistry;
use Campanella\Lists\ListRegistry;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;

/**
 * Components (since 0.3.3): objects of the `component` Blueprint, each a source shown
 * with a display and its settings. This service checks them on every save (the source
 * exists, the display can show it, the settings are valid), tells why one no longer
 * works (its object was deleted, its display is gone …), and shows them.
 *
 *     $components->create($actor, ['title' => 'Footer menu', 'machine_name' => 'lablec_menu'],
 *         'object:12', 'navigation', ['style' => 'vertical']);
 *     echo $components->show('lablec_menu', $context);
 *
 * A component is shown with the visitor's rights: it is found by a query (so its own
 * `Restricted` setting counts), and its source is read as every display's is.
 */
final class ComponentService
{
    public const string BLUEPRINT = 'component';

    /** The most components read for a page's regions. */
    public const int MAX_PLACED = 200;

    public function __construct(
        private readonly DisplayRenderer $renderer,
        private readonly ObjectService $objects,
        private readonly ObjectRepository $repository,
        private readonly QueryEngine $queries,
        private readonly ?ListRegistry $lists = null,
        private readonly ?BlueprintRegistry $blueprints = null,
        private readonly ?LayoutRegistry $layouts = null,
    ) {
    }

    /**
     * Whether there are components on this site: the `component` Blueprint has the
     * Component capability (not if the site made a Blueprint of that name in the admin
     * before 0.3.3: theirs keeps the name).
     */
    public function isAvailable(): bool
    {
        return $this->blueprints === null || isset($this->blueprints->find(self::BLUEPRINT)?->capabilities['component']);
    }

    /**
     * Whether a source names something that exists (whoever may see it): an object, a list
     * made in the admin, a system source; `current` always does.
     */
    public function sourceExists(string $source): bool
    {
        try {
            [$kind, $name] = DisplayRenderer::parseSource($source);
        } catch (DisplayException) {
            return false;
        }

        return match ($kind) {
            'current' => true,
            'object' => $this->repository->find((int) $name) !== null,
            'list' => $this->lists?->find($name) !== null,
            default => $this->renderer->registry()->hasSource($name),
        };
    }

    /**
     * The displays a source can be shown with: those for its object, for a list, for its
     * system source; for `current`, every display for objects (the page's object decides
     * when it is shown). Empty for a source that does not exist.
     *
     * @return list<DisplayDefinition>
     */
    public function displaysFor(string $source): array
    {
        $registry = $this->renderer->registry();
        if (!$this->sourceExists($source)) {
            return [];
        }
        [$kind, $name] = DisplayRenderer::parseSource($source);
        if ($kind === 'current') {
            return array_values(array_filter($registry->definitions(), static fn (DisplayDefinition $d): bool => $d->isForObjects()));
        }
        $subject = match ($kind) {
            'object' => Subject::object($this->repository->find((int) $name) ?? throw new DisplayException('Not found.')),
            'list' => Subject::list([], $this->lists?->find($name)),
            default => Subject::system($name),
        };

        return $registry->for($subject);
    }

    /**
     * Checks a component's source, display and settings: the settings cast, and the problems
     * (`source`, `display`, or a setting's name).
     *
     * @param array<string, mixed> $settings As given in a form
     * @return array{settings: array<string, mixed>, errors: array<string, Message>}
     */
    public function check(string $source, string $display, array $settings): array
    {
        if (!$this->sourceExists($source)) {
            return ['settings' => [], 'errors' => ['source' => new Message('components.source_missing')]];
        }
        $offered = array_map(static fn (DisplayDefinition $d): string => $d->name, $this->displaysFor($source));
        if (!in_array($display, $offered, true)) {
            return ['settings' => [], 'errors' => ['display' => new Message('components.display_unfit')]];
        }

        return $this->renderer->check($display, $settings);
    }

    /**
     * A new component.
     *
     * @param array<string, mixed> $values Its other fields: title, machine_name, visible_to, visible_roles
     * @param array<string, mixed> $settings
     * @throws ValidationException for a problem of the source, the display, a setting or a field
     */
    public function create(Actor $actor, array $values, string $source, string $display, array $settings = []): CampanellaObject
    {
        if (!$this->isAvailable()) {
            throw new DisplayException('There are no components on this site: the Blueprint \'' . self::BLUEPRINT . '\' is not one of components.');
        }
        $checked = $this->checked($source, $display, $settings);
        $component = $this->repository->create(self::BLUEPRINT, $values);
        $component->as(Component::class)->configure($source, $display, $checked);

        return $this->objects->create($actor, self::BLUEPRINT, $this->values($component, $values));
    }

    /**
     * Changes a component.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $settings
     * @throws ValidationException
     */
    public function update(Actor $actor, CampanellaObject $component, array $values, string $source, string $display, array $settings = []): void
    {
        $checked = $this->checked($source, $display, $settings);
        $copy = $this->repository->create(self::BLUEPRINT);
        $copy->as(Component::class)->configure($source, $display, $checked);
        $this->objects->update($actor, $component, $this->values($copy, $values));
    }

    /**
     * The problems of a placement (since 0.3.4): a region no layout has (`region`), conditions
     * that are not valid (`paths`, `except`, `blueprints`).
     *
     * @return array<string, Message>
     */
    public function placementProblems(?string $region, Conditions $where): array
    {
        $problems = [];
        if ($region !== null && $this->layouts !== null && !in_array($region, $this->layouts->regionNames(), true)) {
            $problems['region'] = new Message('components.region_unknown', ['region' => mb_substr($region, 0, 64, 'UTF-8')]);
        }

        return $problems + $where->problems($this->blueprints === null ? null : array_keys($this->blueprints->all()));
    }

    /**
     * Places a component (since 0.3.4): in a region (null: none, only component() shows it),
     * where it shows, and its order within the region (null: kept).
     *
     * @throws ValidationException for a region no layout has, or conditions that are not valid
     */
    public function place(Actor $actor, CampanellaObject $component, ?string $region, ?Conditions $where = null, ?int $weight = null): void
    {
        $where ??= Conditions::everywhere();
        $problems = $this->placementProblems($region, $where);
        if ($problems !== []) {
            throw new ValidationException($problems);
        }
        $copy = $this->repository->create(self::BLUEPRINT);
        $copy->as(Component::class)->place($region, $where);
        $values = ['component_region' => $copy->get('component_region'), 'component_conditions' => $copy->get('component_conditions')];
        if ($weight !== null) {
            $values['weight'] = $weight;
        }
        $this->objects->update($actor, $component, $values);
    }

    /**
     * The components of these regions that show here, for this visitor, in their order (since
     * 0.3.4): one query for them all (their `Restricted` setting counts there), their
     * conditions checked here.
     *
     * @param list<string> $regions
     * @return array<string, list<CampanellaObject>> region => components
     */
    public function forRegions(array $regions, DisplayContext $context): array
    {
        $found = array_fill_keys($regions, []);
        if ($regions === [] || !$this->isAvailable()) {
            return $found;
        }
        $query = Query::objects()->blueprint(self::BLUEPRINT)->where('component_region', 'IN', $regions)
            ->scope('by_weight')->limit(self::MAX_PLACED);
        foreach ($this->queries->execute($query, $context->actor) as $component) {
            $definition = $component->as(Component::class);
            $region = (string) $definition->region();
            if (isset($found[$region]) && $definition->conditions()->matches($context->path, $context->current)) {
                $found[$region][] = $component;
            }
        }

        return $found;
    }

    /**
     * The components placed in a region, in their order, whatever their conditions (since
     * 0.3.4; for the admin's regions page): those the actor may see, at most MAX_PLACED.
     *
     * @return list<CampanellaObject>
     */
    public function inRegion(string $region, Actor $actor): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $query = Query::objects()->blueprint(self::BLUEPRINT)->where('component_region', '=', $region)
            ->scope('by_weight')->limit(self::MAX_PLACED);

        return $this->queries->execute($query, $actor)->items;
    }

    /** The order after the last component of a region (since 0.3.4): where a component added to it goes. */
    public function nextWeight(string $region, Actor $actor): int
    {
        $last = 0;
        foreach ($this->inRegion($region, $actor) as $component) {
            $last = max($last, (int) $component->get('weight') + 1);
        }

        return $last;
    }

    /**
     * Moves a component up (-1) or down (+1) within its region (since 0.3.4): the region's
     * components are numbered 0, 1, 2 … in their order, the component swapped with its
     * neighbour. Nothing happens at the top, the bottom, or outside a region.
     */
    public function move(Actor $actor, CampanellaObject $component, int $by): void
    {
        $region = $component->has(Component::class) ? $component->as(Component::class)->region() : null;
        if ($region === null || ($by !== -1 && $by !== 1)) {
            return;
        }
        $order = $this->inRegion($region, $actor);
        $ids = array_map(static fn (CampanellaObject $c): int => (int) $c->id(), $order);
        $at = array_search((int) $component->id(), $ids, true);
        if ($at === false || !isset($ids[$at + $by])) {
            return;
        }
        [$order[$at], $order[$at + $by]] = [$order[$at + $by], $order[$at]];
        foreach ($order as $weight => $placed) {
            if ((int) $placed->get('weight') !== $weight) {
                $this->objects->update($actor, $placed, ['weight' => $weight]);
            }
        }
    }

    /**
     * Why a component does not work now, or null if it does: its source is gone (a deleted
     * object or list), its display is gone or cannot show the source any more.
     */
    public function problem(CampanellaObject $component): ?Message
    {
        if (!$component->has(Component::class)) {
            return new Message('components.not_component');
        }
        $definition = $component->as(Component::class);
        if (!$this->sourceExists($definition->source())) {
            return new Message('components.source_missing');
        }
        if (!$this->renderer->registry()->has($definition->display())) {
            return new Message('components.display_missing', ['display' => $definition->display()]);
        }
        $offered = array_map(static fn (DisplayDefinition $d): string => $d->name, $this->displaysFor($definition->source()));

        return in_array($definition->display(), $offered, true) ? null : new Message('components.display_unfit');
    }

    /** A component shown here; '' if it does not work (problem()) or its source shows nothing here. */
    public function render(CampanellaObject $component, DisplayContext $context): string
    {
        if (!$component->has(Component::class)) {
            return '';
        }
        $definition = $component->as(Component::class);
        try {
            return $this->renderer->show($definition->source(), $definition->display(), $definition->settings(), $context);
        } catch (DisplayException $e) {
            error_log(sprintf('Campanella: the component #%d is not shown: %s', (int) $component->id(), $e->getMessage()));

            return '';
        }
    }

    /**
     * The component of this machine name, if the visitor may see it (its `Restricted`
     * setting), shown here; '' otherwise.
     */
    public function show(string $key, DisplayContext $context): string
    {
        $component = $this->find($key, $context->actor);

        return $component === null ? '' : $this->render($component, $context);
    }

    /** The component of this machine name, if the actor may see it. */
    public function find(string $key, Actor $actor): ?CampanellaObject
    {
        if (!$this->isAvailable() || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1) {
            return null;
        }

        return $this->queries->first(Query::objects()->blueprint(self::BLUEPRINT)->where('machine_name', '=', $key), $actor);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function checked(string $source, string $display, array $settings): array
    {
        $checked = $this->check($source, $display, $settings);
        if ($checked['errors'] !== []) {
            throw new ValidationException($checked['errors']);
        }

        return $checked['settings'];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function values(CampanellaObject $configured, array $values): array
    {
        foreach (['component_source', 'component_display', 'component_settings'] as $field) {
            $values[$field] = $configured->get($field);
        }

        return $values;
    }
}
