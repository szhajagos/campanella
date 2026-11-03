<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\I18n\Message;
use Campanella\Settings\Settings;

/**
 * Turning a module on and off (since 0.4.6), for the admin's Modules page and the command line.
 *
 * On: only after ModuleTrial found nothing wrong; its `public/` files are copied to
 * public/modules/<name>/ (a fresh copy, beside the old one until it is complete; never a PHP
 * file, a hidden file or a link), then it is added to the setting `modules.enabled`.
 * Off: not while another module that is on needs it; it leaves the setting, its copied files
 * are removed. Its data stays (removing a module's data comes with 0.4.7).
 */
final class ModuleManager
{
    /** The files copied of a module's public/: not these. */
    private const string NOT_COPIED = '/(^\.|\.(php\d?|phtml|phar|pht|phps|cgi|pl|py|sh|htaccess)$)/i';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleTrial $trial,
        private readonly Settings $settings,
        private readonly string $publicDir,
    ) {
    }

    /**
     * Turns the module on: the trial's problems if it is not turned on ([] if it is).
     *
     * @return list<Message>
     */
    public function enable(string $name): array
    {
        $state = $this->registry->get($name);
        if ($state !== null && $state->enabled && $state->status !== ModuleState::BROKEN) {
            return [new Message('modules.already_on')];
        }
        $problems = $this->trial->run($name)['problems'];
        if ($problems !== []) {
            return $problems;
        }
        \assert($state !== null && $state->manifest !== null);
        $published = $this->publish($state->manifest);
        if ($published !== null) {
            return [$published];
        }
        $this->save([...$this->enabledNames(), $name]);

        return [];
    }

    /** Turns the module off; why not, or null. */
    public function disable(string $name): ?Message
    {
        $state = $this->registry->get($name);
        if ($state === null || !$state->enabled) {
            return new Message('modules.not_on');
        }
        $needing = [];
        foreach ($this->registry->all() as $other) {
            if ($other->enabled && $other->name !== $name && isset($other->manifest?->requires[$name])) {
                $needing[] = $other->name;
            }
        }
        if ($needing !== []) {
            return new Message('modules.needed_by', ['modules' => implode(', ', $needing)]);
        }
        $this->save(array_values(array_diff($this->enabledNames(), [$name])));
        $this->remove($this->publicDir . '/' . $name);

        return null;
    }

    /**
     * The files of the module's public/ that are copied, relative to it.
     *
     * @return list<string>
     */
    public static function publicFiles(ModuleManifest $manifest): array
    {
        $dir = $manifest->path . '/public';
        if (!is_dir($dir) || is_link($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            $parts = explode('/', $relative);
            if (preg_grep(self::NOT_COPIED, $parts) !== []) {
                continue;
            }
            $files[] = $relative;
        }
        sort($files);

        return $files;
    }

    /** @return list<string> The setting's, as saved now */
    private function enabledNames(): array
    {
        return ModuleRegistry::namesOf($this->settings->get('modules.enabled'));
    }

    /** @param list<string> $names */
    private function save(array $names): void
    {
        $names = array_values(array_unique($names));
        sort($names);
        $this->settings->set(['modules.enabled' => $names === [] ? null : implode(',', $names)]);
    }

    /** The module's public/ copied to public/modules/<name>/; why not, or null. */
    private function publish(ModuleManifest $manifest): ?Message
    {
        $target = $this->publicDir . '/' . $manifest->name;
        $files = self::publicFiles($manifest);
        if ($files === []) {
            $this->remove($target);

            return null;
        }
        if (!is_dir($this->publicDir) && !@mkdir($this->publicDir, 0755, true)) {
            return new Message('modules.publish_failed', ['folder' => 'public/modules']);
        }
        $fresh = $target . '.new-' . bin2hex(random_bytes(4));
        foreach ($files as $relative) {
            $to = $fresh . '/' . $relative;
            if ((!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) || !@copy($manifest->path . '/public/' . $relative, $to)) {
                $this->remove($fresh);

                return new Message('modules.publish_failed', ['folder' => 'public/modules']);
            }
        }
        $this->remove($target);
        if (!@rename($fresh, $target)) {
            $this->remove($fresh);

            return new Message('modules.publish_failed', ['folder' => 'public/modules']);
        }

        return null;
    }

    /** A folder under public/modules/ removed, with what is in it. */
    private function remove(string $dir): void
    {
        $base = realpath($this->publicDir);
        $real = realpath($dir);
        if ($base === false || $real === false || !str_starts_with($real, $base . '/') || !is_dir($real) || is_link($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
        }
        @rmdir($real);
    }
}
