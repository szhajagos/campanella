<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\Database\Connection;
use Campanella\Database\DatabaseBackup;
use Campanella\Database\Migration\MigrationContext;
use Campanella\Database\Migration\MigrationRegistry;
use Campanella\Database\Migration\Migrator;
use Campanella\Database\Schema\CoreSchema;
use Campanella\I18n\Message;
use Campanella\Settings\Settings;

/**
 * Turning a module on and off (since 0.4.6), for the admin's Modules page and the command line.
 *
 * On: only after ModuleTrial found nothing wrong; its `public/` files are copied to
 * public/modules/<name>/ (a fresh copy, beside the old one until it is complete; never a PHP
 * file, a hidden file or a link), then it is added to the setting `modules.enabled`.
 * Its tables (since 0.4.7): when it is turned on, a backup is made and its migrations run, before
 * the trial tries the site's pages with it.
 * Off: not while another module that is on needs it, nor while anything of the site uses it
 * (ModuleUsage); it leaves the setting, its copied files are removed. Its data stays.
 * Removing its data (since 0.4.7): only while it is off, after a backup, by its own uninstall()
 * (Uninstallable); its migrations count as not run then.
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
        private readonly ?Connection $db = null,
        private readonly ?DatabaseBackup $backup = null,
        private readonly ?ModuleUsage $usage = null,
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
        $problems = $this->trial->run($name, $this->migrate(...))['problems'];
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

    /**
     * Turns the module off: why not ([] if it is off now).
     *
     * @return list<Message>
     */
    public function disable(string $name): array
    {
        $state = $this->registry->get($name);
        if ($state === null || !$state->enabled) {
            return [new Message('modules.not_on')];
        }
        $needing = [];
        foreach ($this->registry->all() as $other) {
            if ($other->enabled && $other->name !== $name && isset($other->manifest?->requires[$name])) {
                $needing[] = $other->name;
            }
        }
        if ($needing !== []) {
            return [new Message('modules.needed_by', ['modules' => implode(', ', $needing)])];
        }
        $uses = $this->usage?->of($name) ?? [];
        if ($uses !== []) {
            return [new Message('modules.in_use'), ...$uses];
        }
        $this->save(array_values(array_diff($this->enabledNames(), [$name])));
        $this->remove($this->publicDir . '/' . $name);

        return [];
    }

    /**
     * Removes the module's data for good (since 0.4.7): only while it is off, if it can
     * (Uninstallable), after a backup; why not, or null. Its files stay: deleting its folder
     * is the site's own.
     */
    public function uninstall(string $name): ?Message
    {
        $state = $this->registry->get($name);
        $manifest = $state?->manifest;
        if ($state === null || $manifest === null) {
            return new Message('modules.trial.missing');
        }
        if ($state->enabled) {
            return new Message('modules.uninstall_on');
        }
        if (!$this->canUninstall($manifest)) {
            return new Message('modules.uninstall_none');
        }
        if ($this->db === null || $this->backup === null) {
            return new Message('modules.uninstall_none');
        }
        try {
            $this->backup->create();
            $class = $manifest->entryClass();
            $module = new $class();
            \assert($module instanceof Uninstallable);
            $module->uninstall(new MigrationContext($this->db));
            if ($this->db->tableExists(CoreSchema::MIGRATIONS)) {
                $this->db->execute('DELETE FROM {migrations} WHERE id LIKE :prefix', ['prefix' => addcslashes($name, '_%') . ':%']);
            }
        } catch (\Throwable $e) {
            error_log("Campanella: the module {$name}'s data could not be removed: " . $e);

            return new Message('modules.uninstall_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return null;
    }

    /** Whether the module's data can be removed: its entry point is Uninstallable (its code is read for it). */
    public function canUninstall(ModuleManifest $manifest): bool
    {
        try {
            ModuleLoader::autoload($manifest);

            return class_exists($manifest->entryClass()) && is_a($manifest->entryClass(), Uninstallable::class, true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The module's migrations run, after a backup (for the trial, when it is turned on).
     *
     * @param list<\Campanella\Database\Migration\Migration> $pending
     */
    private function migrate(array $pending): ?Message
    {
        if ($this->db === null || $this->backup === null) {
            return new Message('modules.migration_failed', ['error' => 'no database']);
        }
        try {
            $this->backup->create();
            (new Migrator($this->db, new MigrationRegistry($pending)))->run();
        } catch (\Throwable $e) {
            error_log('Campanella: a module\'s migration failed: ' . $e . ($e->getPrevious() !== null ? ' — ' . $e->getPrevious() : ''));

            return new Message('modules.migration_failed', ['error' => mb_substr(($e->getPrevious() ?? $e)->getMessage(), 0, 300)]);
        }

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
