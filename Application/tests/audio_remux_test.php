<?php
/** Audio track switching via remux. php Application/tests/audio_remux_test.php */
class Store { public static function settings() { return []; } }
require __DIR__ . '/../src/Services/Remux.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

check(Remux::audioIndex('1', 2) === 1 && Remux::audioIndex(0, 2) === 0, 'valid audio index');
foreach (['2', '-1', '1.5', 'a', '', null, [], '0x1'] as $bad) check(Remux::audioIndex($bad, 2) === null, 'rejects audio ' . json_encode($bad));
check(Remux::startSeconds('83.25') === 83.25 && Remux::startSeconds('-4') === 0.0 && Remux::startSeconds('abc') === 0.0 && Remux::startSeconds('1e99') === 604800.0, 'start position sanitised');

$a = Remux::arguments('/m/My Film.mkv', 1, 83.25, 'ac3');
$s = implode(' ', $a);
check(strpos($s, '-ss 83.250 -i /m/My Film.mkv') !== false, 'seeks to the current position before the input');
check(strpos($s, '-map 0:v:0? -map 0:a:1') !== false, 'maps first video and the chosen audio track');
check(strpos($s, '-c:v copy') !== false && strpos($s, '-c:a aac -ac 2') !== false, 'video copied, AC3 converted to stereo AAC');
check(strpos(implode(' ', Remux::arguments('/m/a.mkv', 0, 0, 'aac')), '-c:a copy') !== false, 'browser-compatible audio is copied');
check(strpos($s, 'frag_keyframe+empty_moov') !== false && end($a) === 'pipe:1', 'fragmented MP4 to stdout (streamable)');
check(!in_array('-ss', Remux::arguments('/m/a.mkv', 0, 0, 'aac'), true), 'no seek at position 0');
check($a[array_search('-i', $a, true) + 1] === '/m/My Film.mkv', 'path passed as one argument (no shell)');

// Real ffmpeg run when available: two audio tracks (440 Hz / 880 Hz), pick the second.
$ff = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
$fp = trim((string)@shell_exec('command -v ffprobe 2>/dev/null'));
if ($ff !== '' && $fp !== '') {
    $tmp = sys_get_temp_dir() . '/vc-remux-' . getmypid(); @mkdir($tmp);
    $src = $tmp . '/two audio.mkv';
    exec('ffmpeg -v error -y -f lavfi -i testsrc=size=160x90:rate=10 -f lavfi -i sine=f=440 -f lavfi -i sine=f=880 -t 6 -map 0 -map 1 -map 2 -c:v libx264 -g 10 -c:a ac3 -metadata:s:a:0 language=eng -metadata:s:a:1 language=fre ' . escapeshellarg($src) . ' 2>&1', $o, $rc);
    if ($rc === 0) {
        $args = Remux::arguments($src, 1, 2.0, 'ac3');
        $out = $tmp . '/out.mp4';
        $p = proc_open($args, [1 => ['file', $out, 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $code = proc_close($p);
        $info = json_decode((string)shell_exec('ffprobe -v error -print_format json -show_streams -show_format ' . escapeshellarg($out)), true);
        $streams = $info['streams'] ?? [];
        $types = array_column($streams, 'codec_type');
        check($code === 0 && $types === ['video', 'audio'], 'remux output has one video and one audio stream');
        $aud = array_values(array_filter($streams, function ($x) { return $x['codec_type'] === 'audio'; }))[0] ?? [];
        check(($aud['codec_name'] ?? '') === 'aac' && (int)($aud['channels'] ?? 0) === 2, 'audio converted to stereo AAC');
        $dur = (float)($info['format']['duration'] ?? 0);
        check($dur > 3 && $dur < 5.6, 'output starts at the requested position, snapped to the previous keyframe (video copy), got ' . round($dur, 2) . ')');
        // second track really chosen: dominant frequency via ffmpeg astats is awkward; compare against track 0 output bytes
        $out0 = $tmp . '/out0.mp4';
        $p = proc_open(Remux::arguments($src, 0, 2.0, 'ac3'), [1 => ['file', $out0, 'w'], 2 => ['file', '/dev/null', 'w']], $pipes); proc_close($p);
        $pcm = function ($f) { return (string)shell_exec('ffmpeg -v error -i ' . escapeshellarg($f) . ' -map 0:a:0 -t 1 -f s16le -ac 1 -ar 8000 - 2>/dev/null'); };
        $zc = function ($pcm) { $n = 0; $prev = 0; for ($i = 0; $i + 1 < strlen($pcm); $i += 2) { $v = unpack('s', substr($pcm, $i, 2))[1]; if (($v >= 0) !== ($prev >= 0)) $n++; $prev = $v; } return $n; };
        $z1 = $zc($pcm($out)); $z0 = $zc($pcm($out0));
        check($z1 > $z0 * 1.6, 'track 1 (880 Hz) selected, not track 0 (440 Hz): zero crossings ' . $z1 . ' vs ' . $z0);
    } else echo "skip ffmpeg run (could not make test file)\n";
    exec('rm -rf ' . escapeshellarg($tmp));
} else echo "skip ffmpeg run (ffmpeg not installed)\n";

$routes = file_get_contents(__DIR__ . '/../src/Http/routes.php');
check(strpos($routes, "\$path === '/stream/remux'") !== false, 'remux URL goes through the playback-token access gate');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, 'function switchViaRemux') !== false && strpos($js, "'&ss='") !== false, 'player restarts the remux at the current position');
check(strpos($js, 'openPlayer._absTime ? openPlayer._absTime()') !== false, 'progress is saved as the absolute position');
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
