<?php
/** Live channels, guide, IPTV proxy and external-player playlists. */
declare(strict_types=1);

function live_bridge_action(): ?string {
    $p = (string) parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!preg_match('#/api/live/([a-z0-9_]+)/?$#', $p, $m)) return null; // digits allowed (m3u); actions still whitelisted
    return $m[1];
}

function live_bridge_deny(int $code, string $msg): void {
    header('Cache-Control: no-store');
    json_error($msg, $code);
}

function live_bridge_host_blocked(string $host): bool {
    $host = strtolower(trim($host, "[] \t"));
    if ($host === '' || $host === 'localhost') return true;
    foreach (['.local', '.lan', '.internal', '.localdomain', '.home.arpa'] as $sfx) {
        if (substr($host, -strlen($sfx)) === $sfx) return true;
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $rec = @gethostbynamel($host);
        if (is_array($rec)) $ips = $rec;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return true;
        $l = ip2long($ip);
        if ($l !== false && (($l & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000))) return true; // CGNAT / Tailscale
    }
    return false;
}

function live_bridge_allow_file(string $jar): string {
    $jar = (string) preg_replace('/[^a-zA-Z0-9]/', '', $jar);
    if (strlen($jar) < 8) $jar = 'default';
    return sys_get_temp_dir() . '/iptv_hls_cj/' . substr($jar, 0, 40) . '.hosts';
}

/** Record rewritten child hosts before emitting a playlist; media needs no output buffer. */
function live_bridge_record_playlist_hosts(string $body): void {
    $file = live_bridge_allow_file((string)($_GET['jar'] ?? ''));
    if (is_file($file) && filesize($file) >= 65536) return;
    $known = is_file($file) ? array_fill_keys((array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), true) : [];
    $new = [];
    if (preg_match_all('#iptv_hls\?u=([^&"\s]+)#', $body, $matches)) {
        foreach ($matches[1] as $encoded) {
            $host = strtolower((string)parse_url(rawurldecode($encoded), PHP_URL_HOST));
            if ($host !== '' && !preg_match('/[\r\n\x00]/', $host) && !isset($known[$host])) $new[$host] = true;
        }
    }
    if ($new) {
        @mkdir(dirname($file), 0700, true);
        @file_put_contents($file, implode("\n", array_keys($new)) . "\n", FILE_APPEND | LOCK_EX);
    }
}

function live_bridge_lineup_hosts(): array {
    require_once STREAM_ROOT . '/src/Live/iptv.php';
    $hosts = [];
    foreach (iptv_lineup_channels() as $c) {
        $h = strtolower((string) parse_url((string)($c['url'] ?? ''), PHP_URL_HOST));
        if ($h !== '') $hosts[$h] = true;
    }
    return $hosts;
}

function live_bridge_require_admin(): void {
    // Bootstrap Auth without running the rest of the catalogue router.
    require_once STREAM_ROOT . '/src/bootstrap.php';
    $action=live_bridge_action();
    if($action==='radio_save'){Permissions::require('radio_manage');return;}
    if(in_array($action,['epg_refresh','iptv_preview','iptv_add','iptv_remove','iptv_toggle','iptv_probe','iptv_save','epg_channels','epg_map'],true)){Permissions::require('iptv_manage');return;}
    Auth::requireAdmin();
}

/** Same-origin IPTV logo (public CSP blocks third-party images). Only exact lineup logo URLs; cached 7 days; raster images only. */
function live_bridge_logo(): void {
    $u = trim((string)($_GET['u'] ?? ''));
    if ($u === '' || !preg_match('#^https?://#i', $u)) live_bridge_deny(400, 'Bad logo url');
    require_once STREAM_ROOT . '/src/Live/iptv.php';
    $ok = false;
    foreach (iptv_lineup_channels() as $c) {
        if ((string)($c['logo'] ?? '') === $u) { $ok = true; break; }
    }
    if (!$ok) foreach (Radio::stations() as $station) if (($station['logo'] ?? '')===$u) {$ok=true;break;}
    if (!$ok) live_bridge_deny(403, 'Logo is not in the saved lineup');
    if (live_bridge_host_blocked((string) parse_url($u, PHP_URL_HOST))) live_bridge_deny(403, 'Upstream not allowed');
    $dir = STREAM_DATA . '/live/iptv_cache/logos';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/' . md5($u) . '.img';
    $meta = $file . '.type';
    if (!is_file($file) || filemtime($file) < time() - 7 * 86400) {
        if (!function_exists('curl_init')) live_bridge_deny(500, 'curl required');
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'Mozilla/5.0 (Vaultcast logo cache)',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        curl_close($ch);
        $good = is_string($body) && $body !== '' && $code < 400 && strlen($body) <= 1048576
            && preg_match('#^image/(png|jpe?g|gif|webp|avif|x-icon|vnd\.microsoft\.icon)#', $ctype);
        if ($good) {
            @file_put_contents($file, $body, LOCK_EX);
            @file_put_contents($meta, preg_replace('/[^a-z0-9\/.+-]/', '', explode(';', $ctype)[0]), LOCK_EX);
        } elseif (!is_file($file)) {
            live_bridge_deny(502, 'Logo fetch failed');
        }
    }
    $type = is_file($meta) ? trim((string) file_get_contents($meta)) : 'image/png';
    if (!preg_match('#^image/(png|jpe?g|gif|webp|avif|x-icon|vnd\.microsoft\.icon)$#', $type)) $type = 'image/png';
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    header('Content-Length: ' . (string) filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
    exit;
}

/** Channel playlist for external players. */
function live_bridge_m3u(): void {
    $root = STREAM_ROOT . '/src/Live';
    require_once $root . '/store.php';
    require_once $root . '/schedule.php';
    require_once $root . '/channel_slugs.php';
    $station = relay_load_station();
    list($slugs, $aliases) = live_channel_slugs_current($station);
    $hit = live_channel_resolve((string)($_GET['channel'] ?? ''), $slugs, $aliases);
    $channel = null;
    $id = '';
    if ($hit && $hit['kind'] === 'relay') {
        foreach ((array)($station['channels'] ?? []) as $c) {
            if (isset($c['id']) && (string)$c['id'] === $hit['id']) { $channel = $c; $id = (string)$c['id']; break; }
        }
    }
    if (!$channel) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "Unknown channel\n";
        exit;
    }
    // Relative media URLs use the hostname chosen by the viewer.
    $path = (string) parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $prefix = rtrim(\Vaultcast\Database::basePath(), '/');
    // Emit playable absolute HTTP URLs: VLC does not resolve root-relative
    // paths against the site's origin the way a browser does.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.:\\[\\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $origin = ($https ? 'https://' : 'http://') . $host . rtrim(\Vaultcast\Database::basePath(), '/') . '/live';
    $body = relay_build_m3u($station, $id, $origin);
    $dl = isset($_GET['dl']) && $_GET['dl'] !== '' && $_GET['dl'] !== '0';
    $fname = 'channel-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($channel['number'] ?? $id)) . '.m3u';
    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
    exit;
}

/** XMLTV guide and whole-lineup M3U (Tvheadend-style outputs). */
function live_bridge_tv_export(string $kind): void {
    require_once STREAM_ROOT . '/src/Live/store.php';
    require_once STREAM_ROOT . '/src/Live/tvstudio.php';
    $station = relay_load_station();
    $dl = isset($_GET['dl']) && $_GET['dl'] !== '' && $_GET['dl'] !== '0';
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if ($kind === 'xmltv') {
        $days = isset($_GET['days']) ? max(1, min(7, (int)$_GET['days'])) : null;
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . '; filename="vaultcast-guide.xml"');
        $body = tvs_xmltv($station, $days);
    } else {
        $prefix = rtrim(\Vaultcast\Database::basePath(), '/') . '/';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        header('Content-Type: audio/x-mpegurl; charset=utf-8');
        header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . '; filename="vaultcast-lineup.m3u"');
        $body = tvs_playlist_m3u($station, ($https ? 'https://' : 'http://') . $host . $prefix);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
    exit;
}

$__liveAction = live_bridge_action();
if ($__liveAction !== null) {
    if (empty(Store::settings()['features']['live'])) live_bridge_deny(404, 'Live channels are disabled in Settings → Integrations.');
    $readActions = ['guide', 'now', 'iptv', 'iptv_status', 'file', 'iptv_hls', 'logo', 'local_file', 'station', 'catalogue', 'programme_source', 'duration', 'channel_logo', 'iptv_browse', 'epg_refresh', 'm3u', 'tv_studio', 'tv_preview', 'tv_health', 'xmltv', 'playlist', 'channels', 'radio', 'radio_search', 'epg_channels'];
    $writeActions = ['iptv_preview','station', 'iptv_add', 'iptv_remove', 'iptv_toggle', 'iptv_probe', 'iptv_save', 'epg_refresh', 'tv_studio', 'tv_channels', 'channel_logo', 'radio_save', 'epg_map'];
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isWrite = ($m === 'POST' || $m === 'PUT' || $m === 'PATCH' || $m === 'DELETE');

    if ($isWrite) {
        if (!in_array($__liveAction, $writeActions, true)) live_bridge_deny(404, 'Unknown live write action');
        live_bridge_require_admin();
    } else {
        if ($m !== 'GET' && $m !== 'HEAD') live_bridge_deny(405, 'Method not allowed');
        if (!in_array($__liveAction, $readActions, true)) live_bridge_deny(404, 'Unknown live action');
    }

    if (in_array($__liveAction, ['tv_studio', 'tv_preview', 'tv_health', 'duration', 'programme_source'], true)) live_bridge_require_admin();
    if ($__liveAction === 'epg_refresh') live_bridge_require_admin();
    if ($__liveAction === 'xmltv' || $__liveAction === 'playlist') live_bridge_tv_export($__liveAction);
    if ($__liveAction === 'logo') live_bridge_logo();
    if ($__liveAction === 'm3u') live_bridge_m3u();
    if ($__liveAction === 'channels') {
        require_once STREAM_ROOT . '/src/Live/channel_slugs.php';
        list($slugs) = live_channel_slugs_current();
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'channels' => $slugs]);
    }
    try {
        if(in_array($__liveAction,['radio','radio_search','radio_save','epg_channels','epg_map'],true))header('Cache-Control: private, no-store');
        if ($__liveAction==='radio') json_out(['ok'=>true,'stations'=>Radio::stations()]);
        if ($__liveAction==='radio_search') json_out(Radio::search($_GET));
        if ($__liveAction==='radio_save') json_out(Radio::save(body_json()));
        if ($__liveAction==='epg_channels' || $__liveAction==='epg_map') {
            live_bridge_require_admin();require_once STREAM_ROOT.'/src/Live/epg.php';
            if ($__liveAction==='epg_channels') json_out(epg_search_channels((string)($_GET['q'] ?? '')));
            json_out(epg_map_channel(body_json()));
        }
    } catch(InvalidArgumentException $error) {json_error($error->getMessage(),422);}
      catch(RuntimeException $error) {json_error($error->getMessage(),502);}
    $_GET['action'] = $__liveAction;

    if ($__liveAction === 'iptv_preview') {
        $body=body_json();
        if(($body['action']??'')==='stop'){
            $jar=(string)($body['jar']??'');
            if(preg_match('/^pv[a-f0-9]{32}$/D',$jar)){
                $file=live_bridge_allow_file($jar);@unlink($file);@unlink(substr($file,0,-6).'.jar');
            }
            json_out(['ok'=>true]);
        }
        $url=trim((string)($body['url']??''));
        if(strlen($url)>8000||!preg_match('#^https?://#i',$url)||!filter_var($url,FILTER_VALIDATE_URL))live_bridge_deny(422,'Invalid preview stream URL.');
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        if(live_bridge_host_blocked($host))live_bridge_deny(403,'Upstream not allowed.');
        $jar='pv'.bin2hex(random_bytes(16));$file=live_bridge_allow_file($jar);
        if(!is_dir(dirname($file))&&!@mkdir(dirname($file),0700,true))live_bridge_deny(500,'Could not create preview storage.');
        if(@file_put_contents($file,$host."\n",LOCK_EX)===false)live_bridge_deny(500,'Could not authorise this preview.');
        @chmod($file,0600);
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $source=['url'=>$url,'userAgent'=>(string)($body['userAgent']??''),'referrer'=>(string)($body['referrer']??'')];
        // Saved channel overrides must match normal playback, including provider headers.
        foreach(iptv_lineup_channels() as $channel)if(($channel['url']??'')===$url){
            $source=['url'=>$url,'userAgent'=>$channel['userAgent']??null,'referrer'=>$channel['referrer']??null];break;
        }
        json_out(['ok'=>true,'jar'=>$jar,'source'=>$source]);
    }
    if ($__liveAction === 'iptv_hls') {
        $u = (string)($_GET['u'] ?? ($_GET['url'] ?? ''));
        $host = strtolower((string) parse_url($u, PHP_URL_HOST));
        if ($host === '' || live_bridge_host_blocked($host)) live_bridge_deny(403, 'Upstream not allowed');
        $allowFile = live_bridge_allow_file((string)($_GET['jar'] ?? ''));
        $allowed = live_bridge_lineup_hosts();
        if (is_file($allowFile) && (strpos((string)($_GET['jar']??''),'pv')!==0 || filemtime($allowFile)>time()-1800)) {
            foreach ((array) @file($allowFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $h) $allowed[trim((string)$h)] = true;
        }
        if (empty($allowed[$host])) live_bridge_deny(403, 'Host is not in the IPTV lineup');
    }

header('Cache-Control: no-store');
require_once STREAM_ROOT . '/src/Live/store.php';

$action = isset($_GET['action']) ? $_GET['action'] : 'station';

try {
    if ($action === 'channel_logo' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $name = (string)($_GET['name'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}\\.(png|jpg|webp)$/D', $name)) live_bridge_deny(404, 'Image unavailable');
        $file = STREAM_DATA . '/live/channel_logos/' . $name;
        if (!is_file($file)) live_bridge_deny(404, 'Image unavailable');
        header('Content-Type: ' . (substr($name, -4) === '.png' ? 'image/png' : (substr($name, -4) === '.jpg' ? 'image/jpeg' : 'image/webp')));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    }
    if ($action === 'channel_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = body_json();
        $image = $body['image'] ?? '';
        $channel = $body['channel'] ?? '';
        if (!is_string($image) || strlen($image) > 3000000 || !preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#D', $image, $parts)) live_bridge_deny(422, 'Choose a PNG, JPEG or WebP image up to 1 MB.');
        $bytes = base64_decode($parts[2], true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($bytes === false || strlen($bytes) > 1048576 || !$info || $info[0] > 3000 || $info[1] > 3000 || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) live_bridge_deny(422, 'Invalid logo. Use PNG, JPEG or WebP up to 1 MB and 3000 pixels.');
        require_once STREAM_ROOT . '/src/Live/tvstudio.php';
        $station = relay_load_station();
        $ids = array_column((array)($station['channels'] ?? []), 'id');
        if (!is_string($channel) || !in_array($channel, $ids, true)) live_bridge_deny(404, 'Channel unavailable');
        $extension = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $dir = STREAM_DATA . '/live/channel_logos';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) live_bridge_deny(500, 'Could not create logo storage');
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        if (@file_put_contents($dir . '/' . $name, $bytes, LOCK_EX) === false) live_bridge_deny(500, 'Could not store logo');
        $url = rtrim(\Vaultcast\Database::basePath(), '/') . '/api/live/channel_logo?name=' . $name;
        // Persist immediately, so the uploaded logo does not depend on Save studio.
        $studio = tvs_load();
        $studio['channels'][$channel]['logo'] = $url;
        tvs_save($studio);
        json_out(['ok' => true, 'logo' => $url]);
    }
    if ($action === 'duration' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Admin-only lookup for local server paths; never expose the file over HTTP.
        $raw = $_GET['path'] ?? '';
        if (!is_string($raw) || $raw === '' || strlen($raw) > 4096 ||
            strpos($raw, chr(0)) !== false || preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) ||
            !(isset($raw[0]) && $raw[0] === '/' || preg_match('#^[a-z]:[/\\\\]#i', $raw))) {
            json_error('Enter an absolute local file path.', 422);
        }
        $path = realpath($raw);
        if ($path === false || !is_file($path)) {
            // An absolute site URL can also begin with /; let the browser handle it.
            json_out(['ok' => false, 'local' => false]);
        }
        if (!preg_match('/\\.(?:mp4|m4v|mov|mkv|webm|avi|mpeg|mpg|ts|m2ts|wmv|flv)$/i', $path)) {
            json_error('Not a supported video file.', 422);
        }
        if (!is_readable($path)) json_error('Vaultcast cannot read that local file.', 403);
        $seconds = MediaProbe::duration($path, 25);
        if ($seconds === null || !is_finite($seconds) || $seconds <= 0) {
            json_error('Could not read duration from the local file.', 422);
        }
        json_out(['ok' => true, 'local' => true, 'durationMs' => (int) round($seconds * 1000)]);
    }
    if ($action === 'local_file' && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        // Resolve IDs only against saved station media. Never accept a path in this URL.
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || $id === '' || strlen($id) > 128) live_bridge_deny(404, 'Media unavailable');
        $station = relay_load_station();
        $path = '';
        foreach ((array)($station['media'] ?? []) as $media) {
            if (!is_array($media) || (string)($media['id'] ?? '') !== $id) continue;
            $candidate = (string)($media['url'] ?? '');
            if (($candidate[0] ?? '') !== '/' && !preg_match('#^[A-Za-z]:[/\\\\]#', $candidate)) break;
            $real = realpath($candidate);
            if ($real !== false && is_file($real) && is_readable($real)) $path = $real;
            break;
        }
        if ($path === '') live_bridge_deny(404, 'Media unavailable');
        $viewer = AreaAccess::viewer();
        $token = Stream::createToken($viewer ? (string)$viewer['id'] : 'live', $id, $path, 'video', 'live');
        header('Cache-Control: no-store');
        header('Location: ' . rtrim(\Vaultcast\Database::basePath(), '/') . '/api/stream/play?t=' . $token, true, 302);
        exit;
    }
    if ($action === 'file' && ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD')) {
        require_once STREAM_ROOT . '/src/Live/library.php';
        relay_catalogue_file();
        exit;
    }
    // Serve proxied HLS playlists and media before setting JSON headers.
    if ($action === 'iptv_hls' && ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD')) {
        require_once STREAM_ROOT . '/src/Live/iptv_hls.php';
        iptv_hls_proxy_request();
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($action === 'programme_source' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/library.php';
        $source = (string)($_GET['source'] ?? 'rules');
        $id = (string)($_GET['id'] ?? '');
        $selected = [];
        if ($source === 'collection') {
            if (!preg_match('/^[a-f0-9]{8,64}$/D', $id)) json_error('Invalid collection', 422);
            $col = Collections::status($id);
            foreach (($col['parts'] ?? []) as $part) {
                if (!empty($part['present']) && !empty($part['item_id'])) $selected[(string)$part['item_id']] = true;
            }
        } elseif ($source === 'smart') {
            if (!preg_match('/^[a-f0-9]{16}$/D', $id)) json_error('Invalid smart collection', 422);
            $result = SmartCollections::items($id, Auth::requireAdmin());
            foreach (($result['items'] ?? []) as $card) {
                if (!empty($card['id'])) $selected[(string)$card['id']] = true;
            }
        } elseif ($source !== 'rules') {
            json_error('Unknown source type', 422);
        }
        $yearFrom = isset($_GET['from']) && $_GET['from'] !== '' ? (int)$_GET['from'] : 0;
        $yearTo = isset($_GET['to']) && $_GET['to'] !== '' ? (int)$_GET['to'] : 9999;
        $genre = strtolower(trim((string)($_GET['genre'] ?? '')));
        if ($yearFrom < 0 || $yearTo > 9999 || $yearFrom > $yearTo || strlen($genre) > 100) json_error('Invalid programming filters', 422);
        $items = [];
        foreach ((Catalogue::get()['items'] ?? []) as $item) {
            if (!is_array($item) || ($item['media_kind'] ?? '') !== 'video') continue;
            if (relay_is_tv($item)) continue; // Movie programming rules and collection import
            if ($source !== 'rules' && !isset($selected[(string)($item['id'] ?? '')])) continue;
            $year = (int)($item['year'] ?? 0);
            if ($yearFrom && $year < $yearFrom || $yearTo !== 9999 && $year > $yearTo) continue;
            $genres = $item['genres'] ?? ($item['genre'] ?? []);
            $genres = is_array($genres) ? implode(', ', $genres) : (string)$genres;
            if ($genre !== '' && stripos($genres, $genre) === false) continue;
            if (empty($item['id']) || empty($item['path']) || !is_file($item['path'])) continue;
            $items[] = relay_public_hit($item, false);
            if (count($items) >= 300) break;
        }
        json_out(['ok' => true, 'items' => $items, 'limited' => count($items) >= 300]);
    }
    if ($action === 'catalogue' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/library.php';
        echo json_encode(relay_catalogue_query($_GET), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'station' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(relay_load_station());
        exit;
    }
    if ($action === 'station' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $incoming = json_decode($raw, true);
        if (!is_array($incoming) || !isset($incoming['channels']) || !isset($incoming['media'])) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid station payload'));
            exit;
        }
        echo json_encode(relay_save_station($incoming));
        exit;
    }
    if ($action === 'now') {
        require_once STREAM_ROOT . '/src/Live/schedule.php';
        $station = relay_load_station();
        $id = isset($_GET['channel']) ? $_GET['channel'] : '';
        $now = (int) round(microtime(true) * 1000);
        // optional ?at=<unix ms> bounded to station timeshift historyDays
        // so the unified Stream shell can rewind, skip, and advance programmes.
        if (isset($_GET['at']) && is_numeric($_GET['at'])) {
            $at = (int) $_GET['at'];
            $histDays = isset($station['historyDays']) ? max(1, min(14, (int)$station['historyDays'])) : 7;
            if ($at > $now - $histDays * 86400000 && $at < $now + 86400000) $now = $at;
        }
        $b = relay_air_block($station, $id, $now);
        if (is_array($b)) {
            // everything the player needs to join at the live edge
            $b['serverNow'] = (int) round(microtime(true) * 1000);
            $b['atUnix'] = $now;
            $b['mediaOffsetMs'] = isset($b['mediaOffsetMs']) ? (int)$b['mediaOffsetMs'] : 0;
            $b['seekMs'] = $b['mediaOffsetMs'] + max(0, $now - (int)$b['startUnix']); // position inside the file
        }
        echo json_encode($b);
        exit;
    }

    // --- IPTV (multi-feed .m3u playlists) ---
    if ($action === 'iptv' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/iptv.php';
        $state = iptv_load();
        echo json_encode(array(
            'ok' => true,
            'iptv' => $state,
            'favourites' => iptv_favourites_with_status($state),
            'lineup' => iptv_lineup_channels($state),
            'feeds' => $state['feeds'],
            'activeFeedId' => isset($state['activeFeedId']) ? $state['activeFeedId'] : 'all',
            'countries' => iptv_countries(),
            'categories' => iptv_categories(),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'iptv_browse' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/iptv.php';
        $kind = isset($_GET['kind']) ? $_GET['kind'] : '';
        $key = isset($_GET['key']) ? $_GET['key'] : (isset($_GET['country']) ? $_GET['country'] : '');
        if ($kind === 'category' && isset($_GET['category'])) $key = $_GET['category'];
        if ($kind === 'country' && $key === '') $key = 'uk';
        $feed = isset($_GET['feed']) ? $_GET['feed'] : '';
        echo json_encode(iptv_browse(array(
            'feed' => $feed,
            'kind' => $kind,
            'key' => $key,
            'q' => isset($_GET['q']) ? $_GET['q'] : '',
            'group' => isset($_GET['group']) ? $_GET['group'] : '',
            'limit' => isset($_GET['limit']) ? $_GET['limit'] : 80,
            'offset' => isset($_GET['offset']) ? $_GET['offset'] : 0,
            'hlsOnly' => !isset($_GET['hlsOnly']) || $_GET['hlsOnly'] !== '0',
            'activeOnly' => isset($_GET['activeOnly']) && $_GET['activeOnly'] === '1',
            'country' => $kind === '' ? (string)($_GET['country'] ?? '') : '',
            'category' => $kind === '' ? (string)($_GET['category'] ?? '') : '',
            'refresh' => isset($_GET['refresh']) && ($_GET['refresh'] === '1' || $_GET['refresh'] === 'true'),
        )), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'iptv_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once STREAM_ROOT . '/src/Live/iptv.php';
        $incoming = json_decode(file_get_contents('php://input'), true);
        echo json_encode(iptv_add_channel($incoming), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'iptv_remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once STREAM_ROOT . '/src/Live/iptv.php';
        $incoming = json_decode(file_get_contents('php://input'), true);
        $id = is_array($incoming) && isset($incoming['id']) ? $incoming['id'] : (isset($_GET['id']) ? $_GET['id'] : '');
        if ($id === '') {
            http_response_code(400);
            echo json_encode(array('error' => 'Missing id'));
            exit;
        }
        echo json_encode(iptv_remove_channel($id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'iptv_toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once STREAM_ROOT . '/src/Live/iptv.php';
        $incoming = json_decode(file_get_contents('php://input'), true);
        if (!is_array($incoming) || empty($incoming['id'])) {
            http_response_code(400);
            echo json_encode(array('error' => 'Missing id'));
            exit;
        }
        $enabled = !isset($incoming['enabled']) || !!$incoming['enabled'];
        echo json_encode(iptv_toggle_channel($incoming['id'], $enabled), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'iptv_probe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once STREAM_ROOT . '/src/Live/iptv.php';
        $incoming = json_decode(file_get_contents('php://input'), true);
        if (!is_array($incoming)) $incoming = array();
        $items = array();
        if (!empty($incoming['channels']) && is_array($incoming['channels'])) {
            $items = $incoming['channels'];
        } elseif (!empty($incoming['ids']) && is_array($incoming['ids'])) {
            $items = $incoming['ids'];
        } elseif (!empty($incoming['id']) || !empty($incoming['url'])) {
            $items = array($incoming);
        }
        if (!$items) {
            http_response_code(400);
            echo json_encode(array('error' => 'Provide id/url or channels[] (max 25, on-demand only)'));
            exit;
        }
        echo json_encode(iptv_probe_channels($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'iptv_status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/iptv.php';
        $state = iptv_load();
        echo json_encode(array(
            'ok' => true,
            'favourites' => iptv_favourites_with_status($state),
            'cacheEntries' => count(iptv_status_load()['entries']),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
        if ($action === 'iptv_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once STREAM_ROOT . '/src/Live/iptv.php';
        $incoming = json_decode(file_get_contents('php://input'), true);
        if (!is_array($incoming)) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid payload'));
            exit;
        }
        echo json_encode(iptv_update_meta($incoming), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    // --- TV Studio (weekly calendar, filler, adverts, numbering, DVR, health) ---
    if ($action === 'tv_studio' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/tvstudio.php';
        echo json_encode(tvs_admin_payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'tv_studio' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once STREAM_ROOT . '/src/Live/tvstudio.php';
        $incoming = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($incoming) || !isset($incoming['studio']) || !is_array($incoming['studio'])) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid studio payload'));
            exit;
        }
        tvs_save($incoming['studio']);
        echo json_encode(tvs_admin_payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'tv_channels' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once STREAM_ROOT . '/src/Live/tvstudio.php';
        $incoming = json_decode((string) file_get_contents('php://input'), true);
        try {
            tvs_apply_channel_edits(is_array($incoming) ? ($incoming['channels'] ?? array()) : array());
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(array('error' => $e->getMessage()));
            exit;
        }
        echo json_encode(tvs_admin_payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'tv_preview' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/tvstudio.php';
        $station = relay_load_station();
        $from = isset($_GET['from']) && is_numeric($_GET['from']) ? (int)$_GET['from'] : (int) round(microtime(true) * 1000);
        $hours = max(1, min(168, (int)($_GET['hours'] ?? 24)));
        $blocks = relay_materialize($station, (string)($_GET['channel'] ?? ''), $from, $from + $hours * 3600000);
        echo json_encode(array('ok' => true, 'from' => $from, 'hours' => $hours, 'blocks' => $blocks), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'tv_health' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/tvstudio.php';
        echo json_encode(tvs_health(relay_load_station()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'guide' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_once STREAM_ROOT . '/src/Live/epg.php';
        $hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 12;
        $slim = isset($_GET['slim']) && $_GET['slim'] === '1';
        json_out(LiveGuideCache::read($hours, $slim, Auth::currentSession(), function ($hours, $slim) {
            return epg_unified_guide(relay_load_station(), $hours, $slim);
        }));
        exit;
    }
    if ($action === 'epg_refresh' && ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET')) {
        $force = isset($_GET['force']) ? $_GET['force'] === '1' : true;
        echo json_encode(LiveGuideCache::queueEpg($force), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(array('error' => 'Unknown action'));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()));
}

    exit;
}
unset($__liveAction);
