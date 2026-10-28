<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Closure;

/**
 * The admin's menu (since 0.3.3): groups of links, each for those who may use them.
 * The core's groups, in their order: content, the media library, the audience
 * (messages, users), and for the system roles the appearance, the structure and the
 * system. A group with no link for the actor is left out.
 *
 * The links come from providers, called for every page with the actor, so a link can
 * depend on who looks (and on the Blueprints). A module (0.5.0) adds its own:
 *
 *     $menu->add(AdminMenu::SYSTEM, static fn (Actor $actor): array => [new AdminMenuItem('backup', 'backup.title', 'system/backup')]);
 */
final class AdminMenu
{
    public const string CONTENT = 'content';
    public const string MEDIA = 'media';
    public const string AUDIENCE = 'audience';
    public const string APPEARANCE = 'appearance';
    public const string STRUCTURE = 'structure';
    public const string SYSTEM = 'system';

    /** @var array<string, array{label: string, providers: list<Closure(Actor): list<AdminMenuItem>>}> */
    private array $groups = [];

    public function __construct()
    {
        foreach ([self::CONTENT, self::MEDIA, self::AUDIENCE, self::APPEARANCE, self::STRUCTURE, self::SYSTEM] as $group) {
            $this->group($group, 'admin.menu.' . $group);
        }
    }

    /** A group, after the others (an existing one keeps its place, and gets this label). */
    public function group(string $name, string $label): void
    {
        $this->groups[$name] = ['label' => $label, 'providers' => $this->groups[$name]['providers'] ?? []];
    }

    /**
     * Links to a group, from a provider called with the actor of every admin page.
     *
     * @param Closure(Actor): list<AdminMenuItem> $items
     */
    public function add(string $group, Closure $items): void
    {
        if (!isset($this->groups[$group])) {
            throw new \InvalidArgumentException("Unknown admin menu group: {$group}");
        }
        $this->groups[$group]['providers'][] = $items;
    }

    /** @return list<string> The groups' names, in their order. */
    public function groups(): array
    {
        return array_keys($this->groups);
    }

    /**
     * The menu for an actor: the groups that have links for them.
     *
     * @return list<array{name: string, label: string, items: list<AdminMenuItem>}>
     */
    public function build(Actor $actor): array
    {
        $menu = [];
        foreach ($this->groups as $name => $group) {
            $items = [];
            foreach ($group['providers'] as $provider) {
                foreach ($provider($actor) as $item) {
                    $items[] = $item;
                }
            }
            if ($items !== []) {
                $menu[] = ['name' => $name, 'label' => $group['label'], 'items' => $items];
            }
        }

        return $menu;
    }
}
