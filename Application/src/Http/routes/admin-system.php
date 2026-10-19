<?php
declare(strict_types=1);
if ($path === '/admin/maintenance/requirements' && $method === 'GET') json_out(MaintenanceRequirements::status());
if ($path === '/admin/maintenance' && $method === 'GET') json_out(Maintenance::status());
if ($path === '/admin/maintenance' && $method === 'POST') {
    $body = body_json(); $admin = Auth::requireAdmin();
    $task = (string)($body['task'] ?? '');
    $target = $task === 'game-metadata' ? trim((string)($body['target'] ?? '')) : '';
    if ($target !== '' && !\Vaultcast\Repository::rows('SELECT payload FROM vaultcast_items WHERE id=? AND media_kind=? LIMIT 1', [$target, 'rom'])) throw new InvalidArgumentException('Game not found.');
    json_out(Maintenance::start($task, $admin['username'], $target !== '' ? $target : null));
}
if ($path === '/admin/maintenance/cancel' && $method === 'POST') json_out(Maintenance::cancel((string)(body_json()['id'] ?? '')));

if (preg_match('#^/admin/maintenance/([0-9]{14}-[a-f0-9]{12})$#', $path, $match) && $method === 'GET') {
    $job = \Vaultcast\Repository::document('maintenance-job:' . $match[1]);
    json_out(['ok' => (bool)$job, 'job' => $job]);
}

// ---- System admin (): resources / services / processes / reboot ----
if ($path === '/admin/system/status' && $method === 'GET') {
    json_out(SystemAdmin::status());
}

if ($path === '/admin/system/processes' && $method === 'GET') {
    $limit = (int)($_GET['limit'] ?? 80);
    json_out(SystemAdmin::processes($limit));
}

if ($path === '/admin/system/kill' && $method === 'POST') {
    $b = body_json();
    $pid = (int)($b['pid'] ?? 0);
    $force = !empty($b['force']);
    json_out(SystemAdmin::killProcess($pid, $force));
}

if ($path === '/admin/system/service/restart' && $method === 'POST') {
    $b = body_json();
    json_out(SystemAdmin::restartService((string)($b['id'] ?? '')));
}

if ($path === '/admin/system/reboot' && $method === 'POST') {
    $b = body_json();
    json_out(SystemAdmin::reboot(!empty($b['confirm'])));
}

if ($path === '/admin/system/cache-clear' && $method === 'POST') {
    $b = body_json();
    $targets = $b['targets'] ?? [];
    if (!is_array($targets)) $targets = [];
    json_out(SystemAdmin::clearCaches($targets));
}

if ($path === '/admin/system/optimise-status' && $method === 'GET') {
    json_out(SystemAdmin::optimiseStatus());
}

/* Poster WebP optimisation batch */
if ($path === '/admin/posters/optimise/summary' && $method === 'GET') {
    json_out(PosterOptimise::summary());
}
if ($path === '/admin/posters/optimise/find' && $method === 'GET') {
    $offset = (int)($_GET['offset'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 40);
    $scope = (string)($_GET['scope'] ?? 'all');
    $overwrite = isset($_GET['overwrite']) && ($_GET['overwrite'] === '1' || $_GET['overwrite'] === 'true');
    json_out(PosterOptimise::find($offset, $limit, $scope, $overwrite));
}
if ($path === '/admin/posters/optimise' && $method === 'POST') {
    @set_time_limit(180);
    $b = body_json();
    $opts = [];
    foreach (['full_quality','full_max_w','medium_quality','medium_max_w','sizes_version','thumb_quality','thumb_max_w','compression_level','overwrite'] as $k) {
        if (array_key_exists($k, $b)) $opts[$k] = $b[$k];
    }
    if (!empty($b['file']) || !empty($b['stem'])) {
        json_out(PosterOptimise::optimiseOne((string)($b['file'] ?? $b['stem']), $opts));
    }
    $files = $b['files'] ?? [];
    if (!is_array($files)) $files = [];
    $budget = (int)($b['budget'] ?? 3);
    json_out(PosterOptimise::enqueueAndRun($files, $budget, $opts));
}
if ($path === '/admin/posters/optimise/queue' && $method === 'GET') {
    json_out(['ok' => true, 'queue' => PosterOptimise::readQueue(), 'summary' => PosterOptimise::summary()]);
}
if ($path === '/admin/posters/optimise/clear-finished' && $method === 'POST') {
    json_out(PosterOptimise::clearFinished());
}
if ($path === '/admin/posters/optimise/cancel' && $method === 'POST') {
    json_out(PosterOptimise::cancel());
}

if ($path === '/admin/system/logs' && $method === 'GET') {
    $lines = (int)($_GET['lines'] ?? 40);
    json_out(SystemAdmin::recentLogs($lines));
}
