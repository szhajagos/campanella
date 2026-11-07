<?php

declare(strict_types=1);

namespace Campanella\Layout;

/**
 * A layout (since 0.3.4): its template and its regions, from its JSON file beside the
 * template (`layout/<name>.json`, of the core, a module or the theme; since 0.4.10 also made in
 * the admin, in the `layouts` table):
 *
 *     {"version": 1, "label": "layout.sidebar", "regions": {
 *         "header": {"label": "layout.region.header"},
 *         "navigation": {"label": "layout.region.navigation", "small": "collapse", "breakpoint": "md"},
 *         "content": {"label": "layout.region.content"}, …}}
 *
 * A layout has a `content` region: the page's own content is its first part.
 *
 * Since 0.4.10 a layout can describe its rows of columns instead of having a template of its
 * own (`rows`): the shared template `layout/_rows.html.twig` makes its HTML, between the
 * header (`header`, `navigation`) and the footer (`footer`), which are always there:
 *     "rows": [{"breakpoint": "lg", "columns": [
 *         {"region": "content", "width": 8, "order": 1},
 *         {"region": "sidebar", "width": 4, "order": 2, "landmark": "aside"}]},
 *       {"full": true, "columns": [{"region": "kiemeles", "width": 12}]}]
 * Each region of the rows is described in `regions`, and each of `regions` but the fixed ones
 * is in the rows, once; `content` is in them. At most MAX_ROWS rows, LayoutRow::MAX_COLUMNS
 * columns in a row, their widths (1–12) at most 12 together, `order` 1–4, unique in a row.
 */
final readonly class LayoutDefinition
{
    public const int VERSION = 1;

    /** The most regions of a layout. */
    public const int MAX_REGIONS = 20;

    public const string CONTENT = 'content';

    /** The most rows of a layout (since 0.4.10). */
    public const int MAX_ROWS = 10;

    /** The regions of the header and the footer: in every layout of rows, not in its rows (since 0.4.10). */
    public const array FIXED = [
        'header' => ['label' => 'layout.region.header'],
        'navigation' => ['label' => 'layout.region.navigation', 'small' => RegionDefinition::COLLAPSE, 'breakpoint' => 'md'],
        'footer' => ['label' => 'layout.region.footer'],
    ];

    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/D';

    /**
     * @param array<string, RegionDefinition> $regions name => region, in their order
     * @param string $origin `core`, `theme` (a module's too) or, since 0.4.10, `admin`
     * @param list<LayoutRow> $rows Its rows (since 0.4.10); [] if its template is its own
     * @param bool $ownTemplate Whether `layout/<name>.html.twig` is there (a layout of rows may have one too)
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $regions,
        public string $origin = 'core',
        public array $rows = [],
        public bool $ownTemplate = true,
    ) {
    }

    /** The template: `layout/<name>.html.twig`, or the shared one of a layout of rows without its own. */
    public function template(): string
    {
        return $this->rows !== [] && !$this->ownTemplate ? 'layout/_rows.html.twig' : 'layout/' . $this->name . '.html.twig';
    }

    /** Whether it is a layout of rows (and so can be copied, or edited if made in the admin). */
    public function hasRows(): bool
    {
        return $this->rows !== [];
    }

    /**
     * As its JSON writes it (since 0.4.10): of a layout of rows, what the admin saves and the
     * content model's file carries.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $regions = [];
        foreach ($this->regions as $name => $region) {
            $regions[$name] = array_filter([
                'label' => $region->label,
                'small' => $region->small === RegionDefinition::ALWAYS ? null : $region->small,
                'breakpoint' => $region->small === RegionDefinition::ALWAYS ? null : $region->breakpoint,
                'page' => $region->page ? true : null,
            ], static fn ($v): bool => $v !== null);
        }

        return array_filter([
            'version' => self::VERSION,
            'label' => $this->label,
            'rows' => $this->rows === [] ? null : array_map(static fn (LayoutRow $r): array => $r->toArray(), $this->rows),
            'regions' => $regions,
        ], static fn ($v): bool => $v !== null);
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
    public static function fromJson(string $name, string $json, string $origin = 'core', bool $ownTemplate = true): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
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
        $hasRows = array_key_exists('rows', $data);
        if ($hasRows && is_array($data['regions'] ?? null)) {
            // A layout of rows: the header's and the footer's regions are always there.
            $data['regions'] += self::FIXED;
        }
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
            $page = $region['page'] ?? false;
            if (!is_bool($page)) {
                throw new \InvalidArgumentException("Layout {$name}, region {$regionName}: \"page\" must be true or false.");
            }
            if (!is_string($regionLabel) || trim($regionLabel) === '' || mb_strlen($regionLabel, 'UTF-8') > 100
                || !in_array($small, RegionDefinition::LOOKS, true) || !in_array($breakpoint, RegionDefinition::BREAKPOINTS, true)) {
                throw new \InvalidArgumentException("Layout {$name}, region {$regionName}: its label, \"small\" (always, collapse, hide) or \"breakpoint\" (sm, md, lg, xl) is not valid.");
            }
            $regions[$regionName] = new RegionDefinition($regionName, trim($regionLabel), $small, $breakpoint, $page);
        }
        if (!isset($regions[self::CONTENT])) {
            throw new \InvalidArgumentException("Layout {$name}: it has no \"content\" region.");
        }
        $rows = $hasRows ? self::rows($name, $data['rows'], $regions) : [];
        if ($rows !== []) {
            // A layout of rows: its regions in the page's order (the header's, the rows', the footer), whatever the JSON's
            // (MySQL's JSON column sorts the keys).
            $ordered = ['header' => $regions['header'], 'navigation' => $regions['navigation']];
            foreach ($rows as $row) {
                foreach ($row->columns as $column) {
                    $ordered[$column->region] = $regions[$column->region];
                }
            }
            $regions = $ordered + ['footer' => $regions['footer']];
        }
        if (!$hasRows && !$ownTemplate) {
            throw new \InvalidArgumentException("Layout {$name}: its template layout/{$name}.html.twig is missing.");
        }

        return new self($name, trim($label), $regions, $origin, $rows, $ownTemplate);
    }

    /**
     * The rows of a layout's JSON, checked against its regions.
     *
     * @param array<string, RegionDefinition> $regions
     * @return list<LayoutRow>
     */
    private static function rows(string $name, mixed $data, array $regions): array
    {
        if (!is_array($data) || !array_is_list($data) || $data === [] || count($data) > self::MAX_ROWS) {
            throw new \InvalidArgumentException("Layout {$name}: \"rows\" must be a list of 1 to " . self::MAX_ROWS . ' rows.');
        }
        $rows = [];
        $placed = [];
        foreach ($data as $index => $row) {
            $at = "Layout {$name}, row " . ($index + 1);
            if (!is_array($row) || array_diff(array_keys($row), ['columns', 'breakpoint', 'full']) !== []) {
                throw new \InvalidArgumentException("{$at}: a row has \"columns\", and may have \"breakpoint\" and \"full\".");
            }
            $breakpoint = $row['breakpoint'] ?? 'lg';
            $full = $row['full'] ?? false;
            if (!in_array($breakpoint, RegionDefinition::BREAKPOINTS, true) || !is_bool($full)) {
                throw new \InvalidArgumentException("{$at}: \"breakpoint\" is sm, md, lg or xl; \"full\" true or false.");
            }
            $columnsData = $row['columns'] ?? null;
            if (!is_array($columnsData) || !array_is_list($columnsData) || $columnsData === [] || count($columnsData) > LayoutRow::MAX_COLUMNS) {
                throw new \InvalidArgumentException("{$at}: \"columns\" must be a list of 1 to " . LayoutRow::MAX_COLUMNS . ' columns.');
            }
            $columns = [];
            $width = 0;
            $orders = [];
            foreach ($columnsData as $column) {
                if (!is_array($column) || array_diff(array_keys($column), ['region', 'width', 'order', 'landmark']) !== []) {
                    throw new \InvalidArgumentException("{$at}: a column has \"region\" and \"width\", and may have \"order\" and \"landmark\".");
                }
                $region = $column['region'] ?? null;
                if (!is_string($region) || !isset($regions[$region]) || isset(self::FIXED[$region])) {
                    throw new \InvalidArgumentException("{$at}: a column's region must be one of \"regions\", not header, navigation or footer.");
                }
                if (isset($placed[$region])) {
                    throw new \InvalidArgumentException("{$at}: the region {$region} is in the rows twice.");
                }
                $placed[$region] = true;
                $columnWidth = $column['width'] ?? LayoutRow::GRID;
                $order = $column['order'] ?? null;
                $landmark = $column['landmark'] ?? LayoutColumn::NONE;
                if (!is_int($columnWidth) || $columnWidth < 1 || $columnWidth > LayoutRow::GRID) {
                    throw new \InvalidArgumentException("{$at}, {$region}: \"width\" must be 1 to " . LayoutRow::GRID . '.');
                }
                if ($order !== null && (!is_int($order) || $order < 1 || $order > LayoutRow::MAX_COLUMNS || isset($orders[$order]))) {
                    throw new \InvalidArgumentException("{$at}, {$region}: \"order\" must be 1 to " . LayoutRow::MAX_COLUMNS . ', each once in a row.');
                }
                if (!in_array($landmark, LayoutColumn::LANDMARKS, true)) {
                    throw new \InvalidArgumentException("{$at}, {$region}: \"landmark\" is aside or none.");
                }
                if ($order !== null) {
                    $orders[$order] = true;
                }
                $width += $columnWidth;
                $columns[] = new LayoutColumn($region, $columnWidth, $order, $landmark);
            }
            if ($width > LayoutRow::GRID) {
                throw new \InvalidArgumentException("{$at}: its columns' widths are {$width} together, more than " . LayoutRow::GRID . '.');
            }
            $rows[] = new LayoutRow($columns, $breakpoint, $full);
        }
        foreach (array_keys($regions) as $region) {
            if (!isset($placed[$region]) && !isset(self::FIXED[$region])) {
                throw new \InvalidArgumentException("Layout {$name}: the region {$region} is in no row.");
            }
        }

        return $rows;
    }
}
