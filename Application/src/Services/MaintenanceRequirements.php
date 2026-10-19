<?php
declare(strict_types=1);

/** Durable admin actions; recording one never launches a worker. */
final class MaintenanceRequirements
{
    private static $seen = [];
    // Future updates can declare an explicit one-time requirement without bumping the app version.
    const RELEASE_TASKS = [];
    private static function state(callable $change): array {
        $dir=STREAM_DATA.'/runtime';
        if (!is_dir($dir) && !@mkdir($dir,0775,true)) return [];
        $handle=@fopen($dir.'/maintenance-requirements.json','c+');
        if (!$handle) return [];
        try {
            if (!flock($handle,LOCK_EX)) return [];
            $raw=stream_get_contents($handle);$state=json_decode($raw ?: '{}',true);
            if (!is_array($state)) $state=[];
            $next=$change($state);
            if ($next!==$state) {rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($next,JSON_UNESCAPED_SLASHES));fflush($handle);}
            return $next;
        } finally {flock($handle,LOCK_UN);fclose($handle);}
    }
    public static function needed(string $task,string $reason,?string $release = null): void {
        if (!isset(Maintenance::TASKS[$task])) return;
        $key=$release ?? $task;
        if (isset(self::$seen[$key])) return;self::$seen[$key]=true;
        $revision=$release ?? (\Vaultcast\Repository::revision('catalogue').'|'.\Vaultcast\Repository::revision('browse_epoch'));
        self::state(static function($state) use ($task,$reason,$key,$revision,$release) {
            if ($release!==null && !empty($state['completed'][$release])) return $state;
            $old=$state['pending'][$key] ?? null;
            if (!$old || ($old['revision'] ?? '')!==$revision) $state['pending'][$key]=['id'=>$key,'task'=>$task,'reason'=>$reason,'revision'=>$revision,'at'=>time(),'release'=>$release];
            return $state;
        });
    }
    public static function status(): array {
        foreach (self::RELEASE_TASKS as $id=>$requirement) self::needed($requirement['task'],$requirement['reason'],$id);
        if (!BrowseIndex::ready()) self::needed('views','The prepared browsing index is missing. Prepare browsing pages; a folder scan is not needed.');
        if (BrowseIndex::ready()) {
            $q=\Vaultcast\Database::connection()->query('SELECT show_key FROM vaultcast_show_summaries WHERE source_sig<>prepared_sig LIMIT 1');
            if ($q->fetchColumn()!==false) self::needed('browse','Search and show summaries contain changes that need preparation.');
        }
        $state=self::state(static function($state){return $state;});$items=array_values($state['pending'] ?? []);
        $hasViews=false;foreach ($items as $item) if ($item['task']==='views') $hasViews=true;
        if ($hasViews) $items=array_values(array_filter($items,static function($item){return !in_array($item['task'],['browse','search'],true);}));
        foreach ($items as &$item) $item['label']=Maintenance::TASKS[$item['task']] ?? $item['task'];unset($item);
        return ['ok'=>true,'required'=>$items,'automatic_update_tasks'=>false];
    }
    public static function completed(array $job): void {
        $task=$job['task'];
        if ($task==='metadata' && ($job['result']['stage'] ?? '')!=='complete') return;
        $covers=[$task];
        if (in_array($task,['browse','search'],true)) $covers=['browse','search'];
        if ($task==='views'||$task==='scan'||($task==='metadata'&&($job['result']['stage'] ?? '')==='complete')) $covers=['views','browse','search',$task];
        $started=strtotime($job['started_at'] ?? '') ?: 0;
        self::state(static function($state) use ($covers,$started) {
            foreach ($state['pending'] ?? [] as $id=>$item) {
                if (in_array($item['task'],$covers,true) && ($item['at'] ?? PHP_INT_MAX)<=$started) {
                    if (!empty($item['release'])) $state['completed'][$item['release']]=date('c');
                    unset($state['pending'][$id]);
                }
            }
            return $state;
        });
    }
}
