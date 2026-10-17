<?php
declare(strict_types=1);

/** Cache guide responses before loading large station/EPG records. Scope by viewer. */
final class LiveGuideCache
{
    public static function read(int $hours, bool $slim, ?array $viewer, callable $build): array
    {
        $hours = max(1, min(48, $hours));
        $revision = [];
        foreach (['live_station','live_iptv','live_tvstudio','settings'] as $name) $revision[] = \Vaultcast\Repository::revision($name);
        $identity = ($viewer['role'] ?? '') === 'admin' ? ['admin'] : [$viewer['id'] ?? $viewer['user_id'] ?? '',$viewer['username'] ?? '',$viewer['role'] ?? ''];
        $key = 'live-guide-v2|' . hash('sha256', json_encode([$hours,$slim,$identity,$revision]));
        $data = \Vaultcast\Repository::cacheGet($key);
        if ($data === null) {
            // Keep programme windows internally, even for the small Home teaser.
            // Then current/next can advance accurately without rebuilding schedules.
            $data = $build($hours, false);
            \Vaultcast\Repository::cachePut($key, $data, $slim ? 300 : 30);
        }
        $now = (int)round(microtime(true)*1000);
        if (!is_array($data['channels'] ?? null)) $data['channels']=[];
        foreach ($data['channels'] as &$channel) {
            if (!isset($channel['programs'])) continue;
            $current = null; $next = null;
            foreach ($channel['programs'] as $program) {
                if (($program['startUnix'] ?? PHP_INT_MAX) <= $now && ($program['endUnix'] ?? 0) > $now) $current = $program;
                elseif (($program['startUnix'] ?? 0) > $now && $next === null) $next = $program;
            }
            $channel['now']=$current; $channel['next']=$next;
            if ($slim) unset($channel['programs']);
        }
        unset($channel);
        $data['slim']=$slim; $data['generatedAt']=$now;
        return $data;
    }

    public static function warm(): void
    {
        if (empty(Store::settings()['features']['live'])) return;
        require_once STREAM_ROOT . '/src/Live/store.php';
        require_once STREAM_ROOT . '/src/Live/epg.php';
        $station=relay_load_station();
        foreach ([null,['role'=>'admin']] as $viewer) self::read(2,true,$viewer,function($hours,$slim) use($station,$viewer){
            return epg_unified_guide($station,$hours,$slim,$viewer);
        });
    }

    public static function queueEpg(): void
    {
        $dir=STREAM_DATA . '/runtime'; if(!is_dir($dir)) @mkdir($dir,0775,true);
        $lock=@fopen($dir . '/epg-queue.lock','c'); if(!$lock)return;
        try {
            if(!flock($lock,LOCK_EX|LOCK_NB))return;
            $last=\Vaultcast\Repository::document('epg-refresh-job',[]);
            if(time()-(int)($last['at']??0)<300)return;
            $job=!empty($last['id'])?\Vaultcast\Repository::document('maintenance-job:' . $last['id'],[]):[];
            if(in_array($job['status']??'',['queued','running'],true))return;
            $result=Maintenance::start('epg','Stale cached EPG');
            \Vaultcast\Repository::putDocument('epg-refresh-job',['id'=>$result['job']['id'],'at'=>time()]);
        } catch(Throwable $e) { error_log('Vaultcast EPG queue: ' . $e->getMessage()); }
        finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
