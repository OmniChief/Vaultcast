<?php
declare(strict_types=1);

/** Provider-only VOD library. Catalogue identity is separate from connection identity. */
final class StalkerLibrary {
    private static function db(): PDO { return \Vaultcast\Database::connection(); }
    private static function query(string $sql,array $args=[]): PDOStatement {$q=self::db()->prepare($sql);$q->execute($args);return $q;}
    private static function json(array $v): string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
    private static function text($v): string {return is_scalar($v)?trim(html_entity_decode(strip_tags((string)$v),ENT_QUOTES|ENT_HTML5,'UTF-8')):'';}
    public static function title(string $s): string {
        $s=mb_strtolower(self::text($s));
        $s=preg_replace('/^(?:(?:en|eng|uk|us|fr|de|es|it|nl|ru|pt|ar|hi|tr|gr|el|nf|netflix|amz|amazon|hbo|max)\s*[-|:]\s*)+/iu','',$s);
        $s=preg_replace('/\s*[\[(](?:(?:19|20)\d{2}|4k|uhd|fhd|hd|sd|1080p|720p|2160p)[\])]\s*/iu',' ',$s);
        $s=preg_replace('/\s+(?:4k|uhd|fhd|1080p|720p|2160p)$/iu','',$s);
        $s=preg_replace('/\s+(?:19|20)\d{2}$/u','',$s);
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$s));
    }
    private static function ids(array $row): array {
        $imdb=self::text($row['imdb_id']??$row['imdb']??'');$tmdb=self::text($row['tmdb_id']??$row['tmdb']??'');
        if(preg_match('/^\d{1,12}$/D',$imdb)&&((int)$imdb)>0)$imdb='tt'.str_pad($imdb,7,'0',STR_PAD_LEFT);
        return [preg_match('/^(?:https?:\/\/(?:www\.)?imdb\.com\/title\/)?(tt\d{5,12})\/?$/i',$imdb,$m)?strtolower($m[1]):'',preg_match('/^[1-9]\d{0,11}$/D',$tmdb)?$tmdb:''];
    }
    private static function names($v): array {
        if(!is_array($v))$v=preg_split('/[,;|\/]+/',self::text($v));
        $out=[];foreach($v as $x){$x=self::text(is_array($x)?($x['name']??$x['title']??''):$x);if($x!==''&&!is_numeric($x))$out[]=mb_substr($x,0,80);}return array_values(array_unique($out));
    }
    private static function languages($v,bool $knownOnly=false): array {
        $map=['en'=>'English','eng'=>'English','uk'=>'English','us'=>'English','english'=>'English','fr'=>'French','fra'=>'French','fre'=>'French','french'=>'French','de'=>'German','deu'=>'German','ger'=>'German','german'=>'German','es'=>'Spanish','spa'=>'Spanish','spanish'=>'Spanish','it'=>'Italian','ita'=>'Italian','italian'=>'Italian','pt'=>'Portuguese','por'=>'Portuguese','portuguese'=>'Portuguese','nl'=>'Dutch','nld'=>'Dutch','dut'=>'Dutch','dutch'=>'Dutch','el'=>'Greek','gr'=>'Greek','gre'=>'Greek','greek'=>'Greek','ru'=>'Russian','rus'=>'Russian','russian'=>'Russian','ar'=>'Arabic','ara'=>'Arabic','arabic'=>'Arabic','hi'=>'Hindi','hin'=>'Hindi','hindi'=>'Hindi','tr'=>'Turkish','tur'=>'Turkish','turkish'=>'Turkish','ja'=>'Japanese','jp'=>'Japanese','jpn'=>'Japanese','japanese'=>'Japanese','ko'=>'Korean','kor'=>'Korean','korean'=>'Korean','zh'=>'Chinese','chi'=>'Chinese','zho'=>'Chinese','chinese'=>'Chinese'];
        $out=[];foreach(self::names($v) as $name){$key=mb_strtolower(trim(preg_replace('/\s*\[[^]]*\]\s*/','',$name)));if(preg_match('/^([a-z]{2,3})[-_][a-z]{2}$/i',$key,$locale))$key=$locale[1];if(isset($map[$key]))$out[]=$map[$key];elseif(!$knownOnly&&!in_array($key,['unknown','multi','multilingual','n/a','none','null'],true))$out[]=$name;}return array_values(array_unique($out));
    }
    private static function metadata(array $feed,array $row,string $kind,string $category): array {
        foreach(['info','movie_info','video_info'] as $section)if(is_array($row[$section]??null))$row=array_merge($row[$section],$row);
        $m=array_merge(StalkerSeries::metadata($feed,$row),StalkerDirect::descriptiveFields($row));
        [$imdb,$tmdb]=self::ids($row);$m['imdb_id']=$imdb;$m['tmdb_id']=$tmdb;
        $m['year']=preg_match('/^(19|20)\d{2}$/D',(string)$m['year'])?(int)$m['year']:null;
        $m['languages']=self::languages($row['audio_languages']??$row['audio_language']??$row['language']??'');
        $m['languageEvidence']=$m['languages']?'provider audio metadata':'unknown';
        // A category label is an audience hint, never proof of an audio track.
        $m['audienceLanguages']=[];
        $audience=preg_replace('/\b(?:with\s+)?(?:english|en|eng)\s+(?:subtitles?|subs?)\b/i','',$category);
        $audience=preg_replace('/^\s*(?:4k|uhd|fhd|hd|sd)\s*[-|:]?\s*/i','',$audience);
        if(preg_match_all('/(?:^|[|:\[(])\s*([A-Za-z]{2,3})\s*(?=[-|:\])]|$)|\b(English|French|German|Spanish|Italian|Portuguese|Dutch|Greek|Russian|Arabic|Hindi|Turkish|Japanese|Korean|Chinese)\b/i',$audience,$matches,PREG_SET_ORDER))foreach($matches as $match)$m['audienceLanguages']=array_merge($m['audienceLanguages'],self::languages(($match[1]??'')?:($match[2]??''),true));
        $m['audienceLanguages']=array_values(array_unique($m['audienceLanguages']));
        $m['subtitleLanguages']=self::languages($row['subtitle_languages']??'');
        $m['genres']=self::names($row['genres']??$row['genres_str']??$row['genre_name']??$row['genre']??'');
        if(!$m['genres'])foreach(['Action','Adventure','Animation','Comedy','Crime','Documentary','Drama','Family','Fantasy','History','Horror','Music','Mystery','Romance','Science Fiction','Thriller','War','Western','Sport'] as $genre)if(preg_match('/\b'.preg_quote($genre,'/').'\b/i',$category))$m['genres'][]=$genre;
        $m['quality']=self::text($row['quality']??$row['resolution']??'');
        if(preg_match('/\b(2160p|4k|uhd|1080p|fhd|720p|hd|sd)\b/i',$m['quality'].' '.$m['name'].' '.$category,$q))$m['quality']=strtoupper($q[1]);
        $m['category']=$category;$m['kind']=$kind;$m['playable']=$kind==='vod'&&!empty($row['cmd'])&&empty($row['is_series'])&&empty($row['series']);
        $m['id']=$m['playable']?$kind.':'.$row['id']:(string)$row['id'];$m['feed']=$feed['id'];$m['connection']=$feed['name'];
        return $m;
    }
    private static function compatible(array $a,array $b): bool {
        foreach(['imdb_id','tmdb_id'] as $id)if(!empty($a[$id])&&!empty($b[$id])&&$a[$id]!==$b[$id])return false;
        return true;
    }
    private static function identity(array $m,string $kind,?array $previous=null): string {
        $candidates=[];
        foreach(['imdb_id','tmdb_id'] as $field)if($m[$field]!=='')foreach(self::query('SELECT * FROM vaultcast_stalker_library WHERE kind=? AND '.$field.'=?',[$kind,$m[$field]]) as $r)$candidates[$r['id']]=$r;
        $title=self::title($m['o_name']??$m['name']);$key=hash('sha256',$title);$displayKey=hash('sha256',self::title($m['name']));
        if($m['year']&&$title!=='')foreach(self::query('SELECT c.* FROM vaultcast_stalker_aliases a JOIN vaultcast_stalker_library c ON c.id=a.catalogue_id WHERE c.kind=? AND a.title_key IN (?,?) AND a.year=?',[$kind,$key,$displayKey,$m['year']]) as $r)if(self::compatible($m,$r))$candidates[$r['id']]=$r;
        if($previous){$r=self::query('SELECT * FROM vaultcast_stalker_library WHERE id=?',[$previous['catalogue_id']])->fetch();if($r)$candidates[$r['id']]=$r;}
        $candidates=array_filter($candidates,static function($r)use($m){return self::compatible($m,$r);});
        // Evidence must agree across the whole group, not just with an ID-less input.
        $consistent=true;$evidence=$m;
        foreach($candidates as $r){if(!self::compatible($evidence,$r)){$consistent=false;break;}foreach(['imdb_id','tmdb_id'] as $field)if($evidence[$field]==='')$evidence[$field]=$r[$field];}
        if($candidates&&$consistent){
            ksort($candidates);$id=$previous&&isset($candidates[$previous['catalogue_id']])?$previous['catalogue_id']:(string)array_key_first($candidates);
            foreach($candidates as $other=>$r)if($other!==$id){
                self::query('UPDATE vaultcast_stalker_offers SET catalogue_id=? WHERE catalogue_id=?',[$id,$other]);
                self::query('INSERT IGNORE INTO vaultcast_stalker_aliases(catalogue_id,title_key,year) SELECT ?,title_key,year FROM vaultcast_stalker_aliases WHERE catalogue_id=?',[$id,$other]);
                foreach(['vaultcast_stalker_facets','vaultcast_stalker_aliases'] as $table)self::query('DELETE FROM '.$table.' WHERE catalogue_id=?',[$other]);
                self::query('DELETE FROM vaultcast_stalker_library WHERE id=?',[$other]);
            }
            self::query("UPDATE vaultcast_stalker_library SET imdb_id=IF(imdb_id='',?,imdb_id),tmdb_id=IF(tmdb_id='',?,tmdb_id) WHERE id=?",[$evidence['imdb_id'],$evidence['tmdb_id'],$id]);return $id;
        }
        // Keep an established source stable when other same-name titles are ambiguous.
        if($previous)return $previous['catalogue_id'];
        $id=bin2hex(random_bytes(16));self::query('INSERT INTO vaultcast_stalker_library(id,kind,title,title_key,year,imdb_id,tmdb_id,payload,unavailable_since) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$kind,mb_substr($m['name'],0,500),$key,$m['year'],$m['imdb_id'],$m['tmdb_id'],self::json($m),time()]);return $id;
    }
    private static function ingest(array $feed,string $kind,array $row,string $category,string $generation): string {
        $m=self::metadata($feed,$row,$kind,$category);
        $old=self::query('SELECT s.catalogue_id,s.payload,c.imdb_id,c.tmdb_id,c.title_key,c.year FROM vaultcast_stalker_offers s JOIN vaultcast_stalker_library c ON c.id=s.catalogue_id WHERE s.feed_id=? AND s.kind=? AND s.item_id=?',[$feed['id'],$kind,(string)$row['id']])->fetch();
        $key=hash('sha256',self::title($m['o_name']??$m['name']));
        $oldMetadata=$old?json_decode($old['payload'],true):[];$oldTitle=$oldMetadata?hash('sha256',self::title($oldMetadata['o_name']??$oldMetadata['name'])):'';
        $same=$old&&self::compatible($m,$old)&&($oldTitle===$key||($m['imdb_id']!==''&&$m['imdb_id']===$old['imdb_id'])||($m['tmdb_id']!==''&&$m['tmdb_id']===$old['tmdb_id']))&&(!$m['year']||!$old['year']||(int)$old['year']===$m['year']);
        $id=self::identity($m,$kind,$same?$old:null);
        if($m['year'])foreach(array_unique([$key,hash('sha256',self::title($m['name']))]) as $alias)self::query('INSERT IGNORE INTO vaultcast_stalker_aliases(catalogue_id,title_key,year) VALUES(?,?,?)',[$id,$alias,$m['year']]);
        if($same)self::query("UPDATE vaultcast_stalker_library SET imdb_id=IF(imdb_id='',?,imdb_id),tmdb_id=IF(tmdb_id='',?,tmdb_id) WHERE id=?",[$m['imdb_id'],$m['tmdb_id'],$id]);
        $private=['cmd'=>is_scalar($row['cmd']??null)?(string)$row['cmd']:'','series'=>(string)($row['series_number']??''),'metadata'=>$m];
        self::query('INSERT INTO vaultcast_stalker_offers(feed_id,kind,item_id,catalogue_id,config,generation,available,last_seen,payload,playback) VALUES(?,?,?,?,?,?,1,?,?,?) ON DUPLICATE KEY UPDATE catalogue_id=VALUES(catalogue_id),config=VALUES(config),generation=VALUES(generation),available=1,last_seen=VALUES(last_seen),payload=VALUES(payload),playback=VALUES(playback)',[$feed['id'],$kind,(string)$row['id'],$id,StalkerCatalogues::config($feed),$generation,time(),self::json($m),self::json($private)]);
        if($old&&$old['catalogue_id']!==$id)self::aggregate($old['catalogue_id']);
        return $id;
    }
    private static function aggregate(string $id): void {
        $rows=self::query('SELECT available,payload FROM vaultcast_stalker_offers WHERE catalogue_id=? ORDER BY available DESC,last_seen DESC,feed_id,item_id',[$id])->fetchAll();
        $available=false;$m=[];$sets=['languages'=>[],'audienceLanguages'=>[],'subtitleLanguages'=>[],'genres'=>[]];
        foreach($rows as $r){$item=json_decode($r['payload'],true);if(!$m)$m=$item;foreach(['description','poster','director','actors','year','imdb_id','tmdb_id','rating_imdb'] as $field)if(empty($m[$field])&&!empty($item[$field]))$m[$field]=$item[$field];if($r['available']){$available=true;foreach($sets as $field=>$_)$sets[$field]=array_merge($sets[$field],$item[$field]??[]);}}
        $previous=self::query('SELECT payload FROM vaultcast_stalker_library WHERE id=?',[$id])->fetchColumn();$previous=$previous?json_decode($previous,true):[];
        if($m)foreach(['description','poster','director','actors','year','rating_imdb'] as $field)if(empty($m[$field])&&!empty($previous[$field]))$m[$field]=$previous[$field];
        if(!$available&&$m)$sets['genres']=$m['genres']??[];
        if($m){foreach($sets as $field=>$values)$m[$field]=array_values(array_unique($values));self::query('UPDATE vaultcast_stalker_library SET title=?,year=COALESCE(?,year),payload=?,available=?,unavailable_since=IF(?=1,NULL,COALESCE(unavailable_since,?)) WHERE id=?',[mb_substr($m['name'],0,500),$m['year'],self::json($m),$available?1:0,$available?1:0,time(),$id]);}
        else self::query('UPDATE vaultcast_stalker_library SET available=0,unavailable_since=COALESCE(unavailable_since,?) WHERE id=?',[time(),$id]);
        self::query('DELETE FROM vaultcast_stalker_facets WHERE catalogue_id=?',[$id]);
        foreach($sets as $field=>$values)foreach(array_unique($values) as $value)self::query('INSERT IGNORE INTO vaultcast_stalker_facets(catalogue_id,facet,value) VALUES(?,?,?)',[$id,$field,$value]);
    }
    public static function invalidate(string $feed,?string $kind=null,?string $config=null): void {
        $where='feed_id=?'.($kind!==null?' AND kind=?':'');$args=$kind!==null?[$feed,$kind]:[$feed];if($config!==null){$where.=' AND config=?';$args[]=$config;}
        $ids=self::query('SELECT DISTINCT catalogue_id FROM vaultcast_stalker_offers WHERE available=1 AND '.$where,$args)->fetchAll(PDO::FETCH_COLUMN);
        self::query('UPDATE vaultcast_stalker_offers SET available=0 WHERE '.$where,$args);foreach($ids as $id)self::aggregate($id);
    }
    public static function queue(array $feed,string $kind,bool $reset=true): void {
        $old=self::query('SELECT * FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=?',[$feed['id'],$kind])->fetch();
        if(!$reset&&$old&&in_array($old['status'],['queued','running'],true))return;
        self::query("INSERT INTO vaultcast_stalker_jobs(feed_id,kind,config,generation,status,next_page,processed,next_run) VALUES(?,?,?,?,'queued',1,0,?) ON DUPLICATE KEY UPDATE config=VALUES(config),generation=VALUES(generation),status='queued',next_page=1,processed=0,last_hash='',error='',next_run=VALUES(next_run)",[$feed['id'],$kind,StalkerCatalogues::config($feed),bin2hex(random_bytes(16)),time()]);
    }
    public static function tick(): bool {
        require_once STREAM_ROOT.'/src/Live/iptv.php';require_once STREAM_ROOT.'/src/Live/stalker.php';
        $feeds=[];foreach(iptv_load()['feeds']??[] as $f)if(($f['type']??'')==='stalker'&&!empty($f['enabled']))$feeds[$f['id']]=$f;
        foreach(self::query('SELECT DISTINCT feed_id,kind,config FROM vaultcast_stalker_offers WHERE available=1') as $source){$current=$feeds[$source['feed_id']]??null;if(!$current||StalkerCatalogues::config($current)!==$source['config'])self::invalidate($source['feed_id'],$source['kind'],$source['config']);}
        foreach(self::query('SELECT * FROM vaultcast_stalker_jobs') as $j){$f=$feeds[$j['feed_id']]??null;if(!$f||StalkerCatalogues::config($f)!==$j['config']){self::invalidate($j['feed_id'],$j['kind']);if($f)self::queue($f,$j['kind']);else self::query("UPDATE vaultcast_stalker_jobs SET status='removed' WHERE feed_id=? AND kind=?",[$j['feed_id'],$j['kind']]);}elseif(in_array($j['status'],['complete','error','removed'],true)&&(int)$j['next_run']<=time())self::queue($f,$j['kind']);}
        self::refreshLocal();self::expire();
        $job=self::query("SELECT * FROM vaultcast_stalker_jobs WHERE status IN ('queued','running') ORDER BY next_run,feed_id,kind LIMIT 1")->fetch();if(!$job)return false;
        $feed=$feeds[$job['feed_id']]??null;if(!$feed)return false;$kind=$job['kind'];
        try {
            if((int)$job['next_page']>10000)throw new RuntimeException('Page limit reached.');
            $groupsKey='stalker_library_groups_'.StalkerCatalogues::config($feed).'_'.$kind;$groups=\Vaultcast\Repository::cacheGet($groupsKey);
            if($groups===null||(int)$job['next_page']===1){$groups=[];try{$g=stalker_call($feed,'get_categories',[],$kind);foreach($g['data']??$g as $r)if(is_array($r)&&isset($r['id']))$groups[(string)$r['id']]=self::text($r['title']??$r['name']??'');}catch(RuntimeException $ignored){}\Vaultcast\Repository::cachePut($groupsKey,$groups,86400);}
            $page=stalker_ordered_page($feed,$kind,['sortby'=>'name','category'=>'*','search'=>''],(int)$job['next_page']);$rows=$page['data']??null;
            if(!is_array($rows))throw new RuntimeException('Invalid page.');
            $hash=hash('sha256',self::json(array_column($rows,'id')));if($rows&&$hash===$job['last_hash'])throw new RuntimeException('Repeated page.');
            foreach($rows as $r)if(!is_array($r)||self::text($r['id']??'')===''||strlen(self::text($r['id']))>120)throw new RuntimeException('Invalid provider item.');
            $count=(int)$job['processed']+count($rows);$total=isset($page['total_items'])&&is_numeric($page['total_items'])?(int)$page['total_items']:0;
            if(!$rows&&$total>$count)throw new RuntimeException('Incomplete page.');
            $done=!$rows||($total>0&&$count>=$total);
            self::db()->beginTransaction();
            $current=self::query('SELECT generation,status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE',[$feed['id'],$kind])->fetch();
            if(!$current||$current['generation']!==$job['generation']||!in_array($current['status'],['queued','running'],true)){self::db()->rollBack();return true;}
            if($rows){$ids=array_column($rows,'id');if(count(array_unique($ids))!==count($ids))throw new RuntimeException('Repeated IDs in provider page.');$seen=self::query('SELECT COUNT(*) FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND generation=? AND item_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_merge([$feed['id'],$kind,$job['generation']],$ids))->fetchColumn();if((int)$seen>0)throw new RuntimeException('Overlapping provider pages; retry a consistent scan.');}
            $changed=[];foreach($rows as $row){$category=self::text($row['category_name']??$row['category_title']??($groups[(string)($row['category_id']??$row['category']??'')]??''));$changed[self::ingest($feed,$kind,$row,$category,$job['generation'])]=true;}
            if($done){foreach(self::query('SELECT DISTINCT catalogue_id FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND generation<>? AND available=1',[$feed['id'],$kind,$job['generation']]) as $r)$changed[$r['catalogue_id']]=true;self::query('UPDATE vaultcast_stalker_offers SET available=0 WHERE feed_id=? AND kind=? AND generation<>?',[$feed['id'],$kind,$job['generation']]);}
            foreach(array_keys($changed) as $id)self::aggregate($id);
            self::query('UPDATE vaultcast_stalker_jobs SET status=?,next_page=next_page+1,processed=?,last_hash=?,next_run=?,error=\'\' WHERE feed_id=? AND kind=?',[$done?'complete':'running',$count,$hash,time()+($done?86400:0),$feed['id'],$kind]);self::db()->commit();
        }catch(Throwable $e){if(self::db()->inTransaction())self::db()->rollBack();self::db()->beginTransaction();try{$current=self::query('SELECT generation,status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE',[$feed['id'],$kind])->fetch();if($current&&$current['generation']===$job['generation']&&in_array($current['status'],['queued','running'],true)){self::invalidate($feed['id'],$kind);self::query("UPDATE vaultcast_stalker_jobs SET status='error',error=?,next_run=? WHERE feed_id=? AND kind=?",['Provider scan failed; retrying in one hour. Last known titles are retained.',time()+3600,$feed['id'],$kind]);}self::db()->commit();}catch(Throwable $failure){if(self::db()->inTransaction())self::db()->rollBack();throw $failure;}}
        return true;
    }
    private static function expire(): void {
        $ids=self::query('SELECT id FROM vaultcast_stalker_library WHERE available=0 AND unavailable_since<=? LIMIT 100',[time()-15*86400])->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id){self::db()->beginTransaction();try{$row=self::query('SELECT available,unavailable_since FROM vaultcast_stalker_library WHERE id=? FOR UPDATE',[$id])->fetch();if($row&&!$row['available']&&(int)$row['unavailable_since']<=time()-15*86400){foreach(['vaultcast_stalker_offers','vaultcast_stalker_facets','vaultcast_stalker_aliases'] as $table)self::query('DELETE FROM '.$table.' WHERE catalogue_id=?',[$id]);self::query('DELETE FROM vaultcast_stalker_library WHERE id=?',[$id]);}self::db()->commit();}catch(Throwable $e){self::db()->rollBack();throw $e;}}
    }
    public static function entry(array $feed,string $publicId): ?array {
        if(!preg_match('/^(vod|series):([^:]+)$/D',$publicId,$m))return null;
        $raw=self::query('SELECT playback FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND item_id=? AND config=? AND available=1',[$feed['id'],$m[1],$m[2],StalkerCatalogues::config($feed)])->fetchColumn();return $raw?json_decode($raw,true):null;
    }
    public static function syncLocal(array $item): void {
        if(empty($item['id']))return;if(($item['media_kind']??'')!=='video'){self::query('DELETE FROM vaultcast_stalker_local WHERE item_id=?',[$item['id']]);return;}
        $show=self::text($item['show']??$item['show_title']??'');$kind=$show!==''?'series':'vod';
        [$imdb,$tmdb]=self::ids($item);$year=(int)($item['year']??0);if($year<1900||$year>2099)$year=null;
        self::query('INSERT INTO vaultcast_stalker_local(item_id,kind,title_key,year,imdb_id,tmdb_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),title_key=VALUES(title_key),year=VALUES(year),imdb_id=VALUES(imdb_id),tmdb_id=VALUES(tmdb_id)',[$item['id'],$kind,hash('sha256',self::title($show!==''?$show:($item['title']??''))),$year,$imdb,$tmdb]);
    }
    private static function refreshLocal(): void {
        // Bounded repair/backfill for pre-upgrade libraries and metadata edits outside BrowseIndex.
        $cursor=\Vaultcast\Repository::document('stalker-library-local-cursor',['id'=>'','next'=>0]);
        if(($cursor['next']??0)>time())return;
        $rows=self::query('SELECT id,payload FROM vaultcast_items WHERE id>? ORDER BY id LIMIT 200',[$cursor['id']??''])->fetchAll();
        foreach($rows as $r){$item=json_decode($r['payload'],true);if(is_array($item))self::syncLocal($item);}
        self::query('DELETE l FROM vaultcast_stalker_local l LEFT JOIN vaultcast_items i ON i.id=l.item_id WHERE i.id IS NULL');
        \Vaultcast\Repository::putDocument('stalker-library-local-cursor',['id'=>count($rows)===200?end($rows)['id']:'','next'=>count($rows)===200?0:time()+300]);
    }
    private static function local(array $item,string $kind): bool {
        if(!AreaAccess::allowed(AreaAccess::viewer(),'library'))return false;
        $where=[];$args=[$kind];foreach(['imdb_id','tmdb_id'] as $field)if(!empty($item[$field])){$where[]='l.'.$field.'=?';$args[]=$item[$field];}
        if(!empty($item['year'])){$where[]='(l.title_key=? AND l.year=?)';$args[]=hash('sha256',self::title($item['o_name']??$item['name']));$args[]=$item['year'];}
        if(!$where)return false;
        foreach(self::query('SELECT l.imdb_id,l.tmdb_id FROM vaultcast_stalker_local l JOIN vaultcast_items i ON i.id=l.item_id WHERE l.kind=? AND ('.implode(' OR ',$where).') LIMIT 100',$args) as $row)if(self::compatible($item,$row))return true;
        return false;
    }
    public static function browse(array $state,array $opts): array {
        $kind=($opts['kind']??'vod')==='series'?'series':'vod';$where='c.kind=?';$args=[$kind];
        $term=mb_substr(self::text($opts['q']??''),0,160);if($term!==''){$where.=" AND c.title LIKE ? ESCAPE '!'";$args[]='%'.strtr($term,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
        foreach(['language'=>'languages','audience'=>'audienceLanguages','genre'=>'genres'] as $param=>$facet)if(!empty($opts[$param])){$where.=' AND EXISTS(SELECT 1 FROM vaultcast_stalker_facets f WHERE f.catalogue_id=c.id AND f.facet=? AND f.value=?)';$args[]=$facet;$args[]=mb_substr(self::text($opts[$param]),0,80);}
        if(preg_match('/^[A-Z]$/D',(string)($opts['letter']??''))){$where.=' AND c.title LIKE ?';$args[]=$opts['letter'].'%';}
        if(preg_match('/^(19|20)\d{2}$/D',(string)($opts['year']??''))){$where.=' AND c.year=?';$args[]=(int)$opts['year'];}
        if(in_array($opts['availability']??'',['available','unavailable'],true)){$where.=' AND c.available=?';$args[]=($opts['availability']==='available')?1:0;}
        $sort=['title'=>'c.title ASC','newest'=>'c.year DESC,c.title ASC','oldest'=>'c.year IS NULL,c.year ASC,c.title ASC'][$opts['sort']??'title']??'c.title ASC';$offset=max(0,min(1000000,(int)($opts['offset']??0)));
        $total=(int)self::query('SELECT COUNT(*) FROM vaultcast_stalker_library c WHERE '.$where,$args)->fetchColumn();
        $rows=self::query('SELECT c.* FROM vaultcast_stalker_library c WHERE '.$where.' ORDER BY '.$sort.',c.id LIMIT 60 OFFSET '.$offset,$args)->fetchAll();
        $feeds=[];foreach($state['feeds']??[] as $f)if(($f['type']??'')==='stalker'&&!empty($f['enabled']))$feeds[$f['id']]=$f;
        $offers=[];if($rows){$ids=array_column($rows,'id');foreach(self::query('SELECT catalogue_id,feed_id,config,payload FROM vaultcast_stalker_offers WHERE available=1 AND catalogue_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY feed_id,item_id',$ids) as $s){$feed=$feeds[$s['feed_id']]??null;if(!$feed||StalkerCatalogues::config($feed)!==$s['config'])continue;$m=json_decode($s['payload'],true);$m['connection']=$feed['name'];$offers[$s['catalogue_id']][]=$m;}}
        $items=[];foreach($rows as $r){$m=json_decode($r['payload'],true);$m['catalogueId']=$r['id'];$m['sources']=$offers[$r['id']]??[];$m['available']=(bool)$m['sources'];$m['local']=self::local($m,$kind);$m['unavailableSince']=$r['unavailable_since'];$items[]=$m;}
        $facets=[];foreach(self::query('SELECT f.facet,f.value,COUNT(*) AS count FROM vaultcast_stalker_facets f JOIN vaultcast_stalker_library c ON c.id=f.catalogue_id WHERE c.kind=? GROUP BY f.facet,f.value ORDER BY f.value',[$kind]) as $f)$facets[$f['facet']][]=['value'=>$f['value'],'count'=>(int)$f['count']];
        $years=self::query('SELECT DISTINCT year FROM vaultcast_stalker_library WHERE kind=? AND year IS NOT NULL ORDER BY year DESC',[$kind])->fetchAll(PDO::FETCH_COLUMN);
        return ['ok'=>true,'items'=>$items,'total'=>$total,'hasMore'=>$offset+count($items)<$total,'facets'=>$facets,'years'=>$years];
    }
}
