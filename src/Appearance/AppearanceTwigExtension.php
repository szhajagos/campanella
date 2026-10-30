<?php

declare(strict_types=1);

namespace Campanella\Appearance;

use Campanella\Http\Request;
use Closure;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The look in templates (since 0.3.6):
 *
 *   color_mode()         `light` or `dark` for `<html data-bs-theme="…">`, or '' to follow the
 *                        visitor's system setting (then theme.js sets it)
 *   color_scheme_url()   the site's colour scheme as CSS, to link after campanella.css
 *   visitor_color_mode() the request's mode, `auto` included (the switch shows it)
 *
 * The core's base.html.twig uses both; admin/base.html.twig only color_mode().
 */
final class AppearanceTwigExtension extends AbstractExtension
{
    /**
     * @param Closure(): Appearance $appearance
     * @param Closure(): Request $request
     * @param Closure(): string $basePath
     */
    public function __construct(
        private readonly Closure $appearance,
        private readonly Closure $request,
        private readonly Closure $basePath,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('color_mode', $this->colorMode(...)),
            new TwigFunction('color_scheme_url', $this->colorSchemeUrl(...)),
            new TwigFunction('visitor_color_mode', $this->visitorColorMode(...)),
        ];
    }

    private function colorMode(): string
    {
        try {
            return ($this->appearance)()->theme(($this->request)());
        } catch (\Throwable) {
            return '';
        }
    }

    private function visitorColorMode(): string
    {
        try {
            return ($this->appearance)()->mode(($this->request)());
        } catch (\Throwable) {
            return Appearance::AUTO;
        }
    }

    private function colorSchemeUrl(): string
    {
        $version = ($this->appearance)()->version();

        return ($this->basePath)() . AppearanceController::PATH . '?v=' . $version;
    }
}
