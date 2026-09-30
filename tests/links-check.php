<?php

declare(strict_types=1);

/*
 * Checks that the relative links in the markdown files point to existing
 * files, and that their #anchors point to existing headings.
 *
 *   php tests/links-check.php      (or: composer docs:links)
 *
 * External links (http, https, mailto) are not checked. Anchors are resolved
 * the way GitHub generates them from headings.
 */

$root = dirname(__DIR__);
$skipDirs = ['vendor', '.git', 'var', 'build'];
$broken = [];
$count = 0;

/** GitHub-style anchor of a heading: lowercase, punctuation removed, spaces to hyphens. */
$slug = static function (string $heading): string {
    $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);   // [text](url) -> text
    $text = mb_strtolower(trim($text));
    $text = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $text);

    return str_replace(' ', '-', $text);
};

/** @var array<string, list<string>> $anchorCache */
$anchorCache = [];
$anchors = static function (string $path) use (&$anchorCache, $slug): array {
    if (!isset($anchorCache[$path])) {
        $text = (string) preg_replace('/^```.*?^```/ms', '', (string) file_get_contents($path));
        preg_match_all('/^#{1,6}\s+(.+?)\s*#*\s*$/m', $text, $m);
        $seen = [];
        $list = [];
        foreach ($m[1] as $heading) {
            $base = $slug($heading);
            $n = $seen[$base] ?? 0;
            $list[] = $n === 0 ? $base : $base . '-' . $n;
            $seen[$base] = $n + 1;
        }
        $anchorCache[$path] = $list;
    }

    return $anchorCache[$path];
};

$files = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), $skipDirs, true)),
    ),
);

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'md') {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    // "Links" inside code blocks are not real links.
    $text = (string) preg_replace('/^```.*?^```/ms', '', $text);
    preg_match_all('/\]\(([^)\s]+)\)/', $text, $matches);

    foreach ($matches[1] as $target) {
        if (preg_match('/^(https?:|mailto:)/', $target) === 1) {
            continue;
        }
        $count++;
        [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
        $resolved = $path === '' ? $file->getPathname() : $file->getPath() . '/' . rawurldecode($path);
        $where = substr($file->getPathname(), strlen($root) + 1) . ' → ' . $target;

        if (!file_exists($resolved)) {
            $broken[] = $where . '  (file not found)';
        } elseif ($anchor !== null && $anchor !== '' && is_file($resolved) && str_ends_with($resolved, '.md')
            && !in_array(rawurldecode($anchor), $anchors($resolved), true)) {
            $broken[] = $where . '  (no such heading)';
        }
    }
}

if ($broken !== []) {
    fwrite(STDERR, "Broken links:\n  " . implode("\n  ", $broken) . "\n");
    exit(1);
}

echo "OK: {$count} relative links, all pointing to existing files and headings.\n";
