<?php


function iptv_hls_jar_path($jar) {
    $jar = preg_replace('/[^a-zA-Z0-9]/', '', (string)$jar);
    if (strlen($jar) < 8) {
        $jar = 'default' . substr(md5(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x'), 0, 12);
    }
    if (strlen($jar) > 40) $jar = substr($jar, 0, 40);
    $dir = sys_get_temp_dir() . '/iptv_hls_cj';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . $jar . '.jar';
}

function iptv_hls_validate_url($url) {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        throw new InvalidArgumentException('Invalid upstream url');
    }
    if (strlen($url) > 8000) {
        throw new InvalidArgumentException('URL too long');
    }
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        throw new InvalidArgumentException('Invalid upstream host');
    }
    return $url;
}

function iptv_hls_resolve_uri($baseUrl, $ref) {
    $ref = trim($ref);
    if ($ref === '') return $baseUrl;
    if (preg_match('#^https?://#i', $ref)) return $ref;
    if (strpos($ref, '//') === 0) {
        $p = parse_url($baseUrl);
        return (isset($p['scheme']) ? $p['scheme'] : 'https') . ':' . $ref;
    }
    $p = parse_url($baseUrl);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return $ref;
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (isset($ref[0]) && $ref[0] === '/') {
        return $origin . $ref;
    }
    $path = isset($p['path']) ? $p['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    $abs = $dir . $ref;
    $parts = array();
    foreach (explode('/', $abs) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    return $origin . '/' . implode('/', $parts);
}

function iptv_hls_should_proxy($absUrl) {
    if (preg_match('/\.m3u8(\?|$)/i', $absUrl)) return true;
    if (preg_match('/\.(ts|m4s|mp4|m4a|aac|vtt|srt|key)(\?|$)/i', $absUrl)) return false;
    return true;
}

function iptv_hls_proxy_link($absUrl, $jar, $ua, $ref) {
    $q = \Vaultcast\Database::basePath() . 'api/live/iptv_hls?u=' . rawurlencode($absUrl) . '&jar=' . rawurlencode($jar);
    if ($ua !== null && $ua !== '') $q .= '&ua=' . rawurlencode($ua);
    if ($ref !== null && $ref !== '') $q .= '&ref=' . rawurlencode($ref);
    return $q;
}

function iptv_hls_rewrite_playlist($body, $finalUrl, $jar, $ua, $ref) {
    $lines = preg_split("/\r?\n/", $body);
    $out = array();
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') { $out[] = $line; continue; }
        if (isset($trim[0]) && $trim[0] === '#') {
            if (stripos($trim, 'URI="') !== false) {
                $line = preg_replace_callback('/URI="([^"]+)"/i', function ($m) use ($finalUrl, $jar, $ua, $ref) {
                    $abs = iptv_hls_resolve_uri($finalUrl, $m[1]);
                    if (iptv_hls_should_proxy($abs)) {
                        return 'URI="' . iptv_hls_proxy_link($abs, $jar, $ua, $ref) . '"';
                    }
                    return 'URI="' . $abs . '"';
                }, $line);
            }
            $out[] = $line;
            continue;
        }
        $abs = iptv_hls_resolve_uri($finalUrl, $trim);
        if (iptv_hls_should_proxy($abs)) {
            $out[] = iptv_hls_proxy_link($abs, $jar, $ua, $ref);
        } else {
            $out[] = $abs;
        }
    }
    return implode("\n", $out);
}

function iptv_hls_default_referrer($url, $ref) {
    if ($ref !== null && $ref !== '') return $ref;
    if (preg_match('/pluto\.tv|jmp2\.uk/i', $url)) return 'https://pluto.tv/';
    return null;
}

function iptv_hls_proxy_request() {
    $url = isset($_GET['u']) ? $_GET['u'] : (isset($_GET['url']) ? $_GET['url'] : '');
    $url = iptv_hls_validate_url($url);
    $jar = isset($_GET['jar']) ? $_GET['jar'] : '';
    $ua = isset($_GET['ua']) ? trim((string)$_GET['ua']) : '';
    $ref = isset($_GET['ref']) ? trim((string)$_GET['ref']) : '';
    if ($ua === '') {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }
    $refHeader = iptv_hls_default_referrer($url, $ref !== '' ? $ref : null);
    $jarPath = iptv_hls_jar_path($jar);
    $jarKey = preg_replace('/[^a-zA-Z0-9]/', '', (string)$jar);
    if (strlen($jarKey) < 8) $jarKey = basename($jarPath, '.jar');

    if (!function_exists('curl_init')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'curl required for HLS proxy';
        exit;
    }

    $headers = array('Accept: */*');
    if ($refHeader) $headers[] = 'Referer: ' . $refHeader;
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $jarPath,
        CURLOPT_COOKIEFILE => $jarPath,
        CURLOPT_HEADER => false,
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Upstream fetch failed: ' . $cerr;
        exit;
    }
    if ($code <= 0) $code = 502;
    if ($code >= 400) {
        http_response_code($code >= 100 ? $code : 502);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body !== '' ? $body : ('Upstream HTTP ' . $code);
        exit;
    }

    $isPlaylist = preg_match('/\.m3u8(\?|$)/i', $final)
        || preg_match('/mpegurl|m3u8|application\/vnd\.apple\.mpegurl|application\/x-mpegURL/i', $ctype)
        || (is_string($body) && strlen($body) < 2000000 && strpos(ltrim($body), '#EXTM3U') === 0);

    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');

    if ($isPlaylist) {
        if (strlen($body) > 5000000) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Playlist too large';
            exit;
        }
        $rewritten = iptv_hls_rewrite_playlist(
            $body,
            $final !== '' ? $final : $url,
            $jarKey,
            $ua,
            $refHeader ? $refHeader : $ref
        );
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        http_response_code(200);
        echo $rewritten;
        exit;
    }

    if ($ctype !== '') header('Content-Type: ' . $ctype);
    else header('Content-Type: application/octet-stream');
    http_response_code($code >= 200 && $code < 300 ? $code : 200);
    echo $body;
    exit;
}
