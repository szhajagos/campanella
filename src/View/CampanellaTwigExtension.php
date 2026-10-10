<?php

declare(strict_types=1);

namespace Campanella\View;

use Campanella\Access\Actor;
use Campanella\Admin\AdminAccess;
use Campanella\Http\Flash;
use Campanella\Http\SitePaths;
use Campanella\Capability\MediaFile;
use Campanella\Capability\Textual;
use Campanella\Media\ResponsiveImages;
use Campanella\Capability\TextFormat;
use Campanella\Core\Version;
use Campanella\I18n\Translator;
use Campanella\Menu\MenuEntry;
use Campanella\Model\CampanellaObject;
use Campanella\Security\Csrf;
use Campanella\Tree\TreeBuilder;
use Campanella\Tree\TreeNode;
use Closure;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Campanella functions available in templates:
 *
 *   {{ url('/hirek') }}                 subdirectory-safe URL
 *   {{ path('login') }}                 a system page's URL (SitePaths: login, logout …; since 0.1.4)
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
 *   {{ tree(result) }}                  Hierarchical objects as a tree (TreeNode roots; hidden parents hide their branch)
 *   {% set main = menu('main') %}       a menu's items for the visitor (MenuEntry tree), or null if there is no such menu
 *   {{ object|body }}                   the safe HTML of the Textual body (its images with srcset, since 0.1.2)
 *   {{ image_url(image, 640) }}         an image's address, or of its smallest copy at least 640 wide
 *   {{ image_srcset(image) }}           an image's srcset ('' without copies)
 *   {{ 1572864|file_size }}             a size in bytes, readable: 1.5 MB (in the current language)
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
     * @param (Closure(string, int): ?list<MenuEntry>)|null $menus Builds a menu for the current
     *        visitor and page: key, levels (MenuBuilder; since 0.0.7).
     * @param (Closure(): ResponsiveImages)|null $images The images' copies (lazy; since 0.1.2).
     * @param SitePaths|null $paths The system pages' paths, for path() (since 0.1.4).
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
        private readonly ?Closure $menus = null,
        private readonly ?Closure $images = null,
        private readonly ?SitePaths $paths = null,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('url', $this->url(...)),
            new TwigFunction('path', $this->path(...)),
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
            new TwigFunction('tree', $this->tree(...)),
            new TwigFunction('menu', $this->menu(...)),
            new TwigFunction('image_url', $this->imageUrl(...)),
            new TwigFunction('image_srcset', $this->imageSrcset(...)),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('body', $this->body(...), ['is_safe' => ['html']]),
            new TwigFilter('file_size', $this->fileSize(...)),
        ];
    }

    #[\Override]
    public function getGlobals(): array
    {
        return $this->globals;
    }

    /**
     * A site path with the installation's prefix. Backslashes and control characters
     * are never passed on as they are (a browser would read `/\host` as another site):
     * they are percent-encoded, and leading slashes are collapsed into one.
     */
    public function url(string $path): string
    {
        $path = (string) preg_replace_callback('/[\\\\\x00-\x20\x7f]/', static fn (array $m): string => rawurlencode($m[0]), $path);

        return ($this->basePath)() . '/' . ltrim($path, '/');
    }

    /** The URL of a system page by its name (`path('login')`), with the installation's prefix. Since 0.1.4. */
    public function path(string $name): string
    {
        return $this->url(($this->paths ?? new SitePaths())->get($name));
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

    /**
     * A size in bytes, readable in the current language: `840 bytes`, `56 KB`, `1.5 MB`
     * (1 KB = 1024 bytes; one decimal below 10 MB; in Hungarian `1,5 MB`).
     */
    public function fileSize(int|float|string|null $bytes): string
    {
        $bytes = max(0, (int) $bytes);
        [$key, $value] = match (true) {
            $bytes < 1024 => ['format.bytes', (float) $bytes],
            $bytes < 1024 * 1024 => ['format.kb', $bytes / 1024],
            default => ['format.mb', $bytes / 1024 / 1024],
        };
        $decimals = $key !== 'format.bytes' && $value < 10 ? 1 : 0;
        $number = number_format($value, $decimals, ($this->translator === null ? '.' : $this->translate('format.decimal_point')), "\u{a0}");
        if ($decimals === 1 && str_ends_with($number, '0')) {
            $number = substr($number, 0, -2); // 2.0 -> 2
        }

        return $this->translate($key, ['n' => $number]);
    }

    /**
     * Hierarchical objects (e.g. a query's result) as a tree, in their order
     * (TreeBuilder::build()): the roots, each with `object`, `children`, `level`.
     * An object whose parent is not in the list (e.g. a draft the visitor cannot see)
     * is left out with its subtree, unless $keepOrphans is true (e.g. for a subtree
     * from descendantsOf(), whose top nodes should become the roots).
     *
     * @param iterable<CampanellaObject> $objects
     * @return list<TreeNode>
     */
    public function tree(iterable $objects, bool $keepOrphans = false): array
    {
        return TreeBuilder::build($objects, $keepOrphans);
    }

    /**
     * A menu's items for the current visitor, as a tree of MenuEntry (title, href,
     * current, active, external, children), at most $levels deep. Null if there is
     * no menu with this machine name, so a template can fall back to fixed links.
     * The navigation never takes the page down: if the menu cannot be read (e.g.
     * before the upgrade has created its tables), it is null too.
     *
     * @return list<MenuEntry>|null
     */
    public function menu(string $key, int $levels = 2): ?array
    {
        if ($this->menus === null) {
            return null;
        }
        try {
            return ($this->menus)($key, $levels);
        } catch (\Exception $e) {
            error_log('Campanella: the menu "' . $key . '" could not be built: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The address of an image object (with the installation's folder), or of its smallest
     * copy at least $width pixels wide (since 0.1.2). '' for an object that is not a file.
     */
    public function imageUrl(CampanellaObject $image, ?int $width = null): string
    {
        if (!$image->has(MediaFile::class)) {
            return '';
        }
        $path = $this->images !== null
            ? ($this->images)()->url($image, $width)
            : '/media/' . $image->as(MediaFile::class)->path();

        return $this->url($path);
    }

    /** An image object's `srcset` (its copies and the original); '' without copies. Since 0.1.2. */
    public function imageSrcset(CampanellaObject $image): string
    {
        if ($this->images === null || !$image->has(MediaFile::class)) {
            return '';
        }

        return ($this->images)()->srcset($image, ($this->basePath)());
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
            if ($this->images === null) {
                return $textual->body();
            }
            try {
                return ($this->images)()->enrich($textual->body(), ($this->basePath)());
            } catch (\Exception $e) {
                // The text is shown even if its images' copies cannot be looked up.
                error_log('Campanella: the images of a text could not be looked up: ' . $e->getMessage());

                return $textual->body();
            }
        }

        $paragraphs = preg_split('/\R{2,}/', trim($textual->body())) ?: [];

        return implode("\n", array_map(
            static fn (string $p): string => '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
            array_filter($paragraphs, static fn (string $p): bool => trim($p) !== ''),
        ));
    }
}
