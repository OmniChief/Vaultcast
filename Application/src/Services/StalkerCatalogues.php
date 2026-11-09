<?php
declare(strict_types=1);

/** Admin-owned provider catalogue scheduler; Live TV remains on demand. */
final class StalkerCatalogues {
    public static function config(array $feed): string {return hash('sha256',json_encode(array_intersect_key($feed,array_flip(['id','url','mac','username','password','serial','deviceId','deviceId2']))));}
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
    public static function status(): array {
        self::ensureWorker();$feeds=array_column(self::feeds(),null,'id');$sources=[];
        foreach(\Vaultcast\Database::connection()->query('SELECT feed_id,kind,status,processed,error,next_run FROM vaultcast_stalker_jobs') as $r){$r['name']=$feeds[$r['feed_id']]['name']??'Removed connection';$r['scanned']=(int)$r['processed'];
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
        return ['ok'=>true,'sources'=>$sources,'worker'=>is_file(STREAM_DATA.'/runtime/stalker-library.heartbeat')&&filemtime(STREAM_DATA.'/runtime/stalker-library.heartbeat')>time()-180];
    }
    public static function control(array $opts): array {
        $op=(string)($opts['operation']??'load');if(!in_array($op,['load','refresh','pause','folders'],true))throw new InvalidArgumentException('Unknown catalogue operation.');
        $kind=(string)($opts['kind']??'all');if(!in_array($kind,['all','vod','series'],true))throw new InvalidArgumentException('Choose Movies, Series, or both. Live TV is browsed on demand.');
        $selected=(string)($opts['feed']??'');$pdo=\Vaultcast\Database::connection();
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
            foreach(self::feeds() as $f)if($selected===''||$f['id']===$selected)foreach($kind==='all'?['vod','series']:[$kind] as $type){
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
        if(!is_file(STREAM_DATA.'/runtime/stalker-library.enabled'))return;
        $old=array_column($before,null,'id');$next=array_column($after,null,'id');$pdo=\Vaultcast\Database::connection();
        // Saving settings only schedules work. Reconciliation runs in the background.
        foreach($before as $f)if(($f['type']??'')==='stalker'&&(!isset($next[$f['id']])||empty($next[$f['id']]['enabled']))){$q=$pdo->prepare("UPDATE vaultcast_stalker_jobs SET status='removed',next_run=? WHERE feed_id=?");$q->execute([time(),$f['id']]);}
        foreach($after as $f)if(($f['type']??'')==='stalker'&&!empty($f['enabled'])&&(!isset($old[$f['id']])||empty($old[$f['id']]['enabled'])||self::config($old[$f['id']])!==self::config($f)))foreach(['vod','series'] as $kind)StalkerLibrary::queue($f,$kind);
        self::ensureWorker();
    }
    private static function revision(): string {return hash('sha256',hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/StalkerLibrary.php').hash_file('sha256',__DIR__.'/StalkerCatalogueScan.php').hash_file('sha256',STREAM_ROOT.'/src/Live/stalker.php'));}
    public static function ensureWorker(): void {
        if(PHP_SAPI==='cli'||!is_file(STREAM_DATA.'/runtime/stalker-library.enabled'))return;
        $dir=STREAM_DATA.'/runtime';$probe=@fopen($dir.'/stalker-library.worker.lock','c');if(!$probe)return;
        if(!flock($probe,LOCK_EX|LOCK_NB)){fclose($probe);if(trim((string)@file_get_contents($dir.'/stalker-library.revision'))===self::revision())return;}else{flock($probe,LOCK_UN);fclose($probe);}
        $lock=@fopen($dir.'/stalker-library.launch','c+');if(!$lock)return;if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return;}
        try{$last=(int)stream_get_contents($lock);if($last>time()-10)return;ftruncate($lock,0);rewind($lock);fwrite($lock,(string)time());fflush($lock);Process::startPhpWorker('stalker-catalogue','stalkerlibrary');}catch(Throwable $e){error_log('Stalker catalogue worker could not start.');}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function run(): array {
        $dir=STREAM_DATA.'/runtime';$lock=fopen($dir.'/stalker-library.worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['ok'=>true,'alreadyRunning'=>true];
        $revision=self::revision();$version=trim((string)file_get_contents(STREAM_ROOT.'/VERSION'));file_put_contents($dir.'/stalker-library.revision',$revision);
        try{while(is_file($dir.'/stalker-library.enabled')&&self::revision()===$revision&&trim((string)file_get_contents(STREAM_ROOT.'/VERSION'))===$version){touch($dir.'/stalker-library.heartbeat');$busy=false;try{$busy=StalkerLibrary::tick();}catch(Throwable $e){error_log('Stalker catalogue step failed: '.$e->getMessage());}\Vaultcast\Database::releaseForStreaming();sleep($busy?1:30);clearstatcache();}}
        finally{@unlink($dir.'/stalker-library.heartbeat');flock($lock,LOCK_UN);fclose($lock);}
        if(is_file($dir.'/stalker-library.enabled'))try{Process::startPhpWorker('stalker-catalogue','stalkerlibrary');}catch(Throwable $ignored){}
        return ['ok'=>true];
    }
}
