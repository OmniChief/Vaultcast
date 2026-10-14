<?php
declare(strict_types=1);
// Clean-URL resolver: /movies/<slug>, /tvshows/<slug>[/season-<n>[/episode-<m>|/<itemId>]]
if ($path === '/resolve' && $method === 'GET') {
    json_out(UrlRoutes::resolve((string)($_GET['path'] ?? ''), function (string $kind, string $slug): ?string {
        $id = Slugs::resolve($kind, $slug);
        if ($id !== null) return $id;
        if ($kind === 'movie' && preg_match('/^[a-f0-9]{8,64}$/', $slug) && Catalogue::item($slug)) return $slug;
        if ($kind === 'show' && preg_match('/^[a-f0-9]{8,64}$/', $slug)) return $slug;
        return null;
    }));
}
if ($path === '/home' && $method === 'GET') {
    json_out(Catalogue::home(current_or_guest()));
}

if ($path === '/search' && $method === 'GET') {
    $q = trim($_GET['q'] ?? '');
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 40)));
    $out = Catalogue::search($q, $limit);
    // Inline light filters ÔÇö avoid loading Acquisition.php on every search
    $acq = Store::settings()['acquisition'] ?? [];
    $qualities = $acq['preferred_qualities'] ?? ['1080p', '720p'];
    if (!is_array($qualities)) $qualities = ['1080p', '720p'];
    $langs = $acq['language_filters'] ?? [];
    if (!is_array($langs)) $langs = [];
    $filters = [
        'min_seeds' => (int)($acq['min_seeds'] ?? 1),
        'preferred_qualities' => array_values(array_filter(array_map('strval', $qualities))),
        'language_filters' => array_values(array_filter(array_map('strval', $langs))),
        'rss_fallback_search' => !empty($acq['rss_fallback_search']),
        'min_interval_seconds' => (int)($acq['min_interval_seconds'] ?? 120),
        'max_per_hour' => (int)($acq['max_per_hour'] ?? 20),
    ];
    $out['rss_fallback_enabled'] = !empty($filters['rss_fallback_search']);
    $viewer = current_or_guest();
    if (($viewer['role'] ?? '') !== 'admin') { $out['rss_fallback_enabled'] = false; $filters = []; }
    $out['filters'] = $filters;
    $out['version'] = STREAM_VERSION;
    json_out($out);
}

if ($path === '/search/rss' && $method === 'POST') {
    $b = body_json();
    $q = trim((string)($b['query'] ?? $b['q'] ?? ''));
    if ($q === '') json_error('query required');
    $kind = trim((string)($b['kind'] ?? 'auto'));
    json_out(Acquisition::searchRssFallback($q, ['kind' => $kind, 'force' => !empty($b['force'])]));
}

if ($path === '/search/get' && $method === 'POST') {
    $b = body_json();
    $title = trim((string)($b['title'] ?? 'Search get'));
    $magnet = trim((string)($b['magnet'] ?? ''));
    $link = trim((string)($b['link'] ?? ''));
    $enclosure = trim((string)($b['enclosure'] ?? ''));
    if ($magnet === '' && $link === '' && $enclosure === '') json_error('magnet or link required');
    json_out(Acquisition::enqueue([
        'title' => $title,
        'magnet' => $magnet,
        'link' => $link,
        'enclosure' => $enclosure,
        'query' => $b['query'] ?? null,
        'show' => $b['show'] ?? null,
        'season' => isset($b['season']) ? (int)$b['season'] : null,
        'episode' => isset($b['episode']) ? (int)$b['episode'] : null,
        'kind' => $b['kind'] ?? 'search_get',
        'section_id' => $b['section_id'] ?? null,
    ]));
}

if ($path === '/sections' && $method === 'GET') {
    $cat = \Vaultcast\Repository::document('catalogue', ['sections'=>[]]);
    json_out(['ok' => true, 'sections' => array_map(function ($section) { unset($section['path']); return $section; }, $cat['sections'] ?? []), 'scanned_at' => $cat['scanned_at'] ?? null]);
}

if (preg_match('#^/sections/([a-zA-Z0-9_-]+)/items$#', $path, $m) && $method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(100, max(1, (int)($_GET['per'] ?? 48)));
    $q = trim($_GET['q'] ?? '');
    $letter = strtoupper(trim((string)($_GET['letter'] ?? '')));
    $system = trim((string)($_GET['system'] ?? ''));
    $genre = trim((string)($_GET['genre'] ?? ''));
    $year = trim((string)($_GET['year'] ?? ''));
    $decade = trim((string)($_GET['decade'] ?? ''));
    $actor = trim((string)($_GET['actor'] ?? ''));
    /* Cache browsing results to avoid rebuilding facets and synopses on each visit. */
    $browseSalt = $m[1] . '|p=' . $page . '|per=' . $per . '|q=' . $q . '|L=' . $letter
        . '|sys=' . $system . '|g=' . $genre . '|y=' . $year . '|d=' . $decade . '|a=' . $actor;
    $browseHit = Catalogue::browseCacheGet('browse_items', $browseSalt);
    if (is_array($browseHit) && !empty($browseHit['ok'])) {
        $browseHit['cached'] = true;
        json_out($browseHit);
    }
    $meta = \Vaultcast\Repository::document('catalogue', ['sections'=>[]]);
    $sectionType = '';
    foreach ($meta['sections'] as $section) if (($section['id'] ?? '') === $m[1]) $sectionType = $section['type'] ?? '';
    $plainMovieBrowse = in_array($sectionType, ['movies','disney'], true) && $genre === '' && $year === '' && $decade === '' && $actor === '';
    if (in_array($sectionType, ['roms','music'], true) || $plainMovieBrowse) {
        $out = \Vaultcast\Repository::browse($m[1], $q, $system, $letter, $page, $per);
        if ($plainMovieBrowse) {
            $pack = Catalogue::browseCacheGet('facets_movies', $m[1]);
            $out['facets'] = $pack['facets'] ?? Catalogue::movieFacetsCached($m[1], Catalogue::sectionItems($m[1]));
        }
        $out['items'] = array_map(function ($item) { return Catalogue::publicItem($item, false); }, $out['items']);
        Catalogue::browseCachePut('browse_items', $browseSalt, $out);
        json_out($out);
    }
    $items = Catalogue::sectionItems($m[1]);
    // Console / system tallies for Play (roms) filter chips — before q/letter filters
    $systems = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $sys = trim((string)($it['system'] ?? ''));
        if ($sys === '') continue;
        $systems[$sys] = ($systems[$sys] ?? 0) + 1;
    }
    if ($systems) {
        uksort($systems, 'strcasecmp');
    }
    // movie/Disney facets from full section pool (before active filters)
    $secMeta = null;
    foreach (($meta['sections'] ?? []) as $s) {
        if (($s['id'] ?? '') === $m[1]) { $secMeta = $s; break; }
    }
    $secType = (string)($secMeta['type'] ?? '');
    $isMovieSec = ($secType === 'movies' || $secType === 'disney' || $m[1] === 'movies' || $m[1] === 'disney');
    $facets = $isMovieSec ? Catalogue::movieFacetsCached($m[1], $items) : new stdClass();
    if ($system !== '') {
        $sl = strtolower($system);
        $items = array_values(array_filter($items, function ($it) use ($sl) {
            return strtolower(trim((string)($it['system'] ?? ''))) === $sl;
        }));
    }
    if ($isMovieSec) {
        $items = Catalogue::filterMovieItems($items, [
            'genre' => $genre,
            'year' => $year,
            'decade' => $decade,
            'actor' => $actor,
        ], $m[1]);
    }
    if ($q !== '') {
        $ql = strtolower($q);
        $items = array_values(array_filter($items, function ($it) use ($ql) {
            return stripos($it['title'] ?? '', $ql) !== false
                || stripos($it['show'] ?? '', $ql) !== false
                || stripos($it['rel'] ?? '', $ql) !== false
                || stripos($it['system'] ?? '', $ql) !== false;
        }));
    }
    usort($items, function ($a, $b) {
        return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
    });
    $letters = Catalogue::letterCounts($items);
    if ($letter !== '') {
        $items = Catalogue::filterByLetter($items, $letter);
    }
    $total = count($items);
    $slice = array_slice($items, ($page - 1) * $per, $per);
    $browseOut = [
        'ok' => true,
        'page' => $page,
        'per' => $per,
        'total' => $total,
        'has_more' => ($page * $per) < $total,
        'letter' => $letter !== '' ? $letter : null,
        'letters' => $letters,
        'system' => $system !== '' ? $system : null,
        'systems' => $systems,
        'facets' => $facets,
        'filters' => [
            'genre' => $genre !== '' ? $genre : null,
            'year' => $year !== '' ? $year : null,
            'decade' => $decade !== '' ? $decade : null,
            'actor' => $actor !== '' ? $actor : null,
        ],
        'items' => array_map(function ($it) { return Catalogue::publicItem($it, false); }, $slice),
        'cached' => false,
    ];
    Catalogue::browseCachePut('browse_items', $browseSalt, $browseOut);
    json_out($browseOut);
}

if (preg_match('#^/sections/([a-zA-Z0-9_-]+)/shows$#', $path, $m) && $method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(100, max(1, (int)($_GET['per'] ?? 48)));
    $q = trim($_GET['q'] ?? '');
    $letter = strtoupper(trim((string)($_GET['letter'] ?? '')));
    $genre = trim((string)($_GET['genre'] ?? ''));
    $year = trim((string)($_GET['year'] ?? ''));
    $decade = trim((string)($_GET['decade'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $network = trim((string)($_GET['network'] ?? ''));
    /* SHM browse cache for TV/Anime grids */
    $browseSalt = $m[1] . '|p=' . $page . '|per=' . $per . '|q=' . $q . '|L=' . $letter
        . '|g=' . $genre . '|y=' . $year . '|d=' . $decade . '|st=' . $status . '|net=' . $network;
    $browseHit = Catalogue::browseCacheGet('browse_shows', $browseSalt);
    if (is_array($browseHit) && !empty($browseHit['ok'])) {
        $browseHit['cached'] = true;
        json_out($browseHit);
    }
    $shows = Catalogue::sectionShows($m[1]);
    // TV/Anime facets from full show pool
    $facets = Catalogue::showFacetsCached($m[1], $shows);
    $shows = Catalogue::filterShowRows($shows, [
        'genre' => $genre,
        'year' => $year,
        'decade' => $decade,
        'status' => $status,
        'network' => $network,
    ]);
    if ($q !== '') {
        $ql = strtolower($q);
        $shows = array_values(array_filter($shows, function ($it) use ($ql) {
            return stripos($it['title'] ?? '', $ql) !== false;
        }));
    }
    $letters = Catalogue::letterCounts($shows);
    if ($letter !== '') {
        $shows = Catalogue::filterByLetter($shows, $letter);
    }
    $total = count($shows);
    $slice = array_slice($shows, ($page - 1) * $per, $per);
    $browseOut = [
        'ok' => true,
        'page' => $page,
        'per' => $per,
        'total' => $total,
        'has_more' => ($page * $per) < $total,
        'letter' => $letter !== '' ? $letter : null,
        'letters' => $letters,
        'facets' => $facets,
        'filters' => [
            'genre' => $genre !== '' ? $genre : null,
            'year' => $year !== '' ? $year : null,
            'decade' => $decade !== '' ? $decade : null,
            'status' => $status !== '' ? $status : null,
            'network' => $network !== '' ? $network : null,
        ],
        'shows' => array_map(['Catalogue','publicShow'], $slice),
        'cached' => false,
    ];
    Catalogue::browseCachePut('browse_shows', $browseSalt, $browseOut);
    json_out($browseOut);
}

if (preg_match('#^/shows/([a-f0-9]+)$#', $path, $m) && $method === 'GET') {
    $detail = Catalogue::show($m[1]);
    if (!$detail) json_error('Show not found', 404);
    json_out($detail);
}

if (preg_match('#^/shows/([a-f0-9]+)/poster$#', $path, $m) && ($method === 'GET' || $method === 'HEAD')) {
    Stream::showPoster($m[1]);
}

if (preg_match('#^/shows/([a-f0-9]+)/seasons/(\d+)/poster$#', $path, $m) && ($method === 'GET' || $method === 'HEAD')) {
    Stream::seasonPoster($m[1], (int)$m[2]);
}

if (preg_match('#^/sections/([a-zA-Z0-9_-]+)/random$#', $path, $m) && $method === 'GET') {
    $n = max(1, min(40, (int)($_GET['n'] ?? 16)));
    json_out(Catalogue::randomPublic($m[1], $n));
}

/* TV/Anime Recently Updated showcase (cached, no ffprobe on cards) */
if (preg_match('#^/sections/([a-zA-Z0-9_-]+)/recent$#', $path, $m) && $method === 'GET') {
    $n = max(1, min(40, (int)($_GET['n'] ?? 16)));
    json_out(Catalogue::recentlyUpdatedPublic($m[1], $n));
}

if (preg_match('#^/items/([a-f0-9]+)$#', $path, $m) && $method === 'GET') {
    $fetchSynopsis = !isset($_GET['synopsis']) || $_GET['synopsis'] !== '0';
    $detail = Catalogue::itemDetail($m[1], $fetchSynopsis);
    if (!$detail) json_error('Not found', 404);
    json_out($detail);
}

if (preg_match('#^/items/([a-f0-9]+)/synopsis$#', $path, $m) && $method === 'GET') {
    // May hit OMDb/Wikipedia/TVMaze; the movie page calls this after painting so the UI stays instant.
    $detail = Catalogue::itemSynopsisFetch($m[1]);
    if (!$detail) json_error('Not found', 404);
    json_out($detail);
}

if (preg_match('#^/items/([a-f0-9]+)/poster$#', $path, $m) && ($method === 'GET' || $method === 'HEAD')) {
    Stream::poster($m[1]);
}

if (preg_match('#^/items/([a-f0-9]+)/play$#', $path, $m) && $method === 'POST') {
    json_out(Stream::issueToken(current_or_guest(), $m[1], body_json()));
}

if (preg_match('#^/items/([a-f0-9]+)/probe$#', $path, $m) && $method === 'GET') {
    release_request_locks();
    $item = Catalogue::item($m[1]);
    if (!$item) json_error('Not found', 404);
    $pathFile = (string)($item['path'] ?? '');
    $probe = MediaProbe::probeSummary($pathFile, 3, true);
    $probe['item_id'] = $m[1];
    $probe['title'] = $item['title'] ?? '';
    json_out($probe);
}

if (preg_match('#^/items/([a-f0-9]+)/alternate$#', $path, $m) && $method === 'POST') {
    // Find alternate versions via existing RSS search + admin quality/language filters
    $item = Catalogue::item($m[1]);
    if (!$item) json_error('Not found', 404);
    $b = body_json();
    $title = trim((string)($b['query'] ?? $item['title'] ?? ''));
    if ($title === '') json_error('query required');
    $kind = trim((string)($b['kind'] ?? (($item['type'] ?? '') === 'anime' ? 'anime' : (($item['type'] ?? '') === 'tv' ? 'tv' : 'movie'))));
    $acq = Store::settings()['acquisition'] ?? [];
    $filters = Acquisition::searchFilters($acq);
    // Append preferred quality/language hints into query when useful
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
    $res['source_item'] = ['id' => $item['id'], 'title' => $item['title'] ?? '', 'path' => $item['path'] ?? ''];
    json_out($res);
}
