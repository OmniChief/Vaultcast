<?php
declare(strict_types=1);
// Shared request policy runs before feature handlers.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = request_path();
$path = '/' . trim($path, '/');
if ($path === '/') $path = '/status';
$features = Store::settings()['features'] ?? [];
// One-time repair of progress rows bloated by the old viewing_armed write (no-op once done).
\Vaultcast\Repository::healProgressOnce();
// Access gate: expired / not-yet-approved users and deleted accounts are refused on every API
// and stream request, whatever the UI shows. Admins always pass.
$__tokenPayload = false;
$__token = function () use ($path, &$__tokenPayload) {
    if ($__tokenPayload !== false) return $__tokenPayload;
    $t = '';
    if ($path === '/stream/play' || $path === '/stream/remux' || $path === '/stream/transcode') $t = (string)($_GET['t'] ?? '');
    elseif (preg_match('#^/stream/(?:rom|subtitles)/([a-f0-9]{48})/#', $path, $tm)) $t = $tm[1];
    return $__tokenPayload = preg_match('/^[a-f0-9]{48}$/', $t) ? \Vaultcast\Repository::playbackToken($t) : null;
};
$__denied = Access::gate($path, Auth::currentSession(), function (string $id) { $user = $id === '' ? null : Auth::findById($id); AreaAccess::remember($user); return $user; },
    !NetworkAccess::guestAllowed(), $__token);
if ($__denied !== null) json_out($__denied[1], $__denied[0]);
$__areaDenied = AreaAccess::gate($path);
if ($__areaDenied !== null) json_out($__areaDenied[1], $__areaDenied[0]);
// Parental controls: blocked titles and switched-off social surfaces, checked on the server for every request.
$__parental = strpos($path, '/stream/') === 0 && strpos($path, '/stream/bios/') !== 0 ? Parental::streamGate($__token()) : Parental::gate($path);
if ($__parental !== null) json_out($__parental[1], $__parental[0]);
// Auth/session cookie already read above. Close any native PHP session so a long
// stream/progress request cannot hold the session file lock across workers.
release_request_locks();
$downloadRoute = preg_match('#^/(downloads(?:/|$)|search/(rss|get)(?:/|$)|items/[^/]+/alternate(?:/|$)|shows/[^/]+/(alternate|fetch-missing|episodes/\d+/\d+/(fetch|cancel))(?:/|$)|admin/(acquisition|transmission|downloads)(?:/|$))#', $path);
if ($downloadRoute) {
    Permissions::require($path==='/search/rss'||preg_match('#^/(?:shows|items)/[^/]+/alternate$#D',$path)?'rss_search':'downloads_request');
    if (strpos($path,'/admin/')===0) Auth::requireAdmin();
    if (!AreaAccess::allowed(AreaAccess::viewer(),'library')) json_error('Library access required',403);
    if (empty($features['downloads'])) json_error('Enable download integrations in Settings first.', 404);
}
if (strpos($path, '/player-assets/') === 0 && in_array($method, ['GET','HEAD'], true)) {
    if($path==='/player-assets/genesis-legacy/'.LegacyGenesis::REVISION.'/Genesis.min.js')LegacyGenesis::serve();
    if(preg_match('#^/player-assets/(nes|snes)-legacy/([a-f0-9]{40})/([A-Za-z]+[.]min[.]js)$#D',$path,$legacyNintendo))LegacyNintendo::serve($legacyNintendo[1],$legacyNintendo[2],$legacyNintendo[3]);
    PlayerAssets::serve(substr($path, strlen('/player-assets/')));
}
if (preg_match('#^/admin/system/(kill|processes|service|reboot)(/|$)#', $path) && empty($features['server_tools'])) json_error('Server administration tools are disabled in Settings → Integrations.', 404);
if (preg_match('#^/(search/(rss|get)|admin/(acquisition|transmission|downloads))(/|$)#', $path) && empty($features['downloads'])) json_error('Download integrations are disabled in Settings → Integrations.', 404);
if (strpos($path, '/diagnostics') === 0) Auth::requireAdmin();

if ($path === '/requests' || strpos($path, '/requests/') === 0) {
    require __DIR__ . '/routes/requests.php';
} elseif (strpos($path, '/play/') === 0) {
    require __DIR__ . '/routes/play.php';
} elseif (strpos($path, '/misc/') === 0 || strpos($path, '/drop/') === 0) {
    require __DIR__ . '/routes/misc.php';
} elseif (strpos($path, '/live/') === 0) {
    require __DIR__ . '/routes/live.php';
} elseif (strpos($path, '/admin/') === 0) {
    Permissions::adminRoute($path,$method);
    foreach (['admin-settings','admin-updates','admin-library','admin-conversion','admin-media','admin-system','downloads','collections'] as $handler) {
        require __DIR__ . '/routes/' . $handler . '.php';
    }
} else {
    foreach (['account','hub','library','diagnostics','downloads','collections','lobby','achievements'] as $handler) {
        require __DIR__ . '/routes/' . $handler . '.php';
    }
}
json_error('Not found: ' . $path, 404);
