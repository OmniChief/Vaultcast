<?php
declare(strict_types=1);

/**
 * "Find collections": for films already in the library, look up the collection (franchise /
 * film series) each belongs to and create or extend collections. Sources:
 *   - TMDB (Settings → Artwork → TMDB API key): movie.belongs_to_collection, then /collection
 *     for the full part list, overview and poster.
 *   - Wikidata (no key): "part of the series" (P179) via SPARQL, used when no TMDB key is set.
 * Work runs in short steps (a few seconds each) driven by the admin page, with the job state
 * kept in the DB so it survives reloads and can resume. Per-film lookups are cached for 30 days.
 * Collections created here carry source_id (tmdb:collection:<id> / wikidata:<Q>) so re-runs
 * extend them instead of duplicating; manual collections (no source_id) are never modified.
 */
final class CollectionDiscovery {
    const DOC = 'collections_discover';
    const STEP_SECONDS = 8.0;
    const CACHE_TTL = 2592000;
    const FILM_TYPES = ['wd:Q11424', 'wd:Q29168811', 'wd:Q24862', 'wd:Q202866', 'wd:Q506240'];

    /** @var callable|null fn(string $url, array $headers): array [status, body, headers]  (tests) */
    public static $http = null;
    /** @var callable|null fn(float $seconds): void  (tests) */
    public static $sleep = null;
    /** @var array|null catalogue items override (tests) */
    public static $items = null;
    private static $lastRequest = [];

    public static function tmdbKey(): string {
        $p = Store::settings()['posters'] ?? [];
        return is_array($p) ? trim((string)($p['tmdb_api_key'] ?? '')) : '';
    }

    public static function source(): string { return self::tmdbKey() !== '' ? 'tmdb' : 'wikidata'; }

    public static function state(): array {
        $s = Store::get(self::DOC, null);
        return is_array($s) ? $s : ['status' => 'idle'];
    }

    private static function saveState(array $s): void { Store::set(self::DOC, $s); }

    /** Public progress view (no queue). */
    public static function progress(?array $s = null): array {
        $s = $s ?? self::state();
        $out = $s;
        unset($out['queue'], $out['series']);
        $out['ok'] = true;
        $out['source_label'] = ($s['source'] ?? self::source()) === 'tmdb' ? 'TMDB' : 'Wikidata';
        if (($s['source'] ?? self::source()) !== 'tmdb') {
            $out['hint'] = 'No TMDB API key is set, so Wikidata (free, less complete) is used. Add a TMDB API key in Settings → Artwork for better results and collection posters.';
        }
        return $out;
    }

    private static function catalogue(): array {
        if (self::$items !== null) return self::$items;
        $cat = Catalogue::get();
        return is_array($cat['items'] ?? null) ? $cat['items'] : [];
    }

    /** Library films (not episodes, ROMs or TV sections). */
    public static function films(): array {
        $out = [];
        foreach (self::catalogue() as $id => $it) {
            if (!is_array($it)) continue;
            $type = (string)($it['type'] ?? '');
            $kind = (string)($it['media_kind'] ?? 'video');
            if ($kind !== 'video' || !empty($it['show']) || in_array($type, ['tv', 'anime', 'roms', 'music'], true)) continue;
            if (trim((string)($it['title'] ?? '')) === '') continue;
            $out[(string)($it['id'] ?? $id)] = $it;
        }
        return $out;
    }

    public static function key(string $title): string {
        $t = strtolower(trim($title));
        if (function_exists('iconv')) { $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t); if (is_string($x) && $x !== '') $t = strtolower($x); }
        $t = str_replace('&', 'and', $t);
        return (string)preg_replace('/[^a-z0-9]+/', '', $t);
    }

    private static function partKey(array $p): string {
        return self::key((string)($p['title'] ?? '')) . '|' . (string)($p['year'] ?? '');
    }

    public static function start(): array {
        $linked = [];
        foreach (Collections::all() as $c) foreach ((array)($c['parts'] ?? []) as $p) if (!empty($p['item_id'])) $linked[(string)$p['item_id']] = true;
        $queue = [];
        foreach (self::films() as $id => $it) if (!isset($linked[$id])) $queue[] = $id;
        $s = [
            'status' => $queue ? 'running' : 'done', 'source' => self::source(), 'started_at' => date('c'),
            'updated_at' => date('c'), 'total' => count($queue), 'done' => 0, 'queue' => $queue, 'series' => [],
            'found' => 0, 'created' => 0, 'updated' => 0, 'skipped_manual' => 0, 'errors' => 0, 'last_error' => null,
            'message' => $queue ? 'Looking up ' . count($queue) . ' films…' : 'Every film is already in a collection.',
        ];
        self::saveState($s);
        return self::progress($s);
    }

    public static function cancel(): array {
        $s = self::state();
        if (($s['status'] ?? '') === 'running') { $s['status'] = 'cancelled'; $s['message'] = 'Stopped. Run again to resume where it left off.'; }
        self::saveState($s);
        return self::progress($s);
    }

    /** Process films for up to $budget seconds. Called repeatedly by the admin page. */
    public static function step(float $budget = self::STEP_SECONDS): array {
        $lock = @fopen(rtrim(sys_get_temp_dir(), '/\\') . '/vaultcast-coldisc.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => true, 'busy' => true] + self::progress();
        try {
            $s = self::state();
            if (($s['status'] ?? '') === 'cancelled' || ($s['status'] ?? '') === 'paused') $s['status'] = 'running'; // resume
            if (($s['status'] ?? '') !== 'running') return self::progress($s);
            $t0 = microtime(true);
            $films = self::films();
            while ($s['done'] < count($s['queue']) && microtime(true) - $t0 < $budget) {
                $id = (string)$s['queue'][$s['done']];
                if (isset($films[$id])) {
                    try {
                        $r = self::lookup($films[$id], $s);
                        if ($r === 'rate') { $s['status'] = 'running'; $s['wait_ms'] = 10000; break; }
                    } catch (RuntimeException $e) { $s['errors']++; $s['last_error'] = $e->getMessage(); }
                }
                $s['done']++;
            }
            if ($s['done'] >= count($s['queue'])) {
                $s['status'] = 'done';
                // Store availability, posters and synopses for the new/extended collections (Settings-side job)
                Collections::queueRefresh(null, false);
                $s['message'] = sprintf('Done: %d films in %d collections · %d created · %d updated', $s['found'], count($s['series']), $s['created'], $s['updated'])
                    . ($s['skipped_manual'] ? ' · ' . $s['skipped_manual'] . ' left alone (manual collection with that name)' : '');
            } else {
                $s['message'] = 'Checked ' . $s['done'] . ' of ' . $s['total'] . ' films…';
            }
            $s['updated_at'] = date('c');
            $wait = $s['wait_ms'] ?? 0;
            unset($s['wait_ms']);
            if ($wait) $s['message'] = 'The metadata source asked us to slow down; continuing shortly…';
            self::saveState($s);
            $out = self::progress($s);
            if ($wait) $out['wait_ms'] = $wait;
            return $out;
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /** Look one film up and upsert its collection. Returns 'rate' when the source asked us to slow down. */
    private static function lookup(array $film, array &$s): ?string {
        $src = (string)$s['source'];
        $id = (string)($film['id'] ?? '');
        $ck = 'coldisc|' . $src . '|' . sha1($id . '|' . ($film['title'] ?? '') . '|' . ($film['year'] ?? '') . '|' . ($film['imdb_id'] ?? ''));
        $hit = self::cacheGet($ck);
        if ($hit === null) {
            $hit = $src === 'tmdb' ? self::tmdbFilm($film) : self::wikidataFilm($film);
            if ($hit === 'rate') return 'rate';
            self::cachePut($ck, is_array($hit) ? $hit : ['none' => true]);
        }
        if (!is_array($hit) || !empty($hit['none'])) return null;
        $s['found']++;
        $sid = (string)$hit['source_id'];
        if (!isset($s['series'][$sid])) {
            $col = self::cacheGet('coldisc|col|' . $sid);
            if ($col === null) {
                $col = $src === 'tmdb' ? self::tmdbCollection((int)$hit['collection_id']) : self::wikidataSeries((string)$hit['series'], (string)($hit['series_label'] ?? ''));
                if ($col === 'rate') { $s['found']--; return 'rate'; }
                if (!is_array($col)) throw new RuntimeException('Collection details unavailable for ' . $sid);
                self::cachePut('coldisc|col|' . $sid, $col);
            }
            $s['series'][$sid] = self::upsert($sid, $col, $s);
        }
        if ($s['series'][$sid]) self::linkFilm((string)$s['series'][$sid], $film, $hit);
        return null;
    }

    /** Create or extend the collection for $sid. Returns its id, or '' when a manual one owns the name. */
    public static function upsert(string $sid, array $col, array &$s): string {
        $list = Collections::all();
        $name = trim((string)($col['name'] ?? ''));
        foreach ($list as $i => $c) {
            if (($c['source_id'] ?? '') !== $sid) continue;
            $parts = (array)($c['parts'] ?? []);
            $have = [];
            foreach ($parts as $p) $have[self::partKey($p)] = true;
            $changed = false;
            foreach ((array)($col['parts'] ?? []) as $p) {
                if (isset($have[self::partKey($p)])) continue;
                $parts[] = $p; $have[self::partKey($p)] = true; $changed = true;
            }
            foreach (['description', 'poster_url', 'source_url'] as $k) {
                if (empty($c[$k]) && !empty($col[$k])) { $list[$i][$k] = $col[$k]; $changed = true; }
            }
            if ($changed) {
                $list[$i]['parts'] = self::sortParts($parts);
                $list[$i]['updated_at'] = date('c');
                Collections::save($list);
                $s['updated']++;
            }
            return (string)$c['id'];
        }
        foreach ($list as $c) {
            if (empty($c['source_id']) && $name !== '' && self::key(self::baseName((string)($c['name'] ?? ''))) === self::key(self::baseName($name))) {
                $s['skipped_manual']++;
                return '';
            }
        }
        $created = Collections::create([
            'name' => $name !== '' ? $name : 'Collection',
            'type' => 'movies',
            'description' => (string)($col['description'] ?? ''),
            'parts' => self::sortParts((array)($col['parts'] ?? [])),
            'source' => (string)($col['source'] ?? ''),
            'source_url' => $col['source_url'] ?? null,
            'source_query' => null,
        ]);
        $list = Collections::all();
        foreach ($list as $i => $c) {
            if (($c['id'] ?? '') !== $created['collection']['id']) continue;
            $list[$i]['source_id'] = $sid;
            if (!empty($col['poster_url'])) $list[$i]['poster_url'] = $col['poster_url'];
        }
        Collections::save($list);
        $s['created']++;
        return (string)$created['collection']['id'];
    }

    private static function baseName(string $n): string {
        return (string)preg_replace('/\b(collection|film series|franchise|saga|series)\b/i', '', $n);
    }

    private static function sortParts(array $parts): array {
        usort($parts, function ($a, $b) { return [(int)($a['year'] ?? 9999), (string)($a['title'] ?? '')] <=> [(int)($b['year'] ?? 9999), (string)($b['title'] ?? '')]; });
        return array_values($parts);
    }

    /** Pin the library film to its part (adds the part if the source list lacked it). */
    private static function linkFilm(string $colId, array $film, array $hit): void {
        $list = Collections::all();
        foreach ($list as $i => $c) {
            if (($c['id'] ?? '') !== $colId) continue;
            $parts = (array)($c['parts'] ?? []);
            $want = self::key((string)($hit['title'] ?? $film['title'])) . '|' . (string)($hit['year'] ?? ($film['year'] ?? ''));
            $ext = (string)($hit['ext_id'] ?? '');
            $idx = null;
            foreach ($parts as $k => $p) {
                if (($ext !== '' && (string)($p['ext_id'] ?? '') === $ext) || self::partKey($p) === $want) { $idx = $k; break; }
            }
            if ($idx === null) { $parts[] = ['title' => (string)($hit['title'] ?? $film['title']), 'year' => $hit['year'] ?? ($film['year'] ?? null), 'ext_id' => $ext ?: null]; $idx = count($parts) - 1; }
            if (($parts[$idx]['item_id'] ?? '') === (string)$film['id']) return;
            $parts[$idx]['item_id'] = (string)$film['id'];
            $list[$i]['parts'] = self::sortParts($parts);
            Collections::save($list);
            return;
        }
    }

    // ---- HTTP with per-host pacing and 429 handling ----

    private static function http(string $url, array $headers = [], float $minGap = 0.3): array {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $since = microtime(true) - (self::$lastRequest[$host] ?? 0);
        if ($since < $minGap) self::sleep($minGap - $since);
        self::$lastRequest[$host] = microtime(true);
        $headers[] = 'User-Agent: Vaultcast/' . (defined('STREAM_VERSION') ? STREAM_VERSION : '1') . ' (collections; self-hosted media server)';
        $headers[] = 'Accept: application/json';
        if (self::$http) return (self::$http)($url, $headers);
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $l) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $l, $m)) $status = (int)$m[1];
            return [$status, (string)$body, []];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, is_string($body) ? $body : '', []];
    }

    private static function sleep(float $s): void {
        if (self::$sleep) { (self::$sleep)($s); return; }
        usleep((int)round($s * 1e6));
    }

    private static function json(array $r): ?array {
        if ($r[0] !== 200) return null;
        $j = json_decode((string)$r[1], true);
        return is_array($j) ? $j : null;
    }

    private static function cacheGet(string $k): ?array {
        if (self::$http) return null;
        return \Vaultcast\Repository::cacheGet($k);
    }
    private static function cachePut(string $k, array $v): void {
        if (self::$http) return;
        \Vaultcast\Repository::cachePut($k, $v, self::CACHE_TTL);
    }

    // ---- TMDB ----

    public static function tmdbGet(string $path, array $q = []) {
        $key = self::tmdbKey();
        $headers = [];
        if (strlen($key) > 60 && substr_count($key, '.') >= 2) $headers[] = 'Authorization: Bearer ' . $key; // v4 read token
        else $q['api_key'] = $key;
        $r = self::http('https://api.themoviedb.org/3' . $path . '?' . http_build_query($q), $headers, 0.25);
        if ($r[0] === 429) return 'rate';
        if ($r[0] === 401) throw new RuntimeException('TMDB rejected the API key (Settings → Artwork).');
        return self::json($r);
    }

    /** @return array|string|null hit, 'rate', or null (not in a collection / not found) */
    public static function tmdbFilm(array $film) {
        $tmdbId = 0;
        if (preg_match('/^tt\d{5,10}$/', (string)($film['imdb_id'] ?? ''))) {
            $f = self::tmdbGet('/find/' . $film['imdb_id'], ['external_source' => 'imdb_id']);
            if ($f === 'rate') return 'rate';
            $tmdbId = (int)($f['movie_results'][0]['id'] ?? 0);
        }
        if (!$tmdbId) {
            $q = ['query' => (string)$film['title'], 'include_adult' => 'false'];
            if (!empty($film['year'])) $q['year'] = (int)$film['year'];
            $f = self::tmdbGet('/search/movie', $q);
            if ($f === 'rate') return 'rate';
            $want = self::key((string)$film['title']);
            foreach ((array)($f['results'] ?? []) as $row) {
                $y = (int)substr((string)($row['release_date'] ?? ''), 0, 4);
                $titleOk = self::key((string)($row['title'] ?? '')) === $want || self::key((string)($row['original_title'] ?? '')) === $want;
                if ($titleOk && (empty($film['year']) || !$y || abs($y - (int)$film['year']) <= 1)) { $tmdbId = (int)$row['id']; break; }
            }
        }
        if (!$tmdbId) return null;
        $m = self::tmdbGet('/movie/' . $tmdbId);
        if ($m === 'rate') return 'rate';
        $c = $m['belongs_to_collection'] ?? null;
        if (!is_array($c) || empty($c['id'])) return null;
        return ['source_id' => 'tmdb:collection:' . (int)$c['id'], 'collection_id' => (int)$c['id'], 'ext_id' => 'tmdb:' . $tmdbId,
            'title' => (string)($m['title'] ?? $film['title']), 'year' => ((int)substr((string)($m['release_date'] ?? ''), 0, 4)) ?: ($film['year'] ?? null)];
    }

    public static function tmdbCollection(int $id) {
        $c = self::tmdbGet('/collection/' . $id);
        if ($c === 'rate') return 'rate';
        if (!is_array($c)) return null;
        $parts = [];
        foreach ((array)($c['parts'] ?? []) as $p) {
            if (empty($p['title'])) continue;
            $y = (int)substr((string)($p['release_date'] ?? ''), 0, 4);
            $parts[] = ['title' => (string)$p['title'], 'year' => $y ?: null, 'ext_id' => 'tmdb:' . (int)$p['id']];
        }
        return ['name' => (string)($c['name'] ?? ''), 'description' => (string)($c['overview'] ?? ''), 'parts' => $parts, 'source' => 'tmdb',
            'source_url' => 'https://www.themoviedb.org/collection/' . $id,
            'poster_url' => !empty($c['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $c['poster_path'] : null];
    }

    // ---- Wikidata ----

    private static function sparql(string $q) {
        $r = self::http('https://query.wikidata.org/sparql?format=json&query=' . rawurlencode($q), [], 1.0);
        if ($r[0] === 429) return 'rate';
        $j = self::json($r);
        return $j['results']['bindings'] ?? null;
    }

    private static function lit(string $s): string { return '"' . addcslashes($s, "\"\\\n\r") . '"'; }

    public static function wikidataFilm(array $film) {
        $types = implode(' ', self::FILM_TYPES);
        if (preg_match('/^tt\d{5,10}$/', (string)($film['imdb_id'] ?? ''))) {
            $q = 'SELECT ?film ?series ?seriesLabel WHERE { ?film wdt:P345 ' . self::lit((string)$film['imdb_id']) . '; wdt:P179 ?series . '
               . 'SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } } LIMIT 3';
        } else {
            $year = (int)($film['year'] ?? 0);
            $q = 'SELECT ?film ?series ?seriesLabel WHERE { VALUES ?ft { ' . $types . ' } ?film rdfs:label ' . self::lit((string)$film['title']) . '@en; '
               . 'wdt:P31 ?ft; wdt:P179 ?series . ' . ($year ? '?film wdt:P577 ?d . FILTER(YEAR(?d) >= ' . ($year - 1) . ' && YEAR(?d) <= ' . ($year + 1) . ') ' : '')
               . 'SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } } LIMIT 3';
        }
        $rows = self::sparql($q);
        if ($rows === 'rate') return 'rate';
        if (!is_array($rows) || !$rows) return null;
        $series = preg_replace('#^.*/#', '', (string)($rows[0]['series']['value'] ?? ''));
        $film_q = preg_replace('#^.*/#', '', (string)($rows[0]['film']['value'] ?? ''));
        if (!preg_match('/^Q\d+$/', (string)$series)) return null;
        return ['source_id' => 'wikidata:' . $series, 'series' => $series, 'series_label' => (string)($rows[0]['seriesLabel']['value'] ?? ''),
            'ext_id' => 'wikidata:' . $film_q, 'title' => (string)$film['title'], 'year' => $film['year'] ?? null];
    }

    public static function wikidataSeries(string $q, string $label) {
        if (!preg_match('/^Q\d+$/', $q)) return null;
        $types = implode(' ', self::FILM_TYPES);
        $rows = self::sparql('SELECT ?film ?filmLabel (MIN(YEAR(?d)) AS ?y) ?desc WHERE { VALUES ?ft { ' . $types . ' } ?film wdt:P179 wd:' . $q . '; wdt:P31 ?ft . '
            . 'OPTIONAL { ?film wdt:P577 ?d } OPTIONAL { wd:' . $q . ' schema:description ?desc FILTER(LANG(?desc) = "en") } '
            . 'SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } } GROUP BY ?film ?filmLabel ?desc LIMIT 200');
        if ($rows === 'rate') return 'rate';
        if (!is_array($rows)) return null;
        $parts = [];
        $desc = '';
        foreach ($rows as $r) {
            $t = (string)($r['filmLabel']['value'] ?? '');
            if ($t === '' || preg_match('/^Q\d+$/', $t)) continue;
            $parts[] = ['title' => $t, 'year' => isset($r['y']['value']) ? (int)$r['y']['value'] : null, 'ext_id' => 'wikidata:' . preg_replace('#^.*/#', '', (string)$r['film']['value'])];
            if ($desc === '' && !empty($r['desc']['value'])) $desc = (string)$r['desc']['value'];
        }
        return ['name' => $label !== '' ? $label : $q, 'description' => $desc, 'parts' => $parts, 'source' => 'wikidata',
            'source_url' => 'https://www.wikidata.org/wiki/' . $q, 'poster_url' => null];
    }
}
