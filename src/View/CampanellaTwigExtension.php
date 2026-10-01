<?php

declare(strict_types=1);

namespace Campanella\View;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Http\Flash;
use Campanella\Capability\Textual;
use Campanella\Capability\TextFormat;
use Campanella\Core\Version;
use Campanella\I18n\Translator;
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
 *   {{ theme_asset('style.css') }}      a file of the active theme (public/themes/<name>/)
 *   {{ render_object(item, 'teaser') }} an object in a presentation mode
 *   {{ related(object, 'categories') }} the loaded target objects of a relation
 *   {{ current_user() }}                the logged-in user or null
 *   {{ csrf_field() }}                  hidden CSRF field for POST forms
 *   {{ t('auth.login') }}               a user-facing text in the current language
 *   {{ locale() }}                      the current language code
 *   {{ admin_url('article') }}          an admin page URL; admin_access(): may the visitor enter it
 *   {{ flash_messages() }}              the one-time messages (and removes them)
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
     * @param (Closure(): Translator)|null $translator For t() and locale() (lazy).
     * @param Theme|null $theme The active theme, for theme_asset().
     * @param AdminAccess|null $admin Where the admin UI is and who may enter it.
     * @param (Closure(): Actor)|null $currentActor The current visitor (lazy).
     * @param (Closure(): Flash)|null $flash One-time messages (lazy).
     */
    public function __construct(
        private readonly Closure $presentation,
        private readonly Closure $basePath,
        private readonly array $globals = [],
        private readonly ?Closure $currentUser = null,
        private readonly ?Closure $csrfToken = null,
        private readonly ?Closure $translator = null,
        private readonly ?Theme $theme = null,
        private readonly ?AdminAccess $admin = null,
        private readonly ?Closure $currentActor = null,
        private readonly ?Closure $flash = null,
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
            new TwigFunction('theme_asset', $this->themeAsset(...)),
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('admin_url', $this->adminUrl(...)),
            new TwigFunction('admin_access', $this->adminAccess(...)),
            new TwigFunction('flash_messages', $this->flashMessages(...)),
            new TwigFunction('locale', $this->locale(...)),
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

    /**
     * A file of the active theme (public/themes/<name>/…), with the same cache buster as asset().
     */
    public function themeAsset(string $path): string
    {
        $theme = $this->theme ?? Theme::none();

        return $this->url('/' . $theme->assetPath($path)) . '?v=' . rawurlencode(Version::CAMPANELLA);
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

    /**
     * A user-facing text by key in the current language: {{ t('auth.login') }},
     * {{ t('auth.too_many_attempts', {minutes: 5}) }}. Without a translator, the key itself.
     *
     * @param array<string, string|int|float> $params
     */
    public function translate(string $key, array $params = []): string
    {
        return $this->translator === null ? $key : ($this->translator)()->translate($key, $params);
    }

    /** The current language code (e.g. for <html lang="...">). */
    public function locale(): string
    {
        return $this->translator === null ? Translator::BASE_LOCALE : ($this->translator)()->locale();
    }

    /** The URL of an admin page: {{ admin_url() }}, {{ admin_url('article') }}. */
    public function adminUrl(string $subpath = ''): string
    {
        return $this->url(($this->admin ?? new AdminAccess())->path($subpath));
    }

    /** Whether the current visitor may enter the admin UI (e.g. to show an "Admin" link). */
    public function adminAccess(): bool
    {
        return $this->admin !== null && $this->currentActor !== null && $this->admin->allows(($this->currentActor)());
    }

    /**
     * The one-time messages, translated, removed from the session.
     *
     * @return list<array{type: string, text: string}>
     */
    public function flashMessages(): array
    {
        if ($this->flash === null) {
            return [];
        }

        return array_map(
            fn (array $m): array => ['type' => $m['type'], 'text' => $this->translate($m['message']->key, $m['message']->params)],
            ($this->flash)()->take(),
        );
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
