<?php
declare(strict_types=1);
/**
 * Ingest inbox: finished torrents wait for the admin to choose TV / Movie / Anime,
 * pick the online match (TVMaze / OMDb / Wikipedia), review per-file mapping, then move
 * into the right library layout and catalogue (with overrides + show pin).
 *
 * data/ingest_inbox.json: { items: { <hash>: {name, status: awaiting|dismissed|imported, first_seen, ...} } }
 * settings.acquisition.ingest_mode: ask_unlinked (default) | ask_all | auto
 */
class Ingest {
    const STORE = 'ingest_inbox';

    public static function mode(array $acq = null): string {
        if ($acq === null) $acq = Store::settings()['acquisition'] ?? [];
        $m = (string)($acq['ingest_mode'] ?? 'ask_unlinked');
        return in_array($m, ['ask_unlinked', 'ask_all', 'auto'], true) ? $m : 'ask_unlinked';
    }

    private static function store(): array {
        $s = Store::get(self::STORE, null);
        if (!is_array($s)) $s = [];
        if (!isset($s['items']) || !is_array($s['items'])) $s['items'] = [];
        return $s;
    }

    private static function save(array $s): void {
        if (count($s['items']) > 300) $s['items'] = array_slice($s['items'], -250, null, true);
        Store::set(self::STORE, $s);
        @unlink(STREAM_SHM . '/ingest_count.json');
    }

    private static function hashOf(array $tor): string {
        $h = strtolower((string)($tor['hashString'] ?? ''));
        return $h !== '' ? $h : ('id' . (int)($tor['id'] ?? 0));
    }

    /** Called from the auto-import loop. */
    public static function needsReview(array $tor, ?array $entry, array $acq): bool {
        $mode = self::mode($acq);
        if ($mode === 'auto') return false;
        $st = self::store()['items'][self::hashOf($tor)] ?? null;
        if (is_array($st) && ($st['status'] ?? '') === 'auto_ok') return false;
        if ($mode === 'ask_all') return true;
        // ask_unlinked: exact episode requests from a show page import automatically
        if (is_array($entry) && !empty($entry['show'])
            && isset($entry['season']) && $entry['season'] !== null && $entry['season'] !== ''
            && isset($entry['episode']) && $entry['episode'] !== null && $entry['episode'] !== '') {
            return false;
        }
        return true;
    }

    public static function noteAwaiting(array $tor, ?array $entry): void {
        $s = self::store();
        $h = self::hashOf($tor);
        if (isset($s['items'][$h])) return;
        $s['items'][$h] = ['name' => (string)($tor['name'] ?? ''), 'status' => 'awaiting', 'first_seen' => date('c')];
        self::save($s);
    }

    /* ---------------------------------------------------------------- guesses */

    private static function seasonFromName(string $name): ?int {
        if (preg_match('/\b(?:Season|Series|Saison|Staffel)[\s._-]*0*(\d{1,2})\b/i', $name, $m)) return (int)$m[1];
        if (preg_match('/(?<![A-Za-z0-9])S0*(\d{1,2})(?![0-9])(?!\s*E\d)(?![Ee]\d)/', $name, $m)) return (int)$m[1];
        return null;
    }

    /** Clean show / movie title guess from a torrent name. */
    public static function titleGuess(string $name): array {
        $n = (string)preg_replace('/^\s*(?:www\.)?[a-z0-9-]+\.(?:org|com|net|to|se|me|cc|io|ws)\s*[-\x{2013}]+\s*/iu', '', $name);
        $n = (string)preg_replace('/^\s*\[[^\]]*\]\s*/', '', $n);
        $series = $n;
        $series = (string)preg_replace('/[\s._-]*\b(?:Season|Series|Saison|Staffel)[\s._-]*\d{1,2}\b.*$/i', '', $series);
        $series = (string)preg_replace('/[\s._-]*(?<![A-Za-z0-9])S\d{1,2}(?:[\s._-]?E\d{1,4})?(?![0-9]).*$/i', '', $series);
        $series = (string)preg_replace('/[\s._-]+-\s*\d{1,4}(?:v\d)?\b.*$/', '', $series);
        $mv = Acquisition::cleanReleaseTitle($n);
        $sv = Acquisition::cleanReleaseTitle($series);
        $mv['title'] = trim((string)preg_replace('/\s+-[A-Za-z0-9]{2,15}$/', '', (string)$mv['title']));
        $sv['title'] = trim((string)preg_replace('/\s+-[A-Za-z0-9]{2,15}$/', '', (string)$sv['title']));
        return [
            'movie' => ['title' => (string)$mv['title'], 'year' => $mv['year']],
            'series' => ['title' => (string)$sv['title'], 'year' => $sv['year']],
        ];
    }

    private static function guessKind(array $tor, array $files, ?array $entry): string {
        $name = (string)($tor['name'] ?? '');
        $ek = strtolower((string)($entry['kind'] ?? ''));
        $series = false;
        foreach ($files as $f) {
            $c = Catalogue::episodeCodeFromName((string)preg_replace('/\.[^.]+$/', '', $f['name']));
            if ($c['explicit'] || in_array($c['method'], ['dash', 'ep'], true)) { $series = true; break; }
        }
        if (!$series && self::seasonFromName($name) !== null) $series = true;
        if (!$series && count($files) >= 3 && preg_match('/\b(?:complete|episodes?)\b/i', $name)) $series = true;
        $animeHint = $ek === 'anime' || preg_match('/^\s*\[[^\]]+\]/', $name) || stripos((string)($entry['url'] ?? ''), 'nyaa') !== false;
        if ($series) {
            if ($animeHint) return 'anime';
            $g = self::titleGuess($name);
            if (self::existingShowFolder('anime', $g['series']['title'])) return 'anime';
            return 'tv';
        }
        if ($ek === 'anime') return 'anime';
        if (in_array($ek, ['tv', 'episode_hit', 'episode_search'], true)) return 'tv';
        return 'movie';
    }

    public static function sectionsByKind(): array {
        $out = ['tv' => [], 'anime' => [], 'movie' => []];
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            if (empty($sec['enabled']) || empty($sec['id'])) continue;
            $t = (string)($sec['type'] ?? '');
            $row = ['id' => (string)$sec['id'], 'label' => (string)($sec['label'] ?? $sec['id']), 'type' => $t, 'path' => (string)($sec['path'] ?? '')];
            if ($t === 'tv') { $out['tv'][] = $row; $out['anime'][] = $row; }
            elseif ($t === 'anime') { $out['anime'][] = $row; $out['tv'][] = $row; }
            elseif ($t === 'movies') $out['movie'][] = $row;
        }
        // preferred first
        usort($out['anime'], function ($a, $b) { return ($a['type'] === 'anime' ? 0 : 1) <=> ($b['type'] === 'anime' ? 0 : 1); });
        usort($out['tv'], function ($a, $b) { return ($a['type'] === 'tv' ? 0 : 1) <=> ($b['type'] === 'tv' ? 0 : 1); });
        usort($out['movie'], function ($a, $b) { return ($a['id'] === 'movies' ? 0 : 1) <=> ($b['id'] === 'movies' ? 0 : 1); });
        return $out;
    }

    private static function sectionRow(string $id): ?array {
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            if (($sec['id'] ?? '') === $id && !empty($sec['enabled'])) return $sec;
        }
        return null;
    }

    private static function loose(string $s): string {
        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $s));
    }

    /** Existing show folder in a section whose name matches $title (year suffix ignored). */
    public static function existingShowFolder(string $sectionId, string $title): ?string {
        $sec = self::sectionRow($sectionId);
        if (!$sec || $title === '') return null;
        $root = (string)($sec['path'] ?? '');
        if ($root === '' || !is_dir($root)) return null;
        $want = self::loose((string)preg_replace('/\s*\(\d{4}\)\s*$/', '', $title));
        if (strlen($want) < 2) return null;
        $dh = @opendir($root);
        if (!$dh) return null;
        $found = null;
        while (($n = readdir($dh)) !== false) {
            if ($n === '.' || $n === '..' || !is_dir($root . '/' . $n)) continue;
            if (self::loose((string)preg_replace('/\s*\(\d{4}\)\s*$/', '', $n)) === $want) { $found = $n; break; }
        }
        closedir($dh);
        return $found;
    }

    /* ------------------------------------------------------------------ inbox */

    public static function inbox(bool $includeDismissed = false): array {
        $acq = Store::settings()['acquisition'] ?? [];
        $tr = $acq['transmission'] ?? [];
        $out = ['ok' => true, 'mode' => self::mode($acq), 'items' => [], 'sections' => self::sectionsByKind(), 'transmission' => true];
        if (empty($tr['enabled']) || !Acquisition::transmissionReachable($tr)) {
            $out['transmission'] = false;
            $out['error'] = 'Transmission RPC unreachable';
            $out['pending'] = 0;
            @file_put_contents(STREAM_SHM . '/ingest_count.json', json_encode(['n' => 0, 'at' => time()]));
            return $out;
        }
        $st = self::store();
        $raw = Acquisition::torrentsForIngest();
        $pending = 0;
        foreach ($raw as $tor) {
            if (!is_array($tor) || (float)($tor['percentDone'] ?? 0) < 0.999) continue;
            $h = self::hashOf($tor);
            $rec = Acquisition::ingestImportRecord($tor);
            $files = Acquisition::ingestVideoFiles($tor);
            $imported = is_array($rec) && ($rec['status'] ?? '') === 'imported';
            if (!$files) continue;
            $state = $st['items'][$h] ?? [];
            $stateStatus = (string)($state['status'] ?? '');
            $dismissed = $stateStatus === 'dismissed';
            /* imported/auto_ok are resolved — do not keep the red badge (keep-torrent
               still leaves video files in Transmission, which previously kept pending++ forever). */
            $resolved = $dismissed || $stateStatus === 'imported' || $stateStatus === 'auto_ok' || $imported;
            if ($resolved && !$includeDismissed) continue;
            $entry = Acquisition::ingestQueueEntry($tor);
            $kind = self::guessKind($tor, $files, $entry);
            $g = self::titleGuess((string)($tor['name'] ?? ''));
            $query = $kind === 'movie' ? $g['movie']['title'] : $g['series']['title'];
            if (is_array($entry) && !empty($entry['show']) && $kind !== 'movie') $query = (string)$entry['show'];
            $fileRows = [];
            $total = 0;
            foreach ($files as $f) {
                $base = (string)preg_replace('/\.[^.]+$/', '', $f['name']);
                $c = Catalogue::episodeCodeFromName($base);
                $total += (int)$f['size'];
                $fileRows[] = ['path' => $f['path'], 'rel' => $f['rel'], 'name' => $f['name'], 'ext' => $f['ext'], 'size' => $f['size'],
                    'season' => $c['season'], 'episode' => $c['episode'], 'code_method' => $c['method']];
            }
            if (!$resolved) $pending++;
            $uiStatus = $dismissed ? 'dismissed' : ($imported || $stateStatus === 'imported' ? 'partial' : 'new');
            $out['items'][] = [
                'hash' => $h,
                'torrent_id' => (int)($tor['id'] ?? 0),
                'name' => (string)($tor['name'] ?? ''),
                'size' => $total,
                'status' => $uiStatus,
                'first_seen' => $state['first_seen'] ?? null,
                'guess_kind' => $kind,
                'guess_query' => $query,
                'guess_year' => $kind === 'movie' ? $g['movie']['year'] : $g['series']['year'],
                'season_hint' => self::seasonFromName((string)($tor['name'] ?? '')),
                'queue' => is_array($entry) ? ['kind' => $entry['kind'] ?? null, 'show' => $entry['show'] ?? null, 'season' => $entry['season'] ?? null, 'episode' => $entry['episode'] ?? null, 'query' => $entry['query'] ?? null, 'section_id' => $entry['section_id'] ?? null] : null,
                'files' => $fileRows,
                'imported_before' => $imported ? count($rec['dests'] ?? []) : 0,
            ];
        }
        $out['pending'] = $pending;
        @file_put_contents(STREAM_SHM . '/ingest_count.json', json_encode(['n' => $pending, 'at' => time()]));
        return $out;
    }

    /** Pending count (60s cache) for badges. */
    public static function pendingCount(): int {
        $f = STREAM_SHM . '/ingest_count.json';
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        if (is_array($j) && (time() - (int)($j['at'] ?? 0)) < 60) return (int)($j['n'] ?? 0);
        try { $r = self::inbox(false); return (int)($r['pending'] ?? 0); } catch (Throwable $e) { return 0; }
    }

    private static function findTorrent(string $hash): ?array {
        foreach (Acquisition::torrentsForIngest() as $tor) {
            if (is_array($tor) && self::hashOf($tor) === $hash) return $tor;
        }
        return null;
    }

    /* ----------------------------------------------------------------- search */

    public static function search(string $kind, string $q, ?string $source = null): array {
        $q = trim($q);
        if ($q === '') return ['ok' => false, 'error' => 'query required'];
        if ($source !== null && $source !== '' && $source !== 'all') return PublicMetadata::search($kind === 'movie' ? 'movie' : $kind, $q, $source);
        if ($kind === 'tv' || $kind === 'anime') {
            $rows = PublicMetadata::search($kind, $q)['results'];
            return ['ok' => true, 'kind' => $kind, 'query' => $q, 'results' => $rows];
        }
        // "Initial D 2005" -> search "Initial D", rank the 2005 hit first (OMDb s= does not match a trailing year)
        $year = null; $title = $q;
        if (preg_match('/^(.*?)[\s(\[]*((?:19|20)\d{2})[)\]]?\s*$/', $q, $m) && trim($m[1]) !== '') { $title = trim($m[1]); $year = (int)$m[2]; }
        $r = MediaManager::searchMetadata(['query' => $title, 'kind' => 'movie']);
        $rows = $r['results'] ?? [];
        if (!$rows && $title !== $q) { $r = MediaManager::searchMetadata(['query' => $q, 'kind' => 'movie']); $rows = $r['results'] ?? []; }
        if ($year && $rows) {
            foreach ($rows as $i => &$row) { $row['_o'] = $i; } unset($row);
            usort($rows, function ($a, $b) use ($year) {
                $da = isset($a['year']) && $a['year'] ? abs((int)$a['year'] - $year) : 99;
                $db = isset($b['year']) && $b['year'] ? abs((int)$b['year'] - $year) : 99;
                return $da === $db ? ($a['_o'] <=> $b['_o']) : ($da <=> $db);
            });
            foreach ($rows as &$row) { unset($row['_o']); } unset($row);
        }
        return ['ok' => !empty($r['ok']), 'kind' => 'movie', 'query' => $q, 'results' => $rows, 'error' => $r['error'] ?? null];
    }

    /* ------------------------------------------------------------------- plan */

    /**
     * body: hash, kind (tv|anime|movie), match {source,id,title,year}, section_id?, season_hint?
     */
    public static function plan(array $b): array {
        $hash = (string)($b['hash'] ?? '');
        $kind = (string)($b['kind'] ?? '');
        if (!in_array($kind, ['tv', 'anime', 'movie'], true)) return ['ok' => false, 'error' => 'Choose TV, Movie or Anime'];
        $tor = self::findTorrent($hash);
        if (!$tor) return ['ok' => false, 'error' => 'Torrent not found (removed from Transmission?)'];
        $files = Acquisition::ingestVideoFiles($tor);
        if (!$files) return ['ok' => false, 'error' => 'No video files left to import'];
        $match = is_array($b['match'] ?? null) ? $b['match'] : [];
        $secs = self::sectionsByKind()[$kind];
        $sectionId = (string)($b['section_id'] ?? '');
        $valid = array_column($secs, 'id');
        $title = trim((string)($match['title'] ?? ''));
        if ($title === '') return ['ok' => false, 'error' => 'Pick an online match (or type a title)'];
        $year = isset($match['year']) && $match['year'] !== '' && $match['year'] !== null ? (int)$match['year'] : null;
        $rows = [];
        if ($kind === 'movie') {
            if ($sectionId === '' || !in_array($sectionId, $valid, true)) $sectionId = $valid[0] ?? '';
            if ($sectionId === '') return ['ok' => false, 'error' => 'No movie library configured'];
            $single = count($files) === 1;
            foreach ($files as $i => $f) {
                if ($single) { $t = $title; $y = $year; $method = 'match'; }
                else {
                    $c = Acquisition::cleanReleaseTitle($f['name']);
                    $t = (string)$c['title']; $y = $c['year']; $method = 'filename';
                    if ($i === 0 && self::loose($t) === self::loose($title)) { $y = $year ?: $y; $method = 'match'; }
                }
                $rows[] = self::rowDest(['path' => $f['path'], 'name' => $f['name'], 'size' => $f['size'], 'title' => $t, 'year' => $y, 'method' => $method], $sectionId, 'movie');
            }
            return ['ok' => true, 'hash' => $hash, 'kind' => $kind, 'section_id' => $sectionId, 'sections' => $secs, 'match' => $match, 'files' => $rows];
        }
        // Series
        $existingSec = null;
        foreach ($secs as $s) { if (self::existingShowFolder($s['id'], $title)) { $existingSec = $s['id']; break; } }
        if ($sectionId === '' || !in_array($sectionId, $valid, true)) $sectionId = $existingSec ?: ($valid[0] ?? '');
        if ($sectionId === '') return ['ok' => false, 'error' => 'No TV/anime library configured'];
        $folder = self::existingShowFolder($sectionId, $title);
        $showFolder = $folder ?: $title;
        $tvmazeId = ($match['source'] ?? '') === 'tvmaze' ? (int)($match['id'] ?? 0) : 0;
        $guide = $tvmazeId > 0 ? Matching::tvmazeGuideById($tvmazeId) : null;
        $regular = $guide ? Matching::regularEpisodes($guide) : [];
        $seasonHint = isset($b['season_hint']) && $b['season_hint'] !== '' && $b['season_hint'] !== null ? (int)$b['season_hint'] : self::seasonFromName((string)($tor['name'] ?? ''));
        $taken = [];
        foreach ($files as $f) {
            $base = (string)preg_replace('/\.[^.]+$/', '', $f['name']);
            $c = Catalogue::episodeCodeFromName($base, $seasonHint !== null || preg_match('#(?:^|/)(?:Season|Series|S)[\s._-]*\d{1,2}/#i', $f['rel']) === 1);
            $s = null; $e = null; $name = null; $method = 'needs_input'; $conf = 0;
            if ($c['explicit']) {
                $s = (int)$c['season']; $e = (int)$c['episode']; $method = 'filename'; $conf = 98;
            } elseif ($c['episode'] !== null) {
                $e = (int)$c['episode'];
                if ($seasonHint !== null) { $s = $seasonHint; $method = 'season+number'; $conf = 85; }
                elseif ($regular && $e >= 1 && $e <= count($regular)) {
                    $g = $regular[$e - 1]; $s = (int)$g['season']; $e = (int)$g['number']; $method = 'absolute'; $conf = 70;
                } else { $s = 1; $method = 'number'; $conf = 60; }
            }
            if ($s === null && $guide) {
                $clean = Catalogue::cleanFileTitle($base, $title);
                $bm = Matching::bestTitleMatch($clean, $guide);
                if ($bm && $bm['episode']['number'] !== null) {
                    $s = (int)$bm['episode']['season']; $e = (int)$bm['episode']['number']; $name = $bm['episode']['name']; $method = 'title'; $conf = (int)round($bm['score']);
                }
            }
            if ($s !== null && $e !== null && $guide && $name === null) {
                $g = Matching::guideLookup($guide, $s, $e);
                if ($g) $name = $g['name'] ?? null;
            }
            if ($name === null) {
                $pn = Catalogue::parseEpisodeNameFromRel($f['name'], $title, '');
                if ($pn !== '') $name = $pn;
            }
            if ($s !== null && $e !== null) {
                $k = $s . 'x' . $e;
                if (isset($taken[$k])) { $method = 'duplicate'; $conf = 0; }
                $taken[$k] = true;
            }
            $rows[] = self::rowDest(['path' => $f['path'], 'name' => $f['name'], 'size' => $f['size'], 'season' => $s, 'episode' => $e, 'episode_title' => $name, 'method' => $method, 'confidence' => $conf, 'show' => $showFolder], $sectionId, $kind);
        }
        return [
            'ok' => true, 'hash' => $hash, 'kind' => $kind, 'section_id' => $sectionId, 'sections' => $secs, 'match' => $match,
            'show_folder' => $showFolder, 'existing_folder' => $folder !== null, 'season_hint' => $seasonHint,
            'guide' => Matching::guideSummary($guide), 'files' => $rows,
        ];
    }

    private static function rowDest(array $r, string $sectionId, string $kind): array {
        $body = ['path' => $r['path'], 'section_id' => $sectionId];
        if ($kind === 'movie') {
            $body['title'] = $r['title'] ?? ''; $body['year'] = $r['year'] ?? null;
        } else {
            $body['show'] = $r['show'] ?? ''; $body['season'] = $r['season']; $body['episode'] = $r['episode']; $body['episode_title'] = $r['episode_title'] ?? '';
        }
        $ok = $kind === 'movie' ? (trim((string)($r['title'] ?? '')) !== '') : ($r['season'] !== null && $r['episode'] !== null);
        if ($ok) {
            $pv = Library::preview($body);
            $r['dest'] = $pv['dest'] ?? null;
            $r['rel_dest'] = $pv['rel'] ?? null;
            $r['conflict'] = !empty($pv['conflict']);
        } else {
            $r['dest'] = null; $r['conflict'] = false;
        }
        $r['ready'] = $ok;
        return $r;
    }

    /* ----------------------------------------------------------------- commit */

    /**
     * body: hash, kind, section_id, show (folder/display), tvmaze_id?, keep_torrent?, files:[{path, skip?, season, episode, episode_title | title, year}]
     */
    public static function commit(array $b, ?callable $progress = null): array {
        @set_time_limit(0);
        $hash = (string)($b['hash'] ?? '');
        $kind = (string)($b['kind'] ?? '');
        if (!in_array($kind, ['tv', 'anime', 'movie'], true)) return ['ok' => false, 'error' => 'kind required'];
        // Files a previous (interrupted or retried) run already moved: source path -> library path
        $doneBefore = self::store()['items'][$hash]['done'] ?? [];
        if (!is_array($doneBefore)) $doneBefore = [];
        $tor = self::findTorrent($hash);
        if (!$tor) {
            $prior = array_filter($doneBefore, function ($d) { return is_string($d) && is_file($d); });
            if (!$prior) return ['ok' => false, 'error' => 'Torrent not found'];
            $results = [];
            foreach ($prior as $src => $d) $results[] = ['path' => $src, 'ok' => true, 'already' => true, 'dest' => $d];
            return ['ok' => true, 'imported' => 0, 'already' => count($results), 'errors' => [], 'results' => $results, 'finish' => null, 'error' => null];
        }
        $sectionId = (string)($b['section_id'] ?? '');
        if (!in_array($sectionId, array_column(self::sectionsByKind()[$kind], 'id'), true)) return ['ok' => false, 'error' => 'Library does not fit the chosen type'];
        $allowed = [];
        foreach (Acquisition::ingestVideoFiles($tor) as $f) $allowed[$f['path']] = $f;
        $show = Catalogue::fixText((string)($b['show'] ?? ''));
        if ($kind !== 'movie' && $show === '') return ['ok' => false, 'error' => 'Show name required'];
        $dests = []; $results = []; $overrideRows = []; $showKeys = [];
        $stopped = false;
        $moved = [];      // [dest, sectionId] for the single catalogue write
        $pending = [];    // dest => override row, filled in after the catalogue write
        $fileList = (array)($b['files'] ?? []);
        $total = count(array_filter($fileList, function ($f) { return is_array($f) && empty($f['skip']); }));
        $n = 0;
        $tick = function ($name) use (&$n, $total, $progress) { if ($progress) $progress($n, $total, $name); };
        $tick('');
        foreach ($fileList as $f) {
            $path = (string)($f['path'] ?? '');
            if (empty($f['skip']) && isset($doneBefore[$path]) && is_string($doneBefore[$path]) && is_file($doneBefore[$path])) {
                $dests[] = $doneBefore[$path];
                $results[] = ['path' => $path, 'ok' => true, 'already' => true, 'dest' => $doneBefore[$path]];
                $n++; $tick(basename($path));
                continue;
            }
            if (!isset($allowed[$path])) { $results[] = ['path' => $path, 'ok' => false, 'error' => 'Not a file of this torrent']; if (empty($f['skip'])) { $n++; $tick(basename($path)); } continue; }
            if (!empty($f['skip'])) { $results[] = ['path' => $path, 'ok' => true, 'skipped' => true]; continue; }
            $body = ['path' => $path, 'section_id' => $sectionId];
            if ($kind === 'movie') {
                $t = trim((string)($f['title'] ?? ''));
                if ($t === '') { $results[] = ['path' => $path, 'ok' => false, 'error' => 'Title missing']; continue; }
                $body['title'] = $t; $body['year'] = isset($f['year']) && $f['year'] !== '' && $f['year'] !== null ? (int)$f['year'] : null;
            } else {
                if (!isset($f['season']) || $f['season'] === '' || $f['season'] === null || !isset($f['episode']) || $f['episode'] === '' || $f['episode'] === null) {
                    $results[] = ['path' => $path, 'ok' => false, 'error' => 'Season/episode missing']; continue;
                }
                $body['show'] = $show; $body['title'] = $show;
                $body['season'] = (int)$f['season']; $body['episode'] = (int)$f['episode'];
                $body['episode_title'] = trim((string)($f['episode_title'] ?? ''));
            }
            $n++; $tick(basename($path));
            $pv = Library::preview($body);
            if (empty($pv['ok'])) { $results[] = ['path' => $path, 'ok' => false, 'error' => $pv['error'] ?? 'preview failed']; continue; }
            if (!empty($pv['conflict'])) {
                $ss = @filesize($path) ?: 0; $ds = @filesize($pv['dest']) ?: 0;
                if ($ss > 0 && $ds > 0 && abs($ss - $ds) <= max(65536, (int)($ss * 0.02))) {
                    $dests[] = $pv['dest'];
                    $results[] = ['path' => $path, 'ok' => true, 'duplicate' => true, 'dest' => $pv['dest']];
                    continue;
                }
                $results[] = ['path' => $path, 'ok' => false, 'error' => 'A different file already exists at ' . $pv['rel']]; continue;
            }
            if (!$stopped) { Acquisition::stopTorrent($tor); $stopped = true; }
            $ap = Library::apply($body + ['defer_catalogue' => true]);
            if (empty($ap['ok'])) { $results[] = ['path' => $path, 'ok' => false, 'error' => $ap['error'] ?? 'move failed']; continue; }
            $dest = (string)$ap['dest'];
            $dests[] = $dest;
            $moved[] = [$dest, $sectionId];
            $results[] = ['path' => $path, 'ok' => true, 'dest' => $dest];
            $pending[$dest] = $kind === 'movie'
                ? ['title' => $body['title'], 'year' => $body['year'], 'imdb_id' => ($b['match']['source'] ?? '') === 'omdb' ? (string)($b['match']['id'] ?? '') : null]
                : ['season' => $body['season'], 'episode' => $body['episode'], 'episode_title' => $body['episode_title'] !== '' ? $body['episode_title'] : null];
            // Remember the move at once so a retry after an interruption reports it as done
            $st = self::store();
            $st['items'][$hash]['done'][$path] = $dest;
            self::save($st);
        }
        // One catalogue write for the whole batch, then overrides/artwork
        if ($moved) {
            $items = Catalogue::ingestFiles($moved);
            foreach ($items as $i => $it) {
                if (!$it) continue;
                $row = ['id' => (string)$it['id']] + $pending[$moved[$i][0]];
                $overrideRows[] = $row;
                if ($kind !== 'movie' && !empty($it['show'])) $showKeys[Catalogue::showKey($sectionId, (string)$it['show'])] = true;
                foreach ($results as &$res) { if (($res['dest'] ?? '') === $moved[$i][0]) $res['item_id'] = $it['id']; }
                unset($res);
            }
        }
        if ($overrideRows) Matching::saveItemFields($overrideRows, 'ingest', Matching::actor());
        $tvmazeId = (int)($b['tvmaze_id'] ?? 0);
        if ($tvmazeId > 0) {
            foreach (array_keys($showKeys) as $sk) Matching::pinShow($sk, ['provider' => 'tvmaze', 'tvmaze_id' => $tvmazeId, 'name' => $show]);
        }
        if ($kind === 'movie' && count($overrideRows) === 1 && !empty($b['match']) && class_exists('Posters')) {
            Matching::rematchMovie($overrideRows[0]['id'], ['title' => $overrideRows[0]['title'], 'year' => $overrideRows[0]['year'], 'image' => (string)($b['match']['image'] ?? ''), 'match'=>$b['match']]);
        }
        $fin = null;
        if ($dests) {
            $tor2 = self::findTorrent($hash) ?: $tor;
            $fin = Acquisition::finishIngest($tor2, $dests, empty($b['keep_torrent']));
            $s = self::store();
            $s['items'][$hash] = array_merge($s['items'][$hash] ?? [], ['name' => (string)($tor['name'] ?? ''), 'status' => 'imported', 'imported_at' => date('c'), 'kind' => $kind, 'section_id' => $sectionId, 'count' => count($dests)]);
            self::save($s);
        }
        // Import done: dispatch the next queued download right away instead of waiting for the schedule
        $next = null;
        if ($dests) { try { $next = Acquisition::processPendingSearches(['limit' => 1]); } catch (Throwable $e) { $next = null; } }
        if ($progress) $progress($total, $total, '');
        $backup = $dests ? MetadataSidecars::schedule() : ['ok'=>true];
        $errors = array_values(array_filter($results, function ($r) { return empty($r['ok']); }));
        Matching::log('INGEST ' . ($tor['name'] ?? '') . ' kind=' . $kind . ' section=' . $sectionId . ' ok=' . count($dests) . ' errors=' . count($errors));
        return ['ok' => count($errors) === 0, 'imported' => count($dests), 'errors' => $errors, 'results' => $results, 'finish' => $fin, 'next' => $next, 'warning'=>empty($backup['ok']) ? $backup['warning'] : null, 'error' => $errors ? ($errors[0]['error'] ?? 'Some files failed') : null];
    }

    public static function setStatus(string $hash, string $status): array {
        if (!in_array($status, ['dismissed', 'awaiting', 'auto_ok'], true)) return ['ok' => false, 'error' => 'bad status'];
        $s = self::store();
        $s['items'][$hash] = array_merge($s['items'][$hash] ?? [], ['status' => $status, 'at' => date('c')]);
        self::save($s);
        return ['ok' => true];
    }
}
