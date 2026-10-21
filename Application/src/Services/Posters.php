<?php
declare(strict_types=1);
/**
 * Poster resolution: local sidecar → server disk cache → remote (TVMaze / OMDb / Wikipedia / libretro).
 * Rate-limited remote fetches (~2 req/sec). Cache forever until deleted/rescan.
 * Lazy fill: callers pass $fetch=true on first detail view (?fetch=1).
 *
 * Movies: OMDb (when posters.omdb_api_key is set) → Wikipedia fallback.
 * TV/Anime: TVMaze (no key required).
 */
class Posters {
    private static $lastFetchAt = 0.0;
    public static function dir(): string {
        $d = STREAM_DATA . '/posters';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }
    public static function showCachePath(string $showKey): string {
        return self::dir() . '/show_' . preg_replace('/[^a-f0-9]/', '', $showKey) . '.jpg';
    }
    /** Season poster cache: season_{showKey}_s{N}.jpg (TVMaze relative season art). */
    public static function seasonCachePath(string $showKey, int $season): string {
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        $n = max(0, (int)$season);
        return self::dir() . '/season_' . $safe . '_s' . $n . '.jpg';
    }
    public static function movieCachePath(string $itemId): string {
        return self::dir() . '/movie_' . preg_replace('/[^a-f0-9]/', '', $itemId) . '.jpg';
    }
    public static function gameCachePath(string $itemId): string {
        return self::dir() . '/game_' . preg_replace('/[^a-f0-9]/', '', $itemId) . '.jpg';
    }
    /** Rematching invalidates converted artwork together with its original. */
    public static function clearCachedArtwork(string $original): void {
        $stem = pathinfo(basename($original), PATHINFO_FILENAME);
        if (!preg_match('/^(?:movie_|game_|show_|season_)[a-zA-Z0-9_-]+$/D', $stem)) return;
        foreach (['.jpg', '.jpeg', '.png', '.webp', '_thumb.webp', '_tiny.webp'] as $suffix) @unlink(self::dir() . '/' . $stem . $suffix);
    }
    public static function enabled(): bool {
        // Once per request: Store::settings() re-reads runtime ini files, and list/show pages call this per item.
        static $on = null;
        if ($on !== null) return $on;
        $s = Store::settings();
        $p = $s['posters'] ?? [];
        return $on = !(isset($p['enabled']) && !$p['enabled']);
    }
    /** OMDb API key, for other services (age ratings). */
    public static function omdbKey(): string { return self::omdbApiKey(); }
    /** Throttled JSON GET inside the current remote budget; null on any failure. */
    public static function remoteJson(string $url, array $headers = [], int $timeout = 8): ?array {
        self::throttle();
        $body = self::httpGet($url, false, $timeout, $headers);
        $j = $body === null ? null : json_decode($body, true);
        return is_array($j) ? $j : null;
    }
    /** OMDb record by IMDb id, or by title/year when no id is known. */
    public static function omdbById(string $imdb, string $title, ?int $year): ?array {
        $key = self::omdbApiKey();
        if ($key === '') return null;
        if (preg_match('/^tt\d{5,10}$/D', $imdb)) {
            $d = self::remoteJson('https://www.omdbapi.com/?' . http_build_query(['apikey' => $key, 'i' => $imdb, 'plot' => 'short']));
            return is_array($d) && strtolower((string)($d['Response'] ?? '')) === 'true' ? $d : null;
        }
        return $title !== '' ? self::omdbLookup($title, $year, $key) : null;
    }
    /** OMDb API key from settings (empty string when not configured). */
    private static function omdbApiKey(): string {
        $s = Store::settings();
        $p = $s['posters'] ?? [];
        $key = trim((string)($p['omdb_api_key'] ?? ''));
        return $key;
    }
    /**
     * Show folder on disk from a catalogue item (…/Show Name (1994)/Season 1/ep.mkv).
     */
    public static function showDirFromItem(array $item, bool $checkDisk = true): ?string {
        $path = str_replace('\\', '/', (string)($item['path'] ?? ''));
        $rel = str_replace('\\', '/', (string)($item['rel'] ?? ''));
        if ($path === '' || $rel === '') return null;
        $rel = ltrim($rel, '/');
        $parts = explode('/', $rel);
        $showFolder = $parts[0] ?? '';
        if ($showFolder === '') return null;
        // path should end with rel
        if (substr($path, -strlen($rel)) === $rel) {
            $root = rtrim(substr($path, 0, strlen($path) - strlen($rel)), '/');
            $dir = $root . '/' . $showFolder;
            return !$checkDisk || is_dir($dir) ? $dir : null;
        }
        // fallback: walk up from file to show folder name
        $dir = !$checkDisk || is_file($path) ? dirname($path) : $path;
        for ($i = 0; $i < 6; $i++) {
            if (basename($dir) === $showFolder && (!$checkDisk || is_dir($dir))) return $dir;
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return null;
    }
    /** Kodi/Emby-style show art in the show root folder. */
    public static function findLocalShowArt(?string $showDir): ?string {
        if (!$showDir || !is_dir($showDir)) return null;
        foreach (['poster.jpg','poster.png','folder.jpg','cover.jpg','cover.png','Poster.jpg','Poster.png'] as $name) {
            $p = $showDir . '/' . $name;
            if (is_file($p) && filesize($p) > 100) return $p;
        }
        return null;
    }
    /**
     * Kodi-style season art: season01-poster.jpg / season-specials-poster.jpg,
     * or poster.jpg inside Season N / S0N folders.
     */
    public static function findLocalSeasonArt(?string $showDir, int $season): ?string {
        if (!$showDir || !is_dir($showDir)) return null;
        $n = (int)$season;
        $candidates = [];
        if ($n === 0) {
            $candidates = [
                'season-specials-poster.jpg', 'season-specials-poster.png',
                'season00-poster.jpg', 'season0-poster.jpg',
                'season-specials-banner.jpg',
            ];
        } else {
            $pad = sprintf('%02d', $n);
            $candidates = [
                "season{$pad}-poster.jpg", "season{$pad}-poster.png",
                "season{$n}-poster.jpg", "season{$n}-poster.png",
                "Season{$pad}-poster.jpg", "Season {$n}-poster.jpg",
            ];
        }
        foreach ($candidates as $name) {
            $p = $showDir . '/' . $name;
            if (is_file($p) && filesize($p) > 100) return $p;
        }
        // Folder-level poster inside Season N / S0N / Specials
        $folderNames = [];
        if ($n === 0) {
            $folderNames = ['Specials', 'Season 0', 'Season 00', 'S00', 'S0'];
        } else {
            $pad = sprintf('%02d', $n);
            $folderNames = [
                "Season {$n}", "Season {$pad}", "Season{$n}", "Season{$pad}",
                "S{$pad}", "S{$n}", "Series {$n}", "Series {$pad}",
            ];
        }
        foreach ($folderNames as $folder) {
            $sub = $showDir . '/' . $folder;
            if (!is_dir($sub)) continue;
            foreach (['poster.jpg','poster.png','folder.jpg','cover.jpg','cover.png'] as $name) {
                $p = $sub . '/' . $name;
                if (is_file($p) && filesize($p) > 100) return $p;
            }
        }
        return null;
    }
    /** Prefer local file, then cached download. Does not hit network. */
    public static function localOrCachedShow(?string $localPoster, string $showKey, ?string $showDir = null): ?string {
        if ($localPoster && is_file($localPoster)) return $localPoster;
        $disk = self::findLocalShowArt($showDir);
        if ($disk) return $disk;
        $cached = self::showCachePath($showKey);
        if (is_file($cached) && filesize($cached) > 100) return $cached;
        return null;
    }
    public static function hasShowPoster(?string $localPoster, string $showKey, ?string $showDir = null): bool {
        return self::localOrCachedShow($localPoster, $showKey, $showDir) !== null;
    }
    public static function localOrCachedSeason(string $showKey, int $season, ?string $localPoster = null, ?string $showDir = null): ?string {
        if ($localPoster && is_file($localPoster)) return $localPoster;
        $disk = self::findLocalSeasonArt($showDir, $season);
        if ($disk) return $disk;
        $cached = self::seasonCachePath($showKey, $season);
        if (is_file($cached) && filesize($cached) > 100) return $cached;
        return null;
    }
    public static function hasSeasonPoster(string $showKey, int $season, ?string $localPoster = null, ?string $showDir = null): bool {
        return self::localOrCachedSeason($showKey, $season, $localPoster, $showDir) !== null;
    }
    /**
     * Resolve a season poster: local/cache, then TVMaze seasons image when $fetch.
     */
    public static function resolveSeason(string $showKey, string $showName, int $season, ?string $localPoster = null, bool $fetch = true, ?string $showDir = null): ?string {
        $hit = self::localOrCachedSeason($showKey, $season, $localPoster, $showDir);
        if ($hit) return $hit;
        if (!$fetch || !self::enabled() || $showName === '' || $season < 0) return null;
        return self::fetchTvMazeSeason($showKey, $showName, $season);
    }
    /**
     * Resolve a show poster path, optionally fetching from TVMaze on miss.
     * @return string|null absolute path to jpeg/png on disk
     */
    public static function resolveShow(string $showKey, string $showName, ?string $localPoster = null, bool $fetch = true, ?string $showDir = null): ?string {
        $hit = self::localOrCachedShow($localPoster, $showKey, $showDir);
        if ($hit) return $hit;
        if (!$fetch || !self::enabled() || $showName === '') return null;
        $pin = self::showMetaPin($showKey);
        if ($pin) {
            foreach (self::pinnedPosterUrls($pin) as $u) {
                $saved = self::downloadToCache(self::showCachePath($showKey), $u);
                if ($saved) return $saved;
            }
        }
        return self::fetchTvMazeShow($showKey, $showName);
    }
    public static function resolveItem(array $item, bool $fetch = true): ?string {
        $type = $item['type'] ?? '';
        $kind = $item['media_kind'] ?? '';
        $showDir = self::showDirFromItem($item);
        // Episode-specific sidecar (not the show-root poster reused as item poster)
        if (!empty($item['poster']) && is_file($item['poster'])) {
            $showPoster = $item['show_poster'] ?? null;
            if (!$showPoster || !is_file($showPoster)) {
                $showPoster = self::findLocalShowArt($showDir);
            }
            $sameAsShow = $showPoster && \Vaultcast\Paths::real($item['poster']) && \Vaultcast\Paths::real($showPoster)
                && \Vaultcast\Paths::real($item['poster']) === \Vaultcast\Paths::real($showPoster);
            // Also treat show-root poster.jpg as non-episode when path matches findLocalShowArt
            if (!$sameAsShow && $showDir) {
                $base = basename((string)$item['poster']);
                if (in_array(strtolower($base), ['poster.jpg','poster.png','folder.jpg','cover.jpg','cover.png'], true)
                    && dirname((string)$item['poster']) === $showDir) {
                    $sameAsShow = true;
                }
            }
            if (!$sameAsShow) {
                return $item['poster'];
            }
        }
        if (($type === 'tv' || $type === 'anime') && !empty($item['show'])) {
            $key = Catalogue::showKey($item['section'] ?? '', $item['show']);
            $localShow = $item['show_poster'] ?? null;
            if (!$localShow || !is_file($localShow)) {
                $localShow = self::findLocalShowArt($showDir);
            }
            $season = array_key_exists('season', $item) && $item['season'] !== null && $item['season'] !== ''
                ? (int)$item['season'] : null;
            if ($season === null && class_exists('Catalogue')) {
                $parsed = Catalogue::seasonEpisodeFromRel((string)($item['rel'] ?? ''));
                if ($parsed['season'] !== null) $season = (int)$parsed['season'];
            }
            $localSeason = $item['season_poster'] ?? null;
            if ($season !== null && (!$localSeason || !is_file($localSeason))) {
                $localSeason = self::findLocalSeasonArt($showDir, $season);
            }
            // Prefer relative season art, then show poster
            if ($season !== null && $season >= 0) {
                $seasonPath = self::resolveSeason($key, (string)$item['show'], $season, $localSeason, $fetch, $showDir);
                if ($seasonPath) return $seasonPath;
            }
            return self::resolveShow($key, $item['show'], $localShow, $fetch, $showDir);
        }
        // Movies / Disney: local cache, then OMDb (if key set) → Wikipedia when $fetch
        if ($type === 'movies' || $type === 'disney' || ($kind === 'video' && empty($item['show']))) {
            $id = (string)($item['id'] ?? '');
            $cached = self::movieCachePath($id);
            if (is_file($cached) && filesize($cached) > 100) return $cached;
            // Local sidecar next to the movie file
            $path = (string)($item['path'] ?? '');
            if ($path !== '') {
                $dir = is_file($path) ? dirname($path) : $path;
                foreach (array_merge(['poster.jpg','poster.png','folder.jpg','cover.jpg','cover.png','Poster.jpg'], array_map(static function ($ext) use ($path) { return pathinfo($path, PATHINFO_FILENAME) . '-poster.' . $ext; }, ['jpg','jpeg','png','webp'])) as $name) {
                    $p = $dir . '/' . $name;
                    if (is_file($p) && filesize($p) > 100) {
                        return self::adoptFile($cached, $p) ?? $p;
                    }
                }
            }
            if ($fetch && self::enabled() && $id !== '') {
                return self::fetchMoviePoster(
                    $id,
                    (string)($item['title'] ?? ''),
                    isset($item['year']) ? (int)$item['year'] : null
                );
            }
            return null;
        }
        // ROMs / games: local cache, then libretro / Wikipedia when $fetch
        if ($type === 'roms' || $kind === 'rom') {
            $id = (string)($item['id'] ?? '');
            $cached = self::gameCachePath($id);
            if (is_file($cached) && filesize($cached) > 100) return $cached;
            if ($fetch && self::enabled() && $id !== '') {
                return self::fetchGamePoster($item);
            }
            return null;
        }
        return null;
    }
    public static function hasGamePoster(?string $localPoster, string $itemId): bool {
        if ($localPoster && is_file($localPoster)) return true;
        $cached = self::gameCachePath($itemId);
        return is_file($cached) && filesize($cached) > 100;
    }
    public static function hasMoviePoster(?string $localPoster, string $itemId): bool {
        if ($localPoster && is_file($localPoster)) return true;
        $cached = self::movieCachePath($itemId);
        return is_file($cached) && filesize($cached) > 100;
    }
    /** Wall-clock deadline (microtime) for remote OMDb/Wikipedia/TVMaze work. Null = unlimited. */
    private static $remoteDeadline = null;

    /**
     * Cap remote metadata work so a single php-cgi worker (Windows install) cannot
     * stall every later /items or /poster request. Call at the start of a request
     * that may hit the network; clear in a finally.
     */
    public static function beginRemoteBudget(float $seconds = 2.5): void {
        self::$remoteDeadline = microtime(true) + max(0.4, $seconds);
    }
    public static function endRemoteBudget(): void { self::$remoteDeadline = null; }
    public static function remoteBudgetExpired(): bool {
        return self::$remoteDeadline !== null && microtime(true) >= self::$remoteDeadline;
    }
    public static function remoteSecondsLeft(): ?float {
        return self::$remoteDeadline === null ? null : max(0.0, self::$remoteDeadline - microtime(true));
    }

    private static function throttle(): void {
        if (self::remoteBudgetExpired()) return;
        $now = microtime(true);
        $elapsed = $now - self::$lastFetchAt;
        $minGap = 0.35;
        // Stay polite to remote APIs, but never sleep past the request budget.
        if ($elapsed < $minGap) {
            $sleep = $minGap - $elapsed;
            if (self::$remoteDeadline !== null) {
                $left = self::$remoteDeadline - microtime(true);
                if ($left <= 0) return;
                $sleep = min($sleep, $left);
            }
            if ($sleep > 0) usleep((int)($sleep * 1e6));
        }
        self::$lastFetchAt = microtime(true);
    }
    /**
     * Copy a local sidecar into the server poster cache so later requests
     * can be served with a single stat and no catalogue/TVMaze work.
     * Returns dest when the cache file is usable, otherwise the source.
     */
    public static function adoptFile(string $dest, ?string $src): ?string {
        if (is_file($dest) && filesize($dest) > 100) return $dest;
        if (!$src || !is_file($src) || filesize($src) < 100) return null;
        $srcReal = \Vaultcast\Paths::real($src);
        if ($srcReal && \Vaultcast\Paths::real($dest) === $srcReal) return $dest;
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $dest . '.tmp.' . getmypid();
        if (@copy($src, $tmp)) {
            if (!@rename($tmp, $dest)) @unlink($tmp);
            if (is_file($dest) && filesize($dest) > 100) return $dest;
        } else {
            @unlink($tmp);
        }
        return $src;
    }

    /**
     * Title variants that improve remote matching (strip year/quality, The/article flips).
     * @return list<string>
     */
    public static function searchTitleVariants(string $title): array {
        $raw = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        $out = [];
        if ($raw !== '') $out[] = $raw;
        $noYear = trim((string)preg_replace('/\s*[\[\(]?(?:19|20)\d{2}[\]\)]?\s*$/u', '', $raw));
        $clean = (string)preg_replace(
            '/\b(1080p|720p|2160p|4k|uhd|bluray|blu-ray|webrip|web-dl|hdtv|x264|x265|hevc|aac|dts|yify|rarbg|proper|repack|extended|remastered|directors?.?cut)\b/iu',
            '',
            $noYear
        );
        $clean = trim((string)preg_replace('/[\._]+/', ' ', $clean));
        $clean = trim((string)preg_replace('/\s+/', ' ', $clean));
        if ($clean !== '' && !in_array($clean, $out, true)) $out[] = $clean;
        $noCountry = trim((string)preg_replace('/\s*[\[\(][A-Za-z]{2}[\]\)]\s*/u', ' ', $clean));
        $noCountry = trim((string)preg_replace('/\s+/', ' ', $noCountry));
        if ($noCountry !== '' && !in_array($noCountry, $out, true)) $out[] = $noCountry;
        $base = $noCountry !== '' ? $noCountry : $clean;
        if ($base !== '') {
            if (preg_match('/^The\s+(.+)$/iu', $base, $m)) {
                if (!in_array($m[1], $out, true)) $out[] = $m[1];
                $comma = $m[1] . ', The';
                if (!in_array($comma, $out, true)) $out[] = $comma;
            } elseif (!preg_match('/^The\s+/iu', $base)) {
                $withThe = 'The ' . $base;
                if (!in_array($withThe, $out, true)) $out[] = $withThe;
            }
            if (strpos($base, '&') !== false) {
                $and = str_replace('&', 'and', $base);
                if (!in_array($and, $out, true)) $out[] = $and;
            }
        }
        return array_values(array_unique(array_filter($out, static function ($s) {
            return is_string($s) && trim($s) !== '';
        })));
    }

    /** Persist a TVMaze show payload image into the show cache. */
    private static function saveShowImageFromPayload(string $dest, array $data): ?string {
        if (is_file($dest) && filesize($dest) > 100) return $dest;
        $img = $data['image']['original'] ?? ($data['image']['medium'] ?? null);
        if (!$img || !is_string($img)) return null;
        self::throttle();
        $bin = self::httpGet($img, true, 8);
        if ($bin === null || strlen($bin) < 100) return null;
        return self::writeCacheFile($dest, $bin);
    }

    public static function fetchTvMazeShow(string $showKey, string $showName): ?string {
        $dest = self::showCachePath($showKey);
        if (is_file($dest) && filesize($dest) > 100) return $dest;
        // pinned show (Settings -> Fix matching) wins over name search
        $pin = class_exists('Matching') ? Matching::pinnedTvmazeId($showKey) : null;
        if ($pin === 0) return null;
        if ($pin) {
            self::throttle();
            $json = self::httpGet('https://api.tvmaze.com/shows/' . (int)$pin, false, 8);
            if ($json !== null) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $saved = self::saveShowImageFromPayload($dest, $data);
                    if ($saved) return $saved;
                }
            }
        }
        $variants = self::searchTitleVariants($showName);
        if ($variants === []) return null;
        // 1) singlesearch with cleaned title variants
        foreach (array_slice($variants, 0, 4) as $q) {
            self::throttle();
            $json = self::httpGet('https://api.tvmaze.com/singlesearch/shows?q=' . rawurlencode($q), false, 8);
            if ($json === null) continue;
            $data = json_decode($json, true);
            if (!is_array($data) || empty($data['id'])) continue;
            // Prefer a decent title match so we do not cache the wrong show
            $score = class_exists('Matching')
                ? Matching::titleScore($showName, (string)($data['name'] ?? ''))
                : 80.0;
            if ($score < 55.0 && count($variants) > 1) continue;
            $saved = self::saveShowImageFromPayload($dest, $data);
            if ($saved) return $saved;
        }
        // 2) richer search API — pick best titleScore (>=70)
        if (class_exists('Matching')) {
            $best = null;
            $bestScore = 0.0;
            foreach (array_slice($variants, 0, 3) as $q) {
                foreach (Matching::tvmazeSearch($q) as $row) {
                    if (!is_array($row) || empty($row['id'])) continue;
                    $sc = Matching::titleScore($showName, (string)($row['title'] ?? ''));
                    if (!empty($row['score'])) $sc = max($sc, min(95.0, (float)$row['score'] * 100.0));
                    if ($sc > $bestScore) {
                        $bestScore = $sc;
                        $best = $row;
                    }
                }
                if ($bestScore >= 92.0) break;
            }
            if ($best && $bestScore >= 70.0) {
                self::throttle();
                $json = self::httpGet('https://api.tvmaze.com/shows/' . (int)$best['id'], false, 8);
                if ($json !== null) {
                    $data = json_decode($json, true);
                    if (is_array($data)) {
                        $saved = self::saveShowImageFromPayload($dest, $data);
                        if ($saved) return $saved;
                    }
                }
                // search row may already carry a medium image URL
                if (!empty($best['image']) && is_string($best['image'])) {
                    $saved = self::downloadToCache($dest, $best['image']);
                    if ($saved) return $saved;
                }
            }
        }
        return null;
    }
    /**
     * Cached TVMaze seasons list (includes per-season image URLs).
     * @return array{id:int,seasons:list}|null
     */
    public static function tvMazeSeasonsMeta(string $showKey, string $showName, bool $refresh = false): ?array {
        if ($showName === '') return null;
        $cacheDir = STREAM_DATA . '/tvmaze';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        $cachePath = $cacheDir . '/show_' . $safe . '_seasons.json';
        $ttl = 7 * 86400;
        if (!$refresh && is_file($cachePath) && (time() - filemtime($cachePath)) < $ttl) {
            $j = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($j) && !empty($j['seasons'])) return $j;
        }
        if (class_exists('Matching') && Matching::pinnedTvmazeId($showKey) === 0) return null;
        // Prefer show id from episode guide cache to avoid an extra search
        $tvId = 0;
        $guide = self::tvMazeEpisodeGuide($showKey, $showName, false);
        if (is_array($guide) && !empty($guide['id'])) $tvId = (int)$guide['id'];
        if ($tvId <= 0) {
            self::throttle();
            $url = 'https://api.tvmaze.com/singlesearch/shows?q=' . rawurlencode($showName);
            $json = self::httpGet($url, false, 8);
            if ($json === null) return null;
            $data = json_decode($json, true);
            if (!is_array($data) || empty($data['id'])) return null;
            $tvId = (int)$data['id'];
        }
        self::throttle();
        $sj = self::httpGet('https://api.tvmaze.com/shows/' . $tvId . '/seasons', false, 8);
        if ($sj === null) return null;
        $rows = json_decode($sj, true);
        if (!is_array($rows)) return null;
        $out = [
            'id' => $tvId,
            'fetched_at' => date('c'),
            'seasons' => [],
        ];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $num = isset($row['number']) ? (int)$row['number'] : null;
            if ($num === null) continue;
            $img = null;
            if (!empty($row['image']) && is_array($row['image'])) {
                $img = $row['image']['original'] ?? ($row['image']['medium'] ?? null);
            }
            $out['seasons'][] = [
                'number' => $num,
                'name' => $row['name'] ?? null,
                'premiere' => $row['premiereDate'] ?? null,
                'image' => is_string($img) ? $img : null,
            ];
        }
        @file_put_contents($cachePath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $out;
    }
    /** Download TVMaze relative season poster into season cache. */
    public static function fetchTvMazeSeason(string $showKey, string $showName, int $season): ?string {
        $dest = self::seasonCachePath($showKey, $season);
        if (is_file($dest) && filesize($dest) > 100) return $dest;
        $meta = self::tvMazeSeasonsMeta($showKey, $showName, false);
        if (!is_array($meta)) return null;
        $imgUrl = null;
        foreach ($meta['seasons'] as $s) {
            if (!is_array($s)) continue;
            if ((int)($s['number'] ?? -1) === (int)$season) {
                $imgUrl = $s['image'] ?? null;
                break;
            }
        }
        if (!$imgUrl || !is_string($imgUrl)) return null;
        self::throttle();
        $bin = self::httpGet($imgUrl, true, 8);
        if ($bin === null || strlen($bin) < 100) return null;
        $tmp = $dest . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $bin, LOCK_EX) === false) return null;
        if (!rename($tmp, $dest)) {
            @unlink($tmp);
            return null;
        }
        return $dest;
    }
    /** Fast local/cache check for movie/Disney art (no network). */
    private static function movieHasArt(array $item): bool {
        $id = (string)($item['id'] ?? '');
        if ($id !== '' && self::hasMoviePoster($item['poster'] ?? null, $id)) return true;
        if (!empty($item['poster']) && is_file((string)$item['poster']) && filesize((string)$item['poster']) > 100) return true;
        $path = (string)($item['path'] ?? '');
        if ($path === '') return false;
        $dir = is_file($path) ? dirname($path) : $path;
        if (!is_dir($dir)) return false;
        foreach (['poster.jpg','poster.png','folder.jpg','cover.jpg','cover.png','Poster.jpg'] as $name) {
            $p = $dir . '/' . $name;
            if (is_file($p) && filesize($p) > 100) return true;
        }
        return false;
    }

    /**
     * Coverage snapshot for Settings → Posters (local + cache only, no remote calls).
     * @return array{ok:bool,enabled:bool,shows:array,movies:array,games:array,missing_sample:list,cache_dir:string,cache_files:int}
     */
    public static function coverage(): array {
        $started = microtime(true);
        $cat = Catalogue::get();
        $shows = ['total' => 0, 'have' => 0, 'missing' => 0];
        $movies = ['total' => 0, 'have' => 0, 'missing' => 0];
        $games = ['total' => 0, 'have' => 0, 'missing' => 0];
        $sample = [];
        $seenShow = [];
        foreach ($cat['sections'] ?? [] as $sec) {
            if (!is_array($sec)) continue;
            $type = (string)($sec['type'] ?? '');
            $sid = (string)($sec['id'] ?? '');
            if ($type === 'tv' || $type === 'anime') {
                try {
                    $list = Catalogue::sectionShows($sid);
                } catch (Throwable $e) {
                    continue;
                }
                foreach ($list as $show) {
                    if (!is_array($show)) continue;
                    $key = (string)($show['show_key'] ?? '');
                    if ($key === '' || isset($seenShow[$key])) continue;
                    $seenShow[$key] = true;
                    $shows['total']++;
                    $title = (string)($show['title'] ?? '');
                    $local = $show['local_poster'] ?? null;
                    $dir = null;
                    if (!empty($show['path']) && is_dir((string)$show['path'])) $dir = (string)$show['path'];
                    if (self::localOrCachedShow(is_string($local) ? $local : null, $key, $dir)) {
                        $shows['have']++;
                    } else {
                        $shows['missing']++;
                        if (count($sample) < 12) {
                            $sample[] = ['kind' => 'show', 'title' => $title, 'section' => $sid];
                        }
                    }
                }
            }
        }
        foreach ($cat['items'] ?? [] as $it) {
            if (!is_array($it)) continue;
            $type = (string)($it['type'] ?? '');
            $kind = (string)($it['media_kind'] ?? '');
            if ($type === 'movies' || $type === 'disney' || ($kind === 'video' && empty($it['show']))) {
                $movies['total']++;
                if (self::movieHasArt($it)) {
                    $movies['have']++;
                } else {
                    $movies['missing']++;
                    if (count($sample) < 12) {
                        $sample[] = ['kind' => 'movie', 'title' => (string)($it['title'] ?? ''), 'section' => (string)($it['section'] ?? '')];
                    }
                }
            } elseif ($type === 'roms' || $kind === 'rom') {
                $games['total']++;
                $id = (string)($it['id'] ?? '');
                if ($id !== '' && self::hasGamePoster($it['poster'] ?? null, $id)) {
                    $games['have']++;
                } else {
                    $games['missing']++;
                    if (count($sample) < 12) {
                        $sample[] = ['kind' => 'game', 'title' => (string)($it['title'] ?? ''), 'section' => (string)($it['section'] ?? '')];
                    }
                }
            }
        }
        $cacheDir = self::dir();
        $cacheFiles = 0;
        if (is_dir($cacheDir)) {
            foreach (scandir($cacheDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                if (is_file($cacheDir . '/' . $f)) $cacheFiles++;
            }
        }
        $missingTotal = $shows['missing'] + $movies['missing'] + $games['missing'];
        $haveTotal = $shows['have'] + $movies['have'] + $games['have'];
        $allTotal = $shows['total'] + $movies['total'] + $games['total'];
        return [
            'ok' => true,
            'enabled' => self::enabled(),
            'omdb_configured' => self::omdbApiKey() !== '',
            'shows' => $shows,
            'movies' => $movies,
            'games' => $games,
            'totals' => [
                'total' => $allTotal,
                'have' => $haveTotal,
                'missing' => $missingTotal,
                'pct' => $allTotal > 0 ? round(100.0 * $haveTotal / $allTotal, 1) : 100.0,
            ],
            'missing_sample' => $sample,
            'cache_dir' => $cacheDir,
            'cache_files' => $cacheFiles,
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Prefetch missing posters across shows / movies / games.
     * Time-budgeted to avoid nginx/php-fpm 502. Call repeatedly until remaining=0.
     * @param list<string>|string $kinds
     */
    public static function prefetchMissing(int $limit = 8, $kinds = null): array {
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');
        $budget = 18.0;
        $started = microtime(true);
        if ($kinds === null || $kinds === '' || $kinds === 'all') {
            $kinds = ['shows', 'movies', 'games'];
        }
        if (is_string($kinds)) {
            $kinds = array_filter(array_map('trim', explode(',', $kinds)));
        }
        if (!is_array($kinds) || $kinds === []) $kinds = ['shows', 'movies', 'games'];
        $kinds = array_values(array_unique(array_map('strval', $kinds)));
        $limit = max(1, min(12, $limit));

        $cat = Catalogue::get();
        $done = [];
        $skipped = 0;
        $skippedFail = 0;
        $failed = [];
        $errors = [];
        $timedOut = false;
        $examined = 0;
        $byKind = ['shows' => 0, 'movies' => 0, 'games' => 0];

        $wantShows = in_array('shows', $kinds, true);
        $wantMovies = in_array('movies', $kinds, true);
        $wantGames = in_array('games', $kinds, true);

        // Build a missing-only queue first so large libraries do not burn the time budget
        // re-checking titles that already have local/cache art.
        $queue = [];
        if ($wantShows) {
            $seenShow = [];
            foreach ($cat['sections'] ?? [] as $sec) {
                if (!is_array($sec)) continue;
                $type = (string)($sec['type'] ?? '');
                if ($type !== 'tv' && $type !== 'anime') continue;
                try {
                    $list = Catalogue::sectionShows((string)$sec['id']);
                } catch (Throwable $e) {
                    $errors[] = 'section '.$sec['id'].': '.$e->getMessage();
                    continue;
                }
                foreach ($list as $show) {
                    if (!is_array($show)) continue;
                    $key = (string)($show['show_key'] ?? '');
                    $title = (string)($show['title'] ?? '');
                    if ($key === '' || isset($seenShow[$key])) continue;
                    $seenShow[$key] = true;
                    $local = $show['local_poster'] ?? null;
                    if (self::localOrCachedShow(is_string($local) ? $local : null, $key)) {
                        $skipped++;
                        continue;
                    }
                    if (self::recentlyFailed('show', $key)) {
                        $skippedFail++;
                        continue;
                    }
                    $queue[] = ['kind' => 'show', 'key' => $key, 'title' => $title];
                }
            }
        }
        if ($wantMovies || $wantGames) {
            foreach ($cat['items'] ?? [] as $it) {
                if (!is_array($it)) continue;
                $type = (string)($it['type'] ?? '');
                $mkind = (string)($it['media_kind'] ?? '');
                $title = (string)($it['title'] ?? '');
                $id = (string)($it['id'] ?? '');
                if ($wantMovies && ($type === 'movies' || $type === 'disney' || ($mkind === 'video' && empty($it['show'])))) {
                    if (self::movieHasArt($it)) { $skipped++; continue; }
                    if ($id !== '' && self::recentlyFailed('movie', $id)) { $skippedFail++; continue; }
                    $queue[] = ['kind' => 'movie', 'id' => $id, 'title' => $title, 'year' => isset($it['year']) ? (int)$it['year'] : null, 'item' => $it];
                    continue;
                }
                if ($wantGames && ($type === 'roms' || $mkind === 'rom')) {
                    if ($id !== '' && self::hasGamePoster($it['poster'] ?? null, $id)) { $skipped++; continue; }
                    if ($id !== '' && self::recentlyFailed('game', $id)) { $skippedFail++; continue; }
                    $queue[] = ['kind' => 'game', 'id' => $id, 'title' => $title, 'item' => $it];
                }
            }
        }

        $queueMissing = count($queue);
        foreach ($queue as $job) {
            if (count($done) >= $limit) break;
            if ((microtime(true) - $started) >= $budget) { $timedOut = true; break; }
            $examined++;
            $kind = (string)($job['kind'] ?? '');
            $title = (string)($job['title'] ?? '');
            try {
                if ($kind === 'show') {
                    $key = (string)$job['key'];
                    $path = self::fetchTvMazeShow($key, $title);
                    if ($path) { $done[] = ['kind' => 'show', 'title' => $title, 'path' => $path]; $byKind['shows']++; self::clearFailed('show', $key); }
                    else { $failed[] = ['kind' => 'show', 'title' => $title]; self::markFailed('show', $key); }
                } elseif ($kind === 'movie') {
                    $id = (string)($job['id'] ?? '');
                    if ($id === '' || $title === '') { $failed[] = ['kind' => 'movie', 'title' => $title ?: $id]; continue; }
                    $path = self::fetchMoviePoster($id, $title, $job['year'] ?? null);
                    if ($path) { $done[] = ['kind' => 'movie', 'title' => $title, 'path' => $path]; $byKind['movies']++; self::clearFailed('movie', $id); }
                    else { $failed[] = ['kind' => 'movie', 'title' => $title]; self::markFailed('movie', $id); }
                } elseif ($kind === 'game') {
                    $id = (string)($job['id'] ?? '');
                    $it = is_array($job['item'] ?? null) ? $job['item'] : ['id' => $id, 'title' => $title];
                    if ($id === '') { $failed[] = ['kind' => 'game', 'title' => $title]; continue; }
                    $path = self::fetchGamePoster($it);
                    if ($path) { $done[] = ['kind' => 'game', 'title' => $title, 'path' => $path]; $byKind['games']++; self::clearFailed('game', $id); }
                    else { $failed[] = ['kind' => 'game', 'title' => $title]; if(!self::remoteBudgetExpired())self::markFailed('game', $id); }
                }
            } catch (Throwable $e) {
                $failed[] = ['kind' => $kind, 'title' => $title];
                $errors[] = $title.': '.$e->getMessage();
            }
        }

        $cov = self::coverage();
        $failTitles = [];
        foreach ($failed as $f) {
            $failTitles[] = is_array($f) ? ((string)($f['kind'] ?? '') . ': ' . (string)($f['title'] ?? '')) : (string)$f;
        }
        $remainingAfter = max(0, $queueMissing - count($done) - count($failed));
        // Coverage missing includes hard-fails; fetchable is what Keep fetching can still try.
        $coverageMissing = (int)(($cov['totals']['missing'] ?? $remainingAfter));
        $remainingFetchable = $remainingAfter; // untried in this build after skips
        return [
            'ok' => true,
            'fetched' => count($done),
            'skipped_existing' => $skipped,
            'skipped_recent_fail' => $skippedFail,
            'failed' => $failTitles,
            'failed_detail' => $failed,
            'errors' => $errors,
            'timed_out' => $timedOut,
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
            'limit' => $limit,
            'kinds' => $kinds,
            'examined' => $examined,
            'queued_missing' => $queueMissing,
            'by_kind' => $byKind,
            'items' => $done,
            'remaining' => $coverageMissing,
            'remaining_fetchable' => $remainingFetchable,
            'coverage' => $cov,
            // Continue while time budget hit with work left, or untried fetchable titles remain
            'more' => $timedOut || ($remainingFetchable > 0),
        ];
    }

    /**
     * Resolve TVMaze show id + official episode list (cached under data/tvmaze/).
     * No API key. Returns null on failure.
     * @return array{id:int,name:string,episodes:list}|null
     */
    public static function tvMazeEpisodeGuide(string $showKey, string $showName, bool $refresh = false): ?array {
        if ($showName === '') return null;
        $cacheDir = STREAM_DATA . '/tvmaze';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        $cachePath = $cacheDir . '/show_' . $safe . '_episodes.json';
        $ttl = 7 * 86400;
        $pin = class_exists('Matching') ? Matching::pinnedTvmazeId($showKey) : null;
        if ($pin === 0) return null; // pinned to "no online match"
        if (!$refresh && $pin && is_file($cachePath)) {
            $jp = json_decode((string)@file_get_contents($cachePath), true);
            if (!is_array($jp) || (int)($jp['id'] ?? 0) !== (int)$pin) $refresh = true;
        }
        if (!$refresh && is_file($cachePath) && (time() - filemtime($cachePath)) < $ttl) {
            $j = json_decode((string)file_get_contents($cachePath), true);
            // Refresh once if older cache lacks synopsis summary
            if (is_array($j) && !empty($j['episodes']) && array_key_exists('summary', $j)) return $j;
            if (is_array($j) && !empty($j['episodes']) && empty($j['summary'])) {
                // fall through to refetch and upgrade cache with summary
            } elseif (is_array($j) && !empty($j['episodes'])) {
                return $j;
            }
        }
        // Negative cache: avoid hammering TVMaze for unmatched titles
        $negPath = $cacheDir . '/show_' . $safe . '_miss.json';
        if (!$refresh && !$pin && is_file($negPath) && (time() - filemtime($negPath)) < 86400) {
            return null;
        }
        self::throttle();
        $url = $pin
            ? 'https://api.tvmaze.com/shows/' . (int)$pin
            : 'https://api.tvmaze.com/singlesearch/shows?q=' . rawurlencode($showName);
        $json = self::httpGet($url);
        if ($json === null) {
            @file_put_contents($negPath, json_encode(['miss' => true, 'at' => date('c')]));
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['id'])) {
            @file_put_contents($negPath, json_encode(['miss' => true, 'at' => date('c')]));
            return null;
        }
        $tvId = (int)$data['id'];
        self::throttle();
        $epJson = self::httpGet('https://api.tvmaze.com/shows/' . $tvId . '/episodes?specials=1');
        if ($epJson === null) return null;
        $eps = json_decode($epJson, true);
        if (!is_array($eps)) return null;
        $summary = isset($data['summary']) ? trim(html_entity_decode(strip_tags((string)$data['summary']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $out = [
            'id' => $tvId,
            'externals' => $data['externals'] ?? [],
            'name' => $data['name'] ?? $showName,
            'premiered' => $data['premiered'] ?? null,
            'summary' => $summary !== '' ? $summary : null,
            'genres' => $data['genres'] ?? [],
            'status' => $data['status'] ?? null,
            // persist network/webChannel for library filter chips
            'network' => is_array($data['network'] ?? null) ? ($data['network'] ?? null) : null,
            'webChannel' => is_array($data['webChannel'] ?? null) ? ($data['webChannel'] ?? null) : null,
            'with_specials' => true,
            'pinned' => $pin ? true : false,
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
        @file_put_contents($cachePath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        BrowseIndex::dirty($showKey);
        @unlink($negPath);
        return $out;
    }
    /** Cached TVMaze show synopsis (summary). */
    /** Show metadata pin from Fix Match (synopsis + poster from the chosen source). */
    public static function showMetaPinPath(string $showKey): string {
        $d = STREAM_DATA . '/synopsis';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d . '/show_' . preg_replace('/[^a-f0-9]/', '', $showKey) . '.meta.json';
    }
    public static function showMetaPin(string $showKey): ?array {
        return MetadataPins::get('show_meta', $showKey);
    }
    /** Cached show synopsis only (pin or TVMaze disk cache). Never hits the network. */
    public static function showSynopsisCached(string $showKey): ?string {
        $pin = self::showMetaPin($showKey);
        if ($pin && !empty($pin['manual'])) return (string)($pin['summary'] ?? '');
        if ($pin && is_string($pin['summary'] ?? null) && $pin['summary'] !== '') return (string)$pin['summary'];
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        $cachePath = STREAM_DATA . '/tvmaze/show_' . $safe . '_episodes.json';
        if (!is_file($cachePath)) return null;
        $j = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($j) && is_string($j['summary'] ?? null) && $j['summary'] !== '') return (string)$j['summary'];
        return null;
    }
    public static function showSynopsis(string $showKey, string $showName, bool $refresh = false): ?string {
        $pin = self::showMetaPin($showKey);
        if ($pin && is_string($pin['summary'] ?? null) && $pin['summary'] !== '') return $pin['summary'];
        $guide = self::tvMazeEpisodeGuide($showKey, $showName, $refresh);
        if (is_array($guide) && !empty($guide['summary'])) return (string)$guide['summary'];
        return null;
    }
    public static function movieSynopsisCachePath(string $itemId): string {
        $d = STREAM_DATA . '/synopsis';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d . '/movie_' . preg_replace('/[^a-f0-9]/', '', $itemId) . '.json';
    }
    /**
     * Movie synopsis: OMDb Plot (when key set) → Wikipedia REST summary.
     * Cached on disk.
     */
    /** Cached movie synopsis only (pin or disk). Never hits OMDb/Wikipedia. */
    public static function movieSynopsisCached(string $itemId): ?array {
        $pin = MetadataPins::get('movie_synopsis', $itemId);
        if ($pin !== null && is_string($pin['summary'] ?? null) && ($pin['summary'] !== '' || !empty($pin['manual']))) {
            return ['summary' => (string)$pin['summary'], 'source' => (string)($pin['provider'] ?? 'pin'), 'pending' => false];
        }
        $cache = self::movieSynopsisCachePath($itemId);
        if (!is_file($cache)) return ['summary' => null, 'source' => null, 'pending' => true];
        $j = json_decode((string)@file_get_contents($cache), true);
        if (!is_array($j) || !array_key_exists('summary', $j)) return ['summary' => null, 'source' => null, 'pending' => true];
        $s = $j['summary'];
        $summary = is_string($s) && $s !== '' ? $s : null;
        // A cached null (previous miss) is final until refresh — do not re-hit Wikipedia on every open.
        return ['summary' => $summary, 'source' => $summary ? (string)($j['source'] ?? null) : null, 'pending' => false];
    }
    public static function movieSynopsis(string $itemId, string $title, ?int $year = null, bool $refresh = false): ?string {
        if ($title === '') return null;
        // A synopsis pinned by Fix Match belongs to the chosen result; never re-guess it by title.
        $pin = MetadataPins::get('movie_synopsis', $itemId);
        if ($pin !== null && is_string($pin['summary'] ?? null) && $pin['summary'] !== '') return $pin['summary'];
        $cache = self::movieSynopsisCachePath($itemId);
        if (is_file($cache)) {
            $j = json_decode((string)file_get_contents($cache), true);
            if (!$refresh && is_array($j) && array_key_exists('summary', $j)) {
                $s = $j['summary'];
                return is_string($s) && $s !== '' ? $s : null;
            }
        }
        $summary = null;
        $source = null;

        // Prefer OMDb when API key is configured
        $apiKey = self::omdbApiKey();
        if ($apiKey !== '') {
            $omdb = self::omdbLookup($title, $year, $apiKey);
            if (is_array($omdb) && isset($omdb['Rated']) && class_exists('ContentRatings')) ContentRatings::capture('item:' . $itemId, $omdb['Rated'], 'omdb');
            if (is_array($omdb) && !empty($omdb['Plot']) && strtolower((string)$omdb['Plot']) !== 'n/a') {
                $summary = trim((string)$omdb['Plot']);
                $source = 'omdb:' . ($omdb['imdbID'] ?? '');
            }
        }

        // Wikipedia fallback
        if ($summary === null || $summary === '') {
            $candidates = [];
            $clean = trim(preg_replace('/\s+/', ' ', $title));
            if ($year) $candidates[] = $clean . ' (' . $year . ' film)';
            $candidates[] = $clean . ' (film)';
            if ($year) $candidates[] = $clean . ' (' . $year . ')';
            $candidates[] = $clean;
            foreach ($candidates as $cand) {
                if (self::remoteBudgetExpired()) break;
                self::throttle();
                $url = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $cand));
                $json = self::httpGet($url, false, 4);
                if ($json === null) continue;
                $data = json_decode($json, true);
                if (!is_array($data)) continue;
                if (($data['type'] ?? '') === 'disambiguation') continue;
                $extract = trim((string)($data['extract'] ?? ''));
                if ($extract === '') continue;
                // Bare-title pages ("Runner" redirects to "Running") are only accepted if they describe a film.
                if (!preg_match('/\bfilm\b/i', $cand) && !preg_match('/\b(film|movie)\b/i', (string)($data['description'] ?? '') . ' ' . $extract)) continue;
                $summary = $extract;
                $source = $data['content_urls']['desktop']['page'] ?? ($data['title'] ?? $cand);
                break;
            }
        }

        // A budget exhaustion is not a definitive provider miss.
        if (($summary === null || $summary === '') && self::remoteBudgetExpired()) return null;
        @file_put_contents($cache, json_encode([
            'title' => $title,
            'year' => $year,
            'summary' => $summary,
            'source' => $source,
            'fetched_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $summary;
    }
    /**
     * Movie / Disney poster: OMDb (preferred when key set) → Wikipedia thumbnail.
     */
    /** Fix Match poster pin: the chosen result's image / exact page. */
    public static function moviePosterPinPath(string $itemId): string {
        $d = STREAM_DATA . '/synopsis';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d . '/movie_' . preg_replace('/[^a-f0-9]/', '', $itemId) . '.poster.json';
    }
    public static function moviePosterPin(string $itemId): ?array {
        return MetadataPins::get('movie_poster', $itemId);
    }
    /** Remote image URL candidates for a pin, in order. Never a title search. */
    public static function pinnedPosterUrls(array $pin, ?callable $pageImage = null): array {
        $urls = [];
        $img = (string)($pin['image'] ?? '');
        if ($img !== '' && preg_match('#^https://#i', $img)) $urls[] = $img;
        $page = (string)($pin['page_title'] ?? '');
        if ($page !== '' && ($pin['provider'] ?? '') === 'wikipedia') {
            $u = $pageImage ? $pageImage($page) : self::wikipediaImage($page, true);
            if ($u) $urls[] = $u;
        }
        return $urls;
    }
    public static function fetchMoviePoster(string $itemId, string $title, ?int $year = null): ?string {
        $dest = self::movieCachePath($itemId);
        if (is_file($dest) && filesize($dest) > 100) return $dest;
        if ($title === '' || !self::enabled()) return null;

        // 0) Fix Match pinned a result: only its own image counts. No image beats a wrong one.
        $pin = self::moviePosterPin($itemId);
        if ($pin !== null) {
            foreach (self::pinnedPosterUrls($pin) as $u) {
                $saved = self::downloadToCache($dest, $u);
                if ($saved) return $saved;
            }
            return null;
        }

        // 1) OMDb
        $apiKey = self::omdbApiKey();
        if ($apiKey !== '') {
            $saved = self::fetchOmdbMoviePoster($dest, $title, $year, $apiKey, $itemId);
            if ($saved) return $saved;
        }

        // 2) Wikipedia fallback
        $clean = trim(preg_replace('/\s+/', ' ', $title));
        $candidates = [];
        if ($year) $candidates[] = $clean . ' (' . $year . ' film)';
        $candidates[] = $clean . ' (film)';
        if ($year) $candidates[] = $clean . ' (' . $year . ')';
        $candidates[] = $clean;
        foreach ($candidates as $cand) {
            if (self::remoteBudgetExpired()) break;
            // Bare-title pages ("Runner" -> "Running") must describe a film.
            $img = self::wikipediaImage($cand, (bool)preg_match('/\bfilm\b/i', $cand));
            if (!$img) continue;
            $saved = self::downloadToCache($dest, $img);
            if ($saved) return $saved;
        }
        return null;
    }
    /**
     * Look up a movie on OMDb and download its Poster into $dest.
     */
    private static function fetchOmdbMoviePoster(string $dest, string $title, ?int $year, string $apiKey, string $itemId = ''): ?string {
        $hit = self::omdbLookup($title, $year, $apiKey);
        if (!is_array($hit)) return null;
        if ($itemId !== '' && class_exists('ContentRatings') && isset($hit['Rated'])) ContentRatings::capture('item:' . $itemId, $hit['Rated'], 'omdb');
        $poster = trim((string)($hit['Poster'] ?? ''));
        if ($poster === '' || strtolower($poster) === 'n/a') return null;
        return self::downloadToCache($dest, $poster);
    }
    /**
     * OMDb title lookup. Returns the decoded response array or null.
     * Uses ?t= (title) with optional year. Falls back to ?s= search if needed.
     * @return array|null
     */
    private static function omdbLookup(string $title, ?int $year, string $apiKey): ?array {
        if ($title === '' || $apiKey === '') return null;
        $clean = trim(preg_replace('/\s+/', ' ', $title));
        // Strip common release-group / quality noise that breaks search
        $clean = preg_replace('/\b(1080p|720p|2160p|4k|bluray|blu-ray|webrip|web-dl|hdtv|x264|x265|hevc|aac|dts|yify|rarbg)\b/i', '', $clean);
        $clean = trim(preg_replace('/\s+/', ' ', (string)$clean));
        if ($clean === '') return null;

        // Primary: exact title lookup
        self::throttle();
        $params = [
            'apikey' => $apiKey,
            't' => $clean,
            'type' => 'movie',
            'plot' => 'short',
        ];
        if ($year && $year > 1900 && $year < 2100) {
            $params['y'] = (string)$year;
        }
        $url = 'https://www.omdbapi.com/?' . http_build_query($params);
        $json = self::httpGet($url, false, 10);
        if ($json !== null) {
            $data = json_decode($json, true);
            if (is_array($data) && strtolower((string)($data['Response'] ?? '')) === 'true') {
                return $data;
            }
        }

        // Fallback: search, then re-fetch by imdbID for full poster/plot
        self::throttle();
        $sParams = [
            'apikey' => $apiKey,
            's' => $clean,
            'type' => 'movie',
        ];
        if ($year && $year > 1900 && $year < 2100) {
            $sParams['y'] = (string)$year;
        }
        $sUrl = 'https://www.omdbapi.com/?' . http_build_query($sParams);
        $sJson = self::httpGet($sUrl, false, 10);
        if ($sJson === null) return null;
        $sData = json_decode($sJson, true);
        if (!is_array($sData) || strtolower((string)($sData['Response'] ?? '')) !== 'true') return null;
        $results = $sData['Search'] ?? [];
        if (!is_array($results) || $results === []) return null;

        // Pick best match by title/year
        $best = null;
        $bestScore = -1;
        $titleLower = strtolower($clean);
        foreach ($results as $r) {
            if (!is_array($r)) continue;
            $rTitle = strtolower(trim((string)($r['Title'] ?? '')));
            $score = 0;
            if ($rTitle === $titleLower) $score += 100;
            elseif ($rTitle !== '' && (strpos($rTitle, $titleLower) !== false || strpos($titleLower, $rTitle) !== false)) $score += 40;
            if ($year && !empty($r['Year'])) {
                $ry = (int)preg_replace('/\D.*/', '', (string)$r['Year']);
                if ($ry === $year) $score += 50;
                elseif (abs($ry - $year) <= 1) $score += 15;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }
        if (!$best || empty($best['imdbID'])) return null;

        self::throttle();
        $iUrl = 'https://www.omdbapi.com/?' . http_build_query([
            'apikey' => $apiKey,
            'i' => $best['imdbID'],
            'plot' => 'short',
        ]);
        $iJson = self::httpGet($iUrl, false, 10);
        if ($iJson === null) return null;
        $iData = json_decode($iJson, true);
        if (!is_array($iData) || strtolower((string)($iData['Response'] ?? '')) !== 'true') return null;
        return $iData;
    }

    /** Persistent short-TTL fail cache so Keep fetching advances past hard misses. */
    private static function failCacheFile(): string {
        return STREAM_DATA . '/poster_fetch_fails.json';
    }
    private static function failCacheLoad(): array {
        $f = self::failCacheFile();
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }
    private static function failCacheSave(array $map): void {
        $f = self::failCacheFile();
        $dir = dirname($f);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($f, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    /** @return bool true when this key failed recently (default 7 days) */
    public static function recentlyFailed(string $kind, string $key, int $ttl = 604800): bool {
        if ($key === '') return false;
        if($kind==='game')$kind='game-v2-'.(GameMetadata::libretroEnabled()?'libretro':'wikipedia');
        $map = self::failCacheLoad();
        $id = $kind . ':' . $key;
        $ts = (int)($map[$id] ?? 0);
        return $ts > 0 && (time() - $ts) < $ttl;
    }
    public static function markFailed(string $kind, string $key): void {
        if ($key === '') return;
        if($kind==='game')$kind='game-v2-'.(GameMetadata::libretroEnabled()?'libretro':'wikipedia');
        $map = self::failCacheLoad();
        $map[$kind . ':' . $key] = time();
        // prune old (>30d)
        $cut = time() - 30 * 86400;
        foreach ($map as $k => $ts) {
            if ((int)$ts < $cut) unset($map[$k]);
        }
        self::failCacheSave($map);
    }
    public static function clearFailed(string $kind, string $key): void {
        if($kind==='game')$kind='game-v2-'.(GameMetadata::libretroEnabled()?'libretro':'wikipedia');
        $map = self::failCacheLoad();
        $id = $kind . ':' . $key;
        if (isset($map[$id])) {
            unset($map[$id]);
            self::failCacheSave($map);
        }
    }

    /**
     * ROM / game poster: indexed public Libretro boxart, then the matched Wikipedia game page.
     */
    public static function fetchGamePoster(array $item): ?string {
        $pin = self::moviePosterPin((string)$item['id']);
        if ($pin) {
            foreach (self::pinnedPosterUrls($pin) as $url) {
                $saved = self::downloadToCache(self::gameCachePath((string)$item['id']), $url);
                if ($saved) return $saved;
            }
            return null;
        }
        $id = (string)($item['id'] ?? '');
        if ($id === '') return null;
        $dest = self::gameCachePath($id);
        if (is_file($dest) && filesize($dest) > 100) return $dest;
        if (!self::enabled()) return null;
        $title = trim((string)($item['title'] ?? ''));
        $core = strtolower((string)($item['emu_core'] ?? ($item['core'] ?? '')));
        $system = strtolower((string)($item['system'] ?? ''));
        // Match real Libretro filenames once per platform instead of guessing many 404 URLs.
        foreach(GameMetadata::boxarts($item) as $match){
            if(self::remoteBudgetExpired())break;
            foreach([$match['image'], 'https://raw.githubusercontent.com/libretro-thumbnails/'.rawurlencode((string)Posters::libretroSystem($core,$system)).'/master/Named_Boxarts/'.rawurlencode(substr($match['id'],strpos($match['id'],':')+1))] as $url){
                $saved=self::downloadToCache($dest,$url);if($saved){self::clearFailed('game',$id);return $saved;}
                if(self::remoteBudgetExpired())break;
            }
        }
        $match=GameMetadata::automaticWikipedia($item);
        if(!empty($match['image'])){$saved=self::downloadToCache($dest,$match['image']);if($saved){self::clearFailed('game',$id);return $saved;}}
        return null;
    }
    public static function gameMetadataRequest(string $url): ?string {
        self::throttle();return self::httpGet($url,false,6);
    }
    public static function gameSynopsisCached(string $id): array {
        $pin=MetadataPins::get('movie_synopsis',$id);
        if($pin!==null&&is_string($pin['summary'] ?? null))return ['summary'=>$pin['summary'],'source'=>$pin['provider'] ?? $pin['source'] ?? 'pin','pending'=>false];
        $path=self::movieSynopsisCachePath($id);$cached=is_file($path)?json_decode((string)@file_get_contents($path),true):null;
        if(!is_array($cached)||($cached['game_lookup_version'] ?? 0)!==GameMetadata::VERSION)return ['summary'=>null,'source'=>null,'pending'=>true];
        $summary=(string)($cached['summary'] ?? '');return ['summary'=>$summary!==''?$summary:null,'source'=>$cached['source'] ?? null,'pending'=>$summary===''&&(int)($cached['retry_at'] ?? 0)<=time()];
    }
    public static function gameSynopsis(array $item): ?string {
        $id=(string)($item['id'] ?? '');if($id==='')return null;$cached=self::gameSynopsisCached($id);if(!$cached['pending'])return $cached['summary'];
        $match=GameMetadata::automaticWikipedia($item);$summary=$match['summary'] ?? null;
        if(!$summary&&self::remoteBudgetExpired())return null;
        $identity=GameMetadata::identity($item);$data=['title'=>$identity['title'],'system'=>$identity['system'],'summary'=>$summary,'source'=>$match['url'] ?? null,'fetched_at'=>date('c'),'game_lookup_version'=>GameMetadata::VERSION,'retry_at'=>time()+3600];
        @file_put_contents(self::movieSynopsisCachePath($id),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);return $summary;
    }
    /** True when bytes look like JPEG/PNG/WebP/GIF (avoid caching HTML 404 bodies). */
    private static function looksLikeImage(string $bin): bool {
        if (strlen($bin) < 24) return false;
        $h4 = substr($bin, 0, 4);
        if ($h4 === "\x89PNG") return true;
        if (substr($bin, 0, 3) === "\xff\xd8\xff") return true;
        if (substr($bin, 0, 6) === 'GIF87a' || substr($bin, 0, 6) === 'GIF89a') return true;
        if ($h4 === 'RIFF' && substr($bin, 8, 4) === 'WEBP') return true;
        return false;
    }
    public static function libretroSystem(string $core, string $systemLabel): ?string {
        $labels=[
            '/mega[ _-]*drive|genesis|segamd/i'=>'Sega_-_Mega_Drive_-_Genesis',
            '/super[ _-]*nintendo|\bsnes\b|super[ _-]*famicom/i'=>'Nintendo_-_Super_Nintendo_Entertainment_System',
            '/game[ _-]*boy[ _-]*advance|\bgba\b/i'=>'Nintendo_-_Game_Boy_Advance',
            '/game[ _-]*boy[ _-]*colou?r|\bgbc\b/i'=>'Nintendo_-_Game_Boy_Color',
            '/game[ _-]*boy|^gb$/i'=>'Nintendo_-_Game_Boy',
            '/nintendo[ _-]*64|^n64$/i'=>'Nintendo_-_Nintendo_64',
            '/nintendo[ _-]*ds|^nds$/i'=>'Nintendo_-_Nintendo_DS',
            '/master[ _-]*system|^sms$/i'=>'Sega_-_Master_System_-_Mark_III',
            '/game[ _-]*gear|^gg$/i'=>'Sega_-_Game_Gear',
            '/playstation|\bps[ _-]*[1x]\b/i'=>'Sony_-_PlayStation',
            '/nintendo[ _-]*entertainment|\bnes\b|^famicom$/i'=>'Nintendo_-_Nintendo_Entertainment_System',
        ];
        foreach($labels as $pattern=>$platform)if(preg_match($pattern,$systemLabel))return $platform;
        $map = [
            'nes' => 'Nintendo_-_Nintendo_Entertainment_System',
            'fds' => 'Nintendo_-_Nintendo_Entertainment_System',
            'snes' => 'Nintendo_-_Super_Nintendo_Entertainment_System',
            'n64' => 'Nintendo_-_Nintendo_64',
            'gb' => 'Nintendo_-_Game_Boy',
            'gbc' => 'Nintendo_-_Game_Boy_Color',
            'gba' => 'Nintendo_-_Game_Boy_Advance',
            'nds' => 'Nintendo_-_Nintendo_DS',
            'psx' => 'Sony_-_PlayStation',
            'segamd' => 'Sega_-_Mega_Drive_-_Genesis',
            'genesis' => 'Sega_-_Mega_Drive_-_Genesis',
            'megadrive' => 'Sega_-_Mega_Drive_-_Genesis',
            'sms' => 'Sega_-_Master_System_-_Mark_III',
            'gg' => 'Sega_-_Game_Gear',
            'pce' => 'NEC_-_PC_Engine_-_TurboGrafx_16',
            'ngp' => 'SNK_-_Neo_Geo_Pocket',
            'wswan' => 'Bandai_-_WonderSwan',
            'atari2600' => 'Atari_-_2600',
        ];
        if (isset($map[$core])) {
            // Catalogue often tags Mega Drive dumps as emu_core=nes; prefer folder label.
            if ($core === 'nes' && (strpos($systemLabel, 'mega drive') !== false || strpos($systemLabel, 'genesis') !== false)) {
                return 'Sega_-_Mega_Drive_-_Genesis';
            }
            return $map[$core];
        }
        $s = $systemLabel;
        if (strpos($s, 'mega drive') !== false || strpos($s, 'genesis') !== false) {
            return 'Sega_-_Mega_Drive_-_Genesis';
        }
        if (strpos($s, 'snes') !== false || strpos($s, 'super nintendo') !== false) {
            return 'Nintendo_-_Super_Nintendo_Entertainment_System';
        }
        if (strpos($s, 'nintendo 64') !== false || $s === 'n64') {
            return 'Nintendo_-_Nintendo_64';
        }
        if (strpos($s, 'game boy advance') !== false || $s === 'gba') {
            return 'Nintendo_-_Game_Boy_Advance';
        }
        if (strpos($s, 'game boy color') !== false || $s === 'gbc') {
            return 'Nintendo_-_Game_Boy_Color';
        }
        if (strpos($s, 'game boy') !== false) {
            return 'Nintendo_-_Game_Boy';
        }
        if (strpos($s, 'master system') !== false) {
            return 'Sega_-_Master_System_-_Mark_III';
        }
        if (strpos($s, 'game gear') !== false) {
            return 'Sega_-_Game_Gear';
        }
        if (strpos($s, 'ps1') !== false || strpos($s, 'playstation') !== false) {
            return 'Sony_-_PlayStation';
        }
        if (strpos($s, 'nes') !== false) {
            return 'Nintendo_-_Nintendo_Entertainment_System';
        }
        return null;
    }
    /** True when a Wikipedia summary payload is about a film. */
    public static function wikiIsFilm(array $data): bool {
        return (bool)preg_match('/\b(film|movie)\b/i', (string)($data['title'] ?? '') . ' ' . (string)($data['description'] ?? '') . ' ' . (string)($data['extract'] ?? ''));
    }
    private static function wikipediaImage(string $pageTitle, bool $trusted = false): ?string {
        if ($pageTitle === '') return null;
        self::throttle();
        $url = 'https://en.wikipedia.org/api/rest_v1/page/summary/'
            . rawurlencode(str_replace(' ', '_', $pageTitle));
        $json = self::httpGet($url, false, 8);
        if ($json === null) return null;
        $data = json_decode($json, true);
        if (!is_array($data)) return null;
        if (($data['type'] ?? '') === 'disambiguation') return null;
        if (!$trusted && !self::wikiIsFilm($data)) return null;
        $img = $data['originalimage']['source'] ?? ($data['thumbnail']['source'] ?? null);
        return is_string($img) && $img !== '' ? $img : null;
    }
    private static function downloadToCache(string $dest, string $imgUrl): ?string {
        self::throttle();
        $bin = self::httpGet($imgUrl, true, 10);
        if ($bin === null || strlen($bin) < 100 || !self::looksLikeImage($bin)) return null;
        return self::writeCacheFile($dest, $bin);
    }
    private static function writeCacheFile(string $dest, string $bin): ?string {
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $dest . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $bin, LOCK_EX) === false) {
            @unlink($tmp);
            return null;
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            return null;
        }
        return (is_file($dest) && filesize($dest) > 100) ? $dest : null;
    }
    private static function httpGet(string $url, bool $binary = false, int $timeout = 12, array $headers = []): ?string {
        if (self::remoteBudgetExpired()) return null;
        $timeout = max(1, min(20, $timeout));
        if (self::$remoteDeadline !== null) {
            $left = self::$remoteDeadline - microtime(true);
            if ($left <= 0.05) return null;
            $timeout = max(1, min($timeout, (int)ceil($left)));
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . ' (+posters)',
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json,image/*,*/*'], $headers),
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $body === false || $status >= 400 ? null : $body;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: Vaultcast/" . STREAM_VERSION . "\r\nAccept: application/json,image/*,*/*\r\n" . ($headers ? implode("\r\n", $headers) . "\r\n" : ''),
                'follow_location' => 1,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
