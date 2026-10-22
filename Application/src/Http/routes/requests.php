<?php
declare(strict_types=1);
// Media requests: ask for films and shows the library doesn't have; managers approve or decline.
// Every route checks the session and permission here; writes are POST (same-origin check in bootstrap).
$user = Auth::requireUser();
if (($user['role'] ?? '') === 'guest') json_error('Sign in to request titles.', 403);
if (!AreaAccess::allowed($user, 'library')) json_error('Library access required', 403);
$manage = strpos($path, '/requests/manage') === 0;
if ($manage && !MediaRequests::canManage($user)) json_error('Managing requests needs the requests.manage permission.', 403);
try {
    if ($path === '/requests/status' && $method === 'GET') json_out(MediaRequests::status($user));
    if (!$manage && !MediaRequests::config()['enabled'] && !MediaRequests::canManage($user)) json_error('Requests are turned off on this server.', 404);
    if ($path === '/requests/search' && $method === 'GET') {
        if (!MediaRequests::canCreate($user)) json_error('Your account can’t request titles. Ask an administrator.', 403);
        json_out(MediaRequests::search($user, (string)($_GET['type'] ?? 'movie'), (string)($_GET['q'] ?? '')));
    }
    if ($path === '/requests/seasons' && $method === 'GET') {
        if (!MediaRequests::canCreate($user)) json_error('Your account can’t request titles. Ask an administrator.', 403);
        json_out(MediaRequests::seasons($user, (string)($_GET['source'] ?? ''), (string)($_GET['id'] ?? '')));
    }
    if ($path === '/requests/mine' && $method === 'GET') json_out(MediaRequests::mine($user));
    if ($path === '/requests' && $method === 'POST') json_out(MediaRequests::create($user, body_json()));
    if (preg_match('#^/requests/(\d{1,18})/cancel$#D', $path, $m) && $method === 'POST') json_out(MediaRequests::cancel($user, (int)$m[1]));
    if ($path === '/requests/manage' && $method === 'GET') {
        json_out(MediaRequests::manageList($user, preg_replace('/[^a-z]/', '', (string)($_GET['status'] ?? 'pending')) ?: 'pending', (string)($_GET['q'] ?? ''), (int)($_GET['page'] ?? 1)));
    }
    if ($path === '/requests/manage/decide' && $method === 'POST') {
        $b = body_json();
        $acquire = array_key_exists('acquire', $b) && $b['acquire'] !== null ? !empty($b['acquire']) : null;
        json_out(MediaRequests::decide($user, (array)($b['ids'] ?? []), (string)($b['action'] ?? ''), (string)($b['reason'] ?? ''), $acquire));
    }
    // Auto-approve, limits and defaults are server-wide: administrators only.
    if ($path === '/requests/manage/settings') {
        if (($user['role'] ?? '') !== 'admin') json_error('Only administrators can change request settings.', 403);
        if ($method === 'GET') json_out(MediaRequests::settingsView());
        if ($method === 'POST') { MediaRequests::saveConfig(body_json()); json_out(MediaRequests::settingsView()); }
    }
} catch (InvalidArgumentException $e) { json_error($e->getMessage(), 422); }
catch (DomainException $e) { json_error($e->getMessage(), 403); }
json_error('Not found', 404);
