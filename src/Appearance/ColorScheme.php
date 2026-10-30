<?php

declare(strict_types=1);

namespace Campanella\Appearance;

/**
 * A colour scheme (since 0.3.6): the site's colours in a light and a dark variant, from its
 * JSON file (`scheme/<name>.json`, of the core or the theme):
 *
 *     {"version": 1, "label": "scheme.forest",
 *      "light": {"accent": "#2f6b3a", "accent_hover": "#24532d", "on_accent": "#ffffff",
 *                "background": "#f8faf6", "text": "#1b1f1b", "muted": "#5f6a60",
 *                "border": "#dfe5dc", "surface": "#eef3eb"},
 *      "dark": {…the same colours…}}
 *
 * Every colour is `#rrggbb`. A scheme is readable, or it is not taken: in both variants the
 * text, the muted text and the links (the accent) on the background, the text on the
 * surface and on the accent's subtle shade, and the text on the accent (buttons) and on
 * its hover have a contrast of at least 4.5:1 (WCAG 2.2 AA).
 */
final readonly class ColorScheme
{
    public const int VERSION = 1;

    /** The colours of a variant, and what each is for. */
    public const array COLORS = [
        'accent' => 'links, buttons, the current menu item',
        'accent_hover' => 'the accent under the pointer',
        'on_accent' => 'text on the accent (a button\'s label)',
        'background' => 'the page',
        'text' => 'the text',
        'muted' => 'secondary text (dates, help)',
        'border' => 'lines and borders',
        'surface' => 'boxes on the page (an info box, a card)',
    ];

    /** The pairs that must be readable: text colour => background colour. */
    public const array READABLE = [
        ['text', 'background'],
        ['muted', 'background'],
        ['accent', 'background'],
        ['text', 'surface'],
        ['on_accent', 'accent'],
        ['on_accent', 'accent_hover'],
    ];

    /** How much of the accent the subtle shades have (the rest is the background). */
    public const float SUBTLE = 0.12;

    public const float SUBTLE_BORDER = 0.35;

    /** The least contrast of a readable pair (WCAG 2.2 AA, normal text). */
    public const float MIN_CONTRAST = 4.5;

    public const array VARIANTS = ['light', 'dark'];

    /**
     * @param array<string, string> $light colour => #rrggbb
     * @param array<string, string> $dark colour => #rrggbb
     * @param string $origin `core` or `theme`
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $light,
        public array $dark,
        public string $origin = 'core',
    ) {
    }

    /** @throws \InvalidArgumentException if the JSON is not a valid, readable scheme */
    public static function fromJson(string $name, string $json, string $origin = 'core'): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1) {
            throw new \InvalidArgumentException("Colour scheme {$name}: the name may have small letters, digits and _ only.");
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Colour scheme {$name}: not valid JSON ({$e->getMessage()}).");
        }
        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException("Colour scheme {$name}: \"version\" must be " . self::VERSION . '.');
        }
        $label = $data['label'] ?? null;
        if (!is_string($label) || $label === '' || mb_strlen($label) > 100) {
            throw new \InvalidArgumentException("Colour scheme {$name}: \"label\" must be a text of at most 100 characters.");
        }
        $variants = [];
        foreach (self::VARIANTS as $variant) {
            $colors = $data[$variant] ?? null;
            if (!is_array($colors)) {
                throw new \InvalidArgumentException("Colour scheme {$name}: \"{$variant}\" is missing.");
            }
            $unknown = array_diff(array_keys($colors), array_keys(self::COLORS));
            if ($unknown !== []) {
                throw new \InvalidArgumentException("Colour scheme {$name}: {$variant}." . reset($unknown) . ' is not a colour of a scheme.');
            }
            /** @var array<string, string> $checked */
            $checked = [];
            foreach (array_keys(self::COLORS) as $color) {
                $value = $colors[$color] ?? null;
                if (!is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/D', $value) !== 1) {
                    throw new \InvalidArgumentException("Colour scheme {$name}: {$variant}.{$color} must be a colour such as #1d1d1f.");
                }
                $checked[$color] = strtolower($value);
            }
            $variants[$variant] = $checked;
            // The text on the accent's subtle shade too (an info box in the primary style).
            $checked['accent_subtle'] = self::mix($checked['accent'] ?? '#000000', $checked['background'] ?? '#000000', self::SUBTLE);
            foreach ([...self::READABLE, ['text', 'accent_subtle']] as [$fore, $back]) {
                $ratio = self::contrast($checked[$fore] ?? '#000000', $checked[$back] ?? '#000000');
                if ($ratio < self::MIN_CONTRAST) {
                    throw new \InvalidArgumentException(sprintf(
                        'Colour scheme %s: %s.%s on %s.%s is hard to read (contrast %.2f:1, at least %.1f:1 is needed).',
                        $name, $variant, $fore, $variant, $back, $ratio, self::MIN_CONTRAST,
                    ));
                }
            }
        }

        return new self($name, $label, $variants['light'], $variants['dark'], $origin);
    }

    /** The contrast ratio of two #rrggbb colours (WCAG 2.2): 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** The relative luminance of a #rrggbb colour (WCAG 2.2). */
    public static function luminance(string $color): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($color));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** Two #rrggbb colours mixed: $weight of the first (0 to 1), the rest of the second. */
    public static function mix(string $a, string $b, float $weight): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);

        return sprintf('#%02x%02x%02x', ...array_map(static fn (int $i): int => (int) round($x[$i] * $weight + $y[$i] * (1 - $weight)), [0, 1, 2]));
    }

    /** @return array{int, int, int} */
    public static function rgb(string $color): array
    {
        $hex = ltrim($color, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * The scheme as CSS: the variables of campanella.css (`--cc-…`) and Bootstrap's
     * (`--bs-…`), for the light variant (`:root`, `[data-bs-theme="light"]`) and the dark one.
     */
    public function css(): string
    {
        $css = "/* Campanella colour scheme: {$this->name} (generated; see docs/php-api/31-appearance.md) */\n";
        foreach (self::VARIANTS as $variant) {
            $colors = $variant === 'light' ? $this->light : $this->dark;
            $css .= ($variant === 'light' ? ":root,\n[data-bs-theme=\"light\"]" : '[data-bs-theme="dark"]') . " {\n";
            foreach ([
                '--cc-accent' => $colors['accent'],
                '--cc-accent-rgb' => implode(', ', self::rgb($colors['accent'])),
                '--cc-accent-hover' => $colors['accent_hover'],
                '--cc-on-accent' => $colors['on_accent'],
                '--bs-body-bg' => $colors['background'],
                '--bs-body-color' => $colors['text'],
                '--bs-border-color' => $colors['border'],
                '--bs-secondary-color' => $colors['muted'],
                '--bs-tertiary-bg' => $colors['surface'],
                '--bs-body-bg-rgb' => implode(', ', self::rgb($colors['background'])),
                '--bs-body-color-rgb' => implode(', ', self::rgb($colors['text'])),
                '--bs-tertiary-bg-rgb' => implode(', ', self::rgb($colors['surface'])),
                '--bs-primary-bg-subtle' => self::mix($colors['accent'], $colors['background'], self::SUBTLE),
                '--bs-primary-border-subtle' => self::mix($colors['accent'], $colors['background'], self::SUBTLE_BORDER),
                '--bs-primary-text-emphasis' => $colors['accent_hover'],
            ] as $property => $value) {
                $css .= "    {$property}: {$value};\n";
            }
            $css .= "}\n";
        }

        return $css;
    }
}
