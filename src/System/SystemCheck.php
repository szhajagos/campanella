<?php

declare(strict_types=1);

namespace Campanella\System;

use Campanella\Core\Config;
use Campanella\Core\Version;
use Campanella\Database\Connection;
use Campanella\Database\Installer;
use Campanella\Http\Request;
use Campanella\I18n\Message;
use Closure;

/**
 * Checks whether the server meets Campanella's requirements: versions, PHP
 * extensions, writable folders, settings, limits, caches. Shown on the admin's
 * system page and by `php bin/campanella status`.
 *
 * Never reports secrets (passwords, environment variables, session data):
 * the results are shown to administrators, but they still describe the server.
 *
 * Other parts of the system can add their own checks with add().
 */
final class SystemCheck
{
    public const string MIN_PHP = '8.3.0';

    /** The oldest supported database servers. */
    public const array MIN_DATABASE = ['MariaDB' => '10.6', 'MySQL' => '8.0'];

    /** PHP extensions Campanella cannot run without. */
    public const array REQUIRED_EXTENSIONS = ['ctype', 'dom', 'json', 'mbstring', 'pdo', 'pdo_mysql', 'session'];

    /**
     * Recommended PHP extensions: name shown => name for extension_loaded(). The
     * message key `admin.system.ext.<name>` says what each one gives.
     */
    public const array RECOMMENDED_EXTENSIONS = ['gd' => 'gd', 'fileinfo' => 'fileinfo', 'opcache' => 'Zend OPcache'];

    /** @var list<Closure(?Request): iterable<CheckResult>> */
    private array $checks = [];

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly Installer $installer,
        private readonly TemplateCache $templates,
        private readonly string $rootDir,
    ) {
    }

    /**
     * Adds a check: a function that returns CheckResults. It receives the current
     * web request, or null on the command line (where web-only values are unknown).
     *
     * @param Closure(?Request): iterable<CheckResult> $check
     */
    public function add(Closure $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * Runs every check. With a request (on the web), the web server's PHP settings are
     * checked too; on the command line (null) these are left out, because the
     * command-line PHP often has different settings (e.g. no opcache).
     *
     * @return list<CheckResult>
     */
    public function run(?Request $request = null): array
    {
        $results = [
            ...$this->versions(),
            ...$this->extensions(),
            ...$this->folders(),
            ...$this->settings($request),
        ];
        if ($request !== null) {
            array_push($results, ...$this->limits(), ...$this->opcache());
        }
        array_push($results, ...$this->templateCache());
        foreach ($this->checks as $check) {
            foreach ($check($request) as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * The worst status among the results (Error > Warning > the rest); null if empty.
     *
     * @param list<CheckResult> $results
     */
    public static function worst(array $results): ?CheckStatus
    {
        $worst = null;
        $rank = [CheckStatus::Info->value => 0, CheckStatus::Ok->value => 0, CheckStatus::Warning->value => 1, CheckStatus::Error->value => 2];
        foreach ($results as $result) {
            if ($worst === null || $rank[$result->status->value] > $rank[$worst->value]) {
                $worst = $result->status;
            }
        }

        return $worst;
    }

    /**
     * The database server's product and version from a `SELECT VERSION()` text,
     * e.g. `10.11.6-MariaDB-0+deb12u1` → `['MariaDB', '10.11.6']`; null if not recognised.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseDatabaseVersion(string $version): ?array
    {
        // Older MariaDB servers report themselves as "5.5.5-<real version>" over the protocol.
        $version = (string) preg_replace('/^5\.5\.5-(?=\d+\.\d+\.\d+.*mariadb)/i', '', $version);
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) !== 1) {
            return null;
        }

        return [stripos($version, 'mariadb') !== false ? 'MariaDB' : 'MySQL', $m[1]];
    }

    /** @return list<CheckResult> */
    private function versions(): array
    {
        $g = 'admin.system.group.versions';
        $results = [new CheckResult($g, 'admin.system.campanella', CheckStatus::Info, Version::CAMPANELLA)];

        $results[] = version_compare(PHP_VERSION, self::MIN_PHP, '>=')
            ? new CheckResult($g, 'admin.system.php', CheckStatus::Ok, PHP_VERSION)
            : new CheckResult($g, 'admin.system.php', CheckStatus::Error, PHP_VERSION, new Message('admin.system.php_too_old', ['min' => self::MIN_PHP]));

        try {
            $raw = (string) $this->db->fetchValue('SELECT VERSION()');
            $parsed = self::parseDatabaseVersion($raw);
            if ($parsed === null) {
                $results[] = new CheckResult($g, 'admin.system.database', CheckStatus::Warning, $raw, new Message('admin.system.database_unknown'));
            } else {
                [$product, $version] = $parsed;
                $min = self::MIN_DATABASE[$product];
                $results[] = version_compare($version, $min, '>=')
                    ? new CheckResult($g, 'admin.system.database', CheckStatus::Ok, "{$product} {$version}")
                    : new CheckResult($g, 'admin.system.database', CheckStatus::Error, "{$product} {$version}", new Message('admin.system.database_too_old', ['product' => $product, 'min' => $min]));
            }

            $installed = $this->installer->isInstalled() ? (string) $this->installer->systemValue('schema_version') : null;
            $results[] = match (true) {
                $installed === null => new CheckResult($g, 'admin.system.schema', CheckStatus::Error, '–', new Message('admin.system.schema_missing')),
                $installed !== Version::SCHEMA => new CheckResult($g, 'admin.system.schema', CheckStatus::Error, $installed, new Message('admin.system.schema_differs', ['code' => Version::SCHEMA])),
                default => new CheckResult($g, 'admin.system.schema', CheckStatus::Ok, $installed),
            };
        } catch (\Throwable) {
            // The details (host, user) are not shown: they are in the server's error log.
            $results[] = new CheckResult($g, 'admin.system.database', CheckStatus::Error, '–', new Message('admin.system.database_unreachable'));
        }

        return $results;
    }

    /** @return list<CheckResult> */
    private function extensions(): array
    {
        $results = [];
        foreach (self::REQUIRED_EXTENSIONS as $name) {
            $results[] = extension_loaded($name)
                ? new CheckResult('admin.system.group.required', $name, CheckStatus::Ok, (string) phpversion($name))
                : new CheckResult('admin.system.group.required', $name, CheckStatus::Error, '–', new Message('admin.system.ext_missing'));
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $name => $loadedName) {
            if ($name === 'gd' && extension_loaded('gd')) {
                $results[] = self::gd();
                continue;
            }
            $results[] = new CheckResult(
                'admin.system.group.recommended',
                $name,
                extension_loaded($loadedName) ? CheckStatus::Ok : CheckStatus::Warning,
                extension_loaded($loadedName) ? (string) phpversion($loadedName) : '–',
                new Message('admin.system.ext.' . $name),
            );
        }

        return $results;
    }

    /**
     * The gd extension with the image formats it was built with: a missing format
     * (e.g. WebP) means such images cannot be uploaded.
     */
    private static function gd(): CheckResult
    {
        $info = gd_info();
        $formats = [
            'JPEG' => (bool) ($info['JPEG Support'] ?? false),
            'PNG' => (bool) ($info['PNG Support'] ?? false),
            'WebP' => (bool) ($info['WebP Support'] ?? false),
            'GIF' => (bool) ($info['GIF Read Support'] ?? false) && (bool) ($info['GIF Create Support'] ?? false),
        ];
        $supported = array_keys(array_filter($formats));
        $missing = array_keys(array_filter($formats, static fn (bool $on): bool => !$on));
        $value = phpversion('gd') . ' · ' . implode(', ', $supported);

        return $missing === []
            ? new CheckResult('admin.system.group.recommended', 'gd', CheckStatus::Ok, $value, new Message('admin.system.ext.gd'))
            : new CheckResult('admin.system.group.recommended', 'gd', CheckStatus::Warning, $value, new Message('admin.system.gd_missing_formats', ['formats' => implode(', ', $missing)]));
    }

    /** @return list<CheckResult> */
    private function folders(): array
    {
        $dir = $this->rootDir . '/var/cache';

        return [is_dir($dir) && is_writable($dir)
            ? new CheckResult('admin.system.group.folders', 'var/cache', CheckStatus::Ok)
            : new CheckResult('admin.system.group.folders', 'var/cache', CheckStatus::Warning, '', new Message('admin.system.folder_not_writable'))];
    }

    /** @return list<CheckResult> */
    private function settings(?Request $request): array
    {
        $g = 'admin.system.group.settings';
        $theme = (string) $this->config->get('theme', '');
        $results = [
            (bool) $this->config->get('debug', false)
                ? new CheckResult($g, 'admin.system.debug', CheckStatus::Warning, 'admin.system.on', new Message('admin.system.debug_on'))
                : new CheckResult($g, 'admin.system.debug', CheckStatus::Ok, 'admin.system.off'),
            new CheckResult($g, 'admin.system.locale', CheckStatus::Info, (string) $this->config->get('locale', 'en')),
            new CheckResult($g, 'admin.system.timezone', CheckStatus::Info, (string) $this->config->get('timezone', 'UTC')),
            new CheckResult($g, 'admin.system.theme', CheckStatus::Info, $theme !== '' ? $theme : '–'),
            new CheckResult($g, 'admin.system.admin_path', CheckStatus::Info, (string) $this->config->get('admin.path', '/admin')),
        ];
        if ($request !== null) {
            $results[] = $request->secure
                ? new CheckResult($g, 'admin.system.https', CheckStatus::Ok, 'HTTPS')
                : new CheckResult($g, 'admin.system.https', CheckStatus::Warning, 'HTTP', new Message('admin.system.https_off'));
        }

        return $results;
    }

    /** @return list<CheckResult> */
    private function limits(): array
    {
        $g = 'admin.system.group.limits';
        $upload = (string) ini_get('upload_max_filesize');
        $post = (string) ini_get('post_max_size');

        return [
            new CheckResult($g, 'upload_max_filesize', CheckStatus::Info, $upload),
            self::bytes($post) > 0 && self::bytes($post) < self::bytes($upload)
                ? new CheckResult($g, 'post_max_size', CheckStatus::Warning, $post, new Message('admin.system.post_smaller'))
                : new CheckResult($g, 'post_max_size', CheckStatus::Info, $post),
            new CheckResult($g, 'memory_limit', CheckStatus::Info, (string) ini_get('memory_limit')),
            new CheckResult($g, 'max_execution_time', CheckStatus::Info, (string) ini_get('max_execution_time')),
        ];
    }

    /** @return list<CheckResult> */
    private function opcache(): array
    {
        $g = 'admin.system.group.cache';
        if (!extension_loaded('Zend OPcache') || !filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
            return [new CheckResult($g, 'admin.system.opcache', CheckStatus::Info, 'admin.system.off', new Message('admin.system.opcache_off'))];
        }
        if (!filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL)) {
            return [new CheckResult($g, 'admin.system.opcache', CheckStatus::Warning, 'admin.system.on', new Message('admin.system.opcache_no_timestamps'))];
        }

        return [new CheckResult($g, 'admin.system.opcache', CheckStatus::Ok, 'admin.system.on', new Message('admin.system.opcache_timestamps', [
            'seconds' => (string) ini_get('opcache.revalidate_freq'),
        ]))];
    }

    /** @return list<CheckResult> */
    private function templateCache(): array
    {
        $g = 'admin.system.group.cache';
        $usage = $this->templates->usage();
        $value = sprintf('%d · %s', $usage['files'], self::formatBytes($usage['bytes']));

        return [$this->templates->twigCache() === false
            ? new CheckResult($g, 'admin.system.template_cache', CheckStatus::Warning, $value, new Message('admin.system.template_cache_off'))
            : new CheckResult($g, 'admin.system.template_cache', CheckStatus::Ok, $value, new Message('admin.system.template_cache_files'))];
    }

    /** A php.ini size (`8M`, `1G`, `512K`, `-1`) in bytes; -1 or 0: no limit. */
    private static function bytes(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return -1;
        }
        $number = (int) $size;

        return match (strtoupper(substr($size, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private static function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 2 => round($bytes / 1024 ** 2, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024) . ' kB',
            default => $bytes . ' B',
        };
    }
}
