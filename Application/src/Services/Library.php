<?php
declare(strict_types=1);

/**
 * Admin library manager: find misplaced/misnamed media on disk,
 * suggest show/movie matches, preview and apply move+rename into
 * standard library layout, then incrementally update the RAM catalogue.
 */
class Library {
    private static $videoExtDefault = ['mp4','m4v','mkv','avi','mov','webm','wmv','ts','m2ts'];

    public static function defaultScanRoots(): array {
        $settings = Store::settings();
        $roots = [];
        $dl = $settings['acquisition']['download_path'] ?? '';
        if ($dl) $roots[] = $dl;
        foreach ($settings['sections'] ?? [] as $section) {
            if (!empty($section['enabled']) && !empty($section['path'])) $roots[] = $section['path'];
        }
        $extra = $settings['library']['scan_roots'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $r) {
                if (is_string($r) && $r !== '') $roots[] = $r;
            }
        }
        // Dedupe
        $out = [];
        $seen = [];
        foreach ($roots as $r) {
            $real = \Vaultcast\Paths::real($r);
            $key = $real !== false ? $real : rtrim($r, '/');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $r;
        }
        return $out;
    }

    public static function knownPaths(): array {
        $cat = Catalogue::get();
        $set = [];
        foreach ($cat['items'] ?? [] as $it) {
            $p = $it['path'] ?? '';
            if ($p === '') continue;
            $real = \Vaultcast\Paths::real($p);
            $set[$real !== false ? $real : $p] = $it['id'];
        }
        return $set;
    }

    public static function videoExtensions(): array {
        $scan = Store::settings()['scan'] ?? [];
        $ext = $scan['video_extensions'] ?? self::$videoExtDefault;
        return array_map('strtolower', is_array($ext) ? $ext : self::$videoExtDefault);
    }

    /**
     * Scan orphan / unmatched video files under download folders and optional roots.
     * Also optionally flags misnamed files already under section roots.
     */
    public static function scanOrphans(array $opts = []): array {
        $limit = max(1, min(500, (int)($opts['limit'] ?? 100)));
        $includeSectionRoots = !empty($opts['include_section_roots']);
        $roots = $opts['roots'] ?? self::defaultScanRoots();
        if (!is_array($roots)) $roots = self::defaultScanRoots();

        $settings = Store::settings();
        $sectionRoots = [];
        foreach ($settings['sections'] ?? [] as $sec) {
            if (empty($sec['enabled'])) continue;
            $p = $sec['path'] ?? '';
            if ($p !== '' && is_dir($p)) {
                $real = \Vaultcast\Paths::real($p);
                $sectionRoots[] = $real !== false ? $real : $p;
            }
        }

        if ($includeSectionRoots) {
            foreach ($sectionRoots as $sr) $roots[] = $sr;
        }

        $known = self::knownPaths();
        $exts = self::videoExtensions();
        $maxDepth = (int)(($settings['library']['orphan_max_depth'] ?? 6));
        $found = [];
        $scannedRoots = [];

        foreach ($roots as $root) {
            if (!is_string($root) || $root === '' || !is_dir($root)) continue;
            $rootReal = \Vaultcast\Paths::real($root);
            if ($rootReal === false) continue;
            $scannedRoots[] = $rootReal;

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                $iterator->setMaxDepth($maxDepth);
            } catch (Throwable $e) {
                continue;
            }

            foreach ($iterator as $file) {
                if (count($found) >= $limit) break 2;
                /** @var SplFileInfo $file */
                if (!$file->isFile()) continue;
                $full = $file->getPathname();
                // Skip recycle / incomplete / hidden junk
                if (preg_match('#/(?:#recycle|\.incomplete|incomplete|\.Trash|@eaDir)/#i', $full)) continue;
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $exts, true)) continue;

                $real = \Vaultcast\Paths::real($full);
                $key = $real !== false ? $real : $full;
                if (isset($known[$key])) continue;

                // Under a library section root but not in catalogue → still orphan (misplaced naming / race)
                // Under download roots → always candidate
                $underLibrary = false;
                foreach ($sectionRoots as $sr) {
                    if (strpos($key, $sr . '/') === 0 || $key === $sr) {
                        $underLibrary = true;
                        break;
                    }
                }

                $parsed = self::parseFilename($full);
                $suggestion = self::buildSuggestion($parsed, $underLibrary);

                $found[] = [
                    'path' => $full,
                    'rel_root' => $rootReal,
                    'name' => $file->getFilename(),
                    'ext' => $ext,
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime(),
                    'under_library' => $underLibrary,
                    'parsed' => $parsed,
                    'suggestion' => $suggestion,
                ];
            }
        }

        return [
            'ok' => true,
            'count' => count($found),
            'limit' => $limit,
            'roots' => $scannedRoots,
            'orphans' => $found,
        ];
    }

    public static function parseFilename(string $path): array {
        $base = basename($path);
        $baseNoExt = preg_replace('/\.[^.]+$/', '', $base);
        $dir = basename(dirname($path));
        $season = null;
        $episode = null;
        $year = null;
        $show = null;
        $title = null;
        $kind = 'movie';

        $hay = $baseNoExt . ' ' . $dir;
        if (preg_match('/[Ss](\d{1,2})[Ee](\d{1,3})/', $hay, $m)) {
            $season = (int)$m[1];
            $episode = (int)$m[2];
            $kind = 'tv';
        } elseif (preg_match('/(?:^|[^0-9])(\d{1,2})[xX](\d{1,3})(?:[^0-9]|$)/', $hay, $m)) {
            $season = (int)$m[1];
            $episode = (int)$m[2];
            $kind = 'tv';
        }

        if (preg_match('/\((\d{4})\)/', $hay, $m)) {
            $year = (int)$m[1];
        } elseif (preg_match('/(?:^|[.\s_-])((?:19|20)\d{2})(?:[.\s_-]|$)/', $hay, $m)) {
            $year = (int)$m[1];
        }

        if ($kind === 'tv') {
            $raw = $baseNoExt;
            $raw = preg_replace('/[Ss]\d{1,2}[Ee]\d{1,3}.*$/', '', $raw);
            $raw = preg_replace('/\d{1,2}[xX]\d{1,3}.*$/', '', $raw);
            $raw = preg_replace('/[._]+/', ' ', $raw);
            $raw = preg_replace('/\s+/', ' ', trim($raw));
            // If leftover is tiny, try parent folder (unless Season NN)
            if ($raw === '' || strlen($raw) < 2) {
                if (!preg_match('/^Season\s*\d+/i', $dir) && !preg_match('/^S\d+$/i', $dir)) {
                    $raw = preg_replace('/[._]+/', ' ', $dir);
                }
            }
            // Clean release tags
            $raw = self::stripReleaseTags($raw);
            $show = $raw !== '' ? $raw : 'Unknown Show';
            $title = $show;
        } else {
            // Prefer folder "Title (Year)" when present (allow trailing junk after year)
            if (preg_match('/^(.+?)\s*\((\d{4})\)\b/', $dir, $m)) {
                $title = self::stripReleaseTags(trim($m[1]));
                $year = (int)$m[2];
            } else {
                $raw = $baseNoExt;
                if (preg_match('/\((\d{4})\)/', $raw, $ym)) {
                    $year = $year ?: (int)$ym[1];
                    $raw = preg_replace('/\((\d{4})\)/', ' ', $raw);
                }
                if (preg_match('/(?:^|[.\s_-])((?:19|20)\d{2})(?:[.\s_-]|$)/', $raw, $ym)) {
                    $year = $year ?: (int)$ym[1];
                    $raw = preg_replace('/(?:^|[.\s_-])(?:19|20)\d{2}(?:[.\s_-]|$)/', ' ', $raw);
                }
                $raw = preg_replace('/[._]+/', ' ', $raw);
                $raw = self::stripReleaseTags($raw);
                $title = trim(preg_replace('/\s+/', ' ', $raw));
                if (($title === '' || strlen($title) < 2) && $dir && $dir !== '.' ) {
                    $d = $dir;
                    if (preg_match('/^(.+?)\s*\((\d{4})\)/', $d, $dm)) {
                        $title = trim($dm[1]);
                        $year = $year ?: (int)$dm[2];
                    } else {
                        $title = self::stripReleaseTags(preg_replace('/[._]+/', ' ', $d));
                    }
                }
            }
        }

        return [
            'kind' => $kind,
            'title' => $title,
            'show' => $show,
            'year' => $year,
            'season' => $season,
            'episode' => $episode,
        ];
    }

    private static function stripReleaseTags(string $s): string {
        $s = preg_replace('/\[.*?\]/', ' ', $s);
        $s = preg_replace('/\{.*?\}/', ' ', $s);
        $s = preg_replace('/\(.*?\)/', ' ', $s); // drop leftover paren groups after year extraction
        // codec / source / audio tokens (allow glued forms like AAC5.1, DD5.1)
        $tags = '1080p|720p|2160p|480p|4k|uhd|hdr10|hdr|dolbyd|dolby|vision|\bdv\b|bluray|blu-ray|bdrip|brrip|webrip|web-dl|webdl|hdtv|hdrip|x264|x265|h264|h265|hevc|avc|xvid|divx|aac(?:\d*(?:[\. ]?\d)?)?|dts(?:-?hd)?|truehd|atmos|eac3|dd(?:p)?(?:5[\. ]1)?|ac3|10bit|8bit|yify|yts(?:\.\w+)?|rarbg|imax|remux|proper|repack|extended|directors?\.?cut|uncut|multi|dual|subs?|internal|amzn|nf|hulu|dsnp|hmax|web|bluray|readnfo|nfo|sample';
        $s = preg_replace('/\b(?:' . $tags . ')\b/i', ' ', $s);
        // trailing release group (-GROUP or –GROUP)
        $s = preg_replace('/\s*[-–]\s*[A-Za-z0-9][A-Za-z0-9._]{1,30}\s*$/', '', $s);
        $s = preg_replace('/\s*\+\s*[A-Za-z0-9][A-Za-z0-9._ -]{1,40}$/', '', $s);
        // stray year left in title after extraction
        $s = preg_replace('/(?:^|\s)((?:19|20)\d{2})(?:\s|$)/', ' ', $s);
        // leftover numeric audio fragments like "5 1"
        $s = preg_replace('/\b\d(?:[\. ]\d)?\b/', ' ', $s);
        return trim(preg_replace('/[\s._-]+/', ' ', $s));
    }

    private static function buildSuggestion(array $parsed, bool $underLibrary): array {
        $settings = Store::settings();
        $sections = $settings['sections'] ?? [];
        $kind = $parsed['kind'] ?? 'movie';
        $sectionId = null;
        $sectionLabel = null;
        $type = $kind === 'tv' ? 'tv' : 'movies';

        foreach ($sections as $sec) {
            if (empty($sec['enabled'])) continue;
            $t = $sec['type'] ?? '';
            if ($kind === 'tv' && ($t === 'tv' || $t === 'anime')) {
                // Prefer tv over anime unless show name hints anime path — default tv
                if ($sectionId === null || $t === 'tv') {
                    $sectionId = $sec['id'];
                    $sectionLabel = $sec['label'] ?? $sec['id'];
                    $type = $t;
                    if ($t === 'tv') break;
                }
            }
            if ($kind === 'movie' && $t === 'movies' && ($sec['id'] ?? '') === 'movies') {
                $sectionId = $sec['id'];
                $sectionLabel = $sec['label'] ?? $sec['id'];
                $type = 'movies';
            }
        }
        if ($sectionId === null) {
            foreach ($sections as $sec) {
                if (empty($sec['enabled'])) continue;
                $t = $sec['type'] ?? '';
                if ($kind === 'tv' && ($t === 'tv' || $t === 'anime')) {
                    $sectionId = $sec['id'];
                    $sectionLabel = $sec['label'] ?? $sec['id'];
                    $type = $t;
                    break;
                }
                if ($kind === 'movie' && $t === 'movies') {
                    $sectionId = $sec['id'];
                    $sectionLabel = $sec['label'] ?? $sec['id'];
                    $type = 'movies';
                    break;
                }
            }
        }

        return [
            'kind' => $kind,
            'type' => $type,
            'section_id' => $sectionId,
            'section_label' => $sectionLabel,
            'title' => $parsed['title'] ?? null,
            'show' => $parsed['show'] ?? null,
            'year' => $parsed['year'] ?? null,
            'season' => $parsed['season'] ?? null,
            'episode' => $parsed['episode'] ?? null,
            'confidence' => self::confidence($parsed),
            'under_library' => $underLibrary,
        ];
    }

    private static function confidence(array $parsed): string {
        if (($parsed['kind'] ?? '') === 'tv') {
            if (!empty($parsed['show']) && $parsed['season'] !== null && $parsed['episode'] !== null) return 'high';
            if ($parsed['season'] !== null && $parsed['episode'] !== null) return 'medium';
            return 'low';
        }
        if (!empty($parsed['title']) && !empty($parsed['year'])) return 'high';
        if (!empty($parsed['title'])) return 'medium';
        return 'low';
    }

    /** Enrich suggestion with TVMaze single-search (TV only). */
    public static function suggest(array $body): array {
        $path = $body['path'] ?? '';
        if ($path === '' || !is_file($path)) {
            return ['ok' => false, 'error' => 'File not found'];
        }
        $parsed = self::parseFilename($path);
        if (!empty($body['kind']) && in_array($body['kind'], ['tv','movie'], true)) {
            $parsed['kind'] = $body['kind'];
        }
        if (!empty($body['show'])) $parsed['show'] = trim((string)$body['show']);
        if (!empty($body['title'])) $parsed['title'] = trim((string)$body['title']);
        if (isset($body['year']) && $body['year'] !== '') $parsed['year'] = (int)$body['year'];
        if (isset($body['season']) && $body['season'] !== '') $parsed['season'] = (int)$body['season'];
        if (isset($body['episode']) && $body['episode'] !== '') $parsed['episode'] = (int)$body['episode'];

        $suggestion = self::buildSuggestion($parsed, false);
        $tvmaze = null;
        if (($suggestion['kind'] ?? '') === 'tv' && !empty($suggestion['show'])) {
            $tvmaze = self::tvMazeLookup($suggestion['show']);
            if ($tvmaze) {
                $suggestion['show'] = $tvmaze['name'] ?? $suggestion['show'];
                $suggestion['tvmaze_id'] = $tvmaze['id'] ?? null;
                $suggestion['year'] = $suggestion['year'] ?? self::yearFromPremiered($tvmaze['premiered'] ?? null);
                if (!empty($tvmaze['name'])) $suggestion['confidence'] = 'high';
            }
        }

        // Allow override section
        if (!empty($body['section_id'])) {
            $suggestion['section_id'] = $body['section_id'];
            foreach (Store::settings()['sections'] ?? [] as $sec) {
                if (($sec['id'] ?? '') === $body['section_id']) {
                    $suggestion['section_label'] = $sec['label'] ?? $sec['id'];
                    $suggestion['type'] = $sec['type'] ?? $suggestion['type'];
                    break;
                }
            }
        }

        $preview = self::preview([
            'path' => $path,
            'section_id' => $suggestion['section_id'],
            'title' => $suggestion['kind'] === 'tv' ? ($suggestion['show'] ?? $suggestion['title']) : $suggestion['title'],
            'show' => $suggestion['show'],
            'year' => $suggestion['year'],
            'season' => $suggestion['season'],
            'episode' => $suggestion['episode'],
            'kind' => $suggestion['kind'],
        ]);

        return [
            'ok' => true,
            'path' => $path,
            'parsed' => $parsed,
            'suggestion' => $suggestion,
            'tvmaze' => $tvmaze,
            'preview' => $preview['ok'] ?? false ? $preview : null,
        ];
    }

    private static function yearFromPremiered(?string $d): ?int {
        if (!$d || !preg_match('/^(\d{4})/', $d, $m)) return null;
        return (int)$m[1];
    }

    public static function tvMazeLookup(string $query): ?array {
        $q = trim($query);
        if ($q === '') return null;
        $cacheDir = STREAM_DATA . '/tvmaze';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheKey = 'search_' . substr(sha1(strtolower($q)), 0, 16) . '.json';
        $cachePath = $cacheDir . '/' . $cacheKey;
        if (is_file($cachePath) && (time() - filemtime($cachePath)) < 86400 * 7) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($cached)) return $cached ?: null;
        }
        $url = 'https://api.tvmaze.com/singlesearch/shows?q=' . rawurlencode($q);
        $json = self::httpGet($url);
        if ($json === null) {
            @file_put_contents($cachePath, 'null');
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            @file_put_contents($cachePath, 'null');
            return null;
        }
        $slim = [
            'id' => $data['id'] ?? null,
            'name' => $data['name'] ?? null,
            'premiered' => $data['premiered'] ?? null,
            'status' => $data['status'] ?? null,
            'genres' => $data['genres'] ?? [],
            'url' => $data['url'] ?? null,
        ];
        @file_put_contents($cachePath, json_encode($slim));
        return $slim;
    }

    private static function httpGet(string $url): ?string {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: Vaultcast/" . STREAM_VERSION . "\r\n"]]);
            $raw = @file_get_contents($url, false, $ctx);
            return $raw === false ? null : $raw;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . ' (+library manager)',
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) return null;
        return $raw;
    }

    public static function standardDest(array $body): array {
        $sectionId = $body['section_id'] ?? '';
        $settings = Store::settings();
        $section = null;
        foreach ($settings['sections'] ?? [] as $sec) {
            if (($sec['id'] ?? '') === $sectionId) { $section = $sec; break; }
        }
        if (!$section) {
            return ['ok' => false, 'error' => 'Unknown section_id'];
        }
        $root = $section['path'] ?? '';
        if ($root === '' || !is_dir($root)) {
            return ['ok' => false, 'error' => 'Section path missing on disk'];
        }
        $type = $section['type'] ?? 'movies';
        $ext = strtolower(pathinfo($body['path'] ?? 'x.mp4', PATHINFO_EXTENSION) ?: 'mp4');

        if ($type === 'tv' || $type === 'anime') {
            $show = self::safeName(trim((string)($body['show'] ?? $body['title'] ?? 'Unknown Show')));
            $season = (int)($body['season'] ?? 1);
            $episode = (int)($body['episode'] ?? 1);
            if ($season < 0) $season = 0;
            if ($episode < 1) $episode = 1;
            $seasonDir = sprintf('Season %02d', $season);
            $file = sprintf('%s S%02dE%02d.%s', $show, $season, $episode, $ext);
            // optional episode title in the file name ("Show S01E02 - Title.ext") so rescans keep the name
            $epTitle = trim((string)($body['episode_title'] ?? ''));
            if ($epTitle !== '') {
                $epTitle = self::safeName($epTitle);
                if (strlen($epTitle) > 80) $epTitle = rtrim(substr($epTitle, 0, 80));
                $file = sprintf('%s S%02dE%02d - %s.%s', $show, $season, $episode, $epTitle, $ext);
            }
            $rel = $show . '/' . $seasonDir . '/' . $file;
            $dest = rtrim($root, '/') . '/' . $rel;
            return [
                'ok' => true,
                'layout' => 'tv',
                'section_id' => $sectionId,
                'type' => $type,
                'root' => $root,
                'rel' => $rel,
                'dest' => $dest,
                'show' => $show,
                'season' => $season,
                'episode' => $episode,
            ];
        }

        // movies / disney / default
        $title = self::safeName(trim((string)($body['title'] ?? 'Unknown')));
        $year = isset($body['year']) && $body['year'] !== '' && $body['year'] !== null
            ? (int)$body['year'] : null;
        $folder = $year ? sprintf('%s (%d)', $title, $year) : $title;
        $file = $year ? sprintf('%s (%d).%s', $title, $year, $ext) : sprintf('%s.%s', $title, $ext);
        $rel = $folder . '/' . $file;
        $dest = rtrim($root, '/') . '/' . $rel;
        return [
            'ok' => true,
            'layout' => 'movies',
            'section_id' => $sectionId,
            'type' => $type,
            'root' => $root,
            'rel' => $rel,
            'dest' => $dest,
            'title' => $title,
            'year' => $year,
        ];
    }

    private static function safeName(string $name): string {
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return $name !== '' ? $name : 'Unknown';
    }

    public static function preview(array $body): array {
        $path = $body['path'] ?? '';
        if ($path === '' || !is_file($path)) {
            return ['ok' => false, 'error' => 'Source file not found'];
        }
        $std = self::standardDest($body);
        if (empty($std['ok'])) return $std;

        $srcReal = \Vaultcast\Paths::real($path);
        $dest = $std['dest'];
        $same = ($srcReal !== false && \Vaultcast\Paths::real(dirname($dest)) && $srcReal === \Vaultcast\Paths::real($dest));
        // Also compare normalized strings when dest does not exist yet
        if (!$same && $srcReal !== false) {
            $same = ($srcReal === $dest);
        }

        $exists = is_file($dest) && !$same;
        $conflict = $exists;

        return [
            'ok' => true,
            'source' => $path,
            'dest' => $dest,
            'rel' => $std['rel'],
            'section_id' => $std['section_id'],
            'type' => $std['type'],
            'layout' => $std['layout'],
            'same_path' => $same,
            'dest_exists' => $exists,
            'conflict' => $conflict,
            'meta' => $std,
        ];
    }

    /**
     * Move/rename into standard layout and upsert catalogue entry.
     */
    public static function apply(array $body): array {
        $preview = self::preview($body);
        if (empty($preview['ok'])) return $preview;
        if (!empty($preview['conflict']) && empty($body['overwrite'])) {
            return ['ok' => false, 'error' => 'Destination already exists', 'preview' => $preview];
        }
        if (!empty($preview['same_path'])) {
            // Still refresh catalogue entry
            $item = Catalogue::ingestFile($preview['source'], $preview['section_id']);
            return [
                'ok' => true,
                'moved' => false,
                'reason' => 'Already at destination',
                'source' => $preview['source'],
                'dest' => $preview['dest'],
                'item' => $item ? Catalogue::publicItem($item) : null,
            ];
        }

        $src = $preview['source'];
        $dest = $preview['dest'];
        $destDir = dirname($dest);
        if (!is_dir($destDir)) {
            if (!@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                return ['ok' => false, 'error' => 'Failed to create destination folder: ' . $destDir];
            }
        }

        $mv = self::moveFile($src, $dest);
        if (empty($mv['ok'])) return ['ok' => false, 'error' => $mv['error'] ?? 'Failed to move file'];

        // Best-effort: remove empty leftover dirs under downloads (not section roots)
        self::cleanupEmptyParents($src);

        // Batch callers (imports) add all files to the catalogue in one write afterwards
        $item = !empty($body['defer_catalogue']) ? null : Catalogue::ingestFile($dest, $preview['section_id']);
        return [
            'ok' => true,
            'moved' => true,
            'deferred' => !empty($body['defer_catalogue']),
            'method' => $mv['method'] ?? 'rename',
            'source_kept' => !empty($mv['source_kept']),
            'source' => $src,
            'dest' => $dest,
            'rel' => $preview['rel'],
            'section_id' => $preview['section_id'],
            'item' => $item ? Catalogue::publicItem($item) : null,
            'item_id' => $item['id'] ?? null,
        ];
    }

    /**
     * Move a file: rename() on the same filesystem; only when that fails (cross-device) copy to a
     * temporary name, verify the size, put it in place and delete the source.
     */
    public static function moveFile(string $src, string $dest): array {
        if (@rename($src, $dest)) return ['ok' => true, 'method' => 'rename'];
        clearstatcache();
        // Windows can refuse a rename while another process (Transmission) still releases the handle
        if (is_file($src) && !file_exists($dest)) {
            usleep(500000);
            if (@rename($src, $dest)) return ['ok' => true, 'method' => 'rename'];
        }
        $size = @filesize($src);
        if ($size === false) return ['ok' => false, 'error' => 'Source file missing'];
        $tmp = $dest . '.vcpart';
        @unlink($tmp);
        if (!@copy($src, $tmp)) { @unlink($tmp); return ['ok' => false, 'error' => 'Failed to copy file to the library (disk full or permissions?)']; }
        clearstatcache();
        if (@filesize($tmp) !== $size) { @unlink($tmp); return ['ok' => false, 'error' => 'Copied file size does not match the source']; }
        if (!@rename($tmp, $dest)) { @unlink($tmp); return ['ok' => false, 'error' => 'Failed to place copied file']; }
        if (!@unlink($src)) {
            // Keep one copy only: the library file is verified, so the source must go; report if it cannot.
            return ['ok' => true, 'method' => 'copy', 'source_kept' => true];
        }
        return ['ok' => true, 'method' => 'copy'];
    }

    private static function cleanupEmptyParents(string $oldPath): void {
        $dir = dirname($oldPath);
        $protected = [];
        foreach (self::defaultScanRoots() as $r) {
            $real = \Vaultcast\Paths::real($r);
            if ($real) $protected[$real] = true;
        }
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            $real = \Vaultcast\Paths::real($sec['path'] ?? '');
            if ($real) $protected[$real] = true;
        }
        for ($i = 0; $i < 4; $i++) {
            $real = \Vaultcast\Paths::real($dir);
            if ($real === false || isset($protected[$real])) break;
            if (!is_dir($dir)) break;
            $files = @scandir($dir);
            if ($files === false) break;
            $files = array_diff($files, ['.', '..']);
            if (count($files) > 0) break;
            if (!@rmdir($dir)) break;
            $dir = dirname($dir);
        }
    }

    public static function applyBatch(array $body): array {
        $items = $body['items'] ?? [];
        if (!is_array($items) || !$items) {
            return ['ok' => false, 'error' => 'items array required'];
        }
        $results = [];
        $ok = 0;
        $fail = 0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $r = self::apply($it);
            $results[] = $r;
            if (!empty($r['ok'])) $ok++; else $fail++;
        }
        return ['ok' => true, 'applied' => $ok, 'failed' => $fail, 'results' => $results];
    }
}
