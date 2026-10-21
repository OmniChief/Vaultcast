<?php
declare(strict_types=1);

/** Library-only, bounded HLS workers. HTTP requests serve prepared fragments, not ffmpeg pipes. */
final class Transcode {
    public const PRESETS = ['ultrafast','superfast','veryfast','faster','fast','medium','slow','slower','veryslow'];
    public static function defaults(): array {
        return ['enabled'=>false,'mode'=>'fallback','threads'=>1,'max_sessions'=>1,'crf'=>20,'preset'=>'veryfast',
            'audio_bitrate'=>'192k','audio_channels'=>'2','max_height'=>0,'max_bitrate'=>0,'segment_seconds'=>4,
            'startup_seconds'=>90,'idle_seconds'=>120,'cache_hours'=>6,'cache_gb'=>10,'allow_save'=>true];
    }
    public static function settings(): array {
        $s = Store::settings()['transcoding'] ?? [];
        $s = array_replace(self::defaults(), is_array($s) ? $s : []);
        $s['threads'] = Convert::sanitizeThreadCount($s['threads']);
        return $s;
    }
    public static function allowed(): bool {
        return (Store::settings()['streaming']['directplay_only'] ?? true) === false && !empty(self::settings()['enabled']);
    }
    public static function validate(array $input): array {
        $rules = ['mode'=>['fallback','always'],'preset'=>self::PRESETS,'audio_bitrate'=>['96k','128k','160k','192k','256k','320k'],
            'audio_channels'=>['source','2'],'max_height'=>[0,480,720,1080,1440,2160],
            'max_bitrate'=>[0,1000,2000,4000,8000,12000,20000,40000],'segment_seconds'=>[2,4,6]];
        $ranges = ['threads'=>[1,Convert::cpuCount()],'max_sessions'=>[1,4],'crf'=>[14,28],
            'startup_seconds'=>[10,120],'idle_seconds'=>[30,600],'cache_hours'=>[1,72],'cache_gb'=>[1,100]];
        $out = [];
        foreach ($input as $key=>$value) {
            if (in_array($key,['enabled','allow_save'],true)) {
                if (!is_bool($value)) throw new InvalidArgumentException('Choose on or off for ' . $key . '.');
            } elseif (isset($rules[$key])) {
                if (!in_array($value,$rules[$key],true)) throw new InvalidArgumentException('Choose a supported ' . str_replace('_',' ',$key) . '.');
            } elseif (isset($ranges[$key])) {
                if (!is_int($value) || $value<$ranges[$key][0] || $value>$ranges[$key][1]) throw new InvalidArgumentException($key . ' must be between ' . implode(' and ',$ranges[$key]) . '.');
            } else throw new InvalidArgumentException('Unknown transcoding option: ' . $key);
            $out[$key]=$value;
        }
        return $out;
    }
    public static function eligible(array $item): bool {
        return ($item['media_kind'] ?? '')==='video' && in_array($item['type'] ?? '',['movies','disney','tv','anime'],true)
            && !preg_match('#^[a-z]+://#i',(string)($item['path'] ?? ''));
    }
    private static function root(): string {
        $dir=STREAM_DATA.'/transcode'; if(!is_dir($dir))@mkdir($dir,0775,true); return $dir;
    }
    private static function dir(string $id): string {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid playback session.');
        return self::root().'/'.$id;
    }
    private static function read(string $id): array {
        $file=self::dir($id).'/session.json';
        return is_file($file) ? (json_decode((string)file_get_contents($file),true) ?: []) : [];
    }
    private static function write(array $job): void {
        $path=self::dir($job['id']).'/session.json'; $tmp=$path.'.tmp';
        if(file_put_contents($tmp,json_encode($job,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false || !@rename($tmp,$path))throw new RuntimeException('Could not save playback session.');
    }
    public static function start(array $user,string $itemId,array $body): array {
        if(!self::allowed())throw new DomainException('Playback transcoding is disabled in DirectPlay Settings.');
        $item=Catalogue::item($itemId);
        if(!$item || !self::eligible($item))throw new DomainException('Only local library movies and episodes can be transcoded.');
        if(!AreaAccess::allowed($user,'library'))throw new DomainException('Library access is required.');
        $config=self::settings(); $save=!empty($body['save']);
        if(array_key_exists('save',$body) && !is_bool($body['save']))throw new InvalidArgumentException('Choose whether to save the completed copy.');
        if(!$save)$item=Stream::playbackItem($item);
        $src=\Vaultcast\Paths::real((string)$item['path']);
        if(!$src || !is_file($src))throw new DomainException('The source file is unavailable.');
        if(!LibraryDelete::rootFor($src))throw new DomainException('Choose a file inside an enabled library folder.');
        if($save && (($user['role'] ?? '')!=='admin' || empty($config['allow_save'])))throw new DomainException('Saving a DirectPlay copy requires administrator access and must be enabled in settings.');
        if($save && preg_match('/\.directplay\.[^.]+$/i',$src))throw new DomainException('This is already a prepared DirectPlay copy.');
        if($save && (!is_writable(dirname($src)) || is_file(Convert::destFor($src))))throw new DomainException('A copy already exists, or the source folder is not writable.');
        $lock=fopen(self::root().'/queue.lock','c');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new RuntimeException('The playback queue is busy; retry shortly.');}
        try {
            if(!empty($body['replace'])) {
                $previous=self::read((string)$body['replace']);
                if(!$previous || !hash_equals((string)$previous['token'],(string)($body['replace_token'] ?? '')) || ($previous['owner'] ?? '')!==(string)$user['id'] || $previous['save'])throw new DomainException('This playback worker cannot be replaced.');
                $previousDir=self::dir($previous['id']);@touch($previousDir.'/cancel');
                $deadline=microtime(true)+3;
                do {
                    $worker=@fopen($previousDir.'/worker.lock','c');
                    $released=$worker && flock($worker,LOCK_EX|LOCK_NB);
                    if($worker){if($released)flock($worker,LOCK_UN);fclose($worker);}
                    if($released)break;
                    usleep(100000);
                } while(microtime(true)<$deadline);
                if(!$released)throw new RuntimeException('The previous worker is stopping. Retry playback shortly.');
            }
            self::cleanup(); $active=0;
            foreach(glob(self::root().'/*/session.json') ?: [] as $file) {
                $job=json_decode((string)@file_get_contents($file),true) ?: [];
                if(in_array($job['status'] ?? '',['queued','running','saving'],true) && !is_file(dirname($file).'/cancel') && time()-(int)($job['updated_at'] ?? 0)<150) {
                    if(($job['owner'] ?? '')===(string)$user['id'] && ($job['request_token'] ?? '')===(string)($body['t'] ?? '') && ($job['item_id'] ?? '')===$itemId && (bool)$job['save']===$save
                        && (int)$job['audio']===(int)($body['audio'] ?? 0) && abs((float)$job['offset']-($save?0.0:(float)($body['start'] ?? 0)))<.1)return self::publicJob($job);
                    $active++;
                }
            }
            if($active>=$config['max_sessions'])throw new RuntimeException('All transcoding slots are in use. Try DirectPlay or wait for a stream to finish.');
            $offset=$save ? 0.0 : Remux::startSeconds($body['start'] ?? 0);
            $audio=filter_var($body['audio'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>99]]);
            if($audio===false)throw new InvalidArgumentException('Choose a valid audio track.');
            $id=bin2hex(random_bytes(16));@mkdir(self::dir($id),0775,true);
            $token=Stream::createToken((string)$user['id'],$itemId,$src,'video','library');
            $job=['id'=>$id,'item_id'=>$itemId,'title'=>$item['title'],'section'=>$item['section'],'src'=>$src,'token'=>$token,'owner'=>(string)$user['id'],'request_token'=>(string)($body['t'] ?? ''),
                'save'=>$save,'offset'=>$offset,'audio'=>$audio,'config'=>$config,'status'=>'queued','created_at'=>time(),'updated_at'=>time(),
                'source_stat'=>['size'=>(int)filesize($src),'mtime'=>(int)filemtime($src)],'error'=>null];
            self::write($job);@touch(self::dir($id).'/access');
            try {Process::startPhpWorker('transcode',$id);} catch(Throwable $e){error_log('Vaultcast playback worker: '.$e->getMessage());$job['status']='failed';$job['error']=$e instanceof RuntimeException?$e->getMessage():'Could not start the PHP playback worker. Check the server PHP error log for details.';self::write($job);throw new RuntimeException($job['error']);}
            return self::publicJob($job);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function activeFor(string $itemId,string $owner): ?array {
        foreach(glob(self::root().'/*/session.json') ?: [] as $file){
            $job=json_decode((string)@file_get_contents($file),true) ?: [];
            if(($job['item_id'] ?? '')===$itemId && ($job['owner'] ?? '')===$owner && in_array($job['status'] ?? '',['queued','running','saving'],true)
                && !is_file(dirname($file).'/cancel') && time()-(int)($job['updated_at'] ?? 0)<150)return self::publicJob($job);
        }
        return null;
    }
    private static function publicJob(array $job): array {
        $base=\Vaultcast\Database::basePath().'api/stream/transcode?t='.rawurlencode($job['token']).'&s='.$job['id'].'&file=';
        $playlist=self::dir($job['id']).'/index.m3u8';
        $text=is_file($playlist)?(string)@file_get_contents($playlist):'';
        // A single fragment is too little runway when encoding is slower than playback.
        $ready=preg_match_all('/^segment\d{6,}\.ts\s*$/m',$text)>=3 || (strpos($text,'#EXT-X-ENDLIST')!==false && strpos($text,'#EXTINF:')!==false);
        return ['ok'=>true,'id'=>$job['id'],'token'=>$job['token'],'status'=>$job['status'],'offset'=>$job['offset'],'save'=>$job['save'],'ready'=>$ready,
            'duration'=>$job['duration'] ?? 0,'error'=>$job['error'] ?? null,'url'=>$base.'index.m3u8','status_url'=>$base.'status',
            'stop_url'=>$base.'stop','startup_seconds'=>$job['config']['startup_seconds']];
    }
    public static function serve(): void {
        $token=(string)($_GET['t'] ?? '');$payload=preg_match('/^[a-f0-9]{48}$/D',$token)?\Vaultcast\Repository::playbackToken($token):null;
        if(!$payload || ($payload['area'] ?? '')!=='library')json_error('Playback token expired.',403);
        $job=self::read((string)($_GET['s'] ?? ''));
        if(!$job || !hash_equals((string)$job['token'],$token) || $job['item_id']!==($payload['item_id'] ?? ''))json_error('Unknown playback session.',403);
        $file=(string)($_GET['file'] ?? '');$dir=self::dir($job['id']);
        if($file==='stop') {
            if(($_SERVER['REQUEST_METHOD'] ?? '')!=='POST')json_error('POST required.',405);
            if(!$job['save'])@touch($dir.'/cancel');
            json_out(['ok'=>true,'continues'=>$job['save']]);
        }
        if(!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','HEAD'],true))json_error('GET required.',405);
        @touch($dir.'/access');
        if($file==='status')json_out(self::publicJob($job));
        if($file!=='index.m3u8' && !preg_match('/^segment\d{6,}\.ts$/D',$file))json_error('Unknown media fragment.',404);
        $path=$dir.'/'.$file;
        if(!is_file($path))json_error($job['error'] ?: 'Preparing video…',in_array($job['status'],['failed','cancelled'],true)?502:404);
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        if($file==='index.m3u8') {
            $body=(string)file_get_contents($path);$base=\Vaultcast\Database::basePath().'api/stream/transcode?t='.rawurlencode($token).'&s='.$job['id'].'&file=';
            $body=str_replace("\r\n","\n",$body);
            $body=preg_replace_callback('/^segment\d{6,}\.ts$/m',static function($m)use($base){return $base.$m[0];},$body);
            header('Content-Type: application/vnd.apple.mpegurl');
            header('Content-Length: '.strlen($body));
            header('X-Accel-Buffering: no');
            \Vaultcast\Database::releaseForStreaming();
            if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='HEAD')echo $body;
            exit;
        }
        \Vaultcast\Database::releaseForStreaming();Stream::sendFile($path,'video/mp2t');
    }
    public static function run(string $id): array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Playback worker is CLI only.');
        $job=self::read($id);if(($job['status'] ?? '')!=='queued')throw new RuntimeException('Playback job is no longer queued.');
        $dir=self::dir($id);$lock=fopen($dir.'/worker.lock','c');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Playback worker already running.');
        $part=null;
        try {
            if(is_file($dir.'/cancel'))throw new RuntimeException('Playback conversion stopped.');
            if(!self::allowed())throw new RuntimeException('Transcoding was disabled before this worker started.');
            $c=$job['config'];$job['status']='running';$job['updated_at']=time();self::write($job);
            $probe=MediaProbe::probeSummary($job['src'],8,true);$job['duration']=(float)($probe['duration'] ?? 0);
            if($job['duration']<=0)throw new RuntimeException('Could not read the source duration; the source was left unchanged.');
            if(!empty($probe['audio_tracks']) && $job['audio']>=count($probe['audio_tracks']))throw new RuntimeException('The selected audio track is unavailable.');
            $args=[Remux::ffmpegBin(),'-hide_banner','-loglevel','error','-nostdin','-y'];
            $args=array_merge($args,Convert::threadOptions((int)$c['threads'],'copy')['input']);
            if($job['offset']>0)array_push($args,'-ss',sprintf('%.3F',$job['offset']));
            array_push($args,'-i',$job['src'],'-map','0:v:0','-map','0:a:'.$job['audio'].'?','-c:v','libx264',
                '-preset',$c['preset'],'-crf',(string)$c['crf'],'-pix_fmt','yuv420p');
            $args=array_merge($args,Convert::threadOptions((int)$c['threads'],'libx264')['encoder']);
            $height=(int)$c['max_height'];$scale=$height>0?'scale=-2:trunc(min(ih\,'.$height.')/2)*2':'scale=trunc(iw/2)*2:trunc(ih/2)*2';
            array_push($args,'-vf',$scale,'-c:a','aac','-b:a',$c['audio_bitrate'],'-threads:a',(string)$c['threads']);
            if($c['audio_channels']==='2')array_push($args,'-ac','2');
            if($c['max_bitrate']>0)array_push($args,'-maxrate',$c['max_bitrate'].'k','-bufsize',($c['max_bitrate']*2).'k');
            array_push($args,'-sn','-dn','-force_key_frames','expr:gte(t,n_forced*'.$c['segment_seconds'].')',
                '-f','hls','-hls_time',(string)$c['segment_seconds'],'-hls_playlist_type','event','-hls_flags','independent_segments+temp_file',
                '-hls_segment_filename',$dir.'/segment%06d.ts','-progress',$dir.'/progress','-nostats',$dir.'/index.m3u8');
            self::execute($args,$job);
            if(!is_file($dir.'/index.m3u8'))throw new RuntimeException('FFmpeg did not produce playable video. Original kept.');
            if($job['save']) {
                $job['status']='saving';self::write($job);$dest=Convert::destFor($job['src']);
                if(is_file($dest))throw new RuntimeException('Another conversion already produced a copy. It was not overwritten.');
                $part=dirname($dest).'/.'.basename($dest).'.'.$id.'.part';
                $job['output_part']=$part;
                $copy=[Remux::ffmpegBin(),'-hide_banner','-loglevel','error','-nostdin','-n','-i',$dir.'/index.m3u8',
                    '-map','0:v:0','-map','0:a?','-c','copy','-movflags','+faststart','-f','mp4','-progress',$dir.'/progress','-nostats',$part];
                self::execute($copy,$job);
                $length=MediaProbe::duration($part,10);
                if(!$length || abs($length-$job['duration'])>max(10,$job['duration']*.03))throw new RuntimeException('The completed copy did not pass the duration check. Original kept.');
                clearstatcache(true,$job['src']);
                if((int)@filesize($job['src'])!==$job['source_stat']['size'] || (int)@filemtime($job['src'])!==$job['source_stat']['mtime'])throw new RuntimeException('The original changed while converting. Original kept.');
                if(is_file($dest) || !@rename($part,$dest))throw new RuntimeException('Could not publish the finished copy. Original kept.');
                $part=null;$job['conversion']=Convert::adoptCompleted($job['item_id'],$job['src'],$dest,$job['section']);
            }
            $job['status']='done';$job['updated_at']=time();self::write($job);
        } catch(Throwable $e) {
            $job['status']=is_file($dir.'/cancel')?'cancelled':'failed';$job['error']=$e->getMessage();$job['updated_at']=time();self::write($job);
        } finally {
            if($part && is_file($part))@unlink($part);
            flock($lock,LOCK_UN);fclose($lock);
        }
        return ['ok'=>$job['status']==='done','status'=>$job['status']];
    }
    private static function execute(array $args,array &$job): void {
        $dir=self::dir($job['id']);$null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
        $proc=proc_open($args,[0=>['file',$null,'r'],1=>['file',$null,'w'],2=>['file',$dir.'/ffmpeg.log','a']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($proc))throw new RuntimeException('Could not start FFmpeg. Check the executable in Advanced settings.');
        \Vaultcast\Database::releaseForStreaming();
        register_shutdown_function(static function()use($proc){if(is_resource($proc)){$s=@proc_get_status($proc);if(!empty($s['running']))@proc_terminate($proc,9);}});
        $lastProgress=time();$lastText='';$checked=0;$lastHeartbeat=0;
        try {
            while(true) {
                $state=proc_get_status($proc);
                if(!$state['running']) {if($state['exitcode']!==0)throw new RuntimeException('FFmpeg could not prepare this video. Original kept; see the playback worker log.');break;}
                clearstatcache();$now=time();
                if(is_file($dir.'/cancel'))throw new RuntimeException('Playback conversion stopped.');
                if(!$job['save'] && $now-(int)@filemtime($dir.'/access')>$job['config']['idle_seconds'])throw new RuntimeException('Playback session became idle.');
                $progress='';$progressFile=@fopen($dir.'/progress','rb');
                if($progressFile){$stat=fstat($progressFile);fseek($progressFile,max(0,(int)($stat['size'] ?? 0)-8192));$progress=(string)stream_get_contents($progressFile,8192);fclose($progressFile);}
                if($progress!==$lastText){$lastText=$progress;$lastProgress=$now;}
                if($now-$lastProgress>180)throw new RuntimeException('FFmpeg stopped making progress. Original kept.');
                if($now-$checked>=5) {
                    $checked=$now;$bytes=0;foreach(glob($dir.'/*') ?: [] as $f)if(is_file($f))$bytes+=(int)@filesize($f);
                    if(!empty($job['output_part'])) {
                        $bytes+=(int)@filesize($job['output_part']);
                        if((float)@disk_free_space(dirname($job['output_part']))<268435456)throw new RuntimeException('The library disk is nearly full. Original kept.');
                    }
                    if($bytes>$job['config']['cache_gb']*1073741824 || (float)@disk_free_space($dir)<268435456)throw new RuntimeException('Temporary storage limit reached. Original kept.');
                    if(preg_match_all('/out_time_ms=(\d+)/',$progress,$m))$job['prepared_seconds']=round((float)end($m[1])/1000000,1);
                }
                if($now!==$lastHeartbeat){$lastHeartbeat=$now;$job['updated_at']=$now;self::write($job);}usleep(500000);
            }
        } finally {
            $state=proc_get_status($proc);if($state['running'])@proc_terminate($proc,9);@proc_close($proc);
        }
    }
    public static function cleanup(bool $force = false): int {
        $removed=0;
        foreach(glob(self::root().'/*/session.json') ?: [] as $file) {
            $job=json_decode((string)@file_get_contents($file),true) ?: []; $dir=dirname($file);
            $age=time()-(int)($job['updated_at'] ?? 0);
            $access=is_file($dir.'/access')?(int)filemtime($dir.'/access'):0;
            if((!$force && $age<max(300,(int)($job['config']['cache_hours'] ?? 6)*3600)) || time()-$access<300)continue;
            $lock=@fopen($dir.'/worker.lock','c');if(!$lock)continue;
            if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);continue;}
            foreach(glob($dir.'/*') ?: [] as $f)if(is_file($f) && basename($f)!=='worker.lock')@unlink($f);
            flock($lock,LOCK_UN);fclose($lock);@unlink($dir.'/worker.lock');if(@rmdir($dir))$removed++;
        }
        return $removed;
    }
    public static function status(): array {
        self::cleanup();$jobs=[];
        foreach(glob(self::root().'/*/session.json') ?: [] as $file) {
            $j=json_decode((string)@file_get_contents($file),true) ?: [];if(!$j)continue;
            $jobs[]=['id'=>$j['id'],'title'=>$j['title'],'status'=>$j['status'],'save'=>$j['save'],'threads'=>$j['config']['threads'],
                'prepared_seconds'=>$j['prepared_seconds'] ?? 0,'duration'=>$j['duration'] ?? 0,'offset'=>$j['offset'] ?? 0,
                'created_at'=>$j['created_at'] ?? 0,'updated_at'=>$j['updated_at'] ?? 0,
                'conversion_job_id'=>$j['conversion']['job_id'] ?? null,'error'=>$j['error'] ?? null];
        }
        usort($jobs,static function($a,$b){return $b['created_at']<=>$a['created_at'];});
        return ['ok'=>true,'sessions'=>$jobs];
    }
    public static function cancel(string $id): array {
        if(!self::read($id))throw new InvalidArgumentException('Playback worker not found.');
        @touch(self::dir($id).'/cancel');return ['ok'=>true];
    }
}
