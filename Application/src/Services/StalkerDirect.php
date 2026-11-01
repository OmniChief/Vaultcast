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
        $total=max(0,(int)($result['total_items']??count($items)));
        $perPage=max(1,(int)($result['max_page_items']??count($rows)));
        $out=['items'=>$items,'total'=>$total,'hasMore'=>$page*$perPage<$total];
        \Vaultcast\Repository::cachePut($key,$out,600);
        return $out;
    }
    /**
     * One ordered provider page per CLI invocation. Browsers only read the
     * file-backed snapshot, so slow portals never occupy an HTTP PHP worker.
     */
    private static function liveState(array $feed): array {
        return \Vaultcast\Repository::cacheGet(self::key($feed,'live:pages:v2'))
            ?? ['rows'=>[],'next'=>1,'processed'=>0,'total'=>null,'done'=>false,'signatures'=>[],'error'=>null,'retryAfter'=>0];
    }
    private static function liveStore(array $feed,array $state): void {
        \Vaultcast\Repository::cachePut(self::key($feed,'live:pages:v2'),$state,86400);
    }
    public static function liveEntry(array $feed,string $id): ?array {
        $legacy=\Vaultcast\Repository::cacheGet(self::key($feed,'live:all'));
        if(isset($legacy[$id]))return $legacy[$id];
        $state=self::liveState($feed);
        return $state['rows'][$id]??null;
    }
    public static function advanceLive(string $id): array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Provider indexing must run in PHP CLI.');
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $feed=iptv_feed_by_id(iptv_load(),$id);
        if(!$feed||($feed['type']??'')!=='stalker'||empty($feed['enabled']))return ['ok'=>false,'error'=>'Connection unavailable'];
        $dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);
        // One active portal request across all country connections on the NAS.
        $lock=@fopen($dir.'/stalker-direct-live.worker.lock','c');
        if(!$lock)return ['ok'=>false,'error'=>'Worker lock unavailable'];
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['ok'=>true,'busy'=>true];}
        try {
            $state=self::liveState($feed);
            if(!empty($state['done'])||($state['retryAfter']??0)>time())return ['ok'=>true,'skipped'=>true];
            if(($state['next']??1)>10000)throw new RuntimeException('Provider exceeded page limit.');
            $page=max(1,(int)$state['next']);
            // Do not attempt get_all_channels: this portal may return an incomplete
            // list. Ask for only the next ordered channel page.
            $result=stalker_ordered_page($feed,'itv',['sortby'=>'number','genre'=>'*','search'=>'','fav'=>0],$page);
            $rows=$result['data']??$result;
            if(!is_array($rows))throw new RuntimeException('Provider returned an invalid live page.');
            $signature=hash('sha256',json_encode(array_column($rows,'id')));
            if($rows&&isset($state['signatures'][$signature]))throw new RuntimeException('Provider repeated a channel page.');
            if($rows)$state['signatures'][$signature]=true;
            $genres=stalker_live_groups($feed);
            foreach($rows as $row){
                if(!is_array($row)||!isset($row['id'])||empty($row['cmd']))continue;
                $channelId=(string)$row['id'];
                if($channelId===''||strlen($channelId)>120)continue;
                $name=mb_substr((string)($row['name']??$channelId),0,300);
                $genreId=(string)($row['tv_genre_id']??'');
                $groupName=mb_substr((string)($row['group_title']??$genres[$genreId]??''),0,180);
                $state['rows'][$channelId]=['id'=>$channelId,'name'=>$name,'description'=>$groupName,'kind'=>'live',
                    'country'=>StalkerCountries::country($name)??StalkerCountries::country($groupName)??'ZZ',
                    'group'=>StalkerCountries::group($name,$groupName),'playback'=>stalker_live_entry($row)];
            }
            $state['processed']+=count($rows);
            $state['next']=$page+1;
            if(isset($result['total_items'])&&is_numeric($result['total_items'])&&(int)$result['total_items']>0)$state['total']=(int)$result['total_items'];
            if(count($state['rows'])>50000)throw new RuntimeException('Provider channel list exceeded safe storage limit.');
            $state['done']=!$rows||($state['total']!==null&&$state['processed']>=$state['total']);
            $state['error']=null;$state['retryAfter']=0;
            self::liveStore($feed,$state);
            return ['ok'=>true,'done'=>$state['done'],'scanned'=>count($state['rows'])];
        }catch(RuntimeException $error){
            $state=self::liveState($feed);
            $state['error']='Could not load the next provider channel page. Check the connection and retry.';
            $state['retryAfter']=time()+120;
            self::liveStore($feed,$state);
            return ['ok'=>false,'error'=>$state['error']];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    /** Advance a short batch when the portal responds quickly; release after a time budget. */
    public static function runLive(string $feedId): array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Provider indexing must run in PHP CLI.');
        $started=microtime(true);$result=['ok'=>true];
        for($page=0;$page<6&&microtime(true)-$started<18;$page++){
            $result=self::advanceLive($feedId);
            if(empty($result['ok'])||!empty($result['done'])||!empty($result['busy'])||!empty($result['skipped']))break;
        }
        return $result;
    }
    private static function launchLive(array $feed): void {
        $dir=STREAM_DATA.'/runtime';if(!is_dir($dir))@mkdir($dir,0700,true);
        $lock=@fopen($dir.'/stalker-direct-'.hash('sha256',self::key($feed,'live:pages:v2')).'.launch','c+');
        if(!$lock)return;
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return;}
        // Do not start another PHP process while the current batch owns the worker lock.
        $worker=@fopen($dir.'/stalker-direct-live.worker.lock','c');
        if(!$worker||!flock($worker,LOCK_EX|LOCK_NB)){
            if($worker)fclose($worker);
            flock($lock,LOCK_UN);fclose($lock);return;
        }
        flock($worker,LOCK_UN);fclose($worker);
        try {
            $last=(int)stream_get_contents($lock);
            if($last>time()-5)return;
            ftruncate($lock,0);rewind($lock);fwrite($lock,(string)time());fflush($lock);
            try{Process::startPhpWorker('stalker-direct-live',(string)$feed['id']);}
            catch(Throwable $e){
                ftruncate($lock,0);
                $state=self::liveState($feed);
                $state['error']='Could not start the PHP CLI channel worker.';
                $state['retryAfter']=time()+60;
                self::liveStore($feed,$state);
            }
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function countries(array $state,array $opts): array {
        $country=strtoupper((string)($opts['country']??''));
        if(!preg_match('/^[A-Z]{2}$/D',$country)||($country!=='ZZ'&&strpos(' '.StalkerCountries::CODES.' ',' '.$country.' ')===false))throw new InvalidArgumentException('Choose a country.');
        $feeds=array_values(array_filter($state['feeds']??[],static function($f){return ($f['type']??'')==='stalker'&&!empty($f['enabled']);}));
        $selected=(string)($opts['group']??'');
        $query=mb_strtolower(mb_substr(trim((string)($opts['q']??'')),0,160));
        $offset=max(0,min(1000000,(int)($opts['offset']??0)));
        $counts=array_fill_keys(StalkerCountries::GROUPS,0);$items=[];$total=0;$progress=[];$indexing=false;
        $launched=false;
        foreach($feeds as $feed){
            $legacy=\Vaultcast\Repository::cacheGet(self::key($feed,'live:all'));
            $scan=self::liveState($feed);
            $rows=$legacy!==null?$legacy:$scan['rows'];
            $done=$legacy!==null||!empty($scan['done']);
            if(!$done&&!$launched&&($scan['retryAfter']??0)<=time()){
                self::launchLive($feed);$launched=true;
            }
            $indexing=$indexing||(!$done&&empty($scan['error']));
            $progress[]=['name'=>$feed['name'],'scanned'=>count($rows),'total'=>$legacy!==null?count($legacy):$scan['total'],
                'done'=>$done,'error'=>$legacy!==null?null:($scan['error']??null)];
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
