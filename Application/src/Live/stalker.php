<?php
/** Stalker/Ministra live TV adapter. Credentials and commands never leave the server. */
class StalkerAuthenticationException extends RuntimeException {}
class StalkerRateLimitException extends RuntimeException {
    public int $retryAt;
    public function __construct(int $retryAt) {parent::__construct('Provider rate limited requests (HTTP 429).',429);$this->retryAt=$retryAt;}
}
function stalker_cooldown_key(array $feed): string {
    return 'stalker_cooldown_'.hash('sha256',json_encode([$feed['url'],$feed['mac']]));
}

function stalker_public_feed(array $feed): array {
    if (($feed['type'] ?? '') !== 'stalker') return $feed;
    $feed['configured'] = !empty($feed['mac']);
    $feed['expired'] = StalkerCatalogues::expired($feed);
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
    $selected = \Vaultcast\Repository::cacheGet('stalker_endpoint_'.hash('sha256',$url));
    if (($selected['path'] ?? '') === $path.'/portal.php') return $origin.$path.'/portal.php';
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

/** Portal reads may share one in-flight request for the same credentials and cookies. */
function stalker_relay_read(array $feed, string $url, string $type, string $action, string $token, string $ip, string $referer, string $jar): ?array {
    if ($token === '' || !in_array($type, ['itv','vod','series'], true)
        || !in_array($action, ['get_genres','get_categories','get_ordered_list','get_all_channels','get_epg_info','get_short_epg'], true)
        || !NodeRuntime::stalkerReadReady()) return null;
    $scope = hash('sha256', json_encode($feed));
    $compatibilityKey = 'stalker-relay-cookie:' . $scope;
    if (\Vaultcast\Repository::cacheGet($compatibilityKey)) return null;
    $parts = parse_url($url);
    $host = strtolower($parts['host']);
    $cookies = NodeMedia::cookies($jar, $host, $parts['path'] ?? '/', strtolower($parts['scheme']) === 'https');
    if ($cookies === null) return null;
    $mag = 'mac='.rawurlencode($feed['mac']).'; stb_lang=en; timezone=Europe/London';
    $result = NodeProvider::get($url, $host, $ip, 30,
        ['accept'=>'application/json','authorization'=>'Bearer '.$token,'x-user-agent'=>'Model: MAG250; Link: Ethernet'], 0,
        ['stalkerScope'=>$scope,'cookies'=>$cookies === '' ? $mag : $cookies.'; '.$mag,'ref'=>$referer,
            'ua'=>'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3']);
    if (($result[1]['compatibility'] ?? '') === 'cookies') {
        \Vaultcast\Repository::cachePut($compatibilityKey, ['cookies'=>true], 600);
        return null;
    }
    return $result;
}

function stalker_request(array $feed, string $type, string $action, array $params = [], string $token = '', ?string $endpoint = null): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Stalker connections require the PHP cURL extension.');
    $cooldown=\Vaultcast\Repository::cacheGet(stalker_cooldown_key($feed));
    if(($cooldown['until']??0)>time())throw new StalkerRateLimitException((int)$cooldown['until']);
    $url = ($endpoint ?? stalker_endpoint($feed)).'?'.http_build_query(array_merge($params, ['type'=>$type,'action'=>$action,'JsHttpRequest'=>'1-xml']));
    $ips = stalker_public_addresses($url);
    $host = (string)parse_url($url,PHP_URL_HOST);
    $port = parse_url($url,PHP_URL_PORT) ?: (parse_url($url,PHP_URL_SCHEME)==='https' ? 443 : 80);
    $headers = ['Accept: application/json', 'X-User-Agent: Model: MAG250; Link: Ethernet'];
    if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
    $referer = preg_replace('#/(?:server/load|portal)\.php$#', '/c/', stalker_endpoint($feed));
    $jar = stalker_cookie_path($feed);
    if (function_exists('release_request_locks')) release_request_locks();
    $relayed = stalker_relay_read($feed, $url, $type, $action, $token, $ips[0], $referer, $jar);
    if ($relayed !== null) {
        [$status, $responseHeaders, $body] = $relayed;
        $retryAfter = $responseHeaders['retry-after'] ?? '';
        $ok = true;
    } else {
        $ch = curl_init($url);
        $body = ''; $retryAfter='';
        $resolvedIp = strpos($ips[0],':')!==false ? '['.$ips[0].']' : $ips[0];
        curl_setopt_array($ch, [CURLOPT_RESOLVE=>[$host.':'.$port.':'.$resolvedIp],CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>30,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_USERAGENT=>'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3',
            CURLOPT_REFERER=>$referer,
            CURLOPT_COOKIE=>'mac='.rawurlencode($feed['mac']).'; stb_lang=en; timezone=Europe/London',
            CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$retryAfter){if(stripos($line,'Retry-After:')===0)$retryAfter=trim(substr($line,12));return strlen($line);},
            CURLOPT_WRITEFUNCTION=>function ($handle, $chunk) use (&$body) { if (strlen($body)+strlen($chunk)>16*1024*1024) return 0; $body.=$chunk; return strlen($chunk); }]);
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    }
    if($status===429){
        $until=ctype_digit($retryAfter)?time()+min(31536000,(int)$retryAfter):(strtotime($retryAfter)?:0);
        $until=max(time()+60,$until);
        \Vaultcast\Repository::cachePut(stalker_cooldown_key($feed),['until'=>$until],max(60,$until-time()));
        throw new StalkerRateLimitException($until);
    }
    if (in_array($status,[401,403],true)) throw new StalkerAuthenticationException('Portal rejected authentication. Check the registered device and account.');
    if ($ok === false || $status < 200 || $status >= 300) throw new RuntimeException('Portal '.$type.'/'.$action.' request failed (HTTP '.$status.').',$status);
    $data = json_decode($body, true);
    if ($action==='do_auth' && isset($data['js']) && is_bool($data['js'])) return ['status'=>$data['js']];
    if (is_array($data) && array_key_exists('js',$data) && ($data['js']===null || $data['js']===false || (is_array($data['js']) && !empty($data['js']['error'])))) throw new StalkerAuthenticationException('Portal rejected the session or device.');
    if (!is_array($data) || !isset($data['js']) || !is_array($data['js'])) throw new RuntimeException('Portal returned an invalid response.');
    return $data['js'];
}

/** Preserve provider-issued cookie scope; media links may use a separate gateway. */
function stalker_media_cookies(array $feed,string $jar,string $url): void {
    $path=iptv_hls_jar_path($jar);
    if(!@copy(stalker_cookie_path($feed),$path))throw new RuntimeException('Could not create provider playback cookies.');
    @chmod($path,0600);
}

function stalker_session(array $feed, bool $renew = false): string {
    $key = 'stalker_session_'.hash('sha256', json_encode($feed));
    $cached = \Vaultcast\Repository::cacheGet($key) ?? [];
    if (!$renew && ($cached['expires'] ?? 0)>time() && !empty($cached['token'])) return $cached['token'];
    $endpoint = stalker_endpoint($feed);
    $candidates = [$endpoint];
    if (!preg_match('#\.php/?$#',(string)parse_url($feed['url'],PHP_URL_PATH))) {
        $candidates[] = preg_replace('#/(?:server/load|portal)\.php$#','/portal.php',$endpoint);
        $candidates[] = preg_replace('#/(?:server/load|portal)\.php$#','/server/load.php',$endpoint);
    }
    $lastError = null; $handshake = [];
    foreach (array_unique($candidates) as $candidate) {
        try {
            $handshake = stalker_request($feed,'stb','handshake',['token'=>''],'',$candidate);
            if (empty($handshake['token'])) throw new RuntimeException('Portal handshake returned no session token.');
            \Vaultcast\Repository::cachePut('stalker_endpoint_'.hash('sha256',$feed['url']),['path'=>parse_url($candidate,PHP_URL_PATH)],86400);
            $lastError = null; break;
        } catch (StalkerRateLimitException $e) { throw $e; } catch (RuntimeException $e) { $lastError = $e; }
    }
    if ($lastError) throw $lastError;
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

function stalker_call(array $feed, string $action, array $params = [], string $type = 'itv'): array {
    $token = stalker_session($feed);
    try { return stalker_request($feed, $type, $action, $params, $token); }
    catch (StalkerAuthenticationException $e) { return stalker_request($feed, $type, $action, $params, stalker_session($feed, true)); }
}

function stalker_channel_url(string $feed, string $id): string {
    // Root-relative references avoid trusting the request Host header or storing provider tokens.
    return rtrim(\Vaultcast\Database::basePath(), '/').'/api/live/stalker_stream?feed='.rawurlencode($feed).'&channel='.rawurlencode($id).'&format=.m3u8';
}

/** Keep the provider's link semantics alongside its private playback command. */
function stalker_live_entry(array $row): array {
    $entry=['cmd'=>(string)($row['cmd']??'')];
    foreach(['use_http_tmp_link','use_load_balancing'] as $flag){
        if(!isset($row[$flag])||!is_scalar($row[$flag]))continue;
        $value=filter_var($row[$flag],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if($value!==null)$entry[$flag]=$value;
    }
    return $entry;
}

function stalker_static_live_url(array $entry): ?string {
    if(!array_key_exists('use_http_tmp_link',$entry)||!array_key_exists('use_load_balancing',$entry)||$entry['use_http_tmp_link']||$entry['use_load_balancing'])return null;
    if(!preg_match('#(?:^|\s)(https?://[^\s]+)#',$entry['cmd'],$match))return null;
    $url=$match[1];$parts=parse_url($url);
    if(!$parts||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||preg_match('/[\r\n]/',$url))return null;
    $host=strtolower(trim($parts['host'],'[]'));
    // Middleware placeholders must still be exchanged through create_link.
    if($host==='localhost'||substr($host,-10)==='.localhost'||(filter_var($host,FILTER_VALIDATE_IP)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))||preg_match('#^/ch/#',$parts['path']??''))return null;
    stalker_public_addresses($url);
    return $url;
}

function stalker_index(array $feed, bool $refresh = false): array {
    $key = 'stalker_channels_v3_'.hash('sha256', json_encode($feed));
    $cached = \Vaultcast\Repository::cacheGet($key) ?? [];
    if (!$refresh && ($cached['expires'] ?? 0)>time()) return $cached;
    $groups = stalker_live_groups($feed);
    $result = stalker_call($feed, 'get_all_channels');
    $rows = $result['data'] ?? $result; $channels = []; $commands = [];
    if (!is_array($rows)) throw new RuntimeException('Provider returned an invalid live channel list.');
    if((int)($result['total_items']??0)>count($rows))throw new RuntimeException('Provider returned a partial live catalogue; use its paginated channel endpoint.');
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id']) || empty($row['cmd'])) continue;
        $id = (string)$row['id']; $group = $groups[(string)($row['tv_genre_id'] ?? '')] ?? '';
        $commands[$id] = (string)$row['cmd'];
        \Vaultcast\Repository::cachePut(stalker_live_key($feed,$id),stalker_live_entry($row),7200);
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

function stalker_live_key(array $feed,string $id): string {return 'stalker_live_v3_'.hash('sha256',json_encode([$feed,$id,StalkerCatalogues::active($feed,'live')])) ;}

function stalker_resolve(array $feed, string $id): string {
    if(StalkerCatalogues::expired($feed))throw new InvalidArgumentException('This provider subscription has expired.');
    $cached=StalkerLiveBrowse::entry($feed,$id)['playback']??null;
    if (!$cached) {
        $countryEntry=StalkerCountries::entry($feed,$id);
        $cached=$countryEntry['playback']??null;
        if($cached)\Vaultcast\Repository::cachePut(stalker_live_key($feed,$id),$cached,7200);
    }
    if ($cached) {
        $direct=stalker_static_live_url($cached);
        if($direct!==null)return $direct;
        $command=$cached['cmd'];
    }
    else {
        throw new InvalidArgumentException('This channel is not in the shared catalogue. Ask an administrator to load it in Stalker settings.');
    }
    $result = stalker_call($feed,'create_link',['cmd'=>$command,'series'=>'','forced_storage'=>'undefined','disable_ad'=>0,'download'=>0]);
    $cmd = (string)($result['cmd'] ?? '');
    if ($cmd === '') {
        stalker_session($feed,true);
        $result = stalker_call($feed,'create_link',['cmd'=>$command,'forced_storage'=>'undefined','disable_ad'=>0]);
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
    $data = StalkerLiveBrowse::snapshot($feed)['guide']??[];
    $out = [];
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

function stalker_item_key(array $feed, string $id): string {
    return 'stalker_item_v2_'.hash('sha256',json_encode([$feed,$id,StalkerCatalogues::active($feed,strpos($id,'series:')===0?'series':'vod')]));
}

/** Learn whether this portal numbers ordered-list pages from zero or one. */
function stalker_ordered_page(array $feed, string $type, array $params, int $page): array {
    $key='stalker_paging_v2_'.hash('sha256',json_encode([$feed,$type,$params]));
    $mode=\Vaultcast\Repository::cacheGet($key);
    if ($mode) { $params['p']=$page-1+$mode['offset'];return stalker_call($feed,'get_ordered_list',$params,$type); }
    $params['p']=0;
    $zero=stalker_call($feed,'get_ordered_list',$params,$type);
    $params['p']=1;$one=stalker_call($feed,'get_ordered_list',$params,$type);
    if(in_array($type,['vod','series'],true)){StalkerCatalogueScan::page($zero);StalkerCatalogueScan::page($one);}
    $first=is_array($zero['data'] ?? null)?$zero['data']:[];
    $second=is_array($one['data'] ?? null)?$one['data']:[];
    $offset=(!$first || array_column($first,'id')===array_column($second,'id'))?1:0;
    if ($first || $second) \Vaultcast\Repository::cachePut($key,['offset'=>$offset],86400);
    if ($page===1) return $offset===1?$one:$zero;
    $params['p']=$page-1+$offset;
    return stalker_call($feed,'get_ordered_list',$params,$type);
}

/**
 * Connection diagnostics inspect the provider directly, not the legacy SQL
 * catalogue. A count reported by an empty local catalogue is not a portal count.
 * Keep requests bounded and do not persist fetched channel rows.
 */
/**
 * Check a small number of account endpoints, without exposing profile details.
 * Missing expiry and failed account endpoints have different diagnostic outcomes.
 */
function stalker_account_expiry(array $feed): array {
    $responses=[];$worked=[];$errors=[];
    $requests=[
        ['account_info','get_main_info',[]],
        ['stb','get_profile',[
            'hd'=>1,'stb_type'=>'MAG250','sn'=>$feed['serial']??'',
            'device_id'=>$feed['deviceId']??'','device_id2'=>$feed['deviceId2']??'',
            'auth_second_step'=>1,'image_version'=>218,'hw_version'=>'1.7-BD-00',
            'num_banks'=>2,'not_valid_token'=>0,
            'ver'=>'ImageDescription: 0.2.18-r14-pub-250; PORTAL version: 5.5.0; API Version: JS API version: 328; STB API version: 134; Player Engine version: 0x58c','client_type'=>'STB','video_out'=>'hdmi'
        ]]
    ];
    foreach ($requests as $request) {
        [$type,$action,$params]=$request;
        try {
            $data=stalker_call($feed,$action,$params,$type);
            $responses[]=$data;$worked[]=$type.'/'.$action;
        } catch (RuntimeException $ignored) {
            $errors[]=$type.'/'.$action;
        }
    }
    // MAG portals commonly expose the human-readable expiry in account_info.phone.
    // Treat that field as a date only when it looks like one, never as a telephone number.
    $fields=['expire_billing_date','end_date','expire_date','expiration_date','expires_at','subscription_end','expiration','expiry_date','expiry','exp_date','tariff_expired_date','tariff_instead_expired','phone'];
    foreach ($responses as $response) {
        $pending=[[$response,0]];
        while($pending) {
            [$data,$depth]=array_pop($pending);
            if(!is_array($data))continue;
            foreach ($data as $key=>$value) {
                if(is_string($key)&&in_array(strtolower($key),$fields,true)&&is_scalar($value)) {
                    $raw=trim((string)$value);
                    if($raw===''||in_array(strtolower($raw),['0','0000-00-00','0000-00-00 00:00:00','unlimited','null','false'],true))continue;
                    if(strtolower($key)==='phone'&&!preg_match('/(?:[.\/-]|[a-z]{3,}|\\s\\d{4}\\b)/i',$raw))continue;
                    $timestamp=null;
                    if(ctype_digit($raw)) {
                        $number=(int)$raw;
                        $timestamp=$number>1000000000000?(int)floor($number/1000):$number;
                    } else {
                        $parsed=strtotime($raw);
                        if($parsed!==false)$timestamp=$parsed;
                    }
                    if($timestamp!==null&&$timestamp>=946684800&&$timestamp<=4102444800) {
                        // A provider date without a time must stay date-only.
                        // Preserve a provider's wall-clock time if it gives no timezone;
                        // numeric Unix timestamps and timezone-qualified values are UTC.
                        $hasTime=ctype_digit($raw)||preg_match('/(?:T|\\s)(\\d{1,2}):(\\d{2})(?::\\d{2})?/', $raw, $timeMatch);
                        $hasZone=ctype_digit($raw)||preg_match('/(?:Z|[+-]\\d{2}:?\\d{2})$/i', $raw);
                        $clock=$hasTime?($hasZone?gmdate('H:i',$timestamp):sprintf('%02d:%s',(int)$timeMatch[1],$timeMatch[2])):null;
                        $date=$hasZone?gmdate('Y-m-d',$timestamp):($hasTime||!ctype_digit($raw)?date('Y-m-d',$timestamp):gmdate('Y-m-d',$timestamp));
                        return ['date'=>$date,'time'=>$clock,'timeZone'=>$clock?($hasZone?'UTC':'provider time'):null,
                            'expires'=>$hasTime?$timestamp:strtotime($date.' +1 day'),
                            'status'=>'found','sources'=>$worked];
                    }
                }
                if($depth<3&&is_array($value))$pending[]=[$value,$depth+1];
            }
        }
    }
    return ['date'=>null,'status'=>$worked?'not_exposed':'account_requests_failed','sources'=>$worked];
}

function stalker_connection_check(array $feed): array {
    stalker_session($feed,true);
    $lastError=null;$reported=null;$sample=0;
    foreach ([0,1] as $page) {
        try {
            $result=stalker_call($feed,'get_ordered_list',['sortby'=>'number','genre'=>'*','search'=>'','fav'=>0,'p'=>$page],'itv');
            $rows=$result['data']??null;
            if(!is_array($rows))throw new RuntimeException('Portal returned an invalid ordered channel page.');
            $sample=max($sample,count($rows));
            if(isset($result['total_items'])&&is_numeric($result['total_items']))$reported=max((int)($reported??0),(int)$result['total_items']);
            if($sample>0)break;
        } catch (RuntimeException $e) {$lastError=$e;}
    }
    if($sample===0){
        // Some portals only implement the bulk channel action. Use it only if
        // the lightweight ordered-page probe returned no actual channels.
        try {
            $result=stalker_call($feed,'get_all_channels');
            $rows=$result['data']??$result;
            if(!is_array($rows))throw new RuntimeException('Portal returned an invalid channel list.');
            $sample=count($rows);
            if(isset($result['total_items'])&&is_numeric($result['total_items']))$reported=max((int)($reported??0),(int)$result['total_items']);
        } catch (RuntimeException $e) {
            if($lastError!==null)throw new RuntimeException('Portal session connected, but channel requests failed: '.$e->getMessage());
            throw $e;
        }
    }
    $expiry=stalker_account_expiry($feed);
    return ['ok'=>true,'expiryDate'=>$expiry['date'],'expiryTime'=>$expiry['time']??null,
        'expiryTimeZone'=>$expiry['timeZone']??null,'expiryStatus'=>$expiry['status'],
        'expirySources'=>$expiry['sources'],
        'channelCount'=>$reported!==null&&$reported>0?$reported:($sample>0?$sample:null),
        'sampleCount'=>$sample,'countEstimated'=>!($reported!==null&&$reported>0),
        'endpoint'=>stalker_endpoint($feed)];
}

function stalker_live_scan_key(array $feed): string {return 'stalker_live_search_v2_'.hash('sha256',json_encode($feed));}

function stalker_live_groups(array $feed): array {
    $key='stalker_live_groups_'.hash('sha256',json_encode($feed));$cached=\Vaultcast\Repository::cacheGet($key);if($cached!==null)return $cached;
    $groups=[];
    try{$genres=stalker_call($feed,'get_genres');foreach(($genres['data']??$genres) as $genre)if(is_array($genre)&&isset($genre['id']))$groups[(string)$genre['id']]=(string)($genre['title']??$genre['name']??'Other channels');}
    catch(RuntimeException $ignored){}
    \Vaultcast\Repository::cachePut($key,$groups,900);return $groups;
}

/** Advance a complete live-channel index by one provider page per request. */
function stalker_live_search_index(array $feed,bool $refresh=false): array {
    $key=stalker_live_scan_key($feed);
    $initial=['rows'=>[],'next'=>1,'processed'=>0,'total'=>null,'done'=>false,'signatures'=>[]];
    $dir=sys_get_temp_dir().'/vaultcast_stalker';if(!is_dir($dir))@mkdir($dir,0700,true);
    $lock=@fopen($dir.'/scan-'.hash('sha256',$key).'.lock','c');
    if(!$lock)throw new RuntimeException('Could not open channel search storage.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return \Vaultcast\Repository::cacheGet($key)??$initial;}
    try{
        $state=$refresh?$initial:(\Vaultcast\Repository::cacheGet($key)??$initial);
        if($state['done'])return $state;
        $groups=stalker_live_groups($feed);
        if($state['next']>10000)throw new RuntimeException('The provider channel list exceeded the search limit.');
        // Do not trust provider-side search or genre filters: fetch every channel,
        // then apply the user's query to the accumulated catalogue ourselves.
        $result=stalker_ordered_page($feed,'itv',['sortby'=>'number','genre'=>'*','search'=>'','fav'=>0],$state['next']);
        $rows=$result['data']??null;
        if(!is_array($rows))throw new RuntimeException('Provider returned an invalid channel page.');
        $signature=hash('sha256',json_encode(array_column($rows,'id')));
        if($rows&&isset($state['signatures'][$signature]))throw new RuntimeException('The provider repeated a channel page. Search could not finish scanning the full catalogue.');
        if($rows)$state['signatures'][$signature]=true;
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||empty($row['cmd']))continue;
            $id=(string)$row['id'];
            $genre=(string)($row['tv_genre_id']??'');
            $state['rows'][$id]=['id'=>$id,'name'=>(string)($row['name']??$id),'description'=>$groups[$genre]??'Other channels','kind'=>'live','genre'=>$genre];
            \Vaultcast\Repository::cachePut(stalker_live_key($feed,$id),stalker_live_entry($row),7200);
        }
        $state['processed']+=count($rows);$state['next']++;
        if(isset($result['total_items'])&&is_numeric($result['total_items'])&&((int)$result['total_items']>0||!$rows))$state['total']=max(0,(int)$result['total_items']);
        $state['done']=$state['total']!==null?$state['processed']>=$state['total']:!$rows;
        if(!$rows&&!$state['done'])throw new RuntimeException('The provider stopped returning channels before the catalogue was complete.');
        \Vaultcast\Repository::cachePut($key,$state,3600);
        return $state;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/** Paginated provider catalogue. Playback commands stay in the server cache. */
function stalker_catalogue(array $feed, array $opts): array {
    $kind = (string)($opts['kind'] ?? 'live');
    if (!in_array($kind,['live','vod','series'],true)) throw new InvalidArgumentException('Unknown Stalker catalogue.');
    $page = max(1,min(10000,(int)($opts['page'] ?? 1)));
    $query = mb_substr(trim((string)($opts['q'] ?? '')),0,200);
    $category = mb_substr((string)($opts['category'] ?? ''),0,120);
    $parent = mb_substr((string)($opts['parent'] ?? ''),0,120);
    if($kind==='live')return StalkerDirect::countries(['feeds'=>[$feed]],$opts);
    if($parent!=='')return StalkerVod::children($feed,$opts);
    return StalkerVod::browse(['feeds'=>[$feed]],$opts);
}

function stalker_vod_entry(array $feed,string $id): ?array {
    if(StalkerCatalogues::expired($feed))return null;
    $isEpisode=strpos($id,'series:')===0;
    if($isEpisode){
        $episode=StalkerDirect::item($feed,$id);
        if($episode)return $episode;
    }
    $catalogued=StalkerLibrary::entry($feed,$id);if($catalogued)return $catalogued;
    $direct=$isEpisode?null:StalkerDirect::item($feed,$id);
    if($direct)return $direct;
    $key=stalker_item_key($feed,$id);$item=\Vaultcast\Repository::cacheGet($key);
    if(!$item){$item=StalkerVod::entry($feed,$id);if($item)\Vaultcast\Repository::cachePut($key,$item,7200);}
    return $item;
}

function stalker_vod_resolve(array $feed, string $id, ?array $item = null): string {
    $item=$item ?? stalker_vod_entry($feed,$id);
    if (!$item) throw new RuntimeException('This catalogue entry has expired. Refresh the Stalker page and select it again.');
    $command=(string)($item['cmd']??'');
    if($command==='')throw new RuntimeException('The selected provider title has no playback command. Refresh the provider page.');
    $params=['cmd'=>$command,'series'=>(string)($item['series']??''),'forced_storage'=>'undefined','disable_ad'=>0,'download'=>0];
    $link='';
    try {
        $result=stalker_call($feed,'create_link',$params,'vod');
        $link=(string)($result['cmd']??$result['url']??'');
    } catch(RuntimeException $error) {
        // A few Ministra portals route series playback through the series API.
        if(strpos($id,'series:')===0){
            try{$result=stalker_call($feed,'create_link',$params,'series');$link=(string)($result['cmd']??$result['url']??'');}
            catch(RuntimeException $ignored){}
        }
        if($link==='')$link=$command;
        if(!preg_match('#(?:^|\\s)(https?://[^\\s]+)#',$link))throw $error;
    }
    if(!preg_match('#(?:^|\\s)(https?://[^\\s]+)#',$link,$match))throw new RuntimeException('Provider did not return a playable video link.');
    $url=$match[1];$parts=parse_url($url);
    if (!$parts || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\r\n]/',$url)) throw new RuntimeException('Provider returned an invalid video link.');
    stalker_public_addresses($url);
    return $url;
}

function stalker_channel_detail(array $feed,string $id,bool $refresh=false,bool $includeGuide=true): array {
    $presentation=StalkerLiveBrowse::presentation($feed,$id);
    $item=\Vaultcast\Repository::cacheGet('stalker_channel_v2_'.hash('sha256',json_encode([$feed,$id,StalkerCatalogues::active($feed,'live')])));
    $scan=\Vaultcast\Repository::cacheGet(stalker_live_scan_key($feed));
    if(!$item)$item=$scan['rows'][$id]??null;
    if(!$item){$item=StalkerCountries::entry($feed,$id);if($item)unset($item['playback']);}
    if(!$item)$item=['name'=>'Channel '.$id,'description'=>$feed['name']];
    if(empty($item['name']))$item['name']=$presentation['name']??'Channel '.$id;
    $guideError='';$programs=$includeGuide?($presentation['programs']??[]):[];
    if($includeGuide&&(!$programs||$refresh))try{
        $key='stalker_channel_epg_'.hash('sha256',json_encode($feed));$guide=$refresh?null:\Vaultcast\Repository::cacheGet($key);
        if($guide===null)$guide=stalker_guide($feed);
        if(!empty($guide['__unavailable']))$guideError='The provider programme guide is currently unavailable.';
        $stableId='stalker-'.substr(hash('sha256',$feed['id'].':'.$id),0,20);
        foreach($guide[$stableId]??[] as $event){
            $overlap=false;foreach($programs as $known)if($event['startUnix']<$known['endUnix']&&$event['endUnix']>$known['startUnix']){$overlap=true;break;}
            if(!$overlap)$programs[]=$event;
        }
    }catch(RuntimeException $e){$guideError='The provider programme guide is currently unavailable.';\Vaultcast\Repository::cachePut($key,['__unavailable'=>true],300);}
    usort($programs,static function($a,$b){return $a['startUnix']<=>$b['startUnix'];});
    $now=(int)round(microtime(true)*1000);$programs=array_values(array_filter($programs,static function($p)use($now){return $p['endUnix']>$now-3600000;}));
    return ['ok'=>true,'channel'=>['id'=>'stalker:'.$feed['id'].':'.$id,'provider'=>'stalker','kind'=>'iptv','number'=>ctype_digit($id)?(int)$id:0,'name'=>$presentation['name']??$item['name'],'slogan'=>($item['description']??'')?:$feed['name'],'logo'=>$presentation['logo']??'','programs'=>$programs],'guideError'=>$guideError];
}

/** Bounded GET of the actual media endpoint; never return its URL or body. */
function stalker_check_media(array $feed,string $id,string $kind,string $profile='stb'): array {
    require_once __DIR__.'/iptv_hls.php';
    if(!in_array($profile,['stb','http1','vlc','browser'],true)||($kind!=='live'&&!in_array($profile,['stb','browser'],true)))throw new InvalidArgumentException('Unknown playback check profile.');
    $url=$kind==='live'?stalker_resolve($feed,$id):stalker_vod_resolve($feed,$id);
    $entry=$kind==='live'?\Vaultcast\Repository::cacheGet(stalker_live_key($feed,$id)):null;
    $linkMode=$entry&&stalker_static_live_url($entry)!==null?'static':'create_link';
    $ua='Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3';
    if($profile==='vlc')$ua='VLC/3.0.21 LibVLC/3.0.21';
    if($profile==='browser')$ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    $browserRange=$profile==='browser';
    $probeJar='st'.bin2hex(random_bytes(16));stalker_media_cookies($feed,$probeJar,$url);$probeCookies=iptv_hls_jar_path($probeJar);
    try {
    for($hop=0;$hop<5;$hop++){
        $ips=stalker_public_addresses($url);$p=parse_url($url);$host=$p['host'];$port=$p['port']??($p['scheme']==='https'?443:80);$ip=strpos($ips[0],':')!==false?'['.$ips[0].']':$ips[0];
        $body='';$headers=[];$ch=curl_init($url);
        $mediaHeaders=['Accept: video/*, application/vnd.apple.mpegurl, application/x-mpegURL, */*;q=0.8'];
        if($browserRange)$mediaHeaders[]='Range: bytes=0-8191';
        curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>$ua,CURLOPT_COOKIEFILE=>$probeCookies,CURLOPT_COOKIEJAR=>$probeCookies,CURLOPT_HTTPHEADER=>$mediaHeaders,
            CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$headers){if(preg_match('#^HTTP/#',$line))$headers=[];elseif(strpos($line,':')!==false){[$key,$value]=explode(':',$line,2);$headers[strtolower(trim($key))]=trim($value);}return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$body){$body.=substr($chunk,0,max(0,8192-strlen($body)));return strlen($body)>=8192?0:strlen($chunk);}]);
        if(in_array($profile,['stb','http1'],true))curl_setopt($ch,CURLOPT_REFERER,preg_replace('#/(?:server/load|portal)\.php$#','/c/',stalker_endpoint($feed)));
        if($profile!=='stb')curl_setopt($ch,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        $type=strtolower(explode(';',$headers['content-type']??'unknown')[0]);
        if($browserRange&&($status===458||($status===400&&$type==='video/mp4'))){$browserRange=false;$hop--;continue;}
        if($status>=300&&$status<400&&!empty($headers['location'])){$url=iptv_hls_resolve_uri($url,$headers['location']);continue;}
        if($status===400&&$type==='video/mp4'&&strpos(substr($body,0,64),'ftyp')!==false)$status=200;
        $format=strpos(ltrim($body),'#EXTM3U')===0?'HLS playlist':((strlen($body)>188&&ord($body[0])===71&&ord($body[188])===71)?'MPEG-TS stream':(strpos($type,'video/')===0?'Video stream':(strpos($type,'html')!==false?'HTML page':'Unrecognised response')));
        return ['ok'=>true,'http'=>$status,'method'=>'GET','profile'=>$profile,'linkMode'=>$linkMode,'temporaryLink'=>$entry['use_http_tmp_link']??null,'loadBalancing'=>$entry['use_load_balancing']??null,'type'=>$type,'format'=>$format,'redirects'=>$hop,'https'=>$p['scheme']==='https','samePortalHost'=>strtolower($host)===strtolower((string)parse_url(stalker_endpoint($feed),PHP_URL_HOST)),'cloudflare'=>stripos($body,'cloudflare')!==false,'allow'=>preg_replace('/[^A-Z, ]/','',(string)($headers['allow']??'')),'received'=>strlen($body),'timedOutOrFailed'=>$ok===false&&strlen($body)<8192];
    }
    throw new RuntimeException('The media endpoint redirected too many times.');
    } finally {@unlink($probeCookies);}
}
