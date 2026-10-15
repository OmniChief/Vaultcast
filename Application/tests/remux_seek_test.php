<?php
/** Remux: DirectPlay-only gate, keyframe-accurate seek offsets, ffmpeg cleanup. php Application/tests/remux_seek_test.php */
declare(strict_types=1);
class Store { public static $s = []; public static function settings() { return self::$s; } }
class MediaProbe { public static function ffprobeBin(): string { return 'ffprobe'; } }
require __DIR__ . '/../src/Services/Remux.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

// DirectPlay only (Settings -> Streaming; UI default is on)
check(!Remux::enabled(), 'remux off when the setting was never saved (DirectPlay only default)');
Store::$s = ['streaming' => ['directplay_only' => true]];
check(!Remux::enabled(), 'remux off with DirectPlay only');
Store::$s = ['streaming' => ['directplay_only' => false]];
check(Remux::enabled(), 'remux on when DirectPlay only is off');
$src = file_get_contents(__DIR__ . '/../src/Services/Remux.php');
$serve = substr($src, strpos($src, 'public static function serve'));
check(strpos($serve, 'if (!self::enabled())') !== false && strpos($serve, 'if (!self::enabled())') < strpos($serve, 'proc_open('), 'endpoint refuses before starting ffmpeg');

// Supersede: a newer remux for the same player ends the older one
$tok = bin2hex(random_bytes(24));
$a = Remux::claim($tok);
check(!Remux::superseded($tok, $a), 'own claim is current');
$b = Remux::claim($tok);
check(Remux::superseded($tok, $a) && !Remux::superseded($tok, $b), 'restart supersedes the previous stream');
Remux::release($tok, $a);
check(!Remux::superseded($tok, $b), 'old stream release keeps the new claim');
Remux::release($tok, $b);

$ff = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
$fp = trim((string)@shell_exec('command -v ffprobe 2>/dev/null'));
if ($ff === '' || $fp === '') { echo "skip ffmpeg run (ffmpeg not installed)\n"; echo $fails ? "$fails FAILED\n" : "all passed\n"; exit($fails ? 1 : 0); }

$tmp = sys_get_temp_dir() . '/vc-remux-seek-' . getmypid(); @mkdir($tmp);
$file = $tmp . '/seek test.mkv';
exec('ffmpeg -v error -y -f lavfi -i testsrc=size=160x90:rate=10 -f lavfi -i sine=f=440 -f lavfi -i sine=f=880 -t 20 -map 0 -map 1 -map 2 -c:v libx264 -g 50 -keyint_min 50 -sc_threshold 0 -c:a ac3 ' . escapeshellarg($file) . ' 2>&1', $o, $rc);
check($rc === 0, 'test file with keyframes every 5 s');

$plan = Remux::seekPlan($file, 10.05);
check(abs($plan['start'] - 10.0) < 0.02 && $plan['seek'] > 10.0 && $plan['seek'] < 15.0, 'seek plan for 10.05 s starts at keyframe 10 s');
$k = Remux::keyframeBefore($file, 12.3);
check(abs($k - 10.0) < 0.02, 'keyframe before 12.3 s is 10 s (got ' . $k . ')');
check(abs(Remux::keyframeBefore($file, 15.0) - 15.0) < 0.02 && Remux::keyframeBefore($file, 0.0) === 0.0, 'exact keyframe and start');

// Real seek: remux from the reported keyframe, keep track 1; first frame == source frame at 10 s
$out = $tmp . '/seek.mp4';
$plan = Remux::seekPlan($file, 12.3);
$p = proc_open(Remux::arguments($file, 1, $plan['seek'], 'ac3'), [1 => ['file', $out, 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
check(proc_close($p) === 0, 'remux at the seek target ran');
$frame = function ($f, $ss) { return md5((string)shell_exec('ffmpeg -v error ' . ($ss !== null ? '-ss ' . $ss . ' ' : '') . '-i ' . escapeshellarg($f) . ' -frames:v 1 -f rawvideo -pix_fmt gray - 2>/dev/null')); };
check($frame($out, null) === $frame($file, '10'), 'remux starts exactly at the reported keyframe, so offset + currentTime is the real position');
$info = json_decode((string)shell_exec('ffprobe -v error -print_format json -show_streams -show_format ' . escapeshellarg($out)), true);
$dur = (float)($info['format']['duration'] ?? 0);
check(abs($dur - 10.0) < 0.6, 'stream covers the rest of the file (10 s), got ' . round($dur, 2));
$zc = function ($f) {
    $pcm = (string)shell_exec('ffmpeg -v error -i ' . escapeshellarg($f) . ' -map 0:a:0 -ss 1 -t 2 -f s16le -ac 1 -ar 8000 - 2>/dev/null');
    $z = 0; $prev = 0; for ($i = 0; $i + 1 < strlen($pcm); $i += 2) { $v = unpack('s', substr($pcm, $i, 2))[1]; if (($v >= 0) !== ($prev >= 0)) $z++; $prev = $v; }
    return $z;
};
$out0 = $tmp . '/seek0.mp4';
$p = proc_open(Remux::arguments($file, 0, $plan['seek'], 'ac3'), [1 => ['file', $out0, 'w'], 2 => ['file', '/dev/null', 'w']], $pipes); proc_close($p);
$z1 = $zc($out); $z0 = $zc($out0);
check($z1 > $z0 * 1.6, 'selected track (880 Hz) kept after the seek, zero crossings ' . $z1 . ' vs ' . $z0);

// Cleanup: endless ffmpeg is ended on client abort / supersede, in select and blocking modes
$alive = function ($pid) { return $pid > 0 && file_exists('/proc/' . $pid) && strpos((string)@file_get_contents('/proc/' . $pid . '/stat'), ') Z') === false; };
foreach ([true, false] as $select) {
    foreach (['client', 'superseded'] as $why) {
        $args = [$ff, '-v', 'error', '-nostdin', '-re', '-f', 'lavfi', '-i', 'testsrc=size=160x90:rate=25', '-c:v', 'mpeg1video', '-f', 'mpegts', 'pipe:1'];
        $proc = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null, null, ['bypass_shell' => true]);
        $pid = (int)(proc_get_status($proc)['pid'] ?? 0);
        $n = 0; $t0 = microtime(true);
        $res = Remux::pump($pp[1], function ($c) use (&$n, $why) { $n++; return !($why === 'client' && $n >= 3); },
            function () use (&$n, $why) { return $why === 'superseded' && $n >= 3; }, $select);
        Remux::stop($proc, $pp);
        usleep(200000);
        check($res === $why && !$alive($pid) && microtime(true) - $t0 < 10, ($select ? 'select' : 'blocking') . " mode: $why ends ffmpeg");
    }
}
exec('rm -rf ' . escapeshellarg($tmp));
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, "streaming:{ directplay_only:false }") !== false && strpos($js, 'disabled by the administrator (DirectPlay only)') !== false, 'admins get a dialog that flips DirectPlay only; others a disabled message');
check(strpos($js, 'media.controls = !on') !== false && strpos($js, 'function fullDuration') !== false, 'remux timeline spans the full duration');
check(strpos($js, '(remux.active ? remux.offset : 0) + (media.currentTime || 0)') !== false, 'displayed time = offset + video.currentTime');
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
