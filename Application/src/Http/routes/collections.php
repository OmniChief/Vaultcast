<?php
declare(strict_types=1);
if (preg_match('#^/collections/for-item/([^/]+)$#', $path, $m) && $method === 'GET') {
    json_out(Collections::forItem(rawurldecode($m[1])));
}

if (preg_match('#^/collections/poster/([a-f0-9]{16}\.(?:jpg|png))$#', $path, $m) && $method === 'GET') {
    $file = Collections::posterDir() . '/' . $m[1];
    if (!is_file($file)) json_error('Not found', 404);
    header('Content-Type: ' . (substr($m[1], -4) === '.png' ? 'image/png' : 'image/jpeg'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=2592000, immutable');
    readfile($file);
    exit;
}

if ($path === '/collections' && $method === 'GET') {
    json_out(Collections::publicList());
}

if (preg_match('#^/collections/([a-f0-9]+)$#', $path, $m) && $method === 'GET') {
    json_out(Collections::status($m[1]));
}



if ($path === '/admin/collections' && $method === 'GET') {
    json_out(['ok' => true, 'collections' => Collections::all()]);
}

if ($path === '/admin/collections' && $method === 'POST') {
    json_out(Collections::create(body_json()));
}

if (preg_match('#^/admin/collections/([a-f0-9]+)$#', $path, $m) && $method === 'POST') {
    json_out(Collections::update($m[1], body_json()));
}

if (preg_match('#^/admin/collections/([a-f0-9]+)$#', $path, $m) && $method === 'DELETE') {
    json_out(Collections::delete($m[1]));
}

if (preg_match('#^/admin/collections/([a-f0-9]+)/status$#', $path, $m) && $method === 'GET') {
    json_out(Collections::status($m[1]));
}

if (preg_match('#^/admin/collections/([a-f0-9]+)/fetch-missing$#', $path, $m) && $method === 'POST') {
    json_out(Collections::fetchMissing($m[1]));
}

// re-resolve collection parts against the library and persist auto links (item_id)
if ($path === '/admin/collections/rematch' && $method === 'POST') {
    $started = Maintenance::start('collections', Auth::requireAdmin()['username']);
    json_out(['ok' => true, 'maintenance_job' => $started['job']['id']]);
}

if (preg_match('#^/admin/collections/([a-f0-9]+)/rematch$#', $path, $m) && $method === 'POST') {
    $started = Maintenance::start('collections', Auth::requireAdmin()['username'], $m[1]);
    json_out(['ok' => true, 'maintenance_job' => $started['job']['id']]);
}

// manual link/unlink one part: {index:int, item_id:string|null}
if (preg_match('#^/admin/collections/([a-f0-9]+)/link$#', $path, $m) && $method === 'POST') {
    json_out(Collections::linkPart($m[1], body_json()));
}


if ($path === '/admin/collections/search-online' && $method === 'POST') {
    $b = body_json();
    $q = trim((string)($b['query'] ?? $b['q'] ?? ''));
    json_out(Collections::searchOnline($q));
}

if ($path === '/admin/collections/import-online' && $method === 'POST') {
    @set_time_limit(90);
    json_out(Collections::importOnline(body_json()));
}

// Find collections for library films (TMDB with a key, else Wikidata). Short resumable steps.
if ($path === '/admin/collections/discover' && $method === 'GET') {
    Auth::requireAdmin();
    json_out(CollectionDiscovery::progress());
}
if ($path === '/admin/collections/discover' && $method === 'POST') {
    Auth::requireAdmin();
    $b = body_json();
    $action = (string)($b['action'] ?? 'step');
    release_request_locks();
    @set_time_limit(60);
    if ($action === 'start') json_out(CollectionDiscovery::start());
    if ($action === 'cancel') json_out(CollectionDiscovery::cancel());
    json_out(CollectionDiscovery::step());
}
