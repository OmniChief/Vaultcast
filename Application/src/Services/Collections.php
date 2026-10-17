<?php
declare(strict_types=1);

class Collections {
    public static function all(): array {
        $c = Store::get('collections', []);
        return is_array($c) ? $c : [];
    }

    public static function save(array $list): bool {
        return Store::set('collections', $list);
    }

    public static function get(string $id): ?array {
        foreach (self::all() as $c) {
            if (($c['id'] ?? '') === $id) return $c;
        }
        return null;
    }

    /**
     * Collections page: reads only what Settings stored (no matching, catalogue load, HTTP or file checks).
     * Counts and the cover were computed by refresh().
     */
    public static function publicList(): array {
        $revision = \Vaultcast\Repository::revision('collections') . '|' . \Vaultcast\Repository::revision('catalogue');
        $hit = \Vaultcast\Repository::cacheGet('public-collections-v1');
        if (($hit['revision'] ?? '') === $revision) return $hit['data'];
        $data = self::buildPublicList();
        $after = \Vaultcast\Repository::revision('collections') . '|' . \Vaultcast\Repository::revision('catalogue');
        if ($revision === $after) \Vaultcast\Repository::cachePut('public-collections-v1', ['revision'=>$revision,'data'=>$data], 0);
        return $data;
    }
    private static function buildPublicList(): array {
        $out = [];
        $all = self::all();
        $owned = self::ownedItems($all);
        foreach ($all as $c) {
            $parts = is_array($c['parts'] ?? null) ? $c['parts'] : [];
            $present = 0;
            $cover = null;
            foreach ($parts as $p) {
                if (!is_array($p)) continue;
                $p = self::withOwned($p, $owned);
                if (empty($p['present'])) continue;
                $present++;
                if ($cover === null && !empty($p['item'])) $cover = $p['item'];
            }
            $out[] = [
                'id' => $c['id'],
                'name' => $c['name'] ?? '',
                'type' => $c['type'] ?? 'movies',
                'description' => $c['description'] ?? '',
                'source' => $c['source'] ?? null,
                'source_url' => $c['source_url'] ?? null,
                'poster_url' => $c['poster_url'] ?? null,
                'part_count' => count($parts),
                'available_count' => $present,
                'missing_count' => count($parts) - $present,
                'cover_item' => $cover,
                'refreshed' => !empty($c['refreshed_at']),
            ];
        }
        // Collections stored before snapshots existed: start ONE background refresh (never matched in-request)
        self::ensureRefreshedOnce($out);
        /* VoD Collections tab always A–Z by collection name */
        usort($out, function ($a, $b) {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        return ['ok' => true, 'collections' => $out];
    }

    /**
     * Library items for the item_ids stored on parts: one indexed primary-key query (no catalogue load).
     * Lets owned/missing show correctly even before (or without) a Settings refresh.
     */
    private static function ownedItems(array $collections): array {
        $ids = [];
        foreach ($collections as $c) {
            foreach ((array)($c['parts'] ?? []) as $p) {
                if (is_array($p) && !empty($p['item_id'])) $ids[(string)$p['item_id']] = true;
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $rows = \Vaultcast\Repository::rows('SELECT payload FROM vaultcast_items WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            foreach ($rows as $it) if (is_array($it) && isset($it['id'])) $out[(string)$it['id']] = $it;
        }
        return $out;
    }

    /** Owned = the stored item_id still exists in the library; snapshot item falls back to the live row. */
    private static function withOwned(array $part, array $owned): array {
        $iid = (string)($part['item_id'] ?? '');
        $part['present'] = $iid !== '' && isset($owned[$iid]);
        if ($part['present'] && empty($part['item'])) $part['item'] = Catalogue::publicItem($owned[$iid], false);
        return $part;
    }

    /** Public view of one stored part (snapshot written by refresh()). */
    private static function publicPart(int $i, array $part): array {
        return [
            'index' => $i,
            'title' => $part['title'] ?? '',
            'year' => $part['year'] ?? null,
            'present' => !empty($part['present']),
            'item_id' => !empty($part['present']) ? ($part['item_id'] ?? null) : null,
            'linked' => !empty($part['item_id']) ? (($part['link'] ?? 'auto') === 'manual' ? 'manual' : 'auto') : null,
            'match' => $part['match'] ?? null,
            'item' => !empty($part['present']) ? ($part['item'] ?? null) : null,
            'overview' => $part['overview'] ?? '',
            'poster' => !empty($part['poster_file']) ? 'collections/poster/' . $part['poster_file'] : null,
            'ext_id' => $part['ext_id'] ?? null,
        ];
    }

    /**
     * Settings-side refresh: link parts to library items, store availability + a public item snapshot,
     * and fetch/cache poster + synopsis for members (owned or not). Network only here.
     */
    public static function refresh(?string $onlyId = null, bool $fetchMeta = true): array {
        $res = self::rematch($onlyId);
        $items = self::catalogueItems();
        // 1) Owned/missing + item snapshot for every part, saved at once (fast, no network)
        $list = self::all();
        foreach ($list as &$c) {
            if ($onlyId !== null && ($c['id'] ?? '') !== $onlyId) continue;
            foreach ($c['parts'] as &$part) {
                if (!is_array($part)) continue;
                $iid = (string)($part['item_id'] ?? '');
                $part['present'] = $iid !== '' && isset($items[$iid]);
                $part['item'] = $part['present'] ? Catalogue::publicItem($items[$iid], false) : null;
            }
            unset($part);
            $c['refreshed_at'] = date('c');
        }
        unset($c);
        self::save($list);
        if (!$fetchMeta) return $res;
        // 2) Posters + synopses (network), saved per collection and merged into the latest list
        foreach ($list as $c) {
            if ($onlyId !== null && ($c['id'] ?? '') !== $onlyId) continue;
            $meta = [];
            foreach ((array)($c['parts'] ?? []) as $k => $part) {
                if (!is_array($part)) continue;
                if (class_exists('Maintenance')) Maintenance::checkpoint();
                if (!empty($part['meta_at']) && (!empty($part['poster_file']) || strtotime((string)$part['meta_at']) > time() - 7 * 86400)) continue;
                self::fetchPartMeta($part);
                $meta[self::partSig($part)] = array_intersect_key($part, array_flip(['meta_at', 'overview', 'poster_file', 'ext_id']));
            }
            if (!$meta) continue;
            $latest = self::all();
            foreach ($latest as &$lc) {
                if (($lc['id'] ?? '') !== ($c['id'] ?? '')) continue;
                foreach ($lc['parts'] as &$lp) {
                    if (is_array($lp) && isset($meta[self::partSig($lp)])) $lp = array_merge($lp, $meta[self::partSig($lp)]);
                }
                unset($lp);
            }
            unset($lc);
            self::save($latest);
        }
        return $res;
    }

    private static function partSig(array $p): string {
        return strtolower(trim((string)($p['title'] ?? ''))) . '|' . (string)($p['year'] ?? '');
    }

    /** Start ONE background refresh for collections never refreshed; the flag is set only when the job started. */
    public static function ensureRefreshedOnce(array $public): void {
        if (!array_filter($public, function ($c) { return empty($c['refreshed']); })) return;
        if (Store::get('collections_snapshot_v2', false)) return;
        try {
            Maintenance::start('collections', 'Settings');
            Store::set('collections_snapshot_v2', date('c'));
        } catch (Throwable $e) { /* retried on a later load */ }
    }

    /** Refresh in the background (Maintenance CLI job); inline without network if no worker can start. */
    public static function queueRefresh(?string $id = null, bool $inlineFallback = true): void {
        try {
            Maintenance::start('collections', 'Settings', $id);
        } catch (Throwable $e) {
            if ($inlineFallback) { try { self::refresh($id, false); } catch (Throwable $ignored) {} }
        }
    }

    public static function posterDir(): string {
        $d = Posters::dir() . '/collections';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    /** Poster + synopsis for one member: TMDB (with a key) else Wikipedia summary. */
    private static function fetchPartMeta(array &$part): void {
        $part['meta_at'] = date('c');
        $title = trim((string)($part['title'] ?? ''));
        if ($title === '') return;
        $year = !empty($part['year']) ? (int)$part['year'] : 0;
        $img = null; $overview = '';
        try {
            if (CollectionDiscovery::tmdbKey() !== '') {
                $tid = preg_match('/^tmdb:(\d+)$/', (string)($part['ext_id'] ?? ''), $m) ? (int)$m[1] : 0;
                if (!$tid) {
                    $q = ['query' => $title];
                    if ($year) $q['year'] = $year;
                    $f = CollectionDiscovery::tmdbGet('/search/movie', $q);
                    if (is_array($f) && !empty($f['results'][0]['id'])) $tid = (int)$f['results'][0]['id'];
                    if ($tid) $part['ext_id'] = 'tmdb:' . $tid;
                }
                if ($tid) {
                    $mv = CollectionDiscovery::tmdbGet('/movie/' . $tid);
                    if (is_array($mv)) {
                        $overview = trim((string)($mv['overview'] ?? ''));
                        if (!empty($mv['poster_path'])) $img = 'https://image.tmdb.org/t/p/w342' . $mv['poster_path'];
                    }
                }
            }
            if ($overview === '' && $img === null) {
                foreach (array_filter([$year ? $title . ' (' . $year . ' film)' : null, $title . ' (film)', $title]) as $page) {
                    $body = self::httpGet('https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $page)));
                    $j = $body ? json_decode($body, true) : null;
                    if (!is_array($j) || ($j['type'] ?? '') === 'disambiguation' || empty($j['extract'])) continue;
                    $overview = trim((string)$j['extract']);
                    $img = (string)($j['originalimage']['source'] ?? $j['thumbnail']['source'] ?? '') ?: null;
                    break;
                }
            }
        } catch (Throwable $e) { return; }
        if ($overview !== '') $part['overview'] = function_exists('mb_substr') ? mb_substr($overview, 0, 2000) : substr($overview, 0, 2000);
        if ($img !== null && preg_match('#^https://#i', $img)) {
            $bin = self::httpGet($img);
            if ($bin !== null && strlen($bin) > 1000 && strlen($bin) < 8 * 1024 * 1024 && in_array(substr($bin, 0, 3), ["\xFF\xD8\xFF", "\x89PN"], true)) {
                $name = substr(sha1($img), 0, 16) . (substr($bin, 0, 3) === "\x89PN" ? '.png' : '.jpg');
                if (@file_put_contents(self::posterDir() . '/' . $name, $bin) !== false) $part['poster_file'] = $name;
            }
        }
    }

    public static function create(array $data, bool $refresh = true): array {
        $list = self::all();
        $col = [
            'id' => bin2hex(random_bytes(8)),
            'name' => trim($data['name'] ?? 'New Collection'),
            'type' => $data['type'] ?? 'movies',
            'description' => $data['description'] ?? '',
            'parts' => $data['parts'] ?? [], // [{title, year, item_id?}]
            'source' => $data['source'] ?? null,
            'source_url' => $data['source_url'] ?? null,
            'source_query' => $data['source_query'] ?? null,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $list[] = $col;
        self::save($list);
        if ($refresh) self::queueRefresh($col['id']);
        return ['ok' => true, 'collection' => $col];
    }

    public static function update(string $id, array $data): array {
        $list = self::all();
        foreach ($list as &$c) {
            if (($c['id'] ?? '') !== $id) continue;
            foreach (['name','type','description','parts','source','source_url','source_query'] as $k) {
                if (array_key_exists($k, $data)) $c[$k] = $data[$k];
            }
            $c['updated_at'] = date('c');
            self::save($list);
            if (array_key_exists('parts', $data) || array_key_exists('type', $data)) self::queueRefresh($id);
            return ['ok' => true, 'collection' => $c];
        }
        json_error('Not found', 404);
        return [];
    }

    public static function delete(string $id): array {
        $list = array_values(array_filter(self::all(), function ($c) use ($id) {
            return ($c['id'] ?? '') !== $id;
        }));
        self::save($list);
        return ['ok' => true];
    }

    /**
     * Match collection parts against the catalogue; report missing.
     *
     * matching is normalized instead of raw strtolower/stripos.
     * Library folders can't hold ":" so "Alien: Covenant" lives on disk as
     * "Alien - Covenant (2017)"; Wikipedia uses en dashes, "&", superscripts
     * (Alien³), fractions (2½) etc. Order of resolution per part:
     *   1. pinned item_id (manual link, or auto link written by rematch) still in catalogue
     *   2. normalized title key + year (±1, or either side unknown)
     *   3. same key with leading article dropped
     *   4. contiguous token containment, exact year ("Alien vs. Predator" ⊂ "AVP Alien vs. Predator")
     *   5. fuzzy (similar_text on compact key), exact year
     * Movie collections only match video items (never ROMs / TV episodes).
     */
    /**
     * collections that contain a library item (by pinned/auto item_id or live match).
     * Used by movie detail to show the rest of the franchise/collection.
     */
    public static function forItem(string $itemId): array {
        $itemId = (string)$itemId;
        if ($itemId === '') json_error('item_id required', 400);
        $out = [];
        $all = array_values(array_filter(self::all(), function ($c) use ($itemId) {
            foreach ((array)($c['parts'] ?? []) as $p) if (is_array($p) && (string)($p['item_id'] ?? '') === $itemId) return true;
            return false;
        }));
        $owned = self::ownedItems($all);
        foreach ($all as $c) {
            $parts = [];
            $matched = false;
            foreach ((array)($c['parts'] ?? []) as $i => $part) {
                if (!is_array($part)) continue;
                $pp = self::publicPart((int)$i, self::withOwned($part, $owned));
                $pp['current'] = $pp['present'] && (string)$pp['item_id'] === $itemId;
                if ($pp['current']) $matched = true;
                $parts[] = $pp;
            }
            if ($matched) {
                $out[] = ['id' => $c['id'] ?? '', 'name' => $c['name'] ?? '', 'type' => (string)($c['type'] ?? 'movies'), 'description' => $c['description'] ?? '', 'parts' => $parts];
            }
        }
        return ['ok' => true, 'item_id' => $itemId, 'collections' => $out];
    }

    /** One collection from the stored snapshot (no matching, no network). */
    public static function status(string $id): array {
        $col = self::get($id);
        if (!$col) json_error('Not found', 404);
        $parts = [];
        $owned = self::ownedItems([$col]);
        foreach ((array)($col['parts'] ?? []) as $i => $part) {
            if (is_array($part)) $parts[] = self::publicPart((int)$i, self::withOwned($part, $owned));
        }
        $missing = array_values(array_filter($parts, function ($p) { return !$p['present']; }));
        $pub = $col;
        unset($pub['parts']);
        return ['ok' => true, 'collection' => $pub, 'parts' => $parts, 'missing' => $missing];
    }

    /**
     * Re-resolve every part (or one collection) against the library and persist
     * auto links (part.item_id + link=auto). Manual links are kept unless they
     * point at an item that no longer exists. Safe to re-run any time.
     */
    public static function rematch(?string $onlyId = null): array {
        self::$matchIndex = null; // fresh catalogue view
        $list = self::all();
        $summary = [];
        $tot = ['parts' => 0, 'present' => 0, 'missing' => 0, 'changed' => 0, 'linked' => 0, 'manual' => 0];
        $items = self::catalogueItems();
        foreach ($list as &$c) {
            if ($onlyId !== null && ($c['id'] ?? '') !== $onlyId) continue;
            $type = (string)($c['type'] ?? 'movies');
            $row = ['id' => $c['id'] ?? '', 'name' => $c['name'] ?? '', 'present' => 0, 'missing' => [], 'changed' => []];
            if (!isset($c['parts']) || !is_array($c['parts'])) $c['parts'] = [];
            foreach ($c['parts'] as &$part) {
                Maintenance::checkpoint();
                if (!is_array($part)) continue;
                $tot['parts']++;
                $before = (string)($part['item_id'] ?? '');
                $manual = !empty($part['item_id']) && ($part['link'] ?? '') === 'manual' && isset($items[$part['item_id']]);
                if (!$manual) {
                    unset($part['item_id'], $part['link'], $part['match']);
                    $res = self::matchPart($part, $type);
                    if ($res) {
                        $part['item_id'] = (string)$res['item']['id'];
                        $part['link'] = 'auto';
                        $part['match'] = $res['how'];
                        $tot['linked']++;
                    }
                } else {
                    $res = ['how' => 'linked'];
                    $tot['manual']++;
                }
                $after = (string)($part['item_id'] ?? '');
                $label = trim(($part['title'] ?? '') . (!empty($part['year']) ? ' (' . $part['year'] . ')' : ''));
                if ($res) {
                    $row['present']++;
                    $tot['present']++;
                    if ($before !== $after) { $row['changed'][] = $label . ' => ' . Catalogue::fixText((string)($items[$after]['title'] ?? '')); $tot['changed']++; }
                } else {
                    $row['missing'][] = $label;
                    $tot['missing']++;
                }
            }
            unset($part);
            $c['updated_at'] = date('c');
            $c['rematched_at'] = date('c');
            $summary[] = $row;
        }
        unset($c);
        if ($onlyId !== null && !$summary) json_error('Not found', 404);
        self::save($list);
        return ['ok' => true, 'totals' => $tot, 'collections' => $summary];
    }

    /** Manually link (item_id) or unlink (null) one part by index. */
    public static function linkPart(string $id, array $body): array {
        $idx = isset($body['index']) ? (int)$body['index'] : -1;
        $itemId = isset($body['item_id']) && $body['item_id'] !== '' ? (string)$body['item_id'] : null;
        $list = self::all();
        foreach ($list as &$c) {
            if (($c['id'] ?? '') !== $id) continue;
            if (!isset($c['parts'][$idx]) || !is_array($c['parts'][$idx])) json_error('Bad part index', 400);
            if ($itemId !== null) {
                $items = self::catalogueItems();
                if (!isset($items[$itemId])) json_error('Item not in catalogue', 404);
                $c['parts'][$idx]['item_id'] = $itemId;
                $c['parts'][$idx]['link'] = 'manual';
                $c['parts'][$idx]['match'] = 'linked';
            } else {
                unset($c['parts'][$idx]['item_id'], $c['parts'][$idx]['link'], $c['parts'][$idx]['match']);
            }
            $c['updated_at'] = date('c');
            self::save($list);
            self::refresh($id, false);
            return self::status($id);
        }
        json_error('Not found', 404);
        return [];
    }

    // ---------------------------------------------------------------------
    // Library matching helpers ()
    // ---------------------------------------------------------------------

    /** @var array|null */
    private static $matchIndex = null;

    private static function catalogueItems(): array {
        $cat = Catalogue::get();
        $items = $cat['items'] ?? [];
        return is_array($items) ? $items : [];
    }

    /**
     * Resolve one part. Returns ['item'=>raw catalogue item, 'how'=>string] or null.
     * $usePin=false ignores a stored item_id (used to compute "before" state).
     */
    public static function matchPart(array $part, string $colType = 'movies', bool $usePin = true): ?array {
        $idx = self::matchIndex();
        $items = $idx['items'];
        if ($usePin && !empty($part['item_id']) && isset($items[$part['item_id']])) {
            return ['item' => $items[$part['item_id']], 'how' => 'linked'];
        }
        $title = (string)($part['title'] ?? '');
        if (trim($title) === '') return null;
        $year = isset($part['year']) && $part['year'] !== '' && $part['year'] !== null ? (int)$part['year'] : null;
        $isTv = in_array($colType, ['tv', 'anime'], true);

        if ($isTv) {
            $k = self::titleKeys($title);
            foreach (['compact', 'noart'] as $kk) {
                if ($k[$kk] !== '' && isset($idx['shows'][$kk][$k[$kk]])) {
                    return ['item' => $idx['shows'][$kk][$k[$kk]], 'how' => 'show'];
                }
            }
        }
        return self::matchMovie($title, $year, $idx);
    }

    private static function matchMovie(string $title, ?int $year, array $idx): ?array {
        $k = self::titleKeys($title);
        if ($k['compact'] === '') return null;
        // Tier 1/2: key equality with tolerant year
        foreach (['compact' => 'title', 'noart' => 'title_noart'] as $kk => $how) {
            $cands = $idx['movies'][$kk][$k[$kk]] ?? [];
            if (!$cands) continue;
            $best = null; $bestScore = -1;
            foreach ($cands as $c) {
                $s = self::yearScore($year, $c['year']);
                if ($s < 0) continue;
                if ($s > $bestScore) { $best = $c; $bestScore = $s; }
            }
            if ($best) return ['item' => $best['item'], 'how' => $how . ($bestScore === 3 ? '+year' : ($bestScore === 1 ? '+year±1' : ''))];
        }
        // Tier 3/4 need a known year on both sides (exact) to stay safe.
        if ($year === null) return null;
        $toks = $k['tokens'];
        $bestFuzzy = null; $bestPct = 0.0;
        foreach ($idx['movies']['list'] as $c) {
            if ($c['year'] === null || $c['year'] !== $year) continue;
            foreach ($c['keys'] as $ck) {
                // containment (shorter has >= 2 tokens)
                $a = $toks; $b = $ck['tokens'];
                if (count($a) > count($b)) { $t = $a; $a = $b; $b = $t; }
                if (count($a) >= 2 && self::tokensContain($b, $a)) {
                    return ['item' => $c['item'], 'how' => 'contains+year'];
                }
                $x = $k['compact']; $y = $ck['compact'];
                if (abs(strlen($x) - strlen($y)) > max(4, (int)(0.2 * max(strlen($x), strlen($y))))) continue;
                similar_text($x, $y, $pct);
                if ($pct > $bestPct) { $bestPct = $pct; $bestFuzzy = $c; }
            }
        }
        if ($bestFuzzy && $bestPct >= 88.0) {
            return ['item' => $bestFuzzy['item'], 'how' => 'fuzzy+year'];
        }
        return null;
    }

    /** 3 exact, 2 unknown on a side, 1 off by one, -1 incompatible. */
    private static function yearScore(?int $want, ?int $have): int {
        if ($want === null || $have === null) return 2;
        if ($want === $have) return 3;
        if (abs($want - $have) === 1) return 1;
        return -1;
    }

    private static function tokensContain(array $hay, array $needle): bool {
        $n = count($needle); $h = count($hay);
        for ($i = 0; $i + $n <= $h; $i++) {
            if (array_slice($hay, $i, $n) === $needle) return true;
        }
        return false;
    }

    private static function matchIndex(): array {
        if (self::$matchIndex !== null) return self::$matchIndex;
        $items = self::catalogueItems();
        $movies = ['compact' => [], 'noart' => [], 'list' => []];
        $shows = ['compact' => [], 'noart' => []];
        foreach ($items as $id => $it) {
            if (!is_array($it)) continue;
            $type = (string)($it['type'] ?? '');
            $kind = (string)($it['media_kind'] ?? '');
            if ($type === 'roms' || $kind === 'rom') continue;
            if (!empty($it['show'])) {
                $sk = self::titleKeys((string)$it['show']);
                if ($sk['compact'] !== '' && !isset($shows['compact'][$sk['compact']])) $shows['compact'][$sk['compact']] = $it;
                if ($sk['noart'] !== '' && !isset($shows['noart'][$sk['noart']])) $shows['noart'][$sk['noart']] = $it;
                continue;
            }
            if (in_array($type, ['tv', 'anime'], true)) continue;
            if ($kind !== '' && $kind !== 'video') continue;
            $rel = str_replace('\\', '/', (string)($it['rel'] ?? ''));
            $year = !empty($it['year']) ? (int)$it['year'] : null;
            $names = [(string)($it['title'] ?? '')];
            $dir = basename(dirname($rel));
            if ($dir !== '' && $dir !== '.') $names[] = $dir;
            $base = preg_replace('/\.[^.\/]+$/', '', basename($rel));
            $base = (string)preg_replace('/\.directplay$/i', '', (string)$base);
            if ($base !== '') $names[] = $base;
            $keys = [];
            $seen = [];
            foreach ($names as $ni => $nm) {
                if ($year === null && preg_match('/[\(\[]\s*((?:19|20)\d{2})\s*[\)\]]/', $nm, $ym)) $year = (int)$ym[1];
                // drop "(2015) Bluray-1080p" style tails
                $nm = (string)preg_replace('/[\(\[]\s*(19|20)\d{2}\s*[\)\]].*$/', '', $nm);
                // scene-style file names only ("Alien.Covenant.2017.1080p.WEB-DL"): cut at year + release tag
                if ($ni === 2 && strpos($nm, ' ') === false && substr_count($nm, '.') >= 2
                    && preg_match('/^(.+?)[._]((?:19|20)\d{2})[._](?:\d{3,4}p|bluray|brrip|bdrip|web|webrip|hdtv|dvdrip|x26|h26|remux|uhd|hdr)/i', $nm, $sm)) {
                    $nm = $sm[1];
                    if ($year === null) $year = (int)$sm[2];
                }
                $kk = self::titleKeys((string)$nm);
                if ($kk['compact'] === '' || isset($seen[$kk['compact']])) continue;
                $seen[$kk['compact']] = true;
                $keys[] = $kk;
            }
            if (!$keys) continue;
            $entry = ['item' => $it, 'year' => $year, 'keys' => $keys];
            foreach ($keys as $kk) {
                $movies['compact'][$kk['compact']][] = $entry;
                if ($kk['noart'] !== $kk['compact']) $movies['noart'][$kk['noart']][] = $entry;
            }
            // noart lookups must also hit titles that never had an article
            foreach ($keys as $kk) {
                if ($kk['noart'] === $kk['compact']) $movies['noart'][$kk['noart']][] = $entry;
            }
            $movies['list'][] = $entry;
        }
        self::$matchIndex = ['items' => $items, 'movies' => $movies, 'shows' => $shows];
        return self::$matchIndex;
    }

    /** @return array{norm:string,compact:string,noart:string,tokens:array} */
    public static function titleKeys(string $s): array {
        $n = self::normTitle($s);
        $tokens = $n === '' ? [] : explode(' ', $n);
        $noartTokens = $tokens;
        if (count($noartTokens) > 1 && in_array($noartTokens[0], ['the', 'a', 'an'], true)) array_shift($noartTokens);
        return [
            'norm' => $n,
            'compact' => str_replace(' ', '', $n),
            'noart' => implode('', $noartTokens),
            'tokens' => $tokens,
        ];
    }

    /** Normalize a title for matching: mojibake repair, transliterate, punctuation-free, lowercase. */
    public static function normTitle(string $s): string {
        $s = Catalogue::fixText($s);
        if ($s === '') return '';
        if (!preg_match('//u', $s)) {
            // not valid UTF-8 (Latin-1 filename) -> convert
            $s = function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252') : utf8_encode($s);
        }
        // UTF-8 read as Windows-1252 ("CafÃ©", "Donâ€™t")
        if (preg_match('/[\x{00C2}\x{00C3}]|\x{00E2}\x{20AC}/u', $s)) {
            $try = function_exists('mb_convert_encoding') ? @mb_convert_encoding($s, 'Windows-1252', 'UTF-8')
                : (function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//IGNORE', $s) : false);
            if (is_string($try) && $try !== '' && preg_match('//u', $try) && substr_count($try, '?') === substr_count($s, '?')) {
                $s = $try;
            }
        }
        static $map = null;
        if ($map === null) {
            $map = [
                // CP437/OEM mojibake leftovers (see Catalogue::fixText)
                "\xC3\x94\xC3\x87\xC3\x96" => "'", "\xC3\x94\xC3\x87\xC3\xBF" => "'",
                "\xC3\x94\xC3\x87\xC2\xA3" => '"', "\xC3\x94\xC3\x87\xC3\x98" => '"',
                "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'", "\xCA\xBC" => "'", '`' => "'", "\xC2\xB4" => "'",
                "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
                "\xE2\x80\x93" => ' ', "\xE2\x80\x94" => ' ', "\xE2\x80\x90" => ' ', "\xE2\x80\x91" => ' ', "\xE2\x88\x92" => ' ',
                "\xE2\x80\xA6" => ' ', "\xC2\xB7" => ' ', "\xC3\x97" => 'x',
                "\xC2\xB9" => '1', "\xC2\xB2" => '2', "\xC2\xB3" => '3',
                "\xC2\xBD" => ' 1/2', "\xC2\xBC" => ' 1/4', "\xC2\xBE" => ' 3/4',
                "\xE2\x85\x93" => ' 1/3', "\xE2\x85\x94" => ' 2/3', "\xE2\x81\x84" => '/',
                "\xC3\x86" => 'AE', "\xC3\xA6" => 'ae', "\xC5\x92" => 'OE', "\xC5\x93" => 'oe',
                "\xC3\x9F" => 'ss', "\xC3\x98" => 'O', "\xC3\xB8" => 'o', "\xC5\x81" => 'L', "\xC5\x82" => 'l',
                "\xC3\x90" => 'D', "\xC3\xB0" => 'd', "\xC3\x9E" => 'Th', "\xC3\xBE" => 'th',
            ];
            $acc = [
                'A' => 'ÀÁÂÃÄÅĀĂĄ', 'a' => 'àáâãäåāăą', 'C' => 'ÇĆĈĊČ', 'c' => 'çćĉċč',
                'D' => 'Ď', 'd' => 'ď', 'E' => 'ÈÉÊËĒĔĖĘĚ', 'e' => 'èéêëēĕėęě',
                'G' => 'ĜĞĠĢ', 'g' => 'ĝğġģ', 'I' => 'ÌÍÎÏĨĪĬĮİ', 'i' => 'ìíîïĩīĭįı',
                'N' => 'ÑŃŅŇ', 'n' => 'ñńņň', 'O' => 'ÒÓÔÕÖŌŎŐ', 'o' => 'òóôõöōŏő',
                'R' => 'ŔŖŘ', 'r' => 'ŕŗř', 'S' => 'ŚŜŞŠ', 's' => 'śŝşš', 'T' => 'ŢŤ', 't' => 'ţť',
                'U' => 'ÙÚÛÜŨŪŬŮŰŲ', 'u' => 'ùúûüũūŭůűų', 'Y' => 'ÝŸ', 'y' => 'ýÿ', 'Z' => 'ŹŻŽ', 'z' => 'źżž',
            ];
            foreach ($acc as $to => $chars) {
                foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $ch) $map[$ch] = $to;
            }
        }
        $s = strtr($s, $map);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace("/(\\p{L})'(\\p{L})/u", '$1$2', $s);          // dead man's -> dead mans
        $s = preg_replace('/(\d)\s*\/\s*(\d)/', '$1 $2', (string)$s);   // 2 1/2 -> 2 1 2
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$s);
        $s = trim(preg_replace('/\s+/', ' ', (string)$s));
        if ($s === '') return '';
        static $roman = ['ii' => '2', 'iii' => '3', 'iv' => '4', 'vi' => '6', 'vii' => '7', 'viii' => '8', 'ix' => '9', 'versus' => 'vs'];
        $tok = explode(' ', $s);
        foreach ($tok as &$t) { if (isset($roman[$t])) $t = $roman[$t]; }
        unset($t);
        return implode(' ', $tok);
    }

    /** Queue acquisition searches for missing parts (RSS search when feeds support {query}). */
    public static function fetchMissing(string $id): array {
        $st = self::status($id);
        $queued = [];
        foreach ($st['missing'] as $m) {
            $title = $m['title'];
            $year = $m['year'] ? ' ' . $m['year'] : '';
            $query = trim($title . $year);
            $settings = Store::settings();
            $acq = $settings['acquisition'] ?? [];
            $hasSearch = false;
            foreach (FeedCatalogue::active($acq['feeds'] ?? []) as $feed) {
                $url = is_array($feed) ? ($feed['url'] ?? '') : (string)$feed;
                if ($url && (strpos($url, '{query}') !== false || strpos($url, '{q}') !== false
                    || (is_array($feed) && ($feed['type'] ?? '') === 'search'))) {
                    $hasSearch = true;
                    break;
                }
            }
            if ($hasSearch && !empty($acq['enabled'])) {
                $search = Acquisition::searchQuery($query);
                $picked = null;
                foreach ($search['items'] ?? [] as $item) {
                    if (!empty($item['magnet']) || !empty($item['enclosure']) || !empty($item['link'])) {
                        $picked = $item;
                        break;
                    }
                }
                if ($picked) {
                    $res = Acquisition::enqueue([
                        'title' => $picked['title'] ?: ('SEARCH: ' . $query),
                        'magnet' => $picked['magnet'] ?? '',
                        'link' => $picked['link'] ?? '',
                        'enclosure' => $picked['enclosure'] ?? '',
                        'query' => $query,
                        'kind' => 'collection_hit',
                    ]);
                    $queued[] = $res['entry'];
                    continue;
                }
            }
            $res = Acquisition::enqueue([
                'title' => 'SEARCH: ' . $query,
                'magnet' => '',
                'link' => '',
                'enclosure' => '',
                'query' => $query,
                'kind' => 'collection_search',
            ]);
            $queued[] = $res['entry'];
        }
        return ['ok' => true, 'queued' => $queued, 'count' => count($queued)];
    }

    /**
     * Search free public sources for film series / TV collections.
     * Wikipedia (film series / franchise) + TVMaze shows. No paid API keys.
     */
    public static function searchOnline(string $query): array {
        $q = trim($query);
        if ($q === '') return ['ok' => false, 'error' => 'query required'];
        $results = [];
        // Normalize: "Aliens collection" → also try "Aliens"
        $base = trim(preg_replace('/\b(collection|franchise|film series|movies?|saga)\b/i', '', $q));
        $base = trim(preg_replace('/\s+/', ' ', $base));
        if ($base === '') $base = $q;

        // Wikipedia: film series / franchise pages
        foreach (array_unique([
            $base . ' film series',
            $base . ' (franchise)',
            $base . ' franchise',
            $base,
            $q,
        ]) as $term) {
            $url = 'https://en.wikipedia.org/w/api.php?action=opensearch&limit=6&namespace=0&format=json&search='
                . rawurlencode($term);
            $json = self::httpGet($url);
            $arr = $json ? json_decode($json, true) : null;
            $titles = is_array($arr) && isset($arr[1]) ? $arr[1] : [];
            $descs = is_array($arr) && isset($arr[2]) ? $arr[2] : [];
            $links = is_array($arr) && isset($arr[3]) ? $arr[3] : [];
            foreach ($titles as $i => $title) {
                $key = strtolower($title);
                $dup = false;
                foreach ($results as $r) {
                    if (strtolower($r['title'] ?? '') === $key) { $dup = true; break; }
                }
                if ($dup) continue;
                $results[] = [
                    'kind' => 'wikipedia',
                    'title' => $title,
                    'description' => $descs[$i] ?? '',
                    'url' => $links[$i] ?? null,
                    'source' => 'wikipedia',
                ];
            }
            if (count($results) >= 8) break;
        }

        // TVMaze shows (for TV collections)
        $tvUrl = 'https://api.tvmaze.com/search/shows?q=' . rawurlencode($q);
        $tvJson = self::httpGet($tvUrl);
        $tvArr = $tvJson ? json_decode($tvJson, true) : null;
        if (is_array($tvArr)) {
            foreach (array_slice($tvArr, 0, 6) as $row) {
                $show = $row['show'] ?? null;
                if (!is_array($show)) continue;
                $results[] = [
                    'kind' => 'tvmaze',
                    'title' => $show['name'] ?? '',
                    'description' => self::stripHtml($show['summary'] ?? ''),
                    'url' => $show['url'] ?? null,
                    'source' => 'tvmaze',
                    'tvmaze_id' => $show['id'] ?? null,
                    'year' => self::yearFromDate($show['premiered'] ?? null),
                    'genres' => $show['genres'] ?? [],
                ];
            }
        }

        return ['ok' => true, 'query' => $q, 'results' => $results];
    }

    /**
     * Import a collection from a Wikipedia page or TVMaze show id.
     * Wikipedia: parse wikitext for film titles with years.
     * TVMaze: single-show "collection" of seasons is less useful — instead treat as
     * one-part TV collection OR expand related if we only have the show itself.
     */
    public static function importOnline(array $body): array {
        $kind = trim((string)($body['kind'] ?? $body['source'] ?? 'wikipedia'));
        $title = trim((string)($body['title'] ?? ''));
        $page = trim((string)($body['page'] ?? $title));
        $tvId = isset($body['tvmaze_id']) ? (int)$body['tvmaze_id'] : 0;

        if ($kind === 'tvmaze' || $tvId > 0) {
            return self::importTvMaze($tvId, $title, $body);
        }
        return self::importWikipedia($page !== '' ? $page : $title, $body);
    }

    private static function importTvMaze(int $tvId, string $title, array $body): array {
        if ($tvId <= 0 && $title !== '') {
            $json = self::httpGet('https://api.tvmaze.com/singlesearch/shows?q=' . rawurlencode($title));
            $show = $json ? json_decode($json, true) : null;
            if (is_array($show)) $tvId = (int)($show['id'] ?? 0);
        }
        if ($tvId <= 0) return ['ok' => false, 'error' => 'TVMaze show not found'];
        $json = self::httpGet('https://api.tvmaze.com/shows/' . $tvId);
        $show = $json ? json_decode($json, true) : null;
        if (!is_array($show)) return ['ok' => false, 'error' => 'TVMaze fetch failed'];
        $name = $show['name'] ?? ($title ?: 'TV Collection');
        // For TV shows, parts = the show itself (availability via catalogue show match)
        $parts = [[
            'title' => $name,
            'year' => self::yearFromDate($show['premiered'] ?? null),
            'tvmaze_id' => $tvId,
        ]];
        // Also try to pull "Also known as" / web channel — keep single part
        $col = self::create([
            'name' => $name . ' (TV)',
            'type' => 'tv',
            'description' => self::stripHtml($show['summary'] ?? ''),
            'parts' => $parts,
            'source' => 'tvmaze',
            'source_url' => $show['url'] ?? null,
            'source_query' => $body['query'] ?? $title,
        ]);
        $st = self::status($col['collection']['id']);
        return ['ok' => true, 'collection' => $col['collection'], 'status' => $st];
    }

    private static function importWikipedia(string $page, array $body): array {
        if ($page === '') return ['ok' => false, 'error' => 'page title required'];
        $url = 'https://en.wikipedia.org/w/api.php?action=parse&page=' . rawurlencode($page)
            . '&prop=wikitext&format=json&redirects=1';
        $json = self::httpGet($url);
        $data = $json ? json_decode($json, true) : null;
        if (!is_array($data) || empty($data['parse']['wikitext']['*'])) {
            // Try with (franchise) / film series suffix
            foreach ([$page . ' (franchise)', $page . ' film series', $page . ' (film series)'] as $alt) {
                $url = 'https://en.wikipedia.org/w/api.php?action=parse&page=' . rawurlencode($alt)
                    . '&prop=wikitext&format=json&redirects=1';
                $json = self::httpGet($url);
                $data = $json ? json_decode($json, true) : null;
                if (is_array($data) && !empty($data['parse']['wikitext']['*'])) {
                    $page = $data['parse']['title'] ?? $alt;
                    break;
                }
            }
        }
        if (!is_array($data) || empty($data['parse']['wikitext']['*'])) {
            return ['ok' => false, 'error' => 'Wikipedia page not found'];
        }
        $wikitext = (string)$data['parse']['wikitext']['*'];
        $resolvedTitle = $data['parse']['title'] ?? $page;
        $parts = self::extractFilmParts($wikitext);
        if (!$parts) {
            // Fallback: REST summary related — still create empty-ish with description
            $sumUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $resolvedTitle));
            $sumJson = self::httpGet($sumUrl);
            $sum = $sumJson ? json_decode($sumJson, true) : null;
            $desc = is_array($sum) ? trim((string)($sum['extract'] ?? '')) : '';
            return ['ok' => false, 'error' => 'No film list parsed from page', 'page' => $resolvedTitle, 'hint' => $desc];
        }
        // Dedupe by title+year
        $seen = [];
        $uniq = [];
        foreach ($parts as $p) {
            $k = strtolower($p['title']) . '|' . ($p['year'] ?? '');
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $uniq[] = $p;
        }
        $col = self::create([
            'name' => $body['name'] ?? $resolvedTitle,
            'type' => 'movies',
            'description' => $body['description'] ?? ('Imported from Wikipedia: ' . $resolvedTitle),
            'parts' => $uniq,
            'source' => 'wikipedia',
            'source_url' => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $resolvedTitle)),
            'source_query' => $body['query'] ?? $page,
        ]);
        $st = self::status($col['collection']['id']);
        return [
            'ok' => true,
            'collection' => $col['collection'],
            'status' => $st,
            'parsed_count' => count($uniq),
            'page' => $resolvedTitle,
        ];
    }

    /** Extract film titles + years from Wikipedia franchise / film-series wikitext. */
    private static function extractFilmParts(string $wikitext): array {
        $parts = [];

        // 1) Prefer the | films = Plainlist (or similar) inside Infobox media franchise.
        //    This is the cleanest source on franchise pages and avoids games/rides/TV.
        if (preg_match('/\|\s*films\s*=\s*(\{\{Plainlist\|.*?\}\}|.*?)(?=\n\||\n\}\})/is', $wikitext, $fm)) {
            $filmsBlock = $fm[1];
            if (preg_match_all("/'{2,3}\s*\[\[([^\]|]+?)(?:\|([^\]]+))?\]\]\s*'{2,3}\s*\((\d{4})\)/", $filmsBlock, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $link = $hit[1];
                    $label = $hit[2] ?? '';
                    $year = (int)$hit[3];
                    $title = $label !== '' ? $label : $link;
                    $title = trim(preg_replace('/\s*\(\d{4}.*$/', '', $title));
                    $title = str_replace('_', ' ', $title);
                    if (self::isLikelyFilmTitle($title)) {
                        $parts[] = ['title' => $title, 'year' => $year];
                    }
                }
            }
            // Also catch bullet form inside Plainlist without extra italics
            if (preg_match_all("/\*+\s*'{0,3}\[\[([^\]|]+)(?:\|([^\]]+))?\]\]'{0,3}\s*\((\d{4})\)/", $filmsBlock, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $link = $hit[1];
                    $label = $hit[2] ?? '';
                    $year = (int)$hit[3];
                    $title = $label !== '' ? $label : $link;
                    $title = trim(preg_replace('/\s*\(\d{4}.*$/', '', $title));
                    $title = str_replace('_', ' ', $title);
                    if (self::isLikelyFilmTitle($title)) {
                        $parts[] = ['title' => $title, 'year' => $year];
                    }
                }
            }
        }

        // 2) Fallback: broader patterns used on many franchise / film-series pages
        if (!$parts) {
            $patterns = [
                "/'{2,3}\s*\[\[([^\]|]+?)(?:\|[^\]]+)?\]\]\s*'{2,3}\s*\((\d{4})\)/",
                "/\[\[([^\]|]+?\(\d{4}\s*film\))\|([^\]]+)\]\]/",
                "/\*+\s*'{0,3}\[\[([^\]|]+)(?:\|([^\]]+))?\]\]'{0,3}\s*\((\d{4})\)/",
                "/\{\{nowrap\|'{2,3}\[\[([^\]|]+)(?:\|([^\]]+))?\]\]'{2,3}\}\}\s*\((\d{4})\)/",
            ];
            foreach ($patterns as $pi => $re) {
                if (!preg_match_all($re, $wikitext, $m, PREG_SET_ORDER)) continue;
                foreach ($m as $hit) {
                    if ($pi === 1) {
                        // [[Alien (1979 film)|Alien]]
                        $link = $hit[1];
                        $label = $hit[2] ?? '';
                        $year = null;
                        if (preg_match('/\((\d{4})/', $link, $ym)) $year = (int)$ym[1];
                        $title = $label !== '' ? $label : preg_replace('/\s*\(\d{4}.*$/', '', $link);
                    } elseif ($pi === 0) {
                        $title = $hit[1];
                        $year = (int)$hit[2];
                    } else {
                        $link = $hit[1];
                        $label = $hit[2] ?? '';
                        $year = isset($hit[3]) ? (int)$hit[3] : null;
                        $title = $label !== '' ? $label : $link;
                    }
                    $title = trim(preg_replace('/\s*\(\d{4}.*$/', '', $title));
                    $title = str_replace('_', ' ', $title);
                    if (!self::isLikelyFilmTitle($title)) continue;
                    $parts[] = ['title' => $title, 'year' => $year];
                }
            }
            // Also catch "Title (YYYY film)" link targets directly
            if (preg_match_all('/\[\[([^\]|]+?\((\d{4})\s*film\))\|([^\]]+)\]\]/', $wikitext, $m2, PREG_SET_ORDER)) {
                foreach ($m2 as $hit) {
                    $title = trim($hit[3]);
                    if (self::isLikelyFilmTitle($title)) {
                        $parts[] = ['title' => $title, 'year' => (int)$hit[2]];
                    }
                }
            }
        }

        return $parts;
    }

    /** Reject non-film media that commonly appears on franchise Wikipedia pages. */
    private static function isLikelyFilmTitle(string $title): bool {
        $title = trim($title);
        if ($title === '' || strlen($title) < 2) return false;
        if (preg_match('/^(List of|Category:|Template:|File:|Image:)/i', $title)) return false;
        // Explicit non-film media
        if (preg_match('/\b(video game|pinball|ride|attraction|theme park|novel|comic|comics|soundtrack|score|television|TV series|miniseries|web series)\b/i', $title)) {
            return false;
        }
        // Pure series/franchise headers without a film indicator
        if (preg_match('/\b(film series|franchise)\b/i', $title) && !preg_match('/\bfilm\b/i', $title)) {
            return false;
        }
        return true;
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
                    'timeout' => 25,
                    'header' => "User-Agent: Vaultcast/" . STREAM_VERSION . " (+collections)\r\nAccept: application/json\r\n",
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
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . ' (+collections)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) return null;
        return $raw;
    }
}
