<?php

declare(strict_types=1);

namespace Campanella\Layout;

/**
 * A layout (since 0.3.4): its template and its regions, from its JSON file beside the
 * template (`layout/<name>.json`, of the core or the theme):
 *
 *     {"version": 1, "label": "layout.sidebar", "regions": {
 *         "header": {"label": "layout.region.header"},
 *         "navigation": {"label": "layout.region.navigation", "small": "collapse", "breakpoint": "md"},
 *         "content": {"label": "layout.region.content"}, …}}
 *
 * A layout has a `content` region: the page's own content is its first part.
 */
final readonly class LayoutDefinition
{
    public const int VERSION = 1;

    /** The most regions of a layout. */
    public const int MAX_REGIONS = 20;

    public const string CONTENT = 'content';

    /**
     * @param array<string, RegionDefinition> $regions name => region, in their order
     * @param string $origin `core` or `theme`
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $regions,
        public string $origin = 'core',
    ) {
    }

    /** The template: `layout/<name>.html.twig`. */
    public function template(): string
    {
        return 'layout/' . $this->name . '.html.twig';
    }

    public function hasRegion(string $name): bool
    {
        return isset($this->regions[$name]);
    }

    /**
     * From a layout's JSON.
     *
     * @throws \InvalidArgumentException if it is not a valid layout
     */
    public static function fromJson(string $name, string $json, string $origin = 'core'): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid layout name: {$name}");
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Layout {$name}: not valid JSON ({$e->getMessage()}).");
        }
        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException("Layout {$name}: \"version\" must be " . self::VERSION . '.');
        }
        $label = $data['label'] ?? $name;
        if (!is_string($label) || trim($label) === '' || mb_strlen($label, 'UTF-8') > 100) {
            throw new \InvalidArgumentException("Layout {$name}: \"label\" must be a text of at most 100 characters.");
        }
        $regions = [];
        if (!is_array($data['regions'] ?? null) || $data['regions'] === [] || count($data['regions']) > self::MAX_REGIONS) {
            throw new \InvalidArgumentException("Layout {$name}: \"regions\" must name 1 to " . self::MAX_REGIONS . ' regions.');
        }
        foreach ($data['regions'] as $regionName => $region) {
            $regionName = (string) $regionName;
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $regionName) !== 1 || !is_array($region)) {
                throw new \InvalidArgumentException("Layout {$name}: invalid region " . json_encode($regionName) . '.');
            }
            $regionLabel = $region['label'] ?? $regionName;
            $small = $region['small'] ?? RegionDefinition::ALWAYS;
            $breakpoint = $region['breakpoint'] ?? 'md';
            if (!is_string($regionLabel) || trim($regionLabel) === '' || mb_strlen($regionLabel, 'UTF-8') > 100
                || !in_array($small, RegionDefinition::LOOKS, true) || !in_array($breakpoint, RegionDefinition::BREAKPOINTS, true)) {
                throw new \InvalidArgumentException("Layout {$name}, region {$regionName}: its label, \"small\" (always, collapse, hide) or \"breakpoint\" (sm, md, lg, xl) is not valid.");
            }
            $regions[$regionName] = new RegionDefinition($regionName, trim($regionLabel), $small, $breakpoint);
        }
        if (!isset($regions[self::CONTENT])) {
            throw new \InvalidArgumentException("Layout {$name}: it has no \"content\" region.");
        }

        return new self($name, trim($label), $regions, $origin);
    }
}
