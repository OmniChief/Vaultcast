<?php
declare(strict_types=1);

/** One administrator-owned background catalogue, shared by every viewer. */
final class StalkerCatalogues {
    private static $cached;
    public static function config(array $feed): string {return hash('sha256',json_encode(array_intersect_key($feed,array_flip(['id','url','mac','username','password','serial','deviceId','deviceId2']))));}
    private static function read(bool $fresh=false): array {if($fresh||self::$cached===null)self::$cached=\Vaultcast\Repository::document('stalker-catalogues',['sources'=>[],'retired'=>[]]);return self::$cached;}
    private static function mutate(callable $change): array {
        $dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);$lock=fopen($dir.'/stalker-catalogue.state.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Catalogue control storage is unavailable.');
        try{$state=self::read(true);$change($state);\Vaultcast\Repository::putDocument('stalker-catalogues',$state);self::$cached=$state;return $state;}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function active(array $feed,string $kind): string {$record=self::read()['sources'][$feed['id'].'/'.$kind]??null;return $record&&$record['config']===self::config($feed)&&!empty($record['active'])?$record['active']:hash('sha256',json_encode($feed));}
    public static function status(): array {
        self::ensureWorker();
        $state=self::read(true);$feeds=[];foreach(iptv_load()['feeds'] as $feed)$feeds[$feed['id']]=$feed;$rows=[];
        foreach($state['sources'] as $record){$feed=$feeds[$record['feed']]??null;if(!$feed||empty($feed['enabled']))continue;$summary=StalkerVod::summary($record['building']?:$record['active'],$record['kind']);$rows[]=['feed'=>$feed['id'],'name'=>$feed['name'],'kind'=>$record['kind'],'status'=>$record['status'],'scanned'=>$summary['scanned'],'error'=>$record['error']??$summary['error'],'updated'=>$record['updated']??null];}
        return ['ok'=>true,'sources'=>$rows,'worker'=>is_file(STREAM_DATA.'/runtime/stalker-catalogue.heartbeat')&&filemtime(STREAM_DATA.'/runtime/stalker-catalogue.heartbeat')>time()-120];
    }
    public static function control(array $opts): array {
        $operation=(string)($opts['operation']??'load');if(!in_array($operation,['load','refresh','pause'],true))throw new InvalidArgumentException('Unknown catalogue operation.');
        $kind=(string)($opts['kind']??'all');if(!in_array($kind,['all','live','vod','series'],true))throw new InvalidArgumentException('Choose a catalogue.');$kinds=$kind==='all'?['live','vod','series']:[$kind];
        $feeds=array_values(array_filter(iptv_load()['feeds'],static function($feed)use($opts){return ($feed['type']??'')==='stalker'&&!empty($feed['enabled'])&&(empty($opts['feed'])||$feed['id']===$opts['feed']);}));
        self::mutate(static function(&$state)use($feeds,$kinds,$operation){foreach($feeds as $feed)foreach($kinds as $kind){$key=$feed['id'].'/'.$kind;$record=$state['sources'][$key]??['feed'=>$feed['id'],'kind'=>$kind,'config'=>self::config($feed),'active'=>self::active($feed,$kind),'building'=>'','status'=>'new'];
            if($operation==='pause'){if($record['building'])$record['status']='paused';}
            elseif($operation==='refresh'||$record['config']!==self::config($feed)||(!$record['building']&&$record['status']!=='complete')){if($record['building']&&$record['building']!==$record['active'])$state['retired'][]=['fingerprint'=>$record['building'],'kind'=>$record['kind']];$record['config']=self::config($feed);$record['building']=hash('sha256',random_bytes(32));$record['status']='queued';$record['promoted']=false;}
            elseif($record['building']){$record['status']='queued';$q=\Vaultcast\Database::connection()->prepare("UPDATE vaultcast_stalker_scans SET error='' WHERE fingerprint=?");$q->execute([$record['building']]);}
            $record['error']='';$record['updated']=time();$state['sources'][$key]=$record;
        }});
        self::launch();return self::status();
    }
    public static function connectionsChanged(array $before,array $after): void {
        $feeds=[];foreach($after as $feed)if(($feed['type']??'')==='stalker'&&!empty($feed['enabled']))$feeds[$feed['id']]=$feed;
        self::mutate(static function(&$state)use($feeds,$before){
            foreach($before as $old){if(($old['type']??'')!=='stalker')continue;$new=$feeds[$old['id']]??null;if($new&&self::config($old)===self::config($new))continue;$oldFingerprint=hash('sha256',json_encode($old));
                foreach(['live','vod','series'] as $kind){$state['retired'][]=['fingerprint'=>$oldFingerprint,'kind'=>$kind];$key=$old['id'].'/'.$kind;
                    if($new&&!isset($state['sources'][$key])&&StalkerVod::scan($oldFingerprint,$kind)['processed']>0)$state['sources'][$key]=['feed'=>$new['id'],'kind'=>$kind,'config'=>self::config($new),'active'=>'','building'=>hash('sha256',random_bytes(32)),'status'=>'queued','promoted'=>false,'error'=>'','updated'=>time()];
                }
            }
            foreach($state['sources'] as $key=>$record){$feed=$feeds[$record['feed']]??null;if($feed&&$record['config']===self::config($feed))continue;foreach(['active','building'] as $field)if(!empty($record[$field]))$state['retired'][]=['fingerprint'=>$record[$field],'kind'=>$record['kind']];
            if(!$feed){unset($state['sources'][$key]);continue;}
            $record['config']=self::config($feed);$record['active']='';$record['building']=hash('sha256',random_bytes(32));$record['status']='queued';$record['promoted']=false;$record['error']='';$record['updated']=time();$state['sources'][$key]=$record;
        }});self::launch();
    }
    private static function launch(): void {
        $state=self::read();$pending=$state['retired']||array_filter($state['sources'],static function($record){return in_array($record['status'],['queued','running'],true);});
        $flag=STREAM_DATA.'/runtime/stalker-catalogue.enabled';if(!$pending){@unlink($flag);return;}file_put_contents($flag,'1');self::ensureWorker();
    }
    public static function ensureWorker(): void {
        $dir=STREAM_DATA.'/runtime';if(!is_file($dir.'/stalker-catalogue.enabled'))return;$probe=fopen($dir.'/stalker-catalogue.worker.lock','c');if(!$probe)return;if(!flock($probe,LOCK_EX|LOCK_NB)){fclose($probe);return;}flock($probe,LOCK_UN);fclose($probe);
        $launch=fopen($dir.'/stalker-catalogue.launch','c+');if(!$launch||!flock($launch,LOCK_EX|LOCK_NB))return;
        try{$last=(int)stream_get_contents($launch);if($last>time()-15)return;ftruncate($launch,0);rewind($launch);fwrite($launch,(string)time());fflush($launch);Process::startPhpWorker('stalker-catalogue','stalkercatalogue');}
        catch(Throwable $error){self::mutate(static function(&$state){foreach($state['sources'] as &$record)if(in_array($record['status'],['queued','running'],true))$record['error']='Catalogue worker could not start. Check the PHP CLI path in Advanced settings, then resume loading.';unset($record);});}
        finally{flock($launch,LOCK_UN);fclose($launch);}
    }
    private static function cleanup(): void {
        $state=self::read(true);$keep=[];foreach($state['sources'] as $record)foreach(['active','building'] as $field)if(!empty($record[$field]))$keep[]=$record[$field];
        foreach(iptv_load()['feeds'] as $feed)if(($feed['type']??'')==='stalker'&&!empty($feed['enabled']))$keep[]=hash('sha256',json_encode($feed));
        {$q=\Vaultcast\Database::connection()->prepare('SELECT DISTINCT fingerprint FROM vaultcast_stalker_titles'.($keep?' WHERE fingerprint NOT IN ('.implode(',',array_fill(0,count($keep),'?')).')':'').' LIMIT 20');$q->execute($keep);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $fingerprint)self::mutate(static function(&$fresh)use($fingerprint){$entry=['fingerprint'=>$fingerprint,'kind'=>null];if(!in_array($entry,$fresh['retired'],true))$fresh['retired'][]=$entry;});}
        $directory=STREAM_DATA.'/live/iptv_cache/art';$removed=0;if(is_dir($directory))foreach(new DirectoryIterator($directory) as $file)if($file->isFile()&&!$file->isLink()&&preg_match('/^[a-f0-9]{64}$/D',$file->getFilename())){ @unlink($file->getPathname());if(++$removed>=200)break;}
    }
    public static function run(): array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Background worker only.');require_once STREAM_ROOT.'/src/Live/iptv.php';$dir=STREAM_DATA.'/runtime';$lock=fopen($dir.'/stalker-catalogue.worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['ok'=>true,'busy'=>true];
        $revision=hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/StalkerVod.php');$heartbeat=0;
        try{self::cleanup();while($revision===hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/StalkerVod.php')){
            clearstatcache();if($heartbeat<time()-10){file_put_contents($dir.'/stalker-catalogue.heartbeat',(string)time());$heartbeat=time();}$state=self::read(true);
            if($state['retired']){$retired=$state['retired'][0];if(StalkerVod::discard($retired['fingerprint'],$retired['kind']))self::mutate(static function(&$fresh)use($retired){$fresh['retired']=array_values(array_filter($fresh['retired'],static function($item)use($retired){return $item!==$retired;}));});usleep(50000);continue;}
            $candidates=array_filter($state['sources'],static function($record){return in_array($record['status'],['queued','running'],true)&&$record['building']!=='';});if(!$candidates)break;uasort($candidates,static function($a,$b){return ($a['step']??0)<=>($b['step']??0);});$key=array_key_first($candidates);$record=$candidates[$key];$feed=iptv_feed_by_id(iptv_load(),$record['feed']);
            if(!$feed||empty($feed['enabled'])||self::config($feed)!==$record['config']){self::connectionsChanged([],iptv_load()['feeds']);continue;}
            $summary=StalkerVod::step($feed,$record['kind'],$record['building']);$root=StalkerVod::scan($record['building'],$record['kind']);
            self::mutate(static function(&$fresh)use($key,$record,$summary,$root){if(($fresh['sources'][$key]['building']??'')!==$record['building']){if(($fresh['sources'][$key]['active']??'')!==$record['building'])$fresh['retired'][]=['fingerprint'=>$record['building'],'kind'=>$record['kind']];return;}$next=&$fresh['sources'][$key];
                if($root['done']&&!$root['error']&&empty($next['promoted'])){if($next['active']&&$next['active']!==$record['building'])$fresh['retired'][]=['fingerprint'=>$next['active'],'kind'=>$record['kind']];$next['active']=$record['building'];$next['promoted']=true;}
                if($next['status']!=='paused')$next['status']=$summary['error']?'error':($summary['done']?'complete':'running');$next['error']=$summary['error'];$next['step']=microtime(true);$next['updated']=time();if($summary['done'])$next['building']='';
            });\Vaultcast\Database::releaseForStreaming();usleep(200000);
        }}finally{@unlink($dir.'/stalker-catalogue.heartbeat');flock($lock,LOCK_UN);fclose($lock);self::$cached=null;self::launch();}
        return ['ok'=>true];
    }
}
