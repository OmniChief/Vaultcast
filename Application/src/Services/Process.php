<?php
declare(strict_types=1);

/** Native process capture without sending media paths through a shell. */
final class Process {
    public static function startPhpWorker(string $command,string $id): int {
        if(!function_exists('proc_open'))throw new RuntimeException('PHP proc_open is disabled. Enable it for the web PHP service to start playback workers.');
        $configured=trim(trim((string)(Store::settings()['tools']['php_cli'] ?? '')), "\"'");
        $binary=PHP_OS_FAMILY==='Windows'?'php.exe':'php';
        $candidates=[$configured,dirname(PHP_BINARY).'/'.$binary,PHP_BINDIR.'/'.$binary];
        foreach(explode(PATH_SEPARATOR,(string)getenv('PATH')) as $dir)if($dir!=='')$candidates[]=rtrim($dir,'/\\').'/'.$binary;
        $php='';
        foreach(array_unique($candidates) as $candidate){
            if($candidate===''||!is_file($candidate)||(PHP_OS_FAMILY!=='Windows'&&!is_executable($candidate)))continue;
            // A CGI/FPM binary cannot run Console.php, even when it exists on disk.
            $sapi=self::capture([$candidate,'-r','echo PHP_SAPI;'],5);
            if(trim((string)$sapi)==='cli'){$php=$candidate;break;}
        }
        if($php==='')throw new RuntimeException('No usable PHP CLI executable was found. Set the full path to php'.(PHP_OS_FAMILY==='Windows'?'.exe':'').' in Advanced settings (not php-cgi or php-fpm).');
        $args=[];if(php_ini_loaded_file())array_push($args,'-c',php_ini_loaded_file());
        array_push($args,STREAM_ROOT.'/src/Console.php',$command,$id);
        if(PHP_OS_FAMILY==='Windows') {
            $quote=static function($v){return "'".str_replace("'","''",$v)."'";};
            $quoted=array_map(static function($v)use($quote){return $quote('"'.$v.'"');},$args);
            $pid=self::powershell('$ErrorActionPreference="Stop"; $p=Start-Process -WindowStyle Hidden -FilePath '.$quote($php).' -WorkingDirectory '.$quote(STREAM_ROOT).' -RedirectStandardOutput '.$quote(STREAM_DATA.'/transcode-'.$id.'.log').' -RedirectStandardError '.$quote(STREAM_DATA.'/transcode-'.$id.'.error.log').' -ArgumentList @('.implode(',',$quoted).') -PassThru; $p.Id');
        } else {
            if(!function_exists('shell_exec'))throw new RuntimeException('PHP shell_exec is disabled. Enable it for the web PHP service to start playback workers.');
            $pid=shell_exec('nohup '.escapeshellarg($php).' '.implode(' ',array_map('escapeshellarg',$args)).' >> '.escapeshellarg(STREAM_DATA.'/transcode-worker.log').' 2>&1 < /dev/null & echo $!');
        }
        if((int)$pid<=0)throw new RuntimeException('Could not launch the PHP playback worker. Check process permissions'.(PHP_OS_FAMILY==='Windows'?' and that powershell.exe is available to the PHP service.':'.'));
        return (int)$pid;
    }
    public static function powershell(string $script): ?string {
        return self::capture(['powershell.exe','-NoProfile','-NonInteractive','-EncodedCommand',base64_encode(mb_convert_encoding($script,'UTF-16LE','UTF-8'))], 10);
    }

    public static function windowsConversion(array $job, string $options, int $threads = 1): int {
        $arguments = array_merge(['-nostdin','-y'], Convert::threadOptions(max(1, $threads), 'copy')['input'], ['-progress',$job['progress_file'],'-nostats','-i',$job['src']], preg_split('/\s+/',trim($options)), [$job['part']]);
        $spec = base64_encode(json_encode(['ffmpeg'=>Remux::ffmpegBin(),'arguments'=>$arguments,'log'=>$job['log'],'part'=>$job['part'],'dest'=>$job['dest'],'exit'=>$job['exit_file']]));
        $worker = '$ErrorActionPreference="Stop"; $s=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $spec . '")) | ConvertFrom-Json; try { $arguments=@($s.arguments | ForEach-Object {[string]$_}); & $s.ffmpeg @arguments *> $s.log; $code=$LASTEXITCODE; if ($code -eq 0) { Move-Item -LiteralPath $s.part -Destination $s.dest }; Set-Content -LiteralPath $s.exit -Value $code -Encoding ASCII } catch { Set-Content -LiteralPath $s.exit -Value 1 -Encoding ASCII }';
        $encoded = base64_encode(mb_convert_encoding($worker,'UTF-16LE','UTF-8'));
        $pid = self::powershell('$p=Start-Process powershell.exe -ArgumentList @("-NoProfile","-NonInteractive","-EncodedCommand","' . $encoded . '") -WindowStyle Hidden -PassThru; $p.Id');
        return (int)trim((string)$pid);
    }

    public static function capture(array $arguments, int $timeout): ?string {
        if (!function_exists('proc_open')) return null;
        $output = tempnam(STREAM_SHM, 'process_');
        if ($output === false) return null;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($arguments, [0=>['file',$null,'r'],1=>['file',$output,'w'],2=>['file',$null,'w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) { unlink($output); return null; }
        $deadline = microtime(true) + max(1, $timeout);
        $timedOut = false;
        do {
            $state = proc_get_status($process);
            if (!$state['running']) break;
            if (microtime(true) >= $deadline) { $timedOut = true; proc_terminate($process, 9); break; }
            usleep(50000);
        } while (true);
        proc_close($process);
        $result = !$timedOut && filesize($output) <= 4194304 ? file_get_contents($output) : false;
        unlink($output);
        return is_string($result) ? $result : null;
    }
}
