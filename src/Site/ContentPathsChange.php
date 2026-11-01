<?php

declare(strict_types=1);

namespace Campanella\Site;

use Campanella\Access\Actor;
use Campanella\Capability\Link;
use Campanella\I18n\Message;
use Campanella\Lists\ListPathCheck;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Settings\Settings;

/**
 * Changing the content paths in the admin (since 0.4.1, System → Site settings): the news'
 * and the categories' (ContentPaths). A new path must be a plain path of the site that
 * nothing else has: no route (the admin, a system page, a list), no public folder, no
 * object, not the other content path. The old one leads on to the new one (a redirect, so
 * links and bookmarks keep working), and the menu items that linked the old one link the
 * new one.
 */
final class ContentPathsChange
{
    /** The content paths that can be changed (the routed ones). */
    public const array NAMES = ['news', 'categories'];

    public const int MAX_LENGTH = 100;

    public function __construct(
        private readonly ContentPaths $paths,
        private readonly Settings $settings,
        private readonly ListPathCheck $taken,
        private readonly Redirects $redirects,
        private readonly QueryEngine $queries,
        private readonly ObjectRepository $repository,
    ) {
    }

    /** @return array<string, string> name => the current path */
    public function current(): array
    {
        $current = [];
        foreach (self::NAMES as $name) {
            $current[$name] = $this->paths->get($name);
        }

        return $current;
    }

    /**
     * Saves the paths given (name => path; a missing or unchanged one stays). All or nothing:
     * a problem is at `path_<name>`.
     *
     * @param array<string, string> $input
     * @return array<string, array{from: string, to: string, links: int}> what changed
     * @throws ValidationException
     */
    public function save(array $input): array
    {
        return $this->apply($this->check($input));
    }

    /**
     * Checks the paths given, saving nothing (for a form that saves other things too).
     *
     * @param array<string, string> $input
     * @return array<string, string> name => the path to save
     * @throws ValidationException
     */
    public function check(array $input): array
    {
        $current = $this->current();
        $new = [];
        $problems = [];
        foreach (self::NAMES as $name) {
            $path = trim($input[$name] ?? $current[$name]);
            if ($path !== '' && $path !== '/' && str_ends_with($path, '/')) {
                $path = rtrim($path, '/');
            }
            $new[$name] = $path;
            if ($path === $current[$name]) {
                continue;
            }
            if (strlen($path) > self::MAX_LENGTH || preg_match('#^/[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*$#D', $path) !== 1) {
                $problems['path_' . $name] = new Message('settings.content_path_invalid');
            } elseif ($this->taken->isTaken($path, '')) {
                $problems['path_' . $name] = new Message('settings.content_path_taken');
            }
        }
        if (!isset($problems['path_categories']) && $new['news'] === $new['categories']) {
            $problems['path_categories'] = new Message('settings.content_path_same');
        }
        if ($problems !== []) {
            throw new ValidationException($problems);
        }

        return $new;
    }

    /**
     * Saves the paths check() returned.
     *
     * @param array<string, string> $new name => path
     * @return array<string, array{from: string, to: string, links: int}> what changed
     */
    public function apply(array $new): array
    {
        $current = $this->current();
        $changed = [];
        foreach (self::NAMES as $name) {
            if (isset($new[$name]) && $new[$name] !== $current[$name]) {
                $changed[$name] = ['from' => $current[$name], 'to' => $new[$name], 'links' => 0];
            }
        }
        if ($changed === []) {
            return [];
        }
        $this->settings->set(array_combine(
            array_map(static fn (string $name): string => 'content.path.' . $name, array_keys($changed)),
            array_column($changed, 'to'),
        ));
        foreach ($changed as $name => $change) {
            if (!$this->redirects->exists($change['from'])) {
                $this->redirects->add($change['from'], $change['to']);
            }
            $changed[$name]['links'] = $this->relink($change['from'], $change['to']);
        }

        return $changed;
    }

    /** The menu items that link the old path (or a page of it, `?page=2`) link the new one. */
    private function relink(string $from, string $to): int
    {
        $count = 0;
        $system = Actor::system();
        foreach ($this->queries->execute(Query::objects()->blueprint('menu_item')->limit(1000), $system) as $item) {
            if (!$item->has(Link::class)) {
                continue;
            }
            $url = (string) $item->get('url');
            if ($url === $from || str_starts_with($url, $from . '?')) {
                $item->set('url', $to . substr($url, strlen($from)));
                $this->repository->save($item);
                $count++;
            }
        }

        return $count;
    }
}
