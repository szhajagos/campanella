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

// These two messages appear before the system (and its translations) is loaded,
// so they are in the base language, English.
if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('Campanella requires PHP 8.3 or newer. Current version: ' . PHP_VERSION);
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
        <!doctype html><meta charset="utf-8"><title>Campanella: the vendor folder is missing</title>
        <h1>The vendor folder is missing</h1>
        <p>Campanella looks for it here: <code>{$where}/vendor/autoload.php</code></p>
        <p>Possible causes:</p>
        <ul>
          <li>The external packages are not installed: run <code>composer install</code> in the project root,
              or upload the release package that includes the <code>vendor</code> folder.</li>
          <li>Only the contents of the <code>public</code> folder were put into the web root. The web root should be
              the project's <code>public</code> folder, with the other folders (<code>src</code>, <code>vendor</code>,
              <code>config</code>…) next to it, one level up.</li>
        </ul>
        HTML);
}

require $autoload;

$kernel = new Kernel($root);
$kernel->handle(Request::fromGlobals())->send();
// Work left for after the response, e.g. an e-mail (since 0.1.4).
$kernel->terminate();
