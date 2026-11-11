<?php
declare(strict_types=1);

/** Hand authorised IPTV requests to the existing loopback Node relay. */
final class NodeMedia
{
    public static function handoff(): void
    {
        if (isset($_GET['_php']) || !preg_match('/^nginx\b/i', (string)($_SERVER['SERVER_SOFTWARE'] ?? ''))
            || rtrim(\Vaultcast\Database::basePath(), '/') !== ''
            || !preg_match('#^/api/live/iptv_hls/?$#D', (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH))) return;

        $url = iptv_hls_validate_url($_GET['u'] ?? $_GET['url'] ?? '');
        $parts = parse_url($url);
        if (!isset($parts['host'], $parts['path']) || isset($parts['user']) || isset($parts['pass'])
            || !preg_match('/\.(?:m3u8|ts|m4s|mp4|aac|m4a|webm)$/iD', $parts['path'])) return;
        $jar = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['jar'] ?? ''));
        if (strlen($jar) < 8 || strpos($jar, 'st') === 0 || strlen($jar) > 40 || is_file(iptv_hls_jar_path($jar))) return;
        $host = strtolower(trim($parts['host'], '[]'));
        // Use the existing public-address policy and pass the checked address to Node.
        // Keep unusual host forms on the compatibility path.
        if (!preg_match('/^[a-z0-9.-]+$/D', $host) || !NodeRuntime::mediaReady()) return;
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (!$ips) return;
        foreach ($ips as $ip) if (!WebFetch::publicIp($ip)) return;

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
        $fallback = '/api/live/iptv_hls?' . http_build_query([
            'u'=>$url, 'jar'=>$jar, 'ua'=>$ua, 'ref'=>$ref, '_php'=>'1',
        ], '', '&', PHP_QUERY_RFC3986);
        $data = json_encode(['url'=>$url, 'host'=>$host, 'ip'=>$ips[0], 'jar'=>$jar, 'ua'=>$ua, 'ref'=>$ref,
            'range'=>$range, 'origin'=>$origin, 'fallback'=>$fallback, 'expires'=>time()+15]);
        if ($data === false || @file_put_contents($dir . '/' . $token . '.json', $data, LOCK_EX) !== strlen($data)) return;

        if (function_exists('release_request_locks')) release_request_locks();
        \Vaultcast\Database::releaseForStreaming();
        header('X-Accel-Redirect: /netplay/runtime/media/' . $token);
        exit;
    }
}
