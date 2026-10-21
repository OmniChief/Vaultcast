<?php
declare(strict_types=1);

/**
 * Misc → Drop: temporary private files, notes and links with resumable chunked uploads and
 * share links. Availability ends the moment an item expires or uses its last download; the
 * bytes are removed later by the admin "Delete expired Drop files" maintenance task.
 */
final class Drop {
    const CHUNK = 2097152;           // 2 MiB: well under the example nginx limit (16m) and PHP defaults
    const MAX_DAYS = 30;
    const TEXT_MAX = 20000;
    const PRESETS = [1 => '1 hour', 6 => '6 hours', 24 => '24 hours', 72 => '3 days', 168 => '7 days', 720 => '30 days'];

    public static function settings(): array {
        $d = (array)(Store::settings()['drop'] ?? []);
        return ['enabled' => !array_key_exists('enabled', $d) || !empty($d['enabled']), 'user_role_upload' => !empty($d['user_role_upload']),
            'default_quota_mb' => max(0, (int)($d['default_quota_mb'] ?? 2048)), 'quotas' => array_map('intval', (array)($d['quotas'] ?? [])),
            'max_file_mb' => max(1, (int)($d['max_file_mb'] ?? 4096)), 'public_links' => !array_key_exists('public_links', $d) || !empty($d['public_links'])];
    }
    public static function saveSettings(array $body): array {
        $all = Store::settings(); $cur = self::settings();
        $roles = array_merge(['user'], array_keys(Permissions::roles()));
        $quotas = [];
        foreach ((array)($body['quotas'] ?? $cur['quotas']) as $role => $mb) if (in_array((string)$role, $roles, true) && $mb !== '' && $mb !== null) $quotas[(string)$role] = max(0, min(10000000, (int)$mb));
        $all['drop'] = ['enabled' => !empty($body['enabled'] ?? $cur['enabled']), 'user_role_upload' => !empty($body['user_role_upload'] ?? $cur['user_role_upload']),
            'default_quota_mb' => max(0, min(10000000, (int)($body['default_quota_mb'] ?? $cur['default_quota_mb']))), 'quotas' => $quotas,
            'max_file_mb' => max(1, min(1000000, (int)($body['max_file_mb'] ?? $cur['max_file_mb']))), 'public_links' => !empty($body['public_links'] ?? $cur['public_links'])];
        Store::saveSettings($all);
        return ['ok' => true, 'settings' => self::settings()];
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
        Arcade::signed($user);
        if (!self::settings()['enabled']) throw new DomainException('Drop is turned off on this server.');
        if (!Permissions::allowed($user, 'drop.upload')) throw new DomainException('Your account cannot upload to Drop. Ask an administrator for the Drop permission.');
    }
    public static function quotaBytes(array $user): int {
        if (($user['role'] ?? '') === 'admin') return 0;
        $s = self::settings(); $mb = $s['quotas'][(string)($user['role'] ?? 'user')] ?? $s['default_quota_mb'];
        return (int)$mb * 1048576;
    }
    /** Bytes held by available (not yet expired, exhausted or purged) items. */
    public static function usedBytes(string $uid): int {
        return (int)self::q('SELECT COALESCE(SUM(GREATEST(bytes,received)),0) FROM vaultcast_drop_files WHERE user_id=? AND purged=0 AND (expires_at=0 OR expires_at>?) AND (max_downloads=0 OR downloads<max_downloads)', [$uid, time()])->fetchColumn();
    }
    private static function availability(array $b): array {
        $hours = isset($b['expires_hours']) ? (float)$b['expires_hours'] : (isset($b['expires_days']) ? 24 * (float)$b['expires_days'] : 24);
        if (!($hours > 0)) $hours = 24;
        $hours = min(self::MAX_DAYS * 24, max(0.25, $hours));
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
    private static function publicRow(array $r, bool $withLinks = true): array {
        $out = ['id' => $r['id'], 'kind' => $r['kind'], 'name' => $r['name'], 'mime' => $r['mime'], 'bytes' => (int)$r['bytes'], 'received' => (int)$r['received'],
            'created_at' => (int)$r['created_at'], 'expires_at' => (int)$r['expires_at'], 'max_downloads' => (int)$r['max_downloads'], 'downloads' => (int)$r['downloads'],
            'inbox' => (bool)$r['inbox'], 'status' => self::status($r)];
        if ($r['kind'] !== 'file' && !(int)$r['purged']) $out['body'] = (string)$r['body'];
        if ($withLinks) $out['links'] = array_map(function ($l) { return ['token' => $l['token'], 'require_login' => (bool)$l['require_login'], 'password' => $l['password_hash'] !== '', 'downloads' => (int)$l['downloads'], 'revoked' => (bool)$l['revoked'], 'created_at' => (int)$l['created_at'], 'url' => self::shareUrl($l['token'])]; },
            self::q('SELECT * FROM vaultcast_drop_links WHERE file_id=? ORDER BY created_at DESC', [$r['id']])->fetchAll(PDO::FETCH_ASSOC));
        return $out;
    }
    public static function shareUrl(string $token): string { return rtrim(\Vaultcast\Database::basePath(), '/') . '/api/drop/s/' . $token; }
    private static function own(array $user, string $id): array {
        $r = self::q('SELECT * FROM vaultcast_drop_files WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$r || ($r['user_id'] !== $user['id'] && ($user['role'] ?? '') !== 'admin')) throw new DomainException('Item not found.');
        return $r;
    }
    public static function limits(): array {
        $toBytes = function ($v) { $v = trim((string)$v); if ($v === '') return 0; $n = (float)$v; $u = strtolower(substr($v, -1)); return (int)($n * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1)))); };
        $post = $toBytes(ini_get('post_max_size'));
        $chunk = $post > 0 ? min(self::CHUNK, max(262144, $post - 65536)) : self::CHUNK;
        return ['chunk' => $chunk, 'post_max_size' => $post, 'max_file' => self::settings()['max_file_mb'] * 1048576];
    }
    public static function state(array $user): array {
        Arcade::signed($user); $uid = (string)$user['id'];
        $rows = self::q('SELECT * FROM vaultcast_drop_files WHERE user_id=? AND purged=0 ORDER BY created_at DESC LIMIT 200', [$uid])->fetchAll(PDO::FETCH_ASSOC);
        return ['ok' => true, 'can_upload' => self::canUpload($user), 'items' => array_map([self::class, 'publicRow'], $rows), 'used' => self::usedBytes($uid), 'quota' => self::quotaBytes($user),
            'limits' => self::limits(), 'presets' => self::PRESETS, 'max_days' => self::MAX_DAYS, 'public_links' => self::settings()['public_links']];
    }
    public static function createText(array $user, array $b): array {
        self::requireUpload($user);
        $kind = ($b['kind'] ?? '') === 'link' ? 'link' : 'text'; $body = trim((string)($b['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > self::TEXT_MAX) throw new DomainException('Write up to ' . number_format(self::TEXT_MAX) . ' characters.');
        if ($kind === 'link' && !preg_match('#^https?://[^\s<>"]+$#i', $body)) throw new DomainException('Links start with http:// or https://.');
        list($exp, $max) = self::availability($b); $id = bin2hex(random_bytes(16));
        $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 120) ?: ($kind === 'link' ? (string)(parse_url($body, PHP_URL_HOST) ?: 'Link') : mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 60));
        self::q('INSERT INTO vaultcast_drop_files (id,user_id,kind,name,mime,bytes,received,body,complete,created_at,expires_at,max_downloads,inbox) VALUES (?,?,?,?,?,?,?,?,1,?,?,?,?)',
            [$id, $user['id'], $kind, $name, 'text/plain', strlen($body), strlen($body), $body, time(), $exp, $max, empty($b['inbox']) ? 0 : 1]);
        if (!empty($b['inbox'])) self::selfNotice($user, $id, $name);
        return ['ok' => true, 'item' => self::publicRow(self::own($user, $id))];
    }
    private static function selfNotice(array $user, string $id, string $name): void {
        $person = Arcade::person($user);
        HomeHub::mutate('home-hub:' . $user['id'], function (array $d) use ($id, $name, $person): array {
            $nid = 'drop:' . $id;
            $d['notifications'][$nid] = ['id' => $nid, 'from' => $person, 'title' => mb_substr($name, 0, 120), 'text' => 'Sent to your devices', 'kind' => 'drop', 'action' => 'Open', 'path' => 'misc/drop', 'created_at' => time()];
            $d['notifications'] = array_slice((array)$d['notifications'], -40, null, true);
            return $d;
        });
    }
    public static function init(array $user, array $b): array {
        self::requireUpload($user);
        $size = (int)($b['size'] ?? -1); $name = trim(str_replace(["\0", '/', '\\'], '', (string)($b['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 200) throw new DomainException('The file needs a name of up to 200 characters.');
        if ($size <= 0) throw new DomainException('Empty files cannot be uploaded.');
        $lim = self::limits();
        if ($size > $lim['max_file']) throw new DomainException('Files can be up to ' . self::settings()['max_file_mb'] . ' MB on this server.');
        $quota = self::quotaBytes($user);
        if ($quota > 0 && self::usedBytes((string)$user['id']) + $size > $quota) throw new DomainException('This would exceed your Drop storage (' . round($quota / 1048576) . ' MB). Delete some items or wait for them to expire.');
        $free = @disk_free_space(self::dir());
        if ($free !== false && $free < $size + 536870912) throw new DomainException('The server does not have enough free space for this file.');
        list($exp, $max) = self::availability($b); $id = bin2hex(random_bytes(16));
        $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', (string)($b['mime'] ?? '')) ? (string)$b['mime'] : 'application/octet-stream';
        touch(self::path($id));
        self::q('INSERT INTO vaultcast_drop_files (id,user_id,kind,name,mime,bytes,received,body,complete,created_at,expires_at,max_downloads,inbox) VALUES (?,?,?,?,?,?,0,NULL,0,?,?,?,?)',
            [$id, $user['id'], 'file', $name, mb_substr($mime, 0, 120), $size, time(), $exp, $max, empty($b['inbox']) ? 0 : 1]);
        return ['ok' => true, 'id' => $id, 'received' => 0, 'chunk' => $lim['chunk']];
    }
    public static function resume(array $user, string $id): array {
        $r = self::own($user, $id); $file = self::path($id); clearstatcache(true, $file);
        $have = is_file($file) ? (int)filesize($file) : 0;
        if ($have !== (int)$r['received']) self::q('UPDATE vaultcast_drop_files SET received=? WHERE id=?', [$have, $id]);
        return ['ok' => true, 'id' => $id, 'received' => $have, 'bytes' => (int)$r['bytes'], 'complete' => (bool)$r['complete'], 'chunk' => self::limits()['chunk']];
    }
    public static function chunk(array $user, string $id, int $offset): array {
        self::requireUpload($user);
        $r = self::own($user, $id);
        if ((int)$r['complete'] || (int)$r['purged']) throw new DomainException('This upload has already finished.');
        if ((int)$r['created_at'] < time() - 86400) throw new DomainException('This upload is older than a day. Start it again.');
        $file = self::path($id); clearstatcache(true, $file); $have = is_file($file) ? (int)filesize($file) : 0;
        if ($offset !== $have) return ['ok' => false, 'error' => 'Offset mismatch', 'received' => $have];
        $in = fopen('php://input', 'rb'); $out = fopen($file, 'ab');
        if (!$in || !$out || !flock($out, LOCK_EX)) throw new DomainException('Could not write the upload.');
        $limit = self::limits()['chunk'] + 1024; $written = 0;
        while (!feof($in)) {
            $buf = fread($in, 65536); if ($buf === false || $buf === '') break;
            $written += strlen($buf);
            if ($written > $limit || $have + $written > (int)$r['bytes']) { ftruncate($out, $have); flock($out, LOCK_UN); fclose($out); throw new DomainException('That chunk is larger than expected.'); }
            fwrite($out, $buf);
        }
        fflush($out); flock($out, LOCK_UN); fclose($out); fclose($in);
        $have += $written; $done = $have === (int)$r['bytes'];
        self::q('UPDATE vaultcast_drop_files SET received=?, complete=? WHERE id=?', [$have, $done ? 1 : 0, $id]);
        if ($done && (int)$r['inbox']) self::selfNotice($user, $id, (string)$r['name']);
        return ['ok' => true, 'received' => $have, 'complete' => $done] + ($done ? ['item' => self::publicRow(self::own($user, $id))] : []);
    }
    public static function update(array $user, string $id, array $b): array {
        $r = self::own($user, $id); if ((int)$r['purged']) throw new DomainException('This item was deleted.');
        $fields = []; $params = [];
        if (isset($b['expires_hours']) || isset($b['expires_days'])) { list($exp) = self::availability($b); $fields[] = 'expires_at=?'; $params[] = $exp; }
        if (array_key_exists('max_downloads', $b)) { $fields[] = 'max_downloads=?'; $params[] = max(0, min(100000, (int)$b['max_downloads'])); }
        if (isset($b['name'])) { $n = mb_substr(trim(str_replace(["\0", '/', '\\'], '', (string)$b['name'])), 0, 200); if ($n !== '') { $fields[] = 'name=?'; $params[] = $n; } }
        if (array_key_exists('inbox', $b)) { $fields[] = 'inbox=?'; $params[] = empty($b['inbox']) ? 0 : 1; }
        if (!empty($b['expire_now'])) { $fields[] = 'expires_at=?'; $params[] = time() - 1; }
        if ($fields) { $params[] = $id; self::q('UPDATE vaultcast_drop_files SET ' . implode(',', $fields) . ' WHERE id=?', $params); }
        return ['ok' => true, 'item' => self::publicRow(self::own($user, $id))];
    }
    /** Owner delete removes the bytes straight away. */
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
        $login = !array_key_exists('require_login', $b) || !empty($b['require_login']);
        if (!$login && !self::settings()['public_links']) throw new DomainException('Public links are turned off on this server; links require a sign-in.');
        $pw = (string)($b['password'] ?? '');
        if ($pw !== '' && (strlen($pw) < 4 || strlen($pw) > 72)) throw new DomainException('Use a password of 4–72 characters.');
        if ((int)self::q('SELECT COUNT(*) FROM vaultcast_drop_links WHERE file_id=?', [$id])->fetchColumn() >= 20) throw new DomainException('Up to 20 links per item.');
        $token = bin2hex(random_bytes(16));
        self::q('INSERT INTO vaultcast_drop_links (token,file_id,user_id,require_login,password_hash,expires_at,max_downloads,downloads,revoked,created_at) VALUES (?,?,?,?,?,0,0,0,0,?)',
            [$token, $id, $user['id'], $login ? 1 : 0, $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : '', time()]);
        return ['ok' => true, 'item' => self::publicRow(self::own($user, $id)), 'token' => $token, 'url' => self::shareUrl($token)];
    }
    public static function revoke(array $user, string $token): array {
        $l = self::q('SELECT * FROM vaultcast_drop_links WHERE token=?', [$token])->fetch(PDO::FETCH_ASSOC);
        if (!$l || ($l['user_id'] !== $user['id'] && ($user['role'] ?? '') !== 'admin')) throw new DomainException('Link not found.');
        self::q('UPDATE vaultcast_drop_links SET revoked=1 WHERE token=?', [$token]);
        return ['ok' => true];
    }

    // ---------------------------------------------------------------- downloads
    /** Atomically take one download; false once the item is expired, exhausted or deleted. */
    private static function take(string $id): bool {
        return self::q('UPDATE vaultcast_drop_files SET downloads=downloads+1 WHERE id=? AND purged=0 AND complete=1 AND (expires_at=0 OR expires_at>?) AND (max_downloads=0 OR downloads<max_downloads)', [$id, time()])->rowCount() === 1;
    }
    private static function stream(array $r, bool $ranges, bool $inline = false): void {
        $file = self::path((string)$r['id']);
        if (!is_file($file)) { http_response_code(410); exit('This file is no longer available.'); }
        $size = (int)filesize($file); $start = 0; $end = $size - 1;
        while (ob_get_level()) ob_end_clean();
        $safeInline = $inline && preg_match('#^(image/(png|jpeg|gif|webp)|video/(mp4|webm)|audio/(mpeg|mp4|ogg|webm)|application/pdf)$#', (string)$r['mime']);
        header('Content-Type: ' . ($safeInline ? $r['mime'] : 'application/octet-stream'));
        $ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', (string)$r['name']);
        header('Content-Disposition: ' . ($safeInline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode((string)$r['name']));
        header('Cache-Control: private, no-store'); header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'"); header('X-Content-Type-Options: nosniff');
        if ($ranges) {
            header('Accept-Ranges: bytes');
            if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
                if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); } else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
                if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
                http_response_code(206); header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            }
        } else header('Accept-Ranges: none');
        header('Content-Length: ' . ($end - $start + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
        @set_time_limit(0); $fh = fopen($file, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
        while ($left > 0 && !feof($fh) && !connection_aborted()) { $buf = fread($fh, (int)min(262144, $left)); if ($buf === false) break; echo $buf; flush(); $left -= strlen($buf); }
        fclose($fh); exit;
    }
    public static function ownerDownload(array $user, string $id, bool $inline): void {
        Arcade::signed($user); $r = self::own($user, $id);
        $st = self::status($r);
        if ($st !== 'available') { http_response_code(410); header('Content-Type: text/plain'); exit('This item is ' . $st . '.'); }
        if ($r['kind'] !== 'file') { header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '_', (string)$r['name']) . '.txt"'); exit((string)$r['body']); }
        self::stream($r, true, $inline);
    }

    // ---------------------------------------------------------------- share page
    private static function linkRow(string $token): ?array {
        $row = self::q('SELECT l.token, l.require_login, l.password_hash, l.revoked, f.* FROM vaultcast_drop_links l JOIN vaultcast_drop_files f ON f.id=l.file_id WHERE l.token=?', [$token])->fetch(PDO::FETCH_ASSOC);
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
    public static function share(string $token, string $method, bool $download): void {
        $row = preg_match('/^[a-f0-9]{32}$/D', $token) ? self::linkRow($token) : null;
        $viewer = current_or_guest(); $signed = ($viewer['id'] ?? 'public') !== 'public' && Access::state($viewer)['active'];
        $error = ''; $status = $row ? self::status($row) : 'missing';
        if (!$row || (int)$row['revoked']) $status = 'missing';
        if ($status === 'available' && (int)$row['require_login'] && !$signed) { self::page($row, 'login', ''); }
        if ($status === 'available' && $row['password_hash'] !== '' && !self::unlocked($token)) {
            if ($method === 'POST' && isset($_POST['password'])) {
                $limit = \Vaultcast\Repository::cacheGet('drop-pw:' . $token) ?: ['n' => 0];
                if ((int)$limit['n'] >= 10) $error = 'Too many attempts. Try again in 15 minutes.';
                elseif (password_verify((string)$_POST['password'], (string)$row['password_hash'])) {
                    setcookie('vc_drop_' . substr($token, 0, 8), hash_hmac('sha256', $token, self::secret()), ['expires' => time() + 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
                    header('Location: ' . self::shareUrl($token), true, 303); exit;
                } else { \Vaultcast\Repository::cachePut('drop-pw:' . $token, ['n' => (int)$limit['n'] + 1], 900); $error = 'That password is not right.'; }
            }
            self::page($row, 'password', $error);
        }
        if ($status !== 'available') self::page($row, $status === 'missing' ? 'missing' : $status, '');
        if ($download && $method === 'POST') {
            $owner = $signed && $viewer['id'] === $row['user_id'];
            if (!$owner && !self::take((string)$row['id'])) self::page($row, 'exhausted', '');
            self::q('UPDATE vaultcast_drop_links SET downloads=downloads+1 WHERE token=?', [$token]);
            if ($row['kind'] !== 'file') { header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="note.txt"'); exit((string)$row['body']); }
            self::stream($row, false);
        }
        if ($row['kind'] !== 'file' && $method === 'POST' && !empty($_POST['reveal'])) {
            $owner = $signed && $viewer['id'] === $row['user_id'];
            if (!$owner && !self::take((string)$row['id'])) self::page($row, 'exhausted', '');
            self::page(self::linkRow($token) ?: $row, 'text', '');
        }
        self::page($row, 'ready', '');
    }
    private static function page(?array $r, string $mode, string $error): void {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $codes = ['missing' => 404, 'expired' => 410, 'exhausted' => 410, 'deleted' => 410, 'uploading' => 409];
        http_response_code($codes[$mode] ?? 200);
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; img-src 'self' data:; script-src 'unsafe-inline'");
        $title = $r && $mode !== 'missing' ? (string)$r['name'] : 'Drop';
        $size = function ($b) { $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = (float)$b; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, $i ? 1 : 0) . ' ' . $u[$i]; };
        $body = '';
        if ($mode === 'missing') $body = '<h1>Link not found</h1><p>This share link does not exist or was revoked.</p>';
        elseif (in_array($mode, ['expired', 'exhausted', 'deleted', 'uploading'], true)) $body = '<h1>No longer available</h1><p>' . ($mode === 'exhausted' ? 'This item reached its download limit.' : ($mode === 'uploading' ? 'This upload has not finished yet.' : 'This item has expired.')) . '</p>';
        elseif ($mode === 'login') $body = '<h1>' . $e($title) . '</h1><p>Sign in to this server to open this item.</p><a class="btn" href="/">Sign in</a><p class="muted">Then open this link again.</p>';
        elseif ($mode === 'password') $body = '<h1>' . $e($title) . '</h1><p>This item is protected with a password.</p>' . ($error ? '<p class="err">' . $e($error) . '</p>' : '') . '<form method="post"><input type="password" name="password" autocomplete="current-password" placeholder="Password" required autofocus><button class="btn" type="submit">Unlock</button></form>';
        elseif ($mode === 'text') {
            $text = (string)$r['body'];
            $body = '<h1>' . $e($title) . '</h1>' . ($r['kind'] === 'link' ? '<p><a class="link" rel="noopener noreferrer nofollow" href="' . $e($text) . '">' . $e($text) . '</a></p><a class="btn" rel="noopener noreferrer nofollow" href="' . $e($text) . '">Open link</a>' : '<pre id="t">' . $e($text) . '</pre><button class="btn" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'t\').textContent).then(function(){this.textContent=\'Copied\'}.bind(this))">Copy text</button>');
        } else {
            $left = []; if ((int)$r['expires_at']) $left[] = 'Available until ' . date('j M Y, H:i', (int)$r['expires_at']);
            if ((int)$r['max_downloads']) $left[] = max(0, (int)$r['max_downloads'] - (int)$r['downloads']) . ' of ' . (int)$r['max_downloads'] . ' downloads left';
            $what = $r['kind'] === 'file' ? $e($size($r['bytes'])) . ' · ' . $e($r['mime']) : ($r['kind'] === 'link' ? 'Shared link' : 'Shared note');
            $body = '<div class="ico">' . ($r['kind'] === 'file' ? '📦' : ($r['kind'] === 'link' ? '🔗' : '📝')) . '</div><h1>' . $e($title) . '</h1><p>' . $what . '</p>' . ($left ? '<p class="muted">' . $e(implode(' · ', $left)) . '</p>' : '')
                . '<form method="post" action="' . $e(self::shareUrl((string)$r['token'])) . ($r['kind'] === 'file' ? '/download' : '') . '">' . ($r['kind'] === 'file' ? '' : '<input type="hidden" name="reveal" value="1">') . '<button class="btn" type="submit">' . ($r['kind'] === 'file' ? 'Download' : 'Show') . '</button></form>';
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $e($title) . ' · Drop</title><style>'
            . ':root{color-scheme:dark}body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:radial-gradient(circle at 20% 0,#1c2a4a,#0b0f1a 60%);color:#eef2ff}'
            . '.card{width:min(92vw,520px);padding:32px;border-radius:24px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);backdrop-filter:blur(18px);box-shadow:0 30px 80px rgba(0,0,0,.45);text-align:center}'
            . 'h1{font-size:1.4rem;margin:.4em 0;word-break:break-word}p{color:#c7cee5}.muted{color:#8d96b3;font-size:.9rem}.err{color:#ff9b9b}.ico{font-size:2.6rem}'
            . '.btn{display:inline-block;margin-top:12px;padding:12px 26px;border-radius:999px;border:0;background:linear-gradient(135deg,#5df4c1,#59e9ff);color:#06121a;font-weight:700;font-size:1rem;cursor:pointer;text-decoration:none}'
            . 'input{width:100%;box-sizing:border-box;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.3);color:inherit;font-size:1rem;margin-top:8px}'
            . 'pre{text-align:left;white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,.3);padding:16px;border-radius:14px;max-height:50vh;overflow:auto}.link{color:#8fe9ff;word-break:break-all}.brand{margin-top:22px;font-size:.8rem;color:#7b84a3;letter-spacing:.12em;text-transform:uppercase}'
            . '</style></head><body><main class="card">' . $body . '<div class="brand">Vaultcast Drop</div></main></body></html>';
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
        $now = time();
        $users = self::q('SELECT user_id, COUNT(*) items, COALESCE(SUM(CASE WHEN kind=\'file\' THEN received ELSE bytes END),0) bytes FROM vaultcast_drop_files WHERE purged=0 GROUP BY user_id ORDER BY bytes DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        $people = Arcade::people(array_column($users, 'user_id'));
        $items = self::q('SELECT * FROM vaultcast_drop_files WHERE purged=0 ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        $total = self::q('SELECT COALESCE(SUM(CASE WHEN kind=\'file\' THEN received ELSE bytes END),0) FROM vaultcast_drop_files WHERE purged=0')->fetchColumn();
        $free = @disk_free_space(self::dir());
        $last = \Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'maintenance-job:%' AND payload LIKE '%\"task\":\"drop-cleanup\"%' ORDER BY name DESC LIMIT 1");
        return ['ok' => true, 'settings' => self::settings(), 'roles' => array_merge(['user' => ['name' => 'User']], Permissions::roles()), 'total_bytes' => (int)$total, 'free_bytes' => $free === false ? null : (int)$free,
            'reclaimable' => self::reclaimable(), 'last_cleanup' => $last[0] ?? null, 'limits' => self::limits(),
            'users' => array_map(function ($u) use ($people) { return ['user' => $people[$u['user_id']] ?? Arcade::person(null), 'items' => (int)$u['items'], 'bytes' => (int)$u['bytes']]; }, $users),
            'items' => array_map(function ($r) use ($people) { $p = self::publicRow($r, false); unset($p['body']); $u = Arcade::people([$r['user_id']]); $p['owner'] = $u[$r['user_id']] ?? Arcade::person(null); return $p; }, $items)];
    }
}
