<?php
declare(strict_types=1);

/** Admin-owned provider catalogue scheduler; Live TV remains on demand. */
final class StalkerCatalogues {
    public static function config(array $feed): string {return hash('sha256',json_encode(array_intersect_key($feed,array_flip(['id','url','mac','username','password','serial','deviceId','deviceId2']))));}
    public static function active(array $feed,string $kind): string {$saved=\Vaultcast\Repository::document('stalker-catalogues',['sources'=>[]]);$record=$saved['sources'][$feed['id'].'/'.$kind]??null;return $record&&$record['config']===self::config($feed)&&!empty($record['active'])?$record['active']:hash('sha256',json_encode($feed));}
    private static function feeds(): array {require_once STREAM_ROOT.'/src/Live/iptv.php';return array_values(array_filter(iptv_load()['feeds']??[],static function($f){return ($f['type']??'')==='stalker'&&!empty($f['enabled']);}));}
    public static function status(): array {
        self::ensureWorker();$feeds=array_column(self::feeds(),null,'id');$sources=[];
        foreach(\Vaultcast\Database::connection()->query('SELECT feed_id,kind,status,processed,error,next_run FROM vaultcast_stalker_jobs') as $r){$r['name']=$feeds[$r['feed_id']]['name']??'Removed connection';$r['scanned']=(int)$r['processed'];$sources[]=$r;}
        return ['ok'=>true,'sources'=>$sources,'worker'=>is_file(STREAM_DATA.'/runtime/stalker-library.heartbeat')&&filemtime(STREAM_DATA.'/runtime/stalker-library.heartbeat')>time()-180];
    }
    public static function control(array $opts): array {
        $op=(string)($opts['operation']??'load');if(!in_array($op,['load','refresh','pause'],true))throw new InvalidArgumentException('Unknown catalogue operation.');
        $kind=(string)($opts['kind']??'all');if(!in_array($kind,['all','vod','series'],true))throw new InvalidArgumentException('Choose Movies, Series, or both. Live TV is browsed on demand.');
        $selected=(string)($opts['feed']??'');$pdo=\Vaultcast\Database::connection();$pdo->beginTransaction();
        try {
            foreach(self::feeds() as $f)if($selected===''||$f['id']===$selected)foreach($kind==='all'?['vod','series']:[$kind] as $type){
                // Lock the job row so a response fetched before pause/rebuild cannot commit afterwards.
                $q=$pdo->prepare('SELECT status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE');$q->execute([$f['id'],$type]);$status=$q->fetchColumn();
                if($op==='pause'){$q=$pdo->prepare("UPDATE vaultcast_stalker_jobs SET status='paused' WHERE feed_id=? AND kind=?");$q->execute([$f['id'],$type]);}
                elseif($op==='load'&&$status==='paused'){$q=$pdo->prepare("UPDATE vaultcast_stalker_jobs SET status='queued',next_run=? WHERE feed_id=? AND kind=?");$q->execute([time(),$f['id'],$type]);}
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
    private static function revision(): string {return hash('sha256',hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/StalkerLibrary.php'));}
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
