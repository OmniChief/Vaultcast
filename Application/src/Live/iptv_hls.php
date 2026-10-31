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
                    if (strpos($jar, 'st') === 0 || iptv_hls_should_proxy($abs)) {
                        return 'URI="' . iptv_hls_proxy_link($abs, $jar, $ua, $ref) . '"';
                    }
                    return 'URI="' . $abs . '"';
                }, $line);
            }
            $out[] = $line;
            continue;
        }
        $abs = iptv_hls_resolve_uri($finalUrl, $trim);
        if (strpos($jar, 'st') === 0 || iptv_hls_should_proxy($abs)) {
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

function iptv_hls_proxy_request(?callable $providerRetry = null) {
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

    if (function_exists('release_request_locks')) release_request_locks();
    \Vaultcast\Database::releaseForStreaming();
    @ignore_user_abort(true);
    @set_time_limit(0);
    $head = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
    $status = 0;
    $responseHeaders = array();
    $mode = null;
    $body = '';
    $sent = false;
    $tooLarge = false;
    $started = microtime(true);
    $emitMedia = function ($chunk) use (&$sent, &$status, &$responseHeaders, $head) {
        if (!$sent) {
            http_response_code($status >= 200 && $status < 300 ? $status : 200);
            header('Content-Type: ' . ($responseHeaders['content-type'] ?? 'application/octet-stream'));
            header('Cache-Control: private, no-store');
            if (class_exists('Cors')) Cors::media(); else header('Access-Control-Allow-Origin: *');
            header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
            foreach (array('content-range', 'content-length', 'accept-ranges') as $name) {
                if ($name === 'content-length' && !empty($responseHeaders['content-encoding']) && strtolower($responseHeaders['content-encoding']) !== 'identity') continue;
                if (isset($responseHeaders[$name])) header($name . ': ' . $responseHeaders[$name]);
            }
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) { if (!@ob_end_flush()) break; }
            $sent = true;
        }
        if (!$head && $chunk !== '') echo $chunk;
        @flush();
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 8,
        // Continuous media can outlive a request timeout; stalled connections cannot.
        CURLOPT_TIMEOUT => 0,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => 45,
        CURLOPT_NOBODY => $head,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => $ua,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $jarPath,
        CURLOPT_COOKIEFILE => $jarPath,
        CURLOPT_HEADER => false,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function () use (&$mode, $started) {
            return connection_aborted() || ($mode !== 'media' && microtime(true) - $started > 45) ? 1 : 0;
        },
        CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$status, &$responseHeaders, &$mode, &$body) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#i', $line, $match)) {
                $status = (int)$match[1];
                $responseHeaders = array();
                $mode = null;
                $body = '';
            } elseif (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $name = strtolower(trim($name));
                if (in_array($name, array('content-type', 'content-range', 'content-length', 'accept-ranges', 'content-encoding'), true)) {
                    $responseHeaders[$name] = trim($value);
                }
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$status, &$responseHeaders, &$mode, &$body, &$tooLarge, $emitMedia) {
            $length = strlen($chunk);
            if (connection_aborted()) return 0;
            // cURL follows redirects; their bodies must not reach the player.
            if ($status >= 300 && $status < 400) return $length;
            if ($status >= 400) {
                $body .= substr($chunk, 0, max(0, 65536 - strlen($body)));
                return strlen($body) >= 65536 ? 0 : $length;
            }
            if ($mode === 'media') { $emitMedia($chunk); return connection_aborted() ? 0 : $length; }
            $body .= $chunk;
            if ($mode === null) {
                $final = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
                $prefix = ltrim(substr($body, 0, 4096));
                if (preg_match('/\.m3u8(\?|$)/i', $final)
                    || preg_match('/mpegurl|m3u8/i', $responseHeaders['content-type'] ?? '')
                    || strpos($prefix, '#EXTM3U') === 0) {
                    $mode = 'playlist';
                } elseif (strlen($body) >= 4096 || ($prefix !== '' && strpos('#EXTM3U', $prefix) !== 0)) {
                    $mode = 'media';
                    $emitMedia($body);
                    $body = '';
                    return connection_aborted() ? 0 : $length;
                }
            }
            if (strlen($body) > 5000000) { $tooLarge = true; return 0; }
            return $length;
        },
    ));
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($sent) exit;
    // Some Stalker media gateways reject the emulated STB media headers or a
    // stale link with 405. A caller may refresh it once; ordinary IPTV is unchanged.
    if ($code===405 && $providerRetry!==null) {
        $replacement=$providerRetry();
        if(is_array($replacement)&&!empty($replacement['url'])){
            $_GET['u']=iptv_hls_validate_url($replacement['url']);
            $_GET['ua']=(string)($replacement['ua']??$ua);
            iptv_hls_proxy_request();
            return;
        }
    }
    if ($code >= 400) {
        header('X-Vaultcast-Error-Origin: provider');
        header('X-Vaultcast-Upstream-Status: '.(string)$code);
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$head) echo $body !== '' ? $body : ('Upstream HTTP ' . $code);
        exit;
    }
    if ($ok === false || $tooLarge) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        if (!$head) echo $tooLarge ? 'Playlist too large' : ('Upstream fetch failed: ' . $cerr);
        exit;
    }
    if ($code <= 0) $code = 502;

    $isPlaylist = preg_match('/\.m3u8(\?|$)/i', $final)
        || preg_match('/mpegurl|m3u8|application\/vnd\.apple\.mpegurl|application\/x-mpegURL/i', $ctype)
        || strpos(ltrim($body), '#EXTM3U') === 0;

    header('Cache-Control: no-store');
    if (class_exists('Cors')) Cors::media(); else header('Access-Control-Allow-Origin: *');

    if ($isPlaylist) {
        $rewritten = iptv_hls_rewrite_playlist(
            $body,
            $final !== '' ? $final : $url,
            $jarKey,
            $ua,
            $refHeader ? $refHeader : $ref
        );
        if (function_exists('live_bridge_record_playlist_hosts')) live_bridge_record_playlist_hosts($rewritten);
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        http_response_code(200);
        if (!$head) echo $rewritten;
        exit;
    }

    $emitMedia($body);
    exit;
}
