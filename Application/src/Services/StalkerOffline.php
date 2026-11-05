<?php
declare(strict_types=1);

final class StalkerOffline {
    private static function root(): string { $p=STREAM_DATA.'/stalker-offline';if(!is_dir($p)&&!@mkdir($p,0700,true))throw new RuntimeException('Cannot create offline storage.');return $p; }
    private static function job(string $id): array {if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new InvalidArgumentException('Invalid offline download.');$j=\Vaultcast\Repository::document('stalker-offline:'.$id,[]);if(!$j)throw new RuntimeException('Offline download not found.');return $j;}
    private static function save(array $j): void {if(is_file(self::root().'/'.$j['id'].'.delete'))return;\Vaultcast\Repository::putDocument('stalker-offline:'.$j['id'],$j);}
    private static function purgeDeletedFiles(string $id): void {$dir=self::root().'/'.$id;foreach(glob($dir.'/*')?:[] as $path)if(is_file($path)||is_link($path))@unlink($path);@rmdir($dir);$q=\Vaultcast\Database::connection()->prepare('DELETE FROM vaultcast_documents WHERE name=?');$q->execute(['stalker-offline:'.$id]);@unlink(self::root().'/'.$id.'.delete');}
    private static function purgeIfUnlocked(string $id): void {$lock=@fopen(self::root().'/'.$id.'.lock','c');if($lock&&flock($lock,LOCK_EX|LOCK_NB)){if(is_file(self::root().'/'.$id.'.delete'))self::purgeDeletedFiles($id);flock($lock,LOCK_UN);}if(is_resource($lock))fclose($lock);}
    public static function listing(): array {
        $rows=\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");
        foreach($rows as &$row){
            if(!in_array($row['status']??'', ['queued','downloading'],true))continue;
            $dir=self::root().'/'.$row['id'];$bytes=0;
            foreach(glob($dir.'/*')?:[] as $file)if(is_file($file)&&preg_match('/^(?:source(?:\.part)?|segment-\d+(?:\.part)?|video(?:\.part)?\.mkv)$/',basename($file)))$bytes+=(int)filesize($file);
            $progressMeta=$dir.'/source.part.json';if(!is_file($progressMeta))$progressMeta=$dir.'/source.json';
            $meta=is_file($progressMeta)?json_decode((string)file_get_contents($progressMeta),true):[];
            $row['bytes']=max((int)($row['bytes']??0),$bytes,(int)($meta['downloaded_bytes']??0));
            $row['speedBytesPerSecond']=time()-(int)($meta['progress_at']??0)<=5?(int)($meta['speed_bytes_per_second']??0):0;
            $row['providerHttpStatus']=(int)($meta['http_status']??0);
            $row['percent']=null;$row['totalBytes']=0;
            if(!empty($row['is_hls'])&&(int)($row['segments_total']??0)>0)$row['percent']=(int)floor(min(1,(int)($row['segments_done']??0)/(int)$row['segments_total'])*100);
            else{
                $row['totalBytes']=(int)($meta['total_bytes']??0);if($row['totalBytes']>0)$row['percent']=(int)floor(min(1,$row['bytes']/$row['totalBytes'])*100);
            }
            if(time()-strtotime($row['updated']??$row['created']??'now')>90){
                $lock=@fopen(self::root().'/'.$row['id'].'.lock','c');
                if($lock&&flock($lock,LOCK_EX|LOCK_NB)){
                    $row=self::job($row['id']);
                    if(in_array($row['status']??'', ['queued','downloading'],true)){$row['status']='failed';$row['error']='The download worker stopped. Retry to resume saved data.';self::save($row);}
                    flock($lock,LOCK_UN);
                }
                if(is_resource($lock))fclose($lock);
            }
        }unset($row);
        usort($rows,static function($a,$b){return strcmp($b['created']??'',$a['created']??'');});
        return array_map(static function($j){
            $item=is_array($j['item']??null)?$j['item']:[];$season=$item['seasonName']??'';
            if($season===''&&is_numeric($item['season']??null))$season=(int)$item['season']===0?'Specials':'Season '.(int)$item['season'];
            return array_merge(array_intersect_key($j,array_flip(['id','name','status','bytes','error','created','updated','job_pid','destination','stage','connection','delete_requested'])),[
                'percent'=>$j['status']==='ready'||$j['status']==='imported'?100:($j['percent']??null),'totalBytes'=>(int)($j['totalBytes']??0),'speedBytesPerSecond'=>(int)($j['speedBytesPerSecond']??0),'providerHttpStatus'=>(int)($j['providerHttpStatus']??0),'elapsedSeconds'=>$j['status']==='downloading'?max(0,time()-(int)(strtotime($j['created']??'now')?:time())):0,
                'showName'=>(string)($item['showName']??$item['seriesName']??''),'season'=>is_numeric($item['season']??null)?(int)$item['season']:null,'seasonName'=>(string)$season,
                'episode'=>(string)($item['episode']??$item['episode_number']??''),'episodeTitle'=>(string)($item['episodeTitle']??$item['episode_title']??'')
            ]);
        },$rows);
    }
    public static function enqueue(array $feed,string $itemId,string $name,string $destination='library',string $playbackTicket='',string $viewer='',string $showName=''): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $item=stalker_vod_entry($feed,$itemId);
        if(!$item)throw new RuntimeException('Refresh the provider catalogue and select the title again.');
        if(empty($item['showName'])&&trim($showName)!=='')$item['showName']=mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',strip_tags($showName))),0,200);
        if(preg_match('/^[a-f0-9]{32}$/D',$playbackTicket)){
            $ticketState=\Vaultcast\Repository::cacheGet('stalker_play_ticket_'.hash('sha256',$playbackTicket));
            if(!$ticketState||($ticketState['viewer']??null)!==$viewer||($ticketState['feed']??null)!==$feed['id']||($ticketState['id']??null)!==$itemId||($ticketState['kind']??null)==='live')$playbackTicket='';
        }else $playbackTicket='';
        $displayName=trim((string)($item['name']??''));if($displayName==='')$displayName=$name;
        $season=is_numeric($item['season']??null)?(int)$item['season']:null;$episode=is_numeric($item['episode']??null)?(int)$item['episode']:null;$show=(string)($item['showName']??'');
        if($show!==''&&$season!==null&&$episode!==null)$displayName=$show.' S'.str_pad((string)$season,2,'0',STR_PAD_LEFT).'E'.str_pad((string)$episode,2,'0',STR_PAD_LEFT);
        $queue=self::listing();
        foreach(\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'") as $existing){
            if(($existing['feed']??'')!==$feed['id']||($existing['item_id']??'')!==$itemId||($existing['status']??'')==='imported')continue;
            if($destination==='library')$existing['destination']='library';
            $existing['item']=$item;$existing['name']=mb_substr($displayName,0,250);$existing['connection']=(string)($feed['name']??'');if($playbackTicket!==''){$existing['playback_ticket']=$playbackTicket;$existing['playback_viewer']=$viewer;}self::save($existing);
            if(in_array($existing['status'],['queued','downloading','ready'],true))return ['ok'=>true,'id'=>$existing['id'],'status'=>$existing['status']];
            if(count(array_filter($queue,static function($j){return in_array($j['status'],['queued','downloading'],true);}))>=20)throw new RuntimeException('The download queue is full. Wait for a download to finish.');
            return self::retry($existing['id']);
        }
        if(count(array_filter($queue,static function($j){return in_array($j['status'],['queued','downloading'],true);}))>=20)throw new RuntimeException('The download queue is full. Wait for a download to finish.');
        $id=bin2hex(random_bytes(12));
        $j=['id'=>$id,'feed'=>$feed['id'],'connection'=>(string)($feed['name']??''),'item'=>$item,'item_id'=>$itemId,'name'=>mb_substr($displayName,0,250),'destination'=>$destination==='browser'?'browser':'library','status'=>'queued','stage'=>'Queued for download','bytes'=>0,'created'=>date('c')];if($playbackTicket!==''){$j['playback_ticket']=$playbackTicket;$j['playback_viewer']=$viewer;}self::save($j);
        return self::retry($id);
    }
    public static function retry(string $id): array {
        $j=self::job($id);$lock=fopen(self::root().'/'.$id.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('This download is still running.');
        try{if(in_array($j['status'],['ready','imported'],true))throw new RuntimeException('This download is already complete.');@unlink(self::root().'/'.$id.'/cancel');$j['status']='queued';$j['stage']='Queued for retry';$j['updated']=date('c');unset($j['error']);self::save($j);}
        finally{flock($lock,LOCK_UN);fclose($lock);}
        try{Process::startPhpWorker('stalker-download',$id);}
        catch(Throwable $e){$j['status']='failed';$j['error']='Could not start the download worker. Check the PHP worker configuration.';self::save($j);throw new RuntimeException($j['error']);}
        return ['ok'=>true,'id'=>$id];
    }
    public static function cancel(string $id): array {$j=self::job($id);if(in_array($j['status'],['ready','imported'],true))throw new RuntimeException('This download is already complete.');$dir=self::root().'/'.$id;if(!is_dir($dir))@mkdir($dir,0700,true);file_put_contents($dir.'/cancel','1');$j['status']='cancelled';$j['stage']='Cancellation requested';$j['updated']=date('c');self::save($j);return ['ok'=>true];}
    public static function delete(string $id): array {
        $j=self::job($id);$lock=@fopen(self::root().'/'.$id.'.lock','c');$locked=false;
        if($lock){$deadline=microtime(true)+8;do{$locked=flock($lock,LOCK_EX|LOCK_NB);if($locked)break;if(!in_array($j['status']??'', ['cancelled','failed'],true))break;usleep(200000);}while(microtime(true)<$deadline);}
        if(!$locked){
            if(is_resource($lock))fclose($lock);
            if(($j['status']??'')==='cancelled'){
                $marker=self::root().'/'.$id.'.delete';@file_put_contents($marker,'1');
                $q=\Vaultcast\Database::connection()->prepare('DELETE FROM vaultcast_documents WHERE name=?');$q->execute(['stalker-offline:'.$id]);
                self::purgeIfUnlocked($id);
                return ['ok'=>true];
            }
            throw new RuntimeException('The download is still stopping. Wait a few seconds, then delete it again.');
        }
        try{
            $j=self::job($id);
            if(in_array($j['status']??'', ['queued','downloading'],true))throw new RuntimeException('Cancel the active download first, then delete it after it stops.');
            $dir=self::root().'/'.$id;
            foreach(glob($dir.'/*')?:[] as $path)if(is_file($path)||is_link($path))@unlink($path);
            @rmdir($dir);
            $q=\Vaultcast\Database::connection()->prepare('DELETE FROM vaultcast_documents WHERE name=?');$q->execute(['stalker-offline:'.$id]);
            return ['ok'=>true];
        }finally{flock($lock,LOCK_UN);fclose($lock);self::purgeIfUnlocked($id);}
    }
    public static function downloadFile(string $id): void {
        $j=self::job($id);$path=self::root().'/'.$id.'/video.mkv';
        if(!in_array($j['status']??'', ['ready','imported'],true)||!is_file($path))throw new RuntimeException('The video is not ready to download. Open Downloads to see its status.');
        $name=preg_replace('/[\x00-\x1f\x7f\/\\\\"<>:|?*]/u','_', $j['name']??'Video').'.mkv';
        header('Cache-Control: private, no-store');
        header("Content-Disposition: attachment; filename=\"video.mkv\"; filename*=UTF-8''".rawurlencode($name));
        Stream::sendFile($path,'video/x-matroska');exit;
    }
    private static function fetch(string $url,string $dest,array $feed,int $limit=21474836480,bool $fresh=false,bool $rangeFirst=false): void {
        for($attempt=0;$attempt<5;$attempt++){
            try{self::fetchOnce($url,$dest,$feed,$limit,$fresh,$attempt,$rangeFirst);return;}
            catch(RuntimeException $error){
                if($attempt===4||is_file(dirname($dest).'/cancel')||preg_match('/^(?:Not enough free disk space|This provider video exceeds|Cannot write the download|The download could not be written)/i',$error->getMessage()))throw $error;
                sleep(min(30,1<<$attempt));
            }
        }
    }
    private static function fetchOnce(string $url,string $dest,array $feed,int $limit,bool $fresh,int $attempt,bool $rangeFirst=false,bool $noRange=false): void {
        if(is_file($dest)&&!$fresh)return;
        $part=$dest.'.part';$metaFile=$part.'.json';$meta=is_file($metaFile)?json_decode((string)file_get_contents($metaFile),true):[];
        $offset=!$fresh&&is_file($part)&&!empty($meta['validator'])?(int)filesize($part):0;
        for($hop=0;$hop<5;$hop++){
            $p=parse_url($url);if(!$p||!in_array($p['scheme']??'',['http','https'],true)||isset($p['user'])||isset($p['pass']))throw new RuntimeException('Provider returned an invalid media URL.');
            $ips=stalker_public_addresses($url);foreach($ips as $ip)if(!WebFetch::publicIp($ip))throw new RuntimeException('Media address is not public.');
            $host=(string)$p['host'];$port=$p['port']??($p['scheme']==='https'?443:80);$ip=strpos($ips[$attempt%count($ips)],':')!==false?'['.$ips[$attempt%count($ips)].']':$ips[$attempt%count($ips)];
            $headers=['Accept: */*'];if(!$noRange){if($offset){$headers[]='Range: bytes='.$offset.'-';$headers[]='If-Range: '.$meta['validator'];}elseif($rangeFirst)$headers[]='Range: bytes=0-';}
            $status=0;$response=[];$file=null;$received=0;$failed=false;$failureCause='';
            $ch=curl_init($url);
            // Match the live stream proxy's protocol negotiation and content
            // decoding; some provider gateways refuse forced HTTP/1.1 requests.
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip],CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>0,CURLOPT_LOW_SPEED_LIMIT=>1024,CURLOPT_LOW_SPEED_TIME=>90,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'',
                CURLOPT_COOKIEFILE=>$feed['_downloadCookies']??stalker_cookie_path($feed),CURLOPT_COOKIEJAR=>$feed['_downloadCookies']??stalker_cookie_path($feed),CURLOPT_REFERER=>preg_replace('#/(?:server/load|portal)\.php$#','/c/',stalker_endpoint($feed)),CURLOPT_USERAGENT=>'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3',CURLOPT_HTTPHEADER=>$headers,CURLOPT_NOPROGRESS=>false,
                CURLOPT_XFERINFOFUNCTION=>static function($handle,$downloadTotal,$downloaded)use($dest,$metaFile,$offset,&$status){$marker=dirname($dest).'/cancel';clearstatcache(true,$marker);if(is_file($marker))return 1;if(in_array($status,[200,206],true)&&($downloadTotal>0||$downloaded>0)){static $lastWrite=0.0;$now=microtime(true);if($now-$lastWrite>=0.75){$meta=is_file($metaFile)?json_decode((string)file_get_contents($metaFile),true):[];$current=(int)$downloaded+($status===206?$offset:0);$previous=(int)($meta['downloaded_bytes']??$current);$previousAt=(float)($meta['progress_sample_at']??$now);$elapsed=$now-$previousAt;$meta['total_bytes']=$downloadTotal>0?(int)$downloadTotal+($status===206?$offset:0):(int)($meta['total_bytes']??0);$meta['downloaded_bytes']=$current;if($elapsed>=0.25){$meta['speed_bytes_per_second']=(int)max(0,($current-$previous)/$elapsed);$meta['progress_sample_at']=$now;}$meta['progress_at']=(int)$now;@file_put_contents($metaFile,json_encode($meta));$lastWrite=$now;}}return 0;},
                CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$status,&$response,$metaFile,$offset){if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m)){$status=(int)$m[1];$response=[];$meta=is_file($metaFile)?json_decode((string)file_get_contents($metaFile),true):[];$meta['http_status']=$status;@file_put_contents($metaFile,json_encode($meta));}elseif(strpos($line,':')!==false){[$k,$v]=explode(':',$line,2);$key=strtolower(trim($k));$value=trim($v);$response[$key]=$value;if(in_array($status,[200,206],true)){if($key==='content-range'&&preg_match('/^bytes \d+-\d+\/(\d+)$/',$value,$range)){$meta=is_file($metaFile)?json_decode((string)file_get_contents($metaFile),true):[];$meta['total_bytes']=(int)$range[1];@file_put_contents($metaFile,json_encode($meta));}elseif($key==='content-length'&&ctype_digit($value)){$meta=is_file($metaFile)?json_decode((string)file_get_contents($metaFile),true):[];$meta['total_bytes']=(int)$value+($status===206?$offset:0);@file_put_contents($metaFile,json_encode($meta));}}}return strlen($line);},
                 CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$file,&$received,&$failed,&$failureCause,&$response,&$status,$offset,$part,$metaFile,$limit){
                    if($status>=300&&$status<400)return strlen($chunk);
                    if(!in_array($status,[200,206],true))return 0;
                     if(!$file){if($status===206&&!preg_match('/^bytes '.preg_quote((string)$offset,'/').'-/',$response['content-range']??'')){$failed=true;$failureCause='The provider changed the partial download; retry the download.';return 0;}$file=@fopen($status===206&&$offset>0?'ab':'wb');if(!$file){$failed=true;$failureCause='Cannot write the download to offline storage.';return 0;}@chmod($part,0600);$meta=is_file($metaFile)?json_decode((string)file_get_contents($metaFile),true):[];$meta['validator']=$response['etag']??$response['last-modified']??($meta['validator']??'');$total=0;if(preg_match('/^bytes \d+-\d+\/(\d+)$/',$response['content-range']??'',$range))$total=(int)$range[1];elseif(isset($response['content-length'])&&ctype_digit((string)$response['content-length']))$total=(int)$response['content-length']+($status===206?$offset:0);if($total>0)$meta['total_bytes']=$total;@file_put_contents($metaFile,json_encode($meta));}
                     $received+=strlen($chunk);$free=@disk_free_space(dirname($part));if($received+($status===206?$offset:0)>$limit){$failed=true;$failureCause='This provider video exceeds the 20 GB offline download limit.';return 0;}if($free!==false&&$free<100*1024*1024){$failed=true;$failureCause='Not enough free disk space to continue the download.';return 0;}
                     $n=fwrite($file,$chunk);if($n===false||$n!==strlen($chunk)){$failed=true;$failureCause='The download could not be written completely. Check free disk space and offline storage permissions.';return 0;}return $n;
                }]);
            $ok=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlRedirect=defined('CURLINFO_REDIRECT_URL')?(string)curl_getinfo($ch,CURLINFO_REDIRECT_URL):'';curl_close($ch);if(is_resource($file))fclose($file);
            $redirect=trim((string)($response['location']??''));
            // Let libcurl supply its parsed target if the raw header callback
            // did not expose Location (for example, on some HTTP/2 gateways).
            if($redirect===''&&$status>=300&&$status<400&&$curlRedirect!=='')$redirect=$curlRedirect;
            // Some provider gateways send their redirect target as Refresh
            // instead of the standard Location header. Resolve it through the
            // same validated redirect loop below.
            if($redirect===''&&$status>=300&&$status<400&&isset($response['refresh'])&&preg_match('/^\s*\d+\s*;\s*url\s*=\s*["\']?([^"\']+)["\']?\s*$/i',$response['refresh'],$refresh))$redirect=trim($refresh[1]);
            if($status>=300&&$status<400&&$redirect!==''){$url=iptv_hls_resolve_uri($url,$redirect);continue;}
            if($status>=300&&$status<400&&$rangeFirst&&!$noRange){@unlink($part);@unlink($metaFile);self::fetchOnce($url,$dest,$feed,$limit,true,$attempt,$rangeFirst,true);return;}
            if($status===206&&!$noRange&&($rangeFirst||$offset>0)&&$received===0&&!$failed){@unlink($part);@unlink($metaFile);self::fetchOnce($url,$dest,$feed,$limit,true,$attempt,$rangeFirst,true);return;}
            if($status>=300&&$status<400)throw new RuntimeException('The provider redirected this download (HTTP '.$status.') without a usable destination. Refresh the provider link and retry.');
            // Some Stalker media hosts reject Range/If-Range requests (notably
            // with 405 or vendor status 460). Drop the incompatible partial and
            // retry this URL as a fresh GET instead of leaving Retry stuck.
            if(!$noRange&&(($offset>0||$rangeFirst)&&(in_array($status,[400,405,416,460],true)||($failed&&strpos($failureCause,'The provider changed the partial download')===0)))){
                @unlink($part);@unlink($metaFile);
                self::fetchOnce($url,$dest,$feed,$limit,true,$attempt,$rangeFirst,true);
                return;
            }
            if($failed&&$failureCause!=='')throw new RuntimeException($failureCause.' Partial files are kept; use Retry.');
            if(!$ok||!in_array($status,[200,206],true)||!is_file($part))throw new RuntimeException('Download interrupted or refused (HTTP '.$status.($errno?', transport '.$errno.': '.curl_strerror($errno):'').'). Partial files are kept; use Retry.');
            if(!@rename($part,$dest))throw new RuntimeException('Cannot finish the downloaded file.');@chmod($dest,0600);return;
        }
        throw new RuntimeException('Too many media redirects.');
    }
    private static function playlist(string $url,string $dir,array $feed,int $depth=0,string $jobId=''): string {
        if($depth>4)throw new RuntimeException('Too many nested playlists.');
        $path=$dir.'/manifest-'.$depth;self::fetch($url,$path,$feed,4*1024*1024,true);$text=(string)file_get_contents($path);
        $lines=preg_split('/\r?\n/',$text);$variants=[];$bandwidth=0;
        foreach($lines as $line){$line=trim($line);if(strpos($line,'#EXT-X-STREAM-INF:')===0){preg_match('/BANDWIDTH=(\d+)/',$line,$m);$bandwidth=(int)($m[1]??1);}elseif($bandwidth&&$line!==''&&$line[0]!=='#'){$variants[$bandwidth]=iptv_hls_resolve_uri($url,$line);$bandwidth=0;}}
        if($variants){krsort($variants);return self::playlist(reset($variants),$dir,$feed,$depth+1,$jobId);}
        if(strpos($text,'#EXT-X-ENDLIST')===false)throw new RuntimeException('This is a continuous stream, not downloadable VOD.');
        $out=[];$number=0;$signature=[];$segments=[];$duration='';
        foreach($lines as $line){$line=trim($line);if($line==='' )continue;
            if(strpos($line,'#EXTINF:')===0)$duration=$line;
            if(strpos($line,'#EXT-X-KEY:')===0&&!preg_match('/METHOD=(?:AES-128|NONE)(?:,|$)/',$line))throw new RuntimeException('This provider uses unsupported protected video.');
            if($line[0]==='#'){
                if(strpos($line,'URI=')!==false){if(!preg_match('/^#EXT-X-(?:KEY|MAP):/',$line))throw new RuntimeException('Unsupported playlist resource.');$line=preg_replace_callback('/URI="([^"]+)"/',static function($m)use($url,$dir,$feed){$resource=iptv_hls_resolve_uri($url,$m[1]);$name='resource-'.hash('sha256',$resource);self::fetch($resource,$dir.'/'.$name,$feed,64*1024*1024);return 'URI="'.$name.'"';},$line);}
                $out[]=$line;continue;
            }
            $resource=iptv_hls_resolve_uri($url,$line);$segments[]=$resource;$params=[];parse_str((string)parse_url($resource,PHP_URL_QUERY),$params);
            foreach(array_keys($params) as $key)if(preg_match('/^(?:token|expires|exp|signature|sig|auth|play_token|session)$/i',(string)$key))unset($params[$key]);
            ksort($params);$signature[]=[parse_url($resource,PHP_URL_PATH),$params,$duration];$out[]='segment-'.($number++);
        }
        $signatureFile=$dir.'/segments.json';$encoded=json_encode($signature);
        if(is_file($signatureFile)&&file_get_contents($signatureFile)!==$encoded)throw new RuntimeException('Provider changed this video while it was downloading. Existing partial files were preserved.');
        file_put_contents($signatureFile,$encoded);
        if($jobId!==''){$job=self::job($jobId);$job['is_hls']=true;$job['segments_total']=count($segments);$job['segments_done']=0;$job['updated']=date('c');self::save($job);}
        foreach($segments as $number=>$segment){self::fetch($segment,$dir.'/segment-'.$number,$feed,512*1024*1024);if($jobId!==''){$job=self::job($jobId);$job['segments_done']=$number+1;$job['updated']=date('c');self::save($job);}}
        $local=$dir.'/offline.m3u8';file_put_contents($local,implode("\n",$out)."\n");return $local;
    }
    public static function run(string $id): array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Worker only.');
        require_once STREAM_ROOT.'/src/Live/iptv.php';require_once STREAM_ROOT.'/src/Live/iptv_hls.php';
        $j=self::job($id);$own=fopen(self::root().'/'.$id.'.lock','c');if(!$own||!flock($own,LOCK_EX|LOCK_NB))return ['ok'=>true,'busy'=>true];
        $bandwidth=fopen(self::root().'/download.lock','c');
        try{
            if(!$bandwidth)throw new RuntimeException('Download queue lock unavailable.');
            while(!flock($bandwidth,LOCK_EX|LOCK_NB)){if(is_file(self::root().'/'.$id.'/cancel'))throw new RuntimeException('Download cancelled. Partial media was preserved.');usleep(250000);}
            if(is_file(self::root().'/'.$id.'/cancel'))throw new RuntimeException('Download cancelled. Partial media was preserved.');
            $j=self::job($id);if(in_array($j['status'],['ready','imported'],true))return ['ok'=>true];
            $features=Store::settings()['features']??[];if(empty($features['live'])||empty($features['downloads']))throw new RuntimeException('Live or downloads have been disabled.');
            $feed=iptv_feed_by_id(iptv_load(),$j['feed']);if(!$feed||empty($feed['enabled']))throw new RuntimeException('The saved provider connection is missing or disabled. Re-enable it in Live settings → IPTV & guide matching, then retry this download.');
            $dir=self::root().'/'.$id;if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('Cannot create download folder.');
            if(is_file($dir.'/cancel'))throw new RuntimeException('Download cancelled. Partial media was preserved.');
            $j['destination']=self::job($id)['destination']??'library';$j['status']='downloading';$j['stage']='Resolving provider stream';$j['updated']=date('c');self::save($j);
            $url='';$playbackCookies='';$ticket=(string)($j['playback_ticket']??'');$ticketViewer=(string)($j['playback_viewer']??'');
            if(preg_match('/^[a-f0-9]{32}$/D',$ticket)&&$ticketViewer!==''){
                $ticketState=\Vaultcast\Repository::cacheGet('stalker_play_ticket_'.hash('sha256',$ticket));
                if($ticketState&&($ticketState['viewer']??null)===$ticketViewer&&($ticketState['feed']??null)===$feed['id']&&($ticketState['id']??null)===$j['item_id']&&($ticketState['kind']??null)!=='live'){
                    $playbackKey='stalker_playback_v3_'.hash('sha256',json_encode([$ticketViewer,$feed,$j['item_id'],$ticket]));$playbackState=\Vaultcast\Repository::cacheGet($playbackKey);
                    if($playbackState&&!empty($playbackState['url'])){$url=(string)$playbackState['url'];if(!empty($playbackState['jar']))$playbackCookies=iptv_hls_jar_path((string)$playbackState['jar']);}
                    elseif(time()-(int)($ticketState['created']??0)<30)$url=(string)($ticketState['url']??'');
                }
            }
            if($url==='')$url=stalker_vod_resolve($feed,$j['item_id'],$j['item']);
            $cookies=$dir.'/cookies';$cookieSource=$playbackCookies!==''&&is_file($playbackCookies)?$playbackCookies:stalker_cookie_path($feed);if(!@copy($cookieSource,$cookies))throw new RuntimeException('Could not prepare provider download cookies.');@chmod($cookies,0600);
            $feed['_downloadCookies']=$cookies;$source=$dir.'/source';$j['stage']='Downloading from provider';$j['updated']=date('c');self::save($j);self::fetch($url,$source,$feed,21474836480,false,true);
            $head=file_get_contents($source,false,null,0,16);
            $expectedDuration=0.0;
            if(strpos(ltrim((string)$head),'#EXTM3U')===0){$j['is_hls']=true;$j['stage']='Downloading stream segments';$j['updated']=date('c');self::save($j);$source=self::playlist($url,$dir,$feed,0,$id);preg_match_all('/#EXTINF:([0-9.]+)/',(string)file_get_contents($source),$durations);$expectedDuration=array_sum(array_map('floatval',$durations[1]));}
            $dest=$dir.'/video.mkv';$part=$dir.'/video.part.mkv';
            $j['stage']='Preparing offline video';$j['updated']=date('c');self::save($j);
            $arguments=[Remux::ffmpegBin(),'-nostdin','-y','-v','error','-protocol_whitelist','file,crypto,data'];
            if(pathinfo($source,PATHINFO_EXTENSION)==='m3u8')array_push($arguments,'-allowed_extensions','ALL');
            array_push($arguments,'-i',$source,'-map','0:v:0','-map','0:a?','-c','copy',$part);
            $process=proc_open($arguments,[0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],1=>['file',$dir.'/remux.log','w'],2=>['file',$dir.'/remux.error.log','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($process))throw new RuntimeException('Could not start the video preparation tool.');
            $deadline=time()+7200;
            do{$processState=proc_get_status($process);if(!$processState['running'])break;clearstatcache(true,$dir.'/cancel');if(time()>$deadline||is_file($dir.'/cancel')){proc_terminate($process);proc_close($process);throw new RuntimeException('Video preparation stopped. Downloaded media was preserved.');}usleep(100000);}while(true);
            $exit=$processState['exitcode'];proc_close($process);
            if($exit!==0)throw new RuntimeException('Video preparation failed. Downloaded media was preserved for retry.');
            $j['stage']='Checking the completed video';$j['updated']=date('c');self::save($j);$probe=is_file($part)?Process::capture([MediaProbe::ffprobeBin(),'-v','error','-show_streams','-show_format','-of','json',$part],20):null;
            $info=json_decode((string)$probe,true);$streams=$info['streams']??[];
            if($expectedDuration>0&&abs((float)($info['format']['duration']??0)-$expectedDuration)>max(5.0,$expectedDuration*0.001))throw new RuntimeException('The saved video is incomplete. Downloaded segments were preserved for retry.');
            if(!array_filter($streams,static function($s){return ($s['codec_type']??'')==='video';})||!@rename($part,$dest))throw new RuntimeException('The download could not be verified as a complete video. Partial files are kept for retry.');
            $j['destination']=self::job($id)['destination']??'library';$j['status']='ready';$j['stage']='Complete';$j['bytes']=filesize($dest);$j['updated']=date('c');unset($j['error']);self::save($j);@unlink(STREAM_SHM.'/ingest_count.json');return ['ok'=>true];
        }catch(Throwable $e){if(is_file(self::root().'/'.$id.'.delete'))return ['ok'=>false,'deleted'=>true];$j['destination']=self::job($id)['destination']??'library';$j['status']=is_file(self::root().'/'.$id.'/cancel')?'cancelled':'failed';$j['stage']=$j['status']==='cancelled'?'Cancelled':'Stopped at: '.($j['stage']??'download');$j['error']=$j['status']==='cancelled'?'Cancelled. Partial downloads were preserved.':($e instanceof RuntimeException?$e->getMessage():'The download worker failed. Partial files were preserved.');$j['updated']=date('c');self::save($j);return ['ok'=>false,'error'=>$j['error']];}
        finally{if(is_resource($bandwidth)){flock($bandwidth,LOCK_UN);fclose($bandwidth);}flock($own,LOCK_UN);fclose($own);self::purgeIfUnlocked($id);}
    }
    public static function torrents(): array {
        $rows=\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");$out=[];
        foreach($rows as $j)if(($j['destination']??'library')!=='browser'&&in_array($j['status'],['ready','imported'],true)&&preg_match('/^[a-f0-9]{24}$/D',$j['id'])){$dir=self::root().'/'.$j['id'];$file=$dir.'/video.mkv';if(is_file($file))$out[]=['stalker_offline'=>$j['id'],'hashString'=>'stalker-'.$j['id'],'id'=>0,'name'=>$j['name'],'percentDone'=>1,'downloadDir'=>$dir,'files'=>[['name'=>'video.mkv','length'=>filesize($file)]]];}
        return $out;
    }
    public static function files(array $tor): array {
        $id=(string)($tor['stalker_offline']??'');self::job($id);$path=self::root().'/'.$id.'/video.mkv';
        return is_file($path)?[['path'=>$path,'rel'=>'video.mkv','name'=>$tor['name'].'.mkv','ext'=>'mkv','size'=>filesize($path)]]:[];
    }
    public static function imported(array $tor): array {
        $j=self::job($tor['stalker_offline']);$j['status']='imported';self::save($j);
        $dir=self::root().'/'.$j['id'];
        // This is a flat, owned staging folder. Keep any completed copy that the
        // import left behind; only its now-unneeded download intermediates go.
        foreach(glob($dir.'/*')?:[] as $file)if(is_file($file)&&!is_link($file)&&basename($file)!=='video.mkv')@unlink($file);
        return ['ok'=>true,'removed'=>false,'left'=>0,'message'=>'Offline video imported into the library.'];
    }
}
