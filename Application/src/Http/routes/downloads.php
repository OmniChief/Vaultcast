<?php
declare(strict_types=1);
if (preg_match('#^/shows/([a-f0-9]+)/alternate$#', $path, $m) && $method === 'POST') {
    $detail = Catalogue::show($m[1]);
    if (!$detail || empty($detail['show'])) json_error('Show not found', 404);
    $show = $detail['show'];
    $b = body_json();
    $title = trim((string)($b['query'] ?? $show['title'] ?? ''));
    if ($title === '') json_error('query required');
    $kind = trim((string)($b['kind'] ?? (($show['section'] ?? '') === 'anime' || ($show['type'] ?? '') === 'anime' ? 'anime' : 'tv')));
    $acq = Store::settings()['acquisition'] ?? [];
    $filters = Acquisition::searchFilters($acq);
    $q = $title;
    $qualities = $filters['preferred_qualities'] ?? ($acq['preferred_qualities'] ?? []);
    $langs = $filters['language_filters'] ?? ($acq['language_filters'] ?? []);
    if (!empty($b['quality'])) $q .= ' ' . trim((string)$b['quality']);
    elseif (is_array($qualities) && $qualities) $q .= ' ' . $qualities[0];
    if (!empty($b['language'])) $q .= ' ' . trim((string)$b['language']);
    elseif (is_array($langs) && $langs) $q .= ' ' . $langs[0];
    $containers = $filters['preferred_containers'] ?? ['mp4'];
    if (!empty($b['container'])) $q .= ' ' . trim((string)$b['container']);
    elseif (is_array($containers) && $containers) $q .= ' ' . $containers[0];
    $res = Acquisition::searchRssFallback($q, ['kind' => $kind, 'force' => !empty($b['force'])]);
    $res['query_used'] = $q;
    $res['filters'] = $filters;
    $res['source_show'] = ['show_key' => $m[1], 'title' => $show['title'] ?? ''];
    json_out($res);
}

if (preg_match('#^/shows/([a-f0-9]+)/fetch-missing$#', $path, $m) && $method === 'POST') {
    $detail = Catalogue::show($m[1]);
    if (!$detail) json_error('Show not found', 404);
    $b = body_json();
    $onlySeason = isset($b['season']) ? (int)$b['season'] : null;
    $onlyEpisode = isset($b['episode']) ? (int)$b['episode'] : null;
    // Optional: limit to one season when season provided without episode
    json_out(Acquisition::fetchMissingForShow($detail, $onlySeason, $onlyEpisode));
}

if (preg_match('#^/shows/([a-f0-9]+)/episodes/(\d+)/(\d+)/cancel$#', $path, $m) && $method === 'POST') {
    $detail = Catalogue::show($m[1]);
    if (!$detail) json_error('Show not found', 404);
    $showTitle = $detail['show']['title'] ?? '';
    json_out(Acquisition::cancelEpisodeSearch($showTitle, (int)$m[2], (int)$m[3]));
}

if (preg_match('#^/shows/([a-f0-9]+)/episodes/(\\d+)/(\\d+)/fetch$#', $path, $m) && $method === 'POST') {
    $detail = Catalogue::show($m[1]);
    if (!$detail) json_error('Show not found', 404);
    $showTitle = $detail['show']['title'] ?? '';
    $season = (int)$m[2];
    $episode = (int)$m[3];
    // Verify it is considered missing (or allow re-fetch)
    $force = !empty(body_json()['force']);
    $isMissing = true;
    foreach ($detail['seasons'] ?? [] as $sb) {
        if ((int)$sb['season'] !== $season) continue;
        foreach ($sb['episodes'] ?? [] as $ep) {
            if ((int)($ep['episode'] ?? -1) !== $episode) continue;
            $isMissing = empty($ep['available']);
            break 2;
        }
    }
    if (!$isMissing && !$force) {
        json_out(['ok' => true, 'skipped' => true, 'reason' => 'Episode already available']);
    }
    json_out(Acquisition::requestEpisode($showTitle, $season, $episode));
}




if ($path === '/downloads/status' && $method === 'GET') {
    // Public: minimal downloading badges only (full queue is admin-only)
    json_out(Acquisition::publicDownloadBadges());
}


if ($path === '/convert/request' && $method === 'POST') {
    $user = current_or_guest();
    $b = body_json();
    $opts = [
        'reason' => (string)($b['reason'] ?? 'playback_failed'),
        'device' => (string)($b['device'] ?? 'TV'),
        'format' => (string)($b['format'] ?? ''),
        'requested_by' => (string)($user['username'] ?? 'guest'),
    ];
    json_out(Convert::createRequest((string)($b['item_id'] ?? ''), $opts));
}

// any viewer can flag a wrong episode / movie / anime label
if ($path === '/reports/label' && $method === 'POST') {
    $user = current_or_guest();
    json_out(Matching::createReport(body_json(), $user));
}



if ($path === '/admin/transmission/check' && $method === 'POST') {
    json_out(Acquisition::checkConnection(body_json()));
}

if ($path === '/admin/transmission/download-dir' && $method === 'GET') {
    $dir = isset($_GET['path']) ? (string)$_GET['path'] : null;
    json_out(['ok' => true, 'transmission' => Acquisition::transmissionDownloadDir()] + Acquisition::downloadVolumeInfo($dir));
}

if ($path === '/admin/transmission' && $method === 'GET') {
    json_out(Acquisition::downloadStatus());
}

if ($path === '/admin/transmission/seeding' && $method === 'POST') {
    $b = body_json();
    json_out(Acquisition::applyTransmissionSeedingPolicy([
        'apply_existing' => !isset($b['apply_existing']) || !empty($b['apply_existing']),
    ]));
}



if ($path === '/admin/downloads/process-completed' && $method === 'POST') {
    $started = Maintenance::start('downloads', Auth::requireAdmin()['username']);
    json_out(['ok' => true, 'maintenance_job' => $started['job']['id']]);
}

if ($path === '/admin/downloads/process-completed/cancel' && $method === 'POST') {
    json_out(Acquisition::requestCancelProcessCompleted());
}

if ($path === '/admin/downloads/remove' && $method === 'POST') {
    json_out(Acquisition::removeFromDownloads(body_json()));
}

if ($path === '/admin/downloads/cancel' && $method === 'POST') {
    $b = body_json();
    $kind = $b['kind'] ?? '';
    if ($kind === 'local' || $kind === 'transmission' || $kind === 'queue') {
        json_out(Acquisition::removeFromDownloads($b));
    }
    if ($kind === 'episode') {
        json_out(Acquisition::cancelEpisodeSearch(
            (string)($b['show'] ?? ''),
            (int)($b['season'] ?? 0),
            (int)($b['episode'] ?? 0)
        ));
    }
    json_error('kind must be local|transmission|episode');
}




if ($path === '/admin/acquisition' && $method === 'GET') {
    json_out(Acquisition::state());
}

if ($path === '/admin/acquisition/feed-catalogue' && $method === 'GET') {
    json_out(['ok' => true, 'feeds' => FeedCatalogue::builtins()]);
}

if ($path === '/admin/acquisition/fetch' && $method === 'POST') {
    json_out(Acquisition::fetchFeeds());
}

if ($path === '/admin/acquisition/enqueue' && $method === 'POST') {
    json_out(Acquisition::enqueue(body_json()));
}
