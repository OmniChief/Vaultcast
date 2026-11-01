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
        $operation=(string)($opts['operation']??'load');
        if(!in_array($operation,['load','refresh','pause'],true))throw new InvalidArgumentException('Unknown catalogue operation.');
        if($operation!=='pause')throw new RuntimeException('Legacy Stalker catalogue indexing is retired. Browse provider categories on demand.');
        self::launch();
        return self::status();
    }
    public static function connectionsChanged(array $before,array $after): void {
        // Feed configuration changes must not queue legacy SQL indexing jobs.
        self::launch();
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
