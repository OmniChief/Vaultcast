<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Maintenance mode while an update swaps core files (data/runtime/maintenance.json, written by
// Updater). Checked before any other code loads; ignored after 15 minutes so it cannot lock the site.
$__maintenance = __DIR__ . '/data/runtime/maintenance.json';
if (is_file($__maintenance) && (time() - (int)(json_decode((string)@file_get_contents($__maintenance), true)['since'] ?? 0)) < 900) {
    http_response_code(503);
    header('Retry-After: 30');
    header('Cache-Control: no-store');
    $__api = strpos((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/api/') !== false;
    header('Content-Type: ' . ($__api ? 'application/json' : 'text/html; charset=utf-8'));
    exit($__api ? '{"ok":false,"error":"Vaultcast is updating. Try again in a minute.","code":"maintenance"}'
        : '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="15"><title>Updating</title><body style="font-family:system-ui;background:#0b1018;color:#edf2f7;display:grid;place-items:center;min-height:100vh;margin:0"><p>Vaultcast is updating. This page reloads by itself.</p>');
}
unset($__maintenance);
require_once __DIR__ . '/src/Services/RequestTiming.php';
RequestTiming::begin();
require_once __DIR__ . '/src/Services/AssetDelivery.php';
if (isset($_GET['asset'])) AssetDelivery::serve(__DIR__);
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Repository.php';
require_once __DIR__ . '/src/Services/StartupFailure.php';
StartupFailure::register();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$api = rtrim(\Vaultcast\Database::basePath(), '/') . '/api';
if ($path === $api || strpos((string)$path, $api . '/') === 0) {
    require_once __DIR__ . '/src/bootstrap.php';
    require __DIR__ . '/src/Http/routes.php';
    exit;
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
if (!\Vaultcast\Database::installed()) {
    require __DIR__ . '/src/Views/installer.php';
    exit;
}
if (($_GET['setup'] ?? '') === 'library') {
    require_once __DIR__ . '/src/bootstrap.php';
    Auth::requireAdmin();
    require __DIR__ . '/src/Views/installer.php';
    exit;
}
$base = \Vaultcast\Database::basePath();
$siteName = (string)(\Vaultcast\Repository::document('settings', [])['app_name'] ?? 'Vaultcast');
if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json');
    $manifest = json_decode((string)file_get_contents(__DIR__ . '/manifest.json'), true);
    $manifest['name'] = $siteName;
    $manifest['short_name'] = mb_substr($siteName, 0, 24);
    $manifest['start_url'] = $base;
    $manifest['scope'] = $base;
    echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__ . '/src/bootstrap.php';
$loginError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['first_action'] ?? '') === 'login') {
    $loginUser = Auth::findUser(trim((string)($_POST['username'] ?? '')));
    if ($loginUser && password_verify((string)($_POST['password'] ?? ''), $loginUser['password_hash'] ?? '')) {
        Auth::createSession($loginUser);
        $destination = (string)($_SERVER['REQUEST_URI'] ?? $base);
        if (strpos($destination, $base) !== 0 || strpos($destination, '//') === 0 || preg_match('/[\r\n]/', $destination)
            || in_array(trim(substr((string)$path, strlen($base)), '/'), ['login','welcome'], true)) $destination = $base;
        header('Location: ' . $destination, true, 303);
        exit;
    }
    $loginError = 'Incorrect username or password.';
}
$startup = Startup::snapshot();
$firstPage = FirstPage::render($startup, $base, $siteName, $loginError, substr((string)$path, strlen($base)));
release_request_locks();
require_once __DIR__ . '/src/Services/PlayerAssets.php';
$shell = (string)file_get_contents(__DIR__ . '/src/Views/app.html');
$config = '<script>window.__STREAM_BASE__=' . json_encode($base, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
$config .= '<script>window.VaultcastDependencies=' . json_encode(PlayerAssets::manifest(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
$publicBase = trim((string)(\Vaultcast\Repository::document('settings', [])['public_base_url'] ?? ''));
$config .= '<script>window.__PUBLIC_BASE_URL__=' . json_encode(preg_match('#^https?://#i', $publicBase) ? rtrim($publicBase, '/') . '/' : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
$config .= '<script>window.__SITE_NAME__=' . json_encode($siteName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
$config .= '<script>window.__STREAM_STARTUP__=' . json_encode($startup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) . ';</script>';
$assetVersion = substr(hash('sha256', hash_file('sha256', __DIR__ . '/assets/js/live-search.js') . hash_file('sha256', __DIR__ . '/assets/js/home-extras.js') . hash_file('sha256', __DIR__ . '/assets/js/home-hub.js') . hash_file('sha256', __DIR__ . '/assets/js/request-cache.js') . hash_file('sha256', __DIR__ . '/assets/js/app.js') . hash_file('sha256', __DIR__ . '/assets/js/routes.js') . hash_file('sha256', __DIR__ . '/assets/js/matching.js') . hash_file('sha256', __DIR__ . '/assets/js/netplay_sync.js') . hash_file('sha256', __DIR__ . '/assets/css/home-hub.css') . hash_file('sha256', __DIR__ . '/assets/css/app.css')), 0, 16);
// <base> keeps relative asset URLs working on deep links such as /tvshows/<show>/season-2.
echo AssetDelivery::text(str_replace(['{{BASE_HREF}}', '<!-- VAULTCAST_CONFIG -->', '<!-- VAULTCAST_PAGE -->', '{{VERSION}}', '{{SITE_NAME}}', '{{ASSET_VERSION}}'], [htmlspecialchars($base, ENT_QUOTES, 'UTF-8'), $config, $firstPage, trim((string)file_get_contents(__DIR__ . '/VERSION')), htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'), $assetVersion], $shell));
