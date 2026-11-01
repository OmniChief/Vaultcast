<?php
declare(strict_types=1);

/** Incremental, queryable provider index. One provider page per user-requested step. */
final class StalkerVod {
    public static function fingerprint(array $feed,string $kind): string {return StalkerCatalogues::active($feed,$kind);}
    private static function pdo(): PDO {return \Vaultcast\Database::connection();}
    public static function scan(string $fingerprint,string $kind,string $parent=''): array {
        $q=self::pdo()->prepare('SELECT * FROM vaultcast_stalker_scans WHERE fingerprint=? AND kind=? AND parent_id=?');$q->execute([$fingerprint,$kind,$parent]);
        return $q->fetch()?:['next_page'=>1,'processed'=>0,'total'=>null,'done'=>0,'error'=>'','last_hash'=>'','depth'=>0];
    }
    private static function text($value): string {return is_scalar($value)?trim((string)$value):'';}
    private static function classify(array $row,string $category): array {
        $name=self::text($row['name']??'');if($name==='')$name=self::text($row['o_name']??'')?:self::text($row['id']??'Untitled');$year=null;
        if(preg_match('/(?:^|[\[(])((?:19|20)\d{2})(?:$|[\])])/',$name,$m))$year=(int)$m[1];
        foreach(['year','movie_year','release_date','premiered'] as $field)if(preg_match('/^((?:19|20)\d{2})(?:$|[-\/])/',self::text($row[$field]??''),$date)){$year=(int)$date[1];break;}
        $quality='Unknown';$hint=strtoupper(strtr(self::text($row['resolution']??$row['quality']??'').' '.$name.' '.$category,['⁴'=>'4','ᴷ'=>'K','³'=>'3','⁸'=>'8','⁰'=>'0','ᴾ'=>'P']));
        foreach(['4K'=>'4K|UHD|2160|3840','1080p'=>'1080|FHD|FULL HD','720p'=>'720','SD'=>'\\bSD\\b|480P|576P'] as $label=>$pattern)if(preg_match('/'.$pattern.'/i',$hint)){$quality=$label;break;}
        if($quality==='Unknown'&&!empty($row['hd']))$quality='HD';
        $language=self::text($row['language']??$row['audio_language']??'');
        if($language===''&&preg_match('/^\s*(EN|GR|EL|FR|DE|ES|IT|JP|JA|PT|TR|AR|HI|NL|RU)\s*[-|:]/i',$category,$m))$language=strtoupper($m[1]);
        $languages=['en'=>'English','eng'=>'English','gr'=>'Greek','el'=>'Greek','fr'=>'French','de'=>'German','es'=>'Spanish','it'=>'Italian','jp'=>'Japanese','ja'=>'Japanese','pt'=>'Portuguese','tr'=>'Turkish','ar'=>'Arabic','hi'=>'Hindi','nl'=>'Dutch','ru'=>'Russian'];
        $language=$languages[strtolower($language)]??($language!==''?mb_substr($language,0,80):'Unknown');
        $genreText=$category;$raw=$row['genres']??$row['genres_str']??$row['genre_name']??$row['genre']??'';
        if(is_array($raw)){foreach($raw as $genre)$genreText.=' '.(is_array($genre)?self::text($genre['name']??$genre['title']??''):self::text($genre));}else $genreText.=' '.self::text($raw);
        $genres=[];foreach(['Action'=>'action','Adventure'=>'adventure','Animation'=>'anim(?:ation|e|i)','Comedy'=>'comedy|stand.?up','Crime'=>'crime','Documentary'=>'docu(?:mentary|mentaries|.?movies)?','Drama'=>'drama','Family'=>'family|kids|children','Fantasy'=>'fantasy','History'=>'history|historical','Horror'=>'horror','Music'=>'music|concert|karaoke','Mystery'=>'mystery','Romance'=>'romance|romantic','Science Fiction'=>'sci.?fi|science fiction','Thriller'=>'thriller','War'=>'\\bwar\\b','Western'=>'western','Sport'=>'sports?|formula.?1|football'] as $genre=>$pattern)if(preg_match('/\b(?:'.$pattern.')\b/iu',$genreText))$genres[]=$genre;
        if(!$genres)$genres=['Unknown'];
        $rating=null;foreach(['rating_imdb','imdb_rating','rating'] as $field)if(isset($row[$field])&&is_numeric($row[$field])&&(float)$row[$field]>=0&&(float)$row[$field]<=10){$rating=(float)$row[$field];break;}
        $clean=preg_replace('/^\s*(?:D\+|NF|NETFLIX|AMZ|AMAZON|ATV\+|HBO|MAX|EN|GR|UK|US|FR|DE|ES|IT|JP|JA|PT|TR|AR|HI|NL|RU)\s*[-|:]\s*/i','',$name);
        $clean=preg_replace('/\s*[\[(](?:(?:19|20)\d{2}|[A-Z]{2,3}|HD|FHD|UHD|4K)[\])]\s*/i',' ',$clean);
        $identity=mb_strtolower(trim(preg_replace('/\s+/u',' ',$clean)));
        return ['name'=>mb_substr($name,0,500),'year'=>$year,'language'=>$language,'quality'=>$quality,'genres'=>$genres,'rating'=>$rating,'group_key'=>hash('sha256',json_encode([$identity,$year,$quality]))];
    }
    /** Called only by the authenticated admin's background catalogue job. */
    public static function step(array $feed,string $kind,string $fingerprint): array {
        $pdo=self::pdo();$q=$pdo->prepare("SELECT parent_id FROM vaultcast_stalker_scans WHERE fingerprint=? AND kind=? AND done=0 AND error='' ORDER BY depth,parent_id LIMIT 1");$q->execute([$fingerprint,$kind]);$parent=$q->fetchColumn();
        if($parent===false){$root=self::scan($fingerprint,$kind);if($root['done']||$root['error'])return self::summary($fingerprint,$kind);$parent='';}
        $scan=self::scan($fingerprint,$kind,$parent);$before=$scan;
        try {
            if((int)$scan['next_page']>10000)throw new RuntimeException('Provider catalogue page limit reached.');
            $groupsKey='stalker_vod_groups_'.hash('sha256',StalkerCatalogues::config($feed).$kind);$groups=($parent===''&&(int)$scan['next_page']===1)?null:\Vaultcast\Repository::cacheGet($groupsKey);
            if($groups===null){$groups=[];try{$result=stalker_call($feed,$kind==='live'?'get_genres':'get_categories',[],$kind==='live'?'itv':$kind);foreach($result['data']??$result as $group)if(is_array($group)&&isset($group['id']))$groups[(string)$group['id']]=(string)($group['title']??$group['name']??'Unknown');}catch(RuntimeException $ignored){}\Vaultcast\Repository::cachePut($groupsKey,$groups,86400);}
            $bulk=false;$result=null;
            if($kind==='live'&&(int)$scan['next_page']===1){try{$result=stalker_call($feed,'get_all_channels');if(!isset($result['data'])&&isset($result[0]))$result=['data'=>$result];if(!is_array($result['data']??null)||!$result['data']||(int)($result['total_items']??0)>count($result['data']))$result=null;else $bulk=true;}catch(RuntimeException $ignored){}}
            if($result===null){$params=$kind==='live'?['sortby'=>'number','genre'=>'*','search'=>'','fav'=>0]:['sortby'=>'name','category'=>'*','search'=>''];if($parent!=='')$params['movie_id']=$parent;$result=stalker_ordered_page($feed,$kind==='live'?'itv':$kind,$params,(int)$scan['next_page']);}
            $rows=$result['data']??null;if(!is_array($rows))throw new RuntimeException('Provider returned an invalid catalogue page.');
            $signature=hash('sha256',json_encode(array_column($rows,'id')));if($rows&&$signature===$scan['last_hash'])throw new RuntimeException('Provider repeated a catalogue page.');
            $insert=$pdo->prepare('INSERT INTO vaultcast_stalker_titles(fingerprint,kind,parent_id,item_id,group_key,title,year,genres,language,quality,category,rating,playable,payload) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE group_key=VALUES(group_key),title=VALUES(title),year=VALUES(year),genres=VALUES(genres),language=VALUES(language),quality=VALUES(quality),category=VALUES(category),rating=VALUES(rating),playable=VALUES(playable),payload=VALUES(payload)');
            $enqueue=$pdo->prepare('INSERT IGNORE INTO vaultcast_stalker_scans(fingerprint,kind,parent_id,depth) VALUES(?,?,?,?)');
            $ids=[];foreach($rows as $row)if(is_array($row)&&isset($row['id'])&&is_scalar($row['id']))$ids[]=(string)$row['id'];$ids=array_values(array_unique($ids));
            if($ids&&(int)$scan['next_page']>1){$seen=$pdo->prepare('SELECT COUNT(*) FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind=? AND parent_id=? AND item_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$seen->execute(array_merge([$fingerprint,$kind,$parent],$ids));if((int)$seen->fetchColumn()===count($ids))throw new RuntimeException('Provider repeated previously indexed titles.');}
            $pdo->beginTransaction();
            foreach($rows as $row){
                if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id']))continue;$id=(string)$row['id'];if($id===''||strlen($id)>120)continue;
                $category=self::text($row['category_name']??$row['category_title']??'');if($category==='')$category=$groups[self::text($row[$kind==='live'?'tv_genre_id':'category_id']??$row['category']??'')]??'Unknown';$category=mb_substr($category,0,190);
                $row=array_intersect_key($row,array_flip(['id','name','o_name','cmd','is_series','series','series_number','season','season_number','episode_titles','episode_title','description','descr','plot','summary','year','movie_year','release_date','premiered','screenshot_uri','cover','poster','movie_image','pic','genres','genres_str','genre_name','genre','language','audio_language','resolution','quality','hd','rating_imdb','imdb_rating','rating','runtime','duration','actors','director','use_http_tmp_link','use_load_balancing']));
                foreach(['description','descr','plot','summary','actors','director'] as $field)if(isset($row[$field])&&is_scalar($row[$field]))$row[$field]=mb_substr(trim(strip_tags((string)$row[$field])),0,5000);
                $meta=self::classify($row,$category);if($meta['year']===null)$meta['group_key']=hash('sha256',$fingerprint.':'.$kind.':'.$parent.':'.$id);
                $playable=!empty($row['cmd'])&&empty($row['is_series'])&&empty($row['series'])&&($kind==='vod'||$parent!=='');
                if($kind==='live'){$meta['country']=StalkerCountries::country($meta['name'])??StalkerCountries::country($category)??'ZZ';$meta['group']=StalkerCountries::group($meta['name'],$category);$meta['providerGroup']=$category;$category=$meta['group'];$meta['language']=$meta['country'];}
                $insert->execute([$fingerprint,$kind,$parent,$id,$meta['group_key'],$meta['name'],$meta['year'],'|'.implode('|',$meta['genres']).'|',$meta['language'],$meta['quality'],$category,$meta['rating'],$playable?1:0,json_encode(['row'=>$row,'facets'=>$meta,'category'=>$category],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
                if($kind!=='live'&&(empty($row['cmd'])||!empty($row['is_series']))&&empty($row['series'])&&$id!==$parent&&(int)$scan['depth']<6)$enqueue->execute([$fingerprint,$kind,$id,(int)$scan['depth']+1]);
            }
            $scan['processed']+=count($rows);$scan['next_page']++;$scan['last_hash']=$signature;$scan['error']='';
            if(isset($result['total_items'])&&is_numeric($result['total_items'])&&((int)$result['total_items']>0||!$rows))$scan['total']=max(0,(int)$result['total_items']);
            if(!$rows&&$scan['total']!==null&&$scan['processed']<(int)$scan['total'])throw new RuntimeException('Provider stopped before completing the catalogue.');
            $scan['done']=$bulk||!$rows||($scan['total']!==null&&$scan['processed']>=(int)$scan['total']);
            self::saveScan($fingerprint,$kind,$parent,$scan);$pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();$scan=$before;$scan['error']='Provider catalogue could not finish. Check the connection and retry from Stalker settings.';self::saveScan($fingerprint,$kind,$parent,$scan);}
        return self::summary($fingerprint,$kind);
    }
    private static function saveScan(string $fingerprint,string $kind,string $parent,array $scan): void {
        $q=self::pdo()->prepare('INSERT INTO vaultcast_stalker_scans(fingerprint,kind,parent_id,next_page,processed,total,done,last_hash,error,depth) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE next_page=VALUES(next_page),processed=VALUES(processed),total=VALUES(total),done=VALUES(done),last_hash=VALUES(last_hash),error=VALUES(error)');
        $q->execute([$fingerprint,$kind,$parent,$scan['next_page'],$scan['processed'],$scan['total'],$scan['done']?1:0,$scan['last_hash'],$scan['error'],$scan['depth']]);
    }
    public static function summary(string $fingerprint,string $kind): array {
        $q=self::pdo()->prepare("SELECT COUNT(*) AS tasks,COALESCE(SUM(processed),0) AS scanned,COALESCE(SUM(done=0 AND error=''),0) AS pending,COALESCE(SUM(error<>''),0) AS failed FROM vaultcast_stalker_scans WHERE fingerprint=? AND kind=?");$q->execute([$fingerprint,$kind]);$row=$q->fetch();
        return ['scanned'=>(int)$row['scanned'],'done'=>(int)$row['tasks']>0&&(int)$row['pending']===0&&(int)$row['failed']===0,'error'=>(int)$row['failed']>0?'One or more provider folders could not be loaded.':''];
    }
    public static function discard(string $fingerprint,?string $kind=null): bool {$complete=true;foreach(['vaultcast_stalker_titles','vaultcast_stalker_scans'] as $table){$q=self::pdo()->prepare('DELETE FROM '.$table.' WHERE fingerprint=?'.($kind!==null?' AND kind=?':'').' LIMIT 500');$q->execute($kind!==null?[$fingerprint,$kind]:[$fingerprint]);if($q->rowCount()>=500)$complete=false;}return $complete;}
    private static function publicItem(array $feed,string $kind,array $stored): array {
        $row=$stored['row'];$meta=$stored['facets'];$id=(string)$row['id'];$playable=$kind==='vod'&&!empty($row['cmd'])&&empty($row['is_series'])&&empty($row['series']);
        return array_merge(StalkerSeries::metadata($feed,$row),$meta,['id'=>$playable?$kind.':'.$id:$id,'kind'=>$kind,'playable'=>$playable,'showId'=>$kind==='series'?$id:null,'feed'=>$feed['id'],'connection'=>$feed['name'],'category'=>$stored['category']]);
    }
    public static function entry(array $feed,string $publicId,string $kind='vod'): ?array {
        $series='';$id=$publicId;
        if(preg_match('/^(vod|series):([^:]+)(?::(.+))?$/D',$publicId,$m)){$kind=$m[1];$id=$m[2];$series=$m[3]??'';}
        $q=self::pdo()->prepare('SELECT parent_id,payload FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind=? AND item_id=? ORDER BY playable DESC,parent_id LIMIT 1');$q->execute([self::fingerprint($feed,$kind),$kind,$id]);$record=$q->fetch();if(!$record)return null;$stored=json_decode($record['payload'],true);
        if($kind==='live')return ['id'=>$id,'name'=>$stored['facets']['name'],'description'=>$stored['facets']['providerGroup']??'','playback'=>stalker_live_entry($stored['row'])];
        $item=self::publicItem($feed,$kind,$stored);
        if(strpos($publicId,':')===false&&$kind==='series')return $item;
        if($series!=='')$item=self::episode($feed,$kind,$stored,StalkerSeries::stored($feed,$record['parent_id'])??[],$series);
        return ['cmd'=>(string)($stored['row']['cmd']??''),'series'=>$series!==''?$series:(string)($stored['row']['series_number']??''),'metadata'=>$item];
    }
    public static function browse(array $state,array $opts): array {
        $kind=(string)($opts['kind']??'vod');if(!in_array($kind,['vod','series'],true))throw new InvalidArgumentException('Choose Movies or Series.');
        $feeds=[];$fingerprints=[];$progress=[];$indexing=false;$preview=false;
        foreach($state['feeds']??[] as $feed)if(($feed['type']??'')==='stalker'&&!empty($feed['enabled'])){$fp=self::fingerprint($feed,$kind);$feeds[$fp]=$feed;$fingerprints[]=$fp;$summary=self::scan($fp,$kind);$progress[]=['name'=>$feed['name'],'scanned'=>(int)$summary['processed'],'total'=>$summary['total']===null?null:(int)$summary['total'],'done'=>(bool)$summary['done'],'error'=>$summary['error']];}
        if(!$feeds)return ['ok'=>true,'items'=>[],'total'=>0,'hasMore'=>false,'facets'=>[],'connections'=>[],'indexing'=>false,'preview'=>false];
        $responseKey='stalker_vod_read_v4_'.hash('sha256',json_encode([array_keys($feeds),array_column($feeds,'name'),array_intersect_key($opts,array_flip(['kind','feed','q','genre','decade','year','language','quality','category','rating','sort','offset']))]));$cached=\Vaultcast\Repository::cacheGet($responseKey);if($cached!==null)return $cached;
        $where="parent_id='' AND kind=? AND fingerprint IN (".implode(',',array_fill(0,count($feeds),'?')).')';$args=array_merge([$kind],$fingerprints);
        if(!empty($opts['feed'])){$wanted=null;foreach($feeds as $fp=>$feed)if($feed['id']===$opts['feed'])$wanted=$fp;$where.=' AND fingerprint=?';$args[]=$wanted??'';}
        $facetWhere=$where;$facetArgs=$args;
        $search=mb_substr(trim((string)($opts['q']??'')),0,160);if($search!==''){$where.=' AND title LIKE ?';$args[]='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$where.=" ESCAPE '!'";}
        foreach(['language','quality','category'] as $field)if(isset($opts[$field])&&$opts[$field]!==''){$where.=' AND '.$field.'=?';$args[]=mb_substr((string)$opts[$field],0,190);}
        if(!empty($opts['genre'])){$where.=' AND genres LIKE ?';$args[]='%|'.str_replace(['%','_','|'], '',mb_substr((string)$opts['genre'],0,80)).'|%';}
        if(isset($opts['year'])&&$opts['year']!==''){$where.=$opts['year']==='unknown'?' AND year IS NULL':' AND year=?';if($opts['year']!=='unknown')$args[]=(int)$opts['year'];}
        if(isset($opts['decade'])&&$opts['decade']!==''){$where.=$opts['decade']==='unknown'?' AND year IS NULL':' AND year BETWEEN ? AND ?';if($opts['decade']!=='unknown'){$args[]=(int)$opts['decade'];$args[]=(int)$opts['decade']+9;}}
        if(!empty($opts['rating'])){$where.=$opts['rating']==='unknown'?' AND rating IS NULL':' AND rating>=?';if($opts['rating']!=='unknown')$args[]=max(0,min(10,(float)$opts['rating']));}
        $orders=['title'=>'MIN(title) ASC','title_desc'=>'MIN(title) DESC','newest'=>'MAX(year) DESC,MIN(title) ASC','oldest'=>'MIN(year) IS NULL,MIN(year) ASC,MIN(title) ASC','rating'=>'MAX(rating) DESC,MIN(title) ASC'];$order=$orders[(string)($opts['sort']??'title')]??$orders['title'];
        $offset=max(0,min(1000000,(int)($opts['offset']??0)));$pdo=self::pdo();$q=$pdo->prepare('SELECT COUNT(DISTINCT group_key) FROM vaultcast_stalker_titles WHERE '.$where);$q->execute($args);$total=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT group_key FROM vaultcast_stalker_titles WHERE '.$where.' GROUP BY group_key ORDER BY '.$order.',group_key LIMIT 60 OFFSET '.$offset);$q->execute($args);$keys=$q->fetchAll(PDO::FETCH_COLUMN);$grouped=[];
        if($keys){$q=$pdo->prepare('SELECT fingerprint,group_key,payload FROM vaultcast_stalker_titles WHERE '.$where.' AND group_key IN ('.implode(',',array_fill(0,count($keys),'?')).') ORDER BY fingerprint,item_id');$q->execute(array_merge($args,$keys));while($record=$q->fetch()){$stored=json_decode($record['payload'],true);$item=self::publicItem($feeds[$record['fingerprint']],$kind,$stored);$grouped[$record['group_key']][]=$item;}}
        $items=[];foreach($keys as $key){$sources=$grouped[$key]??[];if(!$sources)continue;$item=$sources[0];$item['sources']=array_map(static function($source){return array_intersect_key($source,array_flip(['id','feed','connection','playable','kind','language','quality','category']));},$sources);$items[]=$item;}
        $scanned=array_sum(array_column($progress,'scanned'));$facetKey='stalker_vod_facets_v1_'.hash('sha256',json_encode([$facetWhere,$facetArgs,$indexing,$preview,(int)floor($scanned/500)]));
        $facets=\Vaultcast\Repository::cacheGet($facetKey);
        if($facets===null){$facets=[];foreach(['genres','year','language','quality','category'] as $field){$q=$pdo->prepare('SELECT '.$field.' AS value,COUNT(DISTINCT group_key) AS count FROM vaultcast_stalker_titles WHERE '.$facetWhere.' GROUP BY '.$field.' ORDER BY '.$field);$q->execute($facetArgs);$facets[$field]=$q->fetchAll();}\Vaultcast\Repository::cachePut($facetKey,$facets,120);}

        $out=['ok'=>true,'items'=>$items,'total'=>$total,'hasMore'=>$offset+count($items)<$total,'facets'=>$facets,'connections'=>$progress,'indexing'=>false,'preview'=>false];\Vaultcast\Repository::cachePut($responseKey,$out,30);return $out;
    }
    private static function episode(array $feed,string $kind,array $stored,array $show,string $episode): array {
        $row=$stored['row'];$metadata=StalkerSeries::metadata($feed,$row,$show);$season=null;
        foreach(['season_number','season'] as $field)if(isset($row[$field])&&is_numeric($row[$field])){$season=(int)$row[$field];break;}
        if($season===null&&preg_match('/(?:season|сезон|^s)\s*(\d+)/iu',$metadata['name'],$m))$season=(int)$m[1];
        $seasonName=$season!==null?($season===0?'Specials':'Season '.$season):$metadata['name'];
        $title=is_array($row['episode_titles']??null)?self::text($row['episode_titles'][$episode]??''):'';
        return array_merge($metadata,['id'=>$kind.':'.$row['id'].':'.$episode,'name'=>($show['name']??$metadata['name']).' · '.$seasonName.' · '.($title?:'Episode '.$episode),'kind'=>'vod','playable'=>true,'season'=>$season,'seasonName'=>$seasonName,'seasonId'=>(string)$row['id'],'episode'=>$episode,'episodeTitle'=>$title,'showId'=>$show['showId']??$show['id']??'']);
    }
    public static function children(array $feed,array $opts): array {
        $kind=(string)($opts['kind']??'series');$parent=(string)($opts['parent']??'');$page=max(1,(int)($opts['page']??1));$fp=self::fingerprint($feed,$kind);$pdo=self::pdo();
        $show=StalkerSeries::stored($feed,$parent)??['id'=>$parent,'showId'=>$parent,'name'=>'Provider series'];
        $q=$pdo->prepare('SELECT COUNT(*) FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind=? AND parent_id=?');$q->execute([$fp,$kind,$parent]);$total=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT payload FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind=? AND parent_id=? ORDER BY title,item_id LIMIT 60 OFFSET '.(($page-1)*60));$q->execute([$fp,$kind,$parent]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);
        if(!$rows&&$page===1){$q=$pdo->prepare('SELECT payload FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind=? AND item_id=? LIMIT 1');$q->execute([$fp,$kind,$parent]);$raw=$q->fetchColumn();if($raw){$direct=json_decode($raw,true);if(!empty($direct['row']['cmd'])&&!empty($direct['row']['series'])){$rows=[$raw];$total=1;}}}
        $items=[];$expanded=false;
        foreach($rows as $raw){$stored=json_decode($raw,true);$row=$stored['row'];$episodes=is_array($row['series']??null)?$row['series']:[];
            if($episodes&&!empty($row['cmd'])){$expanded=true;foreach($episodes as $episode)if(is_scalar($episode))$items[]=self::episode($feed,$kind,$stored,$show,(string)$episode);continue;}
            $item=self::publicItem($feed,$kind,$stored);$item['playable']=!empty($row['cmd'])&&empty($row['is_series']);if($item['playable'])$item['id']=$kind.':'.$row['id'];else StalkerSeries::remember($feed,(string)$row['id'],$item);$items[]=$item;
        }
        return ['ok'=>true,'items'=>$items,'categories'=>[],'page'=>$page,'total'=>$total,'hasMore'=>$page*60<$total,'expandedEpisodes'=>$expanded,'show'=>$kind==='series'?$show:null,'shared'=>true,'catalogueLoading'=>!self::scan($fp,$kind,$parent)['done']];
    }
    public static function liveBrowse(array $state,array $opts): array {
        $country=strtoupper((string)($opts['country']??''));if($country!==''&&$country!=='ZZ'&&strpos(' '.StalkerCountries::CODES.' ',' '.$country.' ')===false)throw new InvalidArgumentException('Choose a country.');
        $feeds=[];$progress=[];foreach($state['feeds']??[] as $feed)if(($feed['type']??'')==='stalker'&&!empty($feed['enabled'])){$fp=self::fingerprint($feed,'live');$feeds[$fp]=$feed;$summary=self::summary($fp,'live');$progress[]=['name'=>$feed['name'],'scanned'=>$summary['scanned'],'total'=>null,'done'=>$summary['done'],'error'=>$summary['error']];}
        $counts=array_fill_keys(StalkerCountries::GROUPS,0);if(!$feeds)return ['ok'=>true,'items'=>[],'groups'=>$counts,'total'=>0,'hasMore'=>false,'connections'=>[],'indexing'=>false];
        $where="parent_id='' AND kind='live' AND fingerprint IN (".implode(',',array_fill(0,count($feeds),'?')).')';$args=array_keys($feeds);
        if($country!==''){$where.=' AND language=?';$args[]=$country;}$query=trim((string)($opts['q']??''));if($query!==''){$where.=" AND title LIKE ? ESCAPE '!'";$args[]='%'.strtr(mb_substr($query,0,160),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
        $pdo=self::pdo();$q=$pdo->prepare('SELECT category,COUNT(*) AS count FROM vaultcast_stalker_titles WHERE '.$where.' GROUP BY category');$q->execute($args);foreach($q->fetchAll() as $row)$counts[$row['category']]=(int)$row['count'];
        if(!empty($opts['group'])){$where.=' AND category=?';$args[]=(string)$opts['group'];}$q=$pdo->prepare('SELECT COUNT(*) FROM vaultcast_stalker_titles WHERE '.$where);$q->execute($args);$total=(int)$q->fetchColumn();$offset=max(0,(int)($opts['offset']??0));
        $q=$pdo->prepare('SELECT fingerprint,item_id,payload FROM vaultcast_stalker_titles WHERE '.$where.' ORDER BY title,fingerprint,item_id LIMIT 120 OFFSET '.$offset);$q->execute($args);$items=[];
        while($record=$q->fetch()){$stored=json_decode($record['payload'],true);$feed=$feeds[$record['fingerprint']];$meta=$stored['facets'];$items[]=['id'=>$record['item_id'],'name'=>$meta['name'],'description'=>$meta['providerGroup']??'','group'=>$meta['group']??'Other','country'=>$meta['country']??'ZZ','kind'=>'live','feed'=>$feed['id'],'connection'=>$feed['name']];}
        return ['ok'=>true,'items'=>$items,'groups'=>$counts,'total'=>$total,'hasMore'=>$offset+count($items)<$total,'connections'=>$progress,'indexing'=>false,'shared'=>true];
    }

    public static function legacyLiveIndex(array $feed): array {
        $q=self::pdo()->prepare("SELECT item_id,title,category FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind='live' AND parent_id='' ORDER BY title,item_id");$q->execute([self::fingerprint($feed,'live')]);$channels=[];
        while($row=$q->fetch())$channels[]=['id'=>'stalker-'.substr(hash('sha256',$feed['id'].':'.$row['item_id']),0,20),'name'=>$row['title'],'group'=>$row['category'],'url'=>stalker_channel_url($feed['id'],$row['item_id']),'tvgId'=>$row['item_id']];
        return ['channels'=>$channels,'epgUrls'=>[]];
    }

}
