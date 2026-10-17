<?php
declare(strict_types=1);

/** Compact, shared title records prepared by the existing CLI worker. */
final class SearchIndex {
    private const SLOT = 'search-titles-v1';
    private static function revision(): string {
        return \Vaultcast\Repository::revision('catalogue').'|'.\Vaultcast\Repository::revision('browse_epoch');
    }
    public static function warm(): void {
        if (PHP_SAPI !== 'cli') return;
        $revision=self::revision();
        $cached=\Vaultcast\Repository::cacheGet(self::SLOT);
        if (($cached['revision'] ?? '')===$revision) return;
        $sql="SELECT i.id,i.section_id,i.media_kind,i.title,i.show_title,i.system_name,JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.type')) AS type,JSON_EXTRACT(i.payload,'$.year') AS year FROM vaultcast_items i JOIN (SELECT MIN(id) id FROM vaultcast_items GROUP BY section_id,CASE WHEN show_title<>'' THEN show_title ELSE id END) titles ON titles.id=i.id ORDER BY i.id";
        $rows=[];
        foreach (\Vaultcast\Database::connection()->query($sql) as $row) {
            Maintenance::checkpoint();
            $show=$row['show_title']!=='';
            $title=Catalogue::fixText($show?$row['show_title']:$row['title']);
            $key=$show?Catalogue::showKey($row['section_id'],$row['show_title']):$row['id'];
            $rows[]=['id'=>$key,'show_key'=>$show?$key:null,'kind'=>$show?'show':'item','title'=>$title,
                'media_kind'=>$show?'show':$row['media_kind'],'type'=>$row['type'],
                'system'=>$row['system_name'] ?: null,'year'=>json_decode((string)$row['year'],true),
                '_query'=>mb_strtolower($title)];
        }
        if ($revision===self::revision()) \Vaultcast\Repository::cachePut(self::SLOT,['revision'=>$revision,'titles'=>$rows],0);
    }
    private static function schedule(string $revision): void {
        // Coalesce cold requests; building is never performed inside the HTTP request.
        register_shutdown_function(static function() use ($revision): void {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            $dir=STREAM_DATA.'/runtime';if (!is_dir($dir) && !@mkdir($dir,0775,true)) return;
            $lock=@fopen($dir.'/search-index-queue.lock','c');if (!$lock) return;
            try {
                if (!flock($lock,LOCK_EX|LOCK_NB)) return;
                $file=$dir.'/search-index-queue.json';
                $state=is_file($file)?json_decode((string)@file_get_contents($file),true):[];
                if (($state['revision'] ?? '')===$revision && time()-(int)($state['at'] ?? 0)<300) return;
                @file_put_contents($file,json_encode(['revision'=>$revision,'at'=>time()]),LOCK_EX);
                try {Maintenance::start('search','Search index update');}
                catch (Throwable $e) {error_log('Vaultcast search preparation: '.$e->getMessage());}
            } finally {flock($lock,LOCK_UN);fclose($lock);}
        });
    }
    /** null means the background index is not current; callers retain their DB fallback. */
    public static function suggest(string $query,array $viewer,bool $gamesOnly): ?array {
        $cached=\Vaultcast\Repository::cacheGet(self::SLOT);
        $revision=self::revision();
        if (!$cached || ($cached['revision'] ?? '')!==$revision) {self::schedule($revision);return null;}
        $query=mb_strtolower(trim($query));$hits=[];$permissions=AreaAccess::permissions($viewer);
        if ($query==='') return [];
        foreach ($cached['titles'] as $row) {
            if ($gamesOnly && $row['media_kind']!=='rom') continue;
            if (empty($permissions[$row['media_kind']==='rom'?'games':'library'])) continue;
            $at=mb_strpos($row['_query'],$query);
            if ($at===false) continue;
            $row['_score']=$row['_query']===$query?0:($at===0?1:2);$hits[]=$row;
            usort($hits,static function($a,$b){return ($a['_score']<=>$b['_score']) ?: strcasecmp($a['title'],$b['title']) ?: strcmp($a['id'],$b['id']);});
            if (count($hits)>5) array_pop($hits);
        }

        foreach ($hits as &$row) unset($row['_query'],$row['_score']);
        unset($row);return $hits;
    }
}
