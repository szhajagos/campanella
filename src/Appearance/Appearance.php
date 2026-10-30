<?php

declare(strict_types=1);

namespace Campanella\Appearance;

use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\Model\ValidationException;
use Campanella\Settings\Settings;

/**
 * The site's look (since 0.3.6): its colour scheme and its colour mode, from the settings
 * (saved on Appearance → Colour schemes):
 *
 *   appearance.scheme       the colour scheme's name (default: `default`, the look before 0.3.6)
 *   appearance.color_mode   `auto` (the visitor's system setting, the default), `light` or `dark`
 *
 * A visitor's own choice (the colour mode switch, a cookie) comes before the site's mode.
 * The admin follows the mode too, but never the scheme: it stays neutral.
 */
final class Appearance
{
    public const string AUTO = 'auto';

    public const array MODES = [self::AUTO, 'light', 'dark'];

    /** The visitor's own colour mode (`auto`, `light`, `dark`). */
    public const string COOKIE = 'campanella_color_mode';

    private ?string $css = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly ColorSchemeRegistry $schemes,
    ) {
    }

    public function registry(): ColorSchemeRegistry
    {
        return $this->schemes;
    }

    /** The site's colour scheme (the default one if the saved one is no longer there). */
    public function scheme(): ColorScheme
    {
        return $this->schemes->get($this->setting('appearance.scheme') ?? ColorSchemeRegistry::DEFAULT);
    }

    /** The saved scheme's name, if it is no longer there (e.g. the theme was changed): for the admin's warning. */
    public function missingScheme(): ?string
    {
        $name = $this->setting('appearance.scheme');

        return $name !== null && !$this->schemes->has($name) ? $name : null;
    }

    /** The site's colour mode: `auto`, `light` or `dark`. */
    public function siteMode(): string
    {
        $mode = $this->setting('appearance.color_mode');

        return in_array($mode, self::MODES, true) ? $mode : self::AUTO;
    }

    /** The colour mode for this request: the visitor's own choice, or the site's. */
    public function mode(Request $request): string
    {
        $own = $request->cookies[self::COOKIE] ?? null;

        return is_string($own) && in_array($own, self::MODES, true) ? $own : $this->siteMode();
    }

    /** The page's `data-bs-theme`: `light` or `dark`, or '' to follow the system (theme.js decides). */
    public function theme(Request $request): string
    {
        $mode = $this->mode($request);

        return $mode === self::AUTO ? '' : $mode;
    }

    /** The scheme's CSS (`/color-scheme.css`). */
    public function css(): string
    {
        return $this->css ??= $this->scheme()->css();
    }

    /** The CSS's version, for its address (`?v=`): changes with the scheme and its colours. */
    public function version(): string
    {
        return substr(hash('sha256', $this->css()), 0, 12);
    }

    /**
     * Saves the admin's choices: the scheme and the mode (null keeps one as it is). All or
     * nothing: an unknown scheme (`scheme`) or mode (`color_mode`) is a problem.
     *
     * @throws ValidationException
     */
    public function save(?string $scheme, ?string $mode): void
    {
        $values = [];
        $problems = [];
        if ($scheme !== null) {
            $this->schemes->has($scheme) ? $values['appearance.scheme'] = $scheme === ColorSchemeRegistry::DEFAULT ? null : $scheme : $problems['scheme'] = new Message('appearance.scheme_unknown');
        }
        if ($mode !== null) {
            in_array($mode, self::MODES, true) ? $values['appearance.color_mode'] = $mode === self::AUTO ? null : $mode : $problems['color_mode'] = new Message('appearance.mode_invalid');
        }
        if ($problems !== []) {
            throw new ValidationException($problems);
        }
        if ($values !== []) {
            $this->settings->set($values);
            $this->css = null;
        }
    }

    /** A setting, or null before the installation (no settings table yet). */
    private function setting(string $name): ?string
    {
        try {
            return $this->settings->isAvailable() ? $this->settings->get($name) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
