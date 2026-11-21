<?php
declare(strict_types=1);

/** Shared catalogue state used by the legacy IPTV and guide integrations. */
final class StalkerCatalogues {
    public static function config(array $feed): string {return hash('sha256',json_encode(array_intersect_key($feed,array_flip(['id','url','mac','username','password','serial','deviceId','deviceId2']))));}
    public const KINDS=['live','vod','series'];
    private static function accountKey(array $feed): string {return 'stalker-account-'.self::config($feed);}
    public static function expired(array $feed): bool {
        $account=\Vaultcast\Repository::document(self::accountKey($feed),[]);
        return !empty($account['expires'])&&(int)$account['expires']<=time();
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
    public static function active(array $feed,string $kind): string {$saved=\Vaultcast\Repository::document('stalker-catalogues',['sources'=>[]]);$record=$saved['sources'][$feed['id'].'/'.$kind]??null;return $record&&$record['config']===self::config($feed)&&!empty($record['active'])?$record['active']:hash('sha256',json_encode($feed));}
    public static function connectionsChanged(array $before,array $after): void {
        $old=array_column($before,null,'id');$next=array_column($after,null,'id');$pdo=\Vaultcast\Database::connection();
        // A new or changed connection requires an explicit admin import.
        foreach($before as $f)if(($f['type']??'')==='stalker'&&(!isset($next[$f['id']])||empty($next[$f['id']]['enabled']))){$q=$pdo->prepare("UPDATE vaultcast_stalker_jobs SET status='removed',next_run=? WHERE feed_id=?");$q->execute([time(),$f['id']]);}
        foreach($after as $f)if(($f['type']??'')==='stalker'&&!empty($f['enabled'])&&(!isset($old[$f['id']])||empty($old[$f['id']]['enabled'])||self::config($old[$f['id']])!==self::config($f)))self::notifyAdmins($f);
    }
}
