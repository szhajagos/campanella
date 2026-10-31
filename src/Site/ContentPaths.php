<?php

declare(strict_types=1);

namespace Campanella\Site;

use Campanella\I18n\Translator;
use Campanella\Settings\Settings;

/**
 * The core's content paths in the site's language (since 0.3.7): the news, the categories
 * (both lists of config/queries.php, routed here) and the about page (only a link of the
 * starter menu, LayoutSetup). A new site gets its language's (NewSite):
 *
 *   hu   /hirek, /kategoriak, /rolunk
 *   en   /news, /categories, /about-us   (and any other language)
 *
 * saved as the settings `content.path.news`, `content.path.categories`, `content.path.about`.
 * A site installed before 0.3.7 has none of them, so it keeps the Hungarian paths it had:
 * nothing changes on the upgrade.
 */
final class ContentPaths
{
    public const array NAMES = ['news', 'categories', 'about'];

    /** The paths of a site that saved none (installed before 0.3.7). */
    public const array LEGACY = ['news' => '/hirek', 'categories' => '/kategoriak', 'about' => '/rolunk'];

    /** A new site's paths by its language; a language not here gets English. */
    public const array BY_LANGUAGE = [
        'hu' => self::LEGACY,
        'en' => ['news' => '/news', 'categories' => '/categories', 'about' => '/about-us'],
    ];

    /** The lists of config/queries.php routed at the paths, and their titles' message keys. */
    public const array ROUTES = [
        'news' => ['query' => 'news', 'title' => 'site.nav.news', 'per_page' => 5],
        'categories' => ['query' => 'categories', 'title' => 'site.nav.categories'],
    ];

    public function __construct(private readonly ?Settings $settings = null)
    {
    }

    /** The path: the saved one, else the one sites had before 0.3.7. */
    public function get(string $name): string
    {
        if (!isset(self::LEGACY[$name])) {
            throw new \InvalidArgumentException("Not a content path: {$name}.");
        }
        try {
            $saved = $this->settings !== null && $this->settings->isAvailable() ? $this->settings->get('content.path.' . $name) : null;
        } catch (\Throwable) {
            $saved = null; // before the installation
        }

        return is_string($saved) && preg_match('#^/[a-z0-9][a-z0-9/_-]*$#D', $saved) === 1 ? $saved : self::LEGACY[$name];
    }

    /**
     * A new site's paths in the language.
     *
     * @return array<string, string> name => path
     */
    public static function forLanguage(string $locale): array
    {
        return self::BY_LANGUAGE[substr($locale, 0, 2)] ?? self::BY_LANGUAGE['en'];
    }

    /**
     * The routes of the lists: path => [handler, parameters], their titles translated.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public function routes(Translator $translator): array
    {
        $routes = [];
        foreach (self::ROUTES as $name => $params) {
            $routes[$this->get($name)] = ['query', ['title' => $translator->translate($params['title'])] + $params];
        }

        return $routes;
    }
}
