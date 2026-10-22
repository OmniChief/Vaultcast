<?php
declare(strict_types=1);

/**
 * Trailers and extras for films and shows.
 *
 * Local extras follow the Plex / Jellyfin naming conventions: a folder inside the title folder
 * (Trailers, Featurettes, Behind The Scenes, Deleted Scenes, Interviews, Scenes, Shorts, Extras, Other)
 * or a filename suffix next to the film (Film (2010)-trailer.mp4, -featurette, -behindthescenes,
 * -deleted, -interview, -scene, -short, -other). They are pulled out of the catalogue whenever it is
 * saved, so they never show up as films or episodes, and are indexed in vaultcast_extras with a link
 * to their parent film (item id) or show (show key, optionally a season). A file that cannot be tied
 * to a parent stays an ordinary library item, exactly as before.
 *
 * Extras play through the normal /items/{id}/play and transcode paths with a 24-character id;
 * access always follows the parent title (library area, parental ceiling).
 *
 * Online trailers: when a title has no local trailer, the TMDB videos endpoint (the key already
 * configured for posters / collections) is asked once and the pick is cached on the server.
 */
final class Extras
{
    const TYPES = ['trailer' => 'Trailer', 'featurette' => 'Featurette', 'behindthescenes' => 'Behind the scenes', 'deleted' => 'Deleted scene',
        'interview' => 'Interview', 'scene' => 'Scene', 'short' => 'Short', 'other' => 'Extra'];
    const FOLDERS = ['trailers' => 'trailer', 'featurettes' => 'featurette', 'behind the scenes' => 'behindthescenes', 'deleted scenes' => 'deleted',
        'interviews' => 'interview', 'scenes' => 'scene', 'shorts' => 'short', 'extras' => 'other', 'other' => 'other'];
    const SECTION_TYPES = ['movies', 'disney', 'tv', 'anime'];
    const DEFAULTS = ['online' => true, 'hero_autoplay' => false];
    const SETTINGS_DOC = 'extras-settings';

    /** Section ids that a full rescan just walked; their stale rows are pruned on the next save. */
    private static $fullScan = null;
    private static $settings = null;

    private static function q(string $sql, array $args = []): PDOStatement { $s = \Vaultcast\Database::connection()->prepare($sql); $s->execute($args); return $s; }

    public static function isId(string $id): bool { return (bool)preg_match('/^[a-f0-9]{24}$/D', $id); }
    public static function idFor(string $sectionId, string $rel): string { return substr(sha1('extra|' . $sectionId . '|' . $rel), 0, 24); }

    // ------------------------------------------------------------------ settings
    public static function settings(): array
    {
        if (self::$settings !== null) return self::$settings;
        $doc = \Vaultcast\Repository::document(self::SETTINGS_DOC, []);
        $out = self::DEFAULTS;
        if (is_array($doc)) foreach (self::DEFAULTS as $k => $v) if (array_key_exists($k, $doc)) $out[$k] = (bool)$doc[$k];
        return self::$settings = $out;
    }

    public static function settingsView(): array
    {
        return ['ok' => true, 'settings' => self::settings(), 'tmdb' => CollectionDiscovery::tmdbKey() !== '', 'remote' => Posters::enabled(),
            'counts' => self::counts()];
    }

    public static function saveSettings(array $body): array
    {
        $next = self::settings();
        foreach (self::DEFAULTS as $k => $v) if (array_key_exists($k, $body)) {
            if (!is_bool($body[$k])) throw new InvalidArgumentException('Choose on or off.');
            $next[$k] = $body[$k];
        }
        \Vaultcast\Repository::putDocument(self::SETTINGS_DOC, $next);
        self::$settings = $next;
        return self::settingsView();
    }

    /** Any extras still waiting for a running time / thumbnail? (cheap, indexed) */
    public static function waiting(): bool
    {
        try { return self::q('SELECT 1 FROM vaultcast_extras WHERE probed=0 LIMIT 1')->fetchColumn() !== false; } catch (Throwable $e) { return false; }
    }

    private static function counts(): array
    {
        try {
            $r = self::q("SELECT COUNT(*) AS n, SUM(probed=0) AS waiting FROM vaultcast_extras")->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['extras' => (int)($r['n'] ?? 0), 'waiting' => (int)($r['waiting'] ?? 0)];
        } catch (Throwable $e) { return ['extras' => 0, 'waiting' => 0]; }
    }

    // ------------------------------------------------------------------ detection
    /**
     * Is this section-relative path an extra? Folder names count from the second level down, so a show
     * or film that is itself called "Extras" or "Shorts" at the top of a library is never mistaken for one.
     * @return array{type:string,mode:string,index?:int,base?:string}|null
     */
    public static function classify(string $rel): ?array
    {
        $rel = str_replace('\\', '/', $rel);
        $parts = explode('/', $rel);
        $file = (string)array_pop($parts);
        foreach ($parts as $i => $dir) {
            if ($i === 0) continue;
            $k = strtolower(trim((string)preg_replace('/\s+/', ' ', $dir)));
            if (isset(self::FOLDERS[$k])) return ['type' => self::FOLDERS[$k], 'mode' => 'folder', 'index' => $i];
        }
        $stem = (string)pathinfo($file, PATHINFO_FILENAME);
        $stem = (string)preg_replace('/(?:\.directplay)+$/i', '', $stem);
        if (preg_match('/(?<=\S)-(trailer|featurette|behindthescenes|deleted|interview|scene|short|other)$/iD', $stem, $m)) {
            return ['type' => strtolower($m[1]), 'mode' => 'suffix', 'base' => substr($stem, 0, -strlen($m[0]))];
        }
        if ($parts && strtolower($stem) === 'trailer') return ['type' => 'trailer', 'mode' => 'suffix', 'base' => ''];
        return null;
    }

    private static function seasonOf(array $dirs, string $stem = ''): ?int
    {
        foreach ($dirs as $d) {
            $d = trim((string)$d);
            if (preg_match('/^(?:season|series|staffel|saison|temporada)[\s._-]*(\d{1,4})$/iD', $d, $m) || preg_match('/^s(\d{1,4})$/iD', $d, $m)) return (int)$m[1];
            if (strcasecmp($d, 'specials') === 0) return 0;
        }
        if ($stem !== '' && preg_match('/\bs(\d{1,3})e\d{1,4}/i', $stem, $m)) return (int)$m[1];
        return null;
    }

    private static function titleFor(string $rel, string $type): string
    {
        $stem = (string)pathinfo(str_replace('\\', '/', $rel), PATHINFO_FILENAME);
        $stem = (string)preg_replace('/(?:\.directplay)+$/i', '', $stem);
        $stem = (string)preg_replace('/(?<=\S)-(trailer|featurette|behindthescenes|deleted|interview|scene|short|other)$/iD', '', $stem);
        $t = trim((string)preg_replace('/\s+/', ' ', str_replace(['_', '.'], ' ', $stem)));
        if ($t === '' || strcasecmp($t, 'trailer') === 0) return self::TYPES[$type] ?? 'Extra';
        return mb_substr($t, 0, 250);
    }

    /** A full rescan is about to save: prune extras those sections no longer have. */
    public static function beginFullScan(array $sectionIds): void
    {
        self::$fullScan = array_values(array_unique(array_map('strval', $sectionIds)));
    }

    /**
     * Pull extras out of a catalogue that is about to be saved. Linked extras are removed from the
     * items and written to the index; anything without a parent is left alone.
     */
    public static function extract(array $cat): array
    {
        $prune = self::$fullScan; self::$fullScan = null;
        $items = $cat['items'] ?? [];
        if (!is_array($items) || !$items) { if ($prune !== null) self::prune($prune, ''); return $cat; }
        $candidates = []; $byDir = []; $byShowRoot = [];
        foreach ($items as $id => $it) {
            if (!is_array($it) || ($it['media_kind'] ?? '') !== 'video') continue;
            $type = (string)($it['type'] ?? '');
            if (!in_array($type, self::SECTION_TYPES, true)) continue;
            $rel = str_replace('\\', '/', (string)($it['rel'] ?? ''));
            if ($rel === '' || preg_match('#^[a-z]+://#i', (string)($it['path'] ?? ''))) continue;
            $sec = (string)($it['section'] ?? '');
            $c = self::classify($rel);
            if ($c) { $candidates[(string)$id] = $c + ['rel' => $rel, 'section' => $sec, 'type' => $type]; continue; }
            $dir = strpos($rel, '/') === false ? '' : dirname($rel);
            $byDir[$sec . '|' . $dir][] = (string)$id;
            if (in_array($type, ['tv', 'anime'], true)) {
                $root = explode('/', $rel)[0];
                if (strpos($rel, '/') !== false && !isset($byShowRoot[$sec . '|' . $root])) $byShowRoot[$sec . '|' . $root] = (string)$id;
            }
        }
        if (!$candidates) { if ($prune !== null) self::prune($prune, ''); return $cat; }
        $rows = []; $removed = [];
        // Originals first so a DirectPlay copy of an extra never replaces its source.
        uksort($candidates, static function ($a, $b) use ($candidates) {
            return (int)(bool)preg_match('/\.directplay\./i', $candidates[$a]['rel']) <=> (int)(bool)preg_match('/\.directplay\./i', $candidates[$b]['rel']);
        });
        foreach ($candidates as $itemId => $c) {
            $it = $items[$itemId];
            $parts = explode('/', $c['rel']); $file = array_pop($parts);
            $parent = null;
            if (in_array($c['type'], ['tv', 'anime'], true)) {
                if (!$parts) continue;
                $epId = $byShowRoot[$c['section'] . '|' . $parts[0]] ?? null;
                if ($epId === null) continue;
                $ep = $items[$epId];
                $name = (string)($ep['show'] ?? '');
                if ($name === '') $name = $parts[0];
                $dirs = $c['mode'] === 'folder' ? array_slice($parts, 1, max(0, (int)$c['index'] - 1)) : array_slice($parts, 1);
                $parent = ['show', Catalogue::showKey($c['section'], $name), self::seasonOf($dirs, $c['mode'] === 'suffix' ? (string)($c['base'] ?? '') : '')];
            } else {
                $dir = $c['mode'] === 'folder' ? implode('/', array_slice($parts, 0, (int)$c['index'])) : implode('/', $parts);
                $siblings = $byDir[$c['section'] . '|' . $dir] ?? [];
                $pick = null;
                if ($c['mode'] === 'suffix' && ($c['base'] ?? '') !== '') {
                    foreach ($siblings as $sid) {
                        $stem = (string)pathinfo((string)$items[$sid]['rel'], PATHINFO_FILENAME);
                        $stem = (string)preg_replace('/(?:\.directplay)+$/i', '', $stem);
                        if (strcasecmp($stem, (string)$c['base']) === 0) { $pick = $sid; break; }
                    }
                }
                if ($pick === null && $siblings) {
                    // Several files in one film folder are fine when they are versions of the same film.
                    $titles = [];
                    foreach ($siblings as $sid) $titles[mb_strtolower(trim((string)($items[$sid]['title'] ?? '')))] = true;
                    if (count($titles) === 1) { $sorted = $siblings; sort($sorted, SORT_STRING); $pick = $sorted[0]; }
                }
                if ($pick === null) continue;
                $parent = ['item', $pick, null];
            }
            $key = preg_replace('/(?:\.directplay)+(?=\.[^.\/]+$)/i', '', $c['rel']);
            $xid = self::idFor($c['section'], (string)$key);
            $removed[$itemId] = (string)($it['section'] ?? '');
            if (isset($rows[$xid])) continue;
            $rows[$xid] = [$xid, $c['section'], $parent[0], (string)$parent[1], $parent[2], $c['type'], self::titleFor($c['rel'], $c['type']),
                (string)$it['path'], $c['rel'], strtolower((string)($it['ext'] ?? '')), (int)($it['size'] ?? 0), (int)($it['mtime'] ?? 0)];
        }
        $gen = bin2hex(random_bytes(8));
        if ($rows && !self::store(array_values($rows), $gen)) return $cat; // index unavailable: leave the catalogue untouched
        foreach ($removed as $itemId => $sec) unset($cat['items'][$itemId]);
        if ($removed) {
            if (isset($cat['item_count'])) $cat['item_count'] = count($cat['items']);
            $per = array_count_values(array_values($removed));
            foreach ($cat['sections'] ?? [] as $i => $meta) {
                $sid = (string)($meta['id'] ?? '');
                if (isset($per[$sid]) && isset($meta['count'])) $cat['sections'][$i]['count'] = max(0, (int)$meta['count'] - $per[$sid]);
            }
        }
        if ($prune !== null) self::prune($prune, $gen);
        return $cat;
    }

    private static function store(array $rows, string $gen): bool
    {
        try {
            $now = time();
            foreach (array_chunk($rows, 200) as $chunk) {
                $sql = 'INSERT INTO vaultcast_extras (id,section_id,parent_kind,parent_id,season,extra_type,title,path,rel,ext,size,mtime,seen_gen,created_at,updated_at) VALUES '
                    . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'))
                    // probed is reset before size/mtime change: MariaDB applies these assignments left to right.
                    . ' ON DUPLICATE KEY UPDATE probed=IF(size<>VALUES(size) OR mtime<>VALUES(mtime) OR path<>VALUES(path),0,probed),'
                    . ' section_id=VALUES(section_id),parent_kind=VALUES(parent_kind),parent_id=VALUES(parent_id),season=VALUES(season),extra_type=VALUES(extra_type),'
                    . ' title=VALUES(title),path=VALUES(path),rel=VALUES(rel),ext=VALUES(ext),size=VALUES(size),mtime=VALUES(mtime),seen_gen=VALUES(seen_gen),updated_at=VALUES(updated_at)';
                $args = [];
                foreach ($chunk as $r) { array_push($args, ...$r); array_push($args, $gen, $now, $now); }
                self::q($sql, $args);
            }
            return true;
        } catch (Throwable $e) { error_log('Vaultcast extras index: ' . $e->getMessage()); return false; }
    }

    private static function prune(array $sections, string $gen): void
    {
        if (!$sections) return;
        try {
            $old = self::q('SELECT id FROM vaultcast_extras WHERE section_id IN (' . implode(',', array_fill(0, count($sections), '?')) . ') AND seen_gen<>?', array_merge($sections, [$gen]))->fetchAll(PDO::FETCH_COLUMN);
            foreach (array_chunk($old, 200) as $ids) {
                self::q('DELETE FROM vaultcast_extras WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
                foreach ($ids as $id) @unlink(self::thumbCache((string)$id));
            }
        } catch (Throwable $e) { error_log('Vaultcast extras prune: ' . $e->getMessage()); }
    }

    // ------------------------------------------------------------------ access
    private static function row(string $id): ?array
    {
        if (!self::isId($id)) return null;
        try { $r = self::q('SELECT * FROM vaultcast_extras WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) { return null; }
        return $r ?: null;
    }

    /** The parent as a card-ish array (for area and parental checks), or null when it is gone. */
    private static function parentOf(array $row): ?array
    {
        static $memo = [];
        $k = $row['parent_kind'] . ':' . $row['parent_id'];
        if (array_key_exists($k, $memo)) return $memo[$k];
        if ($row['parent_kind'] === 'item') {
            $item = Catalogue::item((string)$row['parent_id']);
            return $memo[$k] = ($item && ($item['media_kind'] ?? '') === 'video' ? $item : null);
        }
        $type = self::sectionType((string)$row['section_id']);
        if (!in_array($type, ['tv', 'anime'], true)) return $memo[$k] = null;
        return $memo[$k] = ['kind' => 'show', 'id' => (string)$row['parent_id'], 'show_key' => (string)$row['parent_id'], 'type' => $type, 'media_kind' => 'video', 'section' => (string)$row['section_id']];
    }

    private static function sectionType(string $sectionId): string
    {
        static $types = null;
        if ($types === null) {
            $types = [];
            foreach (\Vaultcast\Repository::document('catalogue', ['sections' => []])['sections'] ?? [] as $s) if (is_array($s)) $types[(string)($s['id'] ?? '')] = (string)($s['type'] ?? '');
        }
        return $types[$sectionId] ?? '';
    }

    private static function parentAllows(?array $user, array $row, ?array $parent = null): bool
    {
        $parent = $parent ?? self::parentOf($row);
        if (!$parent || !AreaAccess::allowed($user, 'library')) return false;
        if ($row['parent_kind'] === 'item') return Parental::allows($user, $parent);
        return Parental::allowsShowKey($user, (string)$row['parent_id']);
    }

    /** Stream tokens for an extra: refuse when its parent title is above the token owner's ceiling. */
    public static function tokenAllowed(?array $owner, string $id): bool
    {
        $row = self::row($id);
        return $row ? self::parentAllows($owner, $row) : true;
    }

    /**
     * Item-shaped record for the player (Stream::issueToken / Transcode::start).
     * @throws DomainException when missing or not allowed
     */
    public static function playable(array $user, string $id): array
    {
        $row = self::row($id);
        if (!$row) throw new DomainException('Not found');
        $parent = self::parentOf($row);
        if (!$parent || !self::parentAllows($user, $row, $parent)) throw new DomainException("This extra isn\u{2019}t available on this profile.");
        $path = (string)$row['path'];
        if (!is_file($path)) throw new DomainException('File missing');
        $item = ['id' => (string)$row['id'], 'section' => (string)$row['section_id'], 'type' => (string)($parent['type'] ?? 'movies'), 'title' => (string)$row['title'],
            'path' => $path, 'rel' => (string)$row['rel'], 'ext' => (string)$row['ext'], 'size' => (int)$row['size'], 'media_kind' => 'video', 'extra' => true];
        if ((int)$row['duration'] > 0) $item['duration'] = (int)$row['duration'];
        return $item;
    }

    private static function publicRow(array $r): array
    {
        $type = (string)$r['extra_type'];
        $thumb = self::thumbFile($r) !== null;
        return ['id' => (string)$r['id'], 'title' => (string)$r['title'], 'extra_type' => $type, 'type_label' => self::TYPES[$type] ?? 'Extra',
            'season' => $r['season'] === null ? null : (int)$r['season'], 'duration' => (int)$r['duration'], 'ext' => (string)$r['ext'],
            'thumb' => $thumb ? rtrim(\Vaultcast\Database::basePath(), '/') . '/api/extras/' . $r['id'] . '/thumb?v=' . (int)$r['mtime'] : null];
    }

    private static function listFor(string $kind, string $parentId): array
    {
        try {
            $rows = self::q("SELECT * FROM vaultcast_extras WHERE parent_kind=? AND parent_id=? ORDER BY FIELD(extra_type,'trailer','featurette','behindthescenes','deleted','interview','scene','short','other'), season, title LIMIT 200", [$kind, $parentId])->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        $out = []; $gone = [];
        foreach ($rows as $r) { if (!is_file((string)$r['path'])) { $gone[] = $r['id']; continue; } $out[] = self::publicRow($r); }
        if ($gone) try { self::q('DELETE FROM vaultcast_extras WHERE id IN (' . implode(',', array_fill(0, count($gone), '?')) . ')', $gone); } catch (Throwable $e) {}
        return $out;
    }

    private static function view(array $extras): array
    {
        $local = null;
        foreach ($extras as $x) if ($x['extra_type'] === 'trailer') { $local = $x; break; }
        $st = self::settings();
        return ['ok' => true, 'extras' => $extras, 'local_trailer' => $local, 'online' => $local === null && $st['online'] && CollectionDiscovery::tmdbKey() !== '' && Posters::enabled(),
            'hero_autoplay' => $st['hero_autoplay']];
    }

    public static function forItem(array $user, string $itemId): array
    {
        $item = Catalogue::item($itemId);
        if (!$item || ($item['media_kind'] ?? '') !== 'video') throw new DomainException('Not found');
        if (!AreaAccess::allowed($user, AreaAccess::itemArea($item)) || !Parental::allows($user, $item)) throw new DomainException("This title isn\u{2019}t available on this profile.");
        if (!in_array((string)($item['type'] ?? ''), self::SECTION_TYPES, true)) return self::view([]);
        return self::view(self::listFor('item', (string)$item['id']));
    }

    public static function forShow(array $user, string $showKey): array
    {
        if (!preg_match('/^[a-f0-9]{8,64}$/D', $showKey)) throw new DomainException('Not found');
        if (!AreaAccess::allowed($user, 'library') || !Parental::allowsShowKey($user, $showKey)) throw new DomainException("This title isn\u{2019}t available on this profile.");
        return self::view(self::listFor('show', $showKey));
    }

    // ------------------------------------------------------------------ thumbnails and probing
    private static function thumbCache(string $id): string { return STREAM_DATA . '/cache/extras/' . preg_replace('/[^a-f0-9]/', '', $id) . '.jpg'; }

    private static function thumbFile(array $r): ?string
    {
        $cache = self::thumbCache((string)$r['id']);
        if (is_file($cache) && filesize($cache) > 0) return $cache;
        $base = preg_replace('/\.[^.\/\\\\]+$/', '', (string)$r['path']);
        if (!is_string($base) || $base === '') return null;
        foreach (['.jpg', '.jpeg', '.png', '.webp', '-thumb.jpg', '-thumb.png'] as $suffix) if (is_file($base . $suffix)) return $base . $suffix;
        return null;
    }

    public static function serveThumb(array $user, string $id): void
    {
        $row = self::row($id);
        if (!$row || !self::parentAllows($user, $row)) json_error('Not found', 404);
        $file = self::thumbFile($row);
        if ($file === null) json_error('Not found', 404);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        release_request_locks();
        header('Content-Type: ' . ($types[$ext] ?? 'image/jpeg'));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string)filesize($file));
        readfile($file);
        exit;
    }

    /** Background task: durations and a still frame for extras that have neither yet. Time-budgeted. */
    public static function probe(?callable $checkpoint = null, float $budget = 150): array
    {
        $out = ['ok' => true, 'probed' => 0, 'thumbs' => 0, 'deferred' => 0];
        $started = microtime(true);
        try { $rows = self::q('SELECT * FROM vaultcast_extras WHERE probed=0 ORDER BY created_at LIMIT 2000')->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return ['ok' => true, 'probed' => 0, 'thumbs' => 0, 'deferred' => 0, 'note' => 'Extras index not ready.']; }
        $dir = STREAM_DATA . '/cache/extras';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        foreach ($rows as $i => $r) {
            if ($checkpoint) $checkpoint();
            if (microtime(true) - $started > $budget) { $out['deferred'] = count($rows) - $i; break; }
            $path = (string)$r['path'];
            $duration = 0;
            if (is_file($path)) {
                $duration = (int)round((float)(MediaProbe::duration($path, 10) ?? 0));
                $cache = self::thumbCache((string)$r['id']);
                if (self::thumbFile($r) === null && function_exists('proc_open')) {
                    $at = $duration > 0 ? min(90.0, max(1.0, $duration * 0.2)) : 5.0;
                    $tmp = $cache . '.' . bin2hex(random_bytes(3)) . '.jpg';
                    Process::capture([Remux::ffmpegBin(), '-nostdin', '-hide_banner', '-loglevel', 'error', '-ss', (string)round($at, 1), '-i', $path, '-frames:v', '1', '-vf', 'scale=480:-2', '-q:v', '5', '-y', $tmp], 25);
                    if (is_file($tmp) && filesize($tmp) > 0) { @rename($tmp, $cache); $out['thumbs']++; } else @unlink($tmp);
                }
            }
            try { self::q('UPDATE vaultcast_extras SET duration=?, probed=1 WHERE id=?', [$duration, $r['id']]); } catch (Throwable $e) {}
            $out['probed']++;
        }
        return $out;
    }

    // ------------------------------------------------------------------ online trailers (TMDB)
    private static function language(array $user): string
    {
        $pref = strtolower(substr((string)($user['prefs']['language'] ?? ''), 0, 2));
        if (!preg_match('/^[a-z]{2}$/D', $pref)) {
            $accept = strtolower(substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 2));
            $pref = preg_match('/^[a-z]{2}$/D', $accept) ? $accept : 'en';
        }
        return $pref;
    }

    private static function tmdb(string $path, array $query = []): ?array
    {
        $key = CollectionDiscovery::tmdbKey();
        if ($key === '') return null;
        $headers = [];
        if (strlen($key) > 60 && substr_count($key, '.') >= 2) $headers[] = 'Authorization: Bearer ' . $key; else $query['api_key'] = $key;
        return Posters::remoteJson('https://api.themoviedb.org/3/' . $path . ($query ? '?' . http_build_query($query) : ''), $headers, 6);
    }

    /** TMDB id for a film or show from IMDb / TVDB ids we already hold, else a title (and year) search. */
    private static function tmdbId(string $kind, array $subject): ?int
    {
        $tv = $kind === 'show';
        foreach ($subject['ids'] as $source => $value) {
            if ($value === '') continue;
            $find = self::tmdb('find/' . rawurlencode($value), ['external_source' => $source]);
            $hit = $find[$tv ? 'tv_results' : 'movie_results'][0]['id'] ?? null;
            if ($hit) return (int)$hit;
            if (Posters::remoteBudgetExpired()) return null;
        }
        $title = trim((string)$subject['title']);
        if ($title === '') return null;
        $q = ['query' => $title, 'include_adult' => 'false'];
        if (!empty($subject['year'])) $q[$tv ? 'first_air_date_year' : 'year'] = (int)$subject['year'];
        $res = self::tmdb($tv ? 'search/tv' : 'search/movie', $q);
        $first = $res['results'][0]['id'] ?? null;
        return $first ? (int)$first : null;
    }

    /** Best YouTube video: the viewer's language, then English, then official trailers over teasers. */
    public static function pickVideo(array $results, string $lang): ?array
    {
        $best = null; $bestScore = -1;
        foreach ($results as $v) {
            if (!is_array($v) || strcasecmp((string)($v['site'] ?? ''), 'YouTube') !== 0) continue;
            $key = (string)($v['key'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/D', $key)) continue;
            $type = (string)($v['type'] ?? '');
            if (!in_array($type, ['Trailer', 'Teaser'], true)) continue;
            $vl = strtolower((string)($v['iso_639_1'] ?? ''));
            $score = ($vl === $lang ? 400 : ($vl === 'en' ? 200 : 0)) + (!empty($v['official']) ? 40 : 0) + ($type === 'Trailer' ? 20 : 0) + min(9, (int)((int)($v['size'] ?? 0) / 240));
            if ($score > $bestScore) { $bestScore = $score; $best = ['key' => $key, 'name' => mb_substr(trim((string)($v['name'] ?? '')), 0, 160), 'type' => $type, 'language' => $vl, 'official' => !empty($v['official'])]; }
        }
        return $best;
    }

    private static function onlineFor(string $kind, string $id, callable $subjectFn, string $lang): ?array
    {
        $cacheKey = 'extras-trailer:v1:' . $kind . ':' . $id . ':' . $lang;
        $hit = \Vaultcast\Repository::cacheGet($cacheKey);
        if (is_array($hit)) return $hit['video'] ?? null;
        if (!Posters::enabled() || CollectionDiscovery::tmdbKey() === '') return null;
        $video = null; $complete = false;
        Posters::beginRemoteBudget(8);
        try {
            $subject = $subjectFn();
            $tmdbId = $subject ? self::tmdbId($kind, $subject) : null;
            if ($tmdbId) {
                $langs = array_values(array_unique([$lang, 'en']));
                $res = self::tmdb(($kind === 'show' ? 'tv/' : 'movie/') . $tmdbId . '/videos', ['include_video_language' => implode(',', $langs) . ',null']);
                if (is_array($res)) { $video = self::pickVideo((array)($res['results'] ?? []), $lang); $complete = true; }
            } else $complete = !Posters::remoteBudgetExpired();
        } catch (Throwable $e) { error_log('Vaultcast trailer lookup: ' . $e->getMessage()); }
        finally { Posters::endRemoteBudget(); }
        // Found: a week. Nothing there: a day. Provider trouble: try again in an hour.
        \Vaultcast\Repository::cachePut($cacheKey, ['video' => $video, 'at' => time()], $video ? 7 * 86400 : ($complete ? 86400 : 3600));
        return $video;
    }

    public static function trailerForItem(array $user, string $itemId): array
    {
        $item = Catalogue::item($itemId);
        if (!$item || ($item['media_kind'] ?? '') !== 'video' || !in_array((string)($item['type'] ?? ''), self::SECTION_TYPES, true)) throw new DomainException('Not found');
        if (!AreaAccess::allowed($user, 'library') || !Parental::allows($user, $item)) throw new DomainException("This title isn\u{2019}t available on this profile.");
        if (!self::settings()['online']) return ['ok' => true, 'video' => null, 'disabled' => true];
        $video = self::onlineFor('item', (string)$item['id'], static function () use ($item): array {
            $imdb = '';
            try { $imdb = ContentRatings::imdbIdForMovie($item); } catch (Throwable $e) {}
            return ['ids' => ['imdb_id' => $imdb], 'title' => (string)($item['title'] ?? ''), 'year' => $item['year'] ?? null];
        }, self::language($user));
        return ['ok' => true, 'video' => $video];
    }

    public static function trailerForShow(array $user, string $showKey): array
    {
        if (!preg_match('/^[a-f0-9]{8,64}$/D', $showKey)) throw new DomainException('Not found');
        if (!AreaAccess::allowed($user, 'library') || !Parental::allowsShowKey($user, $showKey)) throw new DomainException("This title isn\u{2019}t available on this profile.");
        if (!self::settings()['online']) return ['ok' => true, 'video' => null, 'disabled' => true];
        $video = self::onlineFor('show', $showKey, static function () use ($showKey): ?array {
            $sample = BrowseIndex::items($showKey)[0] ?? null;
            if (!is_array($sample)) return null;
            $guide = \Vaultcast\Repository::document('tvmaze-guide:' . $showKey, null);
            $ext = is_array($guide) ? (array)($guide['externals'] ?? []) : [];
            $imdb = preg_match('/^tt\d{5,10}$/D', (string)($ext['imdb'] ?? '')) ? (string)$ext['imdb'] : '';
            $tvdb = preg_match('/^\d{1,10}$/D', (string)($ext['thetvdb'] ?? '')) ? (string)$ext['thetvdb'] : '';
            $year = null;
            if (is_array($guide) && preg_match('/^(\d{4})/', (string)($guide['premiered'] ?? ''), $m)) $year = (int)$m[1];
            $title = (string)($sample['show'] ?? '');
            if ($title === '' && is_array($guide)) $title = (string)($guide['name'] ?? '');
            return ['ids' => ['imdb_id' => $imdb, 'tvdb_id' => $tvdb], 'title' => $title, 'year' => $year];
        }, self::language($user));
        return ['ok' => true, 'video' => $video];
    }
}
