<?php

declare(strict_types=1);

namespace Campanella\View;

use Campanella\Capability\Textual;
use Campanella\Capability\TextFormat;
use Campanella\Model\CampanellaObject;
use Closure;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * A sablonokban elérhető Campanella-függvények:
 *
 *   {{ url('/hirek') }}                 alkönyvtár-biztos URL
 *   {{ asset('campanella.css') }}       public/assets/ alatti fájl
 *   {{ render_object(item, 'teaser') }} egy objektum egy megjelenítési módban
 *   {{ object|body }}                   a Textual törzs biztonságos HTML-je
 */
final class CampanellaTwigExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @param Closure(): Presentation $presentation Lustán, mert a Presentation is a Twig-re épül.
     * @param array<string, mixed> $globals
     */
    public function __construct(
        private readonly Closure $presentation,
        private readonly string $basePath,
        private readonly array $globals = [],
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('url', $this->url(...)),
            new TwigFunction('asset', fn (string $path): string => $this->url('/assets/' . ltrim($path, '/'))),
            new TwigFunction('render_object', $this->renderObject(...), ['is_safe' => ['html']]),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('body', $this->body(...), ['is_safe' => ['html']]),
        ];
    }

    #[\Override]
    public function getGlobals(): array
    {
        return $this->globals;
    }

    public function url(string $path): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    public function renderObject(CampanellaObject $object, string $mode = Presentation::TEASER): string
    {
        return ($this->presentation)()->renderObject($object, $mode);
    }

    public function body(CampanellaObject $object): string
    {
        if (!$object->has(Textual::class)) {
            return '';
        }
        $textual = $object->as(Textual::class);

        if ($textual->format() === TextFormat::Html) {
            return $textual->body();
        }

        $paragraphs = preg_split('/\R{2,}/', trim($textual->body())) ?: [];

        return implode("\n", array_map(
            static fn (string $p): string => '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
            array_filter($paragraphs, static fn (string $p): bool => trim($p) !== ''),
        ));
    }
}
