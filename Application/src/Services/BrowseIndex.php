<?php
declare(strict_types=1);

/** Durable, incrementally maintained browsing projections. No probes or network work. */
final class BrowseIndex
{
    private static $ready;
    private static $types;
    private static $scheduled=false;
    private static function query(string $sql,array $args=[]): PDOStatement {$q=\Vaultcast\Database::connection()->prepare($sql);$q->execute($args);return $q;}
    public static function ready(): bool {
        if (self::$ready===null) self::$ready=(bool)\Vaultcast\Repository::document('browse-index-ready',false);
        return self::$ready;
    }
    private static function projection(array $it): array {
        if(self::$types===null){self::$types=[];foreach(\Vaultcast\Repository::document('catalogue',['sections'=>[]])['sections'] ?? [] as $section)self::$types[$section['id']]=$section['type'] ?? '';}
        $type=(string)(($it['type'] ?? '') ?: (self::$types[$it['section']] ?? ''));$name='';
        if (in_array($type,['tv','anime'],true)) {
            $name=(string)($it['show'] ?? '');
            if ($name==='') $name=explode('/',str_replace('\\','/',(string)($it['rel'] ?? '')))[0] ?: (string)($it['title'] ?? 'Unknown');
        }
        return ['item_id'=>(string)$it['id'],'section_id'=>(string)$it['section'],'media_kind'=>(string)($it['media_kind'] ?? ''),'type'=>$type,
            'title'=>(string)($it['title'] ?? ''),'search_path'=>(string)($it['rel'] ?? ''),'show_key'=>$name!==''?Catalogue::showKey((string)$it['section'],$name):'',
            'show_name'=>$name,'season'=>isset($it['season'])&&$it['season']!==''?(int)$it['season']:null,'episode'=>isset($it['episode'])&&$it['episode']!==''?(int)$it['episode']:null,'year'=>!empty($it['year'])?(int)$it['year']:null,
            'system_name'=>trim((string)($it['system'] ?? '')),'modified_at'=>(int)($it['mtime'] ?? 0),'source_sig'=>hash('sha256',\Vaultcast\Repository::encode($it).'|'.$type)];
    }
    private static function saveProjection(array $p): void {
        $fields=array_keys($p);$updates=implode(',',array_map(static function($f){return "$f=VALUES($f)";},array_slice($fields,1)));
        self::query('INSERT INTO vaultcast_browse_items ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).') ON DUPLICATE KEY UPDATE '.$updates,array_values($p));
    }
    /** Called in the catalogue replacement transaction. Only changed shows are invalidated. */
    public static function sync(array $items,array $sections): void {
        self::$types=[];foreach($sections as $section)self::$types[$section['id']]=$section['type'] ?? '';
        $initial=!self::ready();
        $old=[];foreach (self::query('SELECT item_id,show_key,source_sig FROM vaultcast_browse_items') as $r) $old[$r['item_id']]=$r;
        $dirty=[];
        foreach ($items as $it) {
            $p=self::projection($it);$prior=$old[$p['item_id']] ?? null;unset($old[$p['item_id']]);
            if ($initial && $p['show_key']!=='') $dirty[$p['show_key']]=true;
            if ($prior && $prior['source_sig']===$p['source_sig']) continue;
            self::saveProjection($p);
            if ($prior && $prior['show_key']!=='') $dirty[$prior['show_key']]=true;
            if ($p['show_key']!=='') $dirty[$p['show_key']]=true;
        }
        foreach (array_chunk(array_keys($old),200) as $batch) self::query('DELETE FROM vaultcast_browse_items WHERE item_id IN ('.implode(',',array_fill(0,count($batch),'?')).')',$batch);
        foreach ($old as $r) if ($r['show_key']!=='') $dirty[$r['show_key']]=true;
        foreach (array_keys($dirty) as $key) self::seed($key);
        \Vaultcast\Repository::putDocument('browse-index-ready',true);self::$ready=true;
    }
    private static function pack(array $r): array {
        $count=(int)$r['available_count'];
        return ['id'=>$r['show_key'],'show_key'=>$r['show_key'],'kind'=>'show','section'=>$r['section_id'],'type'=>$r['type'],'title'=>$r['show_name'],
            'year'=>!empty($r['year'])?(int)$r['year']:null,'episode_count'=>$count,'available_count'=>$count,'missing_count'=>null,
            'season_count'=>(int)$r['season_count'],'has_poster'=>true,'local_poster'=>null,'show_dir'=>null,'mtime'=>(int)$r['mtime'],
            'genres'=>[],'status'=>null,'network'=>null,'_meta_prepared'=>true];
    }
    private static function aggregates(string $source,string $where,array $args): array {
        return self::query("SELECT b.show_key,b.section_id,MIN(b.show_name) AS show_name,MIN(b.type) AS type,MIN(b.item_id) AS sample_id,MIN(b.year) AS year,COUNT(*) AS available_count,COUNT(DISTINCT b.season) AS season_count,MAX(b.modified_at) AS mtime FROM $source b WHERE b.show_key<>'' AND $where GROUP BY b.show_key,b.section_id",$args)->fetchAll();
    }
    private static function seed(string $key): void {
        $rows=self::aggregates('vaultcast_browse_items','b.show_key=?',[$key]);
        if (!$rows) {self::query('DELETE FROM vaultcast_show_summaries WHERE show_key=?',[$key]);return;}
        $r=$rows[0];$base=self::pack($r);$sample=Catalogue::item($r['sample_id']);
        if($sample){$base['show_dir']=Catalogue::showDirFromItem($sample,false);$base['local_poster']=$sample['show_poster'] ?? $sample['poster'] ?? null;}
        $old=self::query('SELECT payload FROM vaultcast_show_summaries WHERE show_key=?',[$key])->fetchColumn();
        // Existing enriched information remains available until the worker replaces it.
        $payload=$old?json_decode((string)$old,true):$base;
        foreach (['title','section','type','available_count','mtime'] as $field) $payload[$field]=$base[$field];
        if(!empty($base['show_dir']))$payload['show_dir']=$base['show_dir'];if(!empty($base['local_poster']))$payload['local_poster']=$base['local_poster'];
        self::query('INSERT INTO vaultcast_show_summaries (show_key,section_id,title,type,source_sig,payload) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE section_id=VALUES(section_id),title=VALUES(title),type=VALUES(type),source_sig=VALUES(source_sig),payload=VALUES(payload)',[$key,$r['section_id'],$r['show_name'],$r['type'],bin2hex(random_bytes(16)),\Vaultcast\Repository::encode($payload)]);
    }
    public static function dirty(string $key): void {
        self::query('UPDATE vaultcast_show_summaries SET source_sig=? WHERE show_key=?',[bin2hex(random_bytes(16)),$key]);
        self::schedule();
    }
    /** One initial backfill, with bounded reads and durable progress; never in a page request. */
    private static function backfill(): void {
        if (self::ready()) return;
        $state=\Vaultcast\Repository::document('browse-index-backfill',[]);$cursor=(string)($state['cursor'] ?? '');
        do {
            Maintenance::checkpoint();
            $count=\Vaultcast\Database::transaction(static function() use (&$cursor): int {
                self::query("SELECT name FROM vaultcast_documents WHERE name='catalogue' FOR UPDATE");
                if (\Vaultcast\Repository::document('browse-index-ready',false)) {self::$ready=true;return 0;}
                $rows=self::query('SELECT id,payload FROM vaultcast_items WHERE id>? ORDER BY id LIMIT 200',[$cursor])->fetchAll();self::$types=null;
                foreach ($rows as $r) {
                    $p=self::projection(json_decode($r['payload'],true));self::saveProjection($p);$cursor=$r['id'];
                }
                \Vaultcast\Repository::putDocument('browse-index-backfill',['cursor'=>$cursor]);return count($rows);
            });
        } while ($count>0);
        $keys=self::query("SELECT DISTINCT show_key FROM vaultcast_browse_items WHERE show_key<>''")->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_chunk($keys,25) as $batch) {
            Maintenance::checkpoint();
            \Vaultcast\Database::transaction(static function() use ($batch): void {
                self::query("SELECT name FROM vaultcast_documents WHERE name='catalogue' FOR UPDATE");
                if (\Vaultcast\Repository::document('browse-index-ready',false)) return;
                foreach ($batch as $key) self::seed($key);
            });
        }
        \Vaultcast\Database::transaction(static function(): void {
            self::query("SELECT name FROM vaultcast_documents WHERE name='catalogue' FOR UPDATE");
            self::query('DELETE b FROM vaultcast_browse_items b LEFT JOIN vaultcast_items i ON i.id=b.item_id WHERE i.id IS NULL');
            self::query('DELETE s FROM vaultcast_show_summaries s LEFT JOIN vaultcast_browse_items b ON b.show_key=s.show_key WHERE b.item_id IS NULL');
            \Vaultcast\Repository::putDocument('browse-index-ready',true);self::$ready=true;
        });
    }
    public static function warm(): void {
        if (PHP_SAPI!=='cli') return;
        self::backfill();$changed=[];
        do {
            $rows=self::query('SELECT show_key,section_id,source_sig FROM vaultcast_show_summaries WHERE source_sig<>prepared_sig ORDER BY show_key LIMIT 25')->fetchAll();
            foreach ($rows as $r) {
                Maintenance::checkpoint();$summary=Catalogue::prepareShowSummary($r['section_id'],$r['show_key']);
                \Vaultcast\Database::transaction(static function() use ($summary,$r,&$changed): void {
                    $written=$summary ? self::query('UPDATE vaultcast_show_summaries SET payload=?,prepared_sig=? WHERE show_key=? AND source_sig=?',[\Vaultcast\Repository::encode($summary),$r['source_sig'],$r['show_key'],$r['source_sig']]) : self::query('DELETE FROM vaultcast_show_summaries WHERE show_key=? AND source_sig=?',[$r['show_key'],$r['source_sig']]);
                    if($written->rowCount()){$changed[$r['section_id']]=true;\Vaultcast\Repository::putDocument('show-summary:'.$r['section_id'],time());\Vaultcast\Repository::putDocument('show-summaries',time());}
                });
            }
        } while ($rows);
        foreach(array_keys($changed) as $section){Maintenance::checkpoint();Catalogue::warmShowViews($section);}
        if($changed)Catalogue::homeLibrary();
    }
    public static function schedule(): void {
        if (self::$scheduled || PHP_SAPI==='cli') return;self::$scheduled=true;
        register_shutdown_function(static function(): void {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            $dir=STREAM_DATA.'/runtime';if (!is_dir($dir)&&!@mkdir($dir,0775,true)) return;
            $lock=@fopen($dir.'/browse-queue.lock','c');if (!$lock) return;
            try {
                if (!flock($lock,LOCK_EX|LOCK_NB)) return;
                $stamp=$dir.'/browse-queue.json';$last=json_decode((string)@file_get_contents($stamp),true) ?: [];
                if (time()-(int)($last['at'] ?? 0)<60) return;
                // Reuse an already queued/running worker rather than spawning another waiting process.
                $active=self::query("SELECT name FROM vaultcast_documents WHERE name LIKE 'maintenance-job:%' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.task')) IN ('browse','views','search') AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.status')) IN ('queued','running') LIMIT 1")->fetchColumn();
                if ($active) return;
                try {Maintenance::start('browse','Prepare search and show summaries');@file_put_contents($stamp,json_encode(['at'=>time()]),LOCK_EX);}catch(Throwable $e){error_log('Vaultcast browse preparation: '.$e->getMessage());}
            } finally {flock($lock,LOCK_UN);fclose($lock);}
        });
    }
    /** Cold fallback groups narrow SQL fields; it never fetches whole episode payloads. */
    private static function source(): string {
        if (self::ready()) return 'vaultcast_browse_items';
        self::schedule();$tv=[];$types='';$pdo=\Vaultcast\Database::connection();foreach (\Vaultcast\Repository::document('catalogue',['sections'=>[]])['sections'] ?? [] as $s){if(in_array($s['type'] ?? '',['tv','anime'],true))$tv[]=(string)$s['id'];$types.=' WHEN '.$pdo->quote((string)$s['id']).' THEN '.$pdo->quote((string)($s['type'] ?? ''));}$types=$types!==''?"CASE i.section_id $types ELSE '' END":"''";
        $scope=$tv?'i.section_id IN ('.implode(',',array_map([\Vaultcast\Database::connection(),'quote'],$tv)).')':'0=1';
        $rel="COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.rel')),'null'),'')";
        $name="COALESCE(NULLIF(i.show_title,''),NULLIF(SUBSTRING_INDEX(REPLACE($rel,CHAR(92),'/'),'/',1),''),i.title)";
        return "(SELECT i.id AS item_id,i.section_id,i.media_kind,COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.type')),'null'),''),$types) AS type,i.title,$rel AS search_path,CASE WHEN $scope THEN LEFT(SHA1(CONCAT(i.section_id,'|',$name)),16) ELSE '' END AS show_key,CASE WHEN $scope THEN $name ELSE '' END AS show_name,CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.season')),'null') AS SIGNED) AS season,CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.episode')),'null') AS SIGNED) AS episode,CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.year')),'null') AS SIGNED) AS year,i.system_name,i.modified_at FROM vaultcast_items i)";
    }
    private static function coldRows(): array {
        $revision=\Vaultcast\Repository::revision('catalogue');$cached=\Vaultcast\Repository::cacheGet('browse-cold-summary-v1');
        if ($cached && ($cached['revision'] ?? -1)===$revision) return $cached['rows'];
        $rows=[];foreach(self::aggregates(self::source(),'1=1',[]) as $r) $rows[$r['show_key']]=$r;
        \Vaultcast\Repository::cachePut('browse-cold-summary-v1',['revision'=>$revision,'rows'=>$rows],120);return $rows;
    }
    public static function firstShows(string $section,int $limit=8): array {
        if(!self::ready())return array_slice(self::shows($section),0,$limit);
        $out=[];foreach(self::query('SELECT payload FROM vaultcast_show_summaries WHERE section_id=? ORDER BY title,show_key LIMIT '.max(1,min(48,$limit)),[$section]) as $r)$out[]=json_decode($r['payload'],true);return $out;
    }
    public static function shows(string $section): array {
        if (self::ready()) {
            $rows=self::query('SELECT payload,(source_sig<>prepared_sig) AS dirty FROM vaultcast_show_summaries WHERE section_id=? ORDER BY title,show_key',[$section])->fetchAll();$out=[];$dirty=false;
            foreach ($rows as $r) {$out[]=json_decode($r['payload'],true);$dirty=$dirty||$r['dirty'];}if($dirty) self::schedule();return $out;
        }
        return array_values(array_map([self::class,'pack'],array_filter(self::coldRows(),static function($r)use($section){return $r['section_id']===$section;})));
    }
    private static function summaryMap(array $keys): array {
        if (!$keys) return [];$out=[];$marks=implode(',',array_fill(0,count($keys),'?'));
        if (self::ready()) {
            foreach(self::query('SELECT show_key,payload,(source_sig<>prepared_sig) AS dirty FROM vaultcast_show_summaries WHERE show_key IN ('.$marks.')',$keys) as $r){$out[$r['show_key']]=json_decode($r['payload'],true);if($r['dirty'])self::schedule();}
        } else { $all=self::coldRows();foreach($keys as $key)if(isset($all[$key]))$out[$key]=self::pack($all[$key]); }
        return $out;
    }
    public static function summary(string $key): ?array {
        if (self::ready()) {
            $r=self::query('SELECT payload,(source_sig<>prepared_sig) AS dirty FROM vaultcast_show_summaries WHERE show_key=?',[$key])->fetch();
            if (!$r) return null;if($r['dirty'])self::schedule();return json_decode($r['payload'],true);
        }
        $rows=self::coldRows();if(!isset($rows[$key]))return null;$card=self::pack($rows[$key]);
        $sample=Catalogue::item($rows[$key]['sample_id']);if($sample){$card['show_dir']=Catalogue::showDirFromItem($sample,false);$card['local_poster']=$sample['show_poster'] ?? $sample['poster'] ?? null;}return $card;
    }
    public static function items(string $key): array {
        return \Vaultcast\Repository::rows('SELECT i.payload FROM vaultcast_items i JOIN '.self::source().' b ON b.item_id=i.id WHERE b.show_key=? ORDER BY i.title,i.id',[$key]);
    }
    public static function search(string $query,int $limit,int $page,array $viewer,bool $games=false,bool $suggest=false): array {
        $query=mb_substr(trim($query),0,200);$limit=max(1,min(100,$limit));$page=max(1,min(1000,$page));
        $empty=['ok'=>true,'query'=>$query,'catalogue'=>[],'count'=>0,'total'=>0,'weak'=>true,'page'=>$page,'has_more'=>false];if($query==='')return $empty;
        $sections=[];foreach (\Vaultcast\Repository::document('catalogue',['sections'=>[]])['sections'] ?? [] as $s) {
            $area=($s['type'] ?? '')==='roms'?'games':'library';if(AreaAccess::allowed($viewer,$area)&&(!$games||$area==='games'))$sections[]=(string)$s['id'];
        }
        if(!$sections)return $empty;
        $source=self::source();$scope='b.section_id IN ('.implode(',',array_fill(0,count($sections),'?')).')';
        $needle='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$prefix=substr($needle,1);
        $show="SELECT b.show_key AS id,'show' AS kind,MIN(b.show_name) AS title,MIN(b.section_id) AS section,MIN(b.type) AS type,'show' AS media_kind,'' AS system_name,MIN(b.year) AS year,CASE WHEN MIN(b.show_name)=? THEN 100 WHEN MIN(b.show_name) LIKE ? ESCAPE '!' THEN 80 ELSE 50 END AS score,'show' AS match_kind FROM $source b WHERE $scope AND b.show_key<>'' AND b.show_name LIKE ? ESCAPE '!' GROUP BY b.show_key";
        if (self::ready()) $show="SELECT b.show_key AS id,'show' AS kind,b.title,b.section_id AS section,b.type,'show' AS media_kind,'' AS system_name,CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(b.payload,'$.year')),'null') AS SIGNED) AS year,CASE WHEN b.title=? THEN 100 WHEN b.title LIKE ? ESCAPE '!' THEN 80 ELSE 50 END AS score,'show' AS match_kind FROM vaultcast_show_summaries b WHERE $scope AND b.title LIKE ? ESCAPE '!'";
        $showArgs=array_merge([$query,$prefix],$sections,[$needle]);
        $item="SELECT b.item_id AS id,'item' AS kind,b.title,b.section_id AS section,b.type,b.media_kind,b.system_name AS system_name,b.year,CASE WHEN b.show_key<>'' THEN 30 WHEN b.title=? THEN 95 WHEN b.title LIKE ? ESCAPE '!' THEN 75 ELSE 45 END AS score,CASE WHEN b.show_key<>'' THEN 'episode' ELSE 'item' END AS match_kind FROM $source b WHERE $scope AND ((b.show_key='' AND (b.title LIKE ? ESCAPE '!' OR b.search_path LIKE ? ESCAPE '!')) OR (b.show_key<>'' AND b.show_name NOT LIKE ? ESCAPE '!' AND b.title LIKE ? ESCAPE '!'))";
        $itemArgs=array_merge([$query,$prefix],$sections,[$needle,$needle,$needle,$needle]);
        $union=$show.' UNION ALL '.$item;$args=array_merge($showArgs,$itemArgs);$offset=($page-1)*$limit;
        $hits=self::query('SELECT * FROM ('.$union.') ranked ORDER BY score DESC,title,id LIMIT '.($limit+1).' OFFSET '.$offset,$args)->fetchAll();
        $more=count($hits)>$limit;$hits=array_slice($hits,0,$limit);
        if($suggest) {
            foreach($hits as &$h){if($h['kind']==='show')$h['show_key']=$h['id'];$h['system']=$h['system_name'];unset($h['system_name'],$h['score'],$h['match_kind']);}unset($h);
            return array_merge($empty,['catalogue'=>$hits,'count'=>count($hits),'has_more'=>$more]);
        }
        $total=(int)self::query('SELECT COUNT(*) FROM ('.$union.') ranked',$args)->fetchColumn();
        $ids=array_column(array_filter($hits,static function($h){return $h['kind']==='item';}),'id');$items=[];
        if($ids)foreach(self::query('SELECT id,payload FROM vaultcast_items WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids) as $r)$items[$r['id']]=json_decode($r['payload'],true);
        MediaFacts::preload(array_values($items));$cards=[];
        $showKeys=array_column(array_filter($hits,static function($h){return $h['kind']==='show';}),'id');$shows=self::summaryMap($showKeys);
        foreach($hits as $hit){
            $raw=$hit['kind']==='show'?($shows[$hit['id']] ?? null):($items[$hit['id']] ?? null);if(!$raw)continue;
            $card=$hit['kind']==='show'?Catalogue::publicShow($raw):Catalogue::publicItem($raw,false);$card['match']=$hit['match_kind'];$card['score']=(int)$hit['score'];$cards[]=$card;
        }
        return array_merge($empty,['catalogue'=>$cards,'count'=>count($cards),'total'=>$total,'has_more'=>$more,'weak'=>$total===0||($total<3&&($cards[0]['score'] ?? 0)<80)]);
    }
}
