<?php
/** Compatibility with the shared IPTV lineup and its guide. */
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

function stalker_channel_url(string $feed, string $id): string {
    // Root-relative references avoid trusting the request Host header or storing provider tokens.
    return rtrim(\Vaultcast\Database::basePath(), '/').'/api/live/stalker_stream?feed='.rawurlencode($feed).'&channel='.rawurlencode($id).'&format=.m3u8';
}

/** Provider guide rows, keyed by the same stable IDs as the saved lineup. */
function stalker_guide(array $feed,?array $ids=null): array {
    $programmes=StalkerLiveBrowse::lineupGuide($feed,$ids);
    if($programmes!==null)return $programmes;
    $wanted=$ids===null?null:array_fill_keys($ids,true);
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
            if($wanted!==null&&!isset($wanted[$id]))continue;
            $out[$id][] = ['startUnix'=>$start*1000,'endUnix'=>$end*1000,'title'=>(string)($row['name'] ?? $row['title'] ?? ''),'subtitle'=>(string)($row['descr'] ?? ''),'kind'=>'epg'];
        }
    }
    return $out;
}

/** Delegate a legacy IPTV settings request using the current account. */
function stalker_node_post(string $action,array $body): array {
    if(!in_array($action,['iptv_probe','stalker_manage'],true))throw new InvalidArgumentException('Unknown provider action.');
    $headers=['Content-Type: application/json','Connection: close'];
    $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
    $cookie=$_COOKIE[Auth::COOKIE]??'';
    if(preg_match('/^Bearer[ \t]+vc_[a-f0-9]{64}$/iD',$auth))$headers[]='Authorization: '.$auth;
    elseif(is_string($cookie)&&preg_match('/^[a-f0-9]{64}$/D',$cookie))$headers[]='Cookie: '.Auth::COOKIE.'='.$cookie;
    else throw new RuntimeException('Sign in to check this provider.');
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),
        'content'=>json_encode($body),'follow_location'=>0,'ignore_errors'=>true,'timeout'=>90]]);
    $raw=@file_get_contents(NodeRuntime::loopback('/application/live/'.$action),false,$context,0,65536);
    $result=is_string($raw)?json_decode($raw,true):null;
    if(empty($result['ok']))throw new RuntimeException($result['error']??'The provider service is unavailable.');
    return $result;
}
