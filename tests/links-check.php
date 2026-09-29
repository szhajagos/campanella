<?php

declare(strict_types=1);

/*
 * Ellenőrzi, hogy a markdown-fájlok relatív linkjei létező fájlra mutatnak-e.
 *
 *   php tests/links-check.php      (vagy: composer docs:links)
 *
 * A külső (http, https, mailto) linkeket és a fájlon belüli horgonyokat
 * (#szakasz) nem vizsgálja, csak a hivatkozott fájl vagy mappa létezését.
 */

$root = dirname(__DIR__);
$skipDirs = ['vendor', '.git', 'var', 'build'];
$broken = [];
$count = 0;

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
    // A kódblokkokban lévő „linkek” nem valódi linkek.
    $text = (string) preg_replace('/^```.*?^```/ms', '', $text);
    preg_match_all('/\]\(([^)\s]+)\)/', $text, $matches);

    foreach ($matches[1] as $target) {
        if (preg_match('/^(https?:|mailto:|#)/', $target) === 1) {
            continue;
        }
        $count++;
        $path = explode('#', $target, 2)[0];
        if (!file_exists($file->getPath() . '/' . rawurldecode($path))) {
            $broken[] = substr($file->getPathname(), strlen($root) + 1) . ' → ' . $target;
        }
    }
}

if ($broken !== []) {
    fwrite(STDERR, "Hibás linkek:\n  " . implode("\n  ", $broken) . "\n");
    exit(1);
}

echo "Rendben: {$count} relatív link, mind létező fájlra mutat.\n";
