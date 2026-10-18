<?php
declare(strict_types=1);
if ($path === '/admin/posters/audit' && $method === 'POST') json_out(ArtworkAudit::scan(body_json()));
if ($path === '/admin/posters/audit/status' && $method === 'POST') json_out(ArtworkAudit::status(body_json()));
if ($path === '/admin/posters/audit/repair' && $method === 'POST') json_out(ArtworkAudit::repair(body_json()));
if ($path === '/admin/posters/status' && $method === 'GET') {
    try {
        json_out(Posters::coverage());
    } catch (Throwable $e) {
        if($e instanceof CompatibilityApiResult)throw $e;
        json_out(['ok' => false, 'error' => 'Poster status failed: ' . $e->getMessage()], 200);
    }
}

if ($path === '/admin/posters/fetch' && $method === 'POST') {
    @set_time_limit(120);
    @ini_set('max_execution_time', '120');
    ignore_user_abort(true);
    try {
        $b = body_json();
        $limit = (int)($b['limit'] ?? $_GET['limit'] ?? 8);
        $limit = max(1, min(12, $limit)); // small batches; Posters::prefetchMissing is time-budgeted
        $kinds = $b['kinds'] ?? ($_GET['kinds'] ?? ['shows', 'movies', 'games']);
        json_out(Posters::prefetchMissing($limit, $kinds));
    } catch (Throwable $e) {
        if($e instanceof CompatibilityApiResult)throw $e;
        json_out([
            'ok' => false,
            'error' => 'Poster fetch failed: ' . $e->getMessage(),
            'fetched' => 0,
            'failed' => [],
            'items' => [],
            'remaining' => null,
            'more' => false,
        ], 200); // still JSON, never blank 502 from uncaught fatal if possible
    }
}

if ($path === '/admin/rescan' && $method === 'POST') {
    @set_time_limit(600);
    $started = Maintenance::start('scan', Auth::requireAdmin()['username']);
    json_out(['ok' => true, 'maintenance_job' => $started['job']['id']]);
}



// ---- Library manager: find / rename / move misplaced media ----
if ($path === '/admin/library/scan-orphans' && $method === 'GET') {
    @set_time_limit(300);
    $opts = [
        'limit' => (int)($_GET['limit'] ?? 100),
        'include_section_roots' => !empty($_GET['include_section_roots']),
    ];
    if (!empty($_GET['roots'])) {
        $roots = array_filter(array_map('trim', explode(',', (string)$_GET['roots'])));
        if ($roots) $opts['roots'] = array_values($roots);
    }
    json_out(Library::scanOrphans($opts));
}

if ($path === '/admin/library/scan-orphans' && $method === 'POST') {
    @set_time_limit(300);
    json_out(Library::scanOrphans(body_json()));
}

if ($path === '/admin/library/suggest' && $method === 'POST') {
    @set_time_limit(60);
    json_out(Library::suggest(body_json()));
}

if ($path === '/admin/library/preview' && $method === 'POST') {
    json_out(Library::preview(body_json()));
}

if ($path === '/admin/library/apply' && $method === 'POST') {
    @set_time_limit(120);
    json_out(Library::apply(body_json()));
}

if ($path === '/admin/library/apply-batch' && $method === 'POST') {
    @set_time_limit(600);
    json_out(Library::applyBatch(body_json()));
}

if ($path === '/admin/library/query' && $method === 'POST') {
    json_out(LibraryQuery::search(body_json()));
}

if ($path === '/admin/library/query/preview' && $method === 'POST') {
    json_out(LibraryQuery::preview(body_json()));
}

if ($path === '/admin/library/query/apply' && $method === 'POST') {
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) json_error('JSON required', 415);
    @set_time_limit(300);
    json_out(LibraryQuery::apply(body_json()));
}

if ($path === '/admin/library/roots' && $method === 'GET') {
    json_out([
        'ok' => true,
        'roots' => Library::defaultScanRoots(),
        'sections' => array_map(function ($s) {
            return [
                'id' => $s['id'] ?? '',
                'label' => $s['label'] ?? '',
                'type' => $s['type'] ?? '',
                'path' => $s['path'] ?? '',
                'enabled' => !empty($s['enabled']),
            ];
        }, Store::settings()['sections'] ?? []),
    ]);
}



// ---- Matching / Ingest inbox / Label reports ----
if (strpos($path, '/admin/match') === 0 || strpos($path, '/admin/ingest') === 0 || strpos($path, '/admin/reports') === 0) {
    if ($path === '/admin/match/counts' && $method === 'GET') {
        json_out(['ok' => true, 'reports' => Matching::openReportCount(), 'ingest' => Ingest::pendingCount(), 'ingest_mode' => Ingest::mode()]);
    }
    if ($path === '/admin/match/shows' && $method === 'GET') {
        json_out(Matching::showsOverview((string)($_GET['q'] ?? ''), (string)($_GET['filter'] ?? 'problems')));
    }
    if (preg_match('#^/admin/match/show/([a-f0-9]{16})$#', $path, $m) && $method === 'GET') {
        json_out(Matching::showEditor($m[1]));
    }
    if (preg_match('#^/admin/match/show/([a-f0-9]{16})/propose$#', $path, $m) && $method === 'POST') {
        json_out(Matching::proposeShow($m[1], body_json()));
    }
    if (preg_match('#^/admin/match/show/([a-f0-9]{16})/apply$#', $path, $m) && $method === 'POST') {
        json_out(Matching::applyShowProposals($m[1], body_json()));
    }
    if (preg_match('#^/admin/match/show/([a-f0-9]{16})/numbering/preview$#', $path, $m) && $method === 'POST') {
        $nb = body_json(); unset($nb['_guide']);
        json_out(AbsoluteNumbering::preview($m[1], $nb));
    }
    if (preg_match('#^/admin/match/show/([a-f0-9]{16})/numbering$#', $path, $m) && $method === 'POST') {
        $nb = body_json(); unset($nb['_guide']);
        json_out(AbsoluteNumbering::apply($m[1], $nb));
    }
    if (preg_match('#^/admin/match/show/([a-f0-9]{16})/pin$#', $path, $m) && $method === 'POST') {
        json_out(Matching::pinShow($m[1], body_json()));
    }
    if ($path === '/admin/match/search' && $method === 'POST') {
        $b = body_json();
        $kind = (string)($b['kind'] ?? 'tv');
        $res = $kind === 'game' ? PublicMetadata::search('game', (string)($b['q'] ?? ''), $b['source'] ?? null) : Ingest::search($kind, (string)($b['q'] ?? ''), isset($b['source']) ? (string)$b['source'] : null);
        if (empty($res['sources'])) $res['sources'] = PublicMetadata::sources($kind);
        Permissions::rememberSelections($res);
        json_out($res);
    }
    if ($path === '/admin/match/items' && $method === 'POST') {
        $b = body_json();
        json_out(Matching::saveItemFields((array)($b['rows'] ?? []), 'manual', Matching::actor()));
    }
    if ($path === '/admin/match/items/clear' && $method === 'POST') {
        $b = body_json();
        json_out(Matching::clearItems((array)($b['ids'] ?? [])));
    }
    if ($path === '/admin/match/reparse' && $method === 'POST') {
        $b = body_json();
        @set_time_limit(300);
        json_out(Matching::reparseCatalogue(empty($b['apply']), 40));
    }
    if (preg_match('#^/admin/match/movie/([a-f0-9]{16})$#', $path, $m) && $method === 'POST') {
        json_out(Matching::rematchMovie($m[1], body_json()));
    }
    if ($path === '/admin/reports' && $method === 'GET') {
        json_out(Matching::listReports((string)($_GET['status'] ?? 'open')));
    }
    if (preg_match('#^/admin/reports/([a-f0-9]{12})$#', $path, $m) && $method === 'POST') {
        json_out(Matching::updateReport($m[1], body_json()));
    }
    if ($path === '/admin/ingest' && $method === 'GET') {
        json_out(Ingest::inbox(!empty($_GET['all'])));
    }
    if ($path === '/admin/ingest/search' && $method === 'POST') {
        $b = body_json();
        json_out(Ingest::search((string)($b['kind'] ?? 'tv'), (string)($b['q'] ?? '')));
    }
    if ($path === '/admin/ingest/plan' && $method === 'POST') {
        json_out(Ingest::plan(body_json()));
    }
    if ($path === '/admin/ingest/commit' && $method === 'POST') {
        $b = body_json();
        // Run as a background job (no PHP/nginx request timeout); inline only if the CLI worker cannot start
        try {
            $started = Maintenance::start('ingest', Auth::requireAdmin()['username'], (string)($b['hash'] ?? ''), $b);
            json_out(['ok' => true, 'job' => $started['job']['id'], 'background' => true]);
        } catch (RuntimeException $e) {
            @set_time_limit(600);
            json_out(Ingest::commit($b) + ['background' => false]);
        }
    }
    if ($path === '/admin/ingest/progress' && $method === 'GET') {
        $id = (string)($_GET['id'] ?? '');
        if (!preg_match('/^\d{14}-[a-f0-9]{12}$/D', $id)) json_error('bad id');
        $job = \Vaultcast\Repository::document('maintenance-job:' . $id);
        if (!is_array($job) || ($job['task'] ?? '') !== 'ingest') json_error('Import job not found', 404);
        json_out(['ok' => true, 'status' => $job['status'] ?? 'queued', 'progress' => $job['progress'] ?? null, 'result' => $job['result'] ?? null, 'error' => $job['error'] ?? null]);
    }
    if ($path === '/admin/ingest/status' && $method === 'POST') {
        $b = body_json();
        json_out(Ingest::setStatus((string)($b['hash'] ?? ''), (string)($b['status'] ?? '')));
    }
    if ($path === '/admin/ingest/mode' && $method === 'POST') {
        $b = body_json();
        $mode = (string)($b['mode'] ?? '');
        if (!in_array($mode, ['ask_unlinked', 'ask_all', 'auto'], true)) json_error('bad mode');
        $s = Store::settings();
        $s['acquisition']['ingest_mode'] = $mode;
        Store::saveSettings($s);
        json_out(['ok' => true, 'mode' => $mode]);
    }
}
