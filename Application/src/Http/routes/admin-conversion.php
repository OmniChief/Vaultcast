<?php
declare(strict_types=1);
if ($path === '/admin/convert/pending' && $method === 'GET') {
    release_request_locks();
    $pending = Convert::pendingRequests();
    json_out([
        'ok' => true,
        'count' => count($pending),
        'pending_requests' => $pending,
    ]);
}

if ($path === '/admin/convert' && $method === 'GET') {
    Convert::tick();
    json_out([
        'ok' => true,
        'queue' => Convert::queue(),
        'requests' => Convert::requests(),
        'pending_requests' => Convert::pendingRequests(),
        'defaults' => Convert::defaults(),
    ]);
}

if ($path === '/admin/convert/find-needs' && $method === 'GET') {
    $typesRaw = (string)($_GET['types'] ?? 'movies,tv,anime');
    $types = array_values(array_filter(array_map('trim', explode(',', $typesRaw))));
    json_out(Convert::findNeedsPage(
        (int)($_GET['offset'] ?? 0),
        (int)($_GET['limit'] ?? 8),
        $types
    ));
}

if ($path === '/admin/convert/library' && $method === 'GET') {
    json_out(Convert::searchLibrary(
        (string)($_GET['q'] ?? ''),
        [
            'limit' => (int)($_GET['limit'] ?? 60),
            'needs_convert' => !isset($_GET['needs_convert']) || $_GET['needs_convert'] === '' || $_GET['needs_convert'] === '1' || $_GET['needs_convert'] === 'true',
        ]
    ));
}

if ($path === '/admin/convert' && $method === 'POST') {
    $b = body_json();
    if (!empty($b['item_ids']) && is_array($b['item_ids'])) {
        json_out(Convert::enqueueMany($b['item_ids']));
    }
    json_out(Convert::enqueue($b['item_id'] ?? ''));
}

if ($path === '/admin/convert/clear-finished' && $method === 'POST') {
    json_out(Convert::clearFinished());
}

if (preg_match('#^/admin/convert/([a-f0-9]+)/cancel$#', $path, $m) && $method === 'POST') {
    json_out(Convert::cancel($m[1]));
}

if (preg_match('#^/admin/convert/([a-f0-9]+)/remove-original$#', $path, $m) && $method === 'POST') {
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) json_error('JSON body required', 415);
    $b = body_json();
    if (empty($b['confirm'])) json_error('confirm:true required', 400);
    json_out(Convert::removeOriginal($m[1]));
}

if (preg_match('#^/admin/convert/([a-f0-9]+)/delete-converted$#', $path, $m) && $method === 'POST') {
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) json_error('JSON body required', 415);
    $b = body_json();
    if (empty($b['confirm'])) json_error('confirm:true required', 400);
    json_out(Convert::deleteConverted($m[1]));
}

if (preg_match('#^/admin/convert/([a-f0-9]+)/discard$#', $path, $m) && $method === 'POST') {
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) json_error('JSON body required', 415);
    $b = body_json();
    if (empty($b['confirm'])) json_error('confirm:true required', 400);
    json_out(Convert::discard($m[1]));
}

if (preg_match('#^/admin/convert/([a-f0-9]+)/resolve$#', $path, $m) && $method === 'POST') {
    json_out(Convert::resolveJob($m[1]));
}

if (preg_match('#^/admin/convert/requests/([a-f0-9]+)$#', $path, $m) && $method === 'POST') {
    $b = body_json();
    json_out(Convert::resolveRequest($m[1], (string)($b['action'] ?? '')));
}
