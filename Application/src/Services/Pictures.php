<?php
declare(strict_types=1);

/**
 * Your pictures: per-user photo store used by Ambient ("Your pictures") and the Board picture frame.
 * Files live under data/uploads/pictures/<user hash>/ (outside the web root's executable paths, denied to
 * direct requests) and are only ever served back to their owner through PHP with nosniff and a strict CSP.
 */
final class Pictures
{
    const TYPES = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'], 'image/gif' => ['gif']];
    const EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    const MAX_FILE_MB = 25;
    const MAX_COUNT = 1000;
    const MAX_TOTAL_MB = 4096;

    private static function q(string $sql, array $p = []): PDOStatement { return Arcade::query($sql, $p); }
    private static function signed(array $user): array {
        if (($user['role'] ?? 'guest') === 'guest' || ($user['id'] ?? 'public') === 'public') throw new DomainException('Sign in to keep your own pictures.');
        return $user;
    }
    public static function canUpload(array $user): bool { return ($user['role'] ?? 'guest') !== 'guest' && Permissions::allowed($user, 'pictures.upload'); }
    private static function root(): string {
        $dir = STREAM_DATA . '/uploads/pictures';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
            @file_put_contents(STREAM_DATA . '/uploads/.htaccess', "Require all denied\nDeny from all\n<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n");
            @file_put_contents(STREAM_DATA . '/uploads/index.html', '');
        }
        return $dir;
    }
    private static function userDir(string $uid): string {
        $dir = self::root() . '/' . substr(hash('sha256', $uid), 0, 24);
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        return $dir;
    }
    private static function path(array $r): string {
        if (!preg_match('/^[a-f0-9]{16}$/D', (string)$r['id']) || !in_array((string)$r['ext'], self::EXT, true)) throw new DomainException('Picture not found.');
        return self::userDir((string)$r['user_id']) . '/' . $r['id'] . '.' . $r['ext'];
    }
    private static function publicRow(array $r): array {
        return ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'bytes' => (int)$r['bytes'], 'width' => (int)$r['width'], 'height' => (int)$r['height'], 'mime' => (string)$r['mime'],
            'created_at' => (int)$r['created_at'], 'url' => '/api/misc/pictures/' . $r['id'] . '?v=' . substr((string)$r['sha1'], 0, 8)];
    }
    public static function list(array $user): array {
        self::signed($user);
        $rows = self::q('SELECT * FROM vaultcast_pictures WHERE user_id=? ORDER BY created_at DESC, id LIMIT ' . self::MAX_COUNT, [$user['id']])->fetchAll(PDO::FETCH_ASSOC);
        $bytes = 0; foreach ($rows as $r) $bytes += (int)$r['bytes'];
        return ['ok' => true, 'pictures' => array_map([self::class, 'publicRow'], $rows), 'bytes' => $bytes, 'can_upload' => self::canUpload($user),
            'limits' => ['file_mb' => self::MAX_FILE_MB, 'count' => self::MAX_COUNT, 'total_mb' => self::MAX_TOTAL_MB, 'types' => ['jpg', 'png', 'webp', 'gif']]];
    }
    /** Detect the real type from the bytes (never trust the browser's MIME or the file name alone). */
    private static function sniff(string $file): ?string {
        $h = (string)@file_get_contents($file, false, null, 0, 16);
        if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
        if (strncmp($h, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
        if (strncmp($h, 'GIF87a', 6) === 0 || strncmp($h, 'GIF89a', 6) === 0) return 'image/gif';
        if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') return 'image/webp';
        return null;
    }
    /** Multipart upload: files[] (several at once). Each file is checked on its own; the result lists what failed. */
    public static function upload(array $user): array {
        self::signed($user);
        if (!self::canUpload($user)) throw new DomainException('Your account cannot upload pictures. Ask an administrator for the picture upload permission.');
        $files = self::files();
        if (!$files) throw new DomainException('Choose one or more pictures (JPG, PNG, WebP or GIF).');
        $stat = self::q('SELECT COUNT(*) AS n, COALESCE(SUM(bytes),0) AS b FROM vaultcast_pictures WHERE user_id=?', [$user['id']])->fetch(PDO::FETCH_ASSOC);
        $count = (int)($stat['n'] ?? 0); $total = (int)($stat['b'] ?? 0);
        $added = []; $failed = [];
        foreach ($files as $f) {
            $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', (string)$f['name']) ?? ''), 0, 200) ?: 'picture';
            try {
                if ((int)$f['error'] === UPLOAD_ERR_INI_SIZE || (int)$f['error'] === UPLOAD_ERR_FORM_SIZE) throw new DomainException('is larger than the server accepts.');
                if ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new DomainException('did not upload completely.');
                $size = (int)filesize((string)$f['tmp_name']);
                if ($size <= 0) throw new DomainException('is empty.');
                if ($size > self::MAX_FILE_MB * 1048576) throw new DomainException('is larger than ' . self::MAX_FILE_MB . ' MB.');
                if ($count >= self::MAX_COUNT) throw new DomainException('was not added: you have reached ' . self::MAX_COUNT . ' pictures.');
                if ($total + $size > self::MAX_TOTAL_MB * 1048576) throw new DomainException('was not added: your pictures would use more than ' . round(self::MAX_TOTAL_MB / 1024, 1) . ' GB.');
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $mime = self::sniff((string)$f['tmp_name']);
                if ($mime === null || !in_array($ext, self::TYPES[$mime], true)) throw new DomainException('is not a JPG, PNG, WebP or GIF picture (or its name does not match its type).');
                if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $real = $fi ? (string)finfo_file($fi, (string)$f['tmp_name']) : $mime; if ($fi) finfo_close($fi); if ($real !== $mime) throw new DomainException('does not look like a valid picture.'); }
                $info = @getimagesize((string)$f['tmp_name']);
                if (!$info || (int)$info[0] < 1 || (int)$info[1] < 1 || ($info['mime'] ?? '') !== $mime) throw new DomainException('could not be read as a picture.');
                if ((int)$info[0] * (int)$info[1] > 100000000) throw new DomainException('has too many pixels (100 megapixels at most).');
                $id = bin2hex(random_bytes(8)); $now = time();
                $row = ['id' => $id, 'user_id' => (string)$user['id'], 'ext' => self::EXT[$mime]];
                $dest = self::path($row);
                if (!@move_uploaded_file((string)$f['tmp_name'], $dest)) throw new DomainException('could not be saved on the server.');
                @chmod($dest, 0640);
                $sha = (string)sha1_file($dest);
                self::q('INSERT INTO vaultcast_pictures (id,user_id,name,ext,mime,bytes,width,height,sha1,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$id, $user['id'], $name, self::EXT[$mime], $mime, $size, (int)$info[0], (int)$info[1], $sha, $now]);
                $count++; $total += $size; $added[] = $id;
            } catch (DomainException $e) { $failed[] = ['name' => $name, 'error' => $name . ' ' . $e->getMessage()]; }
        }
        return self::list($user) + ['added' => $added, 'failed' => $failed];
    }
    /** Normalise $_FILES['files'] (array form) and $_FILES['file'] into one list. */
    private static function files(): array {
        $out = [];
        foreach (['files', 'file'] as $k) {
            if (empty($_FILES[$k])) continue;
            $f = $_FILES[$k];
            if (is_array($f['name'])) { foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE]; }
            else $out[] = ['name' => $f['name'], 'tmp_name' => $f['tmp_name'], 'error' => $f['error']];
        }
        return array_slice($out, 0, 50);
    }
    private static function own(array $user, string $id): array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) throw new DomainException('Picture not found.');
        $r = self::q('SELECT * FROM vaultcast_pictures WHERE id=? AND user_id=?', [$id, $user['id']])->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new DomainException('Picture not found.');
        return $r;
    }
    public static function delete(array $user, array $ids): array {
        self::signed($user);
        foreach (array_slice(array_unique(array_map('strval', $ids)), 0, 500) as $id) {
            $r = self::own($user, $id);
            @unlink(self::path($r));
            self::q('DELETE FROM vaultcast_pictures WHERE id=? AND user_id=?', [$id, $user['id']]);
        }
        return self::list($user);
    }
    public static function rename(array $user, string $id, string $name): array {
        self::signed($user); self::own($user, $id);
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?? ''), 0, 200);
        if ($name === '') throw new DomainException('Give the picture a name.');
        self::q('UPDATE vaultcast_pictures SET name=? WHERE id=? AND user_id=?', [$name, $id, $user['id']]);
        return self::list($user);
    }
    /** Owner-only download; images render inline, nothing else is ever stored. */
    public static function serve(array $user, string $id): void {
        // Owner only (admins included: there is no admin view of other people's pictures).
        self::signed($user); $r = self::own($user, $id); $file = self::path($r);
        if (!is_file($file)) { http_response_code(410); header('Content-Type: text/plain'); exit('This picture is no longer available.'); }
        while (ob_get_level()) @ob_end_clean();
        $etag = '"' . $r['sha1'] . '"';
        // Private and revalidated on every view (cheap 304), so a shared browser cannot keep showing it after sign-out.
        header('Cache-Control: private, no-cache');
        header('Vary: Cookie');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^\x20-\x7e]|["\\\\;]/', '_', (string)$r['name']) . '"');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
        Stream::sendFile($file, (string)$r['mime']);
        exit;
    }
    /** Ids of the user's own pictures (for checking references in saved settings). */
    public static function ownedIds(string $uid): array {
        if ($uid === '' || $uid === 'public') return [];
        try { return array_fill_keys(array_map('strval', self::q('SELECT id FROM vaultcast_pictures WHERE user_id=?', [$uid])->fetchAll(PDO::FETCH_COLUMN)), true); }
        catch (Throwable $e) { return []; }
    }
    /**
     * Remove references to pictures the user does not own from saved widget/Ambient settings: picture ids under
     * picture/pictures keys and /api/misc/pictures/<id> links in any string (lines with a foreign id are dropped).
     */
    public static function scrub(string $uid, $value, ?array &$owned = null, string $key = '') {
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1); $dropped = false;
            foreach ($value as $k => $v) {
                $value[$k] = self::scrub($uid, $v, $owned, is_string($k) ? $k : $key);
                // A list entry that only pointed at someone else's picture is removed rather than left blank.
                if (is_int($k) && is_string($v) && $v !== '' && $value[$k] === '') { unset($value[$k]); $dropped = true; }
            }
            return $isList && $dropped ? array_values($value) : $value;
        }
        if (!is_string($value)) return $value;
        $isIdKey = in_array($key, ['picture', 'pictureId', 'pictures', 'pictureIds'], true);
        if (!$isIdKey && strpos($value, 'misc/pictures/') === false) return $value;
        if ($owned === null) $owned = self::ownedIds($uid);
        if ($isIdKey && preg_match('/^[a-f0-9]{16}$/D', $value)) return isset($owned[$value]) ? $value : '';
        if (strpos($value, 'misc/pictures/') === false) return $value;
        $lines = preg_split('/(\r?\n)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE); $out = '';
        for ($i = 0; $i < count($lines); $i += 2) {
            $line = $lines[$i]; $ok = true;
            if (preg_match_all('#misc/pictures/([a-f0-9]{16})#', $line, $m)) foreach ($m[1] as $id) if (!isset($owned[$id])) $ok = false;
            if ($ok) $out .= $line . ($lines[$i + 1] ?? '');
        }
        return $out;
    }
    /** Admin overview: per-user totals for the storage picture. */
    public static function usage(): array {
        return self::q('SELECT user_id, COUNT(*) AS n, COALESCE(SUM(bytes),0) AS b FROM vaultcast_pictures GROUP BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Account deleted: remove its pictures. */
    public static function purgeUser(string $uid): void {
        foreach (self::q('SELECT * FROM vaultcast_pictures WHERE user_id=?', [$uid])->fetchAll(PDO::FETCH_ASSOC) as $r) { try { @unlink(self::path($r)); } catch (Throwable $e) {} }
        self::q('DELETE FROM vaultcast_pictures WHERE user_id=?', [$uid]);
    }
}
