<?php
/**
 * Router for PHP's built-in development server (not for production):
 *   php -S 127.0.0.1:8080 router.php
 * Serves real static files, keeps private folders private, and sends every other path
 * (clean URLs such as /movies/<slug>, and /api/...) to index.php, like the nginx try_files fallback.
 */
declare(strict_types=1);
$uri = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (preg_match('#^/(src|data|deploy|docs|netplay|tests)(/|$)#i', $uri) || preg_match('#/\.#', $uri)
    || preg_match('#\.(sql|md|log|sh|ps1|cmd|ya?ml|lock|bak)$#i', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $uri);
if ($uri !== '/' && is_file($file) && !preg_match('#\.php$#i', $uri)) return false; // static asset
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
