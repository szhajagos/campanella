<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Controller\AdminController;
use Campanella\Model\BlueprintRegistry;
use Campanella\Service\UserService;
use Closure;

/**
 * The core's links of the admin's menu (since 0.3.3): what each group holds, for whom.
 * The addresses are those of before 0.3.3; only their place in the menu changed.
 */
final class CoreAdminMenu
{
    /**
     * @param (Closure(): ?SubmissionPages)|null $submissions The forms' messages (lazy), if any
     * @param (Closure(): bool)|null $media Whether the media library is there (files can be uploaded)
     * @param (Closure(): bool)|null $components Whether there are components (ComponentService::isAvailable())
     */
    public static function register(
        AdminMenu $menu,
        BlueprintRegistry $blueprints,
        AdminAccess $access,
        UserService $users,
        ?Closure $submissions = null,
        ?Closure $media = null,
        ?Closure $components = null,
    ): void {
        $hasMedia = static fn (): bool => $media === null || $media();

        // Content: the Blueprints' lists, but those with pages of their own and the media.
        $menu->add(AdminMenu::CONTENT, static function () use ($blueprints, $hasMedia): array {
            $items = [];
            $library = $hasMedia();
            foreach ($blueprints->all() as $name => $blueprint) {
                if (!AdminController::ownPages($blueprint) && !($library && AdminController::isMedia($blueprint))) {
                    $items[] = new AdminMenuItem($name, $blueprint->label, $name);
                }
            }

            return $items;
        });

        // The media library: all, the images, the files.
        $menu->add(AdminMenu::MEDIA, static function () use ($blueprints, $hasMedia): array {
            if (!$hasMedia()) {
                return [];
            }
            $items = [];
            foreach ($blueprints->all() as $name => $blueprint) {
                if (!AdminController::ownPages($blueprint) && AdminController::isMedia($blueprint)) {
                    $items[] = new AdminMenuItem($name, $blueprint->label, $name, plural: true);
                }
            }

            return $items === [] ? [] : [new AdminMenuItem('media', 'admin.menu.library_all', 'media'), ...$items];
        });

        // The audience: the forms' messages and the users.
        $menu->add(AdminMenu::AUDIENCE, static function (Actor $actor) use ($submissions, $users): array {
            $items = [];
            $pages = $submissions === null ? null : $submissions();
            if ($pages !== null && $pages->canView($actor)) {
                $items[] = new AdminMenuItem('submission', 'submissions.title', 'submission', $pages->unreadCount($actor), 'submissions.unread');
            }
            if ($users->canManage($actor)) {
                $items[] = new AdminMenuItem('user', 'users.title', 'user');
            }

            return $items;
        });

        // For the system roles: the appearance (the components, if any; the layouts, since
        // 0.3.4), the structure, the system.
        $menu->add(AdminMenu::APPEARANCE, static function (Actor $actor) use ($access, $components): array {
            if (!$access->allowsSystem($actor)) {
                return [];
            }
            $items = $components === null || $components() ? [new AdminMenuItem('component', 'components.title', 'component')] : [];
            $items[] = new AdminMenuItem('layouts', 'layouts.title', 'system/layouts');

            return $items;
        });
        $menu->add(AdminMenu::STRUCTURE, self::forSystem($access, [
            new AdminMenuItem('blueprints', 'structure.blueprints', 'system/blueprints'),
            new AdminMenuItem('capabilities', 'structure.capabilities', 'system/capabilities'),
            new AdminMenuItem('lists', 'lists.title', 'system/lists'),
            new AdminMenuItem('model', 'model.title', 'system/model'),
        ]));
        $menu->add(AdminMenu::SYSTEM, self::forSystem($access, [
            new AdminMenuItem('system', 'admin.menu.status', 'system'),
            new AdminMenuItem('settings', 'settings.title', 'system/settings'),
            new AdminMenuItem('mail', 'mail.log.menu', 'system/mail'),
            new AdminMenuItem('importers', 'importers.title', 'system/importers'),
            new AdminMenuItem('redirects', 'redirects.title', 'system/redirects'),
        ]));
    }

    /**
     * @param list<AdminMenuItem> $items
     * @return Closure(Actor): list<AdminMenuItem>
     */
    private static function forSystem(AdminAccess $access, array $items): Closure
    {
        return static fn (Actor $actor): array => $access->allowsSystem($actor) ? $items : [];
    }
}
