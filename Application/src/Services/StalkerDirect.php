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
    /** Copy presentation-only metadata already supplied with a catalogue page. */
    private static function descriptiveFields(array $row): array {
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
                if(!is_scalar($value))continue;
                $value=trim(html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                if($value===''||preg_match('/^(?:n\/?a|none|null|unknown|undefined|not available)$/i',$value))continue;
                if(preg_match('~(?:https?://|[a-z0-9+.-]+://|(?:token|auth|password|cookie|session|mac)[=:])~i',$value))continue;
                // Provider sends 0 for missing genres; preserve quality flags and time as-is.
                if(preg_match('/^(?:genre_id(?:_[1-4])?|cat_genre_id_[1-4])$/',$field)&&$value==='0')continue;
                $out[$field]=mb_substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$value),0,300);
            }
        }
        return $out;
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
                    $item['episode']=(string)$episode;
                    $item['episodeTitle']='';
                    $seasonRaw=$row['season_number']??$row['season']??null;
                    if(is_scalar($seasonRaw)&&preg_match('/^\\d+$/D',(string)$seasonRaw))$item['season']=(int)$seasonRaw;
                    $item['seasonName']=isset($item['season'])?'Season '.$item['season']:'Season unknown';
                    $item['seasonId']=$id;
                    \Vaultcast\Repository::cachePut(self::key($feed,'item:'.$publicId),['cmd'=>$cmd,'series'=>$episode,'metadata'=>$item],7200);
                    $items[]=$item;
                }
                continue;
            }
            $playable=$cmd!==''&&empty($row['is_series'])&&empty($episodes)&&($kind==='vod'||$parent!=='');
            $publicId=$playable?$kind.':'.$id:$id;
            $item=array_merge($meta,['id'=>$publicId,'kind'=>$kind,'playable'=>$playable,'feed'=>$feed['id'],'connection'=>$feed['name']]);
            // No extra provider calls: extract safe fields from existing page data.
            $item=array_merge($item,self::descriptiveFields($row));

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
        if($category!==''&&!preg_match('/^[0-9]{1,12}$/D',$category))
            throw new InvalidArgumentException('Choose a valid provider category.');
        $feed=null;
        foreach(($state['feeds']??[]) as $candidate) {
            if(($candidate['id']??'')===$wanted&&($candidate['type']??'')==='stalker'&&!empty($candidate['enabled'])){$feed=$candidate;break;}
        }
        if(!$feed)throw new InvalidArgumentException('Choose an enabled Stalker connection.');
        // Never inspect more than one normal catalogue page, no get_info or playback.
        $result=stalker_ordered_page($feed,$kind,['sortby'=>'name','category'=>$category?:'*','search'=>''],1);
        $rows=$result['data']??$result;
        if(!is_array($rows))throw new RuntimeException('Invalid provider catalogue page.');
        $count=0;$inventory=[];
        $safeExamples=['name','o_name','year','movie_year','release_date','premiered','category_id','genre_id',
            'genre','genres','genres_str','director','actors','cast','description','descr','plot','summary','overview',
            'time','duration','country','language','original_language','rating_imdb','rating_mpaa','rating',
            'age','hd','high_quality','added','tmdb_id','imdb_id','kinopoisk_id','season','season_number',
            'series_number','episode','episode_number','episodes_count','seasons_count','audio_languages',
            'subtitle_languages'];
        $inspect=function(array $section,string $prefix='') use (&$inspect,&$inventory,$safeExamples): void {
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
            'rows_inspected'=>$count,'fields'=>$inventory,'note'=>'Inventory of up to 15 entries from one raw catalogue page; sensitive fields omitted.'];
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
        if(!empty($opts['raw_categories_export'])){
            // Explicit diagnostic only: use the provider response, not the simplified category cache.
            // Allowlist descriptive and artwork fields; never return arbitrary provider fields,
            // portal credentials, cookies, authorization headers or query tokens.
            $result=stalker_call($feed,'get_categories',[],$kind);
            $rows=$result['data']??$result;
            if(!is_array($rows))throw new RuntimeException('Provider returned invalid category data.');
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
                if(count($out)>=500)break;
            }
            return ['ok'=>true,'kind'=>$kind,'selectedFeed'=>$feed['id'],'categories'=>$out,'count'=>count($out)];
        }
        // Browse and playback do not require an administrator to have indexed anything.
        $categories=$parent===''?self::categories($feed,$kind):[];
        if(!empty($opts['categories_only']))return ['ok'=>true,'items'=>[],'total'=>null,'hasMore'=>false,'categories'=>$categories,'selectedFeed'=>$feed['id'],'shared'=>false];
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
