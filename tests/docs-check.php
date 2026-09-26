<?php

declare(strict_types=1);

/*
 * Ellenőrzi, hogy a docs/php-api fejezetei minden nyilvános osztályt és
 * metódust említenek-e.
 *
 *   php tests/docs-check.php      (vagy: composer docs:check)
 *
 * Egyszerű szöveges keresés: az osztály rövid nevének és minden saját
 * nyilvános metódus `név(` alakjának szerepelnie kell valamelyik fejezetben.
 * Nem helyettesíti az átolvasást, de jelzi, ha egy új osztály vagy metódus
 * dokumentálatlanul maradt.
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
        $missing[] = "osztály:  {$class}";
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || in_array($method->getName(), $skipMethods, true)) {
            continue;
        }
        $methodCount++;
        if (!str_contains($docs, $method->getName() . '(')) {
            $missing[] = "metódus: {$short}::{$method->getName()}()";
        }
    }
}

if ($missing !== []) {
    echo "Dokumentálatlan nyilvános elemek (docs/php-api):\n\n  " . implode("\n  ", $missing) . "\n";
    exit(1);
}

echo "Rendben: {$classCount} osztály és {$methodCount} metódus szerepel a docs/php-api fejezeteiben.\n";
