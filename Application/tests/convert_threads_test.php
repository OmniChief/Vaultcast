<?php
/** Conversion threads: validation, defaults, and ffmpeg arguments on every path. php Application/tests/convert_threads_test.php */
$tmp = sys_get_temp_dir() . '/rk-threads-test-' . getmypid();
define('STREAM_DATA', $tmp); define('STREAM_SHM', $tmp);
@mkdir($tmp, 0775, true);
class Store { public static $s = []; public static function settings() { return self::$s; } }
class MediaProbe { public static $v = 'hevc'; public static $a = 'ac3';
    public static function probeSummary($p, $t, $c) { return ['video_codec' => self::$v, 'audio_tracks' => [['codec' => self::$a]]]; } }
require __DIR__ . '/../src/Services/Convert.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$build = new ReflectionMethod('Convert', 'buildFfmpegArgs'); $build->setAccessible(true);
function args($cv) { global $build; return $build->invoke(null, '/m/a.mkv', array_merge(Convert::defaults(), $cv), 'mp4', ' -movflags +faststart')[0]; }

check(Convert::defaults()['threads'] === 1, 'default is 1 thread');
check(Convert::cpuCount() >= 1, 'CPU count detected (' . Convert::cpuCount() . ')');
check(Convert::validThreadCount(1, 8) === 1 && Convert::validThreadCount('8', 8) === 8 && Convert::validThreadCount(' 4 ', 8) === 4, 'accepts whole numbers 1..max');
foreach ([0, 9, -1, '2.5', 2.5, 'auto', '', null, true, '1e2', [], '0x2'] as $bad) check(Convert::validThreadCount($bad, 8) === null, 'rejects ' . json_encode($bad));
check(Convert::sanitizeThreadCount(null, 8) === 1 && Convert::sanitizeThreadCount('junk', 8) === 1, 'missing/invalid stored value (existing installs) -> 1');
check(Convert::sanitizeThreadCount(64, 8) === 8, 'stored value above this CPU count is clamped (e.g. moved to a smaller server)');

$o = Convert::threadOptions(4, 'libx264');
check($o['input'] === ['-threads', '4', '-filter_threads', '4', '-filter_complex_threads', '4'], 'decoder/filter threads before -i');
check($o['encoder'] === ['-threads', '4', '-x264-params', 'threads=4'], 'x264 gets its own threads option');
check(Convert::threadOptions(4, 'libx265')['encoder'] === ['-threads', '4', '-x265-params', 'pools=4:frame-threads=4'], 'x265 gets pools/frame-threads');
check(Convert::threadOptions(2, 'copy')['encoder'] === ['-threads', '2'], 'stream copy: plain -threads');

// every encode path carries the setting
MediaProbe::$v = 'hevc'; MediaProbe::$a = 'ac3';
$a = args(['quality_mode' => 'directplay', 'threads' => 3]);
check(strpos($a, '-c:v libx264') !== false && strpos($a, '-threads 3 -x264-params threads=3') !== false, 'DirectPlay encode: -threads 3 + x264 threads');
$a = args(['quality_mode' => 'custom', 'target_video' => 'libx264', 'target_audio' => 'aac', 'threads' => 2]);
check(strpos($a, '-threads 2 -x264-params threads=2') !== false, 'custom encode: -threads 2 + x264 threads');
foreach (['near_lossless', 'balanced', 'efficient'] as $m) {
    $a = args(['quality_mode' => $m, 'threads' => 2]);
    check(strpos($a, '-x264-params threads=2') !== false, $m . ' encode passes threads');
}
MediaProbe::$v = 'h264';
$a = args(['quality_mode' => 'directplay', 'threads' => 2]);
check(strpos($a, '-c:v copy') !== false && strpos($a, '-threads 2') !== false && strpos($a, 'x264') === false, 'DirectPlay audio re-encode: -threads 2, no x264 option');
$a = args(['quality_mode' => 'directplay']);
check(strpos($a, '-threads 1') !== false, 'no stored setting -> -threads 1');
$a = args(['quality_mode' => 'custom', 'target_video' => 'copy', 'target_audio' => 'aac', 'threads' => 2]);
check(strpos($a, '-threads 2') !== false, 'custom video copy + audio encode passes threads');

// wrapper (Linux) and Windows argv put the input threads before -i
$src = file_get_contents(__DIR__ . '/../src/Services/Convert.php');
check(strpos($src, "-threads 1 -filter_threads 1") === false && strpos($src, 'ffmpeg -nostdin -y %s -progress') !== false, 'Linux wrapper uses the setting, not a hard-coded 1');
$proc = file_get_contents(__DIR__ . '/../src/Services/Process.php');
check(strpos($proc, "'-threads','1'") === false && strpos($proc, "Convert::threadOptions(max(1, \$threads), 'copy')['input']") !== false, 'Windows argv uses the setting');
// admin route validates
$route = file_get_contents(__DIR__ . '/../src/Http/routes/admin-settings.php');
check(strpos($route, 'Convert::validThreadCount(') !== false && strpos($route, "unset(\$b['convert']['max_threads'])") !== false, 'settings POST validates threads and never stores max_threads');

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
