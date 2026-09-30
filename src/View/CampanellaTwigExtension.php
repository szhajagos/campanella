<?php

declare(strict_types=1);

namespace Campanella\View;

use Campanella\Capability\Textual;
use Campanella\Capability\TextFormat;
use Campanella\Core\Version;
use Campanella\Model\CampanellaObject;
use Campanella\Security\Csrf;
use Closure;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Campanella functions available in templates:
 *
 *   {{ url('/hirek') }}                 subdirectory-safe URL
 *   {{ asset('campanella.css') }}       a file under public/assets/
 *   {{ render_object(item, 'teaser') }} an object in a presentation mode
 *   {{ related(object, 'categories') }} the loaded target objects of a relation
 *   {{ current_user() }}                the logged-in user or null
 *   {{ csrf_field() }}                  hidden CSRF field for POST forms
 *   {{ object|body }}                   the safe HTML of the Textual body
 */
final class CampanellaTwigExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @param Closure(): Presentation $presentation Lazy, because Presentation itself builds on Twig.
     * @param Closure(): string $basePath The URL prefix of the current request (for installation in a subdirectory).
     * @param array<string, mixed> $globals
     * @param (Closure(): ?CampanellaObject)|null $currentUser The logged-in user (lazy).
     * @param (Closure(): string)|null $csrfToken The current CSRF token (lazy; starts a session).
     */
    public function __construct(
        private readonly Closure $presentation,
        private readonly Closure $basePath,
        private readonly array $globals = [],
        private readonly ?Closure $currentUser = null,
        private readonly ?Closure $csrfToken = null,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('url', $this->url(...)),
            new TwigFunction('asset', $this->asset(...)),
            new TwigFunction('render_object', $this->renderObject(...), ['is_safe' => ['html']]),
            new TwigFunction('related', $this->related(...)),
            new TwigFunction('current_user', $this->currentUser(...)),
            new TwigFunction('csrf_field', $this->csrfField(...), ['is_safe' => ['html']]),
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
        return ($this->basePath)() . '/' . ltrim($path, '/');
    }

    /**
     * The URL of a file under public/assets, with the Campanella version as a
     * cache buster (?v=0.0.3): after an upgrade the browser is sure to download
     * the new CSS and JS, not the previously cached ones.
     */
    public function asset(string $path): string
    {
        return $this->url('/assets/' . ltrim($path, '/')) . '?v=' . rawurlencode(Version::CAMPANELLA);
    }

    public function renderObject(CampanellaObject $object, string $mode = Presentation::TEASER): string
    {
        return ($this->presentation)()->renderObject($object, $mode);
    }

    /**
     * The loaded target objects of a relation. If the controller did not load
     * them (RelationLoader), or the object has no such relation: an empty list.
     *
     * @return list<CampanellaObject>
     */
    public function related(CampanellaObject $object, string $relation): array
    {
        return $object->hasRelation($relation) && $object->isResolved($relation)
            ? $object->relatedObjects($relation)
            : [];
    }

    /** The logged-in user, or null. Does not start a session for an anonymous visitor. */
    public function currentUser(): ?CampanellaObject
    {
        return $this->currentUser === null ? null : ($this->currentUser)();
    }

    /** Hidden field with the CSRF token; it must be put into every POST form. */
    public function csrfField(): string
    {
        if ($this->csrfToken === null) {
            return '';
        }

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            Csrf::FIELD,
            htmlspecialchars(($this->csrfToken)(), ENT_QUOTES, 'UTF-8'),
        );
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
