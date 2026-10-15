<?php
declare(strict_types=1);
// Shared request policy runs before feature handlers.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = request_path();
$path = '/' . trim($path, '/');
if ($path === '/') $path = '/status';
$features = Store::settings()['features'] ?? [];
// Access gate: expired / not-yet-approved users and deleted accounts are refused on every API
// and stream request, whatever the UI shows. Admins always pass.
$__denied = Access::gate($path, Auth::currentSession(), function (string $id) { return $id === '' ? null : Auth::findById($id); },
    (Store::settings()['auth']['require_login'] ?? true) !== false,
    function () use ($path) {
        $t = '';
        if ($path === '/stream/play' || $path === '/stream/remux') $t = (string)($_GET['t'] ?? '');
        elseif (preg_match('#^/stream/(?:rom|subtitles)/([a-f0-9]{48})/#', $path, $tm)) $t = $tm[1];
        return preg_match('/^[a-f0-9]{48}$/', $t) ? \Vaultcast\Repository::playbackToken($t) : null;
    });
if ($__denied !== null) json_out($__denied[1], $__denied[0]);
// Auth/session cookie already read above. Close any native PHP session so a long
// stream/progress request cannot hold the session file lock across workers.
release_request_locks();
$downloadRoute = preg_match('#^/(downloads(?:/|$)|search/(rss|get)(?:/|$)|items/[^/]+/alternate(?:/|$)|shows/[^/]+/(alternate|fetch-missing|episodes/\d+/\d+/(fetch|cancel))(?:/|$)|admin/(acquisition|transmission|downloads)(?:/|$))#', $path);
if ($downloadRoute) {
    Auth::requireAdmin();
    if (empty($features['downloads'])) json_error('Enable download integrations in Settings first.', 404);
}
if (strpos($path, '/player-assets/') === 0 && in_array($method, ['GET','HEAD'], true)) {
    PlayerAssets::serve(substr($path, strlen('/player-assets/')));
}
if (preg_match('#^/admin/system/(kill|processes|service|reboot)(/|$)#', $path) && empty($features['server_tools'])) json_error('Server administration tools are disabled in Settings → Integrations.', 404);
if (preg_match('#^/(search/(rss|get)|admin/(acquisition|transmission|downloads))(/|$)#', $path) && empty($features['downloads'])) json_error('Download integrations are disabled in Settings → Integrations.', 404);
if (strpos($path, '/diagnostics') === 0) Auth::requireAdmin();

if (strpos($path, '/live/') === 0) {
    require __DIR__ . '/routes/live.php';
} elseif (strpos($path, '/admin/') === 0) {
    Auth::requireAdmin();
    foreach (['admin-settings','admin-updates','admin-library','admin-conversion','admin-media','admin-system','downloads','collections'] as $handler) {
        require __DIR__ . '/routes/' . $handler . '.php';
    }
} else {
    foreach (['account','library','diagnostics','downloads','collections','lobby'] as $handler) {
        require __DIR__ . '/routes/' . $handler . '.php';
    }
}
json_error('Not found: ' . $path, 404);
