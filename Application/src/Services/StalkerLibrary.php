<?php
declare(strict_types=1);

/** Provider-only VOD library. Catalogue identity is separate from connection identity. */
final class StalkerLibrary {
    private static function db(): PDO { return \Vaultcast\Database::connection(); }
    private static function query(string $sql,array $args=[]): PDOStatement {$q=self::db()->prepare($sql);$q->execute($args);return $q;}
    private static function json(array $v): string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
    private static function text($v): string {return is_scalar($v)?trim(html_entity_decode(strip_tags((string)$v),ENT_QUOTES|ENT_HTML5,'UTF-8')):'';}
    public static function displayTitle(string $s): string {
        return trim(preg_replace('/^(?:(?:4k|uhd|fhd|hd|sd|2160p|1080p|720p|en|eng|uk|us|fr|de|es|it|nl|ru|pt|ar|hi|tr|gr|el|nf|netflix|amz|amazon|hbo|max)\s*[-|:]\s*)+/iu','',self::text($s)));
    }
    public static function title(string $s): string {
        $s=mb_strtolower(self::displayTitle($s));
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
        if(empty($m['year'])&&preg_match('/[\[(]((?:19|20)\d{2})[\])]/',$m['name'],$year))$m['year']=$year[1];
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
        $m['providerTitle']=self::text($row['name']??$row['o_name']??$m['name']);$m['name']=self::displayTitle($m['name']);
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
        // Strong identities take precedence over same-title/year aliases.
        foreach(['imdb_id','tmdb_id'] as $field){
            if($m[$field]==='')continue;
            foreach(self::query('SELECT * FROM vaultcast_stalker_library WHERE kind=? AND '.$field.'=? LIMIT 101',[$kind,$m[$field]]) as $r)if(self::compatible($m,$r))$candidates[$r['id']]=$r;
            if($candidates)break;
        }
        $title=self::title($m['o_name']??$m['name']);$key=hash('sha256',$title);$displayKey=hash('sha256',self::title($m['name']));
        if(!$candidates&&$m['year']&&$title!=='')foreach(self::query('SELECT c.* FROM vaultcast_stalker_aliases a JOIN vaultcast_stalker_library c ON c.id=a.catalogue_id WHERE c.kind=? AND a.title_key IN (?,?) AND a.year=? LIMIT 101',[$kind,$key,$displayKey,$m['year']]) as $r)if(self::compatible($m,$r))$candidates[$r['id']]=$r;
        if(count($candidates)>100)$candidates=[]; // Ambiguous matches must not grow with the library.
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
        $old=self::query('SELECT s.catalogue_id,s.payload,s.playback,c.imdb_id,c.tmdb_id,c.title_key,c.year FROM vaultcast_stalker_offers s JOIN vaultcast_stalker_library c ON c.id=s.catalogue_id WHERE s.feed_id=? AND s.kind=? AND s.item_id=?',[$feed['id'],$kind,(string)$row['id']])->fetch();
        $key=hash('sha256',self::title($m['o_name']??$m['name']));
        $oldMetadata=$old?json_decode($old['payload'],true):[];$oldTitle=$oldMetadata?hash('sha256',self::title($oldMetadata['o_name']??$oldMetadata['name'])):'';
        $same=$old&&self::compatible($m,$old)&&($oldTitle===$key||($m['imdb_id']!==''&&$m['imdb_id']===$old['imdb_id'])||($m['tmdb_id']!==''&&$m['tmdb_id']===$old['tmdb_id']))&&(!$m['year']||!$old['year']||(int)$old['year']===$m['year']);
        $private=['cmd'=>is_scalar($row['cmd']??null)?(string)$row['cmd']:'','series'=>(string)($row['series_number']??''),'metadata'=>$m];
        if($same&&$old['payload']===self::json($m)&&$old['playback']===self::json($private)){
            self::query('UPDATE vaultcast_stalker_offers SET config=?,generation=?,available=1,last_seen=? WHERE feed_id=? AND kind=? AND item_id=?',[StalkerCatalogues::config($feed),$generation,time(),$feed['id'],$kind,(string)$row['id']]);
            return $old['catalogue_id'];
        }
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
        $desired=[];foreach($sets as $field=>$values)foreach(array_unique($values) as $value)$desired[$field.'\0'.$value]=true;
        $existing=[];foreach(self::query('SELECT facet,value FROM vaultcast_stalker_facets WHERE catalogue_id=?',[$id]) as $facet)$existing[$facet['facet'].'\0'.$facet['value']]=true;
        if(!array_diff_key($desired,$existing)&&!array_diff_key($existing,$desired))return;
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
        if(!$reset&&$old&&$old['config']===StalkerCatalogues::config($feed)){
            if(in_array($old['status'],['paused','failed','error'],true)){
                self::query("UPDATE vaultcast_stalker_folders SET status='pending',failures=0,next_run=0,error='' WHERE feed_id=? AND kind=? AND generation=? AND status NOT IN ('complete','skipped')",[$feed['id'],$kind,$old['generation']]);
                self::query("UPDATE vaultcast_stalker_jobs SET status='queued',next_page=1,next_run=?,error='' WHERE feed_id=? AND kind=?",[time(),$feed['id'],$kind]);
            }
            return;
        }
        self::query("INSERT INTO vaultcast_stalker_jobs(feed_id,kind,config,generation,status,next_page,processed,next_run) VALUES(?,?,?,?,'queued',1,0,?) ON DUPLICATE KEY UPDATE config=VALUES(config),generation=VALUES(generation),status='queued',next_page=1,processed=0,last_hash='',error='',next_run=VALUES(next_run)",[$feed['id'],$kind,StalkerCatalogues::config($feed),bin2hex(random_bytes(16)),time()]);
    }
    public static function publish(array $feed,string $kind,string $generation,array $rows): void {
        $changed=[];
        foreach($rows as $row)$changed[self::ingest($feed,$kind,json_decode($row['payload'],true),$row['category'],$generation)]=true;
        foreach(array_keys($changed) as $id)self::aggregate($id);
    }
    public static function reconcile(array $job): bool {
        $rows=self::query('SELECT item_id,catalogue_id FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND generation<>? LIMIT 100',[$job['feed_id'],$job['kind'],$job['generation']])->fetchAll();
        $changed=[];
        foreach($rows as $row){
            self::query('UPDATE vaultcast_stalker_offers SET available=0,generation=? WHERE feed_id=? AND kind=? AND item_id=?',[$job['generation'],$job['feed_id'],$job['kind'],$row['item_id']]);
            $changed[$row['catalogue_id']]=true;
        }
        foreach(array_keys($changed) as $id)self::aggregate($id);
        return !$rows;
    }
    /** Destructive initial setup is processed in bounded batches by the existing worker.
     * Do not delete the local-library index or user favourites/preferences.
     */
    private static function resetBatch(array $state,array $feeds): bool {
        $tables=['vaultcast_stalker_staging','vaultcast_stalker_folder_items','vaultcast_stalker_folders',
            'vaultcast_stalker_offers','vaultcast_stalker_facets','vaultcast_stalker_aliases',
            'vaultcast_stalker_library'];
        $index=max(0,(int)($state['table']??0));
        if($index<count($tables)){
            // Each step bounds locks and memory; no full-table TRUNCATE/implicit DDL commit.
            $deleted=self::query('DELETE FROM '.$tables[$index].' LIMIT 500')->rowCount();
            if($deleted===0){
                $state['table']=$index+1;
                \\Vaultcast\\Repository::putDocument('stalker-catalogue-reset',$state);
            }
            return true;
        }
        self::db()->beginTransaction();
        try {
            // The old catalogue is gone: missing items have no grace period.
            // Existing connection and folder selections remain unchanged.
            self::query('DELETE FROM vaultcast_stalker_jobs');
            foreach($feeds as $feed)foreach(['vod','series'] as $kind)self::queue($feed,$kind,true);
            \\Vaultcast\\Repository::putDocument('stalker-catalogue-reset',['state'=>'complete','finished'=>time()]);
            self::db()->commit();
        }catch(Throwable $e){if(self::db()->inTransaction())self::db()->rollBack();throw $e;}
        return true;
    }
    public static function tick(): bool {
        require_once STREAM_ROOT.'/src/Live/iptv.php';require_once STREAM_ROOT.'/src/Live/stalker.php';
        $feeds=[];foreach(iptv_load()['feeds']??[] as $f)if(($f['type']??'')==='stalker'&&!empty($f['enabled']))$feeds[$f['id']]=$f;
        $reset=\\Vaultcast\\Repository::document('stalker-catalogue-reset',['state'=>'idle']);
        if(($reset['state']??'idle')==='purging')return self::resetBatch($reset,$feeds);
        foreach(self::query('SELECT * FROM vaultcast_stalker_jobs') as $j){
            $f=$feeds[$j['feed_id']]??null;
            if(!$f){
                // Explicit removal/disable is confirmed loss, handled in bounded batches.
                $rows=self::query('SELECT item_id,catalogue_id FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND available=1 LIMIT 100',[$j['feed_id'],$j['kind']])->fetchAll();
                foreach($rows as $r){self::query('UPDATE vaultcast_stalker_offers SET available=0 WHERE feed_id=? AND kind=? AND item_id=?',[$j['feed_id'],$j['kind'],$r['item_id']]);self::aggregate($r['catalogue_id']);}
                self::query("UPDATE vaultcast_stalker_jobs SET status='removed' WHERE feed_id=? AND kind=?",[$j['feed_id'],$j['kind']]);
            }elseif(StalkerCatalogues::config($f)!==$j['config'])self::queue($f,$j['kind']);
            elseif(in_array($j['status'],['complete','removed'],true)&&(int)$j['next_run']<=time())self::queue($f,$j['kind']);
        }
        self::refreshLocal();self::expire();StalkerCatalogueScan::cleanup();
        $job=self::query("SELECT * FROM vaultcast_stalker_jobs WHERE status IN ('queued','running','partial','waiting') AND next_run<=? ORDER BY next_run,feed_id,kind LIMIT 1",[time()])->fetch();
        if(!$job)return false;
        $feed=$feeds[$job['feed_id']]??null;if(!$feed)return false;
        StalkerCatalogueScan::step($feed,$job);return true;
    }
    private static function expire(): void {
        $ids=self::query('SELECT c.id FROM vaultcast_stalker_library c WHERE c.available=0 AND c.unavailable_since<=? AND NOT EXISTS(SELECT 1 FROM vaultcast_stalker_offers s JOIN vaultcast_stalker_jobs j ON j.feed_id=s.feed_id AND j.kind=s.kind WHERE s.catalogue_id=c.id AND j.status NOT IN (\'complete\',\'removed\')) LIMIT 100',[time()-15*86400])->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id){self::db()->beginTransaction();try{$row=self::query('SELECT available,unavailable_since FROM vaultcast_stalker_library WHERE id=? FOR UPDATE',[$id])->fetch();if($row&&!$row['available']&&$row['unavailable_since']!==null&&(int)$row['unavailable_since']<=time()-15*86400){foreach(['vaultcast_stalker_offers','vaultcast_stalker_facets','vaultcast_stalker_aliases'] as $table)self::query('DELETE FROM '.$table.' WHERE catalogue_id=?',[$id]);self::query('DELETE FROM vaultcast_stalker_library WHERE id=?',[$id]);}self::db()->commit();}catch(Throwable $e){self::db()->rollBack();throw $e;}}
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
        $items=[];foreach($rows as $r){$m=json_decode($r['payload'],true);$m['catalogueId']=$r['id'];$m['sources']=$offers[$r['id']]??[];$m['available']=(bool)$r['available'];$m['verificationPending']=!$m['sources']&&($m['available']||$r['unavailable_since']===null);$m['local']=self::local($m,$kind);$m['unavailableSince']=$r['unavailable_since'];$items[]=$m;}
        $facets=[];foreach(self::query('SELECT f.facet,f.value,COUNT(*) AS count FROM vaultcast_stalker_facets f JOIN vaultcast_stalker_library c ON c.id=f.catalogue_id WHERE c.kind=? GROUP BY f.facet,f.value ORDER BY f.value',[$kind]) as $f)$facets[$f['facet']][]=['value'=>$f['value'],'count'=>(int)$f['count']];
        $years=self::query('SELECT DISTINCT year FROM vaultcast_stalker_library WHERE kind=? AND year IS NOT NULL ORDER BY year DESC',[$kind])->fetchAll(PDO::FETCH_COLUMN);
        return ['ok'=>true,'items'=>$items,'total'=>$total,'hasMore'=>$offset+count($items)<$total,'facets'=>$facets,'years'=>$years];
    }
}
