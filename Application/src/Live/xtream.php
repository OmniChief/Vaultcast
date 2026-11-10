<?php
/** Xtream-compatible Player API. Provider secrets and playback URLs stay server-side. */
require_once __DIR__.'/stalker.php';
require_once __DIR__.'/iptv_hls.php';

function xtream_connections(): array {
    return \Vaultcast\Repository::document('xtream-connections', ['connections'=>[]])['connections'];
}
function xtream_public(array $feed): array {
    return array_intersect_key($feed,array_flip(['id','name','url','enabled']));
}
function xtream_http(string $url, bool $stream=false, ?callable $link=null): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Xtream Codes requires PHP cURL.');
    for ($redirect=0;$redirect<6;$redirect++) {
        $parts=parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme']??''),['http','https'],true) || isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException('Invalid provider URL.');
        $ips=stalker_public_addresses($url);
        $host=$parts['host']; $port=$parts['port']??($parts['scheme']==='https'?443:80);
        $address=strpos($ips[0],':')!==false?'['.$ips[0].']':$ips[0];
        $body=''; $headers=[]; $status=0; $sent=false; $playlist=false;
        $requestHeaders=['Accept: */*'];
        if ($stream && preg_match('/^bytes=\d*-\d*$/D',$_SERVER['HTTP_RANGE']??'')) $requestHeaders[]='Range: '.$_SERVER['HTTP_RANGE'];
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RESOLVE=>[$host.':'.$port.':'.$address],CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>$stream?0:30,CURLOPT_LOW_SPEED_LIMIT=>1,CURLOPT_LOW_SPEED_TIME=>30,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_USERAGENT=>'Vaultcast/'.STREAM_VERSION,CURLOPT_HTTPHEADER=>$requestHeaders,
            CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers,&$status){
                if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m)){$status=(int)$m[1];$headers=[];}
                elseif(strpos($line,':')!==false){[$key,$value]=explode(':',$line,2);$headers[strtolower(trim($key))]=trim($value);}
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use($stream,$url,&$body,&$headers,&$status,&$sent,&$playlist){
                $length=strlen($chunk);
                if($status<200||$status>=300)return $length;
                if(!$stream){if(strlen($body)+$length>32*1024*1024)return 0;$body.=$chunk;return $length;}
                if(connection_aborted())return 0;
                if(!$sent){
                    $body.=$chunk;
                    $playlist=$playlist||preg_match('/mpegurl/i',$headers['content-type']??'')||preg_match('/\.m3u8(?:\?|$)/i',$url)||strpos(ltrim($body),'#EXTM3U')===0;
                    if($playlist)return strlen($body)>5*1024*1024?0:$length;
                    if(strlen($body)<16)return $length;
                    // Do not reflect provider error pages (which may contain credentials).
                    if(preg_match('/text\/html|application\/json/i',$headers['content-type']??''))return 0;
                    http_response_code($status);header('Content-Type: '.($headers['content-type']??'application/octet-stream'));
                    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Accel-Buffering: no');
                    foreach(['content-range','content-length','accept-ranges'] as $key)if(isset($headers[$key]))header($key.': '.$headers[$key]);
                    while(ob_get_level()>0){if(!@ob_end_flush())break;}
                    $sent=true;echo $body;$body='';
                }else echo $chunk;
                flush();return $length;
            }]);
        $ok=curl_exec($ch);curl_close($ch);
        if($sent)exit;
        if($status>=300&&$status<400&&isset($headers['location'])){$url=iptv_hls_resolve_uri($url,$headers['location']);continue;}
        if($ok===false||$status<200||$status>=300)throw new RuntimeException('Provider request failed (HTTP '.$status.').');
        if($stream){
            header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
            if($playlist){
                $lines=preg_split('/\r?\n/',$body);
                foreach($lines as &$line){$trim=trim($line);if($trim==='')continue;
                    if($trim[0]!=='#')$line=$link(iptv_hls_resolve_uri($url,$trim));
                    else $line=preg_replace_callback('/URI="([^"]+)"/i',function($m)use($url,$link){return 'URI="'.$link(iptv_hls_resolve_uri($url,$m[1])).'"';},$line);
                }unset($line);
                header('Content-Type: application/vnd.apple.mpegurl');echo implode("\n",$lines);
            }else{header('Content-Type: application/octet-stream');echo $body;}
            exit;
        }
        $data=json_decode($body,true);
        if(!is_array($data))throw new RuntimeException('Provider returned an invalid API response.');
        return $data;
    }
    throw new RuntimeException('Provider redirected too many times.');
}
function xtream_api(array $feed,string $action='',array $params=[],bool $refresh=false): array {
    $key='xtream-api:'.hash('sha256',json_encode([$feed,$action,$params]));
    if($action!==''&&!$refresh){$cached=\Vaultcast\Repository::cacheGet($key);if(is_array($cached))return $cached;}
    $query=['username'=>$feed['username'],'password'=>$feed['password']];
    if($action!=='')$query['action']=$action;
    $data=xtream_http($feed['url'].'/player_api.php?'.http_build_query(array_merge($query,$params)));
    if(isset($data['user_info'])&&(int)($data['user_info']['auth']??0)!==1)throw new RuntimeException('Provider rejected the username or password.');
    if($action!=='')\Vaultcast\Repository::cachePut($key,$data,300);
    return $data;
}
function xtream_ticket(array $feed,string $viewer,string $url,string $format=''): string {
    $ticket=bin2hex(random_bytes(24));
    \Vaultcast\Repository::cachePut('xtream-play:'.$ticket,['feed'=>$feed['id'],'fingerprint'=>hash('sha256',json_encode($feed)),'viewer'=>$viewer,'url'=>$url],21600);
    return rtrim(\Vaultcast\Database::basePath(),'/').'/api/live/xtream_stream?ticket='.$ticket.($format!==''?'&format=.'.$format:'');
}
function xtream_item(array $row,string $kind): array {
    return ['id'=>(string)($row['stream_id']??$row['series_id']??$row['id']??''),'name'=>(string)($row['name']??$row['title']??'Untitled'),
        'kind'=>$kind,'category'=>(string)($row['category_id']??''),'season'=>(int)($row['season']??0),'episode'=>(int)($row['episode_num']??0)];
}
function xtream_handle(string $action): void {
    $viewer=Auth::requireUser();header('Cache-Control: private, no-store');
    if(!in_array($action,['xtream_connections','xtream_manage','xtream_categories','xtream_browse','xtream_play','xtream_guide','xtream_stream'],true))json_error('Unknown Xtream action.',404);
    if(Parental::filtering())json_error('Provider catalogues are unavailable for restricted profiles.',403);
    $write=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true))json_error('Method not allowed.',405);
    if(in_array($action,['xtream_manage','xtream_play'],true)!==$write)json_error('Method not allowed.',405);
    $input=$write?body_json():$_GET;$feeds=xtream_connections();$id=(string)($input['feed']??'');$feed=$feeds[$id]??null;
    if($action==='xtream_connections')json_out(['ok'=>true,'connections'=>array_values(array_map('xtream_public',array_filter($feeds,static function($f)use($viewer){return !empty($f['enabled'])||Permissions::allowed($viewer,'iptv_manage');})))]);
    if($action==='xtream_manage'){
        Permissions::require('iptv_manage');$op=$input['operation']??'save';
        if($op==='remove'){if(!$feed)throw new InvalidArgumentException('Connection not found.');unset($feeds[$id]);}
        elseif($op==='check'){
            if(!$feed)throw new InvalidArgumentException('Save the connection first.');
            $data=xtream_api($feed);$user=$data['user_info']??[];
            if((int)($user['auth']??0)!==1)throw new RuntimeException('Provider rejected the account.');
            json_out(['ok'=>true,'account'=>array_intersect_key($user,array_flip(['status','exp_date','active_cons','max_connections']))]);
        }elseif($op==='save'){
            if($id!==''&&!$feed)throw new InvalidArgumentException('Connection no longer exists.');
            $value=$input['connection']??[];$url=rtrim(trim((string)($value['url']??'')),'/');
            $url=preg_replace('#/player_api\.php$#i','',$url);stalker_validate_url($url);
            $next=['id'=>$id?:'xtream-'.bin2hex(random_bytes(6)),'name'=>trim((string)($value['name']??'')),'url'=>$url,'enabled'=>!empty($value['enabled'])];
            if($next['name']===''||strlen($next['name'])>160)throw new InvalidArgumentException('Enter a connection name (up to 160 characters).');
            foreach(['username','password'] as $key){$v=(string)($value[$key]??'');if($v==='')$v=$feed[$key]??'';if($v===''||strlen($v)>512||preg_match('/[\r\n\x00]/',$v))throw new InvalidArgumentException('Enter a valid username and password.');$next[$key]=$v;}
            $feeds[$next['id']]=$next;
        }else throw new InvalidArgumentException('Unknown connection operation.');
        \Vaultcast\Repository::putDocument('xtream-connections',['connections'=>$feeds]);json_out(['ok'=>true]);
    }
    if($action==='xtream_stream'){
        $ticket=\Vaultcast\Repository::cacheGet('xtream-play:'.(string)($input['ticket']??''));$feed=$feeds[$ticket['feed']??'']??null;
        if(!$ticket||!$feed||empty($feed['enabled'])||$ticket['viewer']!==(string)$viewer['id']||$ticket['fingerprint']!==hash('sha256',json_encode($feed)))json_error('Playback expired. Select the title again.',403);
        release_request_locks();\Vaultcast\Database::releaseForStreaming();@set_time_limit(0);
        xtream_http($ticket['url'],true,static function($url)use($feed,$viewer){return xtream_ticket($feed,(string)$viewer['id'],$url);});
    }
    if(!$feed||empty($feed['enabled']))throw new InvalidArgumentException('Select an enabled Xtream Codes connection.');
    $kind=(string)($input['kind']??'live');if(!in_array($kind,['live','vod','series'],true))throw new InvalidArgumentException('Invalid library type.');
    $refresh=!empty($input['refresh']);
    if($action==='xtream_guide'){
        $channel=(string)($input['id']??'');if(!ctype_digit($channel))throw new InvalidArgumentException('Invalid channel.');
        $data=xtream_api($feed,'get_short_epg',['stream_id'=>$channel,'limit'=>8],$refresh);$programmes=[];
        foreach($data['epg_listings']??[] as $row){$title=base64_decode((string)($row['title']??''),true);$description=base64_decode((string)($row['description']??''),true);
            $programmes[]=['title'=>$title===false?(string)($row['title']??''):$title,'description'=>$description===false?'':$description,'start'=>(string)($row['start']??''),'end'=>(string)($row['end']??'')];}
        json_out(['ok'=>true,'programmes'=>$programmes]);
    }
    if($action==='xtream_categories'){
        $rows=xtream_api($feed,'get_'.$kind.'_categories',[],$refresh);$result=[];
        foreach($rows as $row)if(is_array($row))$result[]=['id'=>(string)($row['category_id']??''),'name'=>(string)($row['category_name']??'Other')];
        json_out(['ok'=>true,'categories'=>$result]);
    }
    $series=(string)($input['series']??'');
    if($series!==''){
        if($kind!=='series'||!ctype_digit($series))throw new InvalidArgumentException('Invalid series.');
        $detail=xtream_api($feed,'get_series_info',['series_id'=>$series],$refresh);$rows=[];
        foreach($detail['episodes']??[] as $episodes)foreach((array)$episodes as $episode)if(is_array($episode))$rows[]=$episode;
    }else{
        $params=[];if(($input['category']??'')!=='')$params['category_id']=(string)$input['category'];
        $rows=xtream_api($feed,$kind==='series'?'get_series':'get_'.$kind.'_streams',$params,$refresh);
    }
    if($action==='xtream_play'){
        if($kind==='series'&&$series==='')throw new InvalidArgumentException('Select an episode.');
        $selected=null;foreach($rows as $row)if(is_array($row)&&xtream_item($row,$kind)['id']===(string)($input['id']??'')){$selected=$row;break;}
        if(!$selected)throw new InvalidArgumentException('Title no longer available. Refresh the library.');
        $item=xtream_item($selected,$kind);if(!ctype_digit($item['id']))throw new RuntimeException('Provider returned an invalid stream ID.');
        $ext=$kind==='live'?'m3u8':(string)($selected['container_extension']??'mp4');
        if(!preg_match('/^[a-zA-Z0-9]{1,8}$/D',$ext))throw new RuntimeException('Provider returned an invalid media format.');
        $url=$feed['url'].'/'.($kind==='vod'?'movie':$kind).'/'.rawurlencode($feed['username']).'/'.rawurlencode($feed['password']).'/'.$item['id'].'.'.$ext;
        json_out(['ok'=>true,'source'=>['url'=>xtream_ticket($feed,(string)$viewer['id'],$url,$ext),'kind'=>$kind],'item'=>$item]);
    }
    if($action==='xtream_browse'){
        $items=[];$search=strtolower(trim((string)($input['q']??'')));
        foreach($rows as $row)if(is_array($row)){$item=xtream_item($row,$kind);if($search===''||strpos(strtolower($item['name']),$search)!==false)$items[]=$item;}
        $page=max(1,(int)($input['page']??1));json_out(['ok'=>true,'items'=>array_slice($items,($page-1)*60,60),'total'=>count($items),'page'=>$page]);
    }
    throw new InvalidArgumentException('Unknown Xtream action.');
}
