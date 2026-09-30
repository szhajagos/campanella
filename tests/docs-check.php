<?php

declare(strict_types=1);

/*
 * Checks that the docs/php-api chapters mention every public class and
 * method.
 *
 *   php tests/docs-check.php      (or: composer docs:check)
 *
 * A simple text search: the class's short name and the `name(` form of each
 * of its own public methods must appear in one of the chapters.
 * It does not replace proofreading, but it flags a new class or method
 * that was left undocumented.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$docs = '';
foreach (glob($root . '/docs/php-api/*.md') ?: [] as $file) {
    $docs .= file_get_contents($file) . "\n";
}

$skipMethods = ['__construct', '__get', '__isset', 'cases', 'from', 'tryFrom'];
$missing = [];
$classCount = 0;
$methodCount = 0;

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($root . '/src/'), -4);
    $class = 'Campanella\\' . str_replace('/', '\\', $relative);
    $reflection = new ReflectionClass($class);
    $short = $reflection->getShortName();
    $classCount++;

    if (preg_match('/\b' . preg_quote($short, '/') . '\b/', $docs) !== 1) {
        $missing[] = "class:  {$class}";
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || in_array($method->getName(), $skipMethods, true)) {
            continue;
        }
        $methodCount++;
        if (!str_contains($docs, $method->getName() . '(')) {
            $missing[] = "method: {$short}::{$method->getName()}()";
        }
    }
}

if ($missing !== []) {
    echo "Undocumented public elements (docs/php-api):\n\n  " . implode("\n  ", $missing) . "\n";
    exit(1);
}

echo "OK: {$classCount} classes and {$methodCount} methods are documented in the docs/php-api chapters.\n";
