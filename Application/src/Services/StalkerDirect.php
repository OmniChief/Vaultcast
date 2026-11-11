<?php
declare(strict_types=1);

/**
 * Lightweight Stalker Movies/Series browse. Only request the selected provider page:
 * never build a second SQL catalogue just to show a category or a season.
 */
final class StalkerDirect {
    private static function key(array $feed,string $suffix): string {
        return 'stalker_direct_v2_'.hash('sha256',json_encode([$feed,$suffix]));
    }
    private static function snapshotKey(array $feed,string $suffix): string {
        return 'episodes:'.StalkerCatalogues::config($feed).':'.$suffix;
    }
    public static function item(array $feed,string $id): ?array {
        if(strpos($id,'series:')===0){
            $entry=\Vaultcast\Repository::stalkerSnapshot(self::snapshotKey($feed,'item:'.$id));
            if($entry&&self::savedEpisodeUsable($feed,$entry,self::seriesJob($feed)))return $entry;
        }
        return \Vaultcast\Repository::cacheGet(self::key($feed,'item:'.$id));
    }
    private static function seriesJob(array $feed): ?array {
        $query=\Vaultcast\Database::connection()->prepare(
            "SELECT generation,status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind='series' AND config=? AND status NOT IN ('removed','expired','scan_required')"
        );
        $query->execute([$feed['id'],StalkerCatalogues::config($feed)]);
        return $query->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private static function savedEpisodeUsable(array $feed,array $snapshot,?array $job): bool {
        if(!$job||!isset($snapshot['episodeGeneration']))return false;
        if($snapshot['episodeGeneration']===$job['generation'])return true;
        return isset($snapshot['root'])&&StalkerLibrary::seriesAvailable($feed,(string)$snapshot['root'],
            $job['status']==='complete'?$snapshot['episodeGeneration']:null);
    }
    /** Copy presentation-only metadata already supplied with a catalogue page. */
    public static function descriptiveFields(array $row): array {
        $text=['o_name','genres_str','genre','genres','director','actors','cast','country','language',
            'original_language','rating_imdb','rating_mpaa','rating_kinopoisk','age','added',
            'imdb_id','tmdb_id','kinopoisk_id','movie_year','release_date','premiered','duration',
            'category_id','category','genre_id','genre_id_1','genre_id_2','genre_id_3','genre_id_4',
            'cat_genre_id_1','cat_genre_id_2','cat_genre_id_3','cat_genre_id_4','rating','time',
            'hd','high_quality','low_quality','season','season_number','series_number',
            'episodes_count','seasons_count','year_end','audio_languages','subtitle_languages'];
        $out=[];
        foreach([$row,is_array($row['info']??null)?$row['info']:[],
                 is_array($row['movie_info']??null)?$row['movie_info']:[],
                 is_array($row['video_info']??null)?$row['video_info']:[]] as $section) {
            foreach($text as $field) {
                if(array_key_exists($field,$out)||!array_key_exists($field,$section))continue;
                $value=$section[$field];
                if(is_array($value)&&in_array($field,['actors','cast','director','genres','audio_languages','subtitle_languages'],true))
                    $value=implode(', ',StalkerProgramme::names($value));
                if(!is_scalar($value))continue;
                $value=trim(html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                if($value===''||preg_match('/^(?:n\/?a|none|null|unknown|undefined|not available)$/i',$value))continue;
                if(preg_match('~(?:https?://|[a-z0-9+.-]+://|(?:token|auth|password|cookie|session|mac)[=:])~i',$value))continue;
                // Provider sends 0 for missing genres; preserve quality flags and time as-is.
                if(preg_match('/^(?:genre_id(?:_[1-4])?|cat_genre_id_[1-4])$/',$field)&&$value==='0')continue;
                $out[$field]=mb_substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$value),0,in_array($field,['actors','cast','director'],true)?3000:300);
            }
        }
        return $out;
    }
    public static function importEpisodes(array $feed,string $parent,int $page,string $root): array {
        return self::episodePage($feed,$parent,$page,$root);
    }
    private static function episodePage(array $feed,string $parent,int $page,?string $root=null): array {
        $kind='series';
        $import=$root!==null;
        $job=self::seriesJob($feed);
        $generation=$job['generation']??null;
        $key=self::snapshotKey($feed,'page:'.json_encode([$parent,$page]));
        $cached=$generation===null?null:\Vaultcast\Repository::stalkerSnapshot($key);
        if($cached!==null&&($import?($cached['episodeGeneration']??null)===$generation:self::savedEpisodeUsable($feed,$cached,$job))){
            unset($cached['episodeGeneration'],$cached['pageSignature'],$cached['root']);
            return $cached;
        }
        if(!$import||$generation===null)return ['items'=>[],'total'=>0,'hasMore'=>false,'catalogueLoading'=>true];
        $params=['sortby'=>'name','category'=>'*','search'=>'','movie_id'=>$parent];
        $result=stalker_ordered_page($feed,$kind,$params,$page);
        $rows=StalkerCatalogueScan::page($result)['rows'];
        $signature=hash('sha256',json_encode(array_column($rows,'id')));
        if($rows&&$page>1){
            $previous=\Vaultcast\Repository::stalkerSnapshot(self::snapshotKey($feed,'page:'.json_encode([$parent,$page-1])));
            if(($previous['episodeGeneration']??null)===$generation&&($previous['pageSignature']??null)===$signature)
                throw new RuntimeException('Provider repeated an episode page.');
        }
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
                    $item['episode']=(string)$episode;
                    $item['episodeTitle']='';
                    $seasonRaw=$row['season_number']??$row['season']??null;
                    if(is_scalar($seasonRaw)&&preg_match('/^\\d+$/D',(string)$seasonRaw))$item['season']=(int)$seasonRaw;
                    $item['seasonName']=isset($item['season'])?'Season '.$item['season']:'Season unknown';
                    $item['seasonId']=$id;
                    self::rememberPlayback($feed,$publicId,$cmd,$episode,$item,$generation,$root);
                    $items[]=$item;
                }
                continue;
            }
            $playable=$cmd!==''&&empty($row['is_series'])&&empty($episodes)&&($kind==='vod'||$parent!=='');
            $publicId=$playable?$kind.':'.$id:$id;
            $item=array_merge($meta,['id'=>$publicId,'kind'=>$kind,'playable'=>$playable,'feed'=>$feed['id'],'connection'=>$feed['name']]);
            $item['hasChildren']=!$playable&&!empty($row['is_series']);
            // No extra provider calls: extract safe fields from existing page data.
            $item=array_merge($item,self::descriptiveFields($row));

            if($playable)self::rememberPlayback($feed,$publicId,$cmd,(string)($row['series_number']??''),$item,$generation,$root);
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
            : $received>0&&($reportedSize<=0||$received>=$reportedSize);
        $out=['items'=>$items,'total'=>$reportedTotal>0?$reportedTotal:null,'hasMore'=>$hasMore];
        \Vaultcast\Repository::saveStalkerSnapshot($key,$out+['episodeGeneration'=>$generation,'pageSignature'=>$signature,'root'=>$root]);
        return $out;
    }
    private static function rememberPlayback(array $feed,string $id,string $cmd,string $episode,array $item,string $generation,string $root): void {
        $entry=['cmd'=>$cmd,'series'=>$episode,'metadata'=>$item,'episodeGeneration'=>$generation,'root'=>$root];
        \Vaultcast\Repository::saveStalkerSnapshot(self::snapshotKey($feed,'item:'.$id),$entry);
    }
    /** Cache the provider's complete Live list once, never one SQL record per channel. */
    private static function liveRows(array $snapshot): array {
        $items=[];
        $genres=array_column($snapshot['categories'],'name','id');
        foreach($snapshot['channels'] as $row){
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
        return $items;
    }
    public static function liveEntry(array $feed,string $id): ?array {
        return StalkerLiveBrowse::entry($feed,$id);
    }
    public static function countries(array $state,array $opts): array {
        $country=strtoupper((string)($opts['country']??''));
        if($country!==''&&(!preg_match('/^[A-Z]{2}$/D',$country)||($country!=='ZZ'&&strpos(' '.StalkerCountries::CODES.' ',' '.$country.' ')===false)))throw new InvalidArgumentException('Choose a country.');
        $feeds=array_values(array_filter($state['feeds']??[],static function($f){return ($f['type']??'')==='stalker'&&!empty($f['enabled']);}));
        $selected=(string)($opts['group']??'');
        $query=mb_strtolower(mb_substr(trim((string)($opts['q']??'')),0,160));
        $offset=max(0,min(1000000,(int)($opts['offset']??0)));
        $counts=array_fill_keys(StalkerCountries::GROUPS,0);$items=[];$total=0;$progress=[];$indexing=false;
        foreach($feeds as $feed){
            $snapshot=StalkerLiveBrowse::snapshot($feed);
            if($snapshot===null){$progress[]=['name'=>$feed['name'],'scanned'=>0,'total'=>null,'done'=>false,'error'=>'No saved Live TV catalogue.'];continue;}
            $rows=self::liveRows($snapshot);
            $progress[]=['name'=>$feed['name'],'scanned'=>count($rows),'total'=>count($rows),'done'=>true,'error'=>null];
            foreach($rows as $row){
                if(($country!==''&&$row['country']!==$country)||($query!==''&&mb_strpos(mb_strtolower($row['name'].' '.$row['description']),$query)===false))continue;
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
    /** Single-item, opt-in metadata diagnostic. Never return provider transport or auth fields. */
    public static function probe(array $state,array $opts): array {
        $kind=(string)($opts['kind']??'');
        if(!in_array($kind,['vod','series'],true))throw new InvalidArgumentException('Select movies or series.');
        $wanted=(string)($opts['feed']??'');
        $id=(string)($opts['id']??'');
        // Only accept catalogue-issued IDs; do not allow arbitrary provider actions.
        if(!preg_match('/^(?:vod:)?[0-9]{1,12}$/D',$id)&&!preg_match('/^[0-9]{1,12}:[0-9]{1,12}$/D',$id))
            throw new InvalidArgumentException('Choose a valid catalogue item.');
        $feed=null;
        foreach(($state['feeds']??[]) as $candidate){
            if(($candidate['id']??'')===$wanted&&($candidate['type']??'')==='stalker'&&!empty($candidate['enabled'])){$feed=$candidate;break;}
        }
        if(!$feed)throw new InvalidArgumentException('Select an enabled Stalker connection.');
        $rawId=preg_replace('/^vod:/','',$id);
        if(strpos($rawId,':')!==false)$rawId=explode(':',$rawId,2)[0];
        // One provider operation per user-triggered probe, no playback or episode crawl.
        $result=stalker_call($feed,'get_info',['movie_id'=>$rawId],$kind);
        $row=is_array($result['data']??null)?$result['data']:$result;
        if(!is_array($row))return ['ok'=>true,'supported'=>false,'fields'=>[],'field_names'=>[]];
        $sourceSections=[$row];
        foreach(['info','movie_info','video_info'] as $field){
            if(is_array($row[$field]??null))$sourceSections[]=$row[$field];
        }
        $allow=['id','name','o_name','year','movie_year','release_date','premiered','category_id','category',
            'genre_id','genre_id_1','genre_id_2','genre_id_3','genre_id_4','genres_str','genre','genres',
            'director','actors','cast','description','descr','plot','summary','overview','time','duration',
            'country','language','original_language','rating_imdb','rating_mpaa','rating','age','hd',
            'high_quality','added','tmdb_id','imdb_id','kinopoisk_id','series_number','season','season_number',
            'episode','episode_number','episodes_count','seasons_count','is_series','audio_languages',
            'subtitle_languages','audio_tracks','subtitles'];
        $fields=[];$names=[];
        foreach($sourceSections as $section){
            foreach($section as $key=>$value){
                if(!is_string($key))continue;
                // Provider field names can reveal new possibilities; names alone never include secrets.
                if(preg_match('/^[a-z][a-z0-9_]{0,49}$/i',$key)&&!preg_match('/(?:token|auth|pass|secret|cookie|key|mac|serial|device|url|link|cmd|stream|ticket)/i',$key))$names[$key]=true;
                if(!in_array($key,$allow,true)||isset($fields[$key]))continue;
                if(is_bool($value)||is_int($value)||is_float($value))$value=(string)$value;
                if(!is_string($value)||trim($value)==='')continue;
                $value=mb_substr(trim(html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8')),0,$key==='description'||$key==='plot'||$key==='overview'?1200:250);
                if(preg_match('~(?:https?://|[a-z0-9]{2,}://|(?:token|auth|password|session|cookie)[=:])~i',$value))continue;
                $fields[$key]=$value;
            }
        }
        return ['ok'=>true,'supported'=>!empty($fields),'fields'=>$fields,'field_names'=>array_keys($names)];
    }

    /**
     * Admin-only catalogue field inventory from one ordinary provider page.
     * The raw page is inspected in memory; no transport, session or playback data escapes.
     */
    public static function catalogueFieldProbe(array $state,array $opts): array {
        $kind=(string)($opts['kind']??'vod');
        if(!in_array($kind,['vod','series'],true))throw new InvalidArgumentException('Choose movies or series.');
        $wanted=(string)($opts['feed']??'');
        $category=(string)($opts['category']??'');
        if($category!==''&&!preg_match('/^(?:[a-zA-Z0-9_-]{1,120}|\*)$/D',$category))
            throw new InvalidArgumentException('Choose a valid provider category.');
        $feed=null;
        foreach(($state['feeds']??[]) as $candidate) {
            if(($candidate['id']??'')===$wanted&&($candidate['type']??'')==='stalker'&&!empty($candidate['enabled'])){$feed=$candidate;break;}
        }
        if(!$feed)throw new InvalidArgumentException('Choose an enabled Stalker connection.');
        $pageNumber=max(1,min(10000,(int)($opts['page']??1)));
        $groups=StalkerCatalogueScan::groups(stalker_call($feed,'get_categories',[],$kind));
        // The relocated diagnostic has no provider folder selected. Prefer a real
        // folder instead of silently sampling an empty or truncated wildcard listing.
        if($category==='')foreach($groups as $id=>$name)if((string)$id!=='*'){$category=(string)$id;break;}
        $result=stalker_ordered_page($feed,$kind,['sortby'=>'name','category'=>$category?:'*','search'=>''],$pageNumber);
        $parsed=StalkerCatalogueScan::page($result);$rows=$parsed['rows'];
        $count=0;$inventory=[];
        $safeExamples=['id','imdb','tmdb','name','o_name','year','movie_year','release_date','premiered','category_id','genre_id',
            'genre','genres','genres_str','director','actors','cast','description','descr','plot','summary','overview',
            'time','duration','country','language','original_language','rating_imdb','rating_mpaa','rating',
            'age','hd','high_quality','added','tmdb_id','imdb_id','kinopoisk_id','season','season_number',
            'series_number','episode','episode_number','episodes_count','seasons_count','audio_languages',
            'subtitle_languages'];
        $inspect=function(array $section,string $prefix='') use (&$inspect,&$inventory,$safeExamples,$feed): void {
            foreach($section as $key=>$value){
                if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,49}$/i',$key))continue;
                if(preg_match('/(?:token|auth|pass|secret|cookie|key|mac|serial|device|url|link|cmd|stream|ticket|host|ip|login|account|user|pin|drm|license|proxy|path|file|play)/i',$key))continue;
                $path=$prefix.$key;
                $type=is_array($value)?'array':(is_object($value)?'object':gettype($value));
                if(!isset($inventory[$path]))$inventory[$path]=['type'=>$type,'present'=>0,'populated'=>0];
                $inventory[$path]['present']++;
                if($value!==null&&$value!==''&&$value!==[])$inventory[$path]['populated']++;
                if(in_array($key,$safeExamples,true)&&!is_array($value)&&is_scalar($value)&&!isset($inventory[$path]['example'])){
                    $sample=trim(html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                    foreach(['mac','username','password','serial','deviceId','deviceId2','url'] as $secret)if((string)($feed[$secret]??'')!==''&&stripos($sample,(string)$feed[$secret])!==false)$sample='';
                    if(preg_match('/(?:[0-9a-f]{2}:){5}[0-9a-f]{2}/i',$sample))$sample='';
                    if($sample!==''&&!preg_match('~(?:https?://|[a-z][a-z0-9+.-]*://|(?:token|auth|pass|session|cookie)[=:]|[?&][a-z_]+=)~i',$sample)){
                        $inventory[$path]['example']=mb_substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$sample),0,120);
                    }
                }
                if($prefix===''&&in_array($key,['info','movie_info','video_info'],true)&&is_array($value)){
                    $inspect($value,$key.'.');
                }
            }
        };
        foreach($rows as $row) {
            if(!is_array($row))continue;
            $inspect($row);
            if(++$count>=15)break;
        }
        ksort($inventory);
        return ['ok'=>true,'kind'=>$kind,'connection'=>$feed['name'],'category'=>$category?:'*',
            'rows_inspected'=>$count,'fields'=>$inventory,'complete'=>false,'sampleComplete'=>true,
            'page'=>$pageNumber,'rows_returned'=>count($rows),'total_items'=>$parsed['total'],
            'folder_ids'=>array_map('strval',array_keys($groups)),
            'response_fields'=>array_values(array_intersect(['data','total_items','max_page_items','total_pages','cur_page','page'],array_keys($result))),
            'emptyConfirmed'=>!$rows&&$parsed['total']===0,
            'note'=>'One page sample, not a complete catalogue. Empty content without an explicit zero total is unverified; sensitive fields omitted.'];
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
        if(!empty($opts['raw_categories_export'])){
            // Explicit diagnostic only: use the provider response, not the simplified category cache.
            // Allowlist descriptive and artwork fields; never return arbitrary provider fields,
            // portal credentials, cookies, authorization headers or query tokens.
            $result=stalker_call($feed,'get_categories',[],$kind);
            StalkerCatalogueScan::groups($result);
            $rows=$result['data']??$result;
            $fields=['id','title','name','description','logo','icon','image','poster','cover','pic','screenshot_uri','movie_image','thumbnail','thumb','image_url','poster_url','cover_url'];
            $out=[];
            foreach($rows as $row){
                if(!is_array($row))continue;
                $safe=[];
                foreach($fields as $field){
                    if(!isset($row[$field])||!is_scalar($row[$field]))continue;
                    $value=mb_substr((string)$row[$field],0,1000);
                    if(preg_match('~(?:^|[/?&=])(?:token|auth|key|pass|password|session|mac|cookie|signature|access_token)[=/&]~i',$value))$value='[redacted]';
                    // For URL-like fields retain field presence and artwork path, but strip
                    // authority and query strings to prevent exporting portal access details.
                    if(in_array($field,['logo','icon','image','poster','cover','pic','screenshot_uri','movie_image','thumbnail','thumb','image_url','poster_url','cover_url'],true)){
                        $parts=parse_url($value);
                        if(is_array($parts)&&!empty($parts['host']))$value=(string)($parts['path']??'').' [host/query omitted]';
                        else $value=explode('?', $value, 2)[0];
                    }
                    $safe[$field]=$value;
                }
                if($safe)$out[]=$safe;
            }
            return ['ok'=>true,'kind'=>$kind,'selectedFeed'=>$feed['id'],'categories'=>$out,'count'=>count($out)];
        }
        if(StalkerCatalogues::expired($feed))return ['ok'=>true,'items'=>[],'total'=>0,'hasMore'=>false,'categories'=>[],'catalogueLoading'=>false];
        // Viewer browsing only reads the existing import.
        $categories=$parent===''?StalkerLibrary::providerFolders($feed,$kind):[];
        if(!empty($opts['categories_only']))return ['ok'=>true,'items'=>[],'total'=>null,'hasMore'=>false,'categories'=>$categories,'selectedFeed'=>$feed['id'],'shared'=>false];
        $result=$parent===''?StalkerLibrary::providerPage($feed,$kind,$category,$query,$page):($kind==='series'?self::episodePage($feed,$parent,$page):['items'=>[],'total'=>0,'hasMore'=>false]);
        foreach($result['items'] as &$item)$item['sources']=[array_intersect_key($item,array_flip(['id','feed','connection','kind','playable']))];
        unset($item);
        $result['ok']=true;$result['facets']=['category'=>array_map(static function($c){return ['value'=>$c['id'],'name'=>$c['name']];},$categories)];
        $result['categories']=$categories;$result['page']=$page;
        $result['expandedEpisodes']=$parent!=='';$result['catalogueLoading']=$result['catalogueLoading']??false;
        if($parent!==''){$parentItem=self::item($feed,$parent);$result['show']=$parentItem['metadata']??null;}
        $result['connections']=[['name'=>$feed['name'],'scanned'=>count($result['items']),'total'=>$result['total'],'done'=>true,'error'=>'']];
        $result['selectedFeed']=$feed['id'];$result['shared']=false;$result['indexing']=false;
        return $result;
    }
}
