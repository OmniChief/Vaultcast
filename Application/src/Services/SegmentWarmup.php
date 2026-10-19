<?php
declare(strict_types=1);

final class SegmentWarmup {
    public static function run(callable $progress): array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Timing preparation requires the maintenance worker.');
        $pdo=\Vaultcast\Database::connection();$total=(int)$pdo->query("SELECT COUNT(*) FROM vaultcast_items WHERE media_kind='video'")->fetchColumn();
        $out=['ok'=>true,'processed'=>0,'matched'=>0,'preserved'=>0,'unresolved'=>0,'missing_duration'=>0,'failed'=>0];$cursor='';$failures=0;
        $progress(0,$total,'');
        do{
            $items=\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_items WHERE media_kind='video' AND id>? ORDER BY id LIMIT 50",[$cursor]);MediaFacts::preload($items);
            foreach($items as $item){
                Maintenance::checkpoint();$cursor=(string)$item['id'];$out['processed']++;$progress($out['processed'],$total,(string)($item['title'] ?? $cursor));
                if(!EpisodeSegments::eligible($item)){$out['unresolved']++;continue;}
                $saved=EpisodeSegments::saved($item);
                if(($saved['source'] ?? '')==='Admin'){$out['preserved']++;continue;}
                try{
                    $facts=MediaFacts::get($item);$duration=(float)($facts['duration'] ?? $saved['duration'] ?? 0);
                    if($duration<=0){$durationKey='segment-duration:'.hash('sha256',MediaFacts::sig($item).$cursor);$cached=\Vaultcast\Repository::cacheGet($durationKey);$duration=(float)($cached['duration'] ?? 0);
                        if($duration<=0&&!empty($item['path'])&&is_file($item['path'])){$probe=MediaProbe::probeSummary($item['path'],8,true);$duration=(float)($probe['duration'] ?? 0);if($duration>0)\Vaultcast\Repository::cachePut($durationKey,['duration'=>$duration],604800);}
                    }
                    Maintenance::checkpoint();if($duration<=0||!is_finite($duration)){$out['missing_duration']++;continue;}
                    $result=EpisodeSegments::get($item,['role'=>'admin','prefs'=>[]],$duration);
                    if(($result['http_status'] ?? 200)===429){$out['unresolved']++;$out['partial']=true;$out['warning']='TheIntroDB rate limit reached. Run this task again later; saved matches are retained.';return $out;}
                    if(($result['http_status'] ?? 200)===0||($result['http_status'] ?? 200)>=500){$out['failed']++;$failures++;}else{$failures=0;if(!empty($result['segments']))$out['matched']++;else $out['unresolved']++;}
                    if($failures>=3){$out['partial']=true;$out['warning']='Provider unavailable. Run this task again later; saved matches are retained.';return $out;}
                }catch(Throwable $error){if($error->getMessage()==='Cancelled by administrator.')throw $error;Maintenance::checkpoint();$out['failed']++;error_log('Vaultcast timing preparation '.$cursor.': '.$error->getMessage());}
            }
        }while(count($items)===50);
        return $out;
    }
}
