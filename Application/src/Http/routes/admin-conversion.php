<?php
declare(strict_types=1);
if($path==='/admin/convert/review-copy'&&$method==='POST'){
    try{json_out(Convert::reviewExistingCopy((string)(body_json()['item_id']??'')));}catch(RuntimeException $e){json_error($e->getMessage(),409);}
}
if($path==='/admin/convert/recover-copy'&&$method==='POST'){
    $body=body_json();if(($body['confirm']??false)!==true)json_error('Confirm the reviewed replacement first.',422);
    try{json_out(Convert::recoverExistingCopy((string)($body['token']??'')));}catch(RuntimeException|DomainException $e){json_error($e->getMessage(),409);}
}
if ($path==='/admin/transcode' && $method==='GET') json_out(Transcode::status());
if(preg_match('#^/admin/convert/([a-f0-9]+)/preview$#D',$path,$match) && $method==='POST') {
    try {json_out(Convert::preview($match[1],!empty(body_json()['original'])));}catch(RuntimeException $e){json_error($e->getMessage(),409);}
}
if ($path==='/admin/transcode/clear-cache' && $method==='POST') json_out(['ok'=>true,'removed'=>Transcode::cleanup(true)]);
if (preg_match('#^/admin/transcode/([a-f0-9]{32})/stop$#D',$path,$match) && $method==='POST') {
    try {json_out(Transcode::cancel($match[1]));}catch(InvalidArgumentException $e){json_error($e->getMessage(),404);}
}
if ($path==='/admin/convert/merge-versions' && $method==='POST') json_out(Maintenance::start('versions','DirectPlay duplicate repair'));
if ($path==='/admin/convert/policy' && $method==='POST') {
    json_out(Convert::withExternalPolicy(body_json(), static function () {
        return ['ok'=>true,'external_workers_only'=>!empty(Store::settings()['convert']['external_workers_only']),'transfer_limits'=>Convert::transferLimits()];
    }));
}
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
        'contributors' => AIImages::contributorStatus(),
        'external_workers_only' => !empty(Store::settings()['convert']['external_workers_only']),
        'transfer_limits' => Convert::transferLimits(),
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
    json_out(Convert::withExternalPolicy($b, static function () use ($b) {
        if (!empty($b['item_ids']) && is_array($b['item_ids'])) return Convert::enqueueMany($b['item_ids']);
        return Convert::enqueue($b['item_id'] ?? '');
    }));
}

if ($path === '/admin/convert/clear-finished' && $method === 'POST') {
    json_out(Convert::clearFinished());
}
if (preg_match('#^/admin/convert/([a-f0-9]{16})/retry$#D',$path,$m) && $method==='POST') json_out(Convert::retry($m[1]));

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
    json_out(Convert::withExternalPolicy($b, static function () use ($m,$b) {
        return Convert::resolveRequest($m[1], (string)($b['action'] ?? ''));
    }));
}
