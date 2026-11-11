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
    public static function terminatePhpWorker(int $pid,string $command,string $id): bool {
        if($pid<2||!preg_match('/^[a-z0-9-]{1,40}$/D',$command)||!preg_match('/^[a-f0-9]{24}$/D',$id))return false;
        if(PHP_OS_FAMILY==='Windows'){
            $script='$p=Get-CimInstance Win32_Process -Filter "ProcessId = '.$pid.'"; if($p -and $p.CommandLine.Contains("Console.php") -and $p.CommandLine.Contains("'.$command.'") -and $p.CommandLine.Contains("'.$id.'")){Stop-Process -Id '.$pid.' -Force; "stopped"}';
            return trim((string)self::powershell($script))==='stopped';
        }
        $cmdline=@file_get_contents('/proc/'.$pid.'/cmdline');
        if(!is_string($cmdline)||strpos($cmdline,'Console.php')===false||strpos($cmdline,$command)===false||strpos($cmdline,$id)===false)return false;
        if(function_exists('posix_kill'))return @posix_kill($pid,defined('SIGTERM')?SIGTERM:15);
        return false;
    }
    public static function powershell(string $script): ?string {
        return self::capture(['powershell.exe','-NoProfile','-NonInteractive','-EncodedCommand',base64_encode(mb_convert_encoding($script,'UTF-16LE','UTF-8'))], 10);
    }

    public static function windowsConversion(array $job, string $options, int $threads = 1): int {
        $arguments = array_merge(['-nostdin','-y'], Convert::threadOptions(max(1, $threads), 'copy')['input'], ['-progress',$job['progress_file'],'-nostats','-i',$job['src']], preg_split('/\s+/',trim($options)), [$job['part']]);
        $encodedJob = json_encode(['ffmpeg'=>Remux::ffmpegBin(),'arguments'=>$arguments,'log'=>$job['log'],'part'=>$job['part'],'dest'=>$job['dest'],'exit'=>$job['exit_file']], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encodedJob === false) return 0;
        $spec = base64_encode($encodedJob);
        $node = self::nodeBinary();
        $script = STREAM_ROOT . '/src/Workers/convert.js';
        if ($node !== null && is_file($script)) {
            $quote = static function (string $value): string { return "'" . str_replace("'", "''", $value) . "'"; };
            $pid = self::powershell('$ErrorActionPreference="Stop"; $p=Start-Process -FilePath ' . $quote($node)
                . ' -ArgumentList @(' . $quote('"' . $script . '"') . ',' . $quote($spec)
                . ') -WindowStyle Hidden -PassThru; $p.Id');
            return (int)trim((string)$pid);
        }

        $worker = '$ErrorActionPreference="Stop"; $s=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $spec . '")) | ConvertFrom-Json; try { $arguments=@($s.arguments | ForEach-Object {[string]$_}); & $s.ffmpeg @arguments *> $s.log; $code=$LASTEXITCODE; if ($code -eq 0) { Move-Item -LiteralPath $s.part -Destination $s.dest }; Set-Content -LiteralPath $s.exit -Value $code -Encoding ASCII } catch { Set-Content -LiteralPath $s.exit -Value 1 -Encoding ASCII }';
        $encoded = base64_encode(mb_convert_encoding($worker,'UTF-16LE','UTF-8'));
        $pid = self::powershell('$p=Start-Process powershell.exe -ArgumentList @("-NoProfile","-NonInteractive","-EncodedCommand","' . $encoded . '") -WindowStyle Hidden -PassThru; $p.Id');
        return (int)trim((string)$pid);
    }

    public static function nodeBinary(): ?string {
        $windows = PHP_OS_FAMILY === 'Windows';
        $binary = $windows ? 'node.exe' : 'node';
        $candidates = $windows
            ? array_merge([STREAM_DATA . '/runtime/node/node.exe'], glob(STREAM_DATA . '/runtime/node-*-win-*/node.exe') ?: [])
            : [dirname(STREAM_ROOT) . '/node/bin/node'];
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
            if ($directory !== '') $candidates[] = rtrim($directory, '/\\') . '/' . $binary;
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && ($windows || is_executable($candidate))) return $candidate;
        }
        return null;
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
