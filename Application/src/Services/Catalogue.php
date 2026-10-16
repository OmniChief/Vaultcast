<?php
declare(strict_types=1);

class Catalogue {
    private static $romSystems = [
        'nes' => 'nes', 'fds' => 'nes',
        'sfc' => 'snes', 'smc' => 'snes',
        'n64' => 'n64', 'z64' => 'n64', 'v64' => 'n64',
        'gb' => 'gb', 'gbc' => 'gb', 'gba' => 'gba',
        'nds' => 'nds', '3ds' => '3ds',
        'sms' => 'sms', 'gg' => 'segaGG',
        'md' => 'segaMD', 'gen' => 'segaMD', 'smd' => 'segaMD',
        'pce' => 'pce',
        /* Ambiguous: .bin/.iso/.cue often Mega Drive or other; folder heuristics win first. */
        'psx' => 'psx', 'cue' => 'psx', 'bin' => 'psx', 'img' => 'psx', 'pbp' => 'psx', 'chd' => 'psx',
        'iso' => 'psx',
        'zip' => null, '7z' => null, 'rar' => null,
    ];

    public static function get(): array {
        $c = Store::get('catalogue', ['sections' => [], 'items' => [], 'scanned_at' => null]);
        return is_array($c) ? $c : ['sections' => [], 'items' => [], 'scanned_at' => null];
    }

    public static function save(array $cat): bool {
        $ok = Store::set('catalogue', $cat);
        if ($ok) self::invalidateDerivedCaches();
        return $ok;
    }

    /** drop SHM caches derived from catalogue (home, shows, browse, facets, showcase). */
    public static function invalidateDerivedCaches(): void {
        \Vaultcast\Repository::putDocument('browse_epoch', time());
        $dir = defined('STREAM_SHM') ? STREAM_SHM : '/dev/shm/vaultcast';
        if (!is_dir($dir)) return;
        $prefixes = [
            'section_shows_', 'home_cache_', 'browse_items_', 'browse_shows_',
            'facets_movies_', 'facets_shows_', 'showcase_random_', 'showcase_recent_',
        ];
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            if ($f === 'home_cache.json') { @unlink($dir . '/' . $f); continue; }
            foreach ($prefixes as $p) {
                if (strpos($f, $p) === 0) { @unlink($dir . '/' . $f); break; }
            }
        }
    }

    /** Shared MariaDB cache, invalidated by catalogue and metadata revisions. */
    private static function shmCacheGet(string $filePrefix, string $salt = ''): ?array {
        return \Vaultcast\Repository::cacheGet('browse|' . Store::revision('catalogue') . '|' . Store::revision('browse_epoch') . '|' . $filePrefix . '|' . $salt);
    }
    private static function shmCachePut(string $filePrefix, string $salt, array $payload): void {
        \Vaultcast\Repository::cachePut('browse|' . Store::revision('catalogue') . '|' . Store::revision('browse_epoch') . '|' . $filePrefix . '|' . $salt, $payload, 3600);
    }

    /** Public wrappers for browse/showcase SHM cache (index.php). */
    public static function browseCacheGet(string $filePrefix, string $salt = ''): ?array {
        return self::shmCacheGet($filePrefix, $salt);
    }
    public static function browseCachePut(string $filePrefix, string $salt, array $payload): void {
        self::shmCachePut($filePrefix, $salt, $payload);
    }

    /** Cached movie facets for a section (synopsis genre guess is disk-heavy). */
    public static function movieFacetsCached(string $sectionId, array $items): array {
        $pack = self::movieFacetPack($sectionId, $items);
        return $pack['facets'];
    }

    /**
     * facets + per-item genre map in one SHM pack.
     * filterMovieItems uses the map so genre filters do not re-read synopsis files.
     * @return array{facets:array, genres_by_id:array<string,list<string>>}
     */
    public static function movieFacetPack(string $sectionId, array $items): array {
        $hit = self::shmCacheGet('facets_movies', $sectionId);
        if (is_array($hit) && isset($hit['facets']) && is_array($hit['facets']) && isset($hit['genres_by_id']) && is_array($hit['genres_by_id'])) {
            return ['facets' => $hit['facets'], 'genres_by_id' => $hit['genres_by_id']];
        }
        $years = [];
        $decades = [];
        $genres = [];
        $byId = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $id = (string)($it['id'] ?? '');
            $y = isset($it['year']) ? (int)$it['year'] : 0;
            if ($y >= 1900 && $y <= 2100) {
                $years[(string)$y] = ($years[(string)$y] ?? 0) + 1;
                $d = (int)(floor($y / 10) * 10);
                $dk = $d . 's';
                $decades[$dk] = ($decades[$dk] ?? 0) + 1;
            }
            $gs = self::guessMovieGenres($id, (string)($it['title'] ?? ''));
            if ($id !== '') $byId[$id] = $gs;
            foreach ($gs as $g) {
                $genres[$g] = ($genres[$g] ?? 0) + 1;
            }
        }
        krsort($years, SORT_NATURAL);
        uksort($decades, function ($a, $b) { return (int)$b <=> (int)$a; });
        arsort($genres);
        $facets = ['years' => $years, 'decades' => $decades, 'genres' => $genres];
        self::shmCachePut('facets_movies', $sectionId, ['facets' => $facets, 'genres_by_id' => $byId]);
        return ['facets' => $facets, 'genres_by_id' => $byId];
    }

    /** Resolve genres for an item using SHM pack when available. */
    public static function movieGenresForItem(string $sectionId, array $it, ?array $pack = null): array {
        $id = (string)($it['id'] ?? '');
        if ($pack === null && $sectionId !== '') {
            $hit = self::shmCacheGet('facets_movies', $sectionId);
            if (is_array($hit) && isset($hit['genres_by_id']) && is_array($hit['genres_by_id'])) {
                $pack = $hit;
            }
        }
        if (is_array($pack) && $id !== '' && isset($pack['genres_by_id'][$id]) && is_array($pack['genres_by_id'][$id])) {
            return array_values(array_map('strval', $pack['genres_by_id'][$id]));
        }
        return self::guessMovieGenres($id, (string)($it['title'] ?? ''));
    }

    public static function showFacetsCached(string $sectionId, array $shows): array {
        $hit = self::shmCacheGet('facets_shows', $sectionId);
        if (is_array($hit) && isset($hit['facets']) && is_array($hit['facets'])) {
            return $hit['facets'];
        }
        $facets = self::showFacets($shows);
        self::shmCachePut('facets_shows', $sectionId, ['facets' => $facets]);
        return $facets;
    }

    /** Stable URL-safe key for a show within a section. */
    public static function showKey(string $sectionId, string $showName): string {
        return substr(sha1($sectionId . '|' . $showName), 0, 16);
    }

    /** Repair UTF-8 em/en dash / ellipsis mojibake; trim trailing orphan separators. */
    public static function fixText($s): string {
        if ($s === null || $s === '') return '';
        $s = (string)$s;
        static $map = null;
        if ($map === null) {
            $map = [
                "\xC3\x94\xC3\x87\xC3\xB6" => "\xE2\x80\x94",
                "\xC3\x94\xC3\x87\xC3\xB4" => "\xE2\x80\x93",
                "\xC3\x94\xC3\x87\xC3\xA6" => "\xE2\x80\xA6",
            ];
        }
        $s = strtr($s, $map);
        $s = preg_replace('/(?:\s*[\x{2014}\x{2013}\-]\s*)+$/u', '', $s);
        if ($s === null) $s = '';
        $s = preg_replace('/\s{2,}/u', ' ', $s);
        if ($s === null) $s = '';
        return trim($s);
    }

    /**
     * Parse season/episode from relative path when SxxExx is absent.
     * Supports Season N / S0N / Specials folders and leading "01 Title" episode files.
     * @return array{season:?int,episode:?int}
     */
    public static function seasonEpisodeFromRel(string $rel): array {
        $rel = str_replace('\\', '/', $rel);
        $season = null;
        $episode = null;
        $parts = explode('/', $rel);
        $last = count($parts) - 1;
        foreach ($parts as $i => $folder) {
            if ($i === 0 || $i === $last) continue;
            if (preg_match('/^(?:Season|Series|Saison|Staffel|Temporada)[\s._-]*0*(\d{1,4})\b/i', $folder, $m)) {
                $season = (int)$m[1];
                break;
            }
            if (preg_match('/^S0*(\d{1,2})$/i', $folder, $m)) {
                $season = (int)$m[1];
                break;
            }
            if (preg_match('/^Specials?$/i', $folder)) {
                $season = 0;
                break;
            }
        }
        $base = preg_replace('/\.[^.]+$/', '', basename($rel));
        $code = self::episodeCodeFromName((string)$base, $season !== null);
        if ($code['explicit']) {
            $season = $code['season'];
            $episode = $code['episode'];
        } elseif ($code['episode'] !== null) {
            $episode = $code['episode'];
        }
        return ['season' => $season, 'episode' => $episode];
    }

    /**
     * episode code from a file basename.
     * explicit=true when the name carries both season and episode (S01E02, S01.E02, 1x02, Season 1 Episode 2).
     * Otherwise episode may come from "E05", "Episode 5", anime "Show - 05", a leading "01 - Title", or "Part 1 of 10".
     * @return array{season:?int,episode:?int,explicit:bool,method:string}
     */
    public static function episodeCodeFromName(string $base, bool $inSeasonFolder = false): array {
        $out = ['season' => null, 'episode' => null, 'explicit' => false, 'method' => ''];
        if ($base === '') return $out;
        if (preg_match('/(?<![A-Za-z0-9])[Ss](\d{1,2})[\s._-]?[Ee](\d{1,4})(?!\d)/', $base, $m)) {
            return ['season' => (int)$m[1], 'episode' => (int)$m[2], 'explicit' => true, 'method' => 'sxe'];
        }
        if (preg_match('/(?<![0-9A-Za-z])(\d{1,2})[xX](\d{2,3})(?![0-9])/', $base, $m)) {
            return ['season' => (int)$m[1], 'episode' => (int)$m[2], 'explicit' => true, 'method' => 'nxn'];
        }
        if (preg_match('/\b(?:Season|Series)[\s._-]*(\d{1,2})[\s._-]*(?:Episode|Ep)[\s._-]*(\d{1,4})\b/i', $base, $m)) {
            return ['season' => (int)$m[1], 'episode' => (int)$m[2], 'explicit' => true, 'method' => 'words'];
        }
        if (preg_match('/(?:^|[\s._\-\[(])(?:Episode|Ep)[\s._]?0*(\d{1,4})(?=$|[\s._\-\])v])/i', $base, $m)
            || preg_match('/(?:^|[\s._\-\[(])E0*(\d{1,4})(?=$|[\s._\-\])v])/', $base, $m)) {
            $out['episode'] = (int)$m[1];
            $out['method'] = 'ep';
            return $out;
        }
        // Anime / fansub: "[Group] Show - 05 (1080p)" or "Show - 05v2"
        if (preg_match('/\s[-\x{2013}]\s0*(\d{1,4})(?:v\d)?(?=$|\s|\[|\()/u', $base, $m)) {
            $n = (int)$m[1];
            $raw = $m[1];
            if (!(strlen($raw) === 4 && $n >= 1900 && $n <= 2099)) {
                $out['episode'] = $n;
                $out['method'] = 'dash';
                return $out;
            }
        }
        // Leading "01 - Title" / "01. Title" / "1_Title" (never "1-900-Beavis" or "24 Hour ...")
        if (preg_match('/^0*(\d{1,3})\s*[-._]\s*(?=[^\d\s])/', $base, $m)
            || preg_match('/^(0\d{1,2})[\s._-]+/', $base, $m)
            || preg_match('/^(\d{1,3})[\s._-]+(?=(?:19|20)\d{2}[-._]\d{2}[-._]\d{2})/', $base, $m)
            || ($inSeasonFolder && preg_match('/^(\d{1,3})\s+(?=[A-Za-z(\[])/', $base, $m))) {
            $out['episode'] = (int)$m[1];
            $out['method'] = 'leading';
            return $out;
        }
        if (preg_match('/(?<![0-9])(\d{1,2})\s*of\s*(\d{1,2})(?![0-9])/i', $base, $m) && (int)$m[1] >= 1 && (int)$m[1] <= (int)$m[2]) {
            $out['episode'] = (int)$m[1];
            $out['method'] = 'part';
            return $out;
        }
        return $out;
    }

    /** does a basename carry an explicit season+episode code? */
    public static function hasExplicitEpisodeCode(string $base): bool {
        $c = self::episodeCodeFromName($base);
        return !empty($c['explicit']);
    }

    /**
     * human episode label from folders + file name when the file has no SxxExx code.
     * "(2006) No-Laughing Police Station/DVD1/x.avi" -> "No-Laughing Police Station (2006) — Disc 1".
     * "Season 2/Bad Dog.avi" -> "Bad Dog".
     */
    public static function localEpisodeLabel(string $rel, string $show = ''): string {
        $rel = str_replace('\\', '/', $rel);
        $parts = explode('/', $rel);
        if (count($parts) < 2) return '';
        $file = array_pop($parts);
        array_shift($parts);
        $base = (string)preg_replace('/\.[^.]+$/', '', $file);
        $event = '';
        $year = null;
        $subs = [];
        $extra = '';
        foreach ($parts as $f) {
            if (preg_match('/^(?:Season|Series|Saison|Staffel|Temporada)[\s._-]*\d+/i', $f) || preg_match('/^S\d{1,2}$/i', $f) || preg_match('/^Specials?$/i', $f)) continue;
            if ($event === '' && preg_match('/^\((\d{4})\)\s*(.+)$/', $f, $m)) { $year = (int)$m[1]; $event = trim($m[2]); continue; }
            if ($event === '' && preg_match('/^(.+?)\s*\((\d{4})\)$/', $f, $m)) { $year = (int)$m[2]; $event = trim($m[1]); continue; }
            if (preg_match('/^(?:DVD|Disc|Disk|CD)[\s._-]*0*(\d{1,2})$/i', $f, $m)) { $subs[] = 'Disc ' . (int)$m[1]; continue; }
            if (preg_match('/^(Extras?|Bonus|Unseen|Featurettes?|Behind The Scenes|Deleted Scenes)$/i', $f, $m)) { $extra = ucwords(strtolower($m[1])); continue; }
        }
        $inSeason = false;
        foreach ($parts as $f) {
            if (preg_match('/^(?:Season|Series|Saison|Staffel|Temporada)[\s._-]*\d+/i', $f) || preg_match('/^S\d{1,2}$/i', $f)) { $inSeason = true; break; }
        }
        $lc = self::episodeCodeFromName($base, $inSeason);
        if ($lc['method'] === 'leading') {
            $stripped = (string)preg_replace('/^0*\d{1,3}(?:\s*[-._]\s*|[\s._-]+)/', '', $base, 1);
            if (trim($stripped) !== '') $base = $stripped;
        }
        $clean = self::cleanFileTitle($base, $show);
        if ($event !== '') {
            $event = trim((string)preg_replace('/\s*\([^)]*\)\s*/', ' ', $event));
            $bits = [$event . ($year ? ' (' . $year . ')' : '')];
            foreach ($subs as $sb) $bits[] = $sb;
            if (preg_match('/(?<![0-9])(\d{1,2})\s*of\s*(\d{1,2})(?![0-9])/i', $base, $pm)) {
                $bits[] = 'Part ' . (int)$pm[1] . ' of ' . (int)$pm[2];
            }
            if ($extra !== '') $bits[] = $extra . ($clean !== '' ? ': ' . $clean : '');
            return self::fixText(implode(" \xE2\x80\x94 ", $bits));
        }
        if ($clean === '') return '';
        $bits = [$clean];
        foreach ($subs as $sb) $bits[] = $sb;
        if ($extra !== '') $bits[0] = $extra . ': ' . $clean;
        return self::fixText(implode(" \xE2\x80\x94 ", $bits));
    }

    /** clean a file basename into a title (release tags only stripped when it looks like a release). */
    public static function cleanFileTitle(string $base, string $show = ''): string {
        $orig = $base;
        $hadBrackets = (bool)preg_match('/\[[^\]]*\]|\{[^}]*\}/', $base);
        $s = (string)preg_replace('/\[[^\]]*\]|\{[^}]*\}/', ' ', $base);
        $noSpaces = strpos(trim($orig), ' ') === false;
        if (($noSpaces || substr_count($s, '.') >= 3) && preg_match('/[A-Za-z0-9]{2,}\.[A-Za-z0-9]{2,}/', $s)) $s = str_replace('.', ' ', $s);
        $s = str_replace('_', ' ', $s);
        $looksRelease = (bool)preg_match('/\b(?:480p|576p|720p|1080p|2160p|x26[45]|h\.?26[45]|hevc|xvid|divx|web-?dl|webrip|bluray|bdrip|brrip|hdtv|dvdrip|aac|ac3|10bit)\b/i', $s);
        if ($looksRelease) $s = self::stripReleaseTags($s);
        $s = (string)preg_replace('/\b(?:hardcoded|subbed|fixed subs|eng subs?)\b/i', ' ', $s);
        $s = (string)preg_replace('/\(\s*(?:incomplete subs|subbed)\s*\)/i', ' ', $s);
        $s = (string)preg_replace('/(?<![0-9])\d{1,2}\s*of\s*\d{1,2}(?![0-9])/i', ' ', $s);
        $s = trim((string)preg_replace('/\s{2,}/', ' ', $s), " \t-.\xE2\x80\x94");
        $show = self::fixText($show);
        if ($show !== '' && $s !== '') {
            $allow = $hadBrackets || $looksRelease || $noSpaces;
            $stripped = self::stripShowPrefix($s, $show, $allow);
            if ($stripped !== null) $s = $stripped;
        }
        $s = self::fixText($s);
        if ($s !== '' && $show !== '' && strcasecmp($s, $show) === 0) return '';
        if (preg_match('/^\d{1,4}$/', $s)) return '';
        return $s;
    }

    /** Remove a leading show-name prefix ("Gaki no Tsukai - X", "Downtown Gaki no Tsukai X"). Null when untouched. */
    private static function stripShowPrefix(string $s, string $show, bool $allowLoose): ?string {
        $norm = function ($x) { return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $x)); };
        $showToks = array_values(array_filter(array_map($norm, preg_split('/\s+/', $show) ?: [])));
        if (count($showToks) < 1) return null;
        if (!preg_match_all('/\S+/', $s, $mm, PREG_OFFSET_CAPTURE)) return null;
        $toks = $mm[0];
        $n = count($toks);
        $maxStart = min(2, $n - 1);
        for ($i = 0; $i <= $maxStart; $i++) {
            for ($k = count($showToks); $k >= min(2, count($showToks)); $k--) {
                $joined = implode('', array_slice($showToks, 0, $k));
                $endTok = null;
                if ($norm($toks[$i][0]) === $joined && $joined !== '') {
                    $endTok = $i;
                } else {
                    $ok = true;
                    for ($j = 0; $j < $k; $j++) {
                        if (!isset($toks[$i + $j]) || $norm($toks[$i + $j][0]) !== $showToks[$j]) { $ok = false; break; }
                    }
                    if ($ok) $endTok = $i + $k - 1;
                }
                if ($endTok === null) continue;
                $after = substr($s, $toks[$endTok][1] + strlen($toks[$endTok][0]));
                $sepFollows = (bool)preg_match('/^\s*[-\x{2013}\x{2014}:|]/u', $after);
                if (!$allowLoose && !$sepFollows) return null;
                $rest = trim((string)preg_replace('/^\s*[-\x{2013}\x{2014}:|!]+\s*/u', '', $after));
                return $rest !== '' ? $rest : null;
            }
        }
        return null;
    }

    /** Show root directory for a TV/anime item. */
    public static function showDirFromItem(array $item, bool $checkDisk = true): ?string {
        if (class_exists('Posters')) {
            return Posters::showDirFromItem($item, $checkDisk);
        }
        return null;
    }

    /** Enrich missing season/episode/show_poster/season_poster from disk + path. */
    public static function enrichItemArt(array $it, bool $checkDisk = true): array {
        $type = $it['type'] ?? '';
        if ($type !== 'tv' && $type !== 'anime') return $it;
        $rel = (string)($it['rel'] ?? '');
        if (($it['season'] ?? null) === null || ($it['episode'] ?? null) === null) {
            $parsed = self::seasonEpisodeFromRel($rel);
            if (($it['season'] ?? null) === null && $parsed['season'] !== null) {
                $it['season'] = $parsed['season'];
            }
            if (($it['episode'] ?? null) === null && $parsed['episode'] !== null) {
                $it['episode'] = $parsed['episode'];
            }
        }
        if (!$checkDisk) return $it; // request path: art paths come from the scan payload
        $showDir = self::showDirFromItem($it);
        if ($showDir && class_exists('Posters')) {
            if (empty($it['show_poster']) || !is_file((string)$it['show_poster'])) {
                $sp = Posters::findLocalShowArt($showDir);
                if ($sp) $it['show_poster'] = $sp;
            }
            $season = array_key_exists('season', $it) && $it['season'] !== null && $it['season'] !== ''
                ? (int)$it['season'] : null;
            if ($season !== null) {
                if (empty($it['season_poster']) || !is_file((string)$it['season_poster'])) {
                    $ssp = Posters::findLocalSeasonArt($showDir, $season);
                    if ($ssp) $it['season_poster'] = $ssp;
                }
            }
        }
        return $it;
    }


    public static function rescan(): array {
        $settings = Store::settings();
        $sections = $settings['sections'] ?? [];
        $scan = $settings['scan'] ?? [];
        $videoExt = array_map('strtolower', $scan['video_extensions'] ?? ['mp4','mkv','avi','m4v','webm']);
        $audioExt = array_map('strtolower', $scan['audio_extensions'] ?? ['mp3','flac','aac']);
        $romExt = array_map('strtolower', $scan['rom_extensions'] ?? []);
        $maxDepth = (int)($scan['max_depth'] ?? 8);

        $items = [];
        $sectionMeta = [];
        $idCounter = 0;

        foreach ($sections as $sec) {
            if (empty($sec['enabled'])) continue;
            $path = $sec['path'] ?? '';
            if ($path === '' || !is_dir($path)) {
                $sectionMeta[] = [
                    'id' => $sec['id'],
                    'label' => $sec['label'] ?? $sec['id'],
                    'type' => $sec['type'] ?? 'movies',
                    'count' => 0,
                    'error' => 'Path missing',
                ];
                continue;
            }
            $exclude = $sec['exclude_paths'] ?? [];
            $type = $sec['type'] ?? 'movies';
            $found = self::scanPath($path, $type, $sec['id'], $videoExt, $audioExt, $romExt, $maxDepth, $exclude, $idCounter);
            foreach ($found as $it) {
                $items[$it['id']] = $it;
            }
            $showCount = null;
            if ($type === 'tv' || $type === 'anime') {
                $names = [];
                foreach ($found as $it) {
                    if (!empty($it['show'])) $names[$it['show']] = true;
                }
                $showCount = count($names);
            }
            $sectionMeta[] = [
                'id' => $sec['id'],
                'label' => $sec['label'] ?? $sec['id'],
                'type' => $type,
                'path' => $path,
                'order' => $sec['order'] ?? 0,
                'count' => count($found),
                'show_count' => $showCount,
            ];
        }

        usort($sectionMeta, function ($a, $b) {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        $cat = [
            'sections' => $sectionMeta,
            'items' => $items,
            'scanned_at' => date('c'),
            'item_count' => count($items),
        ];
        Maintenance::checkpoint();
        self::save($cat);
        Store::invalidate('catalogue'); self::invalidateDerivedCaches();
        return [
            'ok' => true,
            'scanned_at' => $cat['scanned_at'],
            'item_count' => $cat['item_count'],
            'sections' => $sectionMeta,
        ];
    }

    private static function scanPath(
        string $root,
        string $type,
        string $sectionId,
        array $videoExt,
        array $audioExt,
        array $romExt,
        int $maxDepth,
        array $exclude,
        int &$idCounter
    ): array {
        $out = [];
        $rootReal = \Vaultcast\Paths::real($root);
        if ($rootReal === false) return $out;

        $excludeReals = [];
        foreach ($exclude as $ex) {
            $r = \Vaultcast\Paths::real($ex);
            if ($r) $excludeReals[] = $r;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $iterator->setMaxDepth($maxDepth);

        $allowed = $type === 'music' ? $audioExt : $videoExt;
        if ($type === 'roms') {
            // accept PS1 disc images; catalogue prefers .bin over sibling .cue (EmulatorJS needs .bin only).
            $romExt = array_values(array_unique(array_merge($romExt, ['cue', 'img', 'iso', 'bin', 'chd', 'pbp', 'psx'])));
            $allowed = $romExt;
        }

        foreach ($iterator as $file) {
            Maintenance::checkpoint();
            /** @var SplFileInfo $file */
            if (!$file->isFile()) continue;
            $full = str_replace('\\', '/', $file->getPathname());
            $skip = false;
            foreach ($excludeReals as $ex) {
                if ($full === $ex || strpos($full, rtrim($ex, '/') . '/') === 0) { $skip = true; break; }
            }
            if ($skip) continue;

            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $allowed, true)) continue;
            // EmulatorJS only needs the .bin for PS1 — ignore sibling .cue (Mega Drive also uses .bin).
            if ($type === 'roms' && $ext === 'cue') {
                $binSibling = preg_replace('/\.cue$/i', '.bin', $full);
                if ($binSibling && is_file($binSibling)) continue;
            }

            $rel = substr($full, strlen($rootReal) + 1);
            $rel = str_replace('\\', '/', $rel);
            $idCounter++;
            $id = substr(sha1($sectionId . '|' . $rel), 0, 16);

            $item = self::buildItem($full, $rel, $rootReal, $sectionId, $type, $videoExt, $audioExt, (int)$file->getSize(), (int)$file->getMTime());
            $out[] = $item;
        }
        return $out;
    }


    /**
     * one catalogue item from a file (shared by rescan, single-file ingest and re-parse).
     * Applies saved metadata overrides (Matching) last so manual / matched labels survive rescans.
     */
    public static function buildItem(string $full, string $rel, string $rootReal, string $sectionId, string $type, array $videoExt, array $audioExt, ?int $size = null, ?int $mtime = null): array {
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $id = substr(sha1($sectionId . '|' . $rel), 0, 16);
        $title = self::guessTitle($rel, $type);
        $episodeTitle = $title['episode_title'] ?? null;
        if (($type === 'tv' || $type === 'anime') && $full) {
            $nfoTitle = self::readEpisodeNfoTitle($full);
            if ($nfoTitle !== '') {
                $episodeTitle = $nfoTitle;
                $title['title'] = self::formatEpisodeDisplayTitle(
                    $title['show'] ?? null,
                    $title['season'] ?? null,
                    $title['episode'] ?? null,
                    $nfoTitle
                );
            }
        }
        $item = [
            'id' => $id,
            'section' => $sectionId,
            'type' => $type,
            'title' => self::fixText((string)$title['title']),
            'year' => $title['year'],
            'season' => $title['season'],
            'episode' => $title['episode'],
            'show' => $title['show'],
            'episode_title' => $episodeTitle ? self::fixText((string)$episodeTitle) : null,
            'path' => $full,
            'rel' => $rel,
            'ext' => $ext,
            'size' => $size !== null ? $size : (@filesize($full) ?: 0),
            'mtime' => $mtime !== null ? $mtime : (@filemtime($full) ?: time()),
            'media_kind' => in_array($ext, $videoExt, true) ? 'video'
                : (in_array($ext, $audioExt, true) ? 'audio' : 'rom'),
        ];
        if ($item['media_kind'] === 'rom') {
            $item['emu_core'] = self::guessEmuCore($ext, $rel, $full);
            $item['system'] = self::guessSystemFolder($rel);
        }
        $poster = self::findPoster(dirname($full));
        if (!$poster && ($type === 'tv' || $type === 'anime') && !empty($title['show'])) {
            $parts = explode('/', $rel);
            $showRoot = $rootReal . '/' . $parts[0];
            $poster = self::findPoster($showRoot);
            if ($poster) $item['show_poster'] = $poster;
        }
        if ($poster) $item['poster'] = $poster;
        if (($type === 'tv' || $type === 'anime') && !empty($title['show'])) {
            $parts = explode('/', $rel);
            $showRoot = $rootReal . '/' . $parts[0];
            $sp = self::findPoster($showRoot);
            if ($sp) $item['show_poster'] = $sp;
            if ($title['season'] !== null && class_exists('Posters')) {
                $ssp = Posters::findLocalSeasonArt($showRoot, (int)$title['season']);
                if ($ssp) $item['season_poster'] = $ssp;
            }
        }
        if (class_exists('Matching')) {
            $item = Matching::applyOverride($item);
        }
        return $item;
    }

    /**
     * Format TV display title: Show — SxxExx — Episode Name (no release tags).
     */
    public static function formatEpisodeDisplayTitle(?string $show, $season, $episode, ?string $episodeTitle): string {
        $em = "\xE2\x80\x94";
        $bits = [];
        $show = self::fixText((string)$show);
        if ($show !== '') $bits[] = $show;
        if ($season !== null && $season !== '' && $episode !== null && $episode !== '') {
            $bits[] = sprintf('S%02dE%02d', (int)$season, (int)$episode);
        }
        $et = self::fixText((string)$episodeTitle);
        if ($et !== '' && strcasecmp($et, $show) !== 0) $bits[] = $et;
        return self::fixText(implode(' ' . $em . ' ', $bits));
    }

    /** Strip codec/source/release-group junk from a parsed episode name. */
    public static function stripReleaseTags(string $s): string {
        $s = preg_replace('/\[.*?\]/', ' ', $s);
        $s = preg_replace('/\{.*?\}/', ' ', $s);
        $s = preg_replace('/\((?:\d{3,4}p|WEB|BluRay|HDTV|UHD|HDR|DSNP|AMZN|NF|Hulu|HMAX|DDP?|AAC|x264|x265|H\.?26[45]|HEVC)[^)]*\)/i', ' ', $s);
        $s = preg_replace('/\([^)]*\b(?:WEB-?DL|WEBRip|BluRay|HDTV|Remux|Proper|Repack)\b[^)]*\)/i', ' ', $s);
        $tags = '1080p|720p|2160p|480p|4k|uhd|hdr10|hdr|dolby|vision|\bdv\b|bluray|blu-ray|bdrip|brrip|dvdrip|dvdscr|webrip|web-dl|webdl|hdtv|hdrip|x264|x265|h264|h265|hevc|avc|xvid|divx|aac(?:\d*(?:[\. ]?\d)?)?|dts(?:-?hd)?|truehd|atmos|eac3|dd(?:p)?(?:5[\. ]1)?|ac3|10bit|8bit|yify|yts(?:\.\w+)?|rarbg|imax|remux|proper|repack|extended|uncut|multi|dual|subs?|internal|amzn|nf|hulu|dsnp|hmax|web|readnfo|nfo|sample|ita|eng|mez|mem\.?gp';
        $s = preg_replace('/\b(?:' . $tags . ')\b/i', ' ', $s);
        $s = preg_replace('/\s*[-–]\s*[A-Za-z0-9][A-Za-z0-9._]{1,30}\s*$/', '', $s);
        $s = preg_replace('/\b(?:ITA|ENG|FRE|GER|SPA|JPN)(?:[\s._-]+(?:ITA|ENG|FRE|GER|SPA|JPN))*\b/i', ' ', $s);
        $s = preg_replace('/\b[2567][\. ][01]\b/', ' ', $s); // audio channels only (5.1 / 2.0) - keep "Part 3" / "Rocky 2"
        return trim(preg_replace('/[\s._-]+/', ' ', (string)$s));
    }

    /** Read Kodi/Sonarr episode NFO <title> beside a video file. */
    public static function readEpisodeNfoTitle(string $videoPath): string {
        if ($videoPath === '') return '';
        $nfo = preg_replace('/\.[^.\/]+$/', '.nfo', $videoPath);
        if (!is_string($nfo) || $nfo === '' || $nfo === $videoPath || !is_file($nfo)) return '';
        $size = @filesize($nfo);
        if ($size === false || $size < 20 || $size > 512000) return '';
        $raw = @file_get_contents($nfo);
        if ($raw === false || $raw === '') return '';
        $isEpisode = (stripos($raw, '<episodedetails') !== false)
            || (stripos($raw, '<episode>') !== false && stripos($raw, '<season>') !== false);
        if (!$isEpisode) return '';
        if (!preg_match('/<title>(.*?)<\/title>/is', $raw, $m)) return '';
        $t = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return self::fixText($t);
    }

    /**
     * Episode name from relative path / constructed title (never raw release basename).
     */
    public static function parseEpisodeNameFromRel(string $rel, string $show = '', string $titleHint = ''): string {
        $rel = str_replace('\\', '/', $rel);
        $base = preg_replace('/\.[^.]+$/', '', basename($rel));
        $candidates = [];
        if (is_string($base) && $base !== '') $candidates[] = $base;
        if ($titleHint !== '') $candidates[] = $titleHint;

        $name = '';
        foreach ($candidates as $cand) {
            $cand = (string)$cand;
            if (preg_match('/[Ss]\d{1,2}[\s.]?[Ee]\d{1,3}(?:-?[Ee]\d{1,3})?\s*[-.\x{2013}\x{2014}_ ]+\s*(.+)$/u', $cand, $m)) {
                $name = $m[1];
                break;
            }
            if (preg_match('/[Ss]\d{1,2}[Ee]\d{1,3}[\s._-]+(.+)$/u', $cand, $m)) {
                $name = $m[1];
                break;
            }
            if (preg_match('/\d{1,2}[xX]\d{1,3}\s*[-.\x{2013}\x{2014}_ ]+\s*(.+)$/u', $cand, $m)) {
                $name = $m[1];
                break;
            }
            if (preg_match('/^0*\d{1,3}[\s.\-_]+(.+)$/u', $cand, $m) && $cand === $base) {
                $name = $m[1];
                break;
            }
        }
        if ($name === '') return '';
        if (strpos($name, '.') !== false && preg_match('/\.(?:1080p|720p|2160p|WEB|BluRay|HDTV|DSNP|AMZN)/i', $name)) {
            $name = str_replace('.', ' ', $name);
        } else {
            $name = str_replace(['_', '.'], [' ', ' '], $name);
        }
        $name = self::stripReleaseTags($name);
        $show = self::fixText($show);
        if ($show !== '') {
            if (stripos($name, $show) === 0) {
                $name = substr($name, strlen($show));
                $name = ltrim($name, " \t-—–.|");
            }
            $name = preg_replace('/^\(\d{4}\)\s*/', '', $name);
        }
        $name = self::fixText($name);
        if ($name === '' || ($show !== '' && strcasecmp($name, $show) === 0)) return '';
        if (preg_match('/^(?:\d{3,4}p|S\d{1,2}E\d{1,3})$/i', $name)) return '';
        return $name;
    }

    /**
     * Prefer metadata/NFO/parsed episode title over file basename.
     * Order: stored episode_title → sidecar NFO → parse rel/title.
     */
    public static function resolveEpisodeTitle(array $it, bool $readNfo = true): string {
        $type = (string)($it['type'] ?? '');
        $isTv = ($type === 'tv' || $type === 'anime') || (!empty($it['show']) && array_key_exists('season', $it));
        if (!$isTv) return '';

        if (!empty($it['episode_title'])) {
            $t = self::fixText((string)$it['episode_title']);
            if ($t !== '' && !preg_match('/\b(?:1080p|720p|WEB-?DL|x264|x265|DSNP|MeM\.?GP)\b/i', $t)
                && !preg_match('/\.(?:1080p|WEB|BluRay)/i', $t)) {
                return $t;
            }
        }

        $path = (string)($it['path'] ?? '');
        if ($readNfo && $path !== '') {
            $nfo = self::readEpisodeNfoTitle($path);
            if ($nfo !== '') return $nfo;
        }

        // no SxxExx in the name -> folder/name label beats the legacy leading-number parser ("1-900-Beavis")
        $relL = (string)($it['rel'] ?? '');
        $baseL = (string)preg_replace('/\.[^.]+$/', '', basename(str_replace('\\', '/', $relL)));
        if ($relL !== '' && !self::hasExplicitEpisodeCode($baseL)) {
            $labelL = self::localEpisodeLabel($relL, (string)($it['show'] ?? ''));
            if ($labelL !== '') return $labelL;
        }
        $parsed = self::parseEpisodeNameFromRel(
            (string)($it['rel'] ?? ''),
            (string)($it['show'] ?? ''),
            (string)($it['title'] ?? '')
        );
        if ($parsed !== '') return $parsed;
        // title-only files ("Season 2/Bad Dog.avi", "(2006) Event/DVD1/x.avi") -> label from name/folders
        $rel = (string)($it['rel'] ?? '');
        $base = (string)preg_replace('/\.[^.]+$/', '', basename(str_replace('\\', '/', $rel)));
        if ($rel !== '' && !self::hasExplicitEpisodeCode($base)) {
            $label = self::localEpisodeLabel($rel, (string)($it['show'] ?? ''));
            if ($label !== '') return $label;
        }
        return '';
    }


    private static function guessTitle(string $rel, string $type): array {
        $base = basename($rel);
        $base = preg_replace('/\.[^.]+$/', '', $base);
        $year = null;
        $season = null;
        $episode = null;
        $show = null;
        $title = $base;

        if (preg_match('/\((\d{4})\)/', $base, $m)) {
            $year = (int)$m[1];
        }
        $code = self::episodeCodeFromName((string)$base);
        if ($code['explicit']) {
            $season = $code['season'];
            $episode = $code['episode'];
        }
        if ($type === 'tv' || $type === 'anime') {
            $parts = explode('/', str_replace('\\', '/', $rel));
            if (count($parts) >= 2) {
                $show = $parts[0];
                $show = preg_replace('/\s*\(\d{4}\)\s*$/', '', $show);
            }
            if ($season === null || $episode === null) {
                $parsed = self::seasonEpisodeFromRel($rel);
                if ($season === null && $parsed['season'] !== null) $season = (int)$parsed['season'];
                if ($episode === null && $parsed['episode'] !== null) $episode = (int)$parsed['episode'];
            }
            $episodeTitle = self::parseEpisodeNameFromRel($rel, (string)($show ?? ''), '');
            if ($episodeTitle === '' && !$code['explicit'] && $show) {
                $episodeTitle = self::localEpisodeLabel($rel, (string)$show);
            }
            if ($show) {
                $title = self::formatEpisodeDisplayTitle($show, $season, $episode, $episodeTitle !== '' ? $episodeTitle : null);
            } else {
                $title = $base;
            }
        } else {
            $episodeTitle = null;

            $title = preg_replace('/\s*\(\d{4}\)\s*/', ' ', $base);
            $title = preg_replace('/[._]/', ' ', $title);
            $title = trim(preg_replace('/\s+/', ' ', $title));
            $dir = basename(dirname($rel));
            if ($dir && $dir !== '.' && preg_match('/\(\d{4}\)/', $dir)) {
                $title = preg_replace('/\s*\(\d{4}\)\s*/', ' ', $dir);
                $title = trim(preg_replace('/\s+/', ' ', $title));
                if (preg_match('/\((\d{4})\)/', $dir, $m)) $year = (int)$m[1];
            }
        }
        if (!isset($episodeTitle)) $episodeTitle = null;
        return [
            'title' => $title,
            'year' => $year,
            'season' => $season,
            'episode' => $episode,
            'show' => $show,
            'episode_title' => ($episodeTitle !== null && $episodeTitle !== '') ? self::fixText((string)$episodeTitle) : null,
        ];
    }

    /**
     * prefer folder/system path over extension for core.
     * Mega Drive and PlayStation both use .bin — PS1/playstation/psx → psx; mega drive/genesis → segaMD.
     */
    public static function guessEmuCore(string $ext, string $rel, ?string $full = null): ?string {
        $folder = strtolower(str_replace('\\', '/', $rel));
        $fromFolder = self::guessEmuCoreFromFolder($folder);
        if ($fromFolder) return $fromFolder;
        $map = self::$romSystems;
        $extCore = (isset($map[$ext]) && $map[$ext]) ? $map[$ext] : null;
        if ($extCore) return $extCore;
        if ($ext === 'zip' && $full !== null) {
            // Zipped ROM in a folder with no console name: use the extension of the file inside.
            foreach (self::zipEntryNames($full) as $name) {
                $inner = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($inner !== '' && !empty($map[$inner])) return $map[$inner];
            }
        }
        if ($ext === 'zip' || $ext === '7z') return 'nes';
        return null;
    }

    /** File names in a zip's central directory (pure PHP, reads only the tail of the file). */
    public static function zipEntryNames(string $path, int $max = 32): array {
        $fh = @fopen($path, 'rb');
        if (!$fh) return [];
        $names = [];
        try {
            $size = (int)@filesize($path);
            if ($size < 22) return [];
            $tail = min($size, 65557);
            fseek($fh, $size - $tail);
            $buf = (string)fread($fh, $tail);
            $eocd = strrpos($buf, "PK\x05\x06");
            if ($eocd === false || strlen($buf) < $eocd + 22) return [];
            $e = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset', substr($buf, $eocd + 4, 16));
            if (!$e || $e['cd_offset'] <= 0 || $e['cd_offset'] >= $size) return [];
            fseek($fh, $e['cd_offset']);
            $cd = (string)fread($fh, min((int)$e['cd_size'], 1048576));
            $pos = 0;
            while (count($names) < $max && strlen($cd) >= $pos + 46 && substr($cd, $pos, 4) === "PK\x01\x02") {
                $h = unpack('vnlen/velen/vclen', substr($cd, $pos + 28, 6));
                $name = substr($cd, $pos + 46, $h['nlen']);
                if ($name !== '' && substr($name, -1) !== '/') $names[] = $name;
                $pos += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
            }
        } finally {
            fclose($fh);
        }
        return $names;
    }

    /** Prefer specific Sega folder names before generic nes substring matches. */
    private static function guessEmuCoreFromFolder(string $folder): ?string {
        if (strpos($folder, '32x') !== false) return 'sega32x';
        if (strpos($folder, 'sega cd') !== false || strpos($folder, 'segacd') !== false
            || strpos($folder, 'mega-cd') !== false || strpos($folder, 'mega cd') !== false) {
            return 'segaCD';
        }
        if (strpos($folder, 'genesis') !== false || strpos($folder, 'megadrive') !== false
            || strpos($folder, 'mega drive') !== false || strpos($folder, 'mega_drive') !== false
            || strpos($folder, 'mega-drive') !== false) {
            return 'segaMD';
        }
        if (strpos($folder, 'game gear') !== false || strpos($folder, 'gamegear') !== false) return 'segaGG';
        if (strpos($folder, 'master system') !== false || strpos($folder, 'mastersystem') !== false) return 'sms';
        if (strpos($folder, 'n64') !== false || strpos($folder, 'nintendo 64') !== false) return 'n64';
        if (strpos($folder, 'snes') !== false || strpos($folder, 'super nintendo') !== false) return 'snes';
        if (strpos($folder, 'gba') !== false || strpos($folder, 'game boy advance') !== false) return 'gba';
        if (strpos($folder, 'gbc') !== false || strpos($folder, 'game boy color') !== false) return 'gb';
        if (strpos($folder, 'game boy') !== false) return 'gb';
        if (strpos($folder, 'ps1') !== false || strpos($folder, 'psx') !== false
            || strpos($folder, 'playstation') !== false || strpos($folder, 'play station') !== false) {
            return 'psx';
        }
        // Check nes after genesis — the word "genesis" contains "nes".
        if (preg_match('/(?:^|\/)(?:nes|famicom)(?:\/|$)/', $folder)
            || strpos($folder, 'nintendo entertainment') !== false) {
            return 'nes';
        }
        if (preg_match('/(?:^|\/)gb(?:\/|$)/', $folder)) return 'gb';
        return null;
    }

        private static function guessSystemFolder(string $rel): string {
        $parts = explode('/', str_replace('\\', '/', $rel));
        $raw = $parts[0] ?? 'ROMs';
        $key = strtolower(trim(str_replace(['_', '-'], ' ', $raw)));
        $key = preg_replace('/\\s+/', ' ', $key) ?? $key;
        // stable Play console chip label for PS1 / psx / playstation folders
        if (in_array($key, ['ps1', 'psx', 'playstation', 'play station', 'sony playstation'], true)
            || strpos($key, 'playstation') !== false) {
            return 'PlayStation';
        }
        return $raw;
    }

    private static function findPoster(string $dir): ?string {
        foreach (['poster.jpg','poster.png','folder.jpg','cover.jpg','cover.png','Poster.jpg'] as $name) {
            $p = $dir . '/' . $name;
            if (is_file($p)) return $p;
        }
        return null;
    }


    /** First A-Z letter of a title, or '#' for digits/symbols/empty. */
    public static function titleLetter(string $title): string {
        $t = trim($title);
        if ($t === '') return '#';
        if (function_exists('mb_substr')) {
            $ch = mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8');
        } else {
            $ch = strtoupper(substr($t, 0, 1));
        }
        if (strlen($ch) === 1 && $ch >= 'A' && $ch <= 'Z') return $ch;
        return '#';
    }

    /**
     * Filter catalogue rows by starting letter.
     * $letter = 'A'..'Z' or '#' (non-letter). Empty = no-op.
     */
    public static function filterByLetter(array $rows, string $letter): array {
        $letter = strtoupper(trim($letter));
        if ($letter === '') return $rows;
        if ($letter !== '#' && !preg_match('/^[A-Z]$/', $letter)) return $rows;
        $out = [];
        foreach ($rows as $it) {
            if (!is_array($it)) continue;
            $L = self::titleLetter((string)($it['title'] ?? ''));
            if ($L === $letter) $out[] = $it;
        }
        return $out;
    }


    /** read TVMaze show meta from local episode-guide cache (no network). */
    private static $tvMetaMemo = [];
    public static function tvMazeShowMeta(string $showKey): ?array {
        if (array_key_exists($showKey, self::$tvMetaMemo)) return self::$tvMetaMemo[$showKey];
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        if ($safe === '') { self::$tvMetaMemo[$showKey] = null; return null; }
        $cachePath = STREAM_DATA . '/tvmaze/show_' . $safe . '_episodes.json';
        if (!is_file($cachePath)) { self::$tvMetaMemo[$showKey] = null; return null; }
        $j = json_decode((string)@file_get_contents($cachePath), true);
        if (!is_array($j)) { self::$tvMetaMemo[$showKey] = null; return null; }
        $year = null;
        $prem = (string)($j['premiered'] ?? '');
        if (preg_match('/^(\d{4})/', $prem, $m)) $year = (int)$m[1];
        $network = null;
        $net = $j['network'] ?? null;
        if (is_array($net) && !empty($net['name'])) $network = trim((string)$net['name']);
        elseif (is_string($net) && trim($net) !== '') $network = trim($net);
        if ($network === null || $network === '') {
            $wc = $j['webChannel'] ?? null;
            if (is_array($wc) && !empty($wc['name'])) $network = trim((string)$wc['name']);
            elseif (is_string($wc) && trim($wc) !== '') $network = trim($wc);
        }
        $genres = [];
        foreach (($j['genres'] ?? []) as $g) {
            $g = trim((string)$g);
            if ($g !== '') $genres[] = $g;
        }
        $meta = [
            'genres' => $genres,
            'status' => isset($j['status']) && $j['status'] !== '' ? (string)$j['status'] : null,
            'year' => $year,
            'network' => ($network !== null && $network !== '') ? $network : null,
            'name' => isset($j['name']) ? (string)$j['name'] : null,
        ];
        self::$tvMetaMemo[$showKey] = $meta;
        return $meta;
    }

    /** Enrich a show row with TVMaze facets (genres/status/year/network). */
    public static function enrichShowMeta(array $show): array {
        $key = (string)($show['show_key'] ?? $show['id'] ?? '');
        if ($key === '') return $show;
        $meta = self::tvMazeShowMeta($key);
        if (!$meta) return $show;
        if (empty($show['year']) && !empty($meta['year'])) $show['year'] = $meta['year'];
        $show['genres'] = $meta['genres'] ?? [];
        if (!empty($meta['status'])) $show['status'] = $meta['status'];
        if (!empty($meta['network'])) $show['network'] = $meta['network'];
        return $show;
    }

    /** Movie genre tokens guessed from cached synopsis text (no network). */
    private static $movieSynMemo = [];
    private static function movieSynopsisText(string $itemId): string {
        if (array_key_exists($itemId, self::$movieSynMemo)) return self::$movieSynMemo[$itemId];
        $text = '';
        if (class_exists('Posters')) {
            $path = Posters::movieSynopsisCachePath($itemId);
            if (is_file($path)) {
                $j = json_decode((string)@file_get_contents($path), true);
                if (is_array($j) && !empty($j['summary']) && is_string($j['summary'])) {
                    $text = $j['summary'];
                }
            }
        }
        self::$movieSynMemo[$itemId] = $text;
        return $text;
    }

    public static function movieGenreList(): array {
        return [
            'Action', 'Adventure', 'Animation', 'Biography', 'Comedy', 'Crime',
            'Documentary', 'Drama', 'Family', 'Fantasy', 'History', 'Horror',
            'Music', 'Musical', 'Mystery', 'Romance', 'Sci-Fi', 'Sport',
            'Thriller', 'War', 'Western',
        ];
    }

    public static function guessMovieGenres(string $itemId, string $title = ''): array {
        $blob = strtolower(self::movieSynopsisText($itemId) . ' ' . $title);
        if (trim($blob) === '') return [];
        $found = [];
        $map = [
            'sci-fi' => 'Sci-Fi', 'science fiction' => 'Sci-Fi', 'science-fiction' => 'Sci-Fi',
            'action' => 'Action', 'adventure' => 'Adventure', 'animation' => 'Animation', 'animated' => 'Animation',
            'biography' => 'Biography', 'biographical' => 'Biography', 'comedy' => 'Comedy',
            'crime' => 'Crime', 'documentary' => 'Documentary', 'drama' => 'Drama', 'family' => 'Family',
            'fantasy' => 'Fantasy', 'history' => 'History', 'historical' => 'History', 'horror' => 'Horror',
            'musical' => 'Musical', 'mystery' => 'Mystery', 'romance' => 'Romance', 'romantic' => 'Romance',
            'sport' => 'Sport', 'sports' => 'Sport', 'thriller' => 'Thriller', 'western' => 'Western',
        ];
        foreach ($map as $needle => $label) {
            if (strpos($blob, $needle) !== false) $found[$label] = true;
        }
        if (preg_match('/\bwar\b/', $blob)) $found['War'] = true;
        if (preg_match('/\bmusic\b/', $blob) && empty($found['Musical'])) $found['Music'] = true;
        if (isset($found['Musical'])) unset($found['Music']);
        return array_keys($found);
    }

    /** Facet tallies for movie/Disney item lists. */
    public static function movieFacets(array $items): array {
        $years = [];
        $decades = [];
        $genres = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $y = isset($it['year']) ? (int)$it['year'] : 0;
            if ($y >= 1900 && $y <= 2100) {
                $years[(string)$y] = ($years[(string)$y] ?? 0) + 1;
                $d = (int)(floor($y / 10) * 10);
                $dk = $d . 's';
                $decades[$dk] = ($decades[$dk] ?? 0) + 1;
            }
            foreach (self::guessMovieGenres((string)($it['id'] ?? ''), (string)($it['title'] ?? '')) as $g) {
                $genres[$g] = ($genres[$g] ?? 0) + 1;
            }
        }
        krsort($years, SORT_NATURAL);
        uksort($decades, function ($a, $b) {
            return (int)$b <=> (int)$a;
        });
        arsort($genres);
        return [
            'years' => $years,
            'decades' => $decades,
            'genres' => $genres,
        ];
    }

    /** Facet tallies for TV/Anime show lists (uses TVMaze cache). */
    public static function showFacets(array $shows): array {
        $years = [];
        $decades = [];
        $genres = [];
        $statuses = [];
        $networks = [];
        foreach ($shows as $s) {
            if (!is_array($s)) continue;
            $s = self::enrichShowMeta($s);
            $y = isset($s['year']) ? (int)$s['year'] : 0;
            if ($y >= 1900 && $y <= 2100) {
                $years[(string)$y] = ($years[(string)$y] ?? 0) + 1;
                $d = (int)(floor($y / 10) * 10);
                $dk = $d . 's';
                $decades[$dk] = ($decades[$dk] ?? 0) + 1;
            }
            foreach (($s['genres'] ?? []) as $g) {
                $g = trim((string)$g);
                if ($g === '') continue;
                $genres[$g] = ($genres[$g] ?? 0) + 1;
            }
            $st = trim((string)($s['status'] ?? ''));
            if ($st !== '') $statuses[$st] = ($statuses[$st] ?? 0) + 1;
            $net = trim((string)($s['network'] ?? ''));
            if ($net !== '') $networks[$net] = ($networks[$net] ?? 0) + 1;
        }
        krsort($years, SORT_NATURAL);
        uksort($decades, function ($a, $b) { return (int)$b <=> (int)$a; });
        arsort($genres);
        arsort($statuses);
        arsort($networks);
        return [
            'years' => $years,
            'decades' => $decades,
            'genres' => $genres,
            'statuses' => $statuses,
            'networks' => $networks,
        ];
    }

    public static function filterMovieItems(array $items, array $f, string $sectionId = ''): array {
        $genre = strtolower(trim((string)($f['genre'] ?? '')));
        $year = trim((string)($f['year'] ?? ''));
        $decade = trim((string)($f['decade'] ?? ''));
        $actor = strtolower(trim((string)($f['actor'] ?? '')));
        if ($genre === '' && $year === '' && $decade === '' && $actor === '') return $items;
        $pack = null;
        if ($sectionId !== '' && ($genre !== '' || $actor !== '')) {
            /* Warm / reuse facet pack so genre filters stay RAM-cheap. */
            $pack = self::movieFacetPack($sectionId, $items);
        }
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $y = isset($it['year']) ? (int)$it['year'] : 0;
            if ($year !== '' && $y !== (int)$year) continue;
            if ($decade !== '') {
                $dNum = (int)preg_replace('/\D+/', '', $decade);
                if ($dNum > 0) {
                    if ($y < $dNum || $y > $dNum + 9) continue;
                }
            }
            if ($genre !== '') {
                $gs = array_map('strtolower', self::movieGenresForItem($sectionId, $it, $pack));
                $ok = in_array($genre, $gs, true);
                if (!$ok && in_array($genre, ['sci-fi', 'scifi', 'science-fiction', 'science fiction'], true)) {
                    $ok = in_array('sci-fi', $gs, true);
                }
                if (!$ok) continue;
            }
            if ($actor !== '') {
                $syn = strtolower(self::movieSynopsisText((string)($it['id'] ?? '')));
                $title = strtolower((string)($it['title'] ?? ''));
                if (strpos($syn, $actor) === false && strpos($title, $actor) === false) continue;
            }
            $out[] = $it;
        }
        return $out;
    }

    public static function filterShowRows(array $shows, array $f): array {
        $genre = strtolower(trim((string)($f['genre'] ?? '')));
        $year = trim((string)($f['year'] ?? ''));
        $decade = trim((string)($f['decade'] ?? ''));
        $status = strtolower(trim((string)($f['status'] ?? '')));
        $network = strtolower(trim((string)($f['network'] ?? '')));
        if ($genre === '' && $year === '' && $decade === '' && $status === '' && $network === '') return $shows;
        $out = [];
        foreach ($shows as $s) {
            if (!is_array($s)) continue;
            $s = self::enrichShowMeta($s);
            $y = isset($s['year']) ? (int)$s['year'] : 0;
            if ($year !== '' && $y !== (int)$year) continue;
            if ($decade !== '') {
                $dNum = (int)preg_replace('/\D+/', '', $decade);
                if ($dNum > 0 && ($y < $dNum || $y > $dNum + 9)) continue;
            }
            if ($genre !== '') {
                $gs = array_map('strtolower', array_map('strval', $s['genres'] ?? []));
                if (!in_array($genre, $gs, true)) continue;
            }
            if ($status !== '') {
                if (strtolower((string)($s['status'] ?? '')) !== $status) continue;
            }
            if ($network !== '') {
                if (strtolower((string)($s['network'] ?? '')) !== $network) continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    /** Count titles per letter for A-Z rail enable/disable. */
    public static function letterCounts(array $rows): array {
        $counts = ['#' => 0];
        for ($i = ord('A'); $i <= ord('Z'); $i++) $counts[chr($i)] = 0;
        foreach ($rows as $it) {
            if (!is_array($it)) continue;
            $L = self::titleLetter((string)($it['title'] ?? ''));
            if (!isset($counts[$L])) $counts[$L] = 0;
            $counts[$L]++;
        }
        return $counts;
    }

    public static function sectionItems(string $sectionId): array {
        if (\Vaultcast\Database::installed()) return \Vaultcast\Repository::section($sectionId);
        $cat = self::get();
        $items = [];
        foreach ($cat['items'] ?? [] as $it) {
            if (($it['section'] ?? '') === $sectionId) $items[] = $it;
        }
        return $items;
    }

    /**
     * Read expected regular episodes from an existing TVMaze guide cache.
     * No network I/O. Returns null when cache missing/unusable.
     * @return array{seasons: array<int,true>, ep_keys: array<string,true>}|null
     */
    private static function tvMazeCacheSeasonStats(string $showKey): ?array {
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        if ($safe === '') return null;
        $cachePath = STREAM_DATA . '/tvmaze/show_' . $safe . '_episodes.json';
        if (!is_file($cachePath) || filesize($cachePath) < 20) return null;
        $raw = @file_get_contents($cachePath);
        if ($raw === false || $raw === '') return null;
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['episodes']) || !is_array($j['episodes'])) return null;
        $seasons = [];
        $epKeys = [];
        foreach ($j['episodes'] as $te) {
            if (!is_array($te)) continue;
            if (!array_key_exists('number', $te) || $te['number'] === null || $te['number'] === '') continue;
            $s = isset($te['season']) ? (int)$te['season'] : 0;
            $e = (int)$te['number'];
            if ($s <= 0 || $e < 1) continue; // regular seasons only
            $seasons[$s] = true;
            $epKeys[self::epKey($s, $e)] = true;
        }
        if (!$epKeys) return null;
        return [
            'seasons' => $seasons,
            'ep_keys' => $epKeys,
        ];
    }

    /** Group TV/Anime episodes into show-level entries. */
    public static function sectionShows(string $sectionId): array {
        $revision = Store::revision('catalogue') . '|' . Store::revision('browse_epoch') . '|' . Store::revision('settings');
        $slot = 'section-shows-current-v2|' . $sectionId;
        $cached = \Vaultcast\Repository::cacheGet($slot);
        if ($cached && ($cached['revision'] ?? '') === $revision) return $cached['shows'];
        $shows = self::buildSectionShows($sectionId);
        \Vaultcast\Repository::cachePut($slot, ['revision'=>$revision, 'shows'=>$shows], 0);
        return $shows;
    }

    /** Build show list for a TV/anime section (uncached). */
    private static function buildSectionShows(string $sectionId): array {
        $items = self::sectionItems($sectionId);
        $groups = [];
        foreach ($items as $it) {
            /* do not enrichItemArt per episode (is_file + poster disk walks × thousands). Art resolved once per show below. */
            $name = $it['show'] ?? '';
            if ($name === '') {
                // orphan episode: treat basename folder as show
                $parts = explode('/', str_replace('\\', '/', $it['rel'] ?? ''));
                $name = $parts[0] ?: ($it['title'] ?? 'Unknown');
            }
            if (!isset($groups[$name])) {
                $groups[$name] = [
                    'title' => $name,
                    'section' => $sectionId,
                    'type' => $it['type'] ?? 'tv',
                    'year' => $it['year'],
                    'episodes' => [],
                    'seasons' => [],
                    'ep_keys' => [],
                    'local_poster' => $it['show_poster'] ?? null,
                    'show_dir' => self::showDirFromItem($it, false),
                    'mtime' => $it['mtime'] ?? 0,
                ];
            }
            $g =& $groups[$name];
            $g['episodes'][] = $it['id'];
            if (empty($g['show_dir'])) {
                $dir = self::showDirFromItem($it, false);
                if ($dir) $g['show_dir'] = $dir;
            }
            $season = $it['season'];
            $episode = $it['episode'] ?? null;
            if ($season !== null) $g['seasons'][$season] = true;
            if ($season !== null && $episode !== null) {
                $g['ep_keys'][self::epKey((int)$season, (int)$episode)] = true;
            }
            if (!empty($it['show_poster'])) $g['local_poster'] = $it['show_poster'];
            elseif (!empty($it['poster']) && empty($g['local_poster'])) $g['local_poster'] = $it['poster'];
            if (!empty($it['year']) && empty($g['year'])) $g['year'] = $it['year'];
            if (($it['mtime'] ?? 0) > ($g['mtime'] ?? 0)) $g['mtime'] = $it['mtime'];
            unset($g);
        }
        $out = [];
        foreach ($groups as $name => $g) {
            $key = self::showKey($sectionId, $name);
            // Browsing uses scan records; never wake or walk media disks for summary cards.
            $showDir = $g['show_dir'] ?? null;
            $has = !empty($g['local_poster']) || (class_exists('Posters') && Posters::enabled());
            $localEps = count($g['episodes']);
            $availableCount = $localEps;
            $localEpKeys = $g['ep_keys'] ?? [];
            $seasonMap = [];
            foreach ($g['seasons'] as $sn => $_true) {
                if ((int)$sn > 0) $seasonMap[(int)$sn] = true;
            }
            $expectedKeys = $localEpKeys;
            $guide = self::tvMazeCacheSeasonStats($key);
            if (is_array($guide)) {
                foreach ($guide['seasons'] as $sn => $_t) $seasonMap[(int)$sn] = true;
                // Union guide expected keys with local keys (covers local-only seasons like S7)
                $expectedKeys = ($guide['ep_keys'] ?? []) + $localEpKeys;
            }
            $seasonCount = count($seasonMap) > 0 ? count($seasonMap) : count($g['seasons']);
            $episodeCount = count($expectedKeys) > 0 ? count($expectedKeys) : $localEps;
            $missingCount = $episodeCount > $availableCount ? ($episodeCount - $availableCount) : ($guide ? max(0, $episodeCount - $availableCount) : null);
            if ($guide) $missingCount = max(0, $episodeCount - $availableCount);
            $row = [
                'show_key' => $key,
                'id' => $key,
                'kind' => 'show',
                'section' => $sectionId,
                'type' => $g['type'],
                'title' => $g['title'],
                'year' => $g['year'],
                'episode_count' => $episodeCount,
                'available_count' => $availableCount,
                'missing_count' => $missingCount,
                'season_count' => $seasonCount,
                'has_poster' => $has,
                'local_poster' => $g['local_poster'],
                'show_dir' => $showDir,
                'mtime' => $g['mtime'],
            ];
            // attach TVMaze genres/status/year/network for library filters
            $out[] = self::enrichShowMeta($row);
        }
        usort($out, function ($a, $b) {
            return strcasecmp($a['title'], $b['title']);
        });
        return $out;
    }

    private static $showPosterIndex = null;
    private static $showPosterIndexMtime = null;

    /**
     * Lightweight show poster lookup. Does not build episode guides or call TVMaze.
     * @return array{title:string,local:?string,section:string}|null
     */
    public static function showPosterHint(string $showKey): ?array {
        $revision = Store::revision('catalogue') . '|' . Store::revision('browse_epoch');
        if (self::$showPosterIndex === null || self::$showPosterIndexMtime !== $revision) {
            $entry = \Vaultcast\Repository::cacheGet('show-poster-index-v2');
            if ($entry && ($entry['revision'] ?? '') === $revision) {
                self::$showPosterIndex = $entry['hints'];
            } else {
                $map = [];
                // Read only TV/anime records once per revision, not the whole library per image.
                $sectionIds = [];
                foreach (\Vaultcast\Repository::document('catalogue', ['sections'=>[]])['sections'] ?? [] as $section) {
                    if (in_array($section['type'] ?? '', ['tv','anime'], true)) $sectionIds[] = (string)$section['id'];
                }
                $items = $sectionIds ? \Vaultcast\Repository::rows('SELECT payload FROM vaultcast_items WHERE section_id IN ('
                    . implode(',', array_fill(0, count($sectionIds), '?')) . ')', $sectionIds) : [];
                foreach ($items as $it) {
                    $name = (string)($it['show'] ?? '');
                    if ($name === '') $name = explode('/', str_replace('\\', '/', (string)($it['rel'] ?? '')))[0] ?: (string)($it['title'] ?? 'Unknown');
                    $section = (string)($it['section'] ?? '');
                    $key = self::showKey($section, $name);
                    if (!isset($map[$key])) $map[$key] = ['title'=>$name, 'local'=>null,
                        'section'=>$section, 'show_dir'=>self::showDirFromItem($it, false)];
                    if (!empty($it['show_poster'])) $map[$key]['local'] = $it['show_poster'];
                    elseif (!$map[$key]['local'] && !empty($it['poster'])) $map[$key]['local'] = $it['poster'];
                }
                self::$showPosterIndex = $map;
                \Vaultcast\Repository::cachePut('show-poster-index-v2', ['revision'=>$revision, 'hints'=>$map], 0);
            }
            self::$showPosterIndexMtime = $revision;
        }
        return self::$showPosterIndex[$showKey] ?? null;
    }

    public static function show(string $showKey): ?array {
        $cat = \Vaultcast\Repository::document('catalogue', ['sections' => []]);
        foreach ($cat['sections'] ?? [] as $sec) {
            $type = $sec['type'] ?? '';
            if ($type !== 'tv' && $type !== 'anime') continue;
            $shows = self::sectionShows($sec['id']);
            foreach ($shows as $s) {
                if ($s['show_key'] === $showKey) {
                    return self::showDetail($sec['id'], $s['title'], $s);
                }
            }
        }
        return null;
    }

    private static function showDetail(string $sectionId, string $showName, array $meta): array {
        // The section_show index narrows this to one show; retain orphan-folder fallback.
        $items = \Vaultcast\Repository::rows("SELECT payload FROM vaultcast_items WHERE section_id=? AND (show_title=? OR show_title='') ORDER BY title,id", [$sectionId, $showName]);
        MediaFacts::preload($items);
        $available = []; // "S{s}E{e}" => public item
        $unnumbered = [];
        foreach ($items as $it) {
            $name = $it['show'] ?? '';
            if ($name === '') {
                $parts = explode('/', str_replace('\\', '/', $it['rel'] ?? ''));
                $name = $parts[0] ?: ($it['title'] ?? '');
            }
            if ($name !== $showName) continue;
            $ep = self::publicItem($it);
            $ep['show_key'] = $meta['show_key'];
            $ep['available'] = true;
            $ep['status'] = 'available';
            $season = $it['season'];
            $episode = $it['episode'];
            if ($season !== null && $episode !== null) {
                $s = (int)$season;
                $e = (int)$episode;
                $key = self::epKey($s, $e);
                // Prefer first file if duplicates
                if (!isset($available[$key])) {
                    $available[$key] = $ep;
                }
            } else {
                $unnumbered[] = $ep;
            }
        }

        // Guide stored in MariaDB by the background facts pass; no disk/network on the request.
        $tvmaze = MediaFacts::guide((string)$meta['show_key']);
        $expected = []; // epKey => meta from tvmaze or gap-fill
        $source = 'local_gaps';

        if (is_array($tvmaze) && !empty($tvmaze['episodes'])) {
            $source = 'tvmaze';
            foreach ($tvmaze['episodes'] as $te) {
                $s = isset($te['season']) ? (int)$te['season'] : 0;
                $e = isset($te['number']) ? $te['number'] : null;
                if ($e === null) continue; // skip undated specials without number
                $e = (int)$e;
                $expected[self::epKey($s, $e)] = [
                    'season' => $s,
                    'episode' => $e,
                    'name' => $te['name'] ?? null,
                    'airdate' => $te['airdate'] ?? null,
                    'runtime' => $te['runtime'] ?? null,
                    'type' => $te['type'] ?? 'regular',
                    'tvmaze_id' => $te['id'] ?? null,
                ];
            }
        }

        // Always gap-fill seasons that have local files (covers incomplete TVMaze / no match)
        $localBySeason = [];
        foreach ($available as $ep) {
            $s = (int)$ep['season'];
            $e = (int)$ep['episode'];
            if (!isset($localBySeason[$s])) $localBySeason[$s] = [];
            $localBySeason[$s][] = $e;
        }
        foreach ($localBySeason as $s => $elist) {
            if ($s <= 0) continue; // don't invent missing specials from gaps
            $max = max($elist);
            if ($max < 1) continue;
            for ($e = 1; $e <= $max; $e++) {
                $k = self::epKey($s, $e);
                if (!isset($expected[$k])) {
                    $expected[$k] = [
                        'season' => $s,
                        'episode' => $e,
                        'name' => null,
                        'airdate' => null,
                        'runtime' => null,
                        'type' => 'regular',
                        'tvmaze_id' => null,
                    ];
                    if ($source === 'tvmaze') $source = 'tvmaze+local_gaps';
                }
            }
        }

        // Include local numbered eps not in expected (extras / alternate numbering)
        foreach ($available as $k => $ep) {
            if (!isset($expected[$k])) {
                $expected[$k] = [
                    'season' => (int)$ep['season'],
                    'episode' => (int)$ep['episode'],
                    'name' => null,
                    'airdate' => null,
                    'runtime' => null,
                    'type' => 'extra',
                    'tvmaze_id' => null,
                ];
            }
        }

        $seasons = [];
        $availableCount = 0;
        $missingCount = 0;
        foreach ($expected as $k => $metaEp) {
            $s = (int)$metaEp['season'];
            $e = (int)$metaEp['episode'];
            if (isset($available[$k])) {
                $row = $available[$k];
                $row['available'] = true;
                $row['status'] = 'available';
                if (!empty($metaEp['name'])) $row['episode_title'] = $metaEp['name'];
                elseif (empty($row['episode_title'])) {
                    $resolved = self::resolveEpisodeTitle($row, false);
                    if ($resolved !== '') $row['episode_title'] = $resolved;
                }
                if (!empty($metaEp['airdate'])) $row['airdate'] = $metaEp['airdate'];
                $availableCount++;
            } else {
                $row = [
                    'id' => null,
                    'show_key' => $meta['show_key'],
                    'section' => $sectionId,
                    'type' => $meta['type'],
                    'title' => self::fixText(
                        $showName . " \xE2\x80\x94 " . sprintf('S%02dE%02d', $s, $e)
                        . (!empty($metaEp['name']) ? (" \xE2\x80\x94 " . $metaEp['name']) : '')
                    ),
                    'episode_title' => $metaEp['name'],
                    'year' => $meta['year'],
                    'season' => $s,
                    'episode' => $e,
                    'show' => $showName,
                    'ext' => null,
                    'size' => null,
                    'mtime' => null,
                    'media_kind' => 'video',
                    'has_poster' => false,
                    'kind' => 'episode',
                    'available' => false,
                    'status' => 'missing',
                    'airdate' => $metaEp['airdate'],
                ];
                $missingCount++;
            }
            if (!isset($seasons[$s])) $seasons[$s] = [];
            $seasons[$s][] = $row;
        }

        // Unnumbered local files ÔåÆ Specials (season 0), all available
        foreach ($unnumbered as $ep) {
            $ep['season'] = $ep['season'] !== null ? $ep['season'] : 0;
            $ep['available'] = true;
            $ep['status'] = 'available';
            $s = (int)($ep['season'] ?? 0);
            if (!isset($seasons[$s])) $seasons[$s] = [];
            $seasons[$s][] = $ep;
            $availableCount++;
        }

        ksort($seasons, SORT_NUMERIC);
        $seasonList = [];
        $regularSeasonCount = 0;
        foreach ($seasons as $num => $eps) {
            usort($eps, function ($a, $b) {
                $ae = $a['episode'] ?? 0;
                $be = $b['episode'] ?? 0;
                if ($ae === $be) return strcmp($a['title'] ?? '', $b['title'] ?? '');
                return $ae <=> $be;
            });
            $avail = 0; $miss = 0;
            foreach ($eps as $ep) {
                if (!empty($ep['available'])) $avail++; else $miss++;
            }
            if ($num > 0) $regularSeasonCount++;
            $seasonLocal = null;
            $showDir = null;
            $postersOn = class_exists('Posters') && Posters::enabled();
            if ($postersOn) {
                // Optimistic (as before): UI requests the season poster endpoint; no disk checks here.
                $seasonList[] = ['season' => $num, 'label' => $num === 0 ? 'Specials' : ('Season ' . $num), 'episode_count' => count($eps), 'available_count' => $avail, 'missing_count' => $miss, 'has_poster' => true, 'episodes' => $eps];
                continue;
            }
            if (!empty($meta['local_poster'])) {
                $showDir = dirname((string)$meta['local_poster']);
            }
            if ((!$showDir || !is_dir($showDir)) && class_exists('Posters')) {
                // derive from first available episode path
                foreach ($eps as $ep0) {
                    if (!empty($ep0['id'])) {
                        $raw0 = self::item((string)$ep0['id']);
                        if ($raw0) {
                            $showDir = Posters::showDirFromItem($raw0);
                            if ($showDir) break;
                        }
                    }
                }
            }
            if ($showDir && class_exists('Posters')) {
                $seasonLocal = Posters::findLocalSeasonArt($showDir, (int)$num);
            }
            $seasonHasPoster = false;
            if (class_exists('Posters')) {
                $seasonHasPoster = Posters::hasSeasonPoster($meta['show_key'], (int)$num, $seasonLocal, $showDir);
                // Optimistic: allow UI to request season poster endpoint on first view
                if (!$seasonHasPoster && Posters::enabled()) $seasonHasPoster = true;
            }
            $seasonList[] = [
                'season' => $num,
                'label' => $num === 0 ? 'Specials' : ('Season ' . $num),
                'episode_count' => count($eps),
                'available_count' => $avail,
                'missing_count' => $miss,
                'has_poster' => $seasonHasPoster,
                'episodes' => $eps,
            ];
        }

        $totalExpected = $availableCount + $missingCount;
        return [
            'ok' => true,
            'show' => [
                'show_key' => $meta['show_key'],
                'id' => $meta['show_key'],
                'kind' => 'show',
                'section' => $sectionId,
                'type' => $meta['type'],
                'title' => $showName,
                'year' => $meta['year'],
                'slug' => Slugs::show((string)$meta['show_key'], (string)$showName, $meta['year'] ?? null),
                'episode_count' => $totalExpected,
                'available_count' => $availableCount,
                'missing_count' => $missingCount,
                'season_count' => $regularSeasonCount > 0 ? $regularSeasonCount : count($seasonList),
                'has_poster' => $meta['has_poster'] || (class_exists('Posters') && Posters::enabled()) || (class_exists('Posters') && Posters::hasShowPoster(
                    $meta['local_poster'] ?? null,
                    $meta['show_key'],
                    !empty($meta['show_dir']) ? (string)$meta['show_dir'] : (!empty($meta['local_poster']) ? dirname((string)$meta['local_poster']) : null)
                )),
                'tvmaze_id' => is_array($tvmaze) ? ($tvmaze['id'] ?? null) : null,
                'synopsis' => is_array($tvmaze) ? ($tvmaze['summary'] ?? null) : null,
                'genres' => is_array($tvmaze) ? ($tvmaze['genres'] ?? []) : [],
                'episode_source' => $source,
            ],
            'seasons' => $seasonList,
            'availability' => [
                'available' => $availableCount,
                'missing' => $missingCount,
                'total' => $totalExpected,
                'source' => $source,
            ],
        ];
    }

    /** Stable key for season+episode pair. */
    public static function epKey(int $season, int $episode): string {
        return 'S' . $season . 'E' . $episode;
    }

    /** Locate a show meta + detail by key (shared by fetch routes). */
    public static function showMeta(string $showKey): ?array {
        $detail = self::show($showKey);
        return $detail;
    }

    public static function publicShow(array $s): array {
        $s = self::enrichShowMeta($s);
        return [
            'id' => $s['show_key'] ?? $s['id'],
            'show_key' => $s['show_key'] ?? $s['id'],
            'kind' => 'show',
            'section' => $s['section'],
            'type' => $s['type'],
            'title' => $s['title'],
            'year' => $s['year'] ?? null,
            'slug' => Slugs::show((string)($s['show_key'] ?? $s['id']), (string)$s['title'], $s['year'] ?? null),
            'episode_count' => $s['episode_count'] ?? 0,
            'available_count' => $s['available_count'] ?? null,
            'missing_count' => $s['missing_count'] ?? null,
            'season_count' => $s['season_count'] ?? 0,
            'has_poster' => !empty($s['has_poster']),
            'media_kind' => 'show',
            'genres' => array_values(array_map('strval', $s['genres'] ?? [])),
            'status' => $s['status'] ?? null,
            'network' => $s['network'] ?? null,
        ];
    }

    public static function random(string $sectionId, int $n = 10): array {
        $cat = \Vaultcast\Repository::document('catalogue', ['sections' => []]);
        $secType = null;
        foreach ($cat['sections'] ?? [] as $sec) {
            if (($sec['id'] ?? '') === $sectionId) {
                $secType = $sec['type'] ?? null;
                break;
            }
        }
        if ($secType === 'tv' || $secType === 'anime') {
            $shows = self::sectionShows($sectionId);
            if (!$shows) return [];
            shuffle($shows);
            return array_slice($shows, 0, max(1, $n));
        }
        // Sample narrow IDs first; do not sort every LONGTEXT payload in the section.
        return \Vaultcast\Repository::rows('SELECT i.payload FROM vaultcast_items i JOIN (SELECT id FROM vaultcast_items WHERE section_id=? ORDER BY RAND() LIMIT ' . max(1, min(100, $n)) . ') picks ON picks.id=i.id', [$sectionId]);
    }

    /**
     * Discover / random showcase payload with SHM cache.
     * Cards skip ffprobe; list badges use the file extension.
     */
    public static function randomPublic(string $sectionId, int $n = 16): array {
        $n = max(1, min(40, $n));
        $salt = $sectionId . '|n=' . $n;
        $revision = Store::revision('catalogue') . '|' . Store::revision('browse_epoch') . '|' . Store::revision('settings');
        $slot = 'showcase-random-current|' . $salt;
        $entry = \Vaultcast\Repository::cacheGet($slot);
        $hit = $entry && ($entry['revision'] ?? '') === $revision ? ($entry['payload'] ?? null) : null;
        if (is_array($hit) && isset($hit['items']) && is_array($hit['items'])) {
            $hit['cached'] = true;
            return $hit;
        }
        $raw = self::random($sectionId, $n);
        $cat = \Vaultcast\Repository::document('catalogue', ['sections' => []]);
        $secType = null;
        foreach ($cat['sections'] ?? [] as $sec) {
            if (($sec['id'] ?? '') === $sectionId) { $secType = $sec['type'] ?? null; break; }
        }
        if ($secType === 'tv' || $secType === 'anime') {
            $items = array_map(function ($s) { return self::publicShow($s); }, $raw);
            $kind = 'shows';
        } else {
            $items = self::publicItems($raw);
            $kind = 'items';
        }
        $out = ['ok' => true, 'kind' => $kind, 'items' => $items, 'cached' => false];
        // Capture dependencies before building; no age check or file per revision.
        \Vaultcast\Repository::cachePut($slot, ['revision'=>$revision, 'payload'=>$out], 0);
        return $out;
    }

    /** Prepare the standard Films/Discover row during existing background work. */
    public static function warmRandomShowcases(): void {
        foreach (\Vaultcast\Repository::document('catalogue', ['sections'=>[]])['sections'] ?? [] as $section) {
            if (!in_array($section['type'] ?? '', ['movies','disney'], true)) continue;
            Maintenance::checkpoint();
            self::randomPublic((string)$section['id'], 16);
        }
    }

    /** Recently Updated showcase with short SHM cache (no ffprobe on cards). */
    public static function recentlyUpdatedPublic(string $sectionId, int $n = 16): array {
        $n = max(1, min(40, $n));
        $salt = $sectionId . '|n=' . $n;
        $hit = self::shmCacheGet('showcase_recent', $salt);
        if (is_array($hit) && isset($hit['items']) && is_array($hit['items']) && (time() - (int)($hit['_cached_at'] ?? 0)) < 90) {
            $hit['cached'] = true;
            return $hit;
        }
        $items = self::recentlyUpdated($sectionId, $n);
        $out = ['ok' => true, 'kind' => 'recent', 'items' => $items, 'cached' => false];
        self::shmCachePut('showcase_recent', $salt, $out);
        return $out;
    }


    /**
     * Recently Updated showcase for TV/anime sections.
     * Mixes newly added shows (first-seen mtime) and new episode files on existing shows.
     * Each card carries showcase_kind + showcase_label for clear UI badges.
     * @return list<array>
     */
    public static function recentlyUpdated(string $sectionId, int $n = 16): array {
        $n = max(1, min(40, $n));
        $items = self::sectionItems($sectionId);
        if (!$items) return [];

        $groups = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name = (string)($it['show'] ?? '');
            if ($name === '') {
                $parts = explode('/', str_replace('\\', '/', (string)($it['rel'] ?? '')));
                $name = $parts[0] !== '' ? $parts[0] : (string)($it['title'] ?? 'Unknown');
            }
            if (!isset($groups[$name])) {
                $groups[$name] = [
                    'min' => PHP_INT_MAX,
                    'max' => 0,
                    'count' => 0,
                    'latest' => null,
                ];
            }
            $mt = (int)($it['mtime'] ?? 0);
            $g =& $groups[$name];
            $g['count']++;
            if ($mt > 0 && $mt < $g['min']) $g['min'] = $mt;
            if ($mt >= $g['max']) {
                $g['max'] = $mt;
                $g['latest'] = $it;
            }
            unset($g);
        }

        $showMetas = self::sectionShows($sectionId);
        $showByTitle = [];
        foreach ($showMetas as $s) {
            if (!empty($s['title'])) $showByTitle[(string)$s['title']] = $s;
        }

        $now = time();
        $newShowRows = [];
        $episodeRows = [];
        foreach ($groups as $name => $g) {
            if (empty($g['latest']) || $g['max'] <= 0) continue;
            $min = ($g['min'] === PHP_INT_MAX) ? $g['max'] : (int)$g['min'];
            $span = max(0, (int)$g['max'] - $min);
            /* New show: arrived recently and most files landed in a tight window. */
            $isNewShow = ($min > ($now - 60 * 86400)) && ($span < 21 * 86400);

            if ($isNewShow && isset($showByTitle[$name])) {
                $pub = self::publicShow($showByTitle[$name]);
                $pub['showcase_kind'] = 'new_show';
                $pub['showcase_label'] = 'New show';
                $pub['mtime'] = $g['max'];
                $newShowRows[] = ['sort' => $min, 'row' => $pub];
            } else {
                $pub = self::publicItem($g['latest'], false);
                $pub['showcase_kind'] = 'new_episode';
                $pub['showcase_label'] = 'New episode';
                $episodeRows[] = ['sort' => $g['max'], 'row' => $pub];
            }
        }

        usort($newShowRows, function ($a, $b) { return $b['sort'] <=> $a['sort']; });
        usort($episodeRows, function ($a, $b) { return $b['sort'] <=> $a['sort']; });

        $showCap = max(4, (int)ceil($n * 0.4));
        $picked = [];
        $seenShow = [];
        foreach (array_slice($newShowRows, 0, $showCap) as $c) {
            $key = (string)($c['row']['show_key'] ?? $c['row']['id'] ?? '');
            if ($key !== '') $seenShow[$key] = true;
            $picked[] = ['sort' => $c['sort'], 'row' => $c['row']];
        }
        foreach ($episodeRows as $c) {
            if (count($picked) >= $n) break;
            $sk = (string)($c['row']['show_key'] ?? '');
            if ($sk !== '' && isset($seenShow[$sk])) continue;
            if ($sk !== '') $seenShow[$sk] = true;
            $picked[] = ['sort' => $c['sort'], 'row' => $c['row']];
        }
        /* If still short, fill with remaining new shows. */
        if (count($picked) < $n) {
            foreach (array_slice($newShowRows, $showCap) as $c) {
                if (count($picked) >= $n) break;
                $key = (string)($c['row']['show_key'] ?? $c['row']['id'] ?? '');
                if ($key !== '' && isset($seenShow[$key])) continue;
                if ($key !== '') $seenShow[$key] = true;
                $picked[] = ['sort' => $c['sort'], 'row' => $c['row']];
            }
        }
        usort($picked, function ($a, $b) { return $b['sort'] <=> $a['sort']; });
        return array_map(function ($c) { return $c['row']; }, array_slice($picked, 0, $n));
    }

    public static function item(string $id): ?array {
        return \Vaultcast\Repository::item($id);
    }

    private static function homeCacheKey(array $user): string {
        return 'home-v2|' . md5((string)($user['id'] ?? 'guest') . '|' . Store::revision('catalogue')
            . '|' . Store::revision('progress') . '|' . Store::revision('settings'))
            . '|' . Store::revision('browse_epoch');
    }

    public static function cachedHome(array $user): ?array {
        $entry = \Vaultcast\Repository::cacheGet('home-current|' . (string)($user['id'] ?? 'guest'));
        if (!$entry || ($entry['revision'] ?? '') !== self::homeCacheKey($user)) return null;
        $hit = $entry['home'] ?? null;
        if (is_array($hit)) $hit['cached'] = true;
        return is_array($hit) ? $hit : null;
    }

    public static function cachedHomeLibrary(): ?array {
        $revision = Store::revision('catalogue') . '|' . Store::revision('settings') . '|' . Store::revision('browse_epoch');
        $entry = \Vaultcast\Repository::cacheGet('home-library-v2');
        return $entry && ($entry['revision'] ?? '') === $revision ? $entry['home'] : null;
    }

    public static function homeLibrary(): array {
        $revision = Store::revision('catalogue') . '|' . Store::revision('settings') . '|' . Store::revision('browse_epoch');
        $entry = \Vaultcast\Repository::cacheGet('home-library-v2');
        if ($entry && ($entry['revision'] ?? '') === $revision) return $entry['home'];
        $settings = Store::settings();
        $n = max(1, min(100, (int)($settings['home']['random_per_section'] ?? 10)));
        $cat = \Vaultcast\Repository::document('catalogue', ['sections' => []]);
        $sections = [];
        foreach ($cat['sections'] ?? [] as $sec) {
            $type = $sec['type'] ?? 'movies';
            $rand = self::random($sec['id'], $n);
            if ($type === 'tv' || $type === 'anime') {
                $items = array_map(function ($s) { return self::publicShow($s); }, $rand);
                /* Prefer stored show_count — never rebuild sectionShows just for a number. */
                $browseCount = $sec['show_count'] ?? count($rand);
            } else {
                $items = self::publicItems($rand);
                $browseCount = $sec['count'];
            }
            $sections[] = [
                'id' => $sec['id'],
                'label' => $sec['label'],
                'type' => $type,
                'count' => $browseCount,
                'item_count' => $sec['count'],
                'show_count' => $sec['show_count'] ?? null,
                'items' => $items,
            ];
        }
        $out = ['ok'=>true, 'app_name'=>$settings['app_name'] ?? 'Vaultcast',
            'features'=>$settings['features'] ?? ['live'=>true, 'downloads'=>false, 'server_tools'=>false],
            'scanned_at'=>$cat['scanned_at'] ?? null, 'item_count'=>$cat['item_count'] ?? 0,
            'continue_watching'=>[], 'sections'=>$sections, 'cached'=>false];
        \Vaultcast\Repository::cachePut('home-library-v2', ['revision'=>$revision, 'home'=>$out], 0);
        return $out;
    }

    public static function home(array $user): array {
        $uid = (string)($user['id'] ?? 'guest');
        $cacheKey = self::homeCacheKey($user);
        $hit = self::cachedHome($user);
        if ($hit !== null) return $hit;
        $out = self::homeLibrary();
        $settings = Store::settings();
        $out['continue_watching'] = self::continueWatching(\Vaultcast\Repository::progress($uid),
            max(0, min(100, (int)($settings['home']['continue_watching_limit'] ?? 20))));
        \Vaultcast\Repository::cachePut('home-current|' . $uid, ['revision'=>$cacheKey, 'home'=>$out], 0);
        return $out;
    }

    /* Continue Watching — one entry per TV show (resume last episode, or Up next when finished). */
    private static $cwEpisodeIndex = null;

    /** True when this catalogue item is a TV/anime episode. */
    private static function cwIsEpisode(array $it): bool {
        $type = $it['type'] ?? '';
        if (in_array($type, ['tv', 'anime'], true)) return true;
        return !empty($it['show']) && array_key_exists('season', $it) && $it['season'] !== null;
    }

    /** Stable per-show grouping key (matches sectionShows naming incl. orphan-folder fallback). */
    private static function cwShowGroupKey(array $it): string {
        $name = (string)($it['show'] ?? '');
        if ($name === '') {
            $parts = explode('/', str_replace('\\', '/', (string)($it['rel'] ?? '')));
            $name = $parts[0] !== '' ? $parts[0] : (string)($it['title'] ?? 'Unknown');
        }
        return self::showKey((string)($it['section'] ?? ''), $name);
    }

    /**
     * Finished detection. TV episodes: < 3 min remaining; movies/other video: < 10 min remaining.
     * Threshold is capped at 25% of duration so short clips are not marked done early.
     * Legacy rule (> 92% watched) is still honoured.
     */
    public static function progressFinished(array $p, bool $isEpisode): bool {
        $pos = (float)($p['position'] ?? 0);
        $dur = (float)($p['duration'] ?? 0);
        if ($dur <= 0) return false;
        if ($pos / $dur > 0.92) return true;
        $limit = min($isEpisode ? 180.0 : 600.0, $dur * 0.25);
        return ($dur - $pos) < $limit;
    }

    /** Load only the requested show's episodes, once per request. */
    private static function cwEpisodeIndex(array $current): array {
        $group = self::cwShowGroupKey($current);
        if (isset(self::$cwEpisodeIndex[$group])) return self::$cwEpisodeIndex;
        $idx = self::$cwEpisodeIndex ?? [];
        $idx[$group] = [];
        $episodes = \Vaultcast\Repository::rows('SELECT payload FROM vaultcast_items WHERE section_id=? AND show_title=? AND media_kind=?', [(string)($current['section'] ?? ''), (string)($current['show'] ?? ''), 'video']);
        foreach ($episodes as $it) {
            if (!is_array($it) || !self::cwIsEpisode($it)) continue;
            if (($it['media_kind'] ?? 'video') !== 'video') continue;
            if (self::cwShowGroupKey($it) === $group) $idx[$group][] = $it;
        }
        foreach ($idx as &$list) {
            usort($list, function ($a, $b) {
                $sa = ($a['season'] ?? null) === null ? 0 : (int)$a['season'];
                $sb = ($b['season'] ?? null) === null ? 0 : (int)$b['season'];
                if ($sa !== $sb) return $sa <=> $sb;
                $ea = ($a['episode'] ?? null) === null ? PHP_INT_MAX : (int)$a['episode'];
                $eb = ($b['episode'] ?? null) === null ? PHP_INT_MAX : (int)$b['episode'];
                if ($ea !== $eb) return $ea <=> $eb;
                return strnatcasecmp((string)($a['rel'] ?? ''), (string)($b['rel'] ?? ''));
            });
        }
        unset($list);
        self::$cwEpisodeIndex = $idx;
        return $idx;
    }

    private static function cwEpSig(array $it): ?string {
        if (($it['season'] ?? null) === null || ($it['episode'] ?? null) === null) return null;
        return (int)$it['season'] . 'x' . (int)$it['episode'];
    }

    /** Next distinct episode after $cur in its show (skips duplicate files of the same SxxExx; prefers DirectPlay copy). */
    private static function cwNextEpisode(array $cur): ?array {
        $idx = self::cwEpisodeIndex($cur);
        $list = $idx[self::cwShowGroupKey($cur)] ?? [];
        $pos = -1;
        foreach ($list as $i => $it) { if (($it['id'] ?? '') === ($cur['id'] ?? '')) { $pos = $i; break; } }
        if ($pos < 0) return null;
        $curSig = self::cwEpSig($cur);
        $n = count($list);
        for ($i = $pos + 1; $i < $n; $i++) {
            $cand = $list[$i];
            $sig = self::cwEpSig($cand);
            if ($curSig !== null && $sig === $curSig) continue; // duplicate file of the episode just finished
            // Among duplicate files of this next SxxExx, prefer one that does not need convert.
            if ($sig !== null && self::needsConvert($cand, false)) {
                for ($j = $i + 1; $j < $n && self::cwEpSig($list[$j]) === $sig; $j++) {
                    if (!self::needsConvert($list[$j], false)) { $cand = $list[$j]; break; }
                }
            }
            return $cand;
        }
        return null;
    }

    /**
     * Build Continue Watching rows from a user's progress map.
     * - Movies / non-episode items: one entry each (unchanged), hidden when finished.
     * - TV/anime: collapsed to ONE entry per show, based on the most recently updated episode:
     *   unfinished -> that episode (cw_state=resume); finished -> next episode (cw_state=next),
     *   walking past already-finished episodes; show dropped when caught up.
     */
    public static function continueWatching(array $userProg, int $cwLimit): array {
        uasort($userProg, function ($a, $b) {
            return ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0);
        });
        $continue = [];
        $seenShows = [];
        foreach ($userProg as $itemId => $p) {
            if (count($continue) >= $cwLimit) break;
            if (!is_array($p)) continue;
            $it = self::item((string)$itemId);
            if (!$it) continue;
            $isEp = self::cwIsEpisode($it);
            $finished = self::progressFinished($p, $isEp);
            if (!$isEp) {
                if (($p['position'] ?? 0) < 5 || $finished) continue;
                $pub = self::publicItem($it, false);
                $pub['progress'] = $p;
                $pub['cw_state'] = 'resume';
                $continue[] = $pub;
                continue;
            }
            $gk = self::cwShowGroupKey($it);
            if (isset($seenShows[$gk])) continue;
            // Accidental opens (<5s, not finished) don't count as "last watched" for the show.
            if (!$finished && ($p['position'] ?? 0) < 5) continue;
            $seenShows[$gk] = true;
            if (!$finished) {
                $pub = self::publicItem($it, false);
                $pub['progress'] = $p;
                $pub['cw_state'] = 'resume';
                $pub['cw_group'] = $gk;
                $continue[] = $pub;
                continue;
            }
            $next = $it;
            $nextProg = null;
            for ($guard = 0; $guard < 50; $guard++) {
                $next = self::cwNextEpisode($next);
                if (!$next) break;
                $np = $userProg[$next['id']] ?? null;
                if (is_array($np) && self::progressFinished($np, true)) continue; // already seen, keep walking
                $nextProg = (is_array($np) && ($np['position'] ?? 0) >= 5) ? $np : null;
                break;
            }
            if (!$next) continue; // caught up on this show
            $pub = self::publicItem($next, false);
            $pub['progress'] = $nextProg;
            $pub['cw_state'] = $nextProg ? 'resume' : 'next';
            $pub['cw_group'] = $gk;
            $pub['cw_after'] = $it['id'];
            $continue[] = $pub;
        }
        return $continue;
    }

    public static function publicItems(array $items): array {
        MediaFacts::preload($items);
        return array_map(function ($it) { return self::publicItem($it, false); }, $items);
    }

    public static function publicItem(array $it, bool $checkPosterFiles = true): array {
        $it = self::enrichItemArt($it, false);
        $hasPoster = !empty($it['poster']);
        $type = $it['type'] ?? '';
        $kind = $it['media_kind'] ?? '';
        $postersOn = class_exists('Posters') && Posters::enabled();
        // Posters on: the UI lazy-fetches art, so has_poster is optimistic -- skip every per-item file check.
        if ($postersOn || !$checkPosterFiles) $hasPoster = true; // Card rows defer artwork existence to the image request.
        // Always resolve cache hits when possible (home used checkPosterFiles=false and hid CW/TV art).
        if (!$hasPoster && $type && in_array($type, ['tv','anime'], true) && !empty($it['show'])) {
            $key = self::showKey($it['section'] ?? '', $it['show']);
            if (class_exists('Posters')) {
                $showDir = self::showDirFromItem($it);
                $season = array_key_exists('season', $it) && $it['season'] !== null && $it['season'] !== ''
                    ? (int)$it['season'] : null;
                if ($season !== null && $season >= 0) {
                    $hasPoster = Posters::hasSeasonPoster($key, $season, $it['season_poster'] ?? null, $showDir);
                }
                if (!$hasPoster) {
                    $hasPoster = Posters::hasShowPoster($it['show_poster'] ?? null, $key, $showDir);
                }
            }
            // Optimistic: UI lazy-fetches season/show art on first view (?fetch=1) when missing.
            if (!$hasPoster && $postersOn) $hasPoster = true;
        } elseif (!$hasPoster && class_exists('Posters') && ($type === 'movies' || $type === 'disney' || ($kind === 'video' && empty($it['show'])))) {
            $hasPoster = Posters::hasMoviePoster($it['poster'] ?? null, (string)($it['id'] ?? ''));
            if (!$hasPoster && $postersOn) $hasPoster = true;
        } elseif (!$hasPoster && class_exists('Posters') && ($type === 'roms' || $kind === 'rom')) {
            $hasPoster = Posters::hasGamePoster($it['poster'] ?? null, (string)($it['id'] ?? ''));
            if (!$hasPoster && $postersOn) $hasPoster = true;
        }
        $epTitle = '';
        $isTvItem = in_array($type, ['tv', 'anime'], true) || (!empty($it['show']) && array_key_exists('season', $it));
        if ($isTvItem) {
            $epTitle = self::resolveEpisodeTitle($it, false);
        }
        $displayTitle = self::fixText((string)($it['title'] ?? ''));
        $isShowEp = !empty($it['show']) && in_array($it['type'] ?? '', ['tv','anime'], true);
        $isMovie = !$isShowEp && ($type === 'movies' || $type === 'disney' || ($kind === 'video' && empty($it['show'])));
        if ($isTvItem && $epTitle !== '') {
            $displayTitle = self::formatEpisodeDisplayTitle(
                $it['show'] ?? null,
                $it['season'] ?? null,
                $it['episode'] ?? null,
                $epTitle
            );
        }
        return [
            'id' => $it['id'],
            'section' => $it['section'],
            'type' => $it['type'],
            'title' => $displayTitle,
            'episode_title' => $epTitle !== '' ? $epTitle : null,
            'year' => $it['year'],
            'season' => $it['season'],
            'episode' => $it['episode'],
            'show' => ($it['show'] ?? null) !== null && $it['show'] !== '' ? self::fixText((string)$it['show']) : $it['show'],
            'show_key' => $isShowEp ? self::showKey($it['section'] ?? '', $it['show']) : null,
            // Clean URLs: /movies/<slug>, /tvshows/<show_slug>/season-<n>/episode-<m>
            'slug' => $isMovie ? Slugs::movie(['id' => $it['id'], 'title' => $displayTitle, 'year' => $it['year'] ?? null]) : null,
            'game_path' => ($it['media_kind'] ?? '') === 'rom' ? Slugs::game(['id' => $it['id'], 'title' => $displayTitle, 'system' => $it['system'] ?? null, 'emu_core' => $it['emu_core'] ?? null]) : null,
            'show_slug' => $isShowEp ? Slugs::show(self::showKey($it['section'] ?? '', $it['show']), self::fixText((string)$it['show'])) : null,
            'ext' => $it['ext'],
            'size' => $it['size'],
            'mtime' => $it['mtime'],
            'media_kind' => $it['media_kind'],
            'emu_core' => $it['emu_core'] ?? null,
            'system' => $it['system'] ?? null,
            'has_poster' => $hasPoster,
            'kind' => 'item',
            'needs_convert' => self::needsConvert($it, false),
        ];
    }

    /**
     * Incrementally add/update a single media file in the RAM catalogue
     * without a full library rescan.
     */
    /**
     * rewrite catalogue ROM entries from .cue → sibling .bin (keep item id).
     * Also refresh emu_core from folder so Mega Drive .bin stays segaMD and PS1 → psx.
     */
    public static function repairRomCueToBin(): array {
        $cat = self::get();
        $items = $cat['items'] ?? [];
        if (!is_array($items)) $items = [];
        $cueToBin = 0;
        $coreFixed = 0;
        foreach ($items as $i => $it) {
            if (!is_array($it)) continue;
            if (($it['media_kind'] ?? '') !== 'rom') continue;
            $path = (string)($it['path'] ?? '');
            $rel = (string)($it['rel'] ?? '');
            $ext = strtolower((string)($it['ext'] ?? pathinfo($path, PATHINFO_EXTENSION)));
            if ($ext === 'cue' && $path !== '') {
                $bin = preg_replace('/\.cue$/i', '.bin', $path);
                if (is_string($bin) && $bin !== '' && is_file($bin)) {
                    $it['path'] = $bin;
                    $it['rel'] = is_string($rel) && $rel !== '' ? (preg_replace('/\.cue$/i', '.bin', $rel) ?: $rel) : $rel;
                    $it['ext'] = 'bin';
                    $it['size'] = @filesize($bin) ?: (int)($it['size'] ?? 0);
                    $ext = 'bin';
                    $path = $bin;
                    $rel = (string)($it['rel'] ?? $rel);
                    $cueToBin++;
                }
            }
            $core = self::guessEmuCore($ext, $rel !== '' ? $rel : $path, $path);
            if ($core && ($it['emu_core'] ?? null) !== $core) {
                $it['emu_core'] = $core;
                $coreFixed++;
            } elseif ($core) {
                $it['emu_core'] = $core;
            }
            if ($rel !== '' || $path !== '') {
                $it['system'] = self::guessSystemFolder($rel !== '' ? $rel : $path);
            }
            $items[$i] = $it;
        }
        $cat['items'] = $items;
        if ($cueToBin || $coreFixed) {
            self::save($cat);
        }
        return ['cue_to_bin' => $cueToBin, 'core_fixed' => $coreFixed];
    }

    public static function ingestFile(string $fullPath, string $sectionId): ?array {
        $r = self::ingestFiles([[$fullPath, $sectionId]]);
        return $r[0] ?? null;
    }

    /**
     * Add/refresh several files in one catalogue write: build items, drop old entries for the same
     * paths via one lookup map, recount sections, save and clear caches once.
     * $files: list of [fullPath, sectionId]. Returns items in the same order (null where skipped).
     */
    public static function ingestFiles(array $files): array {
        $settings = Store::settings();
        $sections = [];
        foreach ($settings['sections'] ?? [] as $sec) {
            if (isset($sec['id'])) $sections[(string)$sec['id']] = $sec;
        }
        $scan = $settings['scan'] ?? [];
        $videoExt = array_map('strtolower', $scan['video_extensions'] ?? ['mp4','mkv','avi','m4v','webm']);
        $audioExt = array_map('strtolower', $scan['audio_extensions'] ?? ['mp3','flac','aac']);
        $rootReals = [];
        $built = [];
        foreach (array_values($files) as $i => $f) {
            $built[$i] = null;
            $fullPath = (string)($f[0] ?? ''); $sectionId = (string)($f[1] ?? '');
            $section = $sections[$sectionId] ?? null;
            if (!$section || $fullPath === '') continue;
            if (!array_key_exists($sectionId, $rootReals)) $rootReals[$sectionId] = \Vaultcast\Paths::real((string)($section['path'] ?? ''));
            $rootReal = $rootReals[$sectionId];
            $fullReal = \Vaultcast\Paths::real($fullPath);
            if ($rootReal === false || $fullReal === false || !is_file($fullReal)) continue;
            if (strpos($fullReal, $rootReal . '/') !== 0 && $fullReal !== $rootReal) continue;
            $rel = str_replace('\\', '/', substr($fullReal, strlen($rootReal) + 1));
            $built[$i] = self::buildItem($fullReal, $rel, $rootReal, $sectionId, $section['type'] ?? 'movies', $videoExt, $audioExt);
        }
        if (!array_filter($built)) return $built;

        $cat = self::get();
        if (!isset($cat['items']) || !is_array($cat['items'])) $cat['items'] = [];
        // path -> ids, built once (stored paths are already real paths with forward slashes)
        $byPath = [];
        foreach ($cat['items'] as $oldId => $old) {
            $op = (string)($old['path'] ?? '');
            if ($op !== '') $byPath[str_replace('\\', '/', $op)][] = $oldId;
        }
        foreach ($built as $item) {
            if (!$item) continue;
            $key = str_replace('\\', '/', (string)($item['path'] ?? ''));
            foreach ($byPath[$key] ?? [] as $oldId) {
                if ($oldId !== $item['id']) unset($cat['items'][$oldId]);
            }
            $cat['items'][$item['id']] = $item;
        }
        $cat['item_count'] = count($cat['items']);
        $cat['scanned_at'] = $cat['scanned_at'] ?? date('c');
        $cat['updated_at'] = date('c');

        // Refresh section counts lightly
        $counts = [];
        $showCounts = [];
        foreach ($cat['items'] as $it) {
            $sid = $it['section'] ?? '';
            if ($sid === '') continue;
            $counts[$sid] = ($counts[$sid] ?? 0) + 1;
            if (in_array($it['type'] ?? '', ['tv','anime'], true) && !empty($it['show'])) {
                if (!isset($showCounts[$sid])) $showCounts[$sid] = [];
                $showCounts[$sid][$it['show']] = true;
            }
        }
        foreach ($cat['sections'] ?? [] as &$sec) {
            $sid = $sec['id'] ?? '';
            $sec['count'] = $counts[$sid] ?? 0;
            if (isset($showCounts[$sid])) {
                $sec['show_count'] = count($showCounts[$sid]);
            }
        }
        unset($sec);

        self::save($cat); // save() clears the derived caches
        Store::invalidate('catalogue');
        return $built;
    }


    /** drop catalogue entries whose path is $path (exact or realpath). Returns removed ids. */
    public static function removePath(string $path): array {
        if ($path === '') return [];
        $cat = self::get();
        $ids = [];
        foreach ($cat['items'] ?? [] as $id => $it) {
            $p = (string)($it['path'] ?? '');
            if ($p === $path) { $ids[] = (string)$id; continue; }
            $pr = $p !== '' ? @\Vaultcast\Paths::real($p) : false;
            if ($pr !== false && $pr === $path) $ids[] = (string)$id;
        }
        return $ids ? self::removeIds($ids) : [];
    }

    /** remove items by id and refresh section counts. Returns removed ids. */
    public static function removeIds(array $ids): array {
        $cat = self::get();
        if (!isset($cat['items']) || !is_array($cat['items'])) return [];
        $removed = [];
        foreach ($ids as $id) {
            $id = (string)$id;
            if (isset($cat['items'][$id])) { unset($cat['items'][$id]); $removed[] = $id; }
        }
        if (!$removed) return [];
        $counts = [];
        $showCounts = [];
        foreach ($cat['items'] as $it) {
            $sid = $it['section'] ?? '';
            if ($sid === '') continue;
            $counts[$sid] = ($counts[$sid] ?? 0) + 1;
            if (in_array($it['type'] ?? '', ['tv','anime'], true) && !empty($it['show'])) {
                $showCounts[$sid][$it['show']] = true;
            }
        }
        foreach ($cat['sections'] ?? [] as &$sec) {
            $sid = $sec['id'] ?? '';
            $sec['count'] = $counts[$sid] ?? 0;
            if (isset($showCounts[$sid])) $sec['show_count'] = count($showCounts[$sid]);
        }
        unset($sec);
        $cat['item_count'] = count($cat['items']);
        $cat['updated_at'] = date('c');
        self::save($cat);
        Store::invalidate('catalogue'); self::invalidateDerivedCaches();
        return $removed;
    }

    /**
     * Item detail for the movie/show page. Instant: MariaDB item + disk-cached synopsis only.
     * Never blocks on Wikipedia/OMDb/TVMaze (those run via itemSynopsisFetch). Skips ffprobe
     * unless ?probe=1 / ?audio=1 — needs_convert uses the extension fallback.
     */
    public static function itemDetail(string $itemId, bool $fetchSynopsis = true): ?array {
        $it = self::item($itemId);
        if (!$it) return null;
        // checkPosterFiles=false: no extra disk walks; UI already lazy-loads posters.
        $pub = self::publicItem($it, false);
        $type = $it['type'] ?? '';
        $synopsis = null;
        $synopsisSource = null;
        $synopsisPending = false;
        if ($fetchSynopsis && class_exists('Posters')) {
            if (($type === 'tv' || $type === 'anime') && !empty($it['show'])) {
                $key = self::showKey($it['section'] ?? '', $it['show']);
                $synopsis = Posters::showSynopsisCached($key);
                $synopsisSource = $synopsis ? 'tvmaze' : null;
                $synopsisPending = $synopsis === null;
            } elseif ($type === 'movies' || $type === 'disney' || ($it['media_kind'] ?? '') === 'video') {
                if ($type === 'movies' || $type === 'disney' || empty($it['show'])) {
                    $c = Posters::movieSynopsisCached($it['id']);
                    $synopsis = $c['summary'];
                    $synopsisSource = $c['source'];
                    $synopsisPending = !empty($c['pending']);
                }
            }
        }
        $pub['synopsis'] = $synopsis;
        $pub['synopsis_source'] = $synopsisSource;
        $pub['synopsis_pending'] = $synopsisPending;
        // Extension-only: never ffprobe on the detail request (that blocked the whole PHP worker).
        $pub['needs_convert'] = self::needsConvert($it, false);
        $path = (string)($it['path'] ?? '');
        $facts = MediaFacts::get($it);
        $pub['file_exists'] = $path !== '' && (!$facts || !empty($facts['present']));
        $pub['path'] = $path; // admin/detail may need for diagnose; public UI can hide
        $audio = MediaFacts::audio($it);
        $pub['audio_tracks'] = $audio['audio_tracks'] ?? [];
        $pub['audio_count'] = $audio['audio_count'] ?? 0;
        $pub['multi_audio'] = !empty($audio['multi_audio']);
        if (!empty($_GET['probe']) || !empty($_GET['audio'])) {
            $probe = MediaProbe::probeSummary($path);
            $pub['audio_tracks'] = $probe['audio_tracks'] ?? [];
            $pub['audio_count'] = $probe['audio_count'] ?? 0;
            $pub['multi_audio'] = !empty($probe['multi_audio']);
        }
        return ['ok' => true, 'item' => $pub];
    }

    /** Background synopsis fill: may hit OMDb/Wikipedia/TVMaze. Call after itemDetail has painted. */
    public static function itemSynopsisFetch(string $itemId): ?array {
        $it = self::item($itemId);
        if (!$it || !class_exists('Posters')) return null;
        $type = $it['type'] ?? '';
        $synopsis = null;
        $source = null;
        try {
            // Background fill only — keep the single Windows php-cgi worker free.
            Posters::beginRemoteBudget(3.0);
            if (($type === 'tv' || $type === 'anime') && !empty($it['show'])) {
                $key = self::showKey($it['section'] ?? '', $it['show']);
                $synopsis = Posters::showSynopsis($key, $it['show']);
                $source = $synopsis ? 'tvmaze' : null;
            } elseif ($type === 'movies' || $type === 'disney' || (($it['media_kind'] ?? '') === 'video' && empty($it['show']))) {
                $synopsis = Posters::movieSynopsis($it['id'], $it['title'] ?? '', isset($it['year']) ? (int)$it['year'] : null);
                $source = $synopsis ? 'wikipedia' : null;
            } else {
                return null;
            }
        } finally {
            Posters::endRemoteBudget();
        }
        return ['ok' => true, 'item_id' => $itemId, 'synopsis' => $synopsis, 'synopsis_source' => $source];
    }


    /**
     * Cross-section catalogue search. Shows + movies/disney/roms first.
     * Does not touch RSS / network.
     */
    public static function search(string $q, int $limit = 40): array {
        $q = trim($q);
        if ($q === '') {
            return ['ok' => true, 'query' => '', 'catalogue' => [], 'count' => 0, 'weak' => true];
        }
        $ql = strtolower($q);
        $cat = \Vaultcast\Repository::document('catalogue', ['sections' => []]);
        $cat['items'] = \Vaultcast\Repository::searchItems($q);
        MediaFacts::preload($cat['items']);
        $hits = [];
        $seenShows = [];
        foreach ($cat['sections'] ?? [] as $sec) {
            $type = $sec['type'] ?? '';
            $sid = $sec['id'] ?? '';
            if ($type === 'tv' || $type === 'anime') {
                foreach (self::sectionShows($sid) as $show) {
                    $title = (string)($show['title'] ?? '');
                    if ($title === '' || stripos($title, $q) === false) continue;
                    $key = $show['show_key'] ?? $show['id'] ?? '';
                    if ($key === '' || isset($seenShows[$key])) continue;
                    $seenShows[$key] = true;
                    $pub = self::publicShow($show);
                    $pub['match'] = 'show';
                    $tl = strtolower($title);
                    $pub['score'] = ($tl === $ql) ? 100 : (strpos($tl, $ql) === 0 ? 80 : 50);
                    $hits[] = $pub;
                }
            }
        }
        foreach ($cat['items'] ?? [] as $it) {
            if (!is_array($it)) continue;
            $type = $it['type'] ?? '';
            if ($type === 'tv' || $type === 'anime') {
                $show = (string)($it['show'] ?? '');
                $key = $show !== '' ? self::showKey($it['section'] ?? '', $show) : '';
                if ($key !== '' && isset($seenShows[$key])) continue;
                $hay = ($it['title'] ?? '') . ' ' . $show;
                if (stripos($hay, $q) === false) continue;
                $pub = self::publicItem($it);
                $pub['match'] = 'episode';
                $pub['score'] = 30;
                $hits[] = $pub;
                continue;
            }
            $title = (string)($it['title'] ?? '');
            $hay = $title . ' ' . ($it['rel'] ?? '');
            if (stripos($hay, $q) === false) continue;
            $pub = self::publicItem($it);
            $pub['match'] = 'item';
            $tl = strtolower($title);
            $pub['score'] = ($tl === $ql) ? 95 : (strpos($tl, $ql) === 0 ? 75 : 45);
            $hits[] = $pub;
        }
        usort($hits, function ($a, $b) {
            $sa = (int)($a['score'] ?? 0);
            $sb = (int)($b['score'] ?? 0);
            if ($sa !== $sb) return $sb - $sa;
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        });
        $limit = max(1, min(100, $limit));
        $slice = array_slice($hits, 0, $limit);
        $count = count($slice);
        $weak = $count === 0 || ($count < 3 && (($slice[0]['score'] ?? 0) < 80));
        return [
            'ok' => true,
            'query' => $q,
            'catalogue' => $slice,
            'count' => $count,
            'total' => count($hits),
            'weak' => $weak,
        ];
    }

    /**
     * True when codecs are not already DirectPlay-ready (H.264 + AAC).
     * Uses ffprobe via MediaProbe (cached) — not file extension.
     * Falls back to extension only when probe is unavailable.
     */
    /**
     * @param bool $allowProbe When false (home/list cards), skip ffprobe — extension heuristic only.
     *              Convert UI / detail views pass true (default) for accurate H.264/AAC checks.
     */
    public static function needsConvert(array $it, bool $allowProbe = true): bool {
        if (($it['media_kind'] ?? '') !== 'video') return false;
        // Codecs probed once by the background facts pass (MariaDB); no ffprobe/stat per request.
        $facts = MediaFacts::get($it);
        if ($facts) return !empty($facts['needs_convert']);
        $path = (string)($it['path'] ?? '');
        if ($allowProbe && $path !== '' && is_file($path) && class_exists('MediaProbe')) {
            $probe = MediaProbe::probeSummary($path, 2, true);
            $vc = strtolower((string)($probe['video_codec'] ?? ''));
            $ac = '';
            if (!empty($probe['audio_tracks'][0]['codec'])) {
                $ac = strtolower((string)$probe['audio_tracks'][0]['codec']);
            }
            if ($vc !== '') {
                $h264 = ($vc === 'h264' || $vc === 'avc' || strpos($vc, 'h264') === 0 || strpos($vc, 'avc') === 0);
                $aacOk = ($ac === '' || $ac === 'aac' || $ac === 'mp4a' || strpos($ac, 'aac') === 0);
                // Already H.264 (+ AAC when audio present) — no convert required for DirectPlay codecs
                return !($h264 && $aacOk);
            }
        }
        // Probe skipped / failed / no path: extension fallback
        $ext = strtolower((string)($it['ext'] ?? ''));
        return $ext !== '' && !in_array($ext, ['mp4', 'm4v', 'webm', 'mp3', 'aac', 'flac'], true);
    }
}
