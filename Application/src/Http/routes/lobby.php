<?php
declare(strict_types=1);
/* client console → server lobby.log (diagnose host crash on join) */
if ($path === '/lobby/client-log' && $method === 'POST') {
    $body = body_json();
    $events = $body['events'] ?? null;
    if (!is_array($events)) $events = [];
    $n = 0;
    foreach ($events as $ev) {
        if (!is_array($ev)) continue;
        $event = preg_replace('/[^a-z0-9_\-.]/i', '', (string)($ev['event'] ?? 'client'));
        if ($event === '') $event = 'client';
        $ctx = ['src' => 'client'];
        foreach ($ev as $k => $v) {
            if ($k === 'event') continue;
            if (!is_string($k)) continue;
            if (is_scalar($v) || $v === null) $ctx[$k] = $v;
            elseif (is_array($v)) $ctx[$k] = $v; // allow small nested
        }
        // Bound size
        $enc = json_encode($ctx);
        if ($enc !== false && strlen($enc) > 4000) {
            $ctx = ['src' => 'client', 'truncated' => true, 'event_len' => strlen($enc)];
        }
        Lobby::logEvent('client:' . $event, $ctx);
        if (++$n >= 40) break;
    }
    json_out(['ok' => true, 'logged' => $n]);
}


/* Multiplayer Lobby session registry */
if ($path === '/lobby/sessions' && $method === 'GET') {
    json_out(['ok' => true, 'sessions' => Lobby::listOpen(), 'heartbeat_seconds' => Lobby::HEARTBEAT_SECONDS]);
}

if ($path === '/lobby/sessions' && $method === 'POST') {
    $body = body_json();
    $itemId = preg_replace('/[^a-f0-9]/i', '', (string)($body['item_id'] ?? ''));
    if ($itemId === '') json_error('item_id required');
    $item = Catalogue::item($itemId);
    if (!$item) json_error('ROM not found', 404);
    $hostName = (string)($body['host_name'] ?? '');
    if ($hostName === '') {
        $hostName = lobby_display_name();
        if ($hostName === '') $hostName = 'Host';
    }
    $hostKey = (string)($body['host_key'] ?? '');
    if ($hostKey === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $hostKey)) {
        $hostKey = bin2hex(random_bytes(16));
    }
    $max = isset($body['max_players']) ? (int)$body['max_players'] : null;
    $res = Lobby::create($item, $hostName, $hostKey, $max);
    if (empty($res['ok'])) json_error($res['error'] ?? 'Could not create session', 400);
    json_out($res);
}

if (preg_match('#^/lobby/sessions/([a-f0-9]{16})$#', $path, $m) && $method === 'DELETE') {
    $body = body_json();
    $hostKey = (string)($body['host_key'] ?? ($_SERVER['HTTP_X_LOBBY_HOST_KEY'] ?? ''));
    if ($hostKey === '') json_error('host_key required');
    json_out(Lobby::end($m[1], $hostKey));
}

if (preg_match('#^/lobby/sessions/([a-f0-9]{16})/heartbeat$#', $path, $m) && $method === 'POST') {
    $body = body_json();
    $hostKey = (string)($body['host_key'] ?? '');
    if ($hostKey === '') json_error('host_key required');
    $res = Lobby::heartbeat($m[1], $hostKey);
    if (empty($res['ok'])) json_error($res['error'] ?? 'heartbeat failed', 400);
    json_out($res);
}

if (preg_match('#^/lobby/sessions/([a-f0-9]{16})/join$#', $path, $m) && $method === 'POST') {
    $body = body_json();
    $name = (string)($body['player_name'] ?? '');
    if ($name === '') {
        $name = lobby_display_name();
        if ($name === '') $name = 'Player';
    }
    $res = Lobby::join($m[1], $name);
    if (empty($res['ok'])) json_error($res['error'] ?? 'join failed', 400);
    json_out($res);
}

if (preg_match('#^/lobby/sessions/([a-f0-9]{16})/leave$#', $path, $m) && $method === 'POST') {
    $body = body_json();
    $slot = (int)($body['player_slot'] ?? 0);
    json_out(Lobby::leave($m[1], $slot));
}

if ($path === '/lobby/multiplayer' && $method === 'GET') {
    $rs = null;
    foreach ((Catalogue::get()['sections'] ?? []) as $s) {
        if (($s['type'] ?? '') === 'roms') { $rs = $s; break; }
    }
    if (!$rs) json_out(['ok' => true, 'page' => 1, 'per' => 48, 'total' => 0, 'has_more' => false, 'items' => []]);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(100, max(1, (int)($_GET['per'] ?? 48)));
    $q = trim((string)($_GET['q'] ?? ''));
    $system = trim((string)($_GET['system'] ?? ''));
    json_out(Lobby::multiplayerItems((string)$rs['id'], $q, $system, $page, $per));
}

if ($path === '/lobby/capability' && $method === 'GET') {
    $itemId = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['item_id'] ?? ''));
    if ($itemId === '') json_error('item_id required');
    $item = Catalogue::item($itemId);
    if (!$item) json_error('not found', 404);
    $cap = Lobby::capability($item);
    json_out(['ok' => true, 'multiplayer' => (bool)$cap, 'capability' => $cap, 'game_id' => Lobby::numericGameId($itemId)]);
}

/* EmulatorJS netplay room list mirror (presence only — signaling needs a Socket.IO netplay server) */
if ($path === '/netplay/list' && $method === 'GET') {
    header('Access-Control-Allow-Origin: *');
    $gid = $_GET['game_id'] ?? '';
    json_out(Lobby::netplayListForGameId($gid));
}
