<?php
declare(strict_types=1);
// Public routes
if ($path==='/site/home-poster' && in_array($method,['GET','HEAD'],true)) SiteArtwork::serve();
if ($path==='/site/collections-poster' && in_array($method,['GET','HEAD'],true)) SiteArtwork::serve('collections');
if (preg_match('#^/site/home-section/(cinema|watchlist|favourites|calendar|arrivals|friends|history|stats)$#D',$path,$art) && in_array($method,['GET','HEAD'],true)) SiteArtwork::serve($art[1]);
if (preg_match('#^/profiles/([a-f0-9]{16,64})/avatar$#', $path, $m) && in_array($method, ['GET','HEAD'], true)) Profiles::serve($m[1]);
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
        'drop_anonymous' => (function () { try { return Drop::anonEnabled(); } catch (Throwable $e) { return false; } })(),
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
if ($path === '/stream/transcode') {
    try { Transcode::serve(); } catch(InvalidArgumentException $e){json_error($e->getMessage(),400);}
}
if (preg_match('#^/stream/subtitles/([a-f0-9]{48})/([a-f0-9]{64})\.vtt$#', $path, $m) && in_array($method, ['GET', 'HEAD'], true)) {
    Subtitles::serve($m[1], $m[2]);
}
// Upload a subtitle file (raw request body) for the video behind a playback token.
if (preg_match('#^/stream/subtitles/([a-f0-9]{48})/upload$#', $path, $m) && $method === 'POST') {
    $user = Auth::requireUser();
    $payload = \Vaultcast\Repository::playbackToken($m[1]);
    if ($payload === null || ($payload['media_kind'] ?? '') !== 'video') json_error('Playback token expired.', 403);
    if ((string)($payload['user_id'] ?? '') !== (string)$user['id'] && ($user['role'] ?? '') !== 'admin') json_error('Forbidden', 403);
    if (!Subtitles::uploadAllowed($user)) json_error('Only administrators can add subtitles.', 403);
    release_request_locks();
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > 2097152) json_error('Subtitle files can be at most 2 MB.', 413);
    $raw = (string)file_get_contents('php://input', false, null, 0, 2097153);
    $r = Subtitles::upload((string)$payload['path'], $raw, (string)($_GET['lang'] ?? ''));
    if (!$r['ok']) json_error($r['error'], strlen($raw) > 2097152 ? 413 : 422);
    json_out(['ok' => true, 'format' => $r['format'], 'subtitles' => Subtitles::tracks((string)$payload['path'], $m[1])]);
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
    $guestBrowsing = NetworkAccess::guestAllowed();
    if (!$sess) {
        json_out(['ok' => true, 'user' => null, 'authenticated' => false, 'guest_browsing' => $guestBrowsing, 'pending_convert_count' => 0]);
    }
    $user = Auth::findById($sess['user_id']);
    if (!$user) json_out(['ok' => true, 'user' => null, 'authenticated' => false, 'guest_browsing' => $guestBrowsing, 'pending_convert_count' => 0]);
    unset($user['password_hash']);
    $user['_session'] = $sess;
    $user['access_state'] = Access::state($user);
    $user = Permissions::publicUser($user);
    $user['parental'] = Parental::status($user);
    try { $user['offline'] = Offline::status($user); } catch (Throwable $e) { $user['offline'] = ['enabled' => false, 'allowed' => false]; }
    $user['requests'] = MediaRequests::userStatus($user);
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



// Offline downloads (PWA): a ticket for a browser-ready file, the device inventory and offline progress.
if (strpos($path, '/offline/') === 0) {
    $user = Auth::requireUser();
    try {
        if ($path === '/offline/status' && $method === 'GET') json_out(Offline::status($user));
        $b = body_json();
        if ($path === '/offline/prepare' && $method === 'POST') json_out(Offline::prepare($user, (string)($b['item_id'] ?? ''), (string)($b['device'] ?? '')));
        if ($path === '/offline/inventory' && $method === 'POST') json_out(Offline::inventory($user, (string)($b['device'] ?? ''), (array)($b['items'] ?? [])));
        if ($path === '/offline/progress' && $method === 'POST') json_out(Offline::syncProgress($user, (array)($b['entries'] ?? [])));
    } catch (InvalidArgumentException $e) { json_error($e->getMessage(), 422); }
    catch (DomainException $e) { json_error($e->getMessage(), 403); }
    json_error('Not found', 404);
}

// Unified inbox: messages, invitations, library imports, requests and maintenance in one list.
if ($path === '/inbox' || strpos($path, '/inbox/') === 0) {
    $user = Auth::requireUser();
    if (($user['role'] ?? '') === 'guest') json_error('Sign in to use your inbox.', 403);
    if ($path === '/inbox' && $method === 'GET') json_out(Inbox::list($user, preg_replace('/[^a-z]/', '', (string)($_GET['type'] ?? 'all')) ?: 'all', max(0, (int)($_GET['before'] ?? 0)), max(0, (int)($_GET['before_id'] ?? 0))));
    if ($path === '/inbox/badge' && $method === 'GET') json_out(['ok' => true] + Inbox::badge($user));
    $b = body_json();
    if ($path === '/inbox/seen' && $method === 'POST') json_out(Inbox::seen($user));
    if ($path === '/inbox/read' && $method === 'POST') json_out(Inbox::markRead($user, (array)($b['ids'] ?? []), !array_key_exists('read', $b) || !empty($b['read'])));
    if ($path === '/inbox/delete' && $method === 'POST') json_out(Inbox::delete($user, (array)($b['ids'] ?? [])));
    json_error('Not found', 404);
}

// Parental controls for the signed-in profile. The PIN hash never leaves the server.
if ($path === '/me/parental' && $method === 'GET') json_out(Parental::status(Auth::requireUser()));
if ($path === '/me/parental' && $method === 'POST') {
    $user = Auth::requireUser();
    try { json_out(Parental::selfSave($user, body_json())); }
    catch (InvalidArgumentException $e) { json_error($e->getMessage(), 422); }
    catch (DomainException $e) { json_error($e->getMessage(), 403); }
}
if ($path === '/me/parental/unlock' && $method === 'POST') {
    $user = Auth::requireUser();
    try { json_out(Parental::unlock($user, trim((string)(body_json()['pin'] ?? '')))); }
    catch (DomainException $e) { json_error($e->getMessage(), 403); }
}
if ($path === '/me/parental/lock' && $method === 'POST') json_out(Parental::lock(Auth::requireUser()));

// Prefs require a real logged-in user
if ($path === '/me/prefs' && $method === 'POST') {
    require_once STREAM_ROOT . '/src/Live/formats.php';
    $user = Auth::requireUser();
    $b = body_json();
    if(array_key_exists('motion_mode',$b)&&!in_array($b['motion_mode'],['system','full','reduced'],true))json_error('Choose a valid motion setting.',422);
    if (!empty($b['password']) && (strlen($b['password']) < 12 || strlen($b['password']) > 72)) json_error('Use a password between 12 and 72 bytes.');
    try { $avatar = Profiles::saveAvatar($user, $b); } catch (InvalidArgumentException $error) { json_error($error->getMessage(), 422); }
    $saved = \Vaultcast\Repository::updateUser($user['id'], function (array $u) use ($b, $avatar): array {
            $u['avatar_version'] = $avatar;
            $prefs = $u['prefs'] ?? [];
            foreach (['theme','autoplay','default_quality','language'] as $k) {
                if (array_key_exists($k, $b)) $prefs[$k] = $b[$k];
            }
            if(array_key_exists('motion_mode',$b)){$prefs['motion_mode']=$b['motion_mode'];$prefs['reduce_motion']=$b['motion_mode']==='reduced';}
            elseif(array_key_exists('reduce_motion',$b)){$prefs['reduce_motion']=in_array($b['reduce_motion'],[true,1,'1','true'],true);$prefs['motion_mode']=$prefs['reduce_motion']?'reduced':'full';}
            foreach (['autoplay_on_open','autoplay_next','mini_player','skip_segments','auto_skip_intro','auto_skip_outro'] as $key) if(array_key_exists($key,$b)) $prefs[$key]=!empty($b[$key]);
            if (array_key_exists('hide_spoilers',$b)) $prefs['hide_spoilers']=!empty($b['hide_spoilers']);
            if (array_key_exists('home_compact',$b)) $prefs['home_compact']=!empty($b['home_compact']);
            if (array_key_exists('home_panels',$b) && is_array($b['home_panels'])) $prefs['home_panels']=array_values(array_unique(array_intersect($b['home_panels'],['recent','games','watchlist','friends','calendar','stats'])));
            if (isset($b['home_landing']) && in_array($b['home_landing'],['overview','watchlist','favourites','calendar','friends','cinema','history','stats','inbox','arrivals','lists'],true)) $prefs['home_landing']=$b['home_landing'];
            if (array_key_exists('live_cat_formats', $b)) $prefs['live_cat_formats'] = relay_clean_formats($b['live_cat_formats']);
            $u['prefs'] = $prefs;
            if (!empty($b['display_name'])) $u['display_name'] = trim($b['display_name']);
            if (!empty($b['password']) && strlen($b['password']) >= 6) {
                $u['password_hash'] = password_hash($b['password'], PASSWORD_DEFAULT);
            }
            return $u;
    });
    unset($saved['password_hash']);$saved=Permissions::publicUser($saved);
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
    // Trailers and extras keep no resume position or watch history.
    if (strlen($itemId) === 24) { http_response_code(204); exit; }
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
