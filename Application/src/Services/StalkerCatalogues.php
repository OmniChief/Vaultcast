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
        $state=self::read(true);$feeds=[];foreach(iptv_load()['feeds'] as $feed)$feeds[$feed['id']]=$feed;$rows=[];
        foreach($state['sources'] as $record){$feed=$feeds[$record['feed']]??null;if(!$feed||empty($feed['enabled']))continue;$summary=StalkerVod::summary($record['building']?:$record['active'],$record['kind']);$rows[]=['feed'=>$feed['id'],'name'=>$feed['name'],'kind'=>$record['kind'],'status'=>$record['status'],'scanned'=>$summary['scanned'],'error'=>$record['error']??$summary['error'],'updated'=>$record['updated']??null];}
        return ['ok'=>true,'sources'=>$rows,'worker'=>is_file(STREAM_DATA.'/runtime/stalker-catalogue.heartbeat')&&filemtime(STREAM_DATA.'/runtime/stalker-catalogue.heartbeat')>time()-120];
    }
    public static function control(array $opts): array {
        $operation=(string)($opts['operation']??'load');if(!in_array($operation,['load','refresh','pause'],true))throw new InvalidArgumentException('Unknown catalogue operation.');
        $kind=(string)($opts['kind']??'all');if(!in_array($kind,['all','live','vod','series'],true))throw new InvalidArgumentException('Choose a catalogue.');$kinds=$kind==='all'?['live','vod','series']:[$kind];
        $feeds=array_values(array_filter(iptv_load()['feeds'],static function($feed)use($opts){return ($feed['type']??'')==='stalker'&&!empty($feed['enabled'])&&(empty($opts['feed'])||$feed['id']===$opts['feed']);}));
        if($operation!=='pause')throw new RuntimeException('Legacy Stalker catalogue indexing has been retired. Browse provider categories on demand.');
        self::mutate(static function(&$state)use($feeds,$kinds,$operation){foreach($feeds as $feed)foreach($kinds as $kind){$key=$feed['id'].'/'.$kind;$record=$state['sources'][$key]??['feed'=>$feed['id'],'kind'=>$kind,'config'=>self::config($feed),'active'=>self::active($feed,$kind),'building'=>'','status'=>'new'];
            if($operation==='pause'){if($record['building'])$record['status']='paused';}
            elseif($operation==='refresh'||$record['config']!==self::config($feed)||(!$record['building']&&$record['status']!=='complete')){if($record['building']&&$record['building']!==$record['active'])$state['retired'][]=['fingerprint'=>$record['building'],'kind'=>$record['kind']];$record['config']=self::config($feed);$record['building']=hash('sha256',random_bytes(32));$record['status']='queued';$record['promoted']=false;}
            elseif($record['building']){$record['status']='queued';$q=\Vaultcast\Database::connection()->prepare("UPDATE vaultcast_stalker_scans SET error='' WHERE fingerprint=?");$q->execute([$record['building']]);}
            $record['error']='';$record['updated']=time();$state['sources'][$key]=$record;
        }});
        return self::status();
    }
    public static function connectionsChanged(array $before,array $after): void {
        $feeds=[];foreach($after as $feed)if(($feed['type']??'')==='stalker'&&!empty($feed['enabled']))$feeds[$feed['id']]=$feed;
        self::launch();
        return;
        self::mutate(static function(&$state)use($feeds,$before){
            foreach($before as $old){if(($old['type']??'')!=='stalker')continue;$new=$feeds[$old['id']]??null;if($new&&self::config($old)===self::config($new))continue;$oldFingerprint=hash('sha256',json_encode($old));
                foreach(['live','vod','series'] as $kind){$state['retired'][]=['fingerprint'=>$oldFingerprint,'kind'=>$kind];$key=$old['id'].'/'.$kind;
                    if($new&&!isset($state['sources'][$key])&&StalkerVod::scan($oldFingerprint,$kind)['processed']>0)$state['sources'][$key]=['feed'=>$new['id'],'kind'=>$kind,'config'=>self::config($new),'active'=>'','building'=>hash('sha256',random_bytes(32)),'status'=>'queued','promoted'=>false,'error'=>'','updated'=>time()];
                }
            }
            foreach($state['sources'] as $key=>$record){$feed=$feeds[$record['feed']]??null;if($feed&&$record['config']===self::config($feed))continue;foreach(['active','building'] as $field)if(!empty($record[$field]))$state['retired'][]=['fingerprint'=>$record[$field],'kind'=>$record['kind']];
            if(!$feed){unset($state['sources'][$key]);continue;}
            $record['config']=self::config($feed);$record['active']='';$record['building']=hash('sha256',random_bytes(32));$record['status']='queued';$record['promoted']=false;$record['error']='';$record['updated']=time();$state['sources'][$key]=$record;
        }});
    }
    private static function launch(): void {
        // The SQL-backed recursive catalogue is retired. Never queue or restart it.
        @unlink(STREAM_DATA.'/runtime/stalker-catalogue.enabled');
    }
    public static function ensureWorker(): void {
        self::launch();
    }
    // The previous recursive SQL indexing and cleanup worker are intentionally
    // unavailable. Legacy rows remain untouched until separately migrated.
    public static function run(): array {
        self::launch();
        return ['ok'=>true,'retired'=>true];
    }
}
