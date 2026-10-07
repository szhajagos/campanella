<?php

declare(strict_types=1);

namespace Campanella\Menu;

use Campanella\Access\Actor;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\Link;
use Campanella\Capability\Titled;
use Campanella\Http\Request;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\RelationLoader;
use Campanella\Tree\TreeBuilder;
use Campanella\Tree\TreeNode;

/**
 * Builds a menu for a visitor: the menu found by its machine name, its items
 * as a tree (in their hand-set order), each with its final address. Since 0.0.7.
 *
 * What the visitor may not see is left out: an item whose target object is
 * hidden from them (e.g. a draft), and with it the items below it. Everything
 * is read with the visitor's own access rules (QueryEngine), in three queries.
 *
 *     $entries = $menus->build('main', $actor, $request->path, levels: 2, basePath: $request->basePath);
 */
final class MenuBuilder
{
    /** The Blueprint of menus. */
    public const string MENU_BLUEPRINT = 'menu';

    /** The relation from an item to its menu. */
    public const string MENU_RELATION = 'menu';

    /** The most items read for one menu. */
    public const int MAX_ITEMS = 500;

    public function __construct(
        private readonly QueryEngine $queries,
        private readonly RelationLoader $relations,
    ) {
    }

    /**
     * @param string $key The menu's machine name
     * @param string $currentPath The path of the page being shown (to mark the current item)
     * @param int $levels How many levels (1: only the top items)
     * @param string $basePath The installation's URL prefix, put before site paths
     * @return list<MenuEntry>|null Null if there is no such menu (or the visitor may not see it)
     */
    public function build(string $key, Actor $actor, string $currentPath = '/', int $levels = 2, string $basePath = ''): ?array
    {
        $menu = $this->queries->first(
            Query::objects()->blueprint(self::MENU_BLUEPRINT)->where('machine_name', '=', $key),
            $actor,
        );
        if ($menu === null) {
            return null;
        }

        $items = $this->queries->execute(
            Query::objects()
                ->having(Link::class, Hierarchical::class)
                ->whereRelated(self::MENU_RELATION, $menu)
                ->where('depth', '<', max(1, $levels))
                ->scope('by_weight')
                ->limit(self::MAX_ITEMS),
            $actor,
        );
        $this->relations->resolve($items, $actor, ['target']);

        $hrefs = [];
        $visible = [];
        foreach ($items as $item) {
            $href = $item->as(Link::class)->href($item->relatedObjects('target')[0] ?? null);
            if ($href !== null) {
                $hrefs[(int) $item->id()] = $href;
                $visible[] = $item;
            }
        }

        // Without its parent (hidden), an item is left out with its subtree.
        $current = mb_strtolower(Request::normalizePath($currentPath), 'UTF-8');

        return array_map(
            fn (TreeNode $node): MenuEntry => $this->entry($node, $hrefs, $current, $basePath),
            TreeBuilder::build($visible, keepOrphans: false),
        );
    }

    /** @param array<int, string> $hrefs */
    private function entry(TreeNode $node, array $hrefs, string $current, string $basePath): MenuEntry
    {
        $children = array_map(
            fn (TreeNode $child): MenuEntry => $this->entry($child, $hrefs, $current, $basePath),
            $node->children,
        );
        $href = $hrefs[(int) $node->object->id()];
        $local = Link::isLocal($href);
        $isCurrent = $local && $href[0] === '/' && self::pathOf($href) === $current;
        $active = $isCurrent;
        foreach ($children as $child) {
            $active = $active || $child->active;
        }

        return new MenuEntry(
            id: (int) $node->object->id(),
            title: self::titleOf($node->object),
            href: $local && $href[0] === '/' ? $basePath . $href : $href,
            current: $isCurrent,
            active: $active,
            external: !$local,
            children: $children,
        );
    }

    /** The path of a site address, without its query string and fragment, normalized. */
    private static function pathOf(string $href): string
    {
        $path = (string) preg_replace('/[?#].*$/s', '', $href);

        return mb_strtolower(Request::normalizePath(rawurldecode($path)), 'UTF-8');
    }

    private static function titleOf(CampanellaObject $object): string
    {
        $title = $object->has(Titled::class) ? $object->as(Titled::class)->title() : '';

        return $title !== '' ? $title : '#' . $object->id();
    }
}
