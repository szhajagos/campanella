<?php

declare(strict_types=1);

/*
 * The release package's contents (since 0.2.0): what must be in it, what must not
 * (development files, local settings, uploads, secrets), the dependencies' licences,
 * line endings, and the PHP files' syntax. Run by tests/package/check.sh on the
 * unpacked package.
 *
 *   php tests/package/contents.php <unpacked package folder>
 *
 * Exits with 1 if anything is wrong, listing every problem.
 */

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php contents.php <unpacked package folder>\n");
    exit(2);
}
$problems = [];
$checks = 0;
$check = static function (bool $ok, string $what) use (&$problems, &$checks): void {
    $checks++;
    if (!$ok) {
        $problems[] = $what;
    }
};

/** @return list<string> Every path under the folder, relative, with `/` */
$walk = static function (string $root): array {
    $paths = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $paths[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
    }

    return $paths;
};
$paths = $walk($dir);

// 1. What a site needs.
foreach ([
    'vendor/autoload.php', 'public/index.php', 'bin/campanella', 'config/app.php', 'config/local.php.dist',
    'LICENSE', 'README.md', 'CHANGELOG.md', 'docs/upgrading.md', 'docs/security.md', 'docs/deployment.md',
    '.htaccess', 'public/.htaccess', 'var/.htaccess', 'public/media/.htaccess',
    'public/assets/vendor/bootstrap/css/bootstrap.min.css', 'public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    'public/assets/vendor/jodit/jodit.min.js', 'vendor/twig/twig', 'vendor/symfony/mailer', 'vendor/symfony/html-sanitizer',
] as $needed) {
    $check(file_exists($dir . '/' . $needed), "missing: {$needed}");
}
$check(is_executable($dir . '/bin/campanella'), 'bin/campanella is not executable');

// 2. What must not be in it: development files, local settings, uploads, backups, caches,
// version control, secrets.
$forbidden = [
    '#^tests(/|$)#' => 'the tests',
    '#^\.github(/|$)#' => 'the CI settings',
    '#^phpstan\.neon$#' => "PHPStan's settings",
    '#^\.gitattributes$#' => '.gitattributes',
    '#^config/local\.php$#' => 'a local configuration (passwords!)',
    '#(^|/)\.git(/|$)#' => 'a version-control folder',
    '#(^|/)\.env(\..*)?$#' => 'an .env file',
    '#(^|/)node_modules(/|$)#' => 'node_modules',
    '#^vendor/phpstan(/|$)#' => 'a development package (phpstan)',
    '#^vendor/bin/phpstan#' => 'a development tool',
    '#^public/media/(?!\.htaccess$|\.gitkeep$).#' => 'an uploaded file',
    '#^var/backups/(?!\.htaccess$|\.gitkeep$).#' => 'a backup',
    '#^var/cache/(?!\.gitkeep$).#' => 'a cache file',
    '#\.(sql|sql\.gz|log|sqlite)$#' => 'a database dump or log',
];
foreach ($paths as $path) {
    foreach ($forbidden as $pattern => $what) {
        if (preg_match($pattern, $path) === 1) {
            $check(false, "{$what}: {$path}");
        }
    }
}

// 3. The dependencies: no development package, and only MIT-compatible licences.
$installed = json_decode((string) @file_get_contents($dir . '/vendor/composer/installed.json'), true);
$check(is_array($installed), 'vendor/composer/installed.json is missing');
$allowed = ['MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC'];
foreach (is_array($installed) ? ($installed['packages'] ?? $installed) : [] as $package) {
    $name = (string) ($package['name'] ?? '?');
    $licences = (array) ($package['license'] ?? []);
    $check(array_intersect($licences, $allowed) !== [], "{$name}: licence " . (implode(', ', $licences) ?: 'none') . ' is not one of ' . implode(', ', $allowed));
}
$check(is_array($installed) && ($installed['dev'] ?? true) === false, 'the development packages were installed (composer install --no-dev)');
$lock = json_decode((string) @file_get_contents($dir . '/composer.lock'), true);
foreach ((array) ($lock['packages-dev'] ?? []) as $package) {
    $check(!is_dir($dir . '/vendor/' . $package['name']), 'a development package is in vendor/: ' . $package['name']);
}

// 4. Line endings (a CRLF breaks bin/campanella and the shell scripts) and PHP syntax.
$php = 0;
foreach ($paths as $path) {
    if (str_starts_with($path, 'vendor/') || !is_file($dir . '/' . $path)) {
        continue;
    }
    if (preg_match('#\.(php|twig|md|json|yaml|yml|sh|css|js|dist|neon)$|^bin/|\.htaccess$#', $path) === 1) {
        $check(!str_contains((string) file_get_contents($dir . '/' . $path), "\r\n"), "CRLF line endings: {$path}");
    }
    if (str_ends_with($path, '.php') || $path === 'bin/campanella') {
        $php++;
        exec('php -l ' . escapeshellarg($dir . '/' . $path) . ' 2>&1', $output, $status);
        $check($status === 0, "PHP syntax: {$path}: " . implode(' ', $output));
        $output = [];
    }
}
$check($php > 100, "only {$php} PHP files");

echo "{$checks} checks of the package's contents ({$php} PHP files linted).\n";
if ($problems !== []) {
    echo count($problems) . " problems:\n";
    foreach ($problems as $problem) {
        echo "  ✘ {$problem}\n";
    }
    exit(1);
}
echo "✔ The package holds what it should, and nothing else.\n";
