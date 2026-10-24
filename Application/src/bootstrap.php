<?php
/**
 * Application bootstrap
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) { @mb_internal_encoding('UTF-8'); }

define('STREAM_ROOT', dirname(__DIR__));
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/Services/StartupFailure.php';
StartupFailure::register();
if (!\Vaultcast\Database::installed()) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        header('Content-Type: application/json');
    }
    exit('{"ok":false,"error":"Open index.php to install Vaultcast first."}');
}
define('STREAM_DATA', STREAM_ROOT . '/data');
define('STREAM_SHM', STREAM_DATA . '/cache');
define('STREAM_VERSION', trim((string)file_get_contents(STREAM_ROOT . '/VERSION')));

if (!is_dir(STREAM_SHM)) {
    @mkdir(STREAM_SHM, 0775, true);
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// Load a service only when a request uses it; no dependency manager required.
spl_autoload_register(function (string $class): void {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $class)) return;
    $file = __DIR__ . '/Services/' . $class . '.php';
    if (is_file($file)) require_once $file;
});

require_once __DIR__ . '/Http/helpers.php';

if (PHP_SAPI !== 'cli') Cors::handle();

// Apply pending storage migrations once per schema version (durable stamp check).
Migrations::runIfNeeded();
