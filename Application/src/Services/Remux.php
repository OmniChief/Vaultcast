<?php
declare(strict_types=1);

/**
 * On-the-fly audio track switching. Browsers rarely expose audioTracks for DirectPlay files,
 * so the player asks for a fragmented-MP4 remux of the same file starting at the current
 * position, with the chosen audio track: video is copied, audio is copied when the browser
 * can decode it, otherwise converted to stereo AAC. One ffmpeg process per stream; it is
 * stopped when the player disconnects (track change, seek outside the buffer, close).
 */
final class Remux {
    /** Audio codecs browsers decode inside MP4; anything else is converted to AAC. */
    const BROWSER_AUDIO = ['aac', 'mp3', 'opus', 'flac'];

    /** Remuxing is server-side processing: allowed only when the admin turned "DirectPlay only" off. */
    public static function enabled(): bool {
        $st = Store::settings()['streaming'] ?? [];
        return is_array($st) && array_key_exists('directplay_only', $st) && $st['directplay_only'] === false && ($st['allow_remux'] ?? true) !== false;
    }

    /** Video keyframe times (seconds) between $from and $to, via ffprobe without decoding. */
    public static function keyframes(string $path, float $from, float $to): array {
        if (!function_exists('proc_open')) return [];
        $args = [MediaProbe::ffprobeBin(), '-v', 'error', '-select_streams', 'v:0', '-skip_frame', 'nokey',
            '-read_intervals', sprintf('%.3F%%%.3F', max(0.0, $from), $to), '-show_entries', 'frame=pts_time,best_effort_timestamp_time',
            '-of', 'csv=p=0', $path];
        $stat = @stat($path);
        $key = 'keyframes|' . hash('sha256', $path . '|' . ($stat['mtime'] ?? 0) . '|' . ($stat['size'] ?? 0)
            . '|' . sprintf('%.3F|%.3F', max(0.0, $from), $to));
        $cached = \Vaultcast\Repository::cacheGet($key);
        if ($cached !== null) return $cached['times'] ?? [];
        $out = Process::capture($args, 5);
        if ($out === null) return [];
        $times = [];
        foreach (preg_split('/\R/', $out) as $line) {
            foreach (explode(',', $line) as $v) { if (is_numeric($v)) { $times[] = (float)$v; break; } }
        }
        sort($times);
        $times = array_values(array_unique($times, SORT_REGULAR));
        \Vaultcast\Repository::cachePut($key, ['times' => $times], 86400);
        return $times;
    }

    /**
     * Where a remux for $target really starts. With stream copy the output begins at a video
     * keyframe, and ffmpeg's input seek lands on the keyframe *before* one that sits right at
     * the target (demuxer preroll). So: start = last keyframe <= target; seek = slightly after it
     * (but before the next one), which makes ffmpeg begin exactly at start. The player maps
     * position = start + video.currentTime.
     * @return array{start:float,seek:float}
     */
    public static function seekPlan(string $path, float $target): array {
        if ($target <= 0) return ['start' => 0.0, 'seek' => 0.0];
        $start = null; $next = null;
        foreach (self::keyframes($path, $target - 20.0, $target + 10.0) as $t) {
            if ($t <= $target + 0.001) $start = $t;
            elseif ($next === null) $next = $t;
        }
        if ($start === null) return ['start' => $target, 'seek' => $target];
        $gap = $next !== null ? ($next - $start) / 2 : 0.25;
        $seek = $start <= 0 ? 0.0 : $start + min(0.25, max(0.01, $gap));
        return ['start' => max(0.0, $start), 'seek' => $seek];
    }

    public static function keyframeBefore(string $path, float $target): float {
        return self::seekPlan($path, $target)['start'];
    }

    /** One remux per playback token: a newer request supersedes (and so ends) the older one. */
    private static function claimFile(string $token): string {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'vaultcast-remux-' . sha1($token);
    }
    public static function claim(string $token): string {
        $id = bin2hex(random_bytes(8));
        @file_put_contents(self::claimFile($token), $id, LOCK_EX);
        return $id;
    }
    public static function superseded(string $token, string $id): bool {
        $cur = @file_get_contents(self::claimFile($token));
        return is_string($cur) && $cur !== '' && $cur !== $id;
    }
    public static function release(string $token, string $id): void {
        if (!self::superseded($token, $id)) @unlink(self::claimFile($token));
    }

    /** Stop ffmpeg (if still running) and close its pipes. Safe to call twice. */
    public static function stop($proc, array $pipes): void {
        foreach ($pipes as $p) if (is_resource($p)) @fclose($p);
        if (!is_resource($proc)) return;
        $st = @proc_get_status($proc);
        if (is_array($st) && !empty($st['running'])) @proc_terminate($proc, 9);
        @proc_close($proc);
    }

    /**
     * Copy ffmpeg stdout to the client until EOF, client disconnect, or a newer remux for the same
     * token. Uses non-blocking reads with stream_select so idle/stalled ffmpeg is still noticed.
     * @param callable $write fn(string): bool   false = client gone
     * @param callable $abort fn(): bool          true  = stop now
     * @return string why it stopped: eof | client | superseded
     */
    public static function pump($out, callable $write, callable $abort, ?bool $select = null): string {
        // stream_select() does not work on proc_open pipes on Windows: read blocking there and
        // check for abort/supersede between chunks (ffmpeg writes continuously while remuxing).
        if ($select === null) $select = PHP_OS_FAMILY !== 'Windows';
        if (!$select) {
            while (!feof($out)) {
                if ($abort()) return 'superseded';
                $chunk = fread($out, 65536);
                if ($chunk === false) return 'eof';
                if ($chunk === '') continue;
                if (!$write($chunk)) return 'client';
            }
            return 'eof';
        }
        stream_set_blocking($out, false);
        while (true) {
            if ($abort()) return 'superseded';
            $r = [$out]; $w = null; $e = null;
            $n = @stream_select($r, $w, $e, 1, 0);
            if ($n === false) return 'eof';
            if ($n === 0) continue;
            $chunk = fread($out, 65536);
            if ($chunk === false || ($chunk === '' && feof($out))) return 'eof';
            if ($chunk === '') continue;
            if (!$write($chunk)) return 'client';
        }
    }

    public static function ffmpegBin(): string {
        $configured = trim((string)(Store::settings()['tools']['ffmpeg'] ?? ''));
        return $configured !== '' ? $configured : 'ffmpeg';
    }

    /** Validate the requested audio track (relative index among audio streams). */
    public static function audioIndex($value, int $count): ?int {
        if (is_int($value)) $n = $value;
        elseif (is_string($value) && preg_match('/^\d{1,3}$/D', $value)) $n = (int)$value;
        else return null;
        return ($n >= 0 && $n < max(1, $count)) ? $n : null;
    }

    public static function startSeconds($value): float {
        $s = is_numeric($value) ? (float)$value : 0.0;
        if (!is_finite($s) || $s < 0) $s = 0.0;
        return min($s, 7 * 86400.0);
    }

    /** ffmpeg argv (no shell). $audioCodec is the source codec of the chosen track. */
    public static function arguments(string $path, int $audio, float $start, ?string $audioCodec, string $bin = 'ffmpeg'): array {
        $args = [$bin, '-hide_banner', '-loglevel', 'error', '-nostdin'];
        $config=Transcode::settings();$threads=Convert::sanitizeThreadCount($config['threads']);
        $args=array_merge($args,Convert::threadOptions($threads,'copy')['input']);
        if ($start > 0) array_push($args, '-ss', sprintf('%.3F', $start));
        array_push($args, '-i', $path, '-map', '0:v:0?', '-map', '0:a:' . $audio, '-c:v', 'copy');
        if (in_array(strtolower((string)$audioCodec), self::BROWSER_AUDIO, true)) array_push($args, '-c:a', 'copy');
        else {
            array_push($args, '-c:a', 'aac', '-b:a', $config['audio_bitrate']);
            if($config['audio_channels']==='2')array_push($args,'-ac','2');
        }
        array_push($args, '-threads:a',(string)$threads,'-sn', '-dn', '-map_metadata', '-1', '-f', 'mp4',
            '-movflags', 'frag_keyframe+empty_moov+default_base_moof', '-frag_duration', '1000000', 'pipe:1');
        return $args;
    }

    /** GET /stream/remux?t=<token>&a=<audio>&ss=<seconds>[&info=1] */
    public static function serve(): void {
        $token = (string)($_GET['t'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) { http_response_code(403); echo 'Forbidden'; exit; }
        if (!self::enabled()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Audio remuxing is disabled in DirectPlay Settings.', 'directplay_only' => (Store::settings()['streaming']['directplay_only'] ?? true) !== false]);
            exit;
        }
        $payload = \Vaultcast\Repository::playbackToken($token);
        if ($payload === null || ($payload['media_kind'] ?? '') !== 'video') { http_response_code(403); echo 'Expired'; exit; }
        $real = \Vaultcast\Paths::real((string)($payload['path'] ?? ''));
        if ($real === false || !is_file($real)) { http_response_code(404); echo 'Missing'; exit; }
        $start = self::startSeconds($_GET['ss'] ?? 0);
        if (!empty($_GET['info'])) {
            // Where the remux at ?ss really starts (keyframe), so the player can map its timeline.
            if (function_exists('release_request_locks')) release_request_locks();
            $probe = MediaProbe::probeSummary($real, 5, true);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            $plan = self::seekPlan($real, $start);
            echo json_encode(['ok' => true, 'start' => round($plan['start'], 3), 'seek' => round($plan['seek'], 3), 'duration' => (float)($probe['duration'] ?? 0)]);
            exit;
        }
        $probe = MediaProbe::probeSummary($real, 5, true);
        $tracks = $probe['audio_tracks'] ?? [];
        $audio = self::audioIndex($_GET['a'] ?? '0', count($tracks));
        if ($audio === null) { http_response_code(400); echo 'Unknown audio track'; exit; }
        if (!function_exists('proc_open')) { http_response_code(501); echo 'Audio switching needs proc_open'; exit; }
        $args = self::arguments($real, $audio, $start, $tracks[$audio]['codec'] ?? null, self::ffmpegBin());

        if (function_exists('release_request_locks')) release_request_locks();
        $claim = self::claim($token); // ends the previous remux of this player (seek / track change)
        \Vaultcast\Database::releaseForStreaming();
        @ignore_user_abort(false);
        @set_time_limit(0);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open($args, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) { http_response_code(500); echo 'ffmpeg could not start'; exit; }
        // If PHP aborts the script on a failed write (ignore_user_abort false), still end ffmpeg.
        register_shutdown_function(function () use ($proc, &$pipes, $token, $claim) {
            Remux::stop($proc, $pipes);
            Remux::release($token, $claim);
        });
        header('Content-Type: video/mp4');
        header('Cache-Control: private, no-store');
        header('X-Accel-Buffering: no'); // nginx: pass chunks through as ffmpeg writes them
        header('Accept-Ranges: none');
        while (ob_get_level() > 0) @ob_end_flush();
        self::pump($pipes[1], function ($chunk) {
            echo $chunk;
            @flush();
            return !connection_aborted();
        }, function () use ($token, $claim) {
            return connection_aborted() || Remux::superseded($token, $claim);
        });
        self::stop($proc, $pipes);
        self::release($token, $claim);
        exit;
    }
}
