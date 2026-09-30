<?php

declare(strict_types=1);

/*
 * Checks that every language file in lang/ has exactly the keys of the
 * English base file (lang/en.php).
 *
 *   php tests/lang-check.php      (or: composer lang:check)
 */

use Campanella\I18n\Translator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$catalogs = Translator::loadCatalogs($root . '/lang');
if (!isset($catalogs[Translator::BASE_LOCALE])) {
    fwrite(STDERR, "lang/en.php is missing.\n");
    exit(1);
}

$problems = [];
foreach (Translator::compare($catalogs) as $locale => $diff) {
    foreach ($diff['missing'] as $key) {
        $problems[] = "lang/{$locale}.php: missing key {$key}";
    }
    foreach ($diff['extra'] as $key) {
        $problems[] = "lang/{$locale}.php: key not in en.php: {$key}";
    }
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}

printf("OK: %d keys in %d languages (%s).\n", count($catalogs[Translator::BASE_LOCALE]), count($catalogs), implode(', ', array_keys($catalogs)));
