<?php
declare(strict_types=1);

/**
 * Site-wide webcam registry (Administration → Settings → Ambient). Every webcam a user saves for the Board
 * (Webcams widget list, legacy per-widget cams, Media widget webcam source) is recorded here once,
 * deduplicated by a normalised address (YouTube by video id). Admins name them and choose which ones
 * Ambient offers to everyone; nothing is offered until an admin enables it.
 */
final class Webcams {
    const KINDS = ['auto', 'image', 'mjpeg', 'video', 'youtube', 'embed'];
    const BACKFILL_DOC = 'webcams-registry-backfill';

    private static function q(string $sql, array $p = []): PDOStatement { return Arcade::query($sql, $p); }

    public static function youtubeId(string $url): string {
        $p = parse_url(trim($url));
        if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) return '';
        $host = preg_replace('/^(www|m|music)\./', '', strtolower((string)($p['host'] ?? ''))); $path = (string)($p['path'] ?? ''); $id = '';
        if ($host === 'youtu.be') { if (preg_match('#^/([A-Za-z0-9_-]{11})/?$#D', $path, $m)) $id = $m[1]; }
        elseif ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
            if ($path === '/watch' || $path === '/watch/') { parse_str((string)($p['query'] ?? ''), $qs); $id = is_string($qs['v'] ?? null) ? $qs['v'] : ''; }
            elseif (preg_match('#^/(?:live|embed|shorts|v)/([A-Za-z0-9_-]{11})/?$#D', $path, $m)) $id = $m[1];
        }
        return preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? $id : '';
    }
    public static function validUrl(string $url): bool { return (bool)preg_match('#^https?://[^\s"<>\\\\]+$#iD', $url) && strlen($url) <= 1000 && parse_url($url, PHP_URL_HOST); }

    /** Dedup key: YouTube video id, otherwise scheme-less lower-case host, default port and trailing slash removed, cache-busting query dropped, rest sorted. */
    public static function key(string $url): string {
        $url = trim($url);
        if (($yt = self::youtubeId($url)) !== '') return sha1('yt:' . $yt);
        $p = parse_url($url) ?: [];
        $scheme = strtolower((string)($p['scheme'] ?? 'http')); $host = strtolower((string)($p['host'] ?? ''));
        $port = isset($p['port']) && !(($scheme === 'http' && (int)$p['port'] === 80) || ($scheme === 'https' && (int)$p['port'] === 443)) ? ':' . (int)$p['port'] : '';
        $path = rtrim((string)($p['path'] ?? ''), '/');
        parse_str((string)($p['query'] ?? ''), $qs);
        foreach (['_t', '_', 't', 'ts', 'time', 'timestamp', 'rand', 'random', 'nocache', 'cachebust', 'cb'] as $k) unset($qs[$k]);
        ksort($qs);
        return sha1($host . $port . $path . ($qs ? '?' . http_build_query($qs) : ''));
    }
    private static function kindFor(string $url, string $kind): string {
        if (self::youtubeId($url) !== '') return 'youtube';
        return in_array($kind, self::KINDS, true) ? $kind : 'auto';
    }

    /** Record webcams seen in a user's saved settings. New ones start disabled for Ambient; existing ones only refresh last_seen (and fill a blank name). */
    public static function record(string $uid, array $cams, bool $enable = false): void {
        $now = time(); $seen = [];
        foreach (array_slice($cams, 0, 60) as $c) {
            if (!is_array($c)) continue;
            $url = trim((string)($c['url'] ?? '')); if (!self::validUrl($url)) continue;
            $key = self::key($url); if (isset($seen[$key])) continue; $seen[$key] = true;
            $name = mb_substr(trim((string)($c['name'] ?? '')), 0, 120);
            try {
                self::q('INSERT INTO vaultcast_webcams (id,url_key,name,url,kind,added_by,created_at,last_seen,enabled_ambient) VALUES (?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen), name=IF(name=\'\',VALUES(name),name)' . ($enable ? ', enabled_ambient=1' : ''),
                    [bin2hex(random_bytes(8)), $key, $name, mb_substr($url, 0, 1000), self::kindFor($url, (string)($c['kind'] ?? 'auto')), $uid, $now, $now, $enable ? 1 : 0]);
            } catch (Throwable $e) { error_log('Vaultcast webcam registry: ' . $e->getMessage()); }
        }
    }
    /** Webcams inside saved boards: legacy per-widget lists (settings.cams). */
    public static function fromBoards(array $boards): array {
        $out = [];
        foreach ($boards as $b) foreach ((array)($b['widgets'] ?? []) as $w) {
            if (!is_array($w)) continue;
            foreach ((array)($w['settings']['cams'] ?? []) as $c) if (is_array($c)) $out[] = $c;
        }
        return $out;
    }

    /** One-time import of every webcam already saved by users, plus the old admin-curated Ambient list (kept enabled). */
    public static function ensureBackfill(): void {
        if (\Vaultcast\Repository::document(self::BACKFILL_DOC, null) !== null) return;
        $pdo = \Vaultcast\Database::connection();
        if ((int)$pdo->query("SELECT GET_LOCK('vaultcast_webcams_backfill', 10)")->fetchColumn() !== 1) return;
        try {
            if (\Vaultcast\Repository::document(self::BACKFILL_DOC, null) !== null) return;
            $n = 0;
            foreach (self::q("SELECT name, payload FROM vaultcast_documents WHERE name LIKE 'misc-prefs:%' OR name LIKE 'misc-boards:%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $doc = json_decode((string)$row['payload'], true); if (!is_array($doc)) continue;
                $uid = (string)substr((string)$row['name'], strpos((string)$row['name'], ':') + 1);
                $cams = strpos((string)$row['name'], 'misc-prefs:') === 0 ? array_values((array)($doc['webcams'] ?? [])) : self::fromBoards((array)($doc['boards'] ?? []));
                if ($cams) { self::record($uid, $cams); $n += count($cams); }
            }
            $site = (array)((Store::settings()['misc'] ?? [])['webcams'] ?? []);
            if ($site) self::record('', $site, true);
            \Vaultcast\Repository::putDocument(self::BACKFILL_DOC, ['at' => time(), 'user_cams' => $n, 'site_cams' => count($site)]);
        } catch (Throwable $e) { error_log('Vaultcast webcam backfill: ' . $e->getMessage()); }
        finally { $pdo->query("SELECT RELEASE_LOCK('vaultcast_webcams_backfill')"); }
    }

    private static function row(array $r, array $people = []): array {
        $by = (string)$r['added_by'];
        return ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'url' => (string)$r['url'], 'kind' => (string)$r['kind'], 'added_by' => $by,
            'added_by_name' => $by === '' ? 'Administrator' : (string)($people[$by]['username'] ?? ($people[$by]['name'] ?? 'Former user')),
            'created_at' => (int)$r['created_at'], 'last_seen' => (int)$r['last_seen'], 'enabled_ambient' => (bool)$r['enabled_ambient']];
    }
    public static function adminList(): array {
        self::ensureBackfill();
        $rows = self::q('SELECT * FROM vaultcast_webcams ORDER BY enabled_ambient DESC, name, created_at LIMIT 2000')->fetchAll(PDO::FETCH_ASSOC);
        $people = Arcade::people(array_column($rows, 'added_by'));
        return ['ok' => true, 'webcams' => array_map(function ($r) use ($people) { return self::row($r, $people); }, $rows)];
    }
    public static function adminAdd(array $admin, array $body): array {
        $url = trim((string)($body['url'] ?? ''));
        if (!self::validUrl($url)) throw new DomainException('Enter a webcam address starting with http:// or https://.');
        $key = self::key($url);
        if (self::q('SELECT id FROM vaultcast_webcams WHERE url_key=?', [$key])->fetchColumn()) throw new DomainException('That webcam is already in the list.');
        $now = time();
        self::q('INSERT INTO vaultcast_webcams (id,url_key,name,url,kind,added_by,created_at,last_seen,enabled_ambient) VALUES (?,?,?,?,?,?,?,?,?)',
            [bin2hex(random_bytes(8)), $key, mb_substr(trim((string)($body['name'] ?? '')), 0, 120), mb_substr($url, 0, 1000), self::kindFor($url, (string)($body['kind'] ?? 'auto')), (string)$admin['id'], $now, $now, !empty($body['enabled_ambient']) ? 1 : 0]);
        return self::adminList();
    }
    public static function adminUpdate(string $id, array $body): array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) throw new DomainException('Webcam not found.');
        if (!self::q('SELECT id FROM vaultcast_webcams WHERE id=?', [$id])->fetchColumn()) throw new DomainException('Webcam not found.');
        if (array_key_exists('name', $body)) self::q('UPDATE vaultcast_webcams SET name=? WHERE id=?', [mb_substr(trim((string)$body['name']), 0, 120), $id]);
        if (array_key_exists('enabled_ambient', $body)) self::q('UPDATE vaultcast_webcams SET enabled_ambient=? WHERE id=?', [!empty($body['enabled_ambient']) ? 1 : 0, $id]);
        if (array_key_exists('kind', $body) && in_array($body['kind'], self::KINDS, true)) {
            $url = (string)self::q('SELECT url FROM vaultcast_webcams WHERE id=?', [$id])->fetchColumn();
            self::q('UPDATE vaultcast_webcams SET kind=? WHERE id=?', [self::kindFor($url, (string)$body['kind']), $id]);
        }
        $r = self::q('SELECT * FROM vaultcast_webcams WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        return ['ok' => true, 'webcam' => self::row($r, Arcade::people([(string)$r['added_by']]))];
    }
    public static function adminDelete(string $id): array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) throw new DomainException('Webcam not found.');
        self::q('DELETE FROM vaultcast_webcams WHERE id=?', [$id]);
        return self::adminList();
    }

    /** What Ambient may show: admin-enabled webcams only, under their admin-set names. */
    public static function ambient(): array {
        try {
            self::ensureBackfill();
            $rows = self::q('SELECT id, name, url, kind FROM vaultcast_webcams WHERE enabled_ambient=1 ORDER BY name, created_at LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('Vaultcast webcam registry: ' . $e->getMessage()); return []; }
        return array_map(function ($r) { return ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'url' => (string)$r['url'], 'kind' => (string)$r['kind'], 'refresh' => 30]; }, $rows);
    }
    public static function ambientEnabled(string $id): bool {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) return false;
        try { return (bool)self::q('SELECT 1 FROM vaultcast_webcams WHERE id=? AND enabled_ambient=1', [$id])->fetchColumn(); } catch (Throwable $e) { return false; }
    }
}
