<?php
declare(strict_types=1);
/**
 * Metadata matching: persistent overrides, show provider pins, TVMaze auto-match,
 * catalogue re-parse, and user wrong-label reports.
 *
 * data/match_overrides.json
 *   items: { <itemId>: {section, rel, show?, season?, episode?, episode_title?, title?, year?, source, at, by} }
 *   shows: { <showKey>: {tvmaze_id?:int, provider:'tvmaze'|'none', name?, at, by} }
 * data/label_reports.json: list of reports
 */
class Matching {
    const STORE = 'match_overrides';
    const REPORTS = 'label_reports';
    const FIELDS = ['show', 'season', 'episode', 'episode_title', 'title', 'year', 'poster', 'show_poster'];

    /* ------------------------------------------------------------------ store */

    public static function overrides(): array {
        $o = Store::get(self::STORE, null);
        if (!is_array($o)) $o = [];
        if (!isset($o['items']) || !is_array($o['items'])) $o['items'] = [];
        if (!isset($o['shows']) || !is_array($o['shows'])) $o['shows'] = [];
        return $o;
    }

    public static function saveOverrides(array $o): bool {
        $o['version'] = 1;
        $o['updated_at'] = date('c');
        return Store::set(self::STORE, $o);
    }

    public static function itemOverride(string $id): ?array {
        $o = self::overrides();
        return isset($o['items'][$id]) && is_array($o['items'][$id]) ? $o['items'][$id] : null;
    }

    /** Apply a saved override to a freshly built catalogue item. */
    public static function applyOverride(array $item): array {
        $id = (string)($item['id'] ?? '');
        if ($id === '') return $item;
        $ov = self::itemOverride($id);
        if (!$ov) return $item;
        foreach (self::FIELDS as $k) {
            if (array_key_exists($k, $ov)) $item[$k] = $ov[$k];
        }
        if (isset($item['season']) && $item['season'] !== null && $item['season'] !== '') $item['season'] = (int)$item['season'];
        if (isset($item['episode']) && $item['episode'] !== null && $item['episode'] !== '') $item['episode'] = (int)$item['episode'];
        $item['match_source'] = (string)($ov['source'] ?? 'manual');
        $type = (string)($item['type'] ?? '');
        if (($type === 'tv' || $type === 'anime') && !array_key_exists('title', $ov)) {
            $item['title'] = Catalogue::formatEpisodeDisplayTitle(
                $item['show'] ?? null, $item['season'] ?? null, $item['episode'] ?? null, $item['episode_title'] ?? null
            );
        }
        return $item;
    }

    public static function showPin(string $showKey): ?array {
        $o = self::overrides();
        return isset($o['shows'][$showKey]) && is_array($o['shows'][$showKey]) ? $o['shows'][$showKey] : null;
    }

    /** Pinned TVMaze id (int), 0 when pinned to "no online match", null when not pinned. */
    public static function pinnedTvmazeId(string $showKey): ?int {
        $p = self::showPin($showKey);
        if (!$p) return null;
        if (($p['provider'] ?? '') === 'none') return 0;
        $id = (int)($p['tvmaze_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /* ----------------------------------------------------------- catalogue io */

    private static function sectionTypes(): array {
        $out = [];
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            if (!empty($sec['id'])) $out[(string)$sec['id']] = (string)($sec['type'] ?? 'movies');
        }
        return $out;
    }

    /** Items of a show (by key) from the catalogue. */
    public static function showItems(string $showKey): array {
        $cat = Catalogue::get();
        $out = [];
        foreach ($cat['items'] ?? [] as $id => $it) {
            if (!is_array($it)) continue;
            $type = $it['type'] ?? '';
            if ($type !== 'tv' && $type !== 'anime') continue;
            $name = (string)($it['show'] ?? '');
            if ($name === '') {
                $parts = explode('/', str_replace('\\', '/', (string)($it['rel'] ?? '')));
                $name = $parts[0] ?? '';
            }
            if (Catalogue::showKey((string)($it['section'] ?? ''), $name) === $showKey) $out[] = $it;
        }
        usort($out, function ($a, $b) { return strnatcasecmp((string)($a['rel'] ?? ''), (string)($b['rel'] ?? '')); });
        return $out;
    }

    /** Write overrides for many items and patch the catalogue in place (one save each). */
    public static function saveItemFields(array $rows, string $source, string $by = 'admin', bool $keepManual = false, bool $scheduleBackup = true): array {
        $o = self::overrides();
        $cat = Catalogue::get();
        $changed = 0;
        $skipped = 0;
        $updated = [];
        foreach ($rows as $row) {
            $id = (string)($row['id'] ?? '');
            if ($id === '' || !isset($cat['items'][$id])) { $skipped++; continue; }
            $prev = $o['items'][$id] ?? null;
            if ($keepManual && is_array($prev) && ($prev['source'] ?? '') === 'manual') {
                $manualLabel = false;
                foreach (['season', 'episode', 'episode_title', 'title', 'year'] as $mk) { if (array_key_exists($mk, $prev)) { $manualLabel = true; break; } }
                if ($manualLabel) { $skipped++; continue; }
            }
            $it = $cat['items'][$id];
            // merge: a manual show rename survives later auto/ingest label passes
            $ov = is_array($prev) ? $prev : [];
            unset($ov['source'], $ov['at'], $ov['by']);
            foreach (self::FIELDS as $k) {
                if (!array_key_exists($k, $row)) continue;
                $v = $row[$k];
                if ($k === 'season' || $k === 'episode' || $k === 'year') {
                    $v = ($v === '' || $v === null) ? null : (int)$v;
                } elseif ($v !== null) {
                    $v = Catalogue::fixText((string)$v);
                    if ($v === '') $v = null;
                }
                if ($k === 'show' && ($v === null || $v === '')) continue;
                $ov[$k] = $v;
            }
            if (!$ov) { $skipped++; continue; }
            $ov['section'] = (string)($it['section'] ?? '');
            $ov['rel'] = (string)($it['rel'] ?? '');
            $ov['source'] = $source;
            $ov['at'] = date('c');
            $ov['by'] = $by;
            if (!empty($row['tvmaze_episode_id'])) $ov['tvmaze_episode_id'] = (int)$row['tvmaze_episode_id'];
            if (!empty($row['imdb_id'])) $ov['imdb_id'] = (string)$row['imdb_id'];
            $o['items'][$id] = $ov;
            // Patch catalogue item: start from parsed values, then apply override
            foreach (self::FIELDS as $k) if (array_key_exists($k, $ov)) $it[$k] = $ov[$k];
            $it['match_source'] = $source;
            $type = (string)($it['type'] ?? '');
            if (($type === 'tv' || $type === 'anime') && !array_key_exists('title', $ov)) {
                $it['title'] = Catalogue::formatEpisodeDisplayTitle($it['show'] ?? null, $it['season'] ?? null, $it['episode'] ?? null, $it['episode_title'] ?? null);
            }
            $cat['items'][$id] = $it;
            $updated[] = $id;
            $changed++;
        }
        if ($changed) {
            self::saveOverrides($o);
            $cat['updated_at'] = date('c');
            Catalogue::save($cat);
            Store::invalidate('catalogue');
            $backup = $scheduleBackup ? MetadataSidecars::schedule() : ['ok'=>true];
        }
        return ['ok' => true, 'changed' => $changed, 'skipped' => $skipped, 'ids' => $updated] + (!empty($backup) && empty($backup['ok']) ? ['warning'=>$backup['warning']] : []);
    }

    /** Remove overrides and rebuild the items from disk names. */
    public static function clearItems(array $ids): array {
        $o = self::overrides();
        $cat = Catalogue::get();
        $done = [];
        foreach ($ids as $id) {
            $id = (string)$id;
            unset($o['items'][$id]);
            $done[] = $id;
        }
        self::saveOverrides($o);
        $rebuilt = 0;
        foreach ($done as $id) {
            $it = $cat['items'][$id] ?? null;
            if (!$it) continue;
            $new = self::rebuildItem($it);
            if ($new) { $cat['items'][$id] = $new; $rebuilt++; }
        }
        if ($rebuilt) {
            $cat['updated_at'] = date('c');
            Catalogue::save($cat);
            Store::invalidate('catalogue');
        }
        return ['ok' => true, 'cleared' => count($done), 'rebuilt' => $rebuilt];
    }

    /** Rebuild one catalogue item from its path (no disk walk). */
    public static function rebuildItem(array $it): ?array {
        $path = (string)($it['path'] ?? '');
        $rel = (string)($it['rel'] ?? '');
        $sid = (string)($it['section'] ?? '');
        if ($path === '' || $rel === '' || $sid === '') return null;
        $settings = Store::settings();
        $root = null; $type = (string)($it['type'] ?? 'movies');
        foreach ($settings['sections'] ?? [] as $sec) {
            if (($sec['id'] ?? '') === $sid) { $root = (string)($sec['path'] ?? ''); $type = (string)($sec['type'] ?? $type); break; }
        }
        if (!$root) return null;
        $rootReal = \Vaultcast\Paths::real($root) ?: rtrim($root, '/');
        $scan = $settings['scan'] ?? [];
        $videoExt = array_map('strtolower', $scan['video_extensions'] ?? ['mp4','mkv','avi','m4v','webm']);
        $audioExt = array_map('strtolower', $scan['audio_extensions'] ?? ['mp3','flac','aac']);
        $new = Catalogue::buildItem($path, $rel, $rootReal, $sid, $type, $videoExt, $audioExt, isset($it['size']) ? (int)$it['size'] : null, isset($it['mtime']) ? (int)$it['mtime'] : null);
        return $new;
    }

    /**
     * Re-run the (improved) name parser over existing TV/anime catalogue items without walking disk.
     * dry=true returns a diff only.
     */
    public static function reparseCatalogue(bool $dry = true, int $sampleLimit = 60): array {
        @set_time_limit(120);
        $cat = Catalogue::get();
        $diffs = [];
        $count = 0;
        $changedItems = [];
        foreach ($cat['items'] ?? [] as $id => $it) {
            if (!is_array($it)) continue;
            $type = $it['type'] ?? '';
            if ($type !== 'tv' && $type !== 'anime') continue;
            $new = self::rebuildItem($it);
            if (!$new) continue;
            $keys = ['show', 'season', 'episode', 'episode_title', 'title'];
            $delta = [];
            foreach ($keys as $k) {
                $a = $it[$k] ?? null; $b = $new[$k] ?? null;
                if ((string)$a !== (string)$b || ($a === null) !== ($b === null)) $delta[$k] = [$a, $b];
            }
            if (!$delta) continue;
            $count++;
            // keep existing poster fields from previous item when rebuild found none
            foreach (['poster', 'show_poster', 'season_poster'] as $pk) {
                if (empty($new[$pk]) && !empty($it[$pk])) $new[$pk] = $it[$pk];
            }
            $changedItems[$id] = $new;
            if (count($diffs) < $sampleLimit) $diffs[] = ['id' => $id, 'rel' => $it['rel'] ?? '', 'changes' => $delta];
        }
        if (!$dry && $changedItems) {
            foreach ($changedItems as $id => $new) $cat['items'][$id] = $new;
            $cat['updated_at'] = date('c');
            Catalogue::save($cat);
            Store::invalidate('catalogue');
        }
        return ['ok' => true, 'dry' => $dry, 'changed' => $count, 'sample' => $diffs];
    }

    /* ------------------------------------------------------------ similarity */

    public static function compact(string $s): string {
        $s = Catalogue::fixText($s);
        $map = ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c','É'=>'e','Á'=>'a','Ó'=>'o','Ú'=>'u','Í'=>'i','’'=>'',"'"=>''];
        $s = strtr($s, $map);
        $s = strtolower($s);
        $s = str_replace('&', 'and', $s);
        return (string)preg_replace('/[^a-z0-9]+/', '', $s);
    }

    /** 0..100 similarity of two titles. */
    public static function titleScore(string $a, string $b): float {
        $ca = self::compact($a); $cb = self::compact($b);
        if ($ca === '' || $cb === '') return 0.0;
        if ($ca === $cb) return 100.0;
        if (strlen($ca) < 4 || strlen($cb) < 4) return 0.0;
        similar_text($ca, $cb, $pct);
        $pct = (float)$pct;
        // "the x" / trailing article differences
        $sa = preg_replace('/^the/', '', $ca); $sb = preg_replace('/^the/', '', $cb);
        if ($sa === $sb) $pct = max($pct, 98.0);
        $la = strlen($ca); $lb = strlen($cb);
        if (min($la, $lb) >= 8 && (strpos($ca, $cb) !== false || strpos($cb, $ca) !== false) && min($la, $lb) / max($la, $lb) >= 0.75) {
            $pct = max($pct, 90.0);
        }
        return round($pct, 1);
    }

    /* ---------------------------------------------------------------- TVMaze */

    private static function http(string $url, int $timeout = 12): ?string {
        $left = Posters::remoteSecondsLeft();
        if ($left !== null) {
            if ($left < 0.05) return null;
            $timeout = max(1, min($timeout, (int)ceil($left)));
        }
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: Vaultcast/" . STREAM_VERSION . " (+matching)\r\nAccept: application/json\r\n"]]);
            $b = @file_get_contents($url, false, $ctx);
            return $b === false ? null : $b;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . ' (+matching)', CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) return null;
        return (string)$raw;
    }

    /** TVMaze show search (rich rows for the picker). */
    public static function tvmazeSearch(string $q): array {
        $q = trim($q);
        if ($q === '') return [];
        $cacheKey = 'tvmaze-search:' . mb_strtolower($q);
        $cached = \Vaultcast\Repository::cacheGet($cacheKey);
        if ($cached !== null) return $cached;
        if (preg_match('/^(?:tvmaze:|https:\/\/(?:www\.)?tvmaze\.com\/shows\/)([0-9]+)/i', $q, $m)) {
            $raw = self::http('https://api.tvmaze.com/shows/' . (int)$m[1]);
            $show = $raw ? json_decode($raw, true) : null;
            return is_array($show) && !empty($show['id']) ? [self::tvmazeRow($show, 100)] : [];
        }
        if (preg_match('/^(?:imdb:)?(tt[0-9]+)$/i', $q, $m)) {
            $raw = self::http('https://api.tvmaze.com/lookup/shows?imdb=' . rawurlencode($m[1]));
            $show = $raw ? json_decode($raw, true) : null;
            return is_array($show) && !empty($show['id']) ? [self::tvmazeRow($show, 100)] : [];
        }
        $json = self::http('https://api.tvmaze.com/search/shows?q=' . rawurlencode($q));
        $arr = $json ? json_decode($json, true) : null;
        $out = [];
        if (!is_array($arr)) return $out;
        foreach (array_slice($arr, 0, 12) as $row) {
            $s = $row['show'] ?? null;
            if (!is_array($s)) continue;
            $out[] = self::tvmazeRow($s, (float)($row['score'] ?? 0));
        }
        \Vaultcast\Repository::cachePut($cacheKey, $out, 3600);
        return $out;
    }

    private static function tvmazeRow(array $s, float $score = 0): array {
        $year = null;
        if (!empty($s['premiered']) && preg_match('/^(\d{4})/', (string)$s['premiered'], $m)) $year = (int)$m[1];
        $genres = is_array($s['genres'] ?? null) ? $s['genres'] : [];
        $lang = (string)($s['language'] ?? '');
        $type = (string)($s['type'] ?? '');
        return [
            'kind' => 'tv',
            'source' => 'tvmaze',
            'id' => (int)($s['id'] ?? 0),
            'title' => (string)($s['name'] ?? ''),
            'year' => $year,
            'premiered' => $s['premiered'] ?? null,
            'ended' => $s['ended'] ?? null,
            'language' => $lang,
            'type' => $type,
            'genres' => $genres,
            'network' => $s['network']['name'] ?? ($s['webChannel']['name'] ?? null),
            'status' => $s['status'] ?? null,
            'image' => $s['image']['medium'] ?? ($s['image']['original'] ?? null),
            'summary' => trim(html_entity_decode(strip_tags((string)($s['summary'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'url' => $s['url'] ?? null,
            'looks_anime' => ($type === 'Animation' && strcasecmp($lang, 'Japanese') === 0) || in_array('Anime', $genres, true),
            'score' => round($score, 3),
        ];
    }

    /** Episode guide by TVMaze id (includes specials). Cached 7 days in data/tvmaze/byid_<id>.json. */
    public static function tvmazeGuideById(int $id, bool $refresh = false): ?array {
        if ($id <= 0) return null;
        $dir = STREAM_DATA . '/tvmaze';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir . '/byid_' . $id . '.json';
        if (!$refresh && is_file($path) && (time() - filemtime($path)) < 7 * 86400) {
            $j = json_decode((string)@file_get_contents($path), true);
            if (is_array($j) && isset($j['episodes'])) return $j;
        }
        $sj = self::http('https://api.tvmaze.com/shows/' . $id);
        $show = $sj ? json_decode($sj, true) : null;
        if (!is_array($show) || empty($show['id'])) return null;
        $ej = self::http('https://api.tvmaze.com/shows/' . $id . '/episodes?specials=1');
        $eps = $ej ? json_decode($ej, true) : null;
        if (!is_array($eps)) $eps = [];
        $row = self::tvmazeRow($show);
        $out = [
            'id' => $id,
            'name' => $row['title'],
            'premiered' => $row['premiered'],
            'summary' => $row['summary'] !== '' ? $row['summary'] : null,
            'genres' => $row['genres'],
            'status' => $row['status'],
            'language' => $row['language'],
            'image' => $row['image'],
            'with_specials' => true,
            'fetched_at' => date('c'),
            'episodes' => [],
        ];
        foreach ($eps as $e) {
            if (!is_array($e)) continue;
            $out['episodes'][] = [
                'id' => $e['id'] ?? null,
                'name' => $e['name'] ?? null,
                'season' => $e['season'] ?? null,
                'number' => $e['number'] ?? null,
                'airdate' => $e['airdate'] ?? null,
                'runtime' => $e['runtime'] ?? null,
                'type' => $e['type'] ?? 'regular',
            ];
        }
        @file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $out;
    }

    /** Guide for a catalogue show: pinned id first, then the regular cached lookup. */
    public static function guideForShow(string $showKey, string $showName, bool $refresh = false): ?array {
        $pin = self::pinnedTvmazeId($showKey);
        if ($pin === 0) return null;
        if ($pin) return self::tvmazeGuideById($pin, $refresh);
        if (!class_exists('Posters')) return null;
        $g = Posters::tvMazeEpisodeGuide($showKey, $showName, $refresh);
        if (is_array($g) && !empty($g['id'])) {
            // Upgrade to the specials-inclusive by-id guide for matching
            $full = self::tvmazeGuideById((int)$g['id'], false);
            if ($full) return $full;
        }
        return $g;
    }

    /** Regular episodes ordered by (season, number) — for absolute numbering. */
    public static function regularEpisodes(array $guide): array {
        $reg = [];
        foreach ($guide['episodes'] ?? [] as $e) {
            if (!is_array($e) || $e['number'] === null || (int)($e['season'] ?? 0) <= 0) continue;
            $reg[] = $e;
        }
        usort($reg, function ($a, $b) {
            $c = ((int)$a['season']) <=> ((int)$b['season']);
            return $c !== 0 ? $c : (((int)$a['number']) <=> ((int)$b['number']));
        });
        return $reg;
    }

    public static function guideLookup(array $guide, int $s, int $e): ?array {
        foreach ($guide['episodes'] ?? [] as $x) {
            if (is_array($x) && $x['number'] !== null && (int)$x['season'] === $s && (int)$x['number'] === $e) return $x;
        }
        return null;
    }

    /** Best title match in a guide. */
    /** Natural order for local specials: files in a folder before its sub-folders; Extras/Unseen last. */
    private static function localSortKey(string $rel): string {
        $parts = explode('/', str_replace('\\', '/', $rel));
        $file = array_pop($parts);
        $out = [];
        foreach ($parts as $f) {
            $out[] = preg_match('/^(?:Extras?|Bonus|Unseen|Featurettes?|Behind The Scenes|Deleted Scenes)$/i', $f) ? '~' . $f : $f;
        }
        return implode('/', $out) . '/!' . $file;
    }

    public static function bestTitleMatch(string $title, array $guide, float $threshold = 86.0): ?array {
        if (self::compact($title) === '') return null;
        $best = null; $bestScore = 0.0; $second = 0.0;
        foreach ($guide['episodes'] ?? [] as $e) {
            if (!is_array($e) || empty($e['name'])) continue;
            $sc = self::titleScore($title, (string)$e['name']);
            if ($sc > $bestScore) { $second = $bestScore; $bestScore = $sc; $best = $e; }
            elseif ($sc > $second) { $second = $sc; }
        }
        if (!$best || $bestScore < $threshold) return null;
        if ($bestScore < 100 && $second >= $bestScore - 0.5) return null; // ambiguous
        return ['episode' => $best, 'score' => $bestScore];
    }

    /* ------------------------------------------------------------ auto match */

    /**
     * Propose season/episode/title for every file of a show.
     * Methods: filename (SxxExx), title (fuzzy vs TVMaze names), absolute (anime numbering),
     * folder (Season N + episode number), local (folder/name label, numbered as specials).
     */
    public static function proposeShow(string $showKey, array $opts = []): array {
        $items = self::showItems($showKey);
        if (!$items) return ['ok' => false, 'error' => 'Show not found'];
        $showName = (string)($items[0]['show'] ?? '');
        if ($showName === '') {
            $parts = explode('/', str_replace('\\', '/', (string)($items[0]['rel'] ?? '')));
            $showName = (string)preg_replace('/\s*\(\d{4}\)\s*$/', '', $parts[0] ?? '');
        }
        $guide = self::guideForShow($showKey, $showName, !empty($opts['refresh']));
        $hasGuide = is_array($guide) && !empty($guide['episodes']);
        $regular = $hasGuide ? self::regularEpisodes($guide) : [];
        // Per-show numbering mode (Fix Match → Episode numbering): absolute numbers map through the guide
        $absMode = class_exists('AbsoluteNumbering') && AbsoluteNumbering::effectiveMode($showKey, ['_guide' => $guide]) === 'absolute';
        if ($absMode && $hasGuide && !empty($guide['id'])) {
            $ord = AbsoluteNumbering::tmdbAbsoluteOrder((int)$guide['id']);
            if ($ord) $guide['absolute_order'] = $ord;
        }
        $absTable = $absMode ? AbsoluteNumbering::table($hasGuide ? $guide : null) : null;
        $absTitles = $absMode ? AbsoluteNumbering::showTitles($showKey, $showName, $hasGuide ? $guide : null) : [];
        $absPrefix = '';
        if ($absMode) {
            $bs = [];
            foreach ($items as $it0) $bs[] = basename(str_replace('\\', '/', (string)($it0['rel'] ?? '')));
            $absPrefix = AbsoluteNumbering::commonPrefix($bs);
        }
        $o = self::overrides();
        $rows = [];
        $taken = [];
        foreach ($items as $it) {
            $rel = str_replace('\\', '/', (string)($it['rel'] ?? ''));
            $base = (string)preg_replace('/\.[^.]+$/', '', basename($rel));
            $folder = Catalogue::seasonEpisodeFromRel($rel);
            $folderSeason = null;
            // season from folder only (not from file code)
            $relNoCode = dirname($rel) . '/x.mkv';
            $fs = Catalogue::seasonEpisodeFromRel($relNoCode);
            if ($fs['season'] !== null) $folderSeason = (int)$fs['season'];
            $code = Catalogue::episodeCodeFromName($base, $folderSeason !== null);
            $label = Catalogue::localEpisodeLabel($rel, $showName);
            $clean = Catalogue::cleanFileTitle($base, $showName);
            $prop = ['season' => null, 'episode' => null, 'episode_title' => null, 'method' => 'none', 'confidence' => 0, 'tvmaze_episode_id' => null];
            if ($code['explicit']) {
                $prop['season'] = (int)$code['season'];
                $prop['episode'] = (int)$code['episode'];
                $prop['method'] = 'filename';
                $prop['confidence'] = 98;
                if ($hasGuide) {
                    $g = self::guideLookup($guide, $prop['season'], $prop['episode']);
                    if ($g) { $prop['episode_title'] = $g['name'] ?? null; $prop['tvmaze_episode_id'] = $g['id'] ?? null; }
                }
                if (!$prop['episode_title']) {
                    $n = Catalogue::parseEpisodeNameFromRel($rel, $showName, '');
                    $prop['episode_title'] = $n !== '' ? $n : null;
                }
            } else {
                $matched = false;
                $absP = $absMode ? AbsoluteNumbering::parse($base, $absTitles, $absPrefix) : null;
                $absG = $absP ? AbsoluteNumbering::toSeasonEpisode($absTable, $absP['number']) : null;
                if ($absG) {
                    $prop = ['season' => $absG['season'], 'episode' => $absG['number'], 'episode_title' => $absG['name'], 'method' => 'absolute', 'confidence' => 95, 'tvmaze_episode_id' => $absG['id']];
                    $matched = true;
                }
                if (!$matched && $hasGuide) {
                    foreach (array_unique(array_filter([$clean, $label])) as $cand) {
                        $bm = self::bestTitleMatch((string)$cand, $guide);
                        if ($bm) {
                            $e = $bm['episode'];
                            if ($e['number'] === null) {
                                $prop['season'] = 0; $prop['episode'] = null;
                            } else {
                                $prop['season'] = (int)$e['season']; $prop['episode'] = (int)$e['number'];
                            }
                            $prop['episode_title'] = $e['name'];
                            $prop['tvmaze_episode_id'] = $e['id'] ?? null;
                            $prop['method'] = 'title';
                            $prop['confidence'] = (int)round($bm['score']);
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched && $code['episode'] !== null && in_array($code['method'], ['dash', 'ep', 'leading'], true)) {
                        $n = (int)$code['episode'];
                        if ($folderSeason !== null && $folderSeason > 0 && self::guideLookup($guide, $folderSeason, $n)) {
                            $g = self::guideLookup($guide, $folderSeason, $n);
                            $prop = ['season' => $folderSeason, 'episode' => $n, 'episode_title' => $g['name'] ?? null, 'method' => 'folder', 'confidence' => 85, 'tvmaze_episode_id' => $g['id'] ?? null];
                            $matched = true;
                        } elseif ($folderSeason === null && $n >= 1 && $n <= count($regular)) {
                            $g = $regular[$n - 1];
                            $prop = ['season' => (int)$g['season'], 'episode' => (int)$g['number'], 'episode_title' => $g['name'] ?? null, 'method' => 'absolute', 'confidence' => 75, 'tvmaze_episode_id' => $g['id'] ?? null];
                            $matched = true;
                        }
                    }
                } elseif ($code['episode'] !== null && $folderSeason !== null && in_array($code['method'], ['dash', 'ep', 'leading'], true)) {
                    $prop = ['season' => $folderSeason, 'episode' => (int)$code['episode'], 'episode_title' => $label !== '' ? $label : null, 'method' => 'folder', 'confidence' => 70, 'tvmaze_episode_id' => null];
                    $matched = true;
                }
                if (!$matched) {
                    $prop['season'] = null; // decided below (specials vs folder season)
                    $prop['episode'] = null;
                    $prop['episode_title'] = $label !== '' ? $label : ($clean !== '' ? $clean : null);
                    $prop['method'] = 'local';
                    $prop['confidence'] = 40;
                    $prop['_folder_season'] = $folderSeason;
                    $prop['_sort'] = self::localSortKey($rel);
                }
            }
            if ($prop['season'] !== null && $prop['episode'] !== null) {
                $k = $prop['season'] . 'x' . $prop['episode'];
                if (isset($taken[$k]) && $prop['method'] !== 'filename') {
                    // collision: keep first, demote this one to local
                    $prop['method'] = 'local'; $prop['confidence'] = 30;
                    $prop['season'] = null; $prop['episode'] = null;
                    $prop['episode_title'] = $label !== '' ? $label : ($clean !== '' ? $clean : null);
                    $prop['_folder_season'] = $folderSeason; $prop['_sort'] = self::localSortKey($rel);
                    $prop['note'] = 'collides with another file';
                } else {
                    $taken[$k] = true;
                }
            }
            $prev = $o['items'][(string)$it['id']] ?? null;
            $rows[] = [
                'id' => (string)$it['id'],
                'rel' => $rel,
                'file' => basename($rel),
                'current' => [
                    'season' => $it['season'] ?? null,
                    'episode' => $it['episode'] ?? null,
                    'episode_title' => Catalogue::resolveEpisodeTitle($it) ?: null,
                ],
                'proposal' => $prop,
                'override_source' => is_array($prev) ? ($prev['source'] ?? null) : null,
            ];
        }
        // Unmatched files: specials when an online guide matched most files, otherwise keep folder season.
        $total = count($rows);
        $matchedCount = 0;
        foreach ($rows as $r) if ($r['proposal']['method'] !== 'local') $matchedCount++;
        $useSpecials = !$hasGuide || $matchedCount >= max(1, (int)ceil($total * 0.5));
        $maxSpecial = 0;
        foreach ($rows as $r) {
            if ((int)($r['proposal']['season'] ?? -1) === 0 && $r['proposal']['episode'] !== null) $maxSpecial = max($maxSpecial, (int)$r['proposal']['episode']);
        }
        $locals = [];
        foreach ($rows as $i => $r) {
            if ($r['proposal']['method'] === 'local' || ($r['proposal']['season'] === 0 && $r['proposal']['episode'] === null)) $locals[] = $i;
        }
        usort($locals, function ($a, $b) use ($rows) {
            return strnatcasecmp((string)($rows[$a]['proposal']['_sort'] ?? $rows[$a]['rel']), (string)($rows[$b]['proposal']['_sort'] ?? $rows[$b]['rel']));
        });
        foreach ($locals as $i) {
            $p =& $rows[$i]['proposal'];
            $fsn = $p['_folder_season'] ?? null;
            if ($p['method'] === 'local' && !$useSpecials && $fsn !== null) {
                $p['season'] = (int)$fsn; // keep folder season, unnumbered, named
            } else {
                $p['season'] = 0;
                $maxSpecial++;
                $p['episode'] = $maxSpecial;
            }
            unset($p['_folder_season'], $p['_sort']);
            unset($p);
        }
        foreach ($rows as &$r) {
            unset($r['proposal']['_folder_season'], $r['proposal']['_sort']);
            $c = $r['current']; $p = $r['proposal'];
            $r['changed'] = ((string)$c['season'] !== (string)$p['season']) || ((string)$c['episode'] !== (string)$p['episode'])
                || ((string)$c['episode_title'] !== (string)$p['episode_title']);
        }
        unset($r);
        return [
            'ok' => true,
            'show_key' => $showKey,
            'show' => $showName,
            'section' => (string)($items[0]['section'] ?? ''),
            'guide' => $hasGuide ? ['id' => $guide['id'] ?? null, 'name' => $guide['name'] ?? null, 'premiered' => $guide['premiered'] ?? null, 'episodes' => count($guide['episodes'] ?? [])] : null,
            'pin' => self::showPin($showKey),
            'total' => $total,
            'matched_online' => $matchedCount,
            'rows' => $rows,
        ];
    }

    /** Apply proposals (all, or only selected ids). Manual overrides are kept unless force. */
    public static function applyShowProposals(string $showKey, array $opts = []): array {
        $pr = self::proposeShow($showKey, $opts);
        if (empty($pr['ok'])) return $pr;
        $only = isset($opts['ids']) && is_array($opts['ids']) ? array_flip(array_map('strval', $opts['ids'])) : null;
        $rows = [];
        foreach ($pr['rows'] as $r) {
            if ($only !== null && !isset($only[$r['id']])) continue;
            if (empty($r['changed']) && empty($opts['all'])) continue;
            $p = $r['proposal'];
            $rows[] = [
                'id' => $r['id'],
                'season' => $p['season'],
                'episode' => $p['episode'],
                'episode_title' => $p['episode_title'],
                'tvmaze_episode_id' => $p['tvmaze_episode_id'] ?? null,
            ];
        }
        $res = self::saveItemFields($rows, 'auto', self::actor(), empty($opts['force']));
        $res['show_key'] = $showKey;
        $res['proposed'] = count($rows);
        self::log('AUTO ' . $pr['show'] . ' changed=' . $res['changed']);
        return $res;
    }

    /** Pin a show to a TVMaze id (or "none"), optionally renaming the show to the provider title. */
    public static function pinShow(string $showKey, array $b): array {
        $b=Permissions::selection($b);
        $items = self::showItems($showKey);
        if (!$items) return ['ok' => false, 'error' => 'Show not found'];
        $provider = (string)($b['provider'] ?? 'tvmaze');
        $id = (int)($b['tvmaze_id'] ?? 0);
        $match = is_array($b['match'] ?? null) ? $b['match'] : null;
        $metaOnly = false;
        if ($match && ($match['source'] ?? '') !== 'tvmaze' && $provider === 'tvmaze' && $id <= 0) {
            // A non-TVmaze pick pins its own synopsis/poster. Episode numbering still needs TVmaze,
            // which we only take from an exact IMDb id (OMDb), never from a title guess.
            if (($match['source'] ?? '') === 'omdb' && preg_match('/^tt\d+$/', (string)($match['id'] ?? ''))) {
                $hit = self::tvmazeSearch('imdb:' . $match['id']);
                if ($hit) $id = (int)$hit[0]['id'];
            }
            if ($id <= 0) $metaOnly = true;
        }
        $o = self::overrides();
        $targetKey = $showKey;
        $renameTo = trim((string)($b['rename_to'] ?? ''));
        if ($renameTo !== '') {
            $rows = [];
            foreach ($items as $it) $rows[] = ['id' => (string)$it['id'], 'show' => $renameTo];
            self::saveItemFields($rows, 'manual', self::actor());
            $o = self::overrides();
            $targetKey = Catalogue::showKey((string)($items[0]['section'] ?? ''), Catalogue::fixText($renameTo));
        }
        if ($metaOnly) {
            // keep the existing episode-guide pin as it is
            if ($targetKey !== $showKey && isset($o['shows'][$showKey])) $o['shows'][$targetKey] = $o['shows'][$showKey];
        } elseif ($provider === 'clear') {
            unset($o['shows'][$targetKey]);
        } elseif ($provider === 'none') {
            $o['shows'][$targetKey] = ['provider' => 'none', 'at' => date('c'), 'by' => self::actor()];
        } else {
            if ($id <= 0) return ['ok' => false, 'error' => 'tvmaze_id required'];
            $o['shows'][$targetKey] = ['provider' => 'tvmaze', 'tvmaze_id' => $id, 'name' => (string)($b['name'] ?? ''), 'at' => date('c'), 'by' => self::actor()];
        }
        if ($targetKey !== $showKey) unset($o['shows'][$showKey]);
        self::saveOverrides($o);
        MetadataSidecars::invalidateShow($targetKey);
        if ($targetKey !== $showKey) MetadataSidecars::invalidateShow($showKey);
        self::purgeShowCaches($targetKey);
        if ($targetKey !== $showKey) self::purgeShowCaches($showKey);
        if (class_exists('Posters')) {
            if ($provider === 'clear' || $provider === 'none') $match = null;
            $meta = $match ? self::chosenShowMeta($match) : null;
            if ($meta) MetadataPins::put('show_meta', $targetKey, $meta);
            else MetadataPins::clear('show_meta', $targetKey);
        }
        if ($id > 0) self::tvmazeGuideById($id, true);
        self::log('PIN ' . $targetKey . ' provider=' . $provider . ' id=' . $id . ($renameTo !== '' ? ' rename=' . $renameTo : ''));
        $backup = MetadataSidecars::schedule();
        return ['ok' => true, 'show_key' => $targetKey, 'pin' => $o['shows'][$targetKey] ?? null, 'meta_only' => $metaOnly, 'tvmaze_id' => $id ?: null] + (empty($backup['ok']) ? ['warning'=>$backup['warning']] : []);
    }

    public static function purgeShowCaches(string $showKey): void {
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        if ($safe === '') return;
        foreach (['_episodes.json', '_seasons.json', '_miss.json'] as $suf) @unlink(STREAM_DATA . '/tvmaze/show_' . $safe . $suf);
        if (class_exists('Posters')) {
            Posters::clearCachedArtwork(Posters::showCachePath($showKey));
            foreach (glob(Posters::dir() . '/season_' . $safe . '_s*') ?: [] as $f) {
                if (preg_match('/^season_' . $safe . '_s\d+(?:_thumb|_tiny)?\.(?:jpe?g|png|webp)$/D', basename($f))) @unlink($f);
            }
        }
    }

    /* --------------------------------------------------------- shows overview */

    /** All TV/anime shows with match health (problems first). */
    public static function showsOverview(string $q = '', string $filter = 'problems'): array {
        $cat = Catalogue::get();
        $types = self::sectionTypes();
        $o = self::overrides();
        $groups = [];
        foreach ($cat['items'] ?? [] as $it) {
            if (!is_array($it)) continue;
            $type = $it['type'] ?? '';
            if ($type !== 'tv' && $type !== 'anime') continue;
            $sid = (string)($it['section'] ?? '');
            $name = (string)($it['show'] ?? '');
            if ($name === '') {
                $parts = explode('/', str_replace('\\', '/', (string)($it['rel'] ?? '')));
                $name = $parts[0] ?? '';
            }
            $key = Catalogue::showKey($sid, $name);
            if (!isset($groups[$key])) $groups[$key] = ['show_key' => $key, 'title' => $name, 'section' => $sid, 'type' => $type, 'files' => 0, 'numbered' => 0, 'unnumbered' => 0, 'untitled' => 0, 'overrides' => 0];
            $g =& $groups[$key];
            $g['files']++;
            if (($it['season'] ?? null) !== null && ($it['episode'] ?? null) !== null) $g['numbered']++; else $g['unnumbered']++;
            if (empty($it['episode_title']) && (($it['episode'] ?? null) === null)) $g['untitled']++;
            if (isset($o['items'][(string)($it['id'] ?? '')])) $g['overrides']++;
            unset($g);
        }
        $out = [];
        foreach ($groups as $key => $g) {
            if ($q !== '' && stripos($g['title'], $q) === false) continue;
            $safe = preg_replace('/[^a-f0-9]/', '', $key);
            $pin = $o['shows'][$key] ?? null;
            $guideCached = is_file(STREAM_DATA . '/tvmaze/show_' . $safe . '_episodes.json');
            $miss = is_file(STREAM_DATA . '/tvmaze/show_' . $safe . '_miss.json');
            $g['pin'] = $pin;
            $g['guide'] = $pin ? (($pin['provider'] ?? '') === 'none' ? 'none' : 'pinned') : ($guideCached ? 'auto' : ($miss ? 'miss' : 'unknown'));
            $g['problem'] = $g['unnumbered'] > 0 || $g['guide'] === 'miss';
            if ($filter === 'problems' && !$g['problem']) continue;
            $out[] = $g;
        }
        usort($out, function ($a, $b) {
            if ($a['problem'] !== $b['problem']) return $a['problem'] ? -1 : 1;
            if ($a['unnumbered'] !== $b['unnumbered']) return $b['unnumbered'] <=> $a['unnumbered'];
            return strcasecmp($a['title'], $b['title']);
        });
        return ['ok' => true, 'shows' => array_slice($out, 0, 400), 'total' => count($out)];
    }

    /** Detail for the editor: items + current mapping + guide list (for "pick from guide"). */
    public static function showEditor(string $showKey): array {
        $items = self::showItems($showKey);
        if (!$items) return ['ok' => false, 'error' => 'Show not found'];
        $showName = (string)($items[0]['show'] ?? '');
        $guide = self::guideForShow($showKey, $showName, false);
        $o = self::overrides();
        $rows = [];
        foreach ($items as $it) {
            $id = (string)$it['id'];
            $rows[] = [
                'id' => $id,
                'rel' => $it['rel'] ?? '',
                'file' => basename((string)($it['rel'] ?? '')),
                'show' => $it['show'] ?? null,
                'season' => $it['season'] ?? null,
                'episode' => $it['episode'] ?? null,
                'episode_title' => Catalogue::resolveEpisodeTitle($it) ?: null,
                'override' => $o['items'][$id] ?? null,
                'ext' => $it['ext'] ?? null,
                'size' => $it['size'] ?? null,
            ];
        }
        return [
            'ok' => true,
            'show_key' => $showKey,
            'show' => $showName,
            'section' => (string)($items[0]['section'] ?? ''),
            'type' => (string)($items[0]['type'] ?? 'tv'),
            'pin' => self::showPin($showKey),
            'guide' => self::guideSummary($guide),
            'items' => $rows,
            'shows_in_section' => self::sectionShowNames((string)($items[0]['section'] ?? '')),
        ];
    }

    public static function guideSummary(?array $guide): ?array {
        if (!is_array($guide)) return null;
        $eps = [];
        foreach ($guide['episodes'] ?? [] as $e) {
            if (!is_array($e)) continue;
            $eps[] = ['id' => $e['id'] ?? null, 'season' => $e['season'] ?? null, 'number' => $e['number'] ?? null, 'name' => $e['name'] ?? null, 'airdate' => $e['airdate'] ?? null];
        }
        return ['id' => $guide['id'] ?? null, 'name' => $guide['name'] ?? null, 'premiered' => $guide['premiered'] ?? null, 'episodes' => $eps];
    }

    public static function sectionShowNames(string $sid): array {
        $names = [];
        foreach (Catalogue::get()['items'] ?? [] as $it) {
            if (!is_array($it) || ($it['section'] ?? '') !== $sid) continue;
            $n = (string)($it['show'] ?? '');
            if ($n !== '') $names[$n] = true;
        }
        $list = array_keys($names);
        natcasesort($list);
        return array_values($list);
    }

    /** Movie rematch: title/year (+ optional poster from provider). */
    public static function rematchMovie(string $id, array $b): array {
        $b=Permissions::selection($b);
        $cat = Catalogue::get();
        $it = $cat['items'][$id] ?? null;
        if (!$it) return ['ok' => false, 'error' => 'Item not found'];
        $title = trim((string)($b['title'] ?? ''));
        if ($title === '') return ['ok' => false, 'error' => 'title required'];
        $row = ['id' => $id, 'title' => $title, 'year' => $b['year'] ?? null];
        if (!empty($b['imdb_id'])) $row['imdb_id'] = (string)$b['imdb_id'];
        $res = self::saveItemFields([$row], 'manual', self::actor(), false, false);
        if (class_exists('Posters')) {
            $isGame = ($it['media_kind'] ?? '') === 'rom';
            $dest = $isGame ? Posters::gameCachePath($id) : Posters::movieCachePath($id);
            MetadataSidecars::invalidateMovie($it);
            Posters::clearCachedArtwork($dest);
            $synCache = Posters::movieSynopsisCachePath($id);
            @unlink($synCache);
            // The picked result's own synopsis is authoritative. Without this the detail page
            // re-searched Wikipedia by bare title ("Runner" -> the article on running).
            $pinned = self::chosenMovieSynopsis($b, $title, isset($b['year']) && $b['year'] !== '' ? (int)$b['year'] : null);
            if ($pinned !== null) {
                MetadataPins::put('movie_synopsis', $id, $pinned);
                // Plain cache copy (no pin flag) so offline genre guessing still sees the text.
                $copy = $pinned; unset($copy['pinned']);
                @file_put_contents($synCache, json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            }
            else MetadataPins::clear('movie_synopsis', $id);
            // Pin the poster to the same chosen result. If it has no image, no title lookup runs
            // (that is how "Runner" got the photo from the "Running" article).
            $posterPin = self::chosenMoviePoster($b);
            if ($posterPin !== null) MetadataPins::put('movie_poster', $id, $posterPin);
            else MetadataPins::clear('movie_poster', $id);
            $img = trim((string)($b['image'] ?? ''));
            if ($img !== '' && preg_match('#^https://#i', $img)) {
                $bin = self::http($img, 15);
                if ($bin !== null && strlen($bin) > 500) @file_put_contents($dest, $bin, LOCK_EX);
            }
            if ($posterPin !== null && !is_file($dest)) {
                if ($isGame) { $it['title'] = $title; Posters::fetchGamePoster($it); }
                else Posters::fetchMoviePoster($id, $title, null);
            }
        }
        self::log('MOVIE ' . $id . ' => ' . $title . ' (' . ($b['year'] ?? '') . ')');
        $backup = MetadataSidecars::schedule();
        if (empty($backup['ok'])) $res['warning'] = $backup['warning'];
        return $res;
    }

    /**
     * Synopsis cache record built from the exact search result the admin picked
     * (source + id), or null when the pick carried no synopsis. Pinned records are
     * never replaced by a title-based lookup.
     */
    public static function chosenMovieSynopsis(array $b, string $title, ?int $year): ?array {
        $c = is_array($b['match'] ?? null) ? $b['match'] : null;
        if (!$c || empty($c['source']) || !isset($c['id']) || (string)$c['id'] === '') return null;
        $summary = trim((string)($c['summary'] ?? ''));
        if ($summary === '') return null;
        $url = (string)($c['summary_url'] ?? $c['url'] ?? '');
        return [
            'title' => $title, 'year' => $year, 'summary' => function_exists('mb_substr') ? mb_substr($summary, 0, 4000) : $summary,
            'source' => preg_match('#^https://#i', $url) ? $url : ((string)$c['source'] . ':' . (string)$c['id']),
            'provider' => (string)($c['summary_provider'] ?? $c['source']), 'provider_id' => (string)($c['summary_id'] ?? $c['id']),
            'pinned' => true, 'fetched_at' => date('c'),
        ];
    }

    /** Show metadata pin (synopsis + poster) from the exact picked result of any source. */
    public static function chosenShowMeta(array $c): ?array {
        if (empty($c['source']) || !isset($c['id']) || (string)$c['id'] === '') return null;
        $img = trim((string)($c['image'] ?? ''));
        $sum = trim((string)($c['summary'] ?? ''));
        return [
            'provider' => (string)$c['source'], 'provider_id' => (string)$c['id'],
            'summary' => $sum !== '' ? $sum : null,
            'image' => preg_match('#^https://#i', $img) ? $img : null,
            'page_title' => (string)($c['page_title'] ?? '') ?: null,
            'pinned' => true, 'at' => date('c'),
        ];
    }

    /** Poster pin from the exact picked result (its image URL and/or exact Wikipedia page). */
    public static function chosenMoviePoster(array $b): ?array {
        $c = is_array($b['match'] ?? null) ? $b['match'] : null;
        if (!$c || empty($c['source']) || !isset($c['id']) || (string)$c['id'] === '') return null;
        $img = trim((string)($c['image'] ?? ($b['image'] ?? '')));
        return [
            'provider' => (string)$c['source'], 'provider_id' => (string)$c['id'],
            'image' => preg_match('#^https://#i', $img) ? $img : null,
            'page_title' => (string)($c['page_title'] ?? '') ?: null,
            'pinned' => true, 'at' => date('c'),
        ];
    }

    /* ---------------------------------------------------------------- reports */

    public static function reports(): array {
        $r = Store::get(self::REPORTS, []);
        return is_array($r) ? array_values(array_filter($r, 'is_array')) : [];
    }

    public static function openReportCount(): int {
        $n = 0;
        foreach (self::reports() as $r) if (($r['status'] ?? 'open') === 'open') $n++;
        return $n;
    }

    public static function createReport(array $b, array $user): array {
        $itemId = trim((string)($b['item_id'] ?? ''));
        $showKey = trim((string)($b['show_key'] ?? ''));
        $note = mb_substr(trim((string)($b['note'] ?? '')), 0, 500);
        $kind = (string)($b['kind'] ?? '');
        $snap = null;
        if ($itemId !== '') {
            $it = Catalogue::item($itemId);
            if (!$it) return ['ok' => false, 'error' => 'Item not found'];
            $type = (string)($it['type'] ?? '');
            if ($kind === '') $kind = ($type === 'tv' || $type === 'anime') ? 'episode' : 'movie';
            $snap = [
                'title' => Catalogue::publicItem($it, false)['title'] ?? ($it['title'] ?? ''),
                'show' => $it['show'] ?? null, 'season' => $it['season'] ?? null, 'episode' => $it['episode'] ?? null,
                'episode_title' => Catalogue::resolveEpisodeTitle($it) ?: null, 'year' => $it['year'] ?? null,
                'rel' => $it['rel'] ?? null, 'section' => $it['section'] ?? null, 'type' => $type,
            ];
            if ($showKey === '' && !empty($it['show']) && ($type === 'tv' || $type === 'anime')) {
                $showKey = Catalogue::showKey((string)($it['section'] ?? ''), (string)$it['show']);
            }
        } elseif ($showKey !== '') {
            $items = self::showItems($showKey);
            if (!$items) return ['ok' => false, 'error' => 'Show not found'];
            $kind = 'show';
            $snap = ['title' => $items[0]['show'] ?? '', 'section' => $items[0]['section'] ?? null, 'type' => $items[0]['type'] ?? 'tv', 'files' => count($items)];
        } else {
            return ['ok' => false, 'error' => 'item_id or show_key required'];
        }
        $all = self::reports();
        // de-dupe open report for same target
        foreach ($all as &$r) {
            if (($r['status'] ?? 'open') === 'open' && ($r['item_id'] ?? '') === $itemId && ($r['show_key'] ?? '') === $showKey) {
                $r['count'] = (int)($r['count'] ?? 1) + 1;
                if ($note !== '') $r['notes'][] = ['by' => (string)($user['username'] ?? 'guest'), 'note' => $note, 'at' => date('c')];
                $r['updated_at'] = date('c');
                unset($r);
                Store::set(self::REPORTS, $all);
                return ['ok' => true, 'deduped' => true];
            }
        }
        unset($r);
        $rep = [
            'id' => substr(sha1(uniqid('rep', true)), 0, 12),
            'kind' => in_array($kind, ['episode', 'movie', 'show', 'anime'], true) ? $kind : 'episode',
            'item_id' => $itemId,
            'show_key' => $showKey,
            'snapshot' => $snap,
            'note' => $note,
            'suggested' => is_array($b['suggested'] ?? null) ? array_intersect_key($b['suggested'], array_flip(['title', 'season', 'episode', 'year', 'show'])) : null,
            'reporter' => (string)($user['username'] ?? 'guest'),
            'status' => 'open',
            'count' => 1,
            'notes' => [],
            'created_at' => date('c'),
        ];
        $all[] = $rep;
        if (count($all) > 500) $all = array_slice($all, -400);
        Store::set(self::REPORTS, $all);
        self::log('REPORT ' . $rep['id'] . ' ' . $rep['kind'] . ' by ' . $rep['reporter']);
        return ['ok' => true, 'report' => ['id' => $rep['id']]];
    }

    public static function listReports(string $status = 'open'): array {
        $out = [];
        foreach (array_reverse(self::reports()) as $r) {
            if ($status !== 'all' && ($r['status'] ?? 'open') !== $status) continue;
            $cur = null;
            if (!empty($r['item_id'])) {
                $it = Catalogue::item((string)$r['item_id']);
                if ($it) $cur = ['title' => Catalogue::publicItem($it, false)['title'] ?? '', 'show' => $it['show'] ?? null, 'season' => $it['season'] ?? null, 'episode' => $it['episode'] ?? null, 'episode_title' => Catalogue::resolveEpisodeTitle($it) ?: null, 'year' => $it['year'] ?? null, 'type' => $it['type'] ?? null, 'section' => $it['section'] ?? null, 'rel' => $it['rel'] ?? null];
            }
            $r['current'] = $cur;
            $out[] = $r;
        }
        return ['ok' => true, 'reports' => $out, 'open' => self::openReportCount()];
    }

    public static function updateReport(string $id, array $b): array {
        $all = self::reports();
        $hit = null;
        foreach ($all as &$r) {
            if (($r['id'] ?? '') !== $id) continue;
            $st = (string)($b['status'] ?? '');
            if (in_array($st, ['open', 'resolved', 'dismissed'], true)) $r['status'] = $st;
            if ($st !== 'open') { $r['resolved_at'] = date('c'); $r['resolved_by'] = self::actor(); }
            if (!empty($b['resolution'])) $r['resolution'] = mb_substr((string)$b['resolution'], 0, 300);
            $hit = $r;
        }
        unset($r);
        if (!$hit) return ['ok' => false, 'error' => 'Report not found'];
        Store::set(self::REPORTS, $all);
        return ['ok' => true, 'report' => $hit, 'open' => self::openReportCount()];
    }

    /* ------------------------------------------------------------------ misc */

    public static function actor(): string {
        try {
            $s = Auth::currentSession();
            if ($s) {
                $u = Auth::findById($s['user_id']);
                if ($u) return (string)($u['username'] ?? 'admin');
            }
        } catch (Throwable $e) {}
        return PHP_SAPI === 'cli' ? 'cli' : 'admin';
    }

    public static function log(string $msg): void {
        $f = STREAM_DATA . '/matching.log';
        @file_put_contents($f, date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        if ((@filesize($f) ?: 0) > 400000) {
            $lines = @file($f);
            if (is_array($lines)) @file_put_contents($f, implode('', array_slice($lines, -1500)), LOCK_EX);
        }
    }
}
