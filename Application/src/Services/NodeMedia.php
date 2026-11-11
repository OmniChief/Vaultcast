<?php
declare(strict_types=1);

/** Hand authorised IPTV requests to the existing loopback Node relay. */
final class NodeMedia
{
    public static function handoff(string $action = 'iptv_hls'): void
    {
        if (isset($_GET['_php']) || !preg_match('/^nginx\b/i', (string)($_SERVER['SERVER_SOFTWARE'] ?? ''))
            || rtrim(\Vaultcast\Database::basePath(), '/') !== ''
            || rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/') !== '/api/live/' . $action) return;

        $url = iptv_hls_validate_url($_GET['u'] ?? $_GET['url'] ?? '');
        $parts = parse_url($url);
        if (!isset($parts['host'], $parts['path']) || isset($parts['user']) || isset($parts['pass'])
            || !preg_match('/\.(?:m3u8|ts|m4s|mp4|aac|m4a|webm)$/iD', $parts['path'])) return;
        $jar = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['jar'] ?? ''));
        if (strlen($jar) < 8 || strlen($jar) > 40) return;
        $host = strtolower(trim($parts['host'], '[]'));
        // Use the existing public-address policy and pass the checked address to Node.
        // Keep unusual host forms on the compatibility path.
        if (!preg_match('/^[a-z0-9.-]+$/D', $host) || !NodeRuntime::mediaReady()) return;
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (!$ips) return;
        foreach ($ips as $ip) if (!WebFetch::publicIp($ip)) return;

        $cookies = self::cookies(iptv_hls_jar_path($jar), $host, $parts['path'], strtolower($parts['scheme']) === 'https');
        if ($cookies === null) return;

        $ua = trim((string)($_GET['ua'] ?? ''));
        if ($ua === '') $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $ref = iptv_hls_default_referrer($url, trim((string)($_GET['ref'] ?? '')));
        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if (strlen($ua) > 1024 || strlen((string)$ref) > 4096 || preg_match('/[^\x20-\x7e]/', $ua . (string)$ref)
            || ($range !== '' && !preg_match('/^bytes=\d*-\d*$/D', $range))) return;

        $origin = '*';
        if (class_exists('Cors')) Cors::media();
        foreach (headers_list() as $header) {
            if (stripos($header, 'Access-Control-Allow-Origin: ') === 0) $origin = substr($header, 29);
        }
        $dir = STREAM_DATA . '/runtime/node-media';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return;
        if (random_int(1, 64) === 1) {
            foreach (glob($dir . '/*.json') ?: [] as $old) {
                if (@filemtime($old) < time() - 60) @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(24));
        $query = $action === 'stalker_stream'
            ? array_intersect_key($_GET, array_flip(['feed', 'channel', 'ticket', 'playback', 'format']))
            : ['u'=>$url, 'jar'=>$jar, 'ua'=>$ua, 'ref'=>$ref];
        $fallback = '/api/live/' . $action . '?' . http_build_query($query + ['_php'=>'1'], '', '&', PHP_QUERY_RFC3986);
        $data = json_encode(['url'=>$url, 'host'=>$host, 'ip'=>$ips[0], 'jar'=>$jar, 'ua'=>$ua, 'ref'=>$ref, 'cookies'=>$cookies,
            'range'=>$range, 'origin'=>$origin, 'fallback'=>$fallback, 'expires'=>time()+15]);
        if ($data === false || @file_put_contents($dir . '/' . $token . '.json', $data, LOCK_EX) !== strlen($data)) return;

        if (function_exists('release_request_locks')) release_request_locks();
        \Vaultcast\Database::releaseForStreaming();
        header('X-Accel-Redirect: /netplay/runtime/media/' . $token);
        exit;
    }

    /** Select cookies from cURL's Netscape jar for the one authorised upstream request. */
    private static function cookies(string $jar, string $host, string $path, bool $secure): ?string
    {
        if (!is_file($jar)) return '';
        $size = @filesize($jar);
        if ($size === false || $size > 65536) return null;
        $lines = @file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return null;
        $cookies = [];
        foreach ($lines as $line) {
            if (strpos($line, '#HttpOnly_') === 0) $line = substr($line, 10);
            elseif ($line[0] === '#') continue;
            $columns = explode("\t", $line);
            if (count($columns) !== 7) return null;
            [$domain, $subdomains, $prefix, $httpsOnly, $expiry, $name, $value] = $columns;
            $domain = ltrim(strtolower($domain), '.');
            if ($domain === '' || !in_array($subdomains, ['TRUE', 'FALSE'], true)
                || !in_array($httpsOnly, ['TRUE', 'FALSE'], true) || !ctype_digit($expiry)) return null;
            if (($httpsOnly === 'TRUE' && !$secure) || ((int)$expiry !== 0 && (int)$expiry <= time())) continue;
            if ($domain !== $host && ($subdomains !== 'TRUE' || substr($host, -strlen('.' . $domain)) !== '.' . $domain)) continue;
            if ($prefix === '' || ($path !== $prefix && (strpos($path, $prefix) !== 0
                || (substr($prefix, -1) !== '/' && ($path[strlen($prefix)] ?? '') !== '/')))) continue;
            if ($name === '' || preg_match('/[\x00-\x20\x7f;=]/', $name) || preg_match('/[\x00-\x1f\x7f;]/', $value)) return null;
            $cookies[] = ['length'=>strlen($prefix), 'value'=>$name . '=' . $value];
        }
        usort($cookies, static function (array $a, array $b): int { return $b['length'] <=> $a['length']; });
        $header = implode('; ', array_column($cookies, 'value'));
        return strlen($header) <= 8192 ? $header : null;
    }

}
