<?php
declare(strict_types=1);

/**
 * Lightweight Stalker Movies/Series browse. Only request the selected provider page:
 * never build a second SQL catalogue just to show a category or a season.
 */
final class StalkerDirect {
    private static function key(array $feed,string $suffix): string {
        return 'stalker_direct_v1_'.hash('sha256',json_encode([$feed,$suffix]));
    }
    public static function item(array $feed,string $id): ?array {
        return \Vaultcast\Repository::cacheGet(self::key($feed,'item:'.$id));
    }
    private static function categories(array $feed,string $kind): array {
        $key=self::key($feed,'categories:'.$kind);
        $cached=\Vaultcast\Repository::cacheGet($key);
        if($cached!==null)return $cached;
        $categories=[];
        $result=stalker_call($feed,'get_categories',[],$kind);
        foreach(($result['data']??$result) as $row)if(is_array($row)&&isset($row['id'])){
            $categories[]=['id'=>(string)$row['id'],'name'=>(string)($row['title']??$row['name']??$row['id'])];
            if(count($categories)>=500)break;
        }
        \Vaultcast\Repository::cachePut($key,$categories,3600);
        return $categories;
    }
    private static function page(array $feed,string $kind,int $page,string $category,string $query,string $parent,bool $refresh): array {
        $key=self::key($feed,'page:'.json_encode([$kind,$page,$category,$query,$parent]));
        if(!$refresh){$cached=\Vaultcast\Repository::cacheGet($key);if($cached!==null)return $cached;}
        // At most one provider catalogue page per visit (page-number detection
        // may make two requests on the first page of a new connection).
        $params=['sortby'=>'name','category'=>$category!==''?$category:'*','search'=>$query];
        if($parent!=='')$params['movie_id']=$parent;
        $result=stalker_ordered_page($feed,$kind,$params,$page);
        $rows=$result['data']??$result;
        if(!is_array($rows))throw new RuntimeException('Provider returned an invalid catalogue page.');
        $items=[];
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id']))continue;
            $id=(string)$row['id'];if($id===''||strlen($id)>120)continue;
            $meta=StalkerSeries::metadata($feed,$row);
            $cmd=(string)($row['cmd']??'');
            $episodes=is_array($row['series']??null)?$row['series']:[];
            if($parent!==''&&$episodes&&$cmd!==''){
                foreach(array_slice($episodes,0,200) as $episode) {
                    if(!is_scalar($episode))continue;
                    $episode=(string)$episode;$publicId=$kind.':'.$id.':'.$episode;
                    $item=array_merge($meta,['id'=>$publicId,'kind'=>'vod','playable'=>true,'feed'=>$feed['id'],'connection'=>$feed['name']]);
                    $item['name'].=' · Episode '.$episode;
                    \Vaultcast\Repository::cachePut(self::key($feed,'item:'.$publicId),['cmd'=>$cmd,'series'=>$episode,'metadata'=>$item],7200);
                    $items[]=$item;
                }
                continue;
            }
            $playable=$cmd!==''&&empty($row['is_series'])&&empty($episodes)&&($kind==='vod'||$parent!=='');
            $publicId=$playable?$kind.':'.$id:$id;
            $item=array_merge($meta,['id'=>$publicId,'kind'=>$kind,'playable'=>$playable,'feed'=>$feed['id'],'connection'=>$feed['name']]);
            if($playable)\Vaultcast\Repository::cachePut(self::key($feed,'item:'.$publicId),['cmd'=>$cmd,'series'=>(string)($row['series_number']??''),'metadata'=>$item],7200);
            $items[]=$item;
        }
        $total=max(0,(int)($result['total_items']??count($items)));
        $perPage=max(1,(int)($result['max_page_items']??count($rows)));
        $out=['items'=>$items,'total'=>$total,'hasMore'=>$page*$perPage<$total];
        \Vaultcast\Repository::cachePut($key,$out,600);
        return $out;
    }
    public static function browse(array $state,array $opts): array {
        $kind=(string)($opts['kind']??'vod');
        if(!in_array($kind,['vod','series'],true))throw new InvalidArgumentException('Choose Movies or Series.');
        $feeds=array_values(array_filter($state['feeds']??[],static function($f){return ($f['type']??'')==='stalker'&&!empty($f['enabled']);}));
        if(!$feeds)return ['ok'=>true,'items'=>[],'total'=>0,'hasMore'=>false,'facets'=>['category'=>[]],'connections'=>[],'shared'=>false];
        $wanted=(string)($opts['feed']??'');$feed=$feeds[0];
        if($wanted!==''){
            $found=false;
            foreach($feeds as $candidate)if($candidate['id']===$wanted){$feed=$candidate;$found=true;break;}
            if(!$found)throw new InvalidArgumentException('Select an enabled Stalker connection.');
        }
        $offset=max(0,min(600000,(int)($opts['offset']??0)));
        $page=max(1,min(10000,(int)($opts['page']??(int)floor($offset/60)+1)));
        $category=mb_substr((string)($opts['category']??''),0,120);
        $query=mb_substr(trim((string)($opts['q']??'')),0,160);
        $parent=mb_substr((string)($opts['parent']??''),0,120);
        $refresh=!empty($opts['refresh']);
        // Browse and playback do not require an administrator to have indexed anything.
        $categories=$parent===''?self::categories($feed,$kind):[];
        $result=self::page($feed,$kind,$page,$category,$query,$parent,$refresh);
        foreach($result['items'] as &$item)$item['sources']=[array_intersect_key($item,array_flip(['id','feed','connection','kind','playable']))];
        unset($item);
        $result['ok']=true;$result['facets']=['category'=>array_map(static function($c){return ['value'=>$c['id'],'name'=>$c['name']];},$categories)];
        $result['categories']=$categories;$result['page']=$page;
        $result['connections']=[['name'=>$feed['name'],'scanned'=>count($result['items']),'total'=>$result['total'],'done'=>true,'error'=>'']];
        $result['selectedFeed']=$feed['id'];$result['shared']=false;$result['indexing']=false;
        return $result;
    }
}
