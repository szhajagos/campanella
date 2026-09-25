<?php

declare(strict_types=1);

/*
 * Az egyetlen belépési pont. Minden kérés ide fut be (lásd .htaccess).
 */

use Campanella\Core\Kernel;
use Campanella\Http\Request;

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('A Campanella legalább PHP 8.3-at igényel. Jelenlegi verzió: ' . PHP_VERSION);
}

// Fejlesztéshez (php -S): a létező fájlokat (CSS, képek) a beépített szerver adja ki.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

(new Kernel(dirname(__DIR__)))
    ->handle(Request::fromGlobals())
    ->send();
