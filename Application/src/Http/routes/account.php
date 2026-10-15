<?php
declare(strict_types=1);
// Public routes
if ($path === '/status' && $method === 'GET') {
    $settings = Store::settings();
    $sess = Auth::currentSession();
    json_out([
        'ok' => true,
        'app' => $settings['app_name'] ?? 'Vaultcast',
        'version' => STREAM_VERSION,
        'authenticated' => $sess !== null,
        'base' => \Vaultcast\Database::basePath(),
        'public' => true,
    ]);
}

if ($path === '/login' && $method === 'POST') {
    $b = body_json();
    json_out(Auth::login(trim($b['username'] ?? ''), $b['password'] ?? ''));
}

if ($path === '/logout' && $method === 'POST') {
    Auth::destroySession();
    json_out(['ok' => true]);
}

if ($path === '/stream/play' && ($method === 'GET' || $method === 'HEAD')) {
    release_request_locks();
    Stream::play();
}
if ($path === '/stream/remux' && $method === 'GET') {
    Remux::serve();
}
if (preg_match('#^/stream/subtitles/([a-f0-9]{48})/([a-f0-9]{64})\.vtt$#', $path, $m) && in_array($method, ['GET', 'HEAD'], true)) {
    Subtitles::serve($m[1], $m[2]);
}
// OpenSubtitles search/download for the video behind a playback token (signed-in users only).
if (preg_match('#^/stream/subtitles/([a-f0-9]{48})/(search|download)$#', $path, $m)
    && (($m[2] === 'search' && $method === 'GET') || ($m[2] === 'download' && $method === 'POST'))) {
    $user = Auth::requireUser();
    $payload = \Vaultcast\Repository::playbackToken($m[1]);
    if ($payload === null || ($payload['media_kind'] ?? '') !== 'video') json_error('Playback token expired.', 403);
    if ((string)($payload['user_id'] ?? '') !== (string)$user['id'] && ($user['role'] ?? '') !== 'admin') json_error('Forbidden', 403);
    if (!OpenSubtitles::configured()) json_error('OpenSubtitles is not set up. An administrator can add an API key in Settings → Streaming.', 409);
    if (!OpenSubtitles::allowed($user)) json_error('Only administrators can fetch subtitles from OpenSubtitles.', 403);
    release_request_locks();
    $item = Catalogue::item((string)$payload['item_id']) ?: [];
    $item['path'] = (string)$payload['path'];
    if ($m[2] === 'search') {
        $r = OpenSubtitles::search($item, (string)($_GET['lang'] ?? ''));
        if (!$r['ok']) json_error($r['error'], 502);
        json_out($r);
    }
    $b = body_json();
    $r = OpenSubtitles::download($item, (int)($b['file_id'] ?? 0), (string)($b['language'] ?? ''));
    if (!$r['ok']) json_error($r['error'], !empty($r['quota']) ? 429 : 502);
    json_out(['ok' => true, 'remaining' => $r['remaining'], 'subtitles' => Subtitles::tracks((string)$payload['path'], $m[1])]);
}

// EmulatorJS cue/bin siblings + optional PS1 BIOS
if (preg_match('#^/stream/rom/([a-f0-9]{48})/(.+)$#', $path, $m) && ($method === 'GET' || $method === 'HEAD')) {
    Stream::playRom($m[1], $m[2]);
}
if (preg_match('#^/stream/bios/psx/(.+)$#', $path, $m) && ($method === 'GET' || $method === 'HEAD')) {
    Stream::playBios($m[1]);
}

if ($path === '/me' && $method === 'GET') {
    $sess = Auth::currentSession();
    $guestBrowsing = (Store::settings()['auth']['require_login'] ?? true) === false;
    if (!$sess) {
        json_out(['ok' => true, 'user' => null, 'authenticated' => false, 'guest_browsing' => $guestBrowsing, 'pending_convert_count' => 0]);
    }
    $user = Auth::findById($sess['user_id']);
    if (!$user) json_out(['ok' => true, 'user' => null, 'authenticated' => false, 'guest_browsing' => $guestBrowsing, 'pending_convert_count' => 0]);
    unset($user['password_hash']);
    $user['_session'] = $sess;
    $user['access_state'] = Access::state($user);
    $pendingConvert = 0;
    if (($user['role'] ?? '') === 'admin') {
        $pendingConvert = count(Convert::pendingRequests());
    }
    json_out([
        'ok' => true,
        'user' => $user,
        'authenticated' => true,
        'pending_convert_count' => $pendingConvert,
    ]);
}



// Prefs require a real logged-in user
if ($path === '/me/prefs' && $method === 'POST') {
    $user = Auth::requireUser();
    $b = body_json();
    if (!empty($b['password']) && (strlen($b['password']) < 12 || strlen($b['password']) > 72)) json_error('Use a password between 12 and 72 bytes.');
    $saved = \Vaultcast\Repository::updateUser($user['id'], function (array $u) use ($b): array {
            $prefs = $u['prefs'] ?? [];
            foreach (['theme','autoplay','default_quality','reduce_motion','tv_mode','language'] as $k) {
                if (array_key_exists($k, $b)) $prefs[$k] = $b[$k];
            }
            $u['prefs'] = $prefs;
            if (!empty($b['display_name'])) $u['display_name'] = trim($b['display_name']);
            if (!empty($b['password']) && strlen($b['password']) >= 6) {
                $u['password_hash'] = password_hash($b['password'], PASSWORD_DEFAULT);
            }
            return $u;
    });
    unset($saved['password_hash']);
    json_out(['ok' => true, 'user' => $saved]);
}





if ($path === '/progress' && $method === 'POST') {
    // Fire-and-forget from the player: keep this path tiny. No catalogue lookup
    // (the item id is already trusted from an issued play session / UI card).
    release_request_locks();
    $user = current_or_guest();
    $b = body_json();
    $itemId = is_string($b['item_id'] ?? null) ? (string)$b['item_id'] : '';
    if ($itemId === '' || !preg_match('/^[a-f0-9]{8,64}$/', $itemId)) json_error('item_id required');
    $progress = [
        'position' => (float)($b['position'] ?? 0),
        'duration' => (float)($b['duration'] ?? 0),
        'updated_at' => time(),
    ];
    \Vaultcast\Repository::saveProgress((string)$user['id'], $itemId, $progress);
    // 204: browsers / sendBeacon need no body; avoids json_encode on the hot path.
    release_request_locks();
    http_response_code(204);
    exit;
}
