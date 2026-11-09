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
                foreach($pending as $item)self::q('DELETE FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? AND item_id=?',array_merge($key,[$item['item_id']]));
                self::q("UPDATE vaultcast_stalker_jobs SET status='partial',last_hash='publish',error='',next_run=? WHERE feed_id=? AND kind=?",[time(),$job['feed_id'],$job['kind']]);
                self::db()->commit();
                return;
            }
            $folder=self::q("SELECT * FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND generation=? AND status<>'complete' ORDER BY folder_id='*',folder_id LIMIT 1",$key)->fetch();
            if($folder){
                if((int)$folder['next_run']>time())return;
                if($folder['folder_id']===''){
                    $groups=self::groups(stalker_call($feed,'get_categories',[],$job['kind']));
                    self::db()->beginTransaction();if(!self::locked($job)){self::db()->rollBack();return;}
                    foreach($groups as $id=>$title)self::q('INSERT IGNORE INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title) VALUES(?,?,?,?,?)',array_merge($key,[(string)$id,$title]));
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
                $added=0;
                foreach($rows as $row){
                    if(!self::q('INSERT IGNORE INTO vaultcast_stalker_folder_items(feed_id,kind,generation,folder_id,item_id) VALUES(?,?,?,?,?)',array_merge($key,[$folder['folder_id'],(string)$row['id']]))->rowCount())throw new RuntimeException('Overlapping provider folder pages; completeness is unverified.');
                    $category=$row['category_name']??$row['category_title']??$folder['title'];
                    $category=is_scalar($category)?mb_substr(trim(strip_tags((string)$category)),0,500):$folder['title'];
                    // A provider item can legitimately occur in several folders. Stage once.
                    $added+=self::q('INSERT IGNORE INTO vaultcast_stalker_staging(feed_id,kind,generation,item_id,category,payload) VALUES(?,?,?,?,?,?)',array_merge($key,[(string)$row['id'],$category,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]))->rowCount();
                }
                self::q('UPDATE vaultcast_stalker_folders SET status=?,next_page=next_page+1,processed=?,total=?,last_success=?,error=\'\',failures=0,next_run=0 WHERE feed_id=? AND kind=? AND generation=? AND folder_id=?',array_merge([$rows?'partial':'complete',$count,$total,time()],$key,[$folder['folder_id']]));
                self::q("UPDATE vaultcast_stalker_jobs SET status='partial',processed=processed+?,next_run=?,error='' WHERE feed_id=? AND kind=?",[$added,time(),$job['feed_id'],$job['kind']]);
                self::db()->commit();return;
            }
            // All folders have succeeded. Publication and reconciliation are resumable,
            // bounded transactions; a failed replacement never removes the previous offers.
            self::db()->beginTransaction();if(!self::locked($job)){self::db()->rollBack();return;}
            $rows=self::q('SELECT item_id,category,payload FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? ORDER BY item_id LIMIT 50',$key)->fetchAll();
            if($rows){
                StalkerLibrary::publish($feed,$job['kind'],$job['generation'],$rows);
                foreach($rows as $row)self::q('DELETE FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? AND item_id=?',array_merge($key,[$row['item_id']]));
                self::q("UPDATE vaultcast_stalker_jobs SET status='partial',last_hash='publish',next_page=1,error='',next_run=? WHERE feed_id=? AND kind=?",[time(),$job['feed_id'],$job['kind']]);
            }elseif(StalkerLibrary::reconcile($job)){
                self::q("UPDATE vaultcast_stalker_jobs SET status='complete',last_hash='complete',error='',next_run=? WHERE feed_id=? AND kind=?",[time()+86400,$job['feed_id'],$job['kind']]);
            }
            self::db()->commit();
        }catch(Throwable $e){
            if(self::db()->inTransaction())self::db()->rollBack();
            self::db()->beginTransaction();
            try{
                if(self::locked($job)){
                    $failures=$folder?(int)$folder['failures']+1:max(1,(int)$job['next_page']);
                    $delay=min(3600,60*(2**min(6,$failures-1)))+random_int(0,30);
                    $retryAt=max(time()+$delay,$e instanceof StalkerRateLimitException?$e->retryAt:0);
                    $failed=$e instanceof StalkerAuthenticationException||$failures>=8;
                    $message=$e instanceof StalkerRateLimitException?'HTTP 429; connection requests paused. Checkpoint retained.':($e instanceof StalkerAuthenticationException?'Authentication rejected; check this connection.':'Folder/page could not be verified. Checkpoint retained; refresh this connection if provider contents changed.');
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
                    if($folder)self::q('UPDATE vaultcast_stalker_folders SET status=?,error=?,failures=?,next_run=? WHERE feed_id=? AND kind=? AND generation=? AND folder_id=?',array_merge([$failed?'failed':'waiting',$message,$failures,$retryAt],$key,[$folder['folder_id']]));
                    self::q('UPDATE vaultcast_stalker_jobs SET status=?,error=?,next_run=?,next_page=? WHERE feed_id=? AND kind=?',[$failed?'failed':'waiting',$message,$retryAt,$failures+1,$job['feed_id'],$job['kind']]);
                    if($e instanceof StalkerRateLimitException){
                        \Vaultcast\Repository::cachePut(stalker_cooldown_key($feed),['until'=>$retryAt],max(60,$retryAt-time()));
                        self::q('UPDATE vaultcast_stalker_jobs SET next_run=GREATEST(next_run,?) WHERE feed_id=?',[$retryAt,$job['feed_id']]);
                    }
                }
                self::db()->commit();
            }catch(Throwable $failure){if(self::db()->inTransaction())self::db()->rollBack();throw $failure;}
        }
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
