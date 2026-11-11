<?php
declare(strict_types=1);

/** Admin-owned provider catalogue scheduler. */
final class StalkerCatalogues {
    public static function config(array $feed): string {return hash('sha256',json_encode(array_intersect_key($feed,array_flip(['id','url','mac','username','password','serial','deviceId','deviceId2']))));}
    public const KINDS=['live','vod','series'];
    private static function accountKey(array $feed): string {return 'stalker-account-'.self::config($feed);}
    public static function expired(array $feed): bool {
        $account=\Vaultcast\Repository::document(self::accountKey($feed),[]);
        return !empty($account['expires'])&&(int)$account['expires']<=time();
    }
    public static function recordExpiry(array $feed): void {
        $key=self::accountKey($feed);
        if(\Vaultcast\Repository::document($key,null)!==null)return;
        $expiry=stalker_account_expiry($feed);
        \Vaultcast\Repository::putDocument($key,['expires'=>$expiry['expires']??null]);
    }
    public static function notifyAdmins(array $feed,bool $expired=false): void {
        $event=$expired?'expired':'scan-needed';
        foreach(Auth::users() as $user){
            if(!Permissions::allowed($user,'iptv_manage')||empty($user['id']))continue;
            Inbox::add((string)$user['id'],'system','stalker','stalker-'.$event.'-'.self::config($feed),null,
                $expired?'Stalker provider expired':'Stalker catalogue needs a scan',
                $feed['name'].($expired?' expired. Scan its replacement once it is available.':' is ready to scan in Stalker settings.'),
                ['kind'=>'settings','section'=>'live-stalker']);
        }
    }
    public static function localArtwork(array $data): array {
        foreach($data as $key=>&$value){
            if(in_array($key,['poster','logo','logoUrl'],true))$value='';
            elseif(in_array($key,['posterCandidates','channelLogos'],true))$value=[];
            elseif(is_array($value))$value=self::localArtwork($value);
        }
        return $data;
    }
    public static function active(array $feed,string $kind): string {$saved=\Vaultcast\Repository::document('stalker-catalogues',['sources'=>[]]);$record=$saved['sources'][$feed['id'].'/'.$kind]??null;return $record&&$record['config']===self::config($feed)&&!empty($record['active'])?$record['active']:hash('sha256',json_encode($feed));}
    /** Stored independently per connection and media type; legacy jobs scan all folders. */
    public static function folderSelection(string $feedId,string $kind): array {
        $saved=\Vaultcast\Repository::document('stalker-catalogue-folder-selection',['sources'=>[]]);
        $entry=$saved['sources'][$feedId.'/'.$kind]??null;
        if(!is_array($entry)||($entry['mode']??'all')!=='include')return ['mode'=>'all','ids'=>[]];
        return ['mode'=>'include','ids'=>array_values(array_filter((array)($entry['ids']??[]),'is_string'))];
    }
    public static function folderSelected(array $selection,string $folderId): bool {
        return $folderId===''||$selection['mode']==='all'||($folderId!=='*'&&in_array($folderId,$selection['ids'],true));
    }
    private static function feeds(): array {require_once STREAM_ROOT.'/src/Live/iptv.php';return array_values(array_filter(iptv_load()['feeds']??[],static function($f){return ($f['type']??'')==='stalker'&&!empty($f['enabled']);}));}
    public static function status(bool $summary=false): array {
        self::ensureWorker();$feeds=array_column(self::feeds(),null,'id');$sources=[];
        $heartbeat=STREAM_DATA.'/runtime/stalker-library.heartbeat';
        $worker=StalkerWorkerClock::managed('catalogue') || (is_file($heartbeat)&&filemtime($heartbeat)>time()-180);
        $active=$worker?json_decode((string)@file_get_contents(STREAM_DATA.'/runtime/stalker-library.active'),true):null;
        $seen=[];
        foreach(\Vaultcast\Database::connection()->query('SELECT feed_id,kind,config,generation,status,processed,error,next_run,last_hash FROM vaultcast_stalker_jobs') as $r){$r['name']=$feeds[$r['feed_id']]['name']??'Removed connection';$r['scanned']=(int)$r['processed'];
            $feed=$feeds[$r['feed_id']]??null;
            if($feed&&$r['config']!==self::config($feed))$r['status']='scan_required';
            if($r['kind']==='series'&&$r['status']==='complete'&&$r['last_hash']!=='episodes_complete')$r['status']='scan_required';
            if($feed&&self::expired($feed))$r['status']='expired';
            $r['active']=$active===[$r['feed_id'],$r['kind'],$r['generation']]&&in_array($r['status'],['queued','running','partial','waiting'],true);
            if($r['active']&&(int)$r['next_run']>time())$r['active']=false;
            unset($r['config'],$r['generation']);
            $seen[$r['feed_id']][$r['kind']]=true;
            if($summary){
                $q=\Vaultcast\Database::connection()->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(f.status IN ('complete','skipped')),0) AS done FROM vaultcast_stalker_folders f JOIN vaultcast_stalker_jobs j ON j.feed_id=f.feed_id AND j.kind=f.kind AND j.generation=f.generation WHERE f.feed_id=? AND f.kind=? AND f.folder_id<>''");
                $q->execute([$r['feed_id'],$r['kind']]);
                $progress=$q->fetch();
                $r['foldersTotal']=(int)$progress['total'];
                $r['foldersComplete']=(int)$progress['done'];
                $sources[]=$r;
                continue;
            }
            $q=\Vaultcast\Database::connection()->prepare("SELECT f.folder_id,f.title,f.status,f.next_page,f.processed,f.total,f.last_success,f.error,f.next_run FROM vaultcast_stalker_folders f JOIN vaultcast_stalker_jobs j ON j.feed_id=f.feed_id AND j.kind=f.kind AND j.generation=f.generation WHERE f.feed_id=? AND f.kind=? ORDER BY f.folder_id='*',f.folder_id");
            $q->execute([$r['feed_id'],$r['kind']]);$folders=$q->fetchAll();
            $selection=self::folderSelection($r['feed_id'],$r['kind']);
            $r['folderMode']=$selection['mode'];$r['folderIds']=$selection['ids'];
            $r['folders']=[];
            foreach($folders as $folder)if($folder['folder_id']!==''){
                $folder['selected']=self::folderSelected($selection,$folder['folder_id']);
                $r['folders'][]=$folder;
            }
            $r['foldersSelected']=count(array_filter($r['folders'],static function($f){return $f['selected'];}));
            $r['foldersTotal']=count(array_filter($folders,static function($f){return $f['folder_id']!=='';}));
            $r['foldersComplete']=count(array_filter($folders,static function($f){return $f['folder_id']!==''&&in_array($f['status'],['complete','skipped'],true);}));
            $r['currentFolder']=null;foreach($folders as $folder)if(!in_array($folder['status'],['complete','skipped'],true)){$r['currentFolder']=$folder;break;}
            $sources[]=$r;}
        foreach($feeds as $feed)foreach(self::KINDS as $kind)if(empty($seen[$feed['id']][$kind]))$sources[]=[
            'feed_id'=>$feed['id'],'kind'=>$kind,'name'=>$feed['name'],'scanned'=>0,'status'=>self::expired($feed)?'expired':'scan_required',
        ];
        return ['ok'=>true,'sources'=>$sources,'reset'=>\Vaultcast\Repository::document('stalker-catalogue-reset',['state'=>'idle']),'worker'=>$worker];
    }
    public static function control(array $opts): array {
        $op=(string)($opts['operation']??'load');if(!in_array($op,['load','refresh','pause','folders','discover','reset_rebuild'],true))throw new InvalidArgumentException('Unknown catalogue operation.');
        $kind=(string)($opts['kind']??'all');if($kind!=='all'&&!in_array($kind,self::KINDS,true))throw new InvalidArgumentException('Choose Live TV, Movies, Series, or all.');
        $selected=(string)($opts['feed']??'');$pdo=\Vaultcast\Database::connection();
        $reset=\Vaultcast\Repository::document('stalker-catalogue-reset',['state'=>'idle']);
        if(($reset['state']??'idle')==='purging')throw new InvalidArgumentException('Catalogue reset is in progress. Wait for it to finish.');
        if($op==='reset_rebuild'){
            if($selected!==''||$kind!=='all'||($opts['confirmation']??'')!=='RESET STALKER CATALOGUE')throw new InvalidArgumentException('Confirm a full Stalker Movies and Series reset.');
            $pdo->beginTransaction();
            try {
                // In-flight worker page commits lock the job row. Pausing every job
                // invalidates subsequent page responses before any purge begins.
                $pdo->exec("UPDATE vaultcast_stalker_jobs SET status='paused',next_run=0 WHERE kind IN ('vod','series')");
                \Vaultcast\Repository::putDocument('stalker-catalogue-reset',['state'=>'purging','table'=>0,'started'=>time()]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            file_put_contents(STREAM_DATA.'/runtime/stalker-library.enabled','1');
            self::ensureWorker();return self::status();
        }
        if($op==='discover'){
            if($selected===''||!in_array($kind,['vod','series'],true))throw new InvalidArgumentException('Choose one connection and Movies or Series to discover folders.');
            $feeds=array_column(self::feeds(),null,'id');
            if(!isset($feeds[$selected]))throw new InvalidArgumentException('Unknown or disabled Stalker connection.');
            $q=$pdo->prepare('SELECT status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=?');
            $q->execute([$selected,$kind]);
            if(in_array($q->fetchColumn(),['queued','running','partial','waiting'],true))throw new InvalidArgumentException('Pause the current catalogue scan before rediscovering provider folders.');
            require_once STREAM_ROOT.'/src/Live/stalker.php';
            // Provider requests are outside transactions and only fetch folder names.
            $groups=StalkerCatalogueScan::groups(stalker_call($feeds[$selected],'get_categories',[],$kind));
            $selection=self::folderSelection($selected,$kind);
            $pdo->beginTransaction();
            try {
                StalkerLibrary::queue($feeds[$selected],$kind,true);
                $q=$pdo->prepare('SELECT generation FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE');
                $q->execute([$selected,$kind]);$generation=$q->fetchColumn();
                $q=$pdo->prepare('INSERT INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title,status,last_success) VALUES(?,?,?,?,?,?,?)');
                $q->execute([$selected,$kind,$generation,'','Folder discovery','complete',time()]);
                foreach($groups as $id=>$title){
                    $q->execute([$selected,$kind,$generation,(string)$id,$title,self::folderSelected($selection,(string)$id)?'pending':'skipped',null]);
                }
                $q=$pdo->prepare("UPDATE vaultcast_stalker_jobs SET status='paused',last_hash='discovered',error='',next_run=? WHERE feed_id=? AND kind=?");
                $q->execute([time(),$selected,$kind]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            return self::status();
        }
        if($op==='folders'){
            if($selected===''||!in_array($kind,['vod','series'],true))throw new InvalidArgumentException('Choose one connection and Movies or Series to save folder selection.');
            $feeds=array_column(self::feeds(),null,'id');
            if(!isset($feeds[$selected]))throw new InvalidArgumentException('Unknown or disabled Stalker connection.');
            $mode=(string)($opts['mode']??'');
            if(!in_array($mode,['all','include'],true))throw new InvalidArgumentException('Invalid folder selection mode.');
            $ids=$opts['folders']??[];
            if(!is_array($ids)||count($ids)>500)throw new InvalidArgumentException('Invalid folder selection.');
            if($mode==='all')$ids=[];
            else {
                $known=$pdo->prepare("SELECT DISTINCT folder_id FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND folder_id NOT IN ('','*')");
                $known->execute([$selected,$kind]);$allowed=array_fill_keys($known->fetchAll(PDO::FETCH_COLUMN),true);
                foreach($ids as $id)if(!is_string($id)||!isset($allowed[$id]))throw new InvalidArgumentException('Unknown provider folder. Reload the catalogue folder list.');
                $ids=array_values(array_unique($ids));
            }
            $pdo->beginTransaction();
            try {
                $saved=\Vaultcast\Repository::document('stalker-catalogue-folder-selection',['sources'=>[]]);
                if(!isset($saved['sources'])||!is_array($saved['sources']))$saved=['sources'=>[]];
                $saved['sources'][$selected.'/'.$kind]=['mode'=>$mode,'ids'=>$ids];
                \Vaultcast\Repository::putDocument('stalker-catalogue-folder-selection',$saved);
                // A fresh generation invalidates any in-flight page response without
                // invalidating offers already published from earlier generations.
                StalkerLibrary::queue($feeds[$selected],$kind,true);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            file_put_contents(STREAM_DATA.'/runtime/stalker-library.enabled','1');
            self::ensureWorker();return self::status();
        }
        $pdo->beginTransaction();
        try {
            foreach(self::feeds() as $f)if($selected===''||$f['id']===$selected)foreach($kind==='all'?self::KINDS:[$kind] as $type){
                // Lock the job row so a response fetched before pause/rebuild cannot commit afterwards.
                $q=$pdo->prepare('SELECT status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE');$q->execute([$f['id'],$type]);$status=$q->fetchColumn();
                if($op==='pause'){$q=$pdo->prepare("UPDATE vaultcast_stalker_jobs SET status='paused' WHERE feed_id=? AND kind=?");$q->execute([$f['id'],$type]);}
                else StalkerLibrary::queue($f,$type,$op==='refresh');
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        file_put_contents(STREAM_DATA.'/runtime/stalker-library.enabled','1');self::ensureWorker();return self::status();
    }
    public static function connectionsChanged(array $before,array $after): void {
        $old=array_column($before,null,'id');$next=array_column($after,null,'id');$pdo=\Vaultcast\Database::connection();
        // A new or changed connection requires an explicit admin import.
        foreach($before as $f)if(($f['type']??'')==='stalker'&&(!isset($next[$f['id']])||empty($next[$f['id']]['enabled']))){$q=$pdo->prepare("UPDATE vaultcast_stalker_jobs SET status='removed',next_run=? WHERE feed_id=?");$q->execute([time(),$f['id']]);}
        foreach($after as $f)if(($f['type']??'')==='stalker'&&!empty($f['enabled'])&&(!isset($old[$f['id']])||empty($old[$f['id']]['enabled'])||self::config($old[$f['id']])!==self::config($f)))self::notifyAdmins($f);
    }
    private static function revision(): string {
        $files=[__FILE__,__DIR__.'/StalkerLibrary.php',__DIR__.'/StalkerCatalogueScan.php',
            __DIR__.'/StalkerDirect.php',__DIR__.'/StalkerLiveBrowse.php',STREAM_ROOT.'/src/Live/stalker.php'];
        return hash('sha256',implode('',array_map(static function($file){return hash_file('sha256',$file);},$files)));
    }
    public static function ensureWorker(): void {
        if(PHP_SAPI==='cli'||!is_file(STREAM_DATA.'/runtime/stalker-library.enabled'))return;
        StalkerWorkerClock::configure();
        if (StalkerWorkerClock::managed('catalogue')) return;
        $dir=STREAM_DATA.'/runtime';$probe=@fopen($dir.'/stalker-library.worker.lock','c');if(!$probe)return;
        if(!flock($probe,LOCK_EX|LOCK_NB)){fclose($probe);if(trim((string)@file_get_contents($dir.'/stalker-library.revision'))===self::revision())return;}else{flock($probe,LOCK_UN);fclose($probe);}
        $lock=@fopen($dir.'/stalker-library.launch','c+');if(!$lock)return;if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return;}
        try{$last=(int)stream_get_contents($lock);if($last>time()-10)return;ftruncate($lock,0);rewind($lock);fwrite($lock,(string)time());fflush($lock);Process::startPhpWorker('stalker-catalogue','stalkerlibrary');}catch(Throwable $e){error_log('Stalker catalogue worker could not start.');}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function run(bool $slice = false): array {
        $dir=STREAM_DATA.'/runtime';$lock=fopen($dir.'/stalker-library.worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['ok'=>true,'alreadyRunning'=>true];
        $revision=self::revision();$version=trim((string)file_get_contents(STREAM_ROOT.'/VERSION'));file_put_contents($dir.'/stalker-library.revision',$revision);
        $checkpoint=json_decode((string)@file_get_contents($dir.'/stalker-library.active'),true);
        $current=is_array($checkpoint)&&array_keys($checkpoint)===[0,1,2]
            &&is_string($checkpoint[0])&&in_array($checkpoint[1],self::KINDS,true)&&is_string($checkpoint[2])
            ?$checkpoint:null;
        $deadline = microtime(true) + 12;
        $busy = false;
        try{while(($slice || !StalkerWorkerClock::managed('catalogue')) && is_file($dir.'/stalker-library.enabled')&&self::revision()===$revision&&trim((string)file_get_contents(STREAM_ROOT.'/VERSION'))===$version){touch($dir.'/stalker-library.heartbeat');$busy=false;try{$busy=StalkerLibrary::tick($current);}catch(Throwable $e){error_log('Stalker catalogue step failed: '.$e->getMessage());}\Vaultcast\Database::releaseForStreaming();if ($slice && (!$busy || microtime(true) >= $deadline)) break;sleep($busy?1:30);clearstatcache();}}
        finally{@unlink($dir.'/stalker-library.heartbeat');flock($lock,LOCK_UN);fclose($lock);}
        if(!$slice && !StalkerWorkerClock::managed('catalogue') && is_file($dir.'/stalker-library.enabled'))try{Process::startPhpWorker('stalker-catalogue','stalkerlibrary');}catch(Throwable $ignored){}
        return ['ok'=>true, 'busy'=>$busy];
    }
}
