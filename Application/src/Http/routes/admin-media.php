<?php
declare(strict_types=1);
// ---- Media Manager (interactive catalogue + file browser) ----
if ($path === '/admin/media/diagnose' && $method === 'GET') {
    json_out(PathDiagnose::run([
        'include_orphans' => isset($_GET['orphans']) && $_GET['orphans'] !== '0',
        'limit' => (int)($_GET['limit'] ?? 2000),
        'orphan_limit' => (int)($_GET['orphan_limit'] ?? 100),
    ]));
}

if ($path === '/admin/media/diagnose' && $method === 'POST') {
    json_out(PathDiagnose::run(body_json()));
}

if ($path === '/admin/media/diagnose/fix' && $method === 'POST') {
    json_out(PathDiagnose::applyFix(body_json()));
}

if ($path === '/admin/media/browse' && $method === 'GET') {
    json_out(MediaManager::browseCatalogue([
        'section' => $_GET['section'] ?? '',
        'q' => $_GET['q'] ?? '',
        'mode' => $_GET['mode'] ?? 'auto',
        'page' => $_GET['page'] ?? 1,
        'per' => $_GET['per'] ?? 40,
    ]));
}

if ($path === '/admin/media/browse' && $method === 'POST') {
    json_out(MediaManager::browseCatalogue(body_json()));
}

if ($path === '/admin/media/files' && $method === 'GET') {
    json_out(MediaManager::browseFiles(['path' => $_GET['path'] ?? '']));
}

if ($path === '/admin/media/files' && $method === 'POST') {
    json_out(MediaManager::browseFiles(body_json()));
}

if ($path === '/admin/media/search-metadata' && $method === 'POST') {
    @set_time_limit(60);
    json_out(MediaManager::searchMetadata(body_json()));
}

if ($path === '/admin/media/update' && $method === 'POST') {
    @set_time_limit(120);
    json_out(MediaManager::updateItem(body_json()));
}

if ($path === '/admin/media/reassign' && $method === 'POST') {
    @set_time_limit(300);
    json_out(MediaManager::reassignShow(body_json()));
}

if ($path === '/admin/media/ingest' && $method === 'POST') {
    json_out(MediaManager::ingest(body_json()));
}

if ($path === '/admin/media/roots' && $method === 'GET') {
    json_out(['ok' => true, 'roots' => MediaManager::allowedRoots()]);
}
