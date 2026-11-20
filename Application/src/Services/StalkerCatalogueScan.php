<?php
declare(strict_types=1);

/** One folder page or small publication batch per worker tick. No provider calls in transactions. */
final class StalkerCatalogueScan {
    private static function db(): PDO {return \Vaultcast\Database::connection();}
    private static function q(string $sql,array $args=[]): PDOStatement {$q=self::db()->prepare($sql);$q->execute($args);return $q;}
    private static function key(array $job): array {return [$job['feed_id'],$job['kind'],$job['generation']];}
    private static function locked(array $job): bool {
        $current=self::q('SELECT generation,status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE',[$job['feed_id'],$job['kind']])->fetch();
        return $current&&$current['generation']===$job['generation']&&in_array($current['status'],['queued','running','partial','waiting'],true);
    }
    /** Strict category parsing is shared with the browser and the diagnostic. */
    public static function groups(array $response): array {
        $rows=$response['data']??$response;
        if(!is_array($rows)||(!empty($response['error'])||($response['success']??true)===false||($response['status']??true)===false))throw new RuntimeException('Invalid provider folder response.');
        $out=[];
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id']))throw new RuntimeException('Invalid provider folder.');
            $id=(string)$row['id'];
            if(!preg_match('/^(?:[a-zA-Z0-9_-]{1,120}|\*)$/D',$id))throw new RuntimeException('Unsupported provider folder ID.');
            $name=$row['title']??$row['name']??$id;
            $out[$id]=is_scalar($name)?mb_substr(trim(strip_tags((string)$name)),0,500):$id;
        }
        // All may contain uncategorised entries, but never substitutes for real folders.
        $out['*']=$out['*']??'All';
        return $out;
    }
    public static function page(array $response): array {
        if((!empty($response['error'])||($response['success']??true)===false||($response['status']??true)===false)||!isset($response['data'])||!is_array($response['data']))throw new RuntimeException('Invalid provider page response.');
        $rows=$response['data'];$ids=[];
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id'])||(string)$row['id']===''||strlen((string)$row['id'])>120)throw new RuntimeException('Invalid provider item.');
            $id=(string)$row['id'];if(isset($ids[$id]))throw new RuntimeException('Repeated IDs in provider page.');$ids[$id]=true;
        }
        $total=null;
        if(array_key_exists('total_items',$response)){
            $value=$response['total_items'];
            if(!is_scalar($value)||!preg_match('/^\d{1,12}$/D',(string)$value))throw new RuntimeException('Invalid provider total.');
            $total=(int)$value;
        }
        return ['rows'=>$rows,'total'=>$total];
    }
    public static function step(array $feed,array $job): void {
        $key=self::key($job);$folder=null;
        try{
            // A new generation starts with a durable discovery task (empty ID is internal).
            self::q("INSERT IGNORE INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title) VALUES(?,?,?,'','Folder discovery')",$key);
            // Publish staged additions before requesting another provider page. This makes
            // successfully scanned titles visible while the scan is still running.
            // The generation check prevents stale/paused workers from publishing.
            $pending=self::q('SELECT item_id,category,payload FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? ORDER BY item_id LIMIT 50',$key)->fetchAll();
            if($pending){
                self::db()->beginTransaction();
                if(!self::locked($job)){self::db()->rollBack();return;}
                StalkerLibrary::publish($feed,$job['kind'],$job['generation'],$pending);
                self::deleteStaged($key,$pending);
                self::q("UPDATE vaultcast_stalker_jobs SET status='partial',last_hash='publish',error='',next_run=? WHERE feed_id=? AND kind=?",[time(),$job['feed_id'],$job['kind']]);
                self::db()->commit();
                return;
            }
            $folder=self::q("SELECT * FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND generation=? AND status NOT IN ('complete','skipped') ORDER BY folder_id='*',folder_id LIMIT 1",$key)->fetch();
            if($folder){
                if((int)$folder['next_run']>time())return;
                if($folder['folder_id']===''){
                    $groups=self::groups(stalker_call($feed,'get_categories',[],$job['kind']));
                    self::db()->beginTransaction();if(!self::locked($job)){self::db()->rollBack();return;}
                    $selection=StalkerCatalogues::folderSelection($feed['id'],$job['kind']);
                    foreach($groups as $id=>$title){
                        $state=StalkerCatalogues::folderSelected($selection,(string)$id)?'pending':'skipped';
                        self::q('INSERT IGNORE INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title,status) VALUES(?,?,?,?,?,?)',array_merge($key,[(string)$id,$title,$state]));
                    }
                    self::q("UPDATE vaultcast_stalker_folders SET status='complete',last_success=?,error='',failures=0 WHERE feed_id=? AND kind=? AND generation=? AND folder_id=''",array_merge([time()],$key));
                    self::q("UPDATE vaultcast_stalker_jobs SET status='running',last_hash='scan',processed=0,error='',next_run=? WHERE feed_id=? AND kind=?",[time(),$job['feed_id'],$job['kind']]);
                    self::db()->commit();return;
                }
                if((int)$folder['next_page']>10000)throw new RuntimeException('Folder page safety limit reached.');
                $result=stalker_ordered_page($feed,$job['kind'],['sortby'=>'name','category'=>$folder['folder_id'],'search'=>''],(int)$folder['next_page']);
                $page=self::page($result);$rows=$page['rows'];$total=$page['total'];
                // Totals are advisory until an explicit terminal page is reached. A changed
                // total makes absence unproven; keep the checkpoint for intervention/retry.
                if($folder['total']!==null&&$total!==null&&(int)$folder['total']!==$total&&!(!$rows&&$total===0))throw new RuntimeException('Provider folder total changed during enumeration. Refresh this connection.');
                // Some portals report zero on an out-of-range terminal page. Retain
                // the last advertised total so a premature empty page still fails.
                $total=(!$rows&&$folder['total']!==null)?(int)$folder['total']:($total??($folder['total']===null?null:(int)$folder['total']));
                $count=(int)$folder['processed']+count($rows);
                if(!$rows&&($total!==null&&$count<$total))throw new RuntimeException('Provider stopped before the advertised folder total.');
                if(!$rows&&$count===0&&$total!==0)throw new RuntimeException('Empty folder without an explicit zero total; absence is unverified.');
                self::db()->beginTransaction();if(!self::locked($job)){self::db()->rollBack();return;}
                $added=self::stagePage($key,$folder,$rows);
                self::q('UPDATE vaultcast_stalker_folders SET status=?,next_page=next_page+1,processed=?,total=?,last_success=?,error=\'\',failures=0,next_run=0 WHERE feed_id=? AND kind=? AND generation=? AND folder_id=?',array_merge([$rows?'partial':'complete',$count,$total,time()],$key,[$folder['folder_id']]));
                self::q("UPDATE vaultcast_stalker_jobs SET status='partial',processed=processed+?,next_run=?,error='' WHERE feed_id=? AND kind=?",[$added,time(),$job['feed_id'],$job['kind']]);
                self::db()->commit();return;
            }
            if($job['kind']==='series'&&self::importNextEpisodePage($feed,$job))return;
            // All folders have succeeded. Publication and reconciliation are resumable,
            // bounded transactions; a failed replacement never removes the previous offers.
            $completed=false;
            self::db()->beginTransaction();if(!self::locked($job)){self::db()->rollBack();return;}
            $rows=self::q('SELECT item_id,category,payload FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? ORDER BY item_id LIMIT 50',$key)->fetchAll();
            if($rows){
                StalkerLibrary::publish($feed,$job['kind'],$job['generation'],$rows);
                self::deleteStaged($key,$rows);
                self::q("UPDATE vaultcast_stalker_jobs SET status='partial',last_hash='publish',next_page=1,error='',next_run=? WHERE feed_id=? AND kind=?",[time(),$job['feed_id'],$job['kind']]);
            }elseif(StalkerCatalogues::folderSelection($feed['id'],$job['kind'])['mode']==='include'||StalkerLibrary::reconcile($job)){
                // A filtered scan cannot prove missing titles are gone: excluded
                // folders may still contain them, including duplicate folder entries.
                // Preserve all prior offers unless an unfiltered scan verifies loss.
                $phase=$job['kind']==='series'?'episodes_complete':'complete';
                self::q("UPDATE vaultcast_stalker_jobs SET status='complete',last_hash=?,error='',next_run=0 WHERE feed_id=? AND kind=?",[$phase,$job['feed_id'],$job['kind']]);
                $completed=true;
            }
            self::db()->commit();
            if($completed)StalkerLibrary::refreshFilters($job['kind'],true);
        }catch(Throwable $e){
            if(self::db()->inTransaction())self::db()->rollBack();
            self::db()->beginTransaction();
            try{
                if(self::locked($job)){
                    $failures=$folder?(int)$folder['failures']+1:max(1,(int)$job['next_page']);
                    $delay=min(3600,60*(2**min(6,$failures-1)))+random_int(0,30);
                    $rateLimited=$e instanceof StalkerRateLimitException;
                    $retryAt=$rateLimited?$e->retryAt:time()+$delay;
                    $failed=!$rateLimited&&($e instanceof StalkerAuthenticationException||$failures>=8);
                    $message=$rateLimited?'HTTP 429; connection requests paused. Checkpoint retained.':($e instanceof StalkerAuthenticationException?'Authentication rejected; check this connection.':'Folder/page could not be verified. Checkpoint retained; refresh this connection if provider contents changed.');
                    if($e->getCode()>=400&&$e->getCode()<=599&&!($e instanceof StalkerRateLimitException))$message='Provider HTTP '.(int)$e->getCode().'; checkpoint retained.';
                    if($e instanceof RuntimeException&&in_array($e->getMessage(),[
                        'Invalid provider folder response.','Invalid provider folder.','Unsupported provider folder ID.',
                        'Invalid provider page response.','Invalid provider item.','Repeated IDs in provider page.',
                        'Invalid provider total.','Folder page safety limit reached.',
                        'Provider folder total changed during enumeration. Refresh this connection.',
                        'Provider stopped before the advertised folder total.',
                        'Empty folder without an explicit zero total; absence is unverified.',
                        'Overlapping provider folder pages; completeness is unverified.'
                    ],true))$message=$e->getMessage();
                    if($folder)self::q('UPDATE vaultcast_stalker_folders SET status=?,error=?,failures=?,next_run=? WHERE feed_id=? AND kind=? AND generation=? AND folder_id=?',array_merge([$failed?'failed':'waiting',$message,$rateLimited?(int)$folder['failures']:$failures,$retryAt],$key,[$folder['folder_id']]));
                    self::q('UPDATE vaultcast_stalker_jobs SET status=?,error=?,next_run=?,next_page=? WHERE feed_id=? AND kind=?',[$failed?'failed':'waiting',$message,$retryAt,$rateLimited?(int)$job['next_page']:$failures+1,$job['feed_id'],$job['kind']]);
                    if($rateLimited){
                        \Vaultcast\Repository::cachePut(stalker_cooldown_key($feed),['until'=>$retryAt],max(60,$retryAt-time()));
                        self::q("UPDATE vaultcast_stalker_jobs SET next_run=GREATEST(next_run,?) WHERE feed_id=? AND config=? AND status IN ('queued','running','partial','waiting')",[$retryAt,$job['feed_id'],$job['config']]);
                    }
                }
                self::db()->commit();
            }catch(Throwable $failure){if(self::db()->inTransaction())self::db()->rollBack();throw $failure;}
        }
    }
    private static function stagePage(array $key,array $folder,array $rows): int {
        $added=0;
        $args=[];
        $bytes=0;
        $flush=static function() use (&$args,&$added,&$bytes): void {
            if(!$args)return;
            $values=implode(',',array_fill(0,intdiv(count($args),6),'(?,?,?,?,?,?)'));
            $added+=self::q('INSERT IGNORE INTO vaultcast_stalker_staging(feed_id,kind,generation,item_id,category,payload) VALUES '.$values,$args)->rowCount();
            $args=[];
            $bytes=0;
        };
        foreach(array_chunk($rows,100) as $chunk){
            $members=[];
            foreach($chunk as $row)array_push($members,...array_merge($key,[$folder['folder_id'],(string)$row['id']]));
            $values=implode(',',array_fill(0,count($chunk),'(?,?,?,?,?)'));
            if(self::q('INSERT IGNORE INTO vaultcast_stalker_folder_items(feed_id,kind,generation,folder_id,item_id) VALUES '.$values,$members)->rowCount()!==count($chunk)){
                throw new RuntimeException('Overlapping provider folder pages; completeness is unverified.');
            }
            foreach($chunk as $row){
                $category=$row['category_name']??$row['category_title']??$folder['title'];
                $category=is_scalar($category)?mb_substr(trim(strip_tags((string)$category)),0,500):$folder['title'];
                $payload=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                if($args && (count($args)>=600 || $bytes+strlen($payload)>512*1024))$flush();
                array_push($args,...array_merge($key,[(string)$row['id'],$category,$payload]));
                $bytes+=strlen($payload);
            }
        }
        $flush();
        return $added;
    }

    private static function deleteStaged(array $key,array $rows): void {
        $ids=array_column($rows,'item_id');
        self::q('DELETE FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? AND item_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_merge($key,$ids));
    }

    private static function importNextEpisodePage(array $feed,array $job): bool {
        $key='stalker-episode-import-'.hash('sha256',$job['feed_id']);
        $cursor=\Vaultcast\Repository::document($key,[]);
        if(($cursor['generation']??null)!==$job['generation'])$cursor=['generation'=>$job['generation'],'after'=>'','parent'=>'','page'=>1,'root'=>'','children'=>[],'visited'=>[]];
        $parent=$cursor['parent'];
        if($parent===''){
            $parent=self::q("SELECT item_id FROM vaultcast_stalker_offers WHERE feed_id=? AND kind='series' AND item_id>? AND generation=? AND available=1 ORDER BY item_id LIMIT 1",[$job['feed_id'],$cursor['after'],$job['generation']])->fetchColumn();
            if($parent===false)return false;
            $cursor['root']=$parent;
            $cursor['children']=[];
            $cursor['visited']=[(string)$parent];
        }
        if($cursor['page']>10000)throw new RuntimeException('Episode page safety limit reached.');
        $result=StalkerDirect::importEpisodes($feed,(string)$parent,(int)$cursor['page'],(string)$cursor['root']);
        foreach($result['items'] as $item){
            $id=(string)$item['id'];
            if(!empty($item['hasChildren'])&&!in_array($id,$cursor['visited'],true)){
                $cursor['visited'][]=$id;
                $cursor['children'][]=$id;
            }
        }
        if(count($cursor['visited'])>1000)throw new RuntimeException('Series folder safety limit reached.');
        \Vaultcast\Database::transaction(static function () use ($job,$key,$cursor,$parent,$result): void {
            if(!self::locked($job))return;
            if($result['hasMore']){
                $cursor['parent']=$parent;
                $cursor['page']++;
            }else{
                $cursor['parent']=array_shift($cursor['children'])??'';
                $cursor['page']=1;
                if($cursor['parent']==='')$cursor['after']=$cursor['root'];
            }
            \Vaultcast\Repository::putDocument($key,$cursor);
            self::q("UPDATE vaultcast_stalker_jobs SET status='partial',last_hash='episodes',next_page=1,next_run=?,error='' WHERE feed_id=? AND kind='series'",[time(),$job['feed_id']]);
        });
        return true;
    }
    public static function cleanup(): void {
        // Obsolete generations are disposable staging only, never catalogue associations.
        foreach(self::q('SELECT feed_id,kind,generation,status FROM vaultcast_stalker_jobs') as $job){
            foreach(['vaultcast_stalker_staging','vaultcast_stalker_folder_items','vaultcast_stalker_folders'] as $table){
                self::q('DELETE FROM '.$table.' WHERE feed_id=? AND kind=?'.($job['status']==='removed'?'':' AND generation<>?').' LIMIT 100',$job['status']==='removed'?[$job['feed_id'],$job['kind']]:self::key($job));
            }
        }
    }
}
