<?php
/** Subtitle upload: content detection, encodings, ASS->VTT, saving. php Application/tests/subtitle_upload_test.php */
declare(strict_types=1);
class Store { public static $s = []; public static function settings() { return self::$s; } }
$tmp = sys_get_temp_dir() . '/vc-subup-' . getmypid();
define('STREAM_DATA', $tmp . '/data');
require __DIR__ . '/../src/Paths.php';
eval('namespace Vaultcast; class Database { public static function basePath(): string { return ""; } }');
require __DIR__ . '/../src/Services/Subtitles.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

@mkdir($tmp . '/lib', 0777, true);
$video = $tmp . '/lib/Example Film.mkv';
file_put_contents($video, 'x');
$srt = "1\r\n00:00:01,000 --> 00:00:02,500\r\nCaf\xe9 au lait\r\n\r\n2\r\n0:00:03,5 --> 0:00:04,000\r\nTwo\r\n";

check(Subtitles::detectFormat(Subtitles::decode($srt)) === 'srt', 'SRT detected by content');
check(Subtitles::detectFormat("WEBVTT\n\n00:01.000 --> 00:02.000\nHi\n") === 'vtt', 'VTT detected');
check(Subtitles::detectFormat("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR") === null, 'binary rejected even if named .srt');
check(Subtitles::detectFormat("<html><body>not subs</body></html>") === null, 'HTML rejected');
check(Subtitles::detectFormat("WEBVTT\n\nnothing") === null, 'VTT header without cues rejected');

$r = Subtitles::upload($video, $srt, 'fr');
$side = $tmp . '/lib/Example Film.fr.uploaded.vtt';
check($r['ok'] && $r['file'] === $side && $r['format'] === 'srt', 'cp1252 SRT saved next to the video as <name>.<lang>.uploaded.vtt');
$v = (string)@file_get_contents($side);
check(strpos($v, "WEBVTT\n\n") === 0 && strpos($v, 'Café au lait') !== false && strpos($v, '00:00:01.000 --> 00:00:02.500') !== false, 'converted to UTF-8 WebVTT');
check(strpos($v, '00:00:03.500 --> 00:00:04.000') !== false, 'single-digit hours and short millis normalised');
$tracks = Subtitles::tracks($video, str_repeat('a', 48));
check(count($tracks) === 1 && $tracks[0]['language'] === 'fr', 'listed for every player');

// UTF-8 BOM and UTF-16LE
$u16 = "\xff\xfe" . mb_convert_encoding("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nÜber\n", 'UTF-16LE', 'UTF-8');
$r = Subtitles::upload($video, $u16, 'de');
check($r['ok'] && $r['format'] === 'vtt' && strpos((string)file_get_contents($r['file']), 'Über') !== false, 'UTF-16 VTT accepted');
$r = Subtitles::upload($video, "\xEF\xBB\xBF" . "1\n00:00:01,000 --> 00:00:02,000\nBOM ok\n", 'en');
check($r['ok'] && strpos((string)file_get_contents($r['file']), "\xEF\xBB\xBF") === false, 'UTF-8 BOM stripped');

// ASS
$ass = "[Script Info]\nTitle: x\nScriptType: v4.00+\n\n[V4+ Styles]\nFormat: Name, Fontname\nStyle: Default,Arial\n\n[Events]\n"
     . "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n"
     . "Dialogue: 0,0:00:05.20,0:00:07.00,Default,,0,0,0,,{\\an8\\b1}Hello, {\\i1}world{\\i0}\\Nline two\n"
     . "Dialogue: 0,0:00:01.00,0:00:02.00,Default,,0,0,0,,First <b>&</b>\n"
     . "Dialogue: 0,0:00:03.00,0:00:04.00,Default,,0,0,0,,{\\p1}m 0 0 l 100 0 100 100{\\p0}\n"
     . "Comment: 0,0:00:03.00,0:00:04.00,Default,,0,0,0,,ignored\n";
check(Subtitles::detectFormat($ass) === 'ass', 'ASS detected');
$vtt = Subtitles::assToVtt($ass);
check(strpos($vtt, "00:00:05.200 --> 00:00:07.000\nHello, world\nline two") !== false, 'ASS override tags stripped, \\N becomes a line break, commas in text kept');
check(strpos($vtt, '00:00:01.000') < strpos($vtt, '00:00:05.200'), 'cues sorted by start');
check(strpos($vtt, 'First &lt;b&gt;&amp;&lt;/b&gt;') !== false, 'markup in text escaped');
check(strpos($vtt, 'm 0 0') === false && strpos($vtt, 'ignored') === false, 'drawings and comments skipped');
$r = Subtitles::upload($video, $ass, 'ja');
check($r['ok'] && $r['format'] === 'ass' && substr($r['file'], -16) === '.ja.uploaded.vtt', 'ASS upload saved');
$ssa = "[Script Info]\nScriptType: v4.00\n\n[Events]\nFormat: Marked, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\nDialogue: Marked=0,0:00:01.00,0:00:02.00,Default,,0,0,0,,SSA line\n";
check(strpos(Subtitles::assToVtt($ssa), "00:00:01.000 --> 00:00:02.000\nSSA line") !== false, 'SSA (v4) converted');

// Limits / validation
check(!Subtitles::upload($video, str_repeat('a', 2097153), 'en')['ok'], 'over 2 MB rejected');
check(!Subtitles::upload($video, $srt, '')['ok'] && !Subtitles::upload($video, $srt, '../x')['ok'], 'language required and sanitised');
check(!Subtitles::upload($video, '', 'en')['ok'], 'empty rejected');
check(!Subtitles::upload($video, "[Script Info]\n[Events]\nFormat: Start, End, Text\n", 'en')['ok'], 'ASS without dialogue rejected');

// Permission: admins always, users only with the shared toggle
check(Subtitles::uploadAllowed(['role' => 'admin']) && !Subtitles::uploadAllowed(['role' => 'user']), 'users blocked by default');
Store::$s = ['opensubtitles' => ['allow_users' => true]];
check(Subtitles::uploadAllowed(['role' => 'user']), 'users allowed with the admin toggle');

// Read-only folder -> data/subtitles
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    chmod($tmp . '/lib', 0555);
    $r = Subtitles::upload($video, $srt, 'es');
    check($r['ok'] && strpos($r['file'], STREAM_DATA . '/subtitles/') === 0, 'read-only folder falls back to data/subtitles');
    chmod($tmp . '/lib', 0777);
} else {
    $r = Subtitles::upload('/nonexistent-dir/x.mkv', $srt, 'es');
    check($r['ok'] && strpos($r['file'], STREAM_DATA . '/subtitles/') === 0, 'unwritable folder falls back to data/subtitles');
}

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
