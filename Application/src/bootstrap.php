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

// Browsers send Origin for state-changing fetch requests. Reject cross-site writes
// before any route handler can process them.
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD','OPTIONS'], true)) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $originHost = $origin !== '' ? parse_url($origin, PHP_URL_HOST) : null;
    $originPort = $origin !== '' ? parse_url($origin, PHP_URL_PORT) : null;
    $authority = $originHost ? strtolower($originHost . ($originPort ? ':' . $originPort : '')) : '';
    if (($origin !== '' && $authority !== strtolower($_SERVER['HTTP_HOST'] ?? '')) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        http_response_code(403);
        header('Content-Type: application/json');
        exit('{"ok":false,"error":"Cross-site write rejected."}');
    }
}

// Load a service only when a request uses it; no dependency manager required.
spl_autoload_register(function (string $class): void {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $class)) return;
    $file = __DIR__ . '/Services/' . $class . '.php';
    if (is_file($file)) require_once $file;
});

require_once __DIR__ . '/Http/helpers.php';

// Apply pending storage migrations once per schema version (durable stamp check).
Migrations::runIfNeeded();
