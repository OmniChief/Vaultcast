<?php
declare(strict_types=1);

/**
 * Admin Media Manager: browse/edit catalogue, reassign shows,
 * online metadata search (TVMaze / Wikipedia), file browser,
 * convert / move / ingest — all on demand (no background work).
 */
class MediaManager {
    /** Allowed library roots for file browser (section paths + known volumes). */
    public static function allowedRoots(): array {
        $roots = [];
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            $p = $sec['path'] ?? '';
            if ($p !== '' && is_dir($p)) {
                $real = \Vaultcast\Paths::real($p);
                $roots[] = [
                    'id' => $sec['id'] ?? '',
                    'label' => $sec['label'] ?? ($sec['id'] ?? ''),
                    'type' => $sec['type'] ?? '',
                    'path' => $real !== false ? $real : rtrim($p, '/'),
                ];
            }
        }
        // Explicit extras requested by admin (must already exist)
        foreach (Store::settings()['library']['scan_roots'] ?? [] as $extra) {
            if (!is_dir($extra)) continue;
            $real = \Vaultcast\Paths::real($extra);
            if ($real === false) continue;
            $exists = false;
            foreach ($roots as $r) {
                if (($r['path'] ?? '') === $real) { $exists = true; break; }
            }
            if (!$exists) {
                $roots[] = [
                    'id' => 'path_' . substr(sha1($real), 0, 8),
                    'label' => basename($real),
                    'type' => 'path',
                    'path' => $real,
                ];
            }
        }
        return $roots;
    }

    private static function underAllowed(string $path): bool {
        $real = \Vaultcast\Paths::real($path);
        if ($real === false) {
            // Allow browsing into not-yet-resolved dirs under allowed parents
            $real = $path;
        }
        foreach (self::allowedRoots() as $r) {
            $root = $r['path'];
            if ($real === $root || strpos($real, $root . '/') === 0) return true;
        }
        return false;
    }

    /** List catalogue items/shows for admin media manager. */
    public static function browseCatalogue(array $opts = []): array {
        $section = trim((string)($opts['section'] ?? ''));
        $q = trim((string)($opts['q'] ?? ''));
        $mode = trim((string)($opts['mode'] ?? 'auto')); // auto|items|shows|items_noposter|shows_noposter
        $page = max(1, (int)($opts['page'] ?? 1));
        $per = min(100, max(1, (int)($opts['per'] ?? 40)));

        // Missing-poster filter via:
        //  - dropdown mode items_noposter / shows_noposter
        //  - no_poster=1 query flag
        //  - magic text in Filter box: noposter | no poster | missing poster
        $noPoster = !empty($opts['no_poster']) || !empty($opts['missing_poster']);
        if (preg_match('/^(items|shows)_noposter$/i', $mode, $mm)) {
            $noPoster = true;
            $mode = strtolower($mm[1]); // items | shows
        }
        $qLower = strtolower($q);
        if (!$noPoster && $q !== '') {
            if (preg_match('/^(#?\s*no[\s_-]*poster|missing[\s_-]*poster|without[\s_-]*poster)$/i', $q)
                || $qLower === 'noposter' || $qLower === 'no-poster') {
                $noPoster = true;
                $q = '';
            }
        }

        $settings = Store::settings();
        $posterDir = Posters::dir();
        $secMeta = null;
        foreach ($settings['sections'] ?? [] as $s) {
            if (($s['id'] ?? '') === $section) { $secMeta = $s; break; }
        }
        $type = $secMeta['type'] ?? '';
        if ($mode === 'auto') {
            $mode = in_array($type, ['tv', 'anime'], true) ? 'shows' : 'items';
        }

        if ($mode === 'shows') {
            $shows = $section !== '' ? Catalogue::sectionShows($section) : [];
            if ($section === '') {
                foreach ($settings['sections'] ?? [] as $s) {
                    if (!in_array($s['type'] ?? '', ['tv', 'anime'], true)) continue;
                    if (empty($s['enabled'])) continue;
                    foreach (Catalogue::sectionShows($s['id']) as $sh) $shows[] = $sh;
                }
            }
            if ($q !== '') {
                $ql = strtolower($q);
                $shows = array_values(array_filter($shows, function ($it) use ($ql) {
                    return stripos($it['title'] ?? '', $ql) !== false;
                }));
            }
            if ($noPoster) {
                $shows = array_values(array_filter($shows, function ($sh) use ($posterDir) {
                    return !self::itemHasPosterFile($sh, $posterDir);
                }));
            }
            $total = count($shows);
            $slice = array_slice($shows, ($page - 1) * $per, $per);
            return [
                'ok' => true,
                'mode' => 'shows',
                'section' => $section,
                'page' => $page,
                'per' => $per,
                'total' => $total,
                'no_poster' => $noPoster,
                'shows' => array_map(['Catalogue', 'publicShow'], $slice),
            ];
        }

        $items = $section !== '' ? Catalogue::sectionItems($section) : array_values(Catalogue::get()['items'] ?? []);
        if ($q !== '') {
            $ql = strtolower($q);
            $items = array_values(array_filter($items, function ($it) use ($ql) {
                return stripos($it['title'] ?? '', $ql) !== false
                    || stripos($it['show'] ?? '', $ql) !== false
                    || stripos($it['rel'] ?? '', $ql) !== false
                    || stripos($it['path'] ?? '', $ql) !== false;
            }));
        }
        if ($noPoster) {
            $items = array_values(array_filter($items, function ($it) use ($posterDir) {
                return !self::itemHasPosterFile($it, $posterDir);
            }));
        }
        usort($items, function ($a, $b) {
            $as = strtolower(($a['show'] ?? '') . ' ' . ($a['title'] ?? ''));
            $bs = strtolower(($b['show'] ?? '') . ' ' . ($b['title'] ?? ''));
            return $as <=> $bs;
        });
        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $per, $per);
        $out = [];
        foreach ($slice as $it) {
            $pub = Catalogue::publicItem($it);
            $pub['path'] = $it['path'] ?? null;
            $pub['rel'] = $it['rel'] ?? null;
            $out[] = $pub;
        }
        return [
            'ok' => true,
            'mode' => 'items',
            'section' => $section,
            'page' => $page,
            'per' => $per,
            'total' => $total,
            'no_poster' => $noPoster,
            'items' => $out,
        ];
    }

    /** True if a cached poster file exists for this item/show. */
    private static function itemHasPosterFile(array $it, string $posterDir): bool {
        // Explicit flag from catalogue / publicItem
        if (array_key_exists('has_poster', $it) && $it['has_poster']) return true;
        if (!empty($it['poster_url'])) return true;

        $ids = [];
        if (!empty($it['id'])) $ids[] = (string)$it['id'];
        if (!empty($it['show_key'])) $ids[] = (string)$it['show_key'];
        // Season art key used by the frontend
        if (!empty($it['show_key']) && isset($it['season']) && $it['season'] !== '' && $it['season'] !== null) {
            $ids[] = $it['show_key'] . '-s' . (int)$it['season'];
        }

        foreach ($ids as $id) {
            if ($id === '') continue;
            foreach (['.jpg', '.png', '.webp', ''] as $ext) {
                if (is_file($posterDir . '/' . $id . $ext)) return true;
            }
        }
        return false;
    }

    /** File browser under allowed library roots. */
    public static function browseFiles(array $opts = []): array {
        $path = trim((string)($opts['path'] ?? ''));
        $roots = self::allowedRoots();
        if ($path === '') {
            return ['ok' => true, 'path' => '', 'parent' => null, 'entries' => array_map(function ($r) {
                return [
                    'name' => $r['label'],
                    'path' => $r['path'],
                    'type' => 'dir',
                    'section_id' => $r['id'],
                    'section_type' => $r['type'],
                ];
            }, $roots)];
        }
        if (!self::underAllowed($path) || !is_dir($path)) {
            return ['ok' => false, 'error' => 'Path not allowed or missing'];
        }
        $real = \Vaultcast\Paths::real($path);
        if ($real === false) return ['ok' => false, 'error' => 'Invalid path'];
        $entries = [];
        $list = @scandir($real);
        if ($list === false) return ['ok' => false, 'error' => 'Cannot read directory'];
        $exts = Library::videoExtensions();
        $scan = Store::settings()['scan'] ?? [];
        $romExt = array_map('strtolower', $scan['rom_extensions'] ?? []);
        foreach ($list as $name) {
            if ($name === '.' || $name === '..') continue;
            if ($name === '#recycle' || $name === '@eaDir' || $name[0] === '.') continue;
            $full = $real . '/' . $name;
            $isDir = is_dir($full);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $kind = $isDir ? 'dir' : (in_array($ext, $exts, true) ? 'video' : (in_array($ext, $romExt, true) ? 'rom' : 'file'));
            $entries[] = [
                'name' => $name,
                'path' => $full,
                'type' => $kind,
                'size' => $isDir ? null : (@filesize($full) ?: 0),
                'mtime' => @filemtime($full) ?: null,
            ];
        }
        usort($entries, function ($a, $b) {
            if ($a['type'] === 'dir' && $b['type'] !== 'dir') return -1;
            if ($b['type'] === 'dir' && $a['type'] !== 'dir') return 1;
            return strcasecmp($a['name'], $b['name']);
        });
        $parent = dirname($real);
        if (!self::underAllowed($parent) || $parent === $real) {
            // At a root — parent goes to root list
            $atRoot = false;
            foreach ($roots as $r) {
                if ($r['path'] === $real) { $atRoot = true; break; }
            }
            $parentOut = $atRoot ? '' : (self::underAllowed($parent) ? $parent : '');
        } else {
            $parentOut = $parent;
        }
        return [
            'ok' => true,
            'path' => $real,
            'parent' => $parentOut,
            'entries' => $entries,
        ];
    }

    /**
     * Online metadata search — TVMaze for TV/anime; OMDb (primary) + Wikipedia fallback for movies.
     * Movies always prefer OMDb for posters + plot. Uses a public free OMDb key
     * (no registration required for basic title lookups).
     */
    public static function searchMetadata(array $opts = []): array {
        $q = trim((string)($opts['query'] ?? $opts['q'] ?? ''));
        $kind = trim((string)($opts['kind'] ?? 'auto')); // auto|tv|movie
        if ($q === '') return ['ok' => false, 'error' => 'query required'];
        if ($kind === 'auto') {
            $kind = (preg_match('/\b(S\d+|season|episode)\b/i', $q) || stripos($q, 'anime') !== false) ? 'tv' : 'movie';
        }
        return PublicMetadata::search($kind, $q);
    }

    /**
     * Strip common scene/release-group noise so metadata search works.
     * e.g. "Alien Covenant WEB-DL -EVO 1080p" → "Alien Covenant"
     */
    private static function cleanMovieQuery(string $q): string {
        $q = trim($q);
        // Remove year in parentheses first so we can re-attach later if needed
        $year = null;
        if (preg_match('/\((\d{4})\)/', $q, $m)) {
            $year = $m[1];
            $q = preg_replace('/\s*\(\d{4}\)\s*/', ' ', $q);
        }
        // Common release tags / codecs / sources
        $junk = [
            'WEB[-.]?DL', 'WEB[-.]?Rip', 'BluRay', 'Blu-Ray', 'BRRip', 'BDRip', 'HDRip',
            'DVDRip', 'HDTV', 'PDTV', 'CAM', 'TS', 'TC', 'R5', 'SCR', 'DVDscr',
            'x264', 'x265', 'h264', 'h265', 'HEVC', 'AVC', 'XviD', 'DivX',
            'AAC', 'AC3', 'DTS', 'TrueHD', 'Atmos', 'DD5\.1', 'DDP5\.1',
            '1080p', '720p', '2160p', '4K', 'UHD', 'HDR', 'HDR10', 'DV', 'DoVi',
            'REPACK', 'PROPER', 'EXTENDED', 'UNRATED', 'DIRECTORS?\.?CUT', 'DC',
            'MULTI', 'SUBBED', 'DUBBED', 'ENG', 'ITA', 'FRA', 'GER', 'SPA',
            '-EVO', '-RARBG', '-YIFY', '-YTS', '-SPARKS', '-GECKOS', '-FGT',
            '-AMZN', '-NF', '-DSNP', '-HULU', '-ATVP', '-iT', '-PCOK',
        ];
        $pattern = '/\b(' . implode('|', $junk) . ')\b/i';
        $q = preg_replace($pattern, ' ', $q);
        // Collapse leftover punctuation / multiple spaces
        $q = preg_replace('/[._]+/', ' ', $q);
        $q = preg_replace('/\s{2,}/', ' ', $q);
        $q = trim($q, " \t-");
        if ($year) $q .= ' ' . $year;
        return trim($q);
    }

    /** Update catalogue fields for an item (optional on-disk rename via Library::apply). */
    public static function updateItem(array $body): array {
        $id = trim((string)($body['id'] ?? $body['item_id'] ?? ''));
        if ($id === '') return ['ok' => false, 'error' => 'item id required'];
        $cat = Catalogue::get();
        $it = $cat['items'][$id] ?? null;
        if (!$it) return ['ok' => false, 'error' => 'Item not found'];

        $rename = !empty($body['rename']);
        $title = array_key_exists('title', $body) ? trim((string)$body['title']) : ($it['title'] ?? '');
        $show = array_key_exists('show', $body) ? trim((string)$body['show']) : ($it['show'] ?? '');
        $year = array_key_exists('year', $body) ? ($body['year'] !== '' && $body['year'] !== null ? (int)$body['year'] : null) : ($it['year'] ?? null);
        $season = array_key_exists('season', $body) ? ($body['season'] !== '' && $body['season'] !== null ? (int)$body['season'] : null) : ($it['season'] ?? null);
        $episode = array_key_exists('episode', $body) ? ($body['episode'] !== '' && $body['episode'] !== null ? (int)$body['episode'] : null) : ($it['episode'] ?? null);
        $sectionId = trim((string)($body['section_id'] ?? $it['section'] ?? ''));

        if ($rename) {
            $kind = in_array($it['type'] ?? '', ['tv', 'anime'], true) ? 'tv' : 'movie';
            $apply = Library::apply([
                'path' => $it['path'],
                'section_id' => $sectionId,
                'kind' => $kind,
                'title' => $kind === 'tv' ? ($show ?: $title) : $title,
                'show' => $show ?: $title,
                'year' => $year,
                'season' => $season,
                'episode' => $episode,
                'overwrite' => !empty($body['overwrite']),
            ]);
            return $apply;
        }

        // Save metadata changes to the catalogue.
        $it['title'] = $title !== '' ? $title : $it['title'];
        if ($show !== '') $it['show'] = $show;
        $it['year'] = $year;
        $it['season'] = $season;
        $it['episode'] = $episode;
        if ($sectionId !== '' && $sectionId !== ($it['section'] ?? '')) {
            $it['section'] = $sectionId;
            foreach (Store::settings()['sections'] ?? [] as $sec) {
                if (($sec['id'] ?? '') === $sectionId) {
                    $it['type'] = $sec['type'] ?? $it['type'];
                    break;
                }
            }
        }
        $cat['items'][$id] = $it;
        $cat['updated_at'] = date('c');
        Catalogue::save($cat);
        Store::invalidate();
        $pub = Catalogue::publicItem($it);
        $pub['path'] = $it['path'] ?? null;
        $pub['rel'] = $it['rel'] ?? null;
        return ['ok' => true, 'item' => $pub, 'renamed' => false];
    }

    /**
     * Reassign episodes (by item ids or whole show_key) to a different show name.
     * Optionally renames on disk into standard Show/Season XX/ layout.
     */
    public static function reassignShow(array $body): array {
        $newShow = trim((string)($body['show'] ?? $body['new_show'] ?? ''));
        if ($newShow === '') return ['ok' => false, 'error' => 'new show name required'];
        $rename = !empty($body['rename']);
        $overwrite = !empty($body['overwrite']);
        $itemIds = $body['item_ids'] ?? [];
        $showKey = trim((string)($body['show_key'] ?? ''));
        $cat = Catalogue::get();
        $targets = [];

        if (is_array($itemIds) && $itemIds) {
            foreach ($itemIds as $id) {
                $id = (string)$id;
                if (isset($cat['items'][$id])) $targets[] = $cat['items'][$id];
            }
        } elseif ($showKey !== '') {
            $detail = Catalogue::show($showKey);
            if (!$detail) return ['ok' => false, 'error' => 'Show not found'];
            foreach ($detail['seasons'] ?? [] as $sb) {
                foreach ($sb['episodes'] ?? [] as $ep) {
                    if (!empty($ep['available']) && !empty($ep['id']) && isset($cat['items'][$ep['id']])) {
                        $targets[] = $cat['items'][$ep['id']];
                    }
                }
            }
        } else {
            return ['ok' => false, 'error' => 'item_ids or show_key required'];
        }

        if (!$targets) return ['ok' => false, 'error' => 'No matching items'];

        $results = [];
        $ok = 0;
        $fail = 0;
        foreach ($targets as $it) {
            if ($rename) {
                $r = Library::apply([
                    'path' => $it['path'],
                    'section_id' => $it['section'],
                    'kind' => 'tv',
                    'show' => $newShow,
                    'title' => $newShow,
                    'season' => $it['season'] ?? 1,
                    'episode' => $it['episode'] ?? 1,
                    'year' => $it['year'] ?? null,
                    'overwrite' => $overwrite,
                ]);
            } else {
                $r = self::updateItem([
                    'id' => $it['id'],
                    'show' => $newShow,
                    'title' => $it['title'] ?? $newShow,
                    'season' => $it['season'],
                    'episode' => $it['episode'],
                    'year' => $it['year'],
                    'rename' => false,
                ]);
            }
            $results[] = ['id' => $it['id'], 'result' => $r];
            if (!empty($r['ok'])) $ok++; else $fail++;
        }
        return ['ok' => true, 'updated' => $ok, 'failed' => $fail, 'show' => $newShow, 'results' => $results];
    }

    /** Ingest a file path into a section (after manual move). */
    public static function ingest(array $body): array {
        $path = trim((string)($body['path'] ?? ''));
        $sectionId = trim((string)($body['section_id'] ?? ''));
        if ($path === '' || $sectionId === '') return ['ok' => false, 'error' => 'path and section_id required'];
        if (!is_file($path)) return ['ok' => false, 'error' => 'File not found'];
        if (!self::underAllowed($path) && !self::underDownloadRoots($path)) {
            return ['ok' => false, 'error' => 'Path not under allowed roots'];
        }
        $item = Catalogue::ingestFile($path, $sectionId);
        if (!$item) return ['ok' => false, 'error' => 'Ingest failed (path must end under section root)'];
        return ['ok' => true, 'item' => Catalogue::publicItem($item)];
    }

    private static function underDownloadRoots(string $path): bool {
        $real = \Vaultcast\Paths::real($path);
        if ($real === false) return false;
        foreach (Library::defaultScanRoots() as $r) {
            $rr = \Vaultcast\Paths::real($r);
            if ($rr && (strpos($real, $rr . '/') === 0 || $real === $rr)) return true;
        }
        return false;
    }

    private static function yearFromDate(?string $d): ?int {
        if (!$d) return null;
        if (preg_match('/^(\d{4})/', $d, $m)) return (int)$m[1];
        return null;
    }

    private static function stripHtml(?string $html): string {
        if (!$html) return '';
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function httpGet(string $url): ?string {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 20,
                    'header' => "User-Agent: Vaultcast/" . STREAM_VERSION . " (+media-manager)\r\nAccept: application/json\r\n",
                    'follow_location' => 1,
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            return $body === false ? null : $body;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . ' (+media-manager)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) return null;
        return $raw;
    }
}
