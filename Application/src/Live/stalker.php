<?php
/** Stalker/Ministra live TV adapter. Credentials and commands never leave the server. */
class StalkerAuthenticationException extends RuntimeException {}

function stalker_public_feed(array $feed): array {
    if (($feed['type'] ?? '') !== 'stalker') return $feed;
    $feed['configured'] = !empty($feed['mac']);
    foreach (['mac','username','password','serial','deviceId','deviceId2'] as $key) unset($feed[$key]);
    return $feed;
}

function stalker_public_state(array $state): array {
    $state['feeds'] = array_map('stalker_public_feed', $state['feeds'] ?? []);
    return $state;
}

function stalker_validate_url(string $url): string {
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http','https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || preg_match('/[\r\n]/', $url)) {
        throw new InvalidArgumentException('Enter an HTTP or HTTPS portal URL without embedded credentials.');
    }
    return $url;
}

function stalker_public_addresses(string $url): array {
    $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) throw new RuntimeException('Portal hostname could not be resolved.');
    foreach ($ips as $ip) {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) && (ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000))) throw new RuntimeException('Portal upstream must use a public network address.');
    }
    return $ips;
}

function stalker_endpoint(array $feed): string {
    $url = stalker_validate_url($feed['url']);
    $p = parse_url($url);
    $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    $path = rtrim($p['path'] ?? '', '/');
    if (preg_match('#/(?:server/load|portal)\.php$#', $path)) return $origin.$path;
    $path = preg_replace('#/c$#', '', $path);
    return $origin.$path.'/server/load.php';
}

function stalker_cookie_path(array $feed): string {
    $dir = sys_get_temp_dir().'/vaultcast_stalker';
    if (!is_dir($dir)) @mkdir($dir,0700,true);
    $path = $dir.'/'.hash('sha256',json_encode($feed)).'.cookies';
    $handle = @fopen($path,'x');
    if ($handle) {
        $host = (string)parse_url($feed['url'],PHP_URL_HOST);
        fwrite($handle,"# Netscape HTTP Cookie File\n".$host."\tFALSE\t/\tFALSE\t0\tmac\t".rawurlencode($feed['mac'])."\n");
        fclose($handle); @chmod($path,0600);
    }
    return $path;
}

function stalker_request(array $feed, string $type, string $action, array $params = [], string $token = ''): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Stalker connections require the PHP cURL extension.');
    $url = stalker_endpoint($feed).'?'.http_build_query(array_merge($params, ['type'=>$type,'action'=>$action,'JsHttpRequest'=>'1-xml']));
    $ips = stalker_public_addresses($url);
    $host = (string)parse_url($url,PHP_URL_HOST);
    $port = parse_url($url,PHP_URL_PORT) ?: (parse_url($url,PHP_URL_SCHEME)==='https' ? 443 : 80);
    $headers = ['Accept: application/json', 'X-User-Agent: Model: MAG250; Link: Ethernet'];
    if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
    $ch = curl_init($url);
    $body = '';
    $resolvedIp = strpos($ips[0],':')!==false ? '['.$ips[0].']' : $ips[0];
    curl_setopt_array($ch, [CURLOPT_RESOLVE=>[$host.':'.$port.':'.$resolvedIp],CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>30,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_USERAGENT=>'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3',
        CURLOPT_REFERER=>preg_replace('#/(?:server/load|portal)\.php$#', '/c/', stalker_endpoint($feed)),
        CURLOPT_COOKIE=>'mac='.rawurlencode($feed['mac']).'; stb_lang=en; timezone=Europe/London',
        CURLOPT_COOKIEFILE=>stalker_cookie_path($feed),CURLOPT_COOKIEJAR=>stalker_cookie_path($feed),
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_WRITEFUNCTION=>function ($handle, $chunk) use (&$body) { if (strlen($body)+strlen($chunk)>16*1024*1024) return 0; $body.=$chunk; return strlen($chunk); }]);
    if (function_exists('release_request_locks')) release_request_locks();
    $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (in_array($status,[401,403],true)) throw new StalkerAuthenticationException('Portal rejected authentication. Check the registered device and account.');
    if ($ok === false || $status < 200 || $status >= 300) throw new RuntimeException('Portal request failed. Check the portal address, account and provider access (HTTP '.$status.').');
    $data = json_decode($body, true);
    if ($action==='do_auth' && isset($data['js']) && is_bool($data['js'])) return ['status'=>$data['js']];
    if (is_array($data) && array_key_exists('js',$data) && ($data['js']===null || $data['js']===false || (is_array($data['js']) && !empty($data['js']['error'])))) throw new StalkerAuthenticationException('Portal rejected the session or device.');
    if (!is_array($data) || !isset($data['js']) || !is_array($data['js'])) throw new RuntimeException('Portal returned an invalid response.');
    return $data['js'];
}

function stalker_session(array $feed, bool $renew = false): string {
    $key = 'stalker_session_'.hash('sha256', json_encode($feed));
    $cached = \Vaultcast\Repository::cacheGet($key) ?? [];
    if (!$renew && ($cached['expires'] ?? 0)>time() && !empty($cached['token'])) return $cached['token'];
    $handshake = stalker_request($feed, 'stb', 'handshake', ['token'=>'']);
    $token = (string)($handshake['token'] ?? '');
    if ($token === '' || preg_match('/[\r\n]/', $token)) throw new RuntimeException('Portal handshake failed. Check the registered MAC address.');
    $profile = stalker_request($feed, 'stb', 'get_profile', ['hd'=>1,'stb_type'=>'MAG250','sn'=>$feed['serial'] ?? '',
        'device_id'=>$feed['deviceId'] ?? '', 'device_id2'=>$feed['deviceId2'] ?? '', 'auth_second_step'=>1,
        'image_version'=>218,'hw_version'=>'1.7-BD-00','num_banks'=>2,'not_valid_token'=>0,
        'ver'=>'ImageDescription: 0.2.18-r14-pub-250; PORTAL version: 5.5.0; API Version: JS API version: 328; STB API version: 134; Player Engine version: 0x58c',
        'client_type'=>'STB','video_out'=>'hdmi'], $token);
    if (!empty($profile['blocked']) || !empty($profile['error'])) throw new RuntimeException('Portal rejected the registered device profile.');
    if (!empty($feed['username'])) {
        $auth = stalker_request($feed, 'stb', 'do_auth', ['login'=>$feed['username'],'password'=>$feed['password'] ?? ''], $token);
        if (empty($auth['status'])) throw new RuntimeException('Portal account login was rejected.');
    }
    \Vaultcast\Repository::cachePut($key, ['token'=>$token,'expires'=>time()+600],600);
    return $token;
}

function stalker_call(array $feed, string $action, array $params = []): array {
    $token = stalker_session($feed);
    try { return stalker_request($feed, 'itv', $action, $params, $token); }
    catch (StalkerAuthenticationException $e) { return stalker_request($feed, 'itv', $action, $params, stalker_session($feed, true)); }
}

function stalker_channel_url(string $feed, string $id): string {
    // Root-relative references avoid trusting the request Host header or storing provider tokens.
    return rtrim(\Vaultcast\Database::basePath(), '/').'/api/live/stalker_stream?feed='.rawurlencode($feed).'&channel='.rawurlencode($id).'&format=.m3u8';
}

function stalker_index(array $feed, bool $refresh = false): array {
    $key = 'stalker_channels_'.hash('sha256', json_encode($feed));
    $cached = \Vaultcast\Repository::cacheGet($key) ?? [];
    if (!$refresh && ($cached['expires'] ?? 0)>time()) return $cached;
    $groups = [];
    try { $genres = stalker_call($feed, 'get_genres'); } catch (RuntimeException $e) { $genres = []; }
    foreach ($genres as $g) if (is_array($g)) $groups[(string)($g['id'] ?? '')] = (string)($g['title'] ?? '');
    $result = stalker_call($feed, 'get_all_channels');
    $rows = $result['data'] ?? $result; $channels = []; $commands = [];
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id']) || empty($row['cmd'])) continue;
        $id = (string)$row['id']; $group = $groups[(string)($row['tv_genre_id'] ?? '')] ?? '';
        $commands[$id] = (string)$row['cmd'];
        $channel = ['id'=>'stalker-'.substr(hash('sha256',$feed['id'].':'.$id),0,20), 'tvgId'=>(string)($row['xmltv_id'] ?? ''),
            'name'=>(string)($row['name'] ?? $id),'url'=>stalker_channel_url($feed['id'],$id), 'logo'=>(string)($row['logo'] ?? ''),
            'group'=>$group,'quality'=>'','country'=>'','categories'=>iptv_channel_categories($group),'labels'=>[]];
        if ($channel['logo'] !== '' && !preg_match('#^https?://#i',$channel['logo'])) {
            require_once __DIR__.'/iptv_hls.php';
            $channel['logo'] = iptv_hls_resolve_uri(stalker_endpoint($feed), $channel['logo']);
        }
        $channels[] = $channel;
    }
    if (!$channels) throw new RuntimeException('Portal returned no live channels. Check your subscription and device details.');
    $out = ['channels'=>$channels,'commands'=>$commands,'epgUrls'=>[],'expires'=>time()+3600];
    \Vaultcast\Repository::cachePut($key,$out,3600);
    return $out;
}

function stalker_resolve(array $feed, string $id): string {
    $index = stalker_index($feed);
    if (!isset($index['commands'][$id])) throw new InvalidArgumentException('Unknown portal channel. Refresh the source.');
    $result = stalker_call($feed,'create_link',['cmd'=>$index['commands'][$id],'series'=>'','forced_storage'=>'undefined','disable_ad'=>0,'download'=>0]);
    $cmd = (string)($result['cmd'] ?? '');
    if ($cmd === '') {
        stalker_session($feed,true);
        $result = stalker_call($feed,'create_link',['cmd'=>$index['commands'][$id],'forced_storage'=>'undefined','disable_ad'=>0]);
        $cmd = (string)($result['cmd'] ?? '');
    }
    if (!preg_match('#(?:^|\s)(https?://[^\s]+)#', $cmd, $match)) throw new RuntimeException('Portal did not return a playable HTTP stream.');
    $url = $match[1];
    $p = parse_url($url);
    if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\r\n]/',$url)) throw new RuntimeException('Portal returned an invalid stream URL.');
    stalker_public_addresses($url);
    return $url;
}

/** Provider guide rows, keyed by the same stable IDs as the saved lineup. */
function stalker_guide(array $feed): array {
    $data = stalker_call($feed, 'get_epg_info', ['period'=>48]);
    $data = $data['data'] ?? $data; $out = [];
    foreach ($data as $channelId=>$rows) {
        if (!is_array($rows)) continue;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $start = $row['start_timestamp'] ?? $row['time'] ?? null;
            $end = $row['stop_timestamp'] ?? $row['time_to'] ?? null;
            $start = is_numeric($start) ? (int)$start : strtotime((string)$start);
            $end = is_numeric($end) ? (int)$end : strtotime((string)$end);
            if (!$start || !$end || $end<=$start) continue;
            $id = 'stalker-'.substr(hash('sha256',$feed['id'].':'.($row['ch_id'] ?? $channelId)),0,20);
            $out[$id][] = ['startUnix'=>$start*1000,'endUnix'=>$end*1000,'title'=>(string)($row['name'] ?? $row['title'] ?? ''),'subtitle'=>(string)($row['descr'] ?? ''),'kind'=>'epg'];
        }
    }
    return $out;
}
