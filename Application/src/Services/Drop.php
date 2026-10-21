<?php
declare(strict_types=1);

/**
 * Misc → Drop: temporary private files, notes and links with resumable chunked uploads and
 * share links. Availability ends the moment an item expires or uses its last download; the
 * bytes are removed later by the admin "Delete expired Drop files" maintenance task.
 */
final class Drop {
    const CHUNK = 8388608;           // 8 MiB: under the example nginx limit (16m); trimmed to post_max_size when PHP allows less
    const MAX_DAYS = 30;
    const TEXT_MAX = 20000;
    const PRESETS = [1 => '1 hour', 6 => '6 hours', 24 => '24 hours', 72 => '3 days', 168 => '7 days', 720 => '30 days'];

    public static function settings(): array {
        $d = (array)(Store::settings()['drop'] ?? []);
        return ['enabled' => !array_key_exists('enabled', $d) || !empty($d['enabled']), 'user_role_upload' => !empty($d['user_role_upload']),
            'default_quota_mb' => max(0, (int)($d['default_quota_mb'] ?? 2048)), 'quotas' => array_map('intval', (array)($d['quotas'] ?? [])),
            'max_file_mb' => max(1, (int)($d['max_file_mb'] ?? 1024)), 'public_links' => !array_key_exists('public_links', $d) || !empty($d['public_links']),
            'anonymous' => !empty($d['anonymous']), 'anon_max_file_mb' => max(1, (int)($d['anon_max_file_mb'] ?? 100)), 'anon_quota_mb' => max(1, (int)($d['anon_quota_mb'] ?? 1024)),
            'anon_max_days' => max(1, min(self::MAX_DAYS, (int)($d['anon_max_days'] ?? 7))), 'nginx_downloads' => !empty($d['nginx_downloads'])];
    }
    public static function saveSettings(array $body): array {
        $all = Store::settings(); $cur = self::settings();
        $roles = array_merge(['user'], array_keys(Permissions::roles()));
        $quotas = [];
        foreach ((array)($body['quotas'] ?? $cur['quotas']) as $role => $mb) if (in_array((string)$role, $roles, true) && $mb !== '' && $mb !== null) $quotas[(string)$role] = max(0, min(10000000, (int)$mb));
        $anon = !empty($body['anonymous'] ?? $cur['anonymous']);
        // Turning anonymous uploads on is deliberate: the admin screen asks for confirmation and sends it along.
        if ($anon && !$cur['anonymous'] && empty($body['confirm_anonymous'])) throw new DomainException('Confirm that you want to allow anonymous Drop uploads.');
        $all['drop'] = ['enabled' => !empty($body['enabled'] ?? $cur['enabled']), 'user_role_upload' => !empty($body['user_role_upload'] ?? $cur['user_role_upload']),
            'default_quota_mb' => max(0, min(10000000, (int)($body['default_quota_mb'] ?? $cur['default_quota_mb']))), 'quotas' => $quotas,
            'max_file_mb' => max(1, min(1000000, (int)($body['max_file_mb'] ?? $cur['max_file_mb']))), 'public_links' => !empty($body['public_links'] ?? $cur['public_links']),
            'anonymous' => $anon, 'anon_max_file_mb' => max(1, min(100000, (int)($body['anon_max_file_mb'] ?? $cur['anon_max_file_mb']))),
            'anon_quota_mb' => max(1, min(10000000, (int)($body['anon_quota_mb'] ?? $cur['anon_quota_mb']))), 'anon_max_days' => max(1, min(self::MAX_DAYS, (int)($body['anon_max_days'] ?? $cur['anon_max_days']))),
            'nginx_downloads' => !empty($body['nginx_downloads'] ?? $cur['nginx_downloads'])];
        Store::saveSettings($all);
        return ['ok' => true, 'settings' => self::settings(), 'notes' => self::settingNotes()];
    }
    /** Quotas smaller than the per-file limit cap that role's largest file: say so instead of failing uploads later. */
    public static function settingNotes(): array {
        $s = self::settings(); $notes = []; $roles = array_merge(['user' => ['name' => 'User']], Permissions::roles());
        foreach ($roles as $role => $info) {
            $mb = $s['quotas'][(string)$role] ?? $s['default_quota_mb'];
            if ($mb > 0 && $mb < $s['max_file_mb']) $notes[] = ($info['name'] ?? $role) . ': storage ' . $mb . ' MB is below the ' . $s['max_file_mb'] . ' MB file limit, so their largest file is ' . $mb . ' MB.';
        }
        if ($s['anonymous'] && $s['anon_max_file_mb'] > $s['anon_quota_mb']) $notes[] = 'Anonymous file limit is larger than the anonymous total; the total applies.';
        return $notes;
    }
    private static function dir(): string {
        $dir = STREAM_DATA . '/drop';
        if (!is_dir($dir)) { @mkdir($dir, 0770, true); @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($dir . '/index.html', ''); }
        return $dir;
    }
    private static function path(string $id): string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new DomainException('Item not found.');
        $sub = self::dir() . '/' . substr($id, 0, 2); if (!is_dir($sub)) @mkdir($sub, 0770, true);
        return $sub . '/' . $id . '.bin';
    }
    private static function q(string $sql, array $p = []): PDOStatement { return Arcade::query($sql, $p); }
    public static function canUpload(array $user): bool { return self::settings()['enabled'] && Permissions::allowed($user, 'drop.upload'); }
    private static function requireUpload(array $user): void {
        if (self::isAnon($user)) { self::requireAnon(); return; }
        Arcade::signed($user);
        if (!self::settings()['enabled']) throw new DomainException('Drop is turned off on this server.');
        if (!Permissions::allowed($user, 'drop.upload')) throw new DomainException('Your account cannot upload to Drop. Ask an administrator for the Drop permission.');
    }

    // ---------------------------------------------------------------- anonymous Drop (admin opt-in, off by default)
    const ANON_COOKIE = 'vc_drop_anon';
    public static function isAnon(array $user): bool { return strpos((string)($user['id'] ?? ''), 'anon:') === 0; }
    public static function anonEnabled(): bool { $s = self::settings(); return $s['enabled'] && $s['anonymous']; }
    public static function requireAnon(): void { if (!self::anonEnabled()) throw new DomainException('Anonymous Drop is not available on this server.'); }
    private static function cookiePath(): string { return rtrim(\Vaultcast\Database::basePath(), '/') . '/api/drop/'; }
    private static function secure(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'; }
    /**
     * The anonymous visitor: a random token in an HttpOnly cookie scoped to /api/drop/. Only a keyed hash of it is
     * stored, so the database never holds a usable credential. A visitor sees only the uploads made with their cookie.
     */
    public static function anonUser(bool $create = true): ?array {
        $token = (string)($_COOKIE[self::ANON_COOKIE] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) {
            if (!$create) return null;
            $token = bin2hex(random_bytes(24));
            setcookie(self::ANON_COOKIE, $token, ['expires' => time() + 86400 * (self::MAX_DAYS + 1), 'path' => self::cookiePath(), 'httponly' => true, 'samesite' => 'Lax', 'secure' => self::secure()]);
            $_COOKIE[self::ANON_COOKIE] = $token;
        }
        return ['id' => 'anon:' . substr(hash_hmac('sha256', $token, self::secret()), 0, 40), 'role' => 'anonymous', 'username' => 'anonymous', 'display_name' => 'Anonymous'];
    }
    /** Fixed-window per-IP limit for anonymous requests. */
    public static function ipLimit(string $what, int $max, int $window): void {
        $slot = (int)floor(time() / $window);
        $k = 'drop-ip:' . $what . ':' . substr(hash('sha256', client_ip()), 0, 24) . ':' . $slot;
        $n = (int)((\Vaultcast\Repository::cacheGet($k) ?: ['n' => 0])['n']);
        if ($n >= $max) { header('Retry-After: ' . (($slot + 1) * $window - time())); json_error('Too many requests from your network. Wait a little and try again.', 429); }
        \Vaultcast\Repository::cachePut($k, ['n' => $n + 1], $window + 60);
    }
    private static function anonUsed(): int {
        return (int)self::q("SELECT COALESCE(SUM(GREATEST(bytes,received)),0) FROM vaultcast_drop_files WHERE user_id LIKE 'anon:%' AND purged=0 AND (expires_at=0 OR expires_at>?) AND (max_downloads=0 OR downloads<max_downloads)", [time()])->fetchColumn();
    }

    public static function quotaBytes(array $user): int {
        if (self::isAnon($user)) return self::settings()['anon_quota_mb'] * 1048576;
        if (($user['role'] ?? '') === 'admin') return 0;
        $s = self::settings(); $mb = $s['quotas'][(string)($user['role'] ?? 'user')] ?? $s['default_quota_mb'];
        return (int)$mb * 1048576;
    }
    /** Bytes held by available (not yet expired, exhausted or purged) items. Anonymous visitors share one pool. */
    public static function usedBytes(string $uid): int {
        if (strpos($uid, 'anon:') === 0) return self::anonUsed();
        return (int)self::q('SELECT COALESCE(SUM(GREATEST(bytes,received)),0) FROM vaultcast_drop_files WHERE user_id=? AND purged=0 AND (expires_at=0 OR expires_at>?) AND (max_downloads=0 OR downloads<max_downloads)', [$uid, time()])->fetchColumn();
    }
    private static function availability(array $b, bool $anon = false): array {
        $maxHours = ($anon ? self::settings()['anon_max_days'] : self::MAX_DAYS) * 24;
        $hours = isset($b['expires_hours']) ? (float)$b['expires_hours'] : (isset($b['expires_days']) ? 24 * (float)$b['expires_days'] : 24);
        if (!($hours > 0)) $hours = 24;
        $hours = min($maxHours, max(0.25, $hours));
        $max = max(0, min(100000, (int)($b['max_downloads'] ?? 0)));
        return [time() + (int)round($hours * 3600), $max];
    }
    public static function status(array $row): string {
        if ((int)$row['purged']) return 'deleted';
        if (!(int)$row['complete']) return 'uploading';
        if ((int)$row['expires_at'] && (int)$row['expires_at'] <= time()) return 'expired';
        if ((int)$row['max_downloads'] && (int)$row['downloads'] >= (int)$row['max_downloads']) return 'exhausted';
        return 'available';
    }
    /** Links for many items in one query. */
    private static function linksFor(array $ids): array {
        $out = []; $ids = array_values(array_filter($ids));
        foreach (array_chunk($ids, 200) as $part)
            foreach (self::q('SELECT * FROM vaultcast_drop_links WHERE file_id IN (' . implode(',', array_fill(0, count($part), '?')) . ') ORDER BY created_at DESC', $part)->fetchAll(PDO::FETCH_ASSOC) as $l) $out[$l['file_id']][] = $l;
        return $out;
    }
    private static function publicRow(array $r, ?array $links = null): array {
        $out = ['id' => $r['id'], 'kind' => $r['kind'], 'name' => $r['name'], 'mime' => $r['mime'], 'bytes' => (int)$r['bytes'], 'received' => (int)$r['received'],
            'created_at' => (int)$r['created_at'], 'expires_at' => (int)$r['expires_at'], 'max_downloads' => (int)$r['max_downloads'], 'downloads' => (int)$r['downloads'],
            'inbox' => (bool)$r['inbox'], 'status' => self::status($r), 'preview' => $r['kind'] === 'file' ? self::previewKind((string)$r['mime'], (int)$r['bytes']) : null];
        if ($r['kind'] !== 'file' && !(int)$r['purged']) $out['body'] = (string)$r['body'];
        if ($links !== null) $out['links'] = array_map(function ($l) { return ['token' => $l['token'], 'require_login' => (bool)$l['require_login'], 'password' => $l['password_hash'] !== '', 'downloads' => (int)$l['downloads'], 'revoked' => (bool)$l['revoked'], 'expires_at' => (int)$l['expires_at'], 'created_at' => (int)$l['created_at'], 'url' => self::shareUrl($l['token'])]; }, $links);
        return $out;
    }
    private static function fullRow(array $r): array { return self::publicRow($r, self::linksFor([$r['id']])[$r['id']] ?? []); }
    /** What the owner's preview can show: image, video, audio, pdf or text (null: download only). */
    public static function previewKind(string $mime, int $bytes): ?string {
        if (preg_match('#^image/(png|jpeg|gif|webp|avif|bmp)$#', $mime)) return 'image';
        if (preg_match('#^video/(mp4|webm|ogg)$#', $mime)) return 'video';
        if (preg_match('#^audio/(mpeg|mp3|mp4|aac|ogg|webm|wav|x-wav|flac|x-flac)$#', $mime)) return 'audio';
        if ($mime === 'application/pdf') return 'pdf';
        if ($bytes <= 2097152 && (preg_match('#^text/[\w.+-]+$#', $mime) || in_array($mime, ['application/json', 'application/xml', 'application/x-yaml', 'application/javascript'], true))) return 'text';
        return null;
    }
    public static function shareUrl(string $token): string { return rtrim(\Vaultcast\Database::basePath(), '/') . '/api/drop/s/' . $token; }
    private static function own(array $user, string $id): array {
        $r = self::q('SELECT * FROM vaultcast_drop_files WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$r || ($r['user_id'] !== $user['id'] && ($user['role'] ?? '') !== 'admin')) throw new DomainException('Item not found.');
        return $r;
    }
    public static function limits(?array $user = null): array {
        $toBytes = function ($v) { $v = trim((string)$v); if ($v === '') return 0; $n = (float)$v; $u = strtolower(substr($v, -1)); return (int)($n * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1)))); };
        $post = $toBytes(ini_get('post_max_size'));
        $chunk = $post > 0 ? min(self::CHUNK, max(262144, $post - 65536)) : self::CHUNK;
        $s = self::settings(); $anon = $user !== null && self::isAnon($user);
        $max = ($anon ? $s['anon_max_file_mb'] : $s['max_file_mb']) * 1048576;
        // Role quotas below the file limit cap the largest file too (both are enforced; the smaller wins).
        if ($user !== null) { $quota = self::quotaBytes($user); if ($quota > 0) $max = min($max, $quota); }
        return ['chunk' => $chunk, 'post_max_size' => $post, 'max_file' => $max];
    }
    public static function state(array $user): array {
        if (!self::isAnon($user)) Arcade::signed($user);
        $uid = (string)$user['id'];
        $rows = self::q('SELECT * FROM vaultcast_drop_files WHERE user_id=? AND purged=0 ORDER BY created_at DESC LIMIT 200', [$uid])->fetchAll(PDO::FETCH_ASSOC);
        $links = self::linksFor(array_column($rows, 'id'));
        $anon = self::isAnon($user); $s = self::settings();
        return ['ok' => true, 'anonymous' => $anon, 'can_upload' => $anon ? self::anonEnabled() : self::canUpload($user), 'items' => array_map(function ($r) use ($links) { return self::publicRow($r, $links[$r['id']] ?? []); }, $rows),
            'used' => self::usedBytes($uid), 'quota' => self::quotaBytes($user), 'limits' => self::limits($user), 'presets' => $anon ? array_filter(self::PRESETS, function ($h) use ($s) { return $h <= $s['anon_max_days'] * 24; }, ARRAY_FILTER_USE_KEY) : self::PRESETS,
            'max_days' => $anon ? $s['anon_max_days'] : self::MAX_DAYS, 'default_hours' => 24, 'public_links' => $s['public_links']];
    }
    public static function createText(array $user, array $b): array {
        if (self::isAnon($user)) throw new DomainException('Anonymous Drop accepts files only.');
        self::requireUpload($user);
        $kind = ($b['kind'] ?? '') === 'link' ? 'link' : 'text'; $body = trim((string)($b['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > self::TEXT_MAX) throw new DomainException('Write up to ' . number_format(self::TEXT_MAX) . ' characters.');
        if ($kind === 'link' && !preg_match('#^https?://[^\s<>"]+$#i', $body)) throw new DomainException('Links start with http:// or https://.');
        list($exp, $max) = self::availability($b); $id = bin2hex(random_bytes(16));
        $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 120) ?: ($kind === 'link' ? (string)(parse_url($body, PHP_URL_HOST) ?: 'Link') : mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 60));
        self::q('INSERT INTO vaultcast_drop_files (id,user_id,kind,name,mime,bytes,received,body,complete,created_at,expires_at,max_downloads,inbox) VALUES (?,?,?,?,?,?,?,?,1,?,?,?,?)',
            [$id, $user['id'], $kind, $name, 'text/plain', strlen($body), strlen($body), $body, time(), $exp, $max, empty($b['inbox']) ? 0 : 1]);
        if (!empty($b['inbox'])) self::selfNotice($user, $id, $name);
        return ['ok' => true, 'item' => self::fullRow(self::own($user, $id))];
    }
    private static function selfNotice(array $user, string $id, string $name): void {
        if (self::isAnon($user)) return;
        $person = Arcade::person($user);
        Inbox::add((string)$user['id'], 'system', 'drop', 'drop:' . $id, $person, 'Sent to your devices', mb_substr($name, 0, 120), ['kind' => 'path', 'path' => 'misc/drop']);
        HomeHub::mutate('home-hub:' . $user['id'], function (array $d) use ($id, $name, $person): array {
            $nid = 'drop:' . $id;
            $d['notifications'][$nid] = ['id' => $nid, 'from' => $person, 'title' => mb_substr($name, 0, 120), 'text' => 'Sent to your devices', 'kind' => 'drop', 'action' => 'Open', 'path' => 'misc/drop', 'created_at' => time()];
            $d['notifications'] = array_slice((array)$d['notifications'], -40, null, true);
            return $d;
        });
    }
    public static function init(array $user, array $b): array {
        self::requireUpload($user); $anon = self::isAnon($user);
        $size = (int)($b['size'] ?? -1); $name = trim(str_replace(["\0", '/', '\\'], '', preg_replace('/[\x00-\x1f\x7f]/', '', (string)($b['name'] ?? ''))));
        if ($name === '' || mb_strlen($name) > 200 || $name === '.' || $name === '..') throw new DomainException('The file needs a name of up to 200 characters.');
        if ($size <= 0) throw new DomainException('Empty files cannot be uploaded.');
        $lim = self::limits($user); $s = self::settings();
        $fileMb = $anon ? $s['anon_max_file_mb'] : $s['max_file_mb'];
        if ($size > $fileMb * 1048576) throw new DomainException('Files can be up to ' . $fileMb . ' MB' . ($anon ? ' without signing in.' : ' on this server.'));
        $quota = self::quotaBytes($user);
        if ($quota > 0 && self::usedBytes((string)$user['id']) + $size > $quota) throw new DomainException($anon ? 'Anonymous Drop storage is full right now. Try again later.' : 'This would exceed your Drop storage (' . round($quota / 1048576) . ' MB). Delete some items or wait for them to expire.');
        if ($anon) {
            $open = (int)self::q("SELECT COUNT(*) FROM vaultcast_drop_files WHERE user_id LIKE 'anon:%' AND origin=? AND complete=0 AND purged=0 AND created_at>?", [self::origin(), time() - 86400])->fetchColumn();
            if ($open >= 6) throw new DomainException('Finish or cancel your other uploads first.');
        }
        $free = @disk_free_space(self::dir());
        if ($free !== false && $free < $size + 536870912) throw new DomainException('The server does not have enough free space for this file.');
        list($exp, $max) = self::availability($b, $anon); $id = bin2hex(random_bytes(16));
        $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', (string)($b['mime'] ?? '')) ? strtolower((string)$b['mime']) : 'application/octet-stream';
        touch(self::path($id));
        self::q('INSERT INTO vaultcast_drop_files (id,user_id,kind,name,mime,bytes,received,body,complete,created_at,expires_at,max_downloads,inbox,origin) VALUES (?,?,?,?,?,?,0,NULL,0,?,?,?,?,?)',
            [$id, $user['id'], 'file', $name, mb_substr($mime, 0, 120), $size, time(), $exp, $max, (!$anon && !empty($b['inbox'])) ? 1 : 0, $anon ? self::origin() : '']);
        return ['ok' => true, 'id' => $id, 'received' => 0, 'chunk' => $lim['chunk']];
    }
    /** Network address recorded for anonymous uploads (shown to admins only). */
    private static function origin(): string { return substr(client_ip(), 0, 64); }
    public static function resume(array $user, string $id): array {
        $r = self::own($user, $id); $file = self::path($id); clearstatcache(true, $file);
        $have = is_file($file) ? (int)filesize($file) : 0;
        if ($have !== (int)$r['received'] && !(int)$r['complete']) self::q('UPDATE vaultcast_drop_files SET received=? WHERE id=?', [$have, $id]);
        return ['ok' => true, 'id' => $id, 'received' => $have, 'bytes' => (int)$r['bytes'], 'complete' => (bool)$r['complete'], 'purged' => (bool)$r['purged'], 'chunk' => self::limits($user)['chunk']];
    }
    /** Append one chunk. The offset is checked under the file lock, so parallel or retried requests cannot interleave. */
    public static function chunk(array $user, string $id, int $offset): array {
        self::requireUpload($user);
        if (function_exists('release_request_locks')) release_request_locks();
        if (function_exists('set_time_limit')) @set_time_limit(300);
        $r = self::own($user, $id);
        if ((int)$r['complete'] || (int)$r['purged']) throw new DomainException('This upload has already finished.');
        if ((int)$r['created_at'] < time() - 86400) throw new DomainException('This upload is older than a day. Start it again.');
        $file = self::path($id);
        $out = @fopen($file, 'cb'); $in = fopen('php://input', 'rb');
        if (!$in || !$out || !flock($out, LOCK_EX)) throw new DomainException('Could not write the upload.');
        try {
            $have = (int)fstat($out)['size'];
            if ($offset !== $have) return ['ok' => false, 'error' => 'Offset mismatch', 'received' => $have];
            fseek($out, $have);
            $limit = self::limits($user)['chunk'] + 1024; $written = 0;
            while (!feof($in)) {
                $buf = fread($in, 262144); if ($buf === false || $buf === '') break;
                $written += strlen($buf);
                if ($written > $limit || $have + $written > (int)$r['bytes']) { ftruncate($out, $have); throw new DomainException('That chunk is larger than expected.'); }
                if (fwrite($out, $buf) !== strlen($buf)) { ftruncate($out, $have); throw new DomainException('The server could not store this chunk (is the disk full?).'); }
            }
            fflush($out);
        } finally { flock($out, LOCK_UN); fclose($out); fclose($in); }
        $have += $written; $done = $have === (int)$r['bytes'];
        self::q('UPDATE vaultcast_drop_files SET received=?, complete=? WHERE id=?', [$have, $done ? 1 : 0, $id]);
        if ($done && (int)$r['inbox']) self::selfNotice($user, $id, (string)$r['name']);
        return ['ok' => true, 'received' => $have, 'complete' => $done] + ($done ? ['item' => self::fullRow(self::own($user, $id))] : []);
    }
    public static function update(array $user, string $id, array $b): array {
        $r = self::own($user, $id); if ((int)$r['purged']) throw new DomainException('This item was deleted.');
        $fields = []; $params = [];
        if (isset($b['expires_hours']) || isset($b['expires_days'])) { list($exp) = self::availability($b, self::isAnon($user)); $fields[] = 'expires_at=?'; $params[] = $exp; }
        if (array_key_exists('max_downloads', $b)) { $fields[] = 'max_downloads=?'; $params[] = max(0, min(100000, (int)$b['max_downloads'])); }
        if (isset($b['name'])) { $n = mb_substr(trim(str_replace(["\0", '/', '\\'], '', (string)$b['name'])), 0, 200); if ($n !== '') { $fields[] = 'name=?'; $params[] = $n; } }
        if (array_key_exists('inbox', $b) && !self::isAnon($user)) { $fields[] = 'inbox=?'; $params[] = empty($b['inbox']) ? 0 : 1; }
        if (!empty($b['expire_now'])) { $fields[] = 'expires_at=?'; $params[] = time() - 1; }
        if ($fields) { $params[] = $id; self::q('UPDATE vaultcast_drop_files SET ' . implode(',', $fields) . ' WHERE id=?', $params); }
        return ['ok' => true, 'item' => self::fullRow(self::own($user, $id))];
    }
    /** Owner delete removes the bytes straight away (also used to cancel an upload). */
    public static function delete(array $user, string $id): array {
        $r = self::own($user, $id); self::purge($r);
        return ['ok' => true];
    }
    private static function purge(array $r): int {
        $file = self::path((string)$r['id']); $bytes = is_file($file) ? (int)filesize($file) : 0;
        if (is_file($file)) @unlink($file);
        self::q('UPDATE vaultcast_drop_files SET purged=1, body=NULL WHERE id=?', [$r['id']]);
        self::q('UPDATE vaultcast_drop_links SET revoked=1 WHERE file_id=?', [$r['id']]);
        return $bytes + ($r['kind'] !== 'file' ? strlen((string)$r['body']) : 0);
    }
    public static function link(array $user, string $id, array $b): array {
        self::requireUpload($user); $r = self::own($user, $id);
        if (self::status($r) !== 'available') throw new DomainException('This item is no longer available.');
        $login = self::isAnon($user) ? !empty($b['require_login']) : (!array_key_exists('require_login', $b) || !empty($b['require_login']));
        if (!$login && !self::settings()['public_links']) throw new DomainException('Public links are turned off on this server; links require a sign-in.');
        $pw = (string)($b['password'] ?? '');
        if ($pw !== '' && (strlen($pw) < 4 || strlen($pw) > 72)) throw new DomainException('Use a password of 4–72 characters.');
        if ((int)self::q('SELECT COUNT(*) FROM vaultcast_drop_links WHERE file_id=?', [$id])->fetchColumn() >= 20) throw new DomainException('Up to 20 links per item.');
        $hours = (float)($b['link_hours'] ?? 0); $linkExp = $hours > 0 ? time() + (int)round(min(self::MAX_DAYS * 24, max(0.25, $hours)) * 3600) : 0;
        $token = bin2hex(random_bytes(16));
        self::q('INSERT INTO vaultcast_drop_links (token,file_id,user_id,require_login,password_hash,expires_at,max_downloads,downloads,revoked,created_at) VALUES (?,?,?,?,?,?,0,0,0,?)',
            [$token, $id, $user['id'], $login ? 1 : 0, $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : '', $linkExp, time()]);
        return ['ok' => true, 'item' => self::fullRow(self::own($user, $id)), 'token' => $token, 'url' => self::shareUrl($token)];
    }
    private static function ownLink(array $user, string $token): array {
        $l = self::q('SELECT * FROM vaultcast_drop_links WHERE token=?', [$token])->fetch(PDO::FETCH_ASSOC);
        if (!$l || ($l['user_id'] !== $user['id'] && ($user['role'] ?? '') !== 'admin')) throw new DomainException('Link not found.');
        return $l;
    }
    public static function revoke(array $user, string $token): array {
        $l = self::ownLink($user, $token);
        self::q('UPDATE vaultcast_drop_links SET revoked=1 WHERE token=?', [$token]);
        return ['ok' => true, 'item' => self::fullRow(self::own($user, (string)$l['file_id']))];
    }
    /** Extend (or limit) one link's own lifetime; 0 hours means the link lasts as long as the item. */
    public static function extendLink(array $user, string $token, array $b): array {
        $l = self::ownLink($user, $token);
        if ((int)$l['revoked']) throw new DomainException('This link was revoked. Create a new one.');
        $hours = (float)($b['hours'] ?? 0);
        $exp = $hours > 0 ? time() + (int)round(min(self::MAX_DAYS * 24, max(0.25, $hours)) * 3600) : 0;
        self::q('UPDATE vaultcast_drop_links SET expires_at=? WHERE token=?', [$exp, $token]);
        return ['ok' => true, 'item' => self::fullRow(self::own($user, (string)$l['file_id']))];
    }

    // ---------------------------------------------------------------- downloads
    /** Atomically take one download; false once the item is expired, exhausted or deleted. */
    private static function take(string $id): bool {
        return self::q('UPDATE vaultcast_drop_files SET downloads=downloads+1 WHERE id=? AND purged=0 AND complete=1 AND (expires_at=0 OR expires_at>?) AND (max_downloads=0 OR downloads<max_downloads)', [$id, time()])->rowCount() === 1;
    }
    /**
     * Send a stored file with byte ranges (resumable). Only images, audio, video and PDFs may render inline;
     * everything else is an attachment, and nosniff plus a locked-down CSP stop the browser from executing it.
     * nginx serves the bytes (X-Accel) when the admin enabled it for Drop; otherwise PHP streams them with locks released.
     */
    private static function stream(array $r, bool $inline = false, bool $textPreview = false): void {
        $file = self::path((string)$r['id']);
        if (!is_file($file)) { http_response_code(410); header('Content-Type: text/plain'); exit('This file is no longer available.'); }
        while (ob_get_level()) @ob_end_clean();
        $kind = self::previewKind((string)$r['mime'], (int)$r['bytes']);
        $safeInline = $inline && in_array($kind, ['image', 'video', 'audio', 'pdf'], true) && !preg_match('#svg|html|xml#i', (string)$r['mime']);
        $asText = $inline && $textPreview && $kind === 'text';
        $mime = $asText ? 'text/plain; charset=utf-8' : ($safeInline ? (string)$r['mime'] : 'application/octet-stream');
        $ascii = preg_replace('/[^\x20-\x7e]|["\\\\;]/', '_', (string)$r['name']);
        header('Content-Disposition: ' . ($safeInline || $asText ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode((string)$r['name']));
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'" . ($kind === 'pdf' ? '' : '; sandbox'));
        header('Cross-Origin-Resource-Policy: same-origin');
        $s = self::settings();
        if ($s['nginx_downloads'] && !empty(Store::settings()['streaming']['nginx_accel']) && DIRECTORY_SEPARATOR !== '\\') {
            if (function_exists('release_request_locks')) release_request_locks();
            header('Content-Type: ' . $mime); header('Accept-Ranges: bytes');
            $real = str_replace('\\', '/', (string)(realpath($file) ?: $file));
            header('X-Accel-Redirect: /_vaultcast_media/' . implode('/', array_map('rawurlencode', explode('/', ltrim($real, '/')))));
            exit;
        }
        Stream::sendFile($file, $mime);
        exit;
    }
    public static function ownerDownload(array $user, string $id, bool $inline): void {
        if (!self::isAnon($user)) Arcade::signed($user);
        $r = self::own($user, $id);
        $st = self::status($r);
        if ($st !== 'available') { http_response_code(410); header('Content-Type: text/plain'); exit('This item is ' . $st . '.'); }
        if ($r['kind'] !== 'file') { header('Content-Type: text/plain; charset=utf-8'); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '_', (string)$r['name']) . '.txt"'); exit((string)$r['body']); }
        self::stream($r, $inline, $inline);
    }
    /**
     * Several of the owner's files as one ZIP, streamed (stored, no compression, no temp file) with an exact
     * Content-Length. Kept under 4 GiB so plain ZIP headers suffice (no ZIP64).
     */
    public static function zip(array $user, array $ids): void {
        if (!self::isAnon($user)) Arcade::signed($user);
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function ($i) { return (bool)preg_match('/^[a-f0-9]{32}$/D', $i); })));
        if (!$ids || count($ids) > 200) throw new DomainException('Choose up to 200 files.');
        $rows = self::q('SELECT * FROM vaultcast_drop_files WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND user_id=? AND kind=\'file\'', array_merge($ids, [$user['id']]))->fetchAll(PDO::FETCH_ASSOC);
        $files = []; $names = []; $total = 22;
        foreach ($rows as $r) {
            if (self::status($r) !== 'available') continue;
            $path = self::path((string)$r['id']); if (!is_file($path)) continue;
            $size = (int)filesize($path);
            $base = preg_replace('/[\x00-\x1f\x7f\\\\\/:*?"<>|]+/', '_', (string)$r['name']) ?: 'file'; $name = $base; $n = 1;
            while (isset($names[mb_strtolower($name)])) { $dot = strrpos($base, '.'); $name = $dot ? substr($base, 0, $dot) . ' (' . (++$n) . ')' . substr($base, $dot) : $base . ' (' . (++$n) . ')'; }
            $names[mb_strtolower($name)] = 1;
            $files[] = ['path' => $path, 'name' => $name, 'size' => $size, 'time' => (int)$r['created_at']];
            $total += 30 + strlen($name) + $size + 16 + 46 + strlen($name);
        }
        if (!$files) throw new DomainException('None of those files are available.');
        if ($total >= 4294967295 || count($files) > 65535) throw new DomainException('Together these files are too large for one ZIP (4 GB). Download them separately.');
        if (function_exists('release_request_locks')) release_request_locks();
        @set_time_limit(0); while (ob_get_level()) @ob_end_clean();
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="drop-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . $total); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        $offset = 0; $central = '';
        foreach ($files as $f) {
            $t = getdate($f['time'] ?: time());
            $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
            $dosDate = ((max(1980, $t['year']) - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0808, 0, $dosTime, $dosDate, 0, 0, 0, strlen($f['name']), 0) . $f['name'];
            echo $local; $h = hash_init('crc32b'); $fh = fopen($f['path'], 'rb'); $sent = 0;
            while ($fh && !feof($fh) && $sent < $f['size']) { $buf = fread($fh, (int)min(262144, $f['size'] - $sent)); if ($buf === false || $buf === '') break; hash_update($h, $buf); echo $buf; $sent += strlen($buf); if (connection_aborted()) { fclose($fh); exit; } }
            if ($fh) fclose($fh);
            // A file that shrank underneath us would corrupt the archive: pad with zeros so offsets stay right.
            while ($sent < $f['size']) { $pad = str_repeat("\0", (int)min(262144, $f['size'] - $sent)); hash_update($h, $pad); echo $pad; $sent += strlen($pad); }
            $crc = (int)hexdec(hash_final($h));
            echo pack('VVVV', 0x08074b50, $crc, $f['size'], $f['size']);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0808, 0, $dosTime, $dosDate, $crc, $f['size'], $f['size'], strlen($f['name']), 0, 0, 0, 0, 0, $offset) . $f['name'];
            $offset += strlen($local) + $f['size'] + 16;
            flush();
        }
        echo $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
        exit;
    }

    // ---------------------------------------------------------------- share page
    private static function linkRow(string $token): ?array {
        $row = self::q('SELECT l.token, l.require_login, l.password_hash, l.revoked, l.expires_at link_expires_at, l.user_id link_owner, f.* FROM vaultcast_drop_links l JOIN vaultcast_drop_files f ON f.id=l.file_id WHERE l.token=?', [$token])->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    private static function unlocked(string $token): bool {
        return hash_equals(hash_hmac('sha256', $token, self::secret()), (string)($_COOKIE['vc_drop_' . substr($token, 0, 8)] ?? ''));
    }
    private static function secret(): string {
        $s = \Vaultcast\Repository::document('drop-secret', null);
        if (!is_string($s) || strlen($s) < 32) { $s = bin2hex(random_bytes(32)); \Vaultcast\Repository::putDocument('drop-secret', $s); }
        return $s;
    }
    /** Download grant: after a POST counts a download, this cookie lets the same browser fetch (and resume) it for a few hours. */
    private static function grantCookie(string $token): string { return 'vc_dg_' . substr($token, 0, 8); }
    private static function granted(string $token, string $fileId): bool {
        $v = (string)($_COOKIE[self::grantCookie($token)] ?? '');
        if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/D', $v, $m) || (int)$m[1] < time()) return false;
        return hash_equals(hash_hmac('sha256', 'grant|' . $token . '|' . $fileId . '|' . $m[1], self::secret()), $m[2]);
    }
    private static function grant(string $token, string $fileId): void {
        $exp = time() + 6 * 3600;
        setcookie(self::grantCookie($token), $exp . '.' . hash_hmac('sha256', 'grant|' . $token . '|' . $fileId . '|' . $exp, self::secret()),
            ['expires' => $exp, 'path' => self::shareUrl($token) . '/download', 'httponly' => true, 'samesite' => 'Lax', 'secure' => self::secure()]);
    }
    public static function share(string $token, string $method, bool $download): void {
        $row = preg_match('/^[a-f0-9]{32}$/D', $token) ? self::linkRow($token) : null;
        $viewer = current_or_guest(); $signed = ($viewer['id'] ?? 'public') !== 'public' && Access::state($viewer)['active'];
        $error = ''; $status = $row ? self::status($row) : 'missing';
        if (!$row || (int)$row['revoked'] || !self::settings()['enabled']) $status = 'missing';
        if ($status === 'available' && (int)$row['link_expires_at'] && (int)$row['link_expires_at'] <= time()) $status = 'expired';
        // Links to anonymous uploads stop working when the admin turns anonymous Drop off.
        if ($status === 'available' && strpos((string)$row['user_id'], 'anon:') === 0 && !self::anonEnabled()) $status = 'missing';
        if ($status === 'available' && (int)$row['require_login'] && !$signed) { self::page($row, 'login', ''); }
        if ($status === 'available' && $row['password_hash'] !== '' && !self::unlocked($token)) {
            if ($method === 'POST' && isset($_POST['password'])) {
                $limit = \Vaultcast\Repository::cacheGet('drop-pw:' . $token) ?: ['n' => 0];
                if ((int)$limit['n'] >= 10) $error = 'Too many attempts. Try again in 15 minutes.';
                elseif (password_verify((string)$_POST['password'], (string)$row['password_hash'])) {
                    setcookie('vc_drop_' . substr($token, 0, 8), hash_hmac('sha256', $token, self::secret()), ['expires' => time() + 3600, 'path' => self::shareUrl($token), 'httponly' => true, 'samesite' => 'Lax', 'secure' => self::secure()]);
                    header('Location: ' . self::shareUrl($token), true, 303); exit;
                } else { \Vaultcast\Repository::cachePut('drop-pw:' . $token, ['n' => (int)$limit['n'] + 1], 900); $error = 'That password is not right.'; }
            }
            self::page($row, 'password', $error);
        }
        if ($status !== 'available') self::page($row, $status === 'missing' ? 'missing' : $status, '');
        $owner = $signed && $viewer['id'] === $row['user_id'];
        if ($download && $method === 'POST' && $row['kind'] === 'file') {
            // Count the download once, then hand over to a resumable GET bound to this browser.
            // A browser that already holds a grant (retry, resume) does not use up another download.
            if (!$owner && !self::granted($token, (string)$row['id'])) {
                if (!self::take((string)$row['id'])) self::page($row, 'exhausted', '');
                self::q('UPDATE vaultcast_drop_links SET downloads=downloads+1 WHERE token=?', [$token]);
                self::grant($token, (string)$row['id']);
            }
            header('Cache-Control: no-store'); header('Location: ' . self::shareUrl($token) . '/download', true, 303); exit;
        }
        if ($download && in_array($method, ['GET', 'HEAD'], true) && $row['kind'] === 'file') {
            if ($owner || self::granted($token, (string)$row['id'])) self::stream($row, false);
            self::page($row, 'ready', '');
        }
        if ($download && $method === 'POST') {
            if (!$owner && !self::take((string)$row['id'])) self::page($row, 'exhausted', '');
            self::q('UPDATE vaultcast_drop_links SET downloads=downloads+1 WHERE token=?', [$token]);
            header('Content-Type: text/plain; charset=utf-8'); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: attachment; filename="note.txt"'); exit((string)$row['body']);
        }
        if ($row['kind'] !== 'file' && $method === 'POST' && !empty($_POST['reveal'])) {
            if (!$owner && !self::take((string)$row['id'])) self::page($row, 'exhausted', '');
            self::q('UPDATE vaultcast_drop_links SET downloads=downloads+1 WHERE token=?', [$token]);
            self::page(self::linkRow($token) ?: $row, 'text', '');
        }
        self::page($row, 'ready', '');
    }
    private static function css(): string {
        return ':root{color-scheme:dark;--bg:#0b0f1a;--bg2:#1c2a4a;--card:rgba(255,255,255,.06);--line:rgba(255,255,255,.12);--text:#eef2ff;--muted:#8d96b3;--soft:#c7cee5;--a1:#5df4c1;--a2:#59e9ff;--err:#ff9b9b}'
            . '@media (prefers-color-scheme:light){:root{color-scheme:light;--bg:#eef2f8;--bg2:#d9e6ff;--card:rgba(255,255,255,.85);--line:rgba(10,20,40,.12);--text:#0d1424;--muted:#5b6680;--soft:#2d3854;--a1:#0fbf8c;--a2:#1a9fd6;--err:#c0392b}}'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:radial-gradient(circle at 20% 0,var(--bg2),var(--bg) 60%);color:var(--text)}'
            . '.card{width:min(92vw,560px);box-sizing:border-box;padding:clamp(20px,5vw,32px);border-radius:24px;background:var(--card);border:1px solid var(--line);backdrop-filter:blur(18px);box-shadow:0 30px 80px rgba(0,0,0,.35);text-align:center}'
            . 'h1{font-size:1.4rem;margin:.4em 0;word-break:break-word}p{color:var(--soft)}.muted{color:var(--muted);font-size:.9rem}.err{color:var(--err)}.ico{font-size:2.6rem}'
            . '.btn{display:inline-block;margin-top:12px;padding:14px 28px;min-height:48px;box-sizing:border-box;border-radius:999px;border:0;background:linear-gradient(135deg,var(--a1),var(--a2));color:#06121a;font-weight:700;font-size:1rem;cursor:pointer;text-decoration:none}'
            . '.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--line)}.btn:disabled{opacity:.5;cursor:default}'
            . 'input,select{width:100%;box-sizing:border-box;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(0,0,0,.2);color:inherit;font-size:1rem;margin-top:8px}'
            . 'pre{text-align:left;white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,.2);padding:16px;border-radius:14px;max-height:50vh;overflow:auto}.link{color:var(--a2);word-break:break-all}.brand{margin-top:22px;font-size:.8rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase}'
            . '@media (max-width:480px){body{place-items:start center;padding-top:4vh}.btn{width:100%}}';
    }
    private static function page(?array $r, string $mode, string $error): void {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $codes = ['missing' => 404, 'expired' => 410, 'exhausted' => 410, 'deleted' => 410, 'uploading' => 409];
        http_response_code($codes[$mode] ?? 200);
        $nonce = base64_encode(random_bytes(12));
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; img-src 'self' data:; script-src 'nonce-" . $nonce . "'; frame-ancestors 'none'; base-uri 'none'");
        $title = $r && $mode !== 'missing' ? (string)$r['name'] : 'Drop';
        $size = function ($b) { $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = (float)$b; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, $i ? 1 : 0) . ' ' . $u[$i]; };
        $home = $e(\Vaultcast\Database::basePath());
        $body = '';
        if ($mode === 'missing') $body = '<h1>Link not found</h1><p>This share link does not exist or was revoked.</p>';
        elseif (in_array($mode, ['expired', 'exhausted', 'deleted', 'uploading'], true)) $body = '<h1>No longer available</h1><p>' . ($mode === 'exhausted' ? 'This item reached its download limit.' : ($mode === 'uploading' ? 'This upload has not finished yet.' : 'This item has expired.')) . '</p>';
        elseif ($mode === 'login') $body = '<h1>' . $e($title) . '</h1><p>Sign in to this server to open this item.</p><a class="btn" href="' . $home . '">Sign in</a><p class="muted">Then open this link again.</p>';
        elseif ($mode === 'password') $body = '<h1>' . $e($title) . '</h1><p>This item is protected with a password.</p>' . ($error ? '<p class="err">' . $e($error) . '</p>' : '') . '<form method="post"><input type="password" name="password" autocomplete="current-password" placeholder="Password" required autofocus><button class="btn" type="submit">Unlock</button></form>';
        elseif ($mode === 'text') {
            $text = (string)$r['body'];
            $body = '<h1>' . $e($title) . '</h1>' . ($r['kind'] === 'link' ? '<p><a class="link" rel="noopener noreferrer nofollow" href="' . $e($text) . '">' . $e($text) . '</a></p><a class="btn" rel="noopener noreferrer nofollow" href="' . $e($text) . '">Open link</a>'
                : '<pre id="t">' . $e($text) . '</pre><button class="btn" type="button" id="copy">Copy text</button><script nonce="' . $nonce . '">document.getElementById("copy").addEventListener("click",function(){var b=this;navigator.clipboard&&navigator.clipboard.writeText(document.getElementById("t").textContent).then(function(){b.textContent="Copied"})});</script>');
        } else {
            $left = []; $until = (int)$r['expires_at']; if ((int)($r['link_expires_at'] ?? 0)) $until = $until ? min($until, (int)$r['link_expires_at']) : (int)$r['link_expires_at'];
            if ($until) $left[] = 'Available until ' . date('j M Y, H:i', $until);
            if ((int)$r['max_downloads']) $left[] = max(0, (int)$r['max_downloads'] - (int)$r['downloads']) . ' of ' . (int)$r['max_downloads'] . ' downloads left';
            $what = $r['kind'] === 'file' ? $e($size($r['bytes'])) . ' · ' . $e($r['mime']) : ($r['kind'] === 'link' ? 'Shared link' : 'Shared note');
            $kind = $r['kind'] === 'file' ? self::previewKind((string)$r['mime'], (int)$r['bytes']) : null;
            $icon = $r['kind'] === 'file' ? ['image' => '🖼️', 'video' => '🎬', 'audio' => '🎵', 'pdf' => '📄', 'text' => '📝'][$kind] ?? '📦' : ($r['kind'] === 'link' ? '🔗' : '📝');
            $body = '<div class="ico">' . $icon . '</div><h1>' . $e($title) . '</h1><p>' . $what . '</p>' . ($left ? '<p class="muted">' . $e(implode(' · ', $left)) . '</p>' : '')
                . '<form method="post" action="' . $e(self::shareUrl((string)$r['token'])) . ($r['kind'] === 'file' ? '/download' : '') . '">' . ($r['kind'] === 'file' ? '' : '<input type="hidden" name="reveal" value="1">') . '<button class="btn" type="submit">' . ($r['kind'] === 'file' ? 'Download' : 'Show') . '</button></form>'
                . ($r['kind'] === 'file' && (int)$r['bytes'] > 104857600 ? '<p class="muted">Large file: if the download stops, open this page again within a few hours and it resumes without using another download.</p>' : '');
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $e($title) . ' · Drop</title><style>'
            . self::css() . '</style></head><body><main class="card">' . $body . '<div class="brand">Vaultcast Drop</div></main></body></html>';
        exit;
    }

    // ---------------------------------------------------------------- admin
    private static function cleanable(): string {
        return 'purged=0 AND ((expires_at>0 AND expires_at<=?) OR (max_downloads>0 AND downloads>=max_downloads) OR (complete=0 AND created_at<?))';
    }
    public static function reclaimable(): array {
        $now = time();
        $r = self::q('SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN kind=\'file\' THEN received ELSE bytes END),0) b FROM vaultcast_drop_files WHERE ' . self::cleanable(), [$now, $now - 86400])->fetch(PDO::FETCH_ASSOC);
        return ['items' => (int)$r['n'], 'bytes' => (int)$r['b']];
    }
    /** Maintenance task: delete the bytes of expired, exhausted and abandoned items. */
    public static function cleanup(?callable $progress = null): array {
        $now = time(); $rows = self::q('SELECT * FROM vaultcast_drop_files WHERE ' . self::cleanable() . ' LIMIT 5000', [$now, $now - 86400])->fetchAll(PDO::FETCH_ASSOC);
        $bytes = 0; $n = 0;
        foreach ($rows as $i => $r) { $bytes += self::purge($r); $n++; if ($progress) $progress($i + 1, count($rows), (string)$r['name']); }
        // Forget records deleted more than 30 days ago.
        $old = self::q('SELECT id FROM vaultcast_drop_files WHERE purged=1 AND created_at<?', [$now - 86400 * 60])->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_chunk($old, 200) as $ids) { $in = implode(',', array_fill(0, count($ids), '?')); self::q('DELETE FROM vaultcast_drop_links WHERE file_id IN (' . $in . ')', $ids); self::q('DELETE FROM vaultcast_drop_files WHERE id IN (' . $in . ')', $ids); }
        // Stray files without a record (for example after a database restore).
        $stray = 0;
        foreach (glob(self::dir() . '/*/*.bin') ?: [] as $f) {
            $id = basename($f, '.bin');
            if (filemtime($f) < $now - 86400 && !self::q('SELECT 1 FROM vaultcast_drop_files WHERE id=? AND purged=0', [$id])->fetchColumn()) { $bytes += (int)filesize($f); @unlink($f); $stray++; }
        }
        return ['ok' => true, 'deleted' => $n, 'stray' => $stray, 'bytes' => $bytes];
    }
    public static function overview(): array {
        $users = self::q("SELECT CASE WHEN user_id LIKE 'anon:%' THEN 'anon:' ELSE user_id END uid, COUNT(*) items, COALESCE(SUM(CASE WHEN kind='file' THEN received ELSE bytes END),0) bytes FROM vaultcast_drop_files WHERE purged=0 GROUP BY uid ORDER BY bytes DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $items = self::q('SELECT * FROM vaultcast_drop_files WHERE purged=0 ORDER BY created_at DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
        $people = Arcade::people(array_merge(array_column($users, 'uid'), array_column($items, 'user_id')));
        $anonCard = ['id' => '', 'name' => 'Anonymous visitor', 'avatar_url' => null, 'anonymous' => true];
        $total = self::q('SELECT COALESCE(SUM(CASE WHEN kind=\'file\' THEN received ELSE bytes END),0) FROM vaultcast_drop_files WHERE purged=0')->fetchColumn();
        $free = @disk_free_space(self::dir());
        $last = \Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'maintenance-job:%' AND payload LIKE '%\"task\":\"drop-cleanup\"%' ORDER BY name DESC LIMIT 1");
        return ['ok' => true, 'settings' => self::settings(), 'notes' => self::settingNotes(), 'roles' => array_merge(['user' => ['name' => 'User']], Permissions::roles()), 'total_bytes' => (int)$total, 'free_bytes' => $free === false ? null : (int)$free,
            'reclaimable' => self::reclaimable(), 'last_cleanup' => $last[0] ?? null, 'limits' => self::limits(), 'anon_used' => self::anonUsed(), 'anon_url' => rtrim(\Vaultcast\Database::basePath(), '/') . '/api/drop/anon',
            'users' => array_map(function ($u) use ($people, $anonCard) { return ['user' => $u['uid'] === 'anon:' ? $anonCard : ($people[$u['uid']] ?? Arcade::person(null)), 'items' => (int)$u['items'], 'bytes' => (int)$u['bytes']]; }, $users),
            'items' => array_map(function ($r) use ($people, $anonCard) {
                $p = self::publicRow($r); unset($p['body']);
                $anon = strpos((string)$r['user_id'], 'anon:') === 0;
                $p['owner'] = $anon ? $anonCard : ($people[$r['user_id']] ?? Arcade::person(null));
                $p['anonymous'] = $anon; if ($anon) $p['origin'] = (string)($r['origin'] ?? '');
                return $p;
            }, $items)];
    }

    // ---------------------------------------------------------------- anonymous Drop page
    /** Self-contained upload page for visitors without an account. Exposes nothing but Drop. */
    public static function anonPage(): void {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $nonce = base64_encode(random_bytes(12));
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; script-src 'nonce-" . $nonce . "'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        if (!self::anonEnabled()) {
            http_response_code(404);
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Drop</title><style>' . self::css() . '</style></head><body><main class="card"><h1>Not available</h1><p>Anonymous uploads are turned off on this server.</p><div class="brand">Vaultcast Drop</div></main></body></html>';
            exit;
        }
        self::anonUser(true);
        $api = rtrim(\Vaultcast\Database::basePath(), '/') . '/api/drop/anon/';
        $css = self::css() . '.card{text-align:left;width:min(94vw,720px)}h1{text-align:center}.drop{border:2px dashed var(--line);border-radius:18px;padding:28px 16px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s}.drop.over{border-color:var(--a2);background:rgba(89,233,255,.08)}'
            . '.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.row>*{flex:1 1 160px}.list{list-style:none;padding:0;margin:16px 0 0;display:grid;gap:10px}.item{border:1px solid var(--line);border-radius:14px;padding:12px 14px;display:grid;gap:6px}'
            . '.name{font-weight:600;word-break:break-word}.bar{height:8px;border-radius:99px;background:rgba(127,127,127,.25);overflow:hidden}.bar>i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--a1),var(--a2));transition:width .2s}'
            . '.acts{display:flex;gap:8px;flex-wrap:wrap}.acts .btn{margin:0;padding:8px 16px;min-height:40px;font-size:.9rem;width:auto}.share{font-size:.85rem;word-break:break-all;color:var(--a2)}.note{font-size:.85rem;color:var(--muted);text-align:center}';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Drop · send a file</title><style>' . $css . '</style></head><body><main class="card">'
            . '<h1>Send a file</h1><p class="note" id="limits"></p>'
            . '<div class="drop" id="drop" tabindex="0" role="button" aria-label="Choose files to upload"><div class="ico">📤</div><p><strong>Drop files here</strong> or tap to choose. You can also paste.</p><input type="file" id="pick" multiple hidden></div>'
            . '<div class="row" style="margin-top:12px"><label>Keep for<select id="exp"></select></label></div>'
            . '<ul class="list" id="list"></ul><p class="note">Only this browser can see the files it uploaded. Anyone with a share link can download the file until it expires.</p>'
            . '<div class="brand" style="text-align:center">Vaultcast Drop</div></main>'
            . '<script nonce="' . $nonce . '">window.DROP_API=' . json_encode($api) . ';</script><script nonce="' . $nonce . '">' . self::anonScript() . '</script></body></html>';
        exit;
    }
    private static function anonScript(): string {
        return <<<'JS'
(function(){
  'use strict';
  var API = window.DROP_API, $ = function(id){ return document.getElementById(id); }, state = null, active = {};
  function fmt(b){ var u=['B','KB','MB','GB','TB'], i=0; b=+b||0; while(b>=1024&&i<4){b/=1024;i++;} return (i?b.toFixed(1):b)+' '+u[i]; }
  function dur(s){ s=Math.max(0,Math.round(s)); if(s<60) return s+'s'; if(s<3600) return Math.floor(s/60)+'m '+(s%60)+'s'; return Math.floor(s/3600)+'h '+Math.floor(s%3600/60)+'m'; }
  function el(tag, props, kids){ var n=document.createElement(tag); props=props||{}; for (var k in props){ if(k==='text') n.textContent=props[k]; else if(k==='cls') n.className=props[k]; else if(k.slice(0,2)==='on') n.addEventListener(k.slice(2), props[k]); else n.setAttribute(k, props[k]); } (kids||[]).forEach(function(c){ if(c) n.appendChild(c); }); return n; }
  function call(method, path, body){
    return fetch(API+path, { method:method, credentials:'same-origin', headers: body ? {'Content-Type':'application/json'} : {}, body: body ? JSON.stringify(body) : undefined })
      .then(function(r){ return r.json().catch(function(){ return { ok:false, error:'The server answered with HTTP '+r.status+'.' }; }).then(function(j){ if(!r.ok && j.ok!==false) j.ok=false; j.status=r.status; return j; }); });
  }
  function load(){
    return call('GET','state').then(function(s){
      if (!s.ok && !s.items) { $('limits').textContent = s.error || 'Drop is not available.'; return; }
      state = s;
      $('limits').textContent = 'Up to '+fmt(s.limits.max_file)+' per file · '+fmt(Math.max(0,s.quota-s.used))+' free for anonymous uploads';
      var sel=$('exp'); if (!sel.options.length) Object.keys(s.presets).forEach(function(h){ var o=el('option',{value:h,text:s.presets[h]}); if(+h===24) o.selected=true; sel.appendChild(o); });
      render();
    });
  }
  function render(){
    var list=$('list'); list.textContent='';
    Object.keys(active).forEach(function(k){ list.appendChild(active[k].node); });
    (state && state.items || []).forEach(function(it){ if (Object.keys(active).some(function(k){ return active[k].id===it.id; })) return; list.appendChild(itemNode(it)); });
  }
  function itemNode(it){
    var acts=el('div',{cls:'acts'}), share=el('div',{cls:'share'});
    if (it.status==='available') {
      acts.appendChild(el('a',{cls:'btn ghost',href:API+'items/'+it.id+'/download',text:'Download'}));
      acts.appendChild(el('button',{cls:'btn',type:'button',text:it.links&&it.links.filter(function(l){return !l.revoked;}).length?'New link':'Share link',onclick:function(){ link(it, share); }}));
    }
    acts.appendChild(el('button',{cls:'btn ghost',type:'button',text:it.status==='uploading'?'Cancel':'Delete',onclick:function(){ if(confirm('Delete '+it.name+'?')) call('DELETE','items/'+it.id).then(load); }}));
    (it.links||[]).filter(function(l){ return !l.revoked; }).slice(0,1).forEach(function(l){ showLink(share, location.origin + l.url); });
    var meta = it.status==='available' ? fmt(it.bytes)+' · until '+new Date(it.expires_at*1000).toLocaleString()+' · '+it.downloads+' download'+(it.downloads===1?'':'s') : it.status==='uploading' ? 'Unfinished upload ('+fmt(it.received)+' of '+fmt(it.bytes)+') — choose the same file again to resume' : it.status;
    return el('li',{cls:'item'},[el('div',{cls:'name',text:it.name}), el('div',{cls:'note',style:'text-align:left',text:meta}), share, acts]);
  }
  function showLink(box, url){
    box.textContent=''; box.appendChild(el('span',{text:url}));
    var acts=el('div',{cls:'acts',style:'margin-top:6px'});
    acts.appendChild(el('button',{cls:'btn ghost',type:'button',text:'Copy',onclick:function(){ var b=this; navigator.clipboard&&navigator.clipboard.writeText(url).then(function(){ b.textContent='Copied'; }); }}));
    if (navigator.share) acts.appendChild(el('button',{cls:'btn ghost',type:'button',text:'Share…',onclick:function(){ navigator.share({ url:url }).catch(function(){}); }}));
    box.appendChild(acts);
  }
  function link(it, box){
    call('POST','items/'+it.id+'/links',{ require_login: !state.public_links }).then(function(r){
      if (!r.ok) { box.textContent = r.error || 'Could not create a link.'; return; }
      showLink(box, location.origin + r.url);
      if (!state.public_links) box.appendChild(el('div',{cls:'note',text:'This server only allows links that need a sign-in.'}));
    });
  }
  function upload(file){
    var key=file.name+'|'+file.size+'|'+file.lastModified, pend={};
    try { pend=JSON.parse(localStorage.getItem('vcDropAnonPending')||'{}'); } catch(e){}
    var settle, whenDone=new Promise(function(r){ settle=r; });
    var job={ cancelled:false, xhr:null }, bar=el('i'), info=el('div',{cls:'note',style:'text-align:left',text:'Starting…'});
    var cancel=el('button',{cls:'btn ghost',type:'button',text:'Cancel',onclick:function(){ job.cancelled=true; if(job.xhr) job.xhr.abort(); if(job.id) call('DELETE','items/'+job.id); forget(); finish(); }});
    job.node=el('li',{cls:'item'},[el('div',{cls:'name',text:file.name}), el('div',{cls:'bar'},[bar]), info, el('div',{cls:'acts'},[cancel])]);
    var tmp='u'+Math.random().toString(36).slice(2); active[tmp]=job; render();
    function forget(){ try { var m=JSON.parse(localStorage.getItem('vcDropAnonPending')||'{}'); delete m[key]; localStorage.setItem('vcDropAnonPending', JSON.stringify(m)); } catch(e){} }
    function finish(){ delete active[tmp]; settle(); load(); }
    function fail(msg){ settle(); info.textContent=msg; cancel.textContent='Dismiss'; cancel.onclick=null; cancel.addEventListener('click', finish); }
    var started=Date.now(), base=0, total=file.size;
    function progress(done){ var pct=total?done/total:1, secs=(Date.now()-started)/1000, speed=secs>0.5?(done-base)/secs:0;
      bar.style.width=(pct*100).toFixed(1)+'%'; info.textContent=fmt(done)+' of '+fmt(total)+(speed>0?' · '+fmt(speed)+'/s · '+dur((total-done)/speed)+' left':''); }
    function begin(){
      var prev=pend[key];
      return (prev ? call('GET','upload/'+prev) : Promise.resolve(null)).then(function(r){
        if (r && r.ok && !r.complete && !r.purged) return { id:prev, received:r.received, chunk:r.chunk };
        return call('POST','upload',{ name:file.name, size:file.size, mime:file.type, expires_hours: +$('exp').value || 24 });
      });
    }
    begin().then(function(r){
      if (!r.ok) return fail(r.error || 'Upload refused.');
      job.id=r.id; pend[key]=r.id; try { localStorage.setItem('vcDropAnonPending', JSON.stringify(pend)); } catch(e){}
      var offset=r.received||0, chunk=r.chunk||2097152, retries=0; base=offset; started=Date.now(); progress(offset);
      function next(){
        if (job.cancelled) return;
        if (offset>=total) { forget(); return finish(); }
        var piece=file.slice(offset, Math.min(total, offset+chunk)), x=new XMLHttpRequest(); job.xhr=x;
        x.open('POST', API+'upload/'+r.id+'?offset='+offset); x.withCredentials=true;
        x.upload.onprogress=function(ev){ progress(offset+ev.loaded); };
        x.onload=function(){ var j={}; try { j=JSON.parse(x.responseText); } catch(e){}
          if (x.status===409 && typeof j.received==='number') { offset=j.received; return next(); }
          if (x.status===429 || x.status>=500 || x.status===0) return retry(j.error);
          if (!j.ok) return fail(j.error || 'Upload failed.');
          retries=0; offset=j.received; progress(offset); next(); };
        x.onerror=function(){ retry(); };
        x.send(piece);
      }
      function retry(msg){ if (job.cancelled) return; if (++retries>8) return fail(msg || 'The connection dropped. Choose the file again to resume.'); info.textContent='Connection hiccup, retrying…'; setTimeout(next, Math.min(30000, 1000*Math.pow(2,retries))); }
      next();
    }).catch(function(){ fail('Could not reach the server.'); });
    return whenDone;
  }
  var queue=[], running=0;
  function add(files){ Array.prototype.forEach.call(files, function(f){ queue.push(f); }); pump(); }
  function pump(){ while (running<2 && queue.length) { running++; upload(queue.shift()).then(function(){ running--; pump(); }); } }
  var d=$('drop'), p=$('pick');
  d.addEventListener('click', function(){ p.click(); });
  d.addEventListener('keydown', function(e){ if (e.key==='Enter'||e.key===' ') { e.preventDefault(); p.click(); } });
  p.addEventListener('change', function(){ add(p.files); p.value=''; });
  ['dragenter','dragover'].forEach(function(t){ d.addEventListener(t, function(e){ e.preventDefault(); d.classList.add('over'); }); });
  ['dragleave','drop'].forEach(function(t){ d.addEventListener(t, function(e){ e.preventDefault(); d.classList.remove('over'); }); });
  d.addEventListener('drop', function(e){ if (e.dataTransfer && e.dataTransfer.files.length) add(e.dataTransfer.files); });
  document.addEventListener('paste', function(e){ var fs=[]; Array.prototype.forEach.call((e.clipboardData&&e.clipboardData.items)||[], function(i){ if(i.kind==='file'){ var f=i.getAsFile(); if(f) fs.push(f); } }); if (fs.length) add(fs); });
  window.addEventListener('beforeunload', function(e){ if (Object.keys(active).length) { e.preventDefault(); e.returnValue=''; } });
  load();
})();
JS;
    }
}
