<?php
declare(strict_types=1);

final class StalkerUpdates {
    private static function settings(): array {return \Vaultcast\Repository::document('stalker-update-settings',[]);}
    public static function status(): array {$s=self::settings();return ['configured'=>!empty($s['url']),'paused'=>!empty($s['paused']),'mappings'=>$s['mappings']??[],'last_check'=>$s['last_check']??null,'next_check'=>$s['next_check']??null,'message'=>$s['message']??'Add your provider update page to discover its connections.','worker'=>is_file(STREAM_DATA.'/runtime/stalker-updates.heartbeat')&&filemtime(STREAM_DATA.'/runtime/stalker-updates.heartbeat')>time()-180];}
    private static function discover(string $url): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $response=WebFetch::get($url,12,2*1024*1024);
        if($response['status']!==200)throw new RuntimeException('The provider update page could not be read.');
        if(!class_exists('DOMDocument'))throw new RuntimeException('PHP DOM support is required to read update pages.');
        $doc=new DOMDocument();$prior=libxml_use_internal_errors(true);
        try{$doc->loadHTML('<?xml encoding="utf-8" ?>'.$response['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
        $xpath=new DOMXPath($doc);foreach($xpath->query('//script|//style|//noscript') as $node)$node->parentNode->removeChild($node);
        $blocks=[];
        foreach(['//tr','//li','//pre','//p','//div[not(.//div or .//tr or .//li or .//p or .//pre)]'] as $selector)foreach($xpath->query($selector) as $node){
            if(count($blocks)>=5000)break 2;$text=$node->textContent;if(strlen($text)>16000)continue;
            foreach($xpath->query('.//a[@href]',$node) as $anchor)$text.=' '.$anchor->getAttribute('href');$blocks[]=$text;
        }
        foreach(preg_split('/\r?\n/',$doc->textContent) as $line)$blocks[]=$line;
        $found=[];
        foreach($blocks as $text){
            preg_match_all('/\b(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\b/i',$text,$macs);
            preg_match_all('#https?://[^\s<>"\x27]+#i',$text,$urls);
            $macs=array_values(array_unique(array_map('strtoupper',$macs[0])));$urls=array_values(array_unique(array_map(static function($v){return rtrim(html_entity_decode($v,ENT_QUOTES|ENT_HTML5,'UTF-8'),".,;)]}");},$urls[0])));
            if(count($macs)!==1||count($urls)!==1)continue;
            try{stalker_validate_url($urls[0]);}catch(InvalidArgumentException $e){continue;}
            $label=str_ireplace([$macs[0],$urls[0]],'',$text);
            $label=trim(preg_replace('/\s+/u',' ',preg_replace('/\b(?:url|mac|portal|address|link)\s*:?/iu','',$label))," \t\r\n:|,-");
            $pair=hash('sha256',$urls[0].'|'.$macs[0]);
            if(!isset($found[$pair]))$found[$pair]=['url'=>$urls[0],'mac'=>$macs[0],'label'=>mb_substr($label,0,100),'position'=>stripos($response['body'],$macs[0])];
        }
        $found=array_values($found);usort($found,static function($a,$b){return ($a['position']===false?PHP_INT_MAX:$a['position'])<=>($b['position']===false?PHP_INT_MAX:$b['position']);});
        $labels=array_count_values(array_column($found,'label'));
        foreach($found as $i=>&$entry){$entry['stable']=$entry['label']!==''&&$labels[$entry['label']]===1;$entry['key']=$entry['stable']?'label:'.hash('sha256',$entry['label']):'position:'.($i+1);$entry['name']=$entry['stable']?$entry['label']:'Provider connection '.($i+1);unset($entry['position']);}unset($entry);
        return $found;
    }
    public static function preview(string $url): array {
        $s=self::settings();if($url==='')$url=(string)($s['url']??'');if($url==='')throw new InvalidArgumentException('Enter the provider update-page URL.');
        $entries=self::discover($url);$token=bin2hex(random_bytes(16));\Vaultcast\Repository::cachePut('stalker-discovery:'.$token,['url'=>$url,'entries'=>$entries],3600);
        return ['ok'=>true,'token'=>$token,'entries'=>$entries,'message'=>$entries?'Choose the entries to add or map to existing connections.':'No unambiguous URL/MAC pairs were found. This page may require login, JavaScript, or a different layout.'];
    }
    public static function accept(array $body): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $preview=\Vaultcast\Repository::cacheGet('stalker-discovery:'.(string)($body['token']??''));if(!$preview)throw new RuntimeException('Discovery expired. Check the page again.');
        $s=self::settings();$samePage=($s['url']??'')===$preview['url'];$mappings=$samePage?($s['mappings']??[]):[];$state=iptv_load();$feeds=$state['feeds'];$used=[];
        foreach((array)($body['selected']??[]) as $selected){
            $entry=null;foreach($preview['entries'] as $candidate)if($candidate['key']===($selected['key']??''))$entry=$candidate;
            if(!$entry)throw new InvalidArgumentException('Unknown discovered connection.');
            $id=(string)($selected['feed']??'');if($id==='')$id='stalker-'.bin2hex(random_bytes(6));
            if(isset($used[$id]))throw new InvalidArgumentException('Map each existing connection only once.');$used[$id]=true;
            $old=iptv_feed_by_id($state,$id);if($old&&($old['type']??'')!=='stalker')throw new InvalidArgumentException('Choose a Stalker connection.');
            $next=array_merge($old??[],['id'=>$id,'type'=>'stalker','name'=>trim((string)($selected['name']??''))?:$entry['name'],'url'=>$entry['url'],'mac'=>$entry['mac'],'enabled'=>true]);
            if($old){\Vaultcast\Repository::putDocument('stalker-update-backup:'.$id,$old);foreach($feeds as &$feed)if($feed['id']===$id)$feed=$next;unset($feed);}else $feeds[]=$next;
            $mappings[$entry['key']]=['feed'=>$id,'auto'=>!empty($selected['auto']),'stable'=>$entry['stable'],'allow_position'=>!empty($selected['allow_position']),'count'=>count($preview['entries'])];
        }
        if(!$used)throw new InvalidArgumentException('Select at least one discovered connection.');
        iptv_update_meta(['feeds'=>$feeds]);
        $s=['url'=>$preview['url'],'owner'=>(string)($body['_owner']??''),'mappings'=>$mappings,'fingerprint'=>hash('sha256',json_encode($preview['entries'])),'next_check'=>time()+86400,'last_check'=>time(),'message'=>'Connections saved. Daily checks are enabled.'];\Vaultcast\Repository::putDocument('stalker-update-settings',$s);
        self::enable();return ['ok'=>true,'count'=>count($used)];
    }
    public static function configure(array $body): array {
        $s=self::settings();if(isset($body['url'])&&trim((string)$body['url'])!==''){
            $url=trim((string)$body['url']);$p=parse_url($url);if(!$p||!in_array($p['scheme']??'',['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass']))throw new InvalidArgumentException('Enter an HTTP or HTTPS update-page URL.');
            if(($s['url']??'')!==$url){$s['mappings']=[];}$s['url']=$url;
        }
        $s['owner']=(string)($body['_owner']??$s['owner']??'');$s['paused']=!empty($body['paused']);$s['next_check']=$s['next_check']??time();$s['message']=$s['paused']?'Daily provider checks are paused.':'Daily provider checks are enabled. Discover connections to map any entries you want updated automatically.';\Vaultcast\Repository::putDocument('stalker-update-settings',$s);self::enable();return ['ok'=>true];
    }
    private static function enable(): void {
        $dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);$s=self::settings();$flag=$dir.'/stalker-updates.enabled';
        if(!empty($s['url'])&&empty($s['paused'])){file_put_contents($flag,'1');self::ensureWorker();}else @unlink($flag);
    }
    public static function requestCheck(): array {$s=self::settings();if(empty($s['url']))throw new InvalidArgumentException('Save an update-page URL first.');if(!empty($s['paused']))throw new InvalidArgumentException('Resume daily checks first.');$s['next_check']=time();$s['message']='Provider check queued. Refresh status shortly.';\Vaultcast\Repository::putDocument('stalker-update-settings',$s);self::enable();return ['ok'=>true,'queued'=>true];}
    public static function ensureWorker(): void {
        if(PHP_SAPI==='cli'||!is_file(STREAM_DATA.'/runtime/stalker-updates.enabled'))return;
        // An OS-released lock detects a stopped worker immediately after a reboot;
        // a recent heartbeat file alone must not suppress its restart.
        $probe=@fopen(STREAM_DATA.'/runtime/stalker-updates.worker.lock','c');if(!$probe)return;
        if(!flock($probe,LOCK_EX|LOCK_NB)){fclose($probe);return;}flock($probe,LOCK_UN);fclose($probe);
        $file=STREAM_DATA.'/runtime/stalker-updates.launch';$lock=@fopen($file,'c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return;
        try{$last=(int)stream_get_contents($lock);if($last>time()-5)return;ftruncate($lock,0);rewind($lock);fwrite($lock,(string)time());fflush($lock);Process::startPhpWorker('stalker-updates','stalkerupdates');}catch(Throwable $e){/* Status exposes the missing worker; never break normal browsing. */}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function check(bool $force=false): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';$dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);$lock=fopen($dir.'/stalker-updates.check.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['ok'=>true,'busy'=>true];
        try{
            $s=self::settings();if(empty($s['url'])||(!$force&&(!empty($s['paused'])||($s['next_check']??0)>time())))return ['ok'=>true,'skipped'=>true];
            $entries=self::discover($s['url']);$byKey=array_column($entries,null,'key');$updated=0;$review=0;
            foreach($s['mappings']??[] as $key=>$mapping){
                $currentSettings=self::settings();
                if(($currentSettings['url']??'')!==$s['url']||($currentSettings['mappings']??[])!==($s['mappings']??[])||!empty($currentSettings['paused']))return ['ok'=>true,'skipped'=>true];
                if(empty($mapping['auto']))continue;
                $entry=$byKey[$key]??null;
                if(!$entry||(!$entry['stable']&&(empty($mapping['allow_position'])||$mapping['count']!==count($entries)))){$review++;continue;}
                $state=iptv_load();$old=iptv_feed_by_id($state,$mapping['feed']);if(!$old||($old['type']??'')!=='stalker'||empty($old['enabled']))continue;
                if($old['url']===$entry['url']&&$old['mac']===$entry['mac'])continue;
                $replacement=array_merge($old,['url'=>$entry['url'],'mac'=>$entry['mac']]);
                try{stalker_session($replacement,true);}catch(RuntimeException $e){$review++;continue;}
                $fresh=iptv_load();if(iptv_feed_by_id($fresh,$old['id'])!==$old){$review++;continue;}
                \Vaultcast\Repository::putDocument('stalker-update-backup:'.$old['id'],$old);
                foreach($fresh['feeds'] as &$feed)if($feed['id']===$old['id'])$feed=$replacement;unset($feed);
                iptv_update_meta(['feeds'=>$fresh['feeds']]);$updated++;
            }
            $unmapped=count(array_diff(array_keys($byKey),array_keys($s['mappings']??[])));$review+=$unmapped;
            $fingerprint=hash('sha256',json_encode($entries));
            if($entries&&$fingerprint!==($s['fingerprint']??'')&&!empty($s['owner']))Inbox::add($s['owner'],'system','stalker','stalker-provider-updates',null,$updated?'Provider connections updated':'Provider connection details found',$updated?$updated.' connections were updated. Review Stalker settings for details.':count($entries).' connections were found on your provider page. Choose which to add or map in Stalker settings.',['kind'=>'settings','section'=>'live-stalker']);
            $s['fingerprint']=$fingerprint;$s['last_check']=time();$s['next_check']=time()+86400;$s['message']='Checked '.count($entries).' discovered connections. Updated '.$updated.'.'.($review?' '.$review.' need review; existing details were retained.':'');
            \Vaultcast\Repository::putDocument('stalker-update-settings',$s);return ['ok'=>true,'updated'=>$updated,'review'=>$review,'status'=>self::status()];
        }catch(Throwable $e){$s=self::settings();$s['last_check']=time();$s['next_check']=time()+86400;$s['message']='Update page could not be checked. Existing connection details were retained.';\Vaultcast\Repository::putDocument('stalker-update-settings',$s);throw new RuntimeException($s['message']);}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function rollback(string $id): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';$prior=\Vaultcast\Repository::document('stalker-update-backup:'.$id,[]);if(!$prior)throw new RuntimeException('No previous connection details are saved.');
        $state=iptv_load();foreach($state['feeds'] as &$feed)if($feed['id']===$id)$feed=$prior;unset($feed);iptv_update_meta(['feeds'=>$state['feeds']]);$s=self::settings();foreach($s['mappings']??[] as $key=>$map)if($map['feed']===$id)$s['mappings'][$key]['auto']=false;\Vaultcast\Repository::putDocument('stalker-update-settings',$s);return ['ok'=>true];
    }
    public static function run(): array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Worker only.');$dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);$lock=fopen($dir.'/stalker-updates.worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['ok'=>true,'busy'=>true];
        $version=trim((string)file_get_contents(STREAM_ROOT.'/VERSION'));
        try{while(is_file($dir.'/stalker-updates.enabled')&&trim((string)file_get_contents(STREAM_ROOT.'/VERSION'))===$version){file_put_contents($dir.'/stalker-updates.heartbeat',(string)time());try{self::check();}catch(Throwable $ignored){}\Vaultcast\Database::releaseForStreaming();sleep(60);clearstatcache();}}
        finally{@unlink($dir.'/stalker-updates.heartbeat');flock($lock,LOCK_UN);fclose($lock);}return ['ok'=>true];
    }
}
