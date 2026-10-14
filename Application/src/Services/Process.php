<?php
declare(strict_types=1);

/** Native process capture without sending media paths through a shell. */
final class Process {
    public static function powershell(string $script): ?string {
        return self::capture(['powershell.exe','-NoProfile','-NonInteractive','-EncodedCommand',base64_encode(mb_convert_encoding($script,'UTF-16LE','UTF-8'))], 10);
    }

    public static function windowsConversion(array $job, string $options, int $threads = 1): int {
        $arguments = array_merge(['-nostdin','-y'], Convert::threadOptions(max(1, $threads), 'copy')['input'], ['-progress',$job['progress_file'],'-nostats','-i',$job['src']], preg_split('/\s+/',trim($options)), [$job['part']]);
        $spec = base64_encode(json_encode(['arguments'=>$arguments,'log'=>$job['log'],'part'=>$job['part'],'dest'=>$job['dest'],'exit'=>$job['exit_file']]));
        $worker = '$ErrorActionPreference="Stop"; $s=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $spec . '")) | ConvertFrom-Json; try { $arguments=@($s.arguments | ForEach-Object {[string]$_}); & ffmpeg.exe @arguments *> $s.log; $code=$LASTEXITCODE; if ($code -eq 0) { Move-Item -LiteralPath $s.part -Destination $s.dest -Force }; Set-Content -LiteralPath $s.exit -Value $code -Encoding ASCII } catch { Set-Content -LiteralPath $s.exit -Value 1 -Encoding ASCII }';
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
