<?php

declare(strict_types=1);

/*
 * The single entry point. Every request ends up here (see .htaccess).
 *
 * By default the project root is the parent of this folder (public/).
 * If the contents of public/ were moved elsewhere (e.g. into a web host's
 * web root), the root location can be set with the CAMPANELLA_ROOT
 * environment variable.
 */

use Campanella\Core\Kernel;
use Campanella\Http\Request;

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('A Campanella legalább PHP 8.3-at igényel. Jelenlegi verzió: ' . PHP_VERSION);
}

// For development (php -S): existing files (CSS, images) are served by the built-in server.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

$root = getenv('CAMPANELLA_ROOT') ?: dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $where = htmlspecialchars($root, ENT_QUOTES, 'UTF-8');
    exit(<<<HTML
        <!doctype html><meta charset="utf-8"><title>Campanella: hiányzó vendor mappa</title>
        <h1>Hiányzik a vendor mappa</h1>
        <p>A Campanella ezen a helyen keresi: <code>{$where}/vendor/autoload.php</code></p>
        <p>Lehetséges okok:</p>
        <ul>
          <li>A külső csomagok nincsenek telepítve: futtasd a projekt gyökerében a
              <code>composer install</code> parancsot, vagy a <code>vendor</code> mappát is tartalmazó csomagot töltsd fel.</li>
          <li>Csak a <code>public</code> mappa tartalma került a webgyökérbe. A webgyökér a projekt
              <code>public</code> mappája legyen, a többi mappa (<code>src</code>, <code>vendor</code>, <code>config</code>…)
              pedig mellette, egy szinttel feljebb.</li>
        </ul>
        HTML);
}

require $autoload;

(new Kernel($root))
    ->handle(Request::fromGlobals())
    ->send();
