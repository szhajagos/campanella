<?php

declare(strict_types=1);

namespace Campanella\Layout;

use Campanella\Access\Actor;
use Campanella\Display\ComponentService;
use Campanella\I18n\Translator;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;
use Campanella\Settings\Settings;
use Campanella\Site\ContentPaths;

/**
 * The site's header and footer as components (since 0.3.4): what the core's base.html.twig
 * showed before, made once, on a new installation and on the upgrade to 0.3.4, and then the
 * layouts turned on (`layout.enabled`).
 *
 *   header       the site's name (branding, without the slogan)
 *   navigation   the main menu, the search box, the admin button, the login button
 *   footer       a snippet: © [date:year] [site:name] · Campanella [campanella:version]
 *
 * A site without a main menu gets one with the links the header had built in (the front
 * page, the news, the categories, about us); its id is kept (`layout.starter_menu`), so
 * `seed` can fill it with the sample menu. Once `layout.enabled` is set (to anything)
 * nothing is done again, so the components can be changed or deleted in the admin. On a
 * site whose `component` Blueprint is its own (made before 0.3.3), the layouts stay off.
 */
final class LayoutSetup
{
    /** The main menu's machine name (as menu('main') finds it). */
    public const string MAIN_MENU = 'main';

    /** The setting that names the main menu made here (while it has only the built-in links). */
    public const string STARTER_MENU = 'layout.starter_menu';

    /**
     * The built-in links of the header before 0.3.4: message key => path; since 0.3.7 a
     * content path's name (ContentPaths: `/news` on an English site) in place of a path.
     */
    public const array STARTER_LINKS = [
        'site.nav.home' => '/',
        'site.nav.news' => 'news',
        'site.nav.categories' => 'categories',
        'site.nav.about' => 'about',
    ];

    public const string FOOTER_TEXT = '© [date:year] [site:name] · Campanella [campanella:version]';

    public function __construct(
        private readonly Settings $settings,
        private readonly ComponentService $components,
        private readonly ObjectService $objects,
        private readonly QueryEngine $queries,
        private readonly BlueprintRegistry $blueprints,
        private readonly Translator $translator,
        private readonly ContentPaths $paths = new ContentPaths(),
    ) {
    }

    /** Whether it is still to be done: the layouts were never turned on (nor off). */
    public function isPending(): bool
    {
        $this->settings->reset(); // the tables may have just been made

        return $this->settings->isAvailable() && $this->settings->get('layout.enabled') === null;
    }

    /**
     * Makes the header's and footer's components and turns the layouts on, if not done yet.
     *
     * @return list<string>|null The components made (their titles), or null if nothing was
     *                           to be done (done before, or there are no components here)
     */
    public function ensure(): ?array
    {
        if (!$this->isPending() || !$this->components->isAvailable()) {
            return null;
        }
        $system = Actor::system();
        $made = [];
        $add = function (string $title, string $key, string $source, string $display, array $settings, string $region, int $weight) use ($system, &$made): void {
            $component = $this->components->create($system, ['title' => $this->translator->translate($title), 'machine_name' => $this->freeKey($key)], $source, $display, $settings);
            $this->components->place($system, $component, $region, null, $weight);
            $made[] = (string) $component->get('title');
        };

        $add('setup.branding', 'site_branding', 'system:site', 'branding', ['show_slogan' => '0'], 'header', 0);
        $menu = $this->mainMenu($system);
        if ($menu !== null) {
            $add('setup.main_menu', 'main_menu', 'object:' . $menu->id(), 'navigation', ['style' => 'horizontal', 'depth' => '2'], 'navigation', 0);
        }
        $add('setup.search', 'site_search', 'system:search', 'search_box', [], 'navigation', 1);
        $add('setup.admin_button', 'admin_button', 'system:visitor', 'admin_button', [], 'navigation', 2);
        $add('setup.login_button', 'login_button', 'system:visitor', 'login_button', [], 'navigation', 3);
        if ($this->isCoreSnippet()) {
            $footer = $this->objects->create($system, 'snippet', [
                'title' => $this->translator->translate('setup.footer'),
                'body' => '<p>' . self::FOOTER_TEXT . '</p>',
                'format' => 'html',
            ]);
            $add('setup.footer', 'site_footer', 'object:' . $footer->id(), 'full', ['show_title' => '0'], 'footer', 0);
        }
        $this->settings->set(['layout.enabled' => '1']);

        return $made;
    }

    /**
     * ensure(), for the end of an installation or an upgrade: a problem is written to the
     * error log, not thrown (the layouts stay off, and it is tried again next time).
     *
     * @return list<string>|null
     */
    public function ensureQuietly(): ?array
    {
        try {
            return $this->ensure();
        } catch (\Throwable $e) {
            error_log('Campanella: the header and footer components were not made: ' . $e);

            return null;
        }
    }

    /** The main menu; made with the header's built-in links if there is none. */
    private function mainMenu(Actor $system): ?CampanellaObject
    {
        if ($this->blueprints->find('menu') === null || $this->blueprints->find('menu_item') === null) {
            return null;
        }
        $menu = $this->queries->first(Query::objects()->blueprint('menu')->where('machine_name', '=', self::MAIN_MENU), $system);
        if ($menu !== null) {
            return $menu;
        }
        $menu = $this->objects->create($system, 'menu', ['title' => $this->translator->translate('setup.main_menu'), 'machine_name' => self::MAIN_MENU]);
        $weight = 0;
        foreach (self::STARTER_LINKS as $key => $link) {
            $path = str_starts_with($link, '/') ? $link : $this->paths->get($link);
            $this->objects->create($system, 'menu_item', ['title' => $this->translator->translate($key), 'url' => $path, 'weight' => $weight], false, ['menu' => [(int) $menu->id()]]);
            $weight += 10;
        }
        $this->settings->set([self::STARTER_MENU => (string) $menu->id()]);

        return $menu;
    }

    /** The core's `snippet` Blueprint (a site may have had its own of that name before 0.3.3). */
    private function isCoreSnippet(): bool
    {
        $snippet = $this->blueprints->find('snippet');

        return $snippet !== null && !in_array('snippet', $this->blueprints->yielded(), true) && isset($snippet->capabilities['textual']);
    }

    /** The machine name, or with _2, _3 … if a component has it already. */
    private function freeKey(string $key): string
    {
        $candidate = $key;
        for ($n = 2; $this->components->find($candidate, Actor::system()) !== null; $n++) {
            $candidate = $key . '_' . $n;
        }

        return $candidate;
    }
}
