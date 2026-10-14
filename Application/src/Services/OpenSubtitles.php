<?php
declare(strict_types=1);

/**
 * OpenSubtitles REST API v1: search and download a subtitle for a library video.
 * The API key (and optional account) are entered by an administrator in Settings and
 * stored in the settings document only. Downloaded subtitles are converted to WebVTT and
 * saved next to the video when the folder is writable, otherwise in data/subtitles, so they
 * are listed for every user afterwards.
 */
final class OpenSubtitles {
    const API = 'https://api.opensubtitles.com/api/v1';
    const MAX_BYTES = 2097152;

    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array [status, body]  (tests) */
    public static $http = null;
    /** @var array|null settings override (tests) */
    public static $config = null;

    public static function config(): array {
        $c = self::$config ?? (Store::settings()['opensubtitles'] ?? []);
        return is_array($c) ? $c : [];
    }

    /** Settings as sent to the admin UI: secrets replaced by *_set flags. */
    public static function maskSettings(array $settings): array {
        $c = is_array($settings['opensubtitles'] ?? null) ? $settings['opensubtitles'] : [];
        $c['api_key_set'] = trim((string)($c['api_key'] ?? '')) !== '';
        $c['password_set'] = (string)($c['password'] ?? '') !== '';
        $c['api_key'] = '';
        $c['password'] = '';
        $settings['opensubtitles'] = $c;
        return $settings;
    }

    /** Merge a settings POST: blank secrets keep the stored value; clear_* flags remove it. */
    public static function mergeSettings(array $current, array $patch): array {
        $cur = is_array($current['opensubtitles'] ?? null) ? $current['opensubtitles'] : [];
        $out = $cur;
        foreach (['username', 'languages', 'user_agent'] as $k) if (array_key_exists($k, $patch)) $out[$k] = trim((string)$patch[$k]);
        if (array_key_exists('allow_users', $patch)) $out['allow_users'] = (bool)$patch['allow_users'];
        foreach (['api_key', 'password'] as $k) {
            $v = $k === 'api_key' ? trim((string)($patch[$k] ?? '')) : (string)($patch[$k] ?? '');
            if (!empty($patch['clear_' . $k])) $out[$k] = '';
            elseif ($v !== '') $out[$k] = $v;
        }
        if (array_key_exists('languages', $out)) $out['languages'] = self::languages((string)$out['languages']);
        unset($out['api_key_set'], $out['password_set'], $out['clear_api_key'], $out['clear_password']);
        return $out;
    }

    public static function configured(): bool {
        return trim((string)(self::config()['api_key'] ?? '')) !== '';
    }

    /** May this user search/download? Admins always; others only when the admin allowed it. */
    public static function allowed(array $user): bool {
        if (!self::configured()) return false;
        return ($user['role'] ?? '') === 'admin' || !empty(self::config()['allow_users']);
    }

    private static function userAgent(): string {
        $ua = trim((string)(self::config()['user_agent'] ?? ''));
        return $ua !== '' ? $ua : 'Vaultcast v' . (defined('STREAM_VERSION') ? STREAM_VERSION : '1');
    }

    private static function request(string $method, string $url, ?array $json = null, ?string $bearer = null): array {
        $headers = ['Api-Key: ' . trim((string)(self::config()['api_key'] ?? '')), 'User-Agent: ' . self::userAgent(), 'Accept: application/json'];
        if ($json !== null) $headers[] = 'Content-Type: application/json';
        if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
        $body = $json !== null ? json_encode($json) : null;
        if (self::$http) { $r = (self::$http)($method, $url, $headers, $body); }
        elseif (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $out = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($out === false) throw new RuntimeException('OpenSubtitles could not be reached: ' . $err);
            $r = [$status, (string)$out];
        } else {
            $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => (string)$body, 'timeout' => 25, 'ignore_errors' => true]]);
            $out = @file_get_contents($url, false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
            if ($out === false) throw new RuntimeException('OpenSubtitles could not be reached.');
            $r = [$status, (string)$out];
        }
        $data = json_decode((string)$r[1], true);
        return [(int)$r[0], is_array($data) ? $data : [], (string)$r[1]];
    }

    /** Human message for an API error (quota, rate limit, bad key, ...). */
    public static function errorMessage(int $status, array $data): string {
        $msg = trim((string)($data['message'] ?? ($data['errors'][0] ?? '')));
        if ($status === 406 || (isset($data['remaining']) && (int)$data['remaining'] <= 0)) {
            $reset = trim((string)($data['reset_time'] ?? ''));
            return 'OpenSubtitles daily download limit reached' . ($reset !== '' ? ' (resets in ' . $reset . ')' : '') . '. Add an OpenSubtitles account in Settings for a higher limit, or try again later.';
        }
        if ($status === 429) return 'OpenSubtitles is rate limiting requests. Wait a few seconds and try again.';
        if ($status === 401 || $status === 403) return 'OpenSubtitles rejected the API key or account. Check Settings → Streaming → OpenSubtitles.' . ($msg !== '' ? ' (' . $msg . ')' : '');
        return 'OpenSubtitles request failed' . ($status ? ' (HTTP ' . $status . ')' : '') . ($msg !== '' ? ': ' . $msg : '.');
    }

    /** Optional account login; token cached for 12 hours. */
    private static function token(): ?string {
        $c = self::config();
        $user = trim((string)($c['username'] ?? ''));
        $pass = (string)($c['password'] ?? '');
        if ($user === '' || $pass === '') return null;
        $key = 'opensubtitles_token|' . hash('sha256', $user . '|' . $pass . '|' . ($c['api_key'] ?? ''));
        if (!self::$http) { $hit = \Vaultcast\Repository::cacheGet($key); if ($hit && !empty($hit['token'])) return (string)$hit['token']; }
        list($status, $data) = self::request('POST', self::API . '/login', ['username' => $user, 'password' => $pass]);
        if ($status !== 200 || empty($data['token'])) throw new RuntimeException(self::errorMessage($status, $data));
        if (!self::$http) \Vaultcast\Repository::cachePut($key, ['token' => (string)$data['token']], 43200);
        return (string)$data['token'];
    }

    /** OpenSubtitles movie hash (64-bit sum of size + first/last 64 KiB). Null on 32-bit PHP or short files. */
    public static function movieHash(string $path): ?string {
        if (PHP_INT_SIZE < 8) return null;
        $size = @filesize($path);
        if (!$size || $size < 131072) return null;
        $fh = @fopen($path, 'rb');
        if (!$fh) return null;
        $lo = $size & 0xFFFFFFFF; $hi = ($size >> 32) & 0xFFFFFFFF;
        foreach ([0, $size - 65536] as $offset) {
            fseek($fh, $offset);
            $chunk = (string)fread($fh, 65536);
            foreach (unpack('V*', $chunk) as $i => $word) {
                if ($i % 2) { $lo += $word; } else { $hi += $word; }
                $hi += $lo >> 32; $lo &= 0xFFFFFFFF; $hi &= 0xFFFFFFFF;
            }
        }
        fclose($fh);
        return sprintf('%08x%08x', $hi, $lo);
    }

    /** Search for an item. Returns ['ok'=>true,'results'=>[...]] or ['ok'=>false,'error'=>...]. */
    public static function search(array $item, string $languages = ''): array {
        if (!self::configured()) return ['ok' => false, 'error' => 'OpenSubtitles is not set up. An administrator can add an API key in Settings → Streaming.'];
        $q = ['languages' => self::languages($languages)];
        $hash = self::movieHash((string)($item['path'] ?? ''));
        if ($hash) $q['moviehash'] = $hash;
        $isEp = ($item['season'] ?? null) !== null && ($item['episode'] ?? null) !== null && !empty($item['show']);
        $q['query'] = (string)($isEp ? $item['show'] : ($item['title'] ?? ''));
        if ($isEp) { $q['season_number'] = (int)$item['season']; $q['episode_number'] = (int)$item['episode']; $q['type'] = 'episode'; }
        elseif (!empty($item['year'])) { $q['year'] = (int)$item['year']; $q['type'] = 'movie'; }
        if (!$isEp && preg_match('/^(?:tt)?(\d{5,10})$/', (string)($item['imdb_id'] ?? ''), $m)) $q['imdb_id'] = (int)$m[1];
        $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
        ksort($q);
        try {
            list($status, $data) = self::request('GET', self::API . '/subtitles?' . http_build_query($q));
        } catch (RuntimeException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if ($status !== 200) return ['ok' => false, 'error' => self::errorMessage($status, $data), 'status' => $status];
        $out = [];
        foreach ($data['data'] ?? [] as $row) {
            $a = $row['attributes'] ?? [];
            $file = $a['files'][0] ?? null;
            if (!is_array($file) || empty($file['file_id'])) continue;
            $out[] = [
                'file_id' => (int)$file['file_id'],
                'language' => (string)($a['language'] ?? ''),
                'release' => (string)($a['release'] ?? ($file['file_name'] ?? '')),
                'downloads' => (int)($a['download_count'] ?? 0),
                'hash_match' => !empty($a['moviehash_match']),
                'hearing_impaired' => !empty($a['hearing_impaired']),
                'machine_translated' => !empty($a['machine_translated']) || !empty($a['ai_translated']),
                'fps' => isset($a['fps']) ? (float)$a['fps'] : null,
            ];
            if (count($out) >= 40) break;
        }
        usort($out, function ($x, $y) { return [$y['hash_match'], $y['downloads']] <=> [$x['hash_match'], $x['downloads']]; });
        return ['ok' => true, 'results' => $out];
    }

    public static function languages(string $raw): string {
        $raw = $raw !== '' ? $raw : (string)(self::config()['languages'] ?? 'en');
        $codes = [];
        foreach (preg_split('/[\s,]+/', strtolower($raw)) as $c) if (preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/', $c)) $codes[] = $c;
        $codes = array_values(array_unique($codes));
        sort($codes);
        return implode(',', array_slice($codes, 0, 5)) ?: 'en';
    }

    /** Download $fileId for $item, convert to VTT and store. Returns ['ok'=>true,'file'=>path] or error. */
    public static function download(array $item, int $fileId, string $language): array {
        if (!self::configured()) return ['ok' => false, 'error' => 'OpenSubtitles is not set up.'];
        if ($fileId <= 0) return ['ok' => false, 'error' => 'Choose a subtitle to download.'];
        $language = preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/', strtolower($language)) ? strtolower($language) : 'und';
        try {
            $token = self::token();
            list($status, $data) = self::request('POST', self::API . '/download', ['file_id' => $fileId, 'sub_format' => 'srt'], $token);
            if ($status !== 200 || empty($data['link'])) return ['ok' => false, 'error' => self::errorMessage($status, $data), 'status' => $status, 'quota' => $status === 406];
            $link = (string)$data['link'];
            if (!preg_match('#^https://#i', $link)) return ['ok' => false, 'error' => 'OpenSubtitles returned an unexpected download link.'];
            list($s2, , $raw) = self::request('GET', $link);
            if ($s2 !== 200 || $raw === '') return ['ok' => false, 'error' => 'The subtitle file could not be downloaded (HTTP ' . $s2 . ').'];
        } catch (RuntimeException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if (strlen($raw) > self::MAX_BYTES) return ['ok' => false, 'error' => 'The subtitle file is too large.'];
        $isVtt = (bool)preg_match('/^(\xEF\xBB\xBF)?WEBVTT/', $raw);
        try { $vtt = Subtitles::toVtt($raw, $isVtt ? 'vtt' : 'srt'); } catch (RuntimeException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if (!preg_match('/-->/', $vtt)) return ['ok' => false, 'error' => 'The downloaded file does not contain subtitles.'];
        $path = Subtitles::saveFor((string)$item['path'], $vtt, $language, 'opensubtitles-' . $fileId);
        if ($path === null) return ['ok' => false, 'error' => 'Could not save the subtitle on the server.'];
        return ['ok' => true, 'file' => $path, 'remaining' => isset($data['remaining']) ? (int)$data['remaining'] : null];
    }
}
