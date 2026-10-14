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
        if ($start > 0) array_push($args, '-ss', sprintf('%.3F', $start));
        array_push($args, '-i', $path, '-map', '0:v:0?', '-map', '0:a:' . $audio, '-c:v', 'copy');
        if (in_array(strtolower((string)$audioCodec), self::BROWSER_AUDIO, true)) array_push($args, '-c:a', 'copy');
        else array_push($args, '-c:a', 'aac', '-ac', '2', '-b:a', '192k');
        array_push($args, '-sn', '-dn', '-map_metadata', '-1', '-f', 'mp4',
            '-movflags', 'frag_keyframe+empty_moov+default_base_moof', '-frag_duration', '1000000', 'pipe:1');
        return $args;
    }

    /** GET /stream/remux?t=<token>&a=<audio>&ss=<seconds> */
    public static function serve(): void {
        $token = (string)($_GET['t'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) { http_response_code(403); echo 'Forbidden'; exit; }
        $payload = \Vaultcast\Repository::playbackToken($token);
        if ($payload === null || ($payload['media_kind'] ?? '') !== 'video') { http_response_code(403); echo 'Expired'; exit; }
        $real = \Vaultcast\Paths::real((string)($payload['path'] ?? ''));
        if ($real === false || !is_file($real)) { http_response_code(404); echo 'Missing'; exit; }
        $probe = MediaProbe::probeSummary($real, 5, true);
        $tracks = $probe['audio_tracks'] ?? [];
        $audio = self::audioIndex($_GET['a'] ?? '0', count($tracks));
        if ($audio === null) { http_response_code(400); echo 'Unknown audio track'; exit; }
        if (!function_exists('proc_open')) { http_response_code(501); echo 'Audio switching needs proc_open'; exit; }
        $start = self::startSeconds($_GET['ss'] ?? 0);
        $args = self::arguments($real, $audio, $start, $tracks[$audio]['codec'] ?? null, self::ffmpegBin());

        if (function_exists('release_request_locks')) release_request_locks();
        @ignore_user_abort(false);
        @set_time_limit(0);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open($args, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) { http_response_code(500); echo 'ffmpeg could not start'; exit; }
        header('Content-Type: video/mp4');
        header('Cache-Control: private, no-store');
        header('X-Accel-Buffering: no'); // nginx: pass chunks through as ffmpeg writes them
        header('Accept-Ranges: none');
        while (ob_get_level() > 0) @ob_end_flush();
        $out = $pipes[1];
        while (!feof($out)) {
            $chunk = fread($out, 65536);
            if ($chunk === false) break;
            if ($chunk === '') { if (connection_aborted()) break; usleep(20000); continue; }
            echo $chunk;
            @flush();
            if (connection_aborted()) break;
        }
        @fclose($out);
        $st = @proc_get_status($proc);
        if (is_array($st) && !empty($st['running'])) @proc_terminate($proc, 9);
        @proc_close($proc);
        exit;
    }
}
