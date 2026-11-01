<?php
declare(strict_types=1);

final class StalkerOffline {
    private static function root(): string { $p=STREAM_DATA.'/stalker-offline';if(!is_dir($p)&&!@mkdir($p,0700,true))throw new RuntimeException('Cannot create offline storage.');return $p; }
    private static function job(string $id): array {if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new InvalidArgumentException('Invalid offline download.');$j=\Vaultcast\Repository::document('stalker-offline:'.$id,[]);if(!$j)throw new RuntimeException('Offline download not found.');return $j;}
    private static function save(array $j): void {\Vaultcast\Repository::putDocument('stalker-offline:'.$j['id'],$j);}
    public static function listing(): array {
        $rows=\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");
        usort($rows,static function($a,$b){return strcmp($b['created']??'',$a['created']??'');});
        return array_map(static function($j){return array_intersect_key($j,array_flip(['id','name','status','bytes','error','created','updated','job_pid']));},$rows);
    }
    public static function enqueue(array $feed,string $itemId,string $name): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $item=stalker_vod_entry($feed,$itemId);
        if(!$item)throw new RuntimeException('Refresh the provider catalogue and select the title again.');
        foreach(\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'") as $existing)if(($existing['feed']??'')===$feed['id']&&($existing['item_id']??'')===$itemId&&in_array($existing['status'],['queued','downloading','ready'],true))throw new RuntimeException('This title is already in Offline downloads.');
        if(count(array_filter(self::listing(),static function($j){return in_array($j['status'],['queued','downloading'],true);}))>=20)throw new RuntimeException('The offline queue is full. Wait for a download to finish.');
        $id=bin2hex(random_bytes(12));
        $j=['id'=>$id,'feed'=>$feed['id'],'item'=>$item,'item_id'=>$itemId,'name'=>mb_substr(trim($name),0,250),'status'=>'queued','bytes'=>0,'created'=>date('c')];self::save($j);
        return self::retry($id);
    }
    public static function retry(string $id): array {
        $j=self::job($id);$lock=fopen(self::root().'/'.$id.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('This download is still running.');
        try{if(in_array($j['status'],['ready','imported'],true))throw new RuntimeException('This download is already complete.');@unlink(self::root().'/'.$id.'/cancel');$j['status']='queued';unset($j['error']);self::save($j);}
        finally{flock($lock,LOCK_UN);fclose($lock);}
        try{Process::startPhpWorker('stalker-download',$id);}
        catch(Throwable $e){$j['status']='failed';$j['error']='Could not start the download worker. Check the PHP worker configuration.';self::save($j);throw new RuntimeException($j['error']);}
        return ['ok'=>true,'id'=>$id];
    }
    public static function cancel(string $id): array {$j=self::job($id);if(in_array($j['status'],['ready','imported'],true))throw new RuntimeException('This download is already complete.');$dir=self::root().'/'.$id;if(!is_dir($dir))@mkdir($dir,0700,true);file_put_contents($dir.'/cancel','1');$j['status']='cancelled';self::save($j);return ['ok'=>true];}
    private static function fetch(string $url,string $dest,array $feed,int $limit=21474836480,bool $fresh=false): void {
        if(is_file($dest)&&!$fresh)return;
        $part=$dest.'.part';$metaFile=$part.'.json';$meta=is_file($metaFile)?json_decode((string)file_get_contents($metaFile),true):[];
        $offset=!$fresh&&is_file($part)&&!empty($meta['validator'])?(int)filesize($part):0;
        for($hop=0;$hop<5;$hop++){
            $p=parse_url($url);if(!$p||!in_array($p['scheme']??'',['http','https'],true)||isset($p['user'])||isset($p['pass']))throw new RuntimeException('Provider returned an invalid media URL.');
            $ips=stalker_public_addresses($url);foreach($ips as $ip)if(!WebFetch::publicIp($ip))throw new RuntimeException('Media address is not public.');
            $host=(string)$p['host'];$port=$p['port']??($p['scheme']==='https'?443:80);$ip=strpos($ips[0],':')!==false?'['.$ips[0].']':$ips[0];
            $headers=['Accept: */*'];if($offset){$headers[]='Range: bytes='.$offset.'-';$headers[]='If-Range: '.$meta['validator'];}
            $status=0;$response=[];$file=null;$received=0;$failed=false;
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>0,CURLOPT_LOW_SPEED_LIMIT=>1024,CURLOPT_LOW_SPEED_TIME=>90,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_COOKIEFILE=>stalker_cookie_path($feed),CURLOPT_REFERER=>stalker_endpoint($feed),CURLOPT_USERAGENT=>'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3',CURLOPT_HTTPHEADER=>$headers,CURLOPT_NOPROGRESS=>false,
                CURLOPT_XFERINFOFUNCTION=>static function()use($dest){$marker=dirname($dest).'/cancel';clearstatcache(true,$marker);return is_file($marker)?1:0;},
                CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$status,&$response){if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m)){$status=(int)$m[1];$response=[];}elseif(strpos($line,':')!==false){[$k,$v]=explode(':',$line,2);$response[strtolower(trim($k))]=trim($v);}return strlen($line);},
                CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$file,&$received,&$failed,&$response,&$status,$offset,$part,$metaFile,$limit){
                    if($status>=300&&$status<400)return strlen($chunk);
                    if(!in_array($status,[200,206],true))return 0;
                    if(!$file){if($status===206&&(!$offset||!preg_match('/^bytes '.preg_quote((string)$offset,'/').'-/',$response['content-range']??''))){$failed=true;return 0;}$file=@fopen($part,$status===206?'ab':'wb');if(!$file){$failed=true;return 0;}@chmod($part,0600);$validator=$response['etag']??$response['last-modified']??'';@file_put_contents($metaFile,json_encode(['validator'=>$validator]));}
                    $received+=strlen($chunk);$free=@disk_free_space(dirname($part));if($received+($status===206?$offset:0)>$limit||($free!==false&&$free<100*1024*1024)){$failed=true;return 0;}
                    $n=fwrite($file,$chunk);return $n===false?0:$n;
                }]);
            $ok=curl_exec($ch);curl_close($ch);if(is_resource($file))fclose($file);
            if($status>=300&&$status<400&&!empty($response['location'])){$url=iptv_hls_resolve_uri($url,$response['location']);continue;}
            if(!$ok||$failed||!in_array($status,[200,206],true)||!is_file($part))throw new RuntimeException('Download interrupted or refused (HTTP '.$status.'). Partial files are kept; use Retry. Check available disk space.');
            if(!@rename($part,$dest))throw new RuntimeException('Cannot finish the downloaded file.');@chmod($dest,0600);return;
        }
        throw new RuntimeException('Too many media redirects.');
    }
    private static function playlist(string $url,string $dir,array $feed,int $depth=0): string {
        if($depth>4)throw new RuntimeException('Too many nested playlists.');
        $path=$dir.'/manifest-'.$depth;self::fetch($url,$path,$feed,4*1024*1024,true);$text=(string)file_get_contents($path);
        $lines=preg_split('/\r?\n/',$text);$variants=[];$bandwidth=0;
        foreach($lines as $line){$line=trim($line);if(strpos($line,'#EXT-X-STREAM-INF:')===0){preg_match('/BANDWIDTH=(\d+)/',$line,$m);$bandwidth=(int)($m[1]??1);}elseif($bandwidth&&$line!==''&&$line[0]!=='#'){$variants[$bandwidth]=iptv_hls_resolve_uri($url,$line);$bandwidth=0;}}
        if($variants){krsort($variants);return self::playlist(reset($variants),$dir,$feed,$depth+1);}
        if(strpos($text,'#EXT-X-ENDLIST')===false)throw new RuntimeException('This is a continuous stream, not downloadable VOD.');
        $out=[];$number=0;$signature=[];$duration='';
        foreach($lines as $line){$line=trim($line);if($line==='' )continue;
            if(strpos($line,'#EXTINF:')===0)$duration=$line;
            if(strpos($line,'#EXT-X-KEY:')===0&&!preg_match('/METHOD=(?:AES-128|NONE)(?:,|$)/',$line))throw new RuntimeException('This provider uses unsupported protected video.');
            if($line[0]==='#'){
                if(strpos($line,'URI=')!==false){if(!preg_match('/^#EXT-X-(?:KEY|MAP):/',$line))throw new RuntimeException('Unsupported playlist resource.');$line=preg_replace_callback('/URI="([^"]+)"/',static function($m)use($url,$dir,$feed){$resource=iptv_hls_resolve_uri($url,$m[1]);$name='resource-'.hash('sha256',$resource);self::fetch($resource,$dir.'/'.$name,$feed,64*1024*1024);return 'URI="'.$name.'"';},$line);}
                $out[]=$line;continue;
            }
            $resource=iptv_hls_resolve_uri($url,$line);$params=[];parse_str((string)parse_url($resource,PHP_URL_QUERY),$params);
            foreach(array_keys($params) as $key)if(preg_match('/^(?:token|expires|exp|signature|sig|auth|play_token|session)$/i',(string)$key))unset($params[$key]);
            ksort($params);$signature[]=[parse_url($resource,PHP_URL_PATH),$params,$duration];$out[]='segment-'.($number++);
        }
        $signatureFile=$dir.'/segments.json';$encoded=json_encode($signature);
        if(is_file($signatureFile)&&file_get_contents($signatureFile)!==$encoded)throw new RuntimeException('Provider changed this video while it was downloading. Existing partial files were preserved.');
        file_put_contents($signatureFile,$encoded);
        $number=0;foreach($lines as $line){$line=trim($line);if($line!==''&&$line[0]!=='#'){self::fetch(iptv_hls_resolve_uri($url,$line),$dir.'/segment-'.($number++),$feed,512*1024*1024);}}
        $local=$dir.'/offline.m3u8';file_put_contents($local,implode("\n",$out)."\n");return $local;
    }
    public static function run(string $id): array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Worker only.');
        require_once STREAM_ROOT.'/src/Live/iptv.php';require_once STREAM_ROOT.'/src/Live/iptv_hls.php';
        $j=self::job($id);$own=fopen(self::root().'/'.$id.'.lock','c');if(!$own||!flock($own,LOCK_EX|LOCK_NB))return ['ok'=>true,'busy'=>true];
        $bandwidth=fopen(self::root().'/download.lock','c');
        try{
            if(!$bandwidth||!flock($bandwidth,LOCK_EX))throw new RuntimeException('Download queue lock unavailable.');
            $j=self::job($id);if(in_array($j['status'],['ready','imported'],true))return ['ok'=>true];
            $features=Store::settings()['features']??[];if(empty($features['live'])||empty($features['downloads']))throw new RuntimeException('Live or downloads have been disabled.');
            $feed=iptv_feed_by_id(iptv_load(),$j['feed']);if(!$feed||empty($feed['enabled']))throw new RuntimeException('The provider connection is unavailable.');
            $dir=self::root().'/'.$id;if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('Cannot create download folder.');
            if(is_file($dir.'/cancel'))throw new RuntimeException('Download cancelled. Partial media was preserved.');
            $j['status']='downloading';self::save($j);
            $url=stalker_vod_resolve($feed,$j['item_id'],$j['item']);$source=$dir.'/source';self::fetch($url,$source,$feed);
            $head=file_get_contents($source,false,null,0,16);
            $expectedDuration=0.0;
            if(strpos(ltrim((string)$head),'#EXTM3U')===0){$source=self::playlist($url,$dir,$feed);preg_match_all('/#EXTINF:([0-9.]+)/',(string)file_get_contents($source),$durations);$expectedDuration=array_sum(array_map('floatval',$durations[1]));}
            $dest=$dir.'/video.mkv';$part=$dir.'/video.part.mkv';
            $arguments=[Remux::ffmpegBin(),'-nostdin','-y','-v','error','-protocol_whitelist','file,crypto,data'];
            if(pathinfo($source,PATHINFO_EXTENSION)==='m3u8')array_push($arguments,'-allowed_extensions','ALL');
            array_push($arguments,'-i',$source,'-map','0:v:0','-map','0:a?','-c','copy',$part);
            $process=proc_open($arguments,[0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],1=>['file',$dir.'/remux.log','w'],2=>['file',$dir.'/remux.error.log','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($process))throw new RuntimeException('Could not start the video preparation tool.');
            $deadline=time()+7200;
            do{$processState=proc_get_status($process);if(!$processState['running'])break;clearstatcache(true,$dir.'/cancel');if(time()>$deadline||is_file($dir.'/cancel')){proc_terminate($process);proc_close($process);throw new RuntimeException('Video preparation stopped. Downloaded media was preserved.');}usleep(100000);}while(true);
            $exit=$processState['exitcode'];proc_close($process);
            if($exit!==0)throw new RuntimeException('Video preparation failed. Downloaded media was preserved for retry.');
            $probe=is_file($part)?Process::capture([MediaProbe::ffprobeBin(),'-v','error','-show_streams','-show_format','-of','json',$part],20):null;
            $info=json_decode((string)$probe,true);$streams=$info['streams']??[];
            if($expectedDuration>0&&abs((float)($info['format']['duration']??0)-$expectedDuration)>max(5.0,$expectedDuration*0.001))throw new RuntimeException('The saved video is incomplete. Downloaded segments were preserved for retry.');
            if(!array_filter($streams,static function($s){return ($s['codec_type']??'')==='video';})||!@rename($part,$dest))throw new RuntimeException('The download could not be verified as a complete video. Partial files are kept for retry.');
            $j['status']='ready';$j['bytes']=filesize($dest);$j['updated']=date('c');unset($j['error']);self::save($j);@unlink(STREAM_SHM.'/ingest_count.json');return ['ok'=>true];
        }catch(Throwable $e){$j['status']=is_file(self::root().'/'.$id.'/cancel')?'cancelled':'failed';$j['error']=$j['status']==='cancelled'?'Cancelled. Partial downloads were preserved.':($e instanceof RuntimeException?$e->getMessage():'The download worker failed. Partial files were preserved.');self::save($j);return ['ok'=>false,'error'=>$j['error']];}
        finally{if(is_resource($bandwidth)){flock($bandwidth,LOCK_UN);fclose($bandwidth);}flock($own,LOCK_UN);fclose($own);}
    }
    public static function torrents(): array {
        $rows=\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");$out=[];
        foreach($rows as $j)if(in_array($j['status'],['ready','imported'],true)&&preg_match('/^[a-f0-9]{24}$/D',$j['id'])){$dir=self::root().'/'.$j['id'];$file=$dir.'/video.mkv';if(is_file($file))$out[]=['stalker_offline'=>$j['id'],'hashString'=>'stalker-'.$j['id'],'id'=>0,'name'=>$j['name'],'percentDone'=>1,'downloadDir'=>$dir,'files'=>[['name'=>'video.mkv','length'=>filesize($file)]]];}
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
