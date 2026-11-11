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
function xtream_config_lock(): void {
    $dir=STREAM_DATA.'/runtime/xtream';
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Cannot open connection settings storage.');
    $lock=@fopen($dir.'/configuration.lock','c+');
    if(!$lock)throw new RuntimeException('Cannot open connection settings lock.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new RuntimeException('Connection settings are being saved. Please try again.',409);}
    register_shutdown_function(static function()use($lock){flock($lock,LOCK_UN);fclose($lock);});
}
/** One playback owner per provider account, including duplicate saved connections. */
function xtream_account_path(array $feed): string {
    $dir=STREAM_DATA.'/runtime/xtream';
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Cannot create playback session storage.');
    $p=parse_url($feed['url']);
    return $dir.'/'.hash('sha256',json_encode([strtolower($p['host']),$p['port']??($p['scheme']==='https'?443:80),$feed['username']]));
}
function xtream_session(array $feed,?callable $change=null): array {
    $handle=@fopen(xtream_account_path($feed).'.session','c+');
    if(!$handle)throw new RuntimeException('Cannot open playback session storage.');
    try {
        if(!flock($handle,$change?LOCK_EX:LOCK_SH))throw new RuntimeException('Cannot lock playback session.');
        $state=json_decode((string)stream_get_contents($handle),true)?:[];
        if($change){$state=$change($state);rewind($handle);$body=json_encode($state);if(!ftruncate($handle,0)||fwrite($handle,$body)!==strlen($body)||!fflush($handle))throw new RuntimeException('Cannot save playback session.');}
        return $state;
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}
function xtream_session_current(array $feed,string $session): bool {
    $state=xtream_session($feed);
    return ($state['id']??'')===$session&&($state['expires']??0)>time();
}
function xtream_begin(array $feed,string $viewer,string $client,int $sequence): string {
    if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$client)||$sequence<1)throw new InvalidArgumentException('Reload Xtream Codes before playing.');
    $state=xtream_session($feed,static function($old)use($viewer,$client,$sequence){
        if(($old['expires']??0)>time()&&(($old['viewer']??'')!==$viewer||($old['client']??'')!==$client))throw new RuntimeException('This provider account is already playing in another tab or device. Stop that player first.',409);
        if(($old['client']??'')===$client&&($old['sequence']??0)>=$sequence)throw new RuntimeException('A newer playback selection has replaced this request.',409);
        return ['id'=>bin2hex(random_bytes(24)),'viewer'=>$viewer,'client'=>$client,'sequence'=>$sequence,'expires'=>time()+90];
    });
    return $state['id'];
}
function xtream_release(array $feed,string $session): void {
    xtream_session($feed,static function($state)use($session){if(($state['id']??'')===$session)$state['expires']=0;return $state;});
}
/** Held until cURL closes; queued HLS resources cannot open overlapping upstream sockets. */
function xtream_transfer_lock(array $feed,string $session) {
    $handle=@fopen(xtream_account_path($feed).'.transfer','c+');
    if(!$handle)throw new RuntimeException('Cannot open playback transfer lock.');
    $until=microtime(true)+12;
    do {
        if(!xtream_session_current($feed,$session)){fclose($handle);throw new RuntimeException('Playback was stopped or replaced.',409);}
        if(flock($handle,LOCK_EX|LOCK_NB))return $handle;
        usleep(50000);
    }while(microtime(true)<$until);
    fclose($handle);throw new RuntimeException('Previous provider request is still closing. Please select the title again shortly.',409);
}
function xtream_http(string $url, bool $stream=false, ?callable $link=null, ?callable $current=null, ?callable $resolved=null, string $cookie=''): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Xtream Codes requires PHP cURL.');
    $started=microtime(true);$lastCheck=0.0;$active=true;
    $stillCurrent=static function()use($current,&$lastCheck,&$active){if($current&&microtime(true)-$lastCheck>0.25){$lastCheck=microtime(true);$active=$current();}return $active&&!connection_aborted();};
    for ($redirect=0;$redirect<6;$redirect++) {
        if($stream&&!$stillCurrent())throw new RuntimeException('Playback was stopped or replaced.',409);
        $parts=parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme']??''),['http','https'],true) || isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException('Invalid provider URL.');
        $ips=stalker_public_addresses($url);
        $host=$parts['host']; $port=$parts['port']??($parts['scheme']==='https'?443:80);
        $address=strpos($ips[0],':')!==false?'['.$ips[0].']':$ips[0];
        $body=''; $headers=[]; $status=0; $sent=false; $playlist=false;
        $requestHeaders=['Accept: */*','Connection: close'];
        if ($stream && preg_match('/^bytes=\d*-\d*$/D',$_SERVER['HTTP_RANGE']??'')) $requestHeaders[]='Range: '.$_SERVER['HTTP_RANGE'];
        $node = !$stream && $cookie === '' ? NodeProvider::get($url, $host, $ips[0]) : null;
        if ($node !== null) {
            [$status, $headers, $body] = $node;
            $ok = true;
            $errno = 0;
        } else {
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RESOLVE=>[$host.':'.$port.':'.$address],CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>$stream?0:30,CURLOPT_LOW_SPEED_LIMIT=>1,CURLOPT_LOW_SPEED_TIME=>30,
                CURLOPT_FORBID_REUSE=>true,CURLOPT_NOPROGRESS=>false,
                CURLOPT_PROGRESSFUNCTION=>static function()use($stream,$stillCurrent,$started,&$sent){return $stream&&(!$stillCurrent()||(!$sent&&microtime(true)-$started>20))?1:0;},
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_USERAGENT=>'Vaultcast/'.STREAM_VERSION,CURLOPT_HTTPHEADER=>$requestHeaders,
                CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers,&$status){
                    if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m)){$status=(int)$m[1];$headers=[];}
                    elseif(strpos($line,':')!==false){[$key,$value]=explode(':',$line,2);$headers[strtolower(trim($key))]=trim($value);}
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use($stream,$url,$stillCurrent,$resolved,&$body,&$headers,&$status,&$sent,&$playlist){
                    $length=strlen($chunk);
                    if($status<200||$status>=300)return $length;
                    if(!$stream){if(strlen($body)+$length>32*1024*1024)return 0;$body.=$chunk;return $length;}
                    if(!$stillCurrent())return 0;
                    if(!$sent){
                        $body.=$chunk;
                        $playlist=$playlist||preg_match('/mpegurl/i',$headers['content-type']??'')||preg_match('/\.m3u8(?:\?|$)/i',$url)||strpos(ltrim($body),'#EXTM3U')===0;
                        if($playlist)return strlen($body)>5*1024*1024?0:$length;
                        if(strlen($body)<16)return $length;
                        // Do not reflect provider error pages (which may contain credentials).
                        if(preg_match('/text\/html|application\/json/i',$headers['content-type']??''))return 0;
                        if($resolved)$resolved($url);
                        http_response_code($status);header('Content-Type: '.($headers['content-type']??'application/octet-stream'));
                        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Accel-Buffering: no');
                        foreach(['content-range','content-length','accept-ranges'] as $key)if(isset($headers[$key]))header($key.': '.$headers[$key]);
                        while(ob_get_level()>0){if(!@ob_end_flush())break;}
                        $sent=true;echo $body;$body='';
                    }else echo $chunk;
                    flush();return $length;
                }]);
            if($cookie!=='')curl_setopt_array($ch,[CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);
            try{$ok=curl_exec($ch);$errno=curl_errno($ch);}finally{curl_close($ch);unset($ch);}
        }
        if($sent)exit;
        if($status>=300&&$status<400&&isset($headers['location'])){$url=iptv_hls_resolve_uri($url,$headers['location']);continue;}
        if($stream&&!$stillCurrent())throw new RuntimeException('Playback was stopped or replaced.',409);
        if($ok===false||$status<200||$status>=300){
            if($errno===CURLE_OPERATION_TIMEDOUT||($stream&&microtime(true)-$started>=20))throw new RuntimeException('Provider timed out. The request has been closed; select the title to retry.',504);
            throw new RuntimeException($status?'Provider refused the stream (HTTP '.$status.'). Check account connection limits or channel availability.':'Could not connect to the provider (transport code '.$errno.').',502);
        }
        if($stream){
            header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
            if($playlist){
                if($resolved)$resolved($url);
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
function xtream_ticket(array $feed,string $viewer,string $url,string $format='',string $session=''): string {
    $ticket=hash_hmac('sha256',$url,$session);
    \Vaultcast\Repository::cachePut('xtream-play:'.$ticket,['feed'=>$feed['id'],'fingerprint'=>hash('sha256',json_encode($feed)),'viewer'=>$viewer,'url'=>$url,'session'=>$session],21600);
    return rtrim(\Vaultcast\Database::basePath(),'/').'/api/live/xtream_stream?ticket='.$ticket.($format!==''?'&format=.'.$format:'');
}
function xtream_item(array $row,string $kind): array {
    return ['id'=>(string)($row['stream_id']??$row['series_id']??$row['id']??''),'name'=>(string)($row['name']??$row['title']??'Untitled'),
        'kind'=>$kind,'category'=>(string)($row['category_id']??''),'season'=>(int)($row['season']??0),'episode'=>(int)($row['episode_num']??0)];
}
function xtream_handle(string $action): void {
    $viewer=Auth::requireUser();header('Cache-Control: private, no-store');
    if(!in_array($action,['xtream_connections','xtream_manage','xtream_categories','xtream_browse','xtream_play','xtream_guide','xtream_stream','xtream_stop','xtream_heartbeat','xtream_import','xtream_import_status'],true))json_error('Unknown Xtream action.',404);
    if(Parental::filtering())json_error('Provider catalogues are unavailable for restricted profiles.',403);
    $write=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST','HEAD'],true)||($_SERVER['REQUEST_METHOD']==='HEAD'&&$action!=='xtream_stream'))json_error('Method not allowed.',405);
    if(in_array($action,['xtream_manage','xtream_play','xtream_stop','xtream_heartbeat','xtream_import'],true)!==$write)json_error('Method not allowed.',405);
    $input=$write?body_json():$_GET;
    if(in_array($action,['xtream_import','xtream_import_status','xtream_manage'],true)){
        Permissions::require('iptv_manage');
        if(($action==='xtream_manage'&&($input['operation']??'save')!=='check')||($action==='xtream_import'&&in_array($input['operation']??'',['add','configure'],true)))xtream_config_lock();
        if($action==='xtream_import_status')json_out(XtreamImport::status());
        if($action==='xtream_import')json_out(XtreamImport::handle($input,$viewer));
    }
    $feeds=xtream_connections();$id=(string)($input['feed']??'');$feed=$feeds[$id]??null;
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
        if($feed)xtream_session($feed,static function($state){$state['expires']=0;return $state;});
        \Vaultcast\Repository::putDocument('xtream-connections',['connections'=>$feeds]);json_out(['ok'=>true]);
    }
    if(in_array($action,['xtream_stream','xtream_stop','xtream_heartbeat'],true)){
        $ticket=\Vaultcast\Repository::cacheGet('xtream-play:'.(string)($input['ticket']??''));$feed=$feeds[$ticket['feed']??'']??null;
        if(!$ticket||!$feed||empty($feed['enabled'])||$ticket['viewer']!==(string)$viewer['id']||$ticket['fingerprint']!==hash('sha256',json_encode($feed)))json_error('Playback expired. Select the title again.',403);
        $session=(string)($ticket['session']??'');if($session==='')json_error('Playback changed after an update. Select the title again.',409);
        if($_SERVER['REQUEST_METHOD']==='HEAD'){
            if(!xtream_session_current($feed,$session))json_error('Playback was stopped or replaced.',409);
            // Browser metadata probes must not start another provider stream.
            header('Content-Type: '.(preg_match('/\.m3u8(?:\?|$)/i',$ticket['url'])?'application/vnd.apple.mpegurl':'application/octet-stream'));exit;
        }
        if($action==='xtream_stop'){xtream_release($feed,$session);json_out(['ok'=>true]);}
        if($action==='xtream_heartbeat'){
            xtream_session($feed,static function($state)use($session){if(($state['id']??'')!==$session||($state['expires']??0)<=time())throw new RuntimeException('Playback was stopped or replaced.',409);$state['expires']=time()+90;return $state;});json_out(['ok'=>true]);
        }
        release_request_locks();\Vaultcast\Database::releaseForStreaming();@set_time_limit(0);@ignore_user_abort(true);
        $lock=xtream_transfer_lock($feed,$session);
        // exit inside the streaming helper still releases the OS lock at request shutdown.
        register_shutdown_function(static function()use($lock){if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}});
        $resolvedKey='xtream-resolved:'.hash('sha256',$session.$ticket['url']);$saved=\Vaultcast\Repository::cacheGet($resolvedKey);
        $cookie=xtream_account_path($feed).'.cookies';
        try {
            xtream_http($saved['url']??$ticket['url'],true,
                static function($url)use($feed,$viewer,$session){return xtream_ticket($feed,(string)$viewer['id'],$url,'',$session);},
                static function()use($feed,$session){return xtream_session_current($feed,$session);},
                static function($url)use($resolvedKey){\Vaultcast\Repository::cachePut($resolvedKey,['url'=>$url],21600);},$cookie);
        }catch(Throwable $error){xtream_release($feed,$session);throw $error;}
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
        $session=xtream_begin($feed,(string)$viewer['id'],(string)($input['client']??''),(int)($input['sequence']??0));
        json_out(['ok'=>true,'source'=>['url'=>xtream_ticket($feed,(string)$viewer['id'],$url,$ext,$session),'kind'=>$kind],'item'=>$item]);
    }
    if($action==='xtream_browse'){
        $items=[];$search=strtolower(trim((string)($input['q']??'')));
        foreach($rows as $row)if(is_array($row)){$item=xtream_item($row,$kind);if($search===''||strpos(strtolower($item['name']),$search)!==false)$items[]=$item;}
        $page=max(1,(int)($input['page']??1));json_out(['ok'=>true,'items'=>array_slice($items,($page-1)*60,60),'total'=>count($items),'page'=>$page]);
    }
    throw new InvalidArgumentException('Unknown Xtream action.');
}
