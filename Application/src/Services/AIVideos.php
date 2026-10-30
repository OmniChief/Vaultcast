<?php
declare(strict_types=1);

/** Private, bounded AI-video queue. Upload bytes never hold a database transaction. */
final class AIVideos {
    private const MAX_BYTES = 67108864;
    private static function root(): string {
        $root=STREAM_DATA.'/ai-videos';
        if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new RuntimeException('Video storage is unavailable.');
        return $root;
    }
    private static function locked(string $id, callable $fn) {
        if (!preg_match('/^[a-f0-9]{16}$/D',$id)) throw new DomainException('Invalid video job.');
        $root=self::root(); $lock=fopen($root.'/'.$id.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Video queue is busy.');
        try {
            $file=$root.'/'.$id.'.json';
            $job=is_file($file) ? json_decode((string)file_get_contents($file),true) : null;
            $result=$fn($job,$root.'/'.$id);
            if (is_array($job)) {
                $tmp=$file.'.tmp';
                if (file_put_contents($tmp,json_encode($job,JSON_THROW_ON_ERROR))===false || !rename($tmp,$file)) throw new RuntimeException('Could not save video job.');
            }
            return $result;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private static function publicJob(array $job): array {
        return array_intersect_key($job,array_flip(['id','prompt','negative_prompt','width','height','frames','fps','steps','seed','status','progress','created_at','error']));
    }
    public static function state(array $user): array {
        Arcade::signed($user); $jobs=[];
        foreach (glob(self::root().'/*.json') ?: [] as $file) {
            $j=json_decode((string)file_get_contents($file),true);
            if (!is_array($j) || ($j['status'] ?? '')==='deleted' || ($j['user_id'] ?? '')!==(string)$user['id']) continue;
            $jobs[]=self::publicJob($j);
        }
        usort($jobs,static function($a,$b){return $b['created_at']<=>$a['created_at'];});
        return ['ok'=>true,'jobs'=>array_slice($jobs,0,100),'max_bytes'=>self::MAX_BYTES];
    }
    public static function create(array $user,array $body): array {
        Arcade::signed($user);
        $prompt=trim((string)($body['prompt'] ?? ''));
        if ($prompt==='' || mb_strlen($prompt)>2000) throw new DomainException('Describe your video using up to 2,000 characters.');
        $negative=mb_substr(trim((string)($body['negative_prompt'] ?? '')),0,2000);
        $size=(string)($body['size'] ?? '512x320');
        if (!in_array($size,['512x320','768x448'],true)) throw new DomainException('Choose a supported video size.');
        list($width,$height)=array_map('intval',explode('x',$size));
        $frames=(int)($body['frames'] ?? 49);
        if (!in_array($frames,[49,81,121],true)) throw new DomainException('Choose 2, 3.3 or 5 seconds.');
        $seed=$body['seed'] ?? null;
        if ($seed!==null && $seed!=='' && (filter_var($seed,FILTER_VALIDATE_INT)===false || $seed<0 || $seed>4294967295)) throw new DomainException('Seed must be between 0 and 4,294,967,295.');
        $queue=fopen(self::root().'/queue.lock','c');
        if (!$queue || !flock($queue,LOCK_EX)) throw new RuntimeException('Video queue is busy.');
        try {
            $jobs=self::state($user)['jobs'];
            $active=array_filter($jobs,static function($j){return in_array($j['status'],['queued','running'],true);});
            if (count($active)>=3 || count($jobs)>=100) throw new DomainException('Finish queued videos or delete old videos before adding more.');
            $id=bin2hex(random_bytes(8));
            $job=['id'=>$id,'user_id'=>(string)$user['id'],'prompt'=>$prompt,'negative_prompt'=>$negative,'width'=>$width,'height'=>$height,'frames'=>$frames,'fps'=>24,'steps'=>20,'seed'=>$seed===null || $seed==='' ? random_int(0,4294967295) : (int)$seed,'status'=>'queued','progress'=>0,'created_at'=>time()];
            self::locked($id,static function(&$stored) use($job){$stored=$job;});
            return ['ok'=>true,'job'=>self::publicJob($job)];
        } finally {flock($queue,LOCK_UN);fclose($queue);}
    }
    public static function userAction(array $user,string $id,string $action): array {
        Arcade::signed($user);
        return self::locked($id,static function(&$job,$base) use($user,$action){
            if (!$job || $job['user_id']!==(string)$user['id']) throw new DomainException('Video not found.');
            if ($action==='delete') {
                @unlink($base.'.mp4'); @unlink($base.'.upload');
                $job['status']='deleted'; $job['prompt']='';
            } elseif ($action==='cancel' && in_array($job['status'],['queued','running'],true)) {
                $job['status']='canceled'; @unlink($base.'.upload');
            }
            return ['ok'=>true];
        });
    }
    public static function serve(array $user,string $id): void {
        Arcade::signed($user);
        $file=self::locked($id,static function(&$job,$base) use($user){
            if (!$job || $job['user_id']!==(string)$user['id'] || $job['status']!=='completed') throw new DomainException('Video not found.');
            return $base.'.mp4';
        });
        if (!is_file($file)) json_error('Video file missing.',404);
        release_request_locks();
        $size=filesize($file);$start=0;$end=$size-1;
        header('Accept-Ranges: bytes');
        $range=(string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range!=='') {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$parts) || ($parts[1]==='' && $parts[2]==='')) {
                header('Content-Range: bytes */'.$size);http_response_code(416);exit;
            }
            if ($parts[1]==='') {$start=max(0,$size-(int)$parts[2]);}
            else {$start=(int)$parts[1];if ($parts[2]!=='') $end=min($end,(int)$parts[2]);}
            if ($start>$end || $start>=$size) {header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
            http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
        }
        header('Content-Type: video/mp4'); header('Content-Length: '.($end-$start+1));
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='HEAD') {
            $source=fopen($file,'rb');
            if (!$source) exit;
            fseek($source,$start);$remaining=$end-$start+1;
            while ($remaining>0 && !feof($source) && !connection_aborted()) {
                $block=fread($source,min(1048576,$remaining));
                if ($block===false || $block==='') break;
                echo $block;$remaining-=strlen($block);
            }
            fclose($source);
        }
        exit;
    }
    public static function worker(array $worker,string $action,string $id): array {
        $owner=Auth::findById((string)$worker['user_id']);
        if (!$owner) throw new DomainException('Contributor account unavailable.');
        Arcade::signed($owner);
        if (empty($worker['capabilities']['video']['verified'])) json_error('Configure an AI video worker first.',403);
        if ($action==='claim') {
            if (empty($worker['ready'])) return ['ok'=>true,'job'=>null];
            $files=glob(self::root().'/*.json') ?: [];
            usort($files,static function($a,$b) use($worker){
                $ja=json_decode((string)file_get_contents($a),true) ?: [];
                $jb=json_decode((string)file_get_contents($b),true) ?: [];
                $own=static function($j) use($worker){return ($j['status'] ?? '')==='running' && ($j['worker_id'] ?? '')===$worker['id'] && ($j['worker_slot'] ?? 0)===$worker['slot_id'];};
                return ((int)$own($jb)<=>(int)$own($ja)) ?: (($ja['created_at'] ?? 0)<=>($jb['created_at'] ?? 0));
            });
            foreach ($files as $file) {
                $candidate=basename($file,'.json');
                $result=self::locked($candidate,static function(&$job,$base) use($worker){
                    if (!$job) return null;
                    if ($job['status']==='running' && ($job['worker_id'] ?? '')===$worker['id'] && ($job['worker_slot'] ?? 0)===$worker['slot_id']) return array_merge(self::publicJob($job),['lease'=>$job['lease']]);
                    if ($job['status']!=='queued' && !($job['status']==='running' && (int)($job['updated_at'] ?? 0)<time()-180)) return null;
                    $user=Auth::findById($job['user_id']);
                    if (!$user || empty(Access::state($user)['active'])) return null;
                    $job['status']='running';$job['worker_id']=$worker['id'];$job['worker_slot']=$worker['slot_id'];
                    $job['lease']=bin2hex(random_bytes(16));$job['updated_at']=time();$job['progress']=1;
                    @unlink($base.'.upload');
                    return array_merge(self::publicJob($job),['lease'=>$job['lease']]);
                });
                if ($result) return ['ok'=>true,'job'=>$result];
            }
            return ['ok'=>true,'job'=>null];
        }
        $lease=(string)($_SERVER['HTTP_X_VAULTCAST_LEASE'] ?? '');
        return self::locked($id,static function(&$job,$base) use($worker,$action,$lease){
            if (!$job || !in_array($job['status'],['running','completed'],true) || ($job['worker_id'] ?? '')!==$worker['id'] || ($job['worker_slot'] ?? 0)!==$worker['slot_id'] || !hash_equals($job['lease'] ?? '',$lease)) json_error('Video lease expired.',409);
            $job['updated_at']=time();
            if ($action==='status') return ['ok'=>true,'offset'=>is_file($base.'.upload') ? filesize($base.'.upload') : 0,'completed'=>$job['status']==='completed'];
            if ($job['status']==='completed') return ['ok'=>true,'completed'=>true];
            if ($action==='progress') {$job['progress']=max(1,min(99,(int)(body_json()['progress'] ?? 1)));return ['ok'=>true];}
            if ($action==='fail') {$job['status']='failed';$job['error']=mb_substr((string)(body_json()['error'] ?? 'Generation failed'),0,300);return ['ok'=>true];}
            if ($action==='chunk') {
                $offset=filter_var($_SERVER['HTTP_X_VAULTCAST_OFFSET'] ?? '',FILTER_VALIDATE_INT);
                $size=is_file($base.'.upload') ? filesize($base.'.upload') : 0;
                if ($offset===false || $offset!==$size) return ['ok'=>true,'offset'=>$size];
                $bytes=file_get_contents('php://input',false,null,0,2097153);
                if ($bytes===false || strlen($bytes)>2097152 || $size+strlen($bytes)>self::MAX_BYTES) throw new DomainException('Video upload exceeds the allowed size.');
                $written=file_put_contents($base.'.upload',$bytes,FILE_APPEND);
                if ($written!==strlen($bytes)) throw new RuntimeException('Could not write the video upload.');
                return ['ok'=>true,'offset'=>$size+$written];
            }
            if ($action==='complete') {
                $body=body_json();$file=$base.'.upload';
                if (!is_file($file) || filesize($file)!==(int)($body['size'] ?? 0) || !hash_equals(hash_file('sha256',$file),(string)($body['sha256'] ?? ''))) throw new DomainException('Video upload integrity check failed.');
                $info=Process::capture([MediaProbe::ffprobeBin(),'-v','error','-show_streams','-show_format','-of','json',$file],20);
                $probe=json_decode((string)$info,true);
                $video=null;
                foreach ($probe['streams'] ?? [] as $stream) if (($stream['codec_type'] ?? '')==='video') {$video=$stream;break;}
                if (!$video || ($video['codec_name'] ?? '')!=='h264' || (int)$video['width']!==$job['width'] || (int)$video['height']!==$job['height'] || (float)($probe['format']['duration'] ?? 0)>6 || (float)($probe['format']['duration'] ?? 0)<=0) throw new DomainException('The generated video has an unsupported format or duration.');
                if (!rename($file,$base.'.mp4')) throw new RuntimeException('Could not save the generated video.');
                $job['status']='completed';$job['progress']=100;return ['ok'=>true];
            }
            throw new DomainException('Unknown video operation.');
        });
    }
}
