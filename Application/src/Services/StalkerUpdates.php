<?php
declare(strict_types=1);

final class StalkerUpdates {
    private static function settings(): array {return \Vaultcast\Repository::document('stalker-update-settings',[]);}
    public static function status(): array {$s=self::settings();return ['configured'=>!empty($s['url']),'paused'=>!empty($s['paused']),'mappings'=>$s['mappings']??[],'last_check'=>$s['last_check']??null,'next_check'=>$s['next_check']??null,'message'=>$s['message']??'Add your provider update page to discover its connections.','worker'=>is_file(STREAM_DATA.'/runtime/stalker-updates.heartbeat')&&filemtime(STREAM_DATA.'/runtime/stalker-updates.heartbeat')>time()-180];}
    private static function values(string $text): array {
        $text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
        preg_match_all('/\b(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}\b/i',$text,$macs);
        preg_match_all('#https?://[^\s<>"\x27]+#i',$text,$urls);
        return ['urls'=>array_values(array_unique(array_map(static function($v){return rtrim($v,".,;)]}");},$urls[0]))),'macs'=>array_values(array_unique(array_map(static function($v){return strtoupper(str_replace('-',':',$v));},$macs[0])))];
    }
    private static function blockText(DOMXPath $xpath,DOMNode $node): string {
        $text=$node->textContent;
        foreach($xpath->query('.//@href|.//@value|.//@data-clipboard-text|.//@data-copy|.//@data-url|.//@data-mac',$node) as $attribute)$text.=' '.$attribute->nodeValue;
        return $text;
    }
    private static function fingerprint(array $entries,array $fields,array $mappings): string {
        $byId=array_column(array_merge($fields['urls']??[],$fields['macs']??[]),null,'id');$selected=[];
        foreach($mappings as $key=>$mapping)if(isset($mapping['fields']))foreach(['url','mac'] as $type){$id=$mapping['fields'][$type];$selected[$key][$type]=$byId[$id]['value']??null;}
        return hash('sha256',json_encode([$entries,$selected]));
    }
    private static function discover(string $url,?array &$fields=null): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $response=WebFetch::get($url,12,2*1024*1024);
        if($response['status']!==200)throw new RuntimeException('The provider update page could not be read.');
        if(!class_exists('DOMDocument'))throw new RuntimeException('PHP DOM support is required to read update pages.');
        $doc=new DOMDocument();$prior=libxml_use_internal_errors(true);
        try{$doc->loadHTML('<?xml encoding="utf-8" ?>'.$response['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
        $xpath=new DOMXPath($doc);foreach($xpath->query('//script|//style|//noscript') as $node)$node->parentNode->removeChild($node);
        $fields=['urls'=>[],'macs'=>[]];$order=0;$idCounts=[];$nameCounts=[];
        foreach($xpath->query('//*[@id]') as $element){$id=$element->getAttribute('id');$idCounts[$id]=($idCounts[$id]??0)+1;}
        foreach($xpath->query('//input[@name]|//textarea[@name]') as $element){$nameKey=strtolower($element->tagName).'|'.$element->getAttribute('name');$nameCounts[$nameKey]=($nameCounts[$nameKey]??0)+1;}
        foreach($xpath->query('//text()[normalize-space()]|//@href|//@value|//@data-clipboard-text|//@data-copy|//@data-url|//@data-mac') as $node){
            $order++;if($order>30000)break;
            $text=(string)$node->nodeValue;if(strlen($text)>16000)continue;
            $element=$node instanceof DOMAttr?$node->ownerElement:$node->parentNode;
            if(!$element instanceof DOMElement)continue;
            // Prefer an explicit field/container ID or a unique named input. These
            // anchors survive unrelated boxes being inserted elsewhere on the page.
            $anchor=$element;$suffix=$node instanceof DOMAttr?'@'.$node->nodeName:($node->nodeType===XML_TEXT_NODE?'text()':'');
            $locator=$node->getNodePath();$stable=false;
            while($anchor instanceof DOMElement){
                $id=$anchor->getAttribute('id');
                if($id!==''&&($idCounts[$id]??0)===1){$locator='id:'.$id.'|'.substr($node->getNodePath(),strlen($anchor->getNodePath()));$stable=$anchor===$element;break;}
                if($anchor===$element&&$anchor->hasAttribute('name')&&in_array(strtolower($anchor->tagName),['input','textarea'],true)){
                    $name=$anchor->getAttribute('name');
                    if(($nameCounts[strtolower($anchor->tagName).'|'.$name]??0)===1){$locator='name:'.$anchor->tagName.':'.$name.'|'.$suffix;$stable=true;break;}
                }
                $anchor=$anchor->parentNode;
            }
            $context=trim(preg_replace('/\s+/u',' ',(string)$element->getAttribute('aria-label').' '.(string)$element->getAttribute('name').' '.(string)$element->getAttribute('id')));
            $values=self::values($text);
            foreach(['urls','macs'] as $type)foreach($values[$type] as $slot=>$value){
                if(count($fields[$type])>=250)continue;
                $valid=true;if($type==='urls')try{stalker_validate_url($value);}catch(InvalidArgumentException $e){$valid=false;}
                $fields[$type][]=['id'=>hash('sha256',$type.'|'.$locator.'|'.$slot),'value'=>$value,'stable'=>$stable&&count($values[$type])===1,'order'=>$order,'context'=>mb_substr($context,0,100),'valid'=>$valid];
            }
        }
        $blocks=[];
        foreach(['//tr','//li','//pre','//p','//section','//article','//div[not(.//div or .//tr or .//li or .//p or .//pre)]','//div[.//input or .//textarea or .//*[@data-clipboard-text]]'] as $selector)foreach($xpath->query($selector) as $node){
            if(count($blocks)>=5000)break 2;$text=self::blockText($xpath,$node);if(strlen($text)>16000)continue;$blocks[]=$text;
        }
        foreach(preg_split('/\r?\n/',$doc->textContent) as $line)$blocks[]=$line;
        $found=[];
        foreach($blocks as $text){
            $values=self::values($text);$macs=$values['macs'];$urls=$values['urls'];
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
        $fields=[];$entries=self::discover($url,$fields);$token=bin2hex(random_bytes(16));\Vaultcast\Repository::cachePut('stalker-discovery:'.$token,['url'=>$url,'entries'=>$entries,'fields'=>$fields],3600);
        return ['ok'=>true,'token'=>$token,'entries'=>$entries,'fields'=>$fields,'mappings'=>($s['url']??'')===$url?($s['mappings']??[]):[],'message'=>count($entries).' suggested pairs, '.count($fields['urls']).' URL fields and '.count($fields['macs']).' MAC fields found. Review the suggestions or pair fields below. Textboxes and copy fields are included; pages populated only by JavaScript still require manual setup.'];
    }
    public static function accept(array $body): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $preview=\Vaultcast\Repository::cacheGet('stalker-discovery:'.(string)($body['token']??''));if(!$preview)throw new RuntimeException('Discovery expired. Check the page again.');
        $s=self::settings();$samePage=($s['url']??'')===$preview['url'];$mappings=$samePage?($s['mappings']??[]):[];$state=iptv_load();$feeds=$state['feeds'];$used=[];$usedKeys=[];
        $fields=$preview['fields']??['urls'=>[],'macs'=>[]];$urls=array_column($fields['urls'],null,'id');$macs=array_column($fields['macs'],null,'id');
        $shape=hash('sha256',json_encode([array_keys($urls),array_keys($macs)]));
        foreach((array)($body['selected']??[]) as $selected){
            $entry=null;foreach($preview['entries'] as $candidate)if($candidate['key']===($selected['key']??''))$entry=$candidate;
            $fieldMapping=null;
            if(isset($selected['url_field'],$selected['mac_field'])){
                $urlField=$urls[(string)$selected['url_field']]??null;$macField=$macs[(string)$selected['mac_field']]??null;
                if(!$urlField||!$macField||empty($urlField['valid']))throw new InvalidArgumentException('Choose a valid portal URL field and MAC field from this discovery.');
                stalker_validate_url($urlField['value']);
                $entry=['key'=>'fields:'.hash('sha256',$urlField['id'].'|'.$macField['id']),'url'=>$urlField['value'],'mac'=>$macField['value'],'stable'=>$urlField['stable']&&$macField['stable'],'name'=>'Provider connection'];
                $fieldMapping=['url'=>$urlField['id'],'mac'=>$macField['id'],'shape'=>$shape];
                if(!empty($selected['auto'])&&!$entry['stable']&&empty($selected['allow_position']))throw new InvalidArgumentException('Confirm following these fields by page position, or turn off automatic updates for this pair.');
            }
            if(!$entry)throw new InvalidArgumentException('Unknown discovered connection.');
            if(isset($usedKeys[$entry['key']]))throw new InvalidArgumentException('Select each page field pair only once.');$usedKeys[$entry['key']]=true;
            $id=(string)($selected['feed']??'');if($id==='')$id='stalker-'.bin2hex(random_bytes(6));
            if(isset($used[$id]))throw new InvalidArgumentException('Map each existing connection only once.');$used[$id]=true;
            $old=iptv_feed_by_id($state,$id);if($old&&($old['type']??'')!=='stalker')throw new InvalidArgumentException('Choose a Stalker connection.');
            $next=array_merge($old??[],['id'=>$id,'type'=>'stalker','name'=>trim((string)($selected['name']??''))?:$entry['name'],'url'=>$entry['url'],'mac'=>$entry['mac'],'enabled'=>true]);
            if($old){\Vaultcast\Repository::putDocument('stalker-update-backup:'.$id,$old);foreach($feeds as &$feed)if($feed['id']===$id)$feed=$next;unset($feed);}else $feeds[]=$next;
            foreach($mappings as $oldKey=>$mapping)if($mapping['feed']===$id)unset($mappings[$oldKey]);
            $mappings[$entry['key']]=['feed'=>$id,'auto'=>!empty($selected['auto']),'stable'=>$entry['stable'],'allow_position'=>!empty($selected['allow_position']),'count'=>count($preview['entries'])];
            if($fieldMapping)$mappings[$entry['key']]['fields']=$fieldMapping;
        }
        if(!$used)throw new InvalidArgumentException('Select at least one discovered connection.');
        iptv_update_meta(['feeds'=>$feeds]);
        $s=['url'=>$preview['url'],'owner'=>(string)($body['_owner']??''),'mappings'=>$mappings,'paused'=>!empty($s['paused']),'fingerprint'=>self::fingerprint($preview['entries'],$fields,$mappings),'next_check'=>time()+86400,'last_check'=>time(),'message'=>'Connections and selected page fields saved.'.(!empty($s['paused'])?' Daily checks remain paused.':' Daily checks are enabled.')];\Vaultcast\Repository::putDocument('stalker-update-settings',$s);
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
        $dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);$s=self::settings();$flag=$dir.'/stalker-updates.enabled-v2';
        @unlink($dir.'/stalker-updates.enabled');
        if(!empty($s['url'])&&empty($s['paused'])){file_put_contents($flag,'1');self::ensureWorker();}else @unlink($flag);
    }
    public static function requestCheck(): array {$s=self::settings();if(empty($s['url']))throw new InvalidArgumentException('Save an update-page URL first.');if(!empty($s['paused']))throw new InvalidArgumentException('Resume daily checks first.');$s['next_check']=time();$s['message']='Provider check queued. Refresh status shortly.';\Vaultcast\Repository::putDocument('stalker-update-settings',$s);self::enable();return ['ok'=>true,'queued'=>true];}
    public static function ensureWorker(): void {
        if(PHP_SAPI==='cli')return;
        $dir=STREAM_DATA.'/runtime';
        // Retire the original loop automatically, including same-version branch
        // updates. Its flag disappears; a fresh worker waits for its lock.
        if(is_file($dir.'/stalker-updates.enabled')&&!is_file($dir.'/stalker-updates.enabled-v2'))self::migrateWorkerFlag($dir);
        if(!is_file($dir.'/stalker-updates.enabled-v2'))return;
        // An OS-released lock detects a stopped worker immediately after a reboot;
        // a recent heartbeat file alone must not suppress its restart.
        $probe=@fopen(STREAM_DATA.'/runtime/stalker-updates.worker.lock','c');if(!$probe)return;
        if(!flock($probe,LOCK_EX|LOCK_NB)){fclose($probe);if(trim((string)@file_get_contents($dir.'/stalker-updates.revision'))===hash_file('sha256',__FILE__))return;}else{flock($probe,LOCK_UN);fclose($probe);}
        $file=STREAM_DATA.'/runtime/stalker-updates.launch';$lock=@fopen($file,'c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return;
        try{$last=(int)stream_get_contents($lock);if($last>time()-5)return;ftruncate($lock,0);rewind($lock);fwrite($lock,(string)time());fflush($lock);Process::startPhpWorker('stalker-updates','stalkerupdates');}catch(Throwable $e){/* Status exposes the missing worker; never break normal browsing. */}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function migrateWorkerFlag(string $dir): void {
        $s=self::settings();if(!empty($s['url'])&&empty($s['paused']))file_put_contents($dir.'/stalker-updates.enabled-v2','1');
        @unlink($dir.'/stalker-updates.enabled');
    }
    public static function check(bool $force=false): array {
        require_once STREAM_ROOT.'/src/Live/iptv.php';$dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);$lock=fopen($dir.'/stalker-updates.check.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['ok'=>true,'busy'=>true];
        try{
            $s=self::settings();if(empty($s['url'])||(!$force&&(!empty($s['paused'])||($s['next_check']??0)>time())))return ['ok'=>true,'skipped'=>true];
            $fields=[];$entries=self::discover($s['url'],$fields);$byKey=array_column($entries,null,'key');$updated=0;$review=0;
            $urls=array_column($fields['urls'],null,'id');$macs=array_column($fields['macs'],null,'id');$shape=hash('sha256',json_encode([array_keys($urls),array_keys($macs)]));
            foreach($s['mappings']??[] as $key=>$mapping){
                $currentSettings=self::settings();
                if(($currentSettings['url']??'')!==$s['url']||($currentSettings['mappings']??[])!==($s['mappings']??[])||!empty($currentSettings['paused']))return ['ok'=>true,'skipped'=>true];
                if(empty($mapping['auto']))continue;
                $entry=$byKey[$key]??null;
                if(isset($mapping['fields'])){
                    $urlField=$urls[$mapping['fields']['url']]??null;$macField=$macs[$mapping['fields']['mac']]??null;
                    if(!$urlField||!$macField||empty($urlField['valid'])||($mapping['stable']&&(!$urlField['stable']||!$macField['stable']))||(!$mapping['stable']&&(empty($mapping['allow_position'])||$mapping['fields']['shape']!==$shape))){$review++;continue;}
                    $entry=['url'=>$urlField['value'],'mac'=>$macField['value'],'stable'=>$mapping['stable']];
                }elseif(!$entry||(!$entry['stable']&&(empty($mapping['allow_position'])||$mapping['count']!==count($entries)))){$review++;continue;}
                $state=iptv_load();$old=iptv_feed_by_id($state,$mapping['feed']);if(!$old||($old['type']??'')!=='stalker'||empty($old['enabled']))continue;
                if($old['url']===$entry['url']&&$old['mac']===$entry['mac'])continue;
                $replacement=array_merge($old,['url'=>$entry['url'],'mac'=>$entry['mac']]);
                try{stalker_session($replacement,true);}catch(RuntimeException $e){$review++;continue;}
                $fresh=iptv_load();if(iptv_feed_by_id($fresh,$old['id'])!==$old){$review++;continue;}
                \Vaultcast\Repository::putDocument('stalker-update-backup:'.$old['id'],$old);
                foreach($fresh['feeds'] as &$feed)if($feed['id']===$old['id'])$feed=$replacement;unset($feed);
                iptv_update_meta(['feeds'=>$fresh['feeds']]);$updated++;
            }
            $mappedKeys=array_keys($s['mappings']??[]);
            foreach($s['mappings']??[] as $mapping)if(isset($mapping['fields'])){
                $u=$urls[$mapping['fields']['url']]['value']??null;$m=$macs[$mapping['fields']['mac']]['value']??null;
                foreach($byKey as $key=>$entry)if($u===$entry['url']&&$m===$entry['mac'])$mappedKeys[]=$key;
            }
            $unmapped=count(array_diff(array_keys($byKey),$mappedKeys));$review+=$unmapped;
            $fingerprint=self::fingerprint($entries,$fields,$s['mappings']??[]);
            if(($entries||!empty($s['mappings']))&&$fingerprint!==($s['fingerprint']??'')&&!empty($s['owner']))Inbox::add($s['owner'],'system','stalker','stalker-provider-updates',null,$updated?'Provider connections updated':'Provider connection details need review',$updated?$updated.' connections were updated. Review Stalker settings for details.':'The provider page or your selected fields changed. Review the discovered details in Stalker settings.',['kind'=>'settings','section'=>'live-stalker']);
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
        if(PHP_SAPI!=='cli')throw new RuntimeException('Worker only.');$dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);$lock=fopen($dir.'/stalker-updates.worker.lock','c');if(!$lock)return ['ok'=>true,'busy'=>true];
        if(!flock($lock,LOCK_EX|LOCK_NB)){
            if(trim((string)@file_get_contents($dir.'/stalker-updates.revision'))===hash_file('sha256',__FILE__)){fclose($lock);return ['ok'=>true,'busy'=>true];}
            $handoff=@fopen($dir.'/stalker-updates.handoff.lock','c');if(!$handoff||!flock($handoff,LOCK_EX|LOCK_NB)){if($handoff)fclose($handoff);fclose($lock);return ['ok'=>true,'busy'=>true];}
            $deadline=time()+120;$acquired=false;
            try{while(time()<$deadline){if(flock($lock,LOCK_EX|LOCK_NB)){$acquired=true;break;}sleep(1);}}
            finally{flock($handoff,LOCK_UN);fclose($handoff);}
            if(!$acquired){fclose($lock);return ['ok'=>true,'busy'=>true];}
        }
        $version=trim((string)file_get_contents(STREAM_ROOT.'/VERSION'));$revision=hash_file('sha256',__FILE__);file_put_contents($dir.'/stalker-updates.revision',$revision);
        try{while(is_file($dir.'/stalker-updates.enabled-v2')&&trim((string)file_get_contents(STREAM_ROOT.'/VERSION'))===$version&&hash_file('sha256',__FILE__)===$revision){file_put_contents($dir.'/stalker-updates.enabled','1');file_put_contents($dir.'/stalker-updates.heartbeat',(string)time());try{self::check();}catch(Throwable $ignored){}\Vaultcast\Database::releaseForStreaming();sleep(60);clearstatcache();}}
        finally{@unlink($dir.'/stalker-updates.heartbeat');flock($lock,LOCK_UN);fclose($lock);}
        if(is_file($dir.'/stalker-updates.enabled-v2'))try{file_put_contents($dir.'/stalker-updates.enabled','1');Process::startPhpWorker('stalker-updates','stalkerupdates');}catch(Throwable $ignored){}
        return ['ok'=>true];
    }
}
