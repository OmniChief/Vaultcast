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
        try {
            $result=stalker_call($feed,'get_categories',[],$kind);
            foreach(($result['data']??$result) as $row)if(is_array($row)&&isset($row['id'])){
            $categories[]=['id'=>(string)$row['id'],'name'=>(string)($row['title']??$row['name']??$row['id'])];
            if(count($categories)>=500)break;
            }
        } catch (RuntimeException $ignored) {
            // Categories are optional; a provider may allow page browsing without them.
        }
        \Vaultcast\Repository::cachePut($key,$categories,$categories?3600:300);
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
        $reportedTotal=(int)($result['total_items']??0);
        $reportedSize=(int)($result['max_page_items']??0);
        $received=count($rows);
        // Many Stalker portals omit or misreport totals. Don't treat a full
        // provider page as the end merely because total_items is absent.
        $perPage=$reportedSize>0?$reportedSize:max(1,$received);
        $hasMore=$reportedTotal>0
            ? $page*$perPage<$reportedTotal
            : $received>0&&$reportedSize>0&&$received>=$reportedSize;
        $out=['items'=>$items,'total'=>$reportedTotal>0?$reportedTotal:null,'hasMore'=>$hasMore];
        \Vaultcast\Repository::cachePut($key,$out,600);
        return $out;
    }
    /** Cache the provider's complete Live list once, never one SQL record per channel. */
    private static function liveRows(array $feed): array {
        $key=self::key($feed,'live:all');
        $cached=\Vaultcast\Repository::cacheGet($key);
        if($cached!==null)return $cached;
        $result=stalker_call($feed,'get_all_channels');
        $rows=$result['data']??$result;
        if(!is_array($rows))throw new RuntimeException('Provider returned an invalid live-channel list.');
        $total=(int)($result['total_items']??0);
        if($total>count($rows))throw new RuntimeException('Provider returned only part of its channel list; complete country browsing is unavailable.');
        if(count($rows)>50000)throw new RuntimeException('Provider channel list exceeds the safe NAS limit.');
        $items=[];
        $genres=stalker_live_groups($feed);
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||empty($row['cmd']))continue;
            $id=(string)$row['id'];if($id===''||strlen($id)>120)continue;
            $name=mb_substr((string)($row['name']??$id),0,300);
            $genreId=(string)($row['tv_genre_id']??'');
            $groupName=mb_substr((string)($row['group_title']??$genres[$genreId]??''),0,180);
            $items[$id]=['id'=>$id,'name'=>$name,'description'=>$groupName,'kind'=>'live',
                'country'=>StalkerCountries::country($name)??StalkerCountries::country($groupName)??'ZZ',
                'group'=>StalkerCountries::group($name,$groupName),
                'playback'=>stalker_live_entry($row)];
        }
        // A shorter TTL bounds memory and disk without reimporting entire SQL tables.
        \Vaultcast\Repository::cachePut($key,$items,1800);
        return $items;
    }
    public static function liveEntry(array $feed,string $id): ?array {
        $cached=\Vaultcast\Repository::cacheGet(self::key($feed,'live:all'));
        return $cached[$id]??null;
    }
    public static function countries(array $state,array $opts): array {
        $country=strtoupper((string)($opts['country']??''));
        if(!preg_match('/^[A-Z]{2}$/D',$country)||($country!=='ZZ'&&strpos(' '.StalkerCountries::CODES.' ',' '.$country.' ')===false))throw new InvalidArgumentException('Choose a country.');
        $feeds=array_values(array_filter($state['feeds']??[],static function($f){return ($f['type']??'')==='stalker'&&!empty($f['enabled']);}));
        $selected=(string)($opts['group']??'');
        $query=mb_strtolower(mb_substr(trim((string)($opts['q']??'')),0,160));
        $offset=max(0,min(1000000,(int)($opts['offset']??0)));
        $counts=array_fill_keys(StalkerCountries::GROUPS,0);$items=[];$total=0;$progress=[];$indexing=false;
        // Only contact one uncached provider per request to protect a six-worker NAS.
        $requested=false;
        foreach($feeds as $feed){
            $key=self::key($feed,'live:all');
            $rows=\Vaultcast\Repository::cacheGet($key);
            if($rows===null&&!$requested){
                $requested=true;
                try{$rows=self::liveRows($feed);}
                catch(RuntimeException $e){$progress[]=['name'=>$feed['name'],'scanned'=>0,'total'=>null,'done'=>false,'error'=>'Provider could not supply a complete channel list.'];continue;}
            }
            if($rows===null){$indexing=true;$progress[]=['name'=>$feed['name'],'scanned'=>0,'total'=>null,'done'=>false,'error'=>null];continue;}
            $progress[]=['name'=>$feed['name'],'scanned'=>count($rows),'total'=>count($rows),'done'=>true,'error'=>null];
            foreach($rows as $row){
                if($row['country']!==$country||($query!==''&&mb_strpos(mb_strtolower($row['name'].' '.$row['description']),$query)===false))continue;
                $counts[$row['group']]++;
                if($selected!==''&&$row['group']!==$selected)continue;
                if($total>=$offset&&count($items)<120){
                    $item=$row;unset($item['playback']);
                    $item['feed']=$feed['id'];$item['connection']=$feed['name'];$items[]=$item;
                }
                $total++;
            }
        }
        return ['ok'=>true,'items'=>$items,'groups'=>$counts,'total'=>$total,'hasMore'=>$offset+count($items)<$total,
            'indexing'=>$indexing,'connections'=>$progress,'shared'=>false];
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
        $result['expandedEpisodes']=$parent!=='';$result['catalogueLoading']=false;
        if($parent!==''){$parentItem=self::item($feed,$parent);$result['show']=$parentItem['metadata']??null;}
        $result['connections']=[['name'=>$feed['name'],'scanned'=>count($result['items']),'total'=>$result['total'],'done'=>true,'error'=>'']];
        $result['selectedFeed']=$feed['id'];$result['shared']=false;$result['indexing']=false;
        return $result;
    }
}
