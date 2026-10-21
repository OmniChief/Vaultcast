<?php
declare(strict_types=1);

/**
 * Smart collections: saved rules that pick titles from the library and stay up to date on their own.
 * Personal collections belong to one person; shared ones (owner '') are made by admins for everyone.
 * Rules run as one SQL query over the browse index, media facts, age ratings, watch progress and
 * vaultcast_smart_facts (genres, cast/director, community rating and resolution gathered by the
 * 'smart-index' maintenance task). Results are cached per collection and invalidated by revision stamps.
 */
final class SmartCollections
{
    const FIELDS = [
        'kind' => 'Films or shows', 'genre' => 'Genre', 'year' => 'Year', 'decade' => 'Decade', 'rating' => 'Age rating',
        'runtime' => 'Runtime (minutes)', 'added' => 'Added in the last (days)', 'watch' => 'Watch status', 'library' => 'Library',
        'person' => 'Cast or director', 'community' => 'Community rating (0–10)', 'resolution' => 'Resolution', 'codec' => 'Video codec',
    ];
    const SORTS = ['added_desc' => 'Newest added', 'title' => 'Title A–Z', 'year_desc' => 'Newest release', 'year_asc' => 'Oldest release', 'community_desc' => 'Highest community rating', 'runtime_asc' => 'Shortest first', 'random' => 'Shuffle'];
    const MAX_LIMIT = 200;
    const REVISION = 'smart-collections-rev';
    const TABLE_SQL = "CREATE TABLE IF NOT EXISTS vaultcast_smart_collections (
 id CHAR(16) CHARACTER SET ascii NOT NULL PRIMARY KEY, owner_id VARCHAR(64) NOT NULL DEFAULT '',
 name VARCHAR(120) NOT NULL, description VARCHAR(400) NOT NULL DEFAULT '', rules TEXT NOT NULL,
 pin_home TINYINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0,
 INDEX smart_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    private static function pdo(): PDO { return \Vaultcast\Database::connection(); }
    private static function q(string $sql, array $args = []): PDOStatement { $s = self::pdo()->prepare($sql); $s->execute($args); return $s; }
    public static function bump(): void { try { \Vaultcast\Repository::putDocument(self::REVISION, ['at' => time()]); } catch (Throwable $e) {} }

    // ------------------------------------------------------------------ rules
    /** Validate and normalise a rule set from the editor. Never throws for unknown bits: they are dropped. */
    public static function cleanRules($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = ['match' => ($raw['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => [], 'sort' => isset(self::SORTS[$raw['sort'] ?? '']) ? (string)$raw['sort'] : 'added_desc',
            'limit' => max(1, min(self::MAX_LIMIT, (int)($raw['limit'] ?? 50)))];
        foreach (array_slice((array)($raw['rules'] ?? []), 0, 20) as $r) {
            if (!is_array($r) || !isset(self::FIELDS[$r['field'] ?? ''])) continue;
            $op = in_array($r['op'] ?? 'is', ['is', 'is_not', 'gte', 'lte', 'between', 'contains'], true) ? (string)$r['op'] : 'is';
            $v = $r['value'] ?? '';
            $v = is_array($v) ? array_slice(array_map(static function ($x) { return mb_substr(trim((string)$x), 0, 80); }, $v), 0, 2) : mb_substr(trim((string)$v), 0, 80);
            if ($v === '' || $v === []) continue;
            $out['rules'][] = ['field' => (string)$r['field'], 'op' => $op, 'value' => $v];
        }
        return $out;
    }

    private static function num($v): float { return is_numeric($v) ? (float)$v : 0.0; }

    /** One rule as SQL. Returns [sql, params] or null when it cannot apply. */
    private static function ruleSql(array $r, string $userId): ?array
    {
        $f = $r['field']; $op = $r['op']; $v = $r['value']; $not = $op === 'is_not';
        $range = function (string $col, float $scale = 1.0) use ($op, $v): ?array {
            if ($op === 'between' && is_array($v) && count($v) === 2) return ["($col BETWEEN ? AND ?)", [self::num($v[0]) * $scale, self::num($v[1]) * $scale]];
            $x = self::num(is_array($v) ? ($v[0] ?? 0) : $v) * $scale;
            if ($op === 'gte') return ["$col >= ?", [$x]];
            if ($op === 'lte') return ["$col <= ?", [$x]];
            if ($op === 'is_not') return ["$col <> ?", [$x]];
            return ["$col = ?", [$x]];
        };
        $s = is_array($v) ? (string)($v[0] ?? '') : (string)$v;
        switch ($f) {
            case 'kind': return [$s === 'shows' ? "b.show_key<>''" : "b.show_key=''", []];
            case 'genre': return [($not ? 'NOT ' : '') . "COALESCE(s.genres,'') LIKE ?", ['%|' . self::tag($s) . '|%']];
            case 'person': return [($not ? 'NOT ' : '') . "COALESCE(s.people,'') LIKE ?", ['%' . self::tag($s) . '%']];
            case 'year': return $range('COALESCE(b.year,0)');
            case 'decade': $d = (int)floor(self::num($s) / 10) * 10; return [($not ? 'NOT ' : '') . '(COALESCE(b.year,0) BETWEEN ? AND ?)', [$d, $d + 9]];
            case 'rating': return $range('COALESCE(r.bucket,0)');
            case 'runtime': return $range('COALESCE(f.duration,0)', 60.0);
            case 'added': return ['i.added_at >= ?', [time() - (int)max(1, self::num($s)) * 86400]];
            case 'community': return $range('COALESCE(s.community,0)');
            case 'resolution': return $range('COALESCE(s.height,0)');
            case 'codec': return [($not ? 'NOT ' : '') . "LOWER(COALESCE(f.video_codec,'')) LIKE ?", [strtolower($s) === 'h265' ? 'hevc%' : strtolower($s) . '%']];
            case 'library': return [($not ? 'b.section_id<>?' : 'b.section_id=?'), [$s]];
            case 'watch':
                $done = "(p.item_id IS NOT NULL AND (COALESCE(JSON_EXTRACT(p.payload,'$.watched'),0)=1 OR COALESCE(JSON_EXTRACT(p.payload,'$.viewings'),0)>0 OR (COALESCE(JSON_EXTRACT(p.payload,'$.duration'),0)>0 AND COALESCE(JSON_EXTRACT(p.payload,'$.position'),0)/NULLIF(JSON_EXTRACT(p.payload,'$.duration'),0)>0.92)))";
                if ($s === 'watched') return [$done, []];
                if ($s === 'in_progress') return ["(p.item_id IS NOT NULL AND COALESCE(JSON_EXTRACT(p.payload,'$.position'),0)>30 AND NOT $done)", []];
                return ["(p.item_id IS NULL OR (COALESCE(JSON_EXTRACT(p.payload,'$.position'),0)<=30 AND NOT $done))", []];
        }
        return null;
    }

    public static function tag(string $s): string { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace('|', ' ', $s))), 'UTF-8'); }

    private static function enabledSections(): array
    {
        $out = [];
        foreach ((array)(Store::settings()['sections'] ?? []) as $sec) if (is_array($sec) && !empty($sec['enabled']) && isset($sec['id'])) $out[] = (string)$sec['id'];
        return $out;
    }

    /** Ordered title keys ('item:<id>' / 'show:<key>') matching the rules, before the viewer filter. */
    private static function units(array $rules, string $userId, int $cap = 3000): array
    {
        $sections = self::enabledSections();
        if (!$sections) return [];
        $where = []; $args = [$userId];
        foreach ($rules['rules'] as $r) { $sql = self::ruleSql($r, $userId); if ($sql) { $where[] = '(' . $sql[0] . ')'; foreach ($sql[1] as $a) $args[] = $a; } }
        $base = "b.media_kind='video' AND b.section_id IN (" . implode(',', array_fill(0, count($sections), '?')) . ')';
        $cond = $base . ($where ? ' AND (' . implode($rules['match'] === 'any' ? ' OR ' : ' AND ', $where) . ')' : '');
        $args = array_merge([$userId], $sections, array_slice($args, 1));
        $order = ['added_desc' => 'added DESC, unit', 'title' => 'title, unit', 'year_desc' => 'year DESC, title', 'year_asc' => 'year IS NULL, year, title', 'community_desc' => 'community DESC, title', 'runtime_asc' => 'runtime, title', 'random' => 'RAND()'][$rules['sort']] ?? 'added DESC';
        $sql = "SELECT CASE WHEN b.show_key<>'' THEN CONCAT('show:',b.show_key) ELSE CONCAT('item:',b.item_id) END AS unit,
                MAX(i.added_at) AS added, MIN(CASE WHEN b.show_key<>'' THEN b.show_name ELSE b.title END) AS title, MAX(b.year) AS year,
                MAX(COALESCE(s.community,0)) AS community, MIN(COALESCE(f.duration,0)) AS runtime
            FROM vaultcast_browse_items b
            JOIN vaultcast_items i ON i.id=b.item_id
            LEFT JOIN vaultcast_media_facts f ON f.item_id=b.item_id
            LEFT JOIN vaultcast_smart_facts s ON s.item_id=b.item_id
            LEFT JOIN vaultcast_content_ratings r ON r.target=(CASE WHEN b.show_key<>'' THEN CONCAT('show:',b.show_key) ELSE CONCAT('item:',b.item_id) END)
            LEFT JOIN vaultcast_progress p ON p.item_id=b.item_id AND p.user_id=?
            WHERE $cond GROUP BY unit ORDER BY $order LIMIT " . (int)$cap;
        // The progress join parameter comes first in the statement, then the WHERE parameters.
        return array_column(self::q($sql, $args)->fetchAll(PDO::FETCH_ASSOC), 'unit');
    }

    private static function viewerAllows(?array $user, string $unit): bool
    {
        if (!$user || !class_exists('Parental') || !Parental::restricted($user)) return true;
        [$kind, $id] = explode(':', $unit, 2);
        return Parental::allows($user, $kind === 'show' ? ['id' => $id, 'kind' => 'show', 'media_kind' => 'show', 'show_key' => $id, 'title' => ''] : (Catalogue::item($id) ?: ['id' => $id, 'media_kind' => 'video']));
    }

    /** Cards for title keys, in order. */
    private static function cards(array $units): array
    {
        $items = []; $shows = [];
        foreach ($units as $u) { [$k, $id] = explode(':', $u, 2); if ($k === 'show') $shows[] = $id; else $items[] = $id; }
        $byKey = [];
        foreach (array_chunk($items, 300) as $chunk) {
            $rows = \Vaultcast\Repository::rows('SELECT payload FROM vaultcast_items WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            foreach (Catalogue::publicItems($rows) as $card) $byKey['item:' . $card['id']] = $card;
        }
        foreach (array_chunk($shows, 300) as $chunk) {
            foreach (self::q('SELECT show_key,payload FROM vaultcast_show_summaries WHERE show_key IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $p = json_decode((string)$r['payload'], true);
                if (is_array($p) && isset($p['title'])) { $p['show_key'] = $p['show_key'] ?? $r['show_key']; try { $byKey['show:' . $r['show_key']] = Catalogue::publicShow($p); } catch (Throwable $e) {} }
            }
        }
        $out = []; foreach ($units as $u) if (isset($byKey[$u])) $out[] = $byKey[$u];
        return $out;
    }

    private static function usesWatch(array $rules): bool { foreach ($rules['rules'] as $r) if ($r['field'] === 'watch') return true; return false; }

    /** Evaluate (with caching) for one viewer: ['count' => n, 'cards' => [...]]. */
    public static function evaluate(array $rules, ?array $user, int $take, string $cacheId = ''): array
    {
        $uid = (string)($user['id'] ?? '');
        $restricted = $user && class_exists('Parental') && Parental::restricted($user);
        $personal = self::usesWatch($rules) || $restricted;
        $rev = '';
        $key = '';
        if ($cacheId !== '' && $rules['sort'] !== 'random') {
            $rev = \Vaultcast\Repository::revision('catalogue') . '|' . \Vaultcast\Repository::revision(self::REVISION) . '|' . ($personal ? \Vaultcast\Repository::revision(\Vaultcast\Repository::progressRevisionName($uid)) : '');
            $key = 'smart:' . $cacheId . ':' . md5(json_encode($rules)) . ':' . ($personal ? hash('sha256', $uid) : 'all') . ':' . $take;
            $hit = \Vaultcast\Repository::cacheGet($key);
            if (is_array($hit) && ($hit['rev'] ?? '') === $rev) return $hit['data'];
        }
        try { $units = self::units($rules, $uid); } catch (Throwable $e) { error_log('Vaultcast smart collection: ' . $e->getMessage()); $units = []; }
        if ($restricted) $units = array_values(array_filter($units, static function ($u) use ($user) { return self::viewerAllows($user, $u); }));
        $count = min(count($units), $rules['limit']);
        $data = ['count' => $count, 'cards' => self::cards(array_slice($units, 0, min($take, $rules['limit'])))];
        if ($key !== '') \Vaultcast\Repository::cachePut($key, ['rev' => $rev, 'data' => $data], 900);
        return $data;
    }

    // ------------------------------------------------------------------ storage
    private static function decode(array $r, ?array $user): array
    {
        $own = (string)$r['owner_id'];
        $admin = ($user['role'] ?? '') === 'admin';
        return ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'description' => (string)$r['description'], 'rules' => self::cleanRules(json_decode((string)$r['rules'], true)),
            'pin_home' => (bool)$r['pin_home'], 'shared' => $own === '', 'mine' => $own !== '' && $own === (string)($user['id'] ?? ''), 'can_edit' => $own === '' ? $admin : $own === (string)($user['id'] ?? ''),
            'smart' => true, 'updated_at' => (int)$r['updated_at']];
    }

    public static function visible(array $user): array
    {
        $out = [];
        try { foreach (self::q("SELECT * FROM vaultcast_smart_collections WHERE owner_id='' OR owner_id=? ORDER BY name", [(string)$user['id']]) as $r) $out[] = self::decode($r, $user); }
        catch (Throwable $e) { /* not migrated yet */ }
        return $out;
    }

    public static function find(string $id, array $user): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
        foreach (self::visible($user) as $c) if ($c['id'] === $id) return $c;
        return null;
    }

    /** List for the Collections page: each with a live count and up to three cover cards. */
    public static function listFor(array $user): array
    {
        if (!AreaAccess::allowed($user, 'library')) return ['ok' => true, 'collections' => [], 'fields' => self::FIELDS, 'sorts' => self::SORTS];
        $out = [];
        foreach (self::visible($user) as $c) { $e = self::evaluate($c['rules'], $user, 3, $c['id']); $c['count'] = $e['count']; $c['cover_items'] = $e['cards']; $out[] = $c; }
        return ['ok' => true, 'collections' => $out, 'fields' => self::FIELDS, 'sorts' => self::SORTS, 'choices' => self::choices()];
    }

    public static function items(string $id, array $user): array
    {
        $c = self::find($id, $user);
        if (!$c || !AreaAccess::allowed($user, 'library')) json_error('This smart collection was not found.', 404);
        $e = self::evaluate($c['rules'], $user, $c['rules']['limit'], $c['id']);
        return ['ok' => true, 'collection' => $c, 'count' => $e['count'], 'items' => $e['cards']];
    }

    public static function preview(array $user, $rules): array
    {
        if (!AreaAccess::allowed($user, 'library')) return ['ok' => true, 'count' => 0, 'items' => []];
        $e = self::evaluate(self::cleanRules($rules), $user, 12);
        return ['ok' => true, 'count' => $e['count'], 'items' => $e['cards']];
    }

    public static function save(array $user, array $b): array
    {
        if (empty($user['id']) || ($user['role'] ?? '') === 'guest') throw new DomainException('Sign in to save smart collections.');
        $admin = ($user['role'] ?? '') === 'admin';
        $name = trim(preg_replace('/\s+/u', ' ', (string)($b['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 80) throw new InvalidArgumentException('Give the collection a name (up to 80 characters).');
        $rules = self::cleanRules($b['rules'] ?? []);
        $id = (string)($b['id'] ?? '');
        $owner = !empty($b['shared']) && $admin ? '' : (string)$user['id'];
        if ($id !== '') {
            $c = self::find($id, $user);
            if (!$c || !$c['can_edit']) throw new DomainException('You can only change your own smart collections.');
            if (!$admin) $owner = (string)$user['id'];
            self::q('UPDATE vaultcast_smart_collections SET owner_id=?,name=?,description=?,rules=?,pin_home=?,updated_at=? WHERE id=?', [$owner, $name, mb_substr(trim((string)($b['description'] ?? '')), 0, 400), json_encode($rules), !empty($b['pin_home']) ? 1 : 0, time(), $id]);
        } else {
            $count = (int)self::q('SELECT COUNT(*) FROM vaultcast_smart_collections WHERE owner_id=?', [$owner])->fetchColumn();
            if ($count >= 60) throw new DomainException('That is a lot of smart collections. Remove one before adding another.');
            $id = bin2hex(random_bytes(8));
            self::q('INSERT INTO vaultcast_smart_collections (id,owner_id,name,description,rules,pin_home,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)', [$id, $owner, $name, mb_substr(trim((string)($b['description'] ?? '')), 0, 400), json_encode($rules), !empty($b['pin_home']) ? 1 : 0, time(), time()]);
        }
        self::bump();
        return ['ok' => true, 'collection' => self::find($id, $user)];
    }

    public static function delete(string $id, array $user): array
    {
        $c = self::find($id, $user);
        if (!$c || !$c['can_edit']) throw new DomainException('You can only remove your own smart collections.');
        self::q('DELETE FROM vaultcast_smart_collections WHERE id=?', [$id]);
        self::bump();
        return ['ok' => true];
    }

    public static function purgeUser(string $userId): void
    {
        if ($userId === '') return;
        try { self::q('DELETE FROM vaultcast_smart_collections WHERE owner_id=?', [$userId]); self::bump(); } catch (Throwable $e) {}
    }

    /** Pinned smart collections as Home rows (own pins first, then shared pins). */
    public static function homeRows(array $user): array
    {
        if (!AreaAccess::allowed($user, 'library')) return ['ok' => true, 'rows' => []];
        $rows = [];
        foreach (self::visible($user) as $c) {
            if (!$c['pin_home']) continue;
            $e = self::evaluate($c['rules'], $user, 24, $c['id']);
            if ($e['cards']) $rows[] = ['id' => $c['id'], 'name' => $c['name'], 'count' => $e['count'], 'items' => $e['cards'], 'shared' => $c['shared']];
            if (count($rows) >= 6) break;
        }
        usort($rows, static function ($a, $b) { return (int)$a['shared'] <=> (int)$b['shared']; });
        return ['ok' => true, 'rows' => $rows];
    }

    /** Board "Recently added"-style source: same row shape as Misc::recent. */
    public static function widget(array $user, string $id, int $n): array
    {
        $c = self::find($id, $user);
        if (!$c || !AreaAccess::allowed($user, 'library')) return ['ok' => true, 'items' => [], 'missing' => true];
        $e = self::evaluate($c['rules'], $user, max(4, min(30, $n)), $c['id']);
        $out = [];
        foreach ($e['cards'] as $card) {
            $isShow = ($card['kind'] ?? '') === 'show';
            $out[] = ['id' => $card['id'], 'title' => (string)($isShow ? $card['title'] : ($card['show'] ?: $card['title'])), 'subtitle' => $isShow ? '' : (string)($card['year'] ?? ''),
                'show_key' => $isShow ? $card['show_key'] : ($card['show_key'] ?? null), 'type' => $card['type'] ?? '',
                'poster' => $isShow ? '/api/shows/' . rawurlencode((string)$card['show_key']) . '/poster?fetch=0&variant=medium' : '/api/items/' . rawurlencode((string)$card['id']) . '/poster?fetch=0&variant=medium'];
        }
        return ['ok' => true, 'items' => $out, 'name' => $c['name']];
    }

    /** Values offered by the rule builder. */
    public static function choices(): array
    {
        $libraries = [];
        foreach ((array)(Store::settings()['sections'] ?? []) as $sec) if (is_array($sec) && !empty($sec['enabled']) && in_array($sec['type'] ?? '', ['movies', 'disney', 'tv', 'anime'], true)) $libraries[] = ['value' => (string)$sec['id'], 'label' => (string)($sec['name'] ?? $sec['label'] ?? $sec['id'])];
        $genres = [];
        try {
            foreach (self::q("SELECT genres FROM vaultcast_smart_facts WHERE genres<>'' LIMIT 20000") as $r) foreach (explode('|', (string)$r['genres']) as $g) if ($g !== '') $genres[$g] = ($genres[$g] ?? 0) + 1;
        } catch (Throwable $e) {}
        arsort($genres);
        $codecs = [];
        try { foreach (self::q("SELECT LOWER(video_codec) c, COUNT(*) n FROM vaultcast_media_facts WHERE video_codec<>'' GROUP BY LOWER(video_codec) ORDER BY n DESC LIMIT 12") as $r) $codecs[] = (string)$r['c']; } catch (Throwable $e) {}
        return ['libraries' => $libraries, 'genres' => array_slice(array_keys($genres), 0, 60), 'codecs' => $codecs ?: ['h264', 'hevc', 'av1', 'vp9', 'mpeg4']];
    }

    // ------------------------------------------------------------------ facts index
    /** Remember OMDb genres and people for a film when the synopsis/poster lookup already has them. */
    public static function captureOmdb(string $itemId, array $omdb): void
    {
        $genres = self::tagList(explode(',', (string)($omdb['Genre'] ?? '')));
        $people = self::tagList(array_merge(explode(',', (string)($omdb['Actors'] ?? '')), explode(',', (string)($omdb['Director'] ?? ''))));
        if ($genres === '' && $people === '') return;
        try {
            self::q("INSERT INTO vaultcast_smart_facts (item_id,genres,people,sig,updated_at) VALUES (?,?,?,'',?) ON DUPLICATE KEY UPDATE genres=IF(genres='',VALUES(genres),genres), people=IF(people='',VALUES(people),people)", [$itemId, $genres, $people, time()]);
        } catch (Throwable $e) {}
    }

    private static function tagList(array $list): string
    {
        $out = [];
        foreach ($list as $x) { $t = self::tag((string)$x); if ($t !== '' && $t !== 'n/a' && mb_strlen($t) <= 80) $out[$t] = true; }
        $s = $out ? '|' . implode('|', array_slice(array_keys($out), 0, 25)) . '|' : '';
        return mb_substr($s, 0, 1500);
    }

    private static function nfoFacts(string $file): array
    {
        if (!is_file($file) || filesize($file) > 2000000) return [[], []];
        $x = (string)@file_get_contents($file);
        preg_match_all('#<genre>\s*([^<]{1,60})\s*</genre>#i', $x, $g);
        preg_match_all('#<actor>.*?<name>\s*([^<]{1,80})\s*</name>#is', $x, $a);
        preg_match_all('#<director>\s*([^<]{1,80})\s*</director>#i', $x, $d);
        $genres = [];
        foreach ($g[1] as $one) foreach (preg_split('#\s*[/,]\s*#', html_entity_decode($one)) as $part) $genres[] = $part;
        return [$genres, array_merge(array_slice(array_map('html_entity_decode', $a[1]), 0, 20), array_map('html_entity_decode', $d[1]))];
    }

    private static function heightFrom(array $it): int
    {
        if (!empty($it['height']) && is_numeric($it['height'])) return (int)$it['height'];
        $name = strtolower(basename((string)($it['path'] ?? '')));
        if (preg_match('/(2160p|\b4k\b|\buhd\b)/', $name)) return 2160;
        if (preg_match('/1080[pi]/', $name)) return 1080;
        if (preg_match('/720p/', $name)) return 720;
        if (preg_match('/(576p|480p|\bdvd(rip)?\b|\bsd\b)/', $name)) return 480;
        return 0;
    }

    /** Fill vaultcast_smart_facts for new or changed video items. Bounded per pass. */
    public static function index(?callable $checkpoint = null, int $budget = 3000): array
    {
        $stale = time() - 7 * 86400;
        $rows = self::q("SELECT b.item_id,b.section_id,b.show_key,b.type,i.modified_at,i.payload FROM vaultcast_browse_items b JOIN vaultcast_items i ON i.id=b.item_id LEFT JOIN vaultcast_smart_facts s ON s.item_id=b.item_id
            WHERE b.media_kind='video' AND (s.item_id IS NULL OR s.sig<>CAST(i.modified_at AS CHAR) OR s.updated_at<?) LIMIT " . (int)$budget, [$stale])->fetchAll(PDO::FETCH_ASSOC);
        $shows = []; $reviews = []; $packs = []; $done = 0;
        $community = function (string $target) use (&$reviews): float {
            if (!array_key_exists($target, $reviews)) {
                $vals = [];
                foreach ((array)\Vaultcast\Repository::document('hub-reviews:' . $target, []) as $rv) if (is_array($rv) && is_numeric($rv['rating'] ?? null) && $rv['rating'] > 0) $vals[] = (float)$rv['rating'];
                $reviews[$target] = $vals ? round(array_sum($vals) / count($vals), 1) : 0.0;
            }
            return $reviews[$target];
        };
        $up = self::pdo()->prepare("INSERT INTO vaultcast_smart_facts (item_id,show_key,genres,people,community,height,sig,updated_at) VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE show_key=VALUES(show_key), genres=IF(VALUES(genres)='',genres,VALUES(genres)), people=IF(VALUES(people)='',people,VALUES(people)), community=VALUES(community), height=VALUES(height), sig=VALUES(sig), updated_at=VALUES(updated_at)");
        foreach ($rows as $r) {
            $it = json_decode((string)$r['payload'], true) ?: [];
            $key = (string)$r['show_key'];
            if ($key !== '') {
                if (!isset($shows[$key])) {
                    $guide = \Vaultcast\Repository::document('tvmaze-guide:' . $key, null);
                    $genres = is_array($guide) ? (array)($guide['genres'] ?? []) : []; $people = [];
                    $dir = Catalogue::showDirFromItem($it);
                    if ($dir) { [$ng, $np] = self::nfoFacts(rtrim($dir, '/\\') . '/tvshow.nfo'); $genres = array_merge($genres, $ng); $people = $np; }
                    $shows[$key] = [self::tagList($genres), self::tagList($people), $community('show:' . $key)];
                }
                [$g, $p, $c] = $shows[$key];
            } else {
                $path = (string)($it['path'] ?? '');
                [$ng, $np] = $path !== '' ? self::nfoFacts(preg_replace('/\.[^.\/\\\\]+$/', '', $path) . '.nfo') : [[], []];
                if (!$ng && $path !== '') { [$ng2, $np2] = self::nfoFacts(dirname($path) . '/movie.nfo'); $ng = $ng2; $np = $np ?: $np2; }
                if (!$ng) {
                    $sec = (string)$r['section_id'];
                    if (!isset($packs[$sec])) { try { $packs[$sec] = Catalogue::movieFacetPack($sec, Catalogue::sectionItems($sec)); } catch (Throwable $e) { $packs[$sec] = null; } }
                    try { $ng = Catalogue::movieGenresForItem($sec, $it, $packs[$sec]); } catch (Throwable $e) { $ng = []; }
                }
                $g = self::tagList($ng); $p = self::tagList($np); $c = $community('item:' . $r['item_id']);
            }
            $up->execute([(string)$r['item_id'], $key, $g, $p, $c, self::heightFrom($it), (string)$r['modified_at'], time()]);
            if ((++$done % 200) === 0 && $checkpoint) $checkpoint();
        }
        // Rows for items that left the library.
        self::q('DELETE s FROM vaultcast_smart_facts s LEFT JOIN vaultcast_browse_items b ON b.item_id=s.item_id WHERE b.item_id IS NULL');
        if ($done) self::bump();
        return ['indexed' => $done, 'deferred' => count($rows) >= $budget];
    }
}
