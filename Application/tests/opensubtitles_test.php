<?php
/** OpenSubtitles search/download with a mocked HTTP layer. php Application/tests/opensubtitles_test.php */
declare(strict_types=1);
class Store { public static function settings() { return []; } }
$tmp = sys_get_temp_dir() . '/vc-os-' . getmypid();
define('STREAM_DATA', $tmp . '/data');
require __DIR__ . '/../src/Paths.php';
eval('namespace Vaultcast; class Database { public static function basePath(): string { return ""; } }');
require __DIR__ . '/../src/Services/Subtitles.php';
require __DIR__ . '/../src/Services/OpenSubtitles.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

@mkdir($tmp . '/lib', 0777, true);
$video = $tmp . '/lib/Example Film (2001).mkv';
file_put_contents($video, str_repeat("\x01", 200000));
$item = ['path' => $video, 'title' => 'Example Film', 'year' => 2001, 'imdb_id' => 'tt0123456'];

// Not configured
OpenSubtitles::$config = [];
check(!OpenSubtitles::configured() && !OpenSubtitles::allowed(['role' => 'admin']), 'disabled without an API key');
$r = OpenSubtitles::search($item);
check(!$r['ok'] && strpos($r['error'], 'Settings') !== false, 'clear message when not set up');

OpenSubtitles::$config = ['api_key' => 'TESTKEY', 'languages' => 'en'];
check(OpenSubtitles::allowed(['role' => 'admin']) && !OpenSubtitles::allowed(['role' => 'user']), 'admins only by default');
OpenSubtitles::$config['allow_users'] = true;
check(OpenSubtitles::allowed(['role' => 'user']), 'users allowed when enabled');

// Settings masking / merge
$masked = OpenSubtitles::maskSettings(['opensubtitles' => ['api_key' => 'SECRET', 'password' => 'pw', 'username' => 'u']]);
check($masked['opensubtitles']['api_key'] === '' && $masked['opensubtitles']['password'] === '' && $masked['opensubtitles']['api_key_set'] && $masked['opensubtitles']['password_set'], 'secrets never sent back to the browser');
$merged = OpenSubtitles::mergeSettings(['opensubtitles' => ['api_key' => 'SECRET', 'password' => 'pw']], ['api_key' => '', 'password' => '', 'username' => ' bob ', 'languages' => 'FR, en,xx-yy-zz', 'api_key_set' => true]);
check($merged['api_key'] === 'SECRET' && $merged['password'] === 'pw' && $merged['username'] === 'bob' && $merged['languages'] === 'en,fr' && !isset($merged['api_key_set']), 'blank secrets keep stored values');
$cleared = OpenSubtitles::mergeSettings(['opensubtitles' => ['api_key' => 'SECRET', 'password' => 'pw']], ['clear_api_key' => true, 'clear_password' => true]);
check($cleared['api_key'] === '' && $cleared['password'] === '', 'clear flags remove secrets');

// Movie hash (reference: size + 64-bit little-endian word sums of first/last 64 KiB)
check(OpenSubtitles::movieHash($video) === '4040404040434d40', 'movie hash');

// Search
$calls = [];
$srt = "1\r\n00:00:01,000 --> 00:00:02,500\r\nHello\r\n\r\n2\r\n00:00:03,000 --> 00:00:04,000\r\nWorld\r\n";
$downloadReply = [200, json_encode(['link' => 'https://dl.example.com/file.srt', 'remaining' => 4, 'reset_time' => '10 hours'])];
OpenSubtitles::$http = function ($method, $url, $headers, $body) use (&$calls, $srt, &$downloadReply) {
    $calls[] = [$method, $url, $headers, $body];
    if (strpos($url, '/subtitles?') !== false) return [200, json_encode(['data' => [
        ['attributes' => ['language' => 'en', 'release' => 'Low', 'download_count' => 5, 'files' => [['file_id' => 11]]]],
        ['attributes' => ['language' => 'en', 'release' => 'Exact', 'download_count' => 1, 'moviehash_match' => true, 'files' => [['file_id' => 22]]]],
        ['attributes' => ['language' => 'en', 'files' => []]],
    ]])];
    if (substr($url, -6) === '/login') return [200, json_encode(['token' => 'TOK'])];
    if (substr($url, -9) === '/download') return $downloadReply;
    if ($url === 'https://dl.example.com/file.srt') return [200, $srt];
    return [404, ''];
};
$r = OpenSubtitles::search($item, 'en');
check($r['ok'] && count($r['results']) === 2 && $r['results'][0]['file_id'] === 22, 'results parsed, hash match first');
check(strpos($calls[0][1], 'imdb_id=123456') !== false && strpos($calls[0][1], 'query=Example+Film') !== false && strpos($calls[0][1], 'year=2001') !== false && strpos($calls[0][1], 'moviehash=') !== false, 'search query uses title, year, imdb id and hash');
check(in_array('Api-Key: TESTKEY', $calls[0][2], true) && preg_grep('/^User-Agent: Vaultcast/', $calls[0][2]), 'API key and User-Agent headers sent');
$ep = OpenSubtitles::search(['path' => $video, 'title' => 'Pilot', 'show' => 'Example Show', 'season' => 1, 'episode' => 2], 'en');
$u = end($calls)[1];
check($ep['ok'] && strpos($u, 'query=Example+Show') !== false && strpos($u, 'season_number=1') !== false && strpos($u, 'episode_number=2') !== false, 'episode search uses show/season/episode');

// Download (read-only-safe: writes next to video)
$calls = [];
$d = OpenSubtitles::download($item, 22, 'en');
$side = $tmp . '/lib/Example Film (2001).en.opensubtitles.vtt';
check($d['ok'] && $d['file'] === $side && is_file($side) && $d['remaining'] === 4, 'saved next to the video');
$vtt = (string)@file_get_contents($side);
check(strpos($vtt, "WEBVTT\n\n") === 0 && strpos($vtt, '00:00:01.000 --> 00:00:02.500') !== false && strpos($vtt, "\r") === false, 'SRT converted to WebVTT');
check(json_decode($calls[0][3], true)['file_id'] === 22 && !preg_grep('/^Authorization/', $calls[0][2]), 'download without account sends no bearer');
$tracks = Subtitles::tracks($video, str_repeat('a', 48));
check(count($tracks) === 1 && $tracks[0]['language'] === 'en', 'new subtitle listed for every player');

// With account: login token used
OpenSubtitles::$config += ['username' => 'someone', 'password' => 'pw'];
$calls = [];
OpenSubtitles::download($item, 22, 'en');
check(substr($calls[0][1], -6) === '/login' && in_array('Authorization: Bearer TOK', $calls[1][2], true), 'account login token used for download');
unset(OpenSubtitles::$config['username'], OpenSubtitles::$config['password']);

// Quota
$downloadReply = [406, json_encode(['requests' => 6, 'remaining' => 0, 'message' => 'You have downloaded your allowed 5 subtitles for 24h.', 'reset_time' => '7 hours and 3 minutes'])];
$q = OpenSubtitles::download($item, 22, 'en');
check(!$q['ok'] && !empty($q['quota']) && strpos($q['error'], 'daily download limit') !== false && strpos($q['error'], '7 hours and 3 minutes') !== false, 'daily quota reported clearly');
check(strpos(OpenSubtitles::errorMessage(429, []), 'rate limiting') !== false && strpos(OpenSubtitles::errorMessage(401, ['message' => 'bad']), 'API key') !== false, 'rate limit and auth messages');
$downloadReply = [200, json_encode(['link' => 'http://insecure.example.com/x.srt'])];
check(!OpenSubtitles::download($item, 22, 'en')['ok'], 'non-HTTPS download link rejected');
$downloadReply = [200, json_encode(['link' => 'https://dl.example.com/file.srt'])];

// Read-only library folder: falls back to app store
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    unlink($side);
    chmod($tmp . '/lib', 0555);
    $d = OpenSubtitles::download($item, 22, 'en');
    check($d['ok'] && strpos($d['file'], STREAM_DATA . '/subtitles/') === 0, 'read-only folder falls back to data/subtitles');
    $tracks = Subtitles::tracks($video, str_repeat('a', 48));
    check(count($tracks) === 1 && $tracks[0]['language'] === 'en', 'store subtitle listed');
    chmod($tmp . '/lib', 0777);
} else {
    $d = Subtitles::saveFor('/nonexistent-dir/x.mkv', "WEBVTT\n\n", 'en', 'opensubtitles');
    check($d !== null && strpos($d, STREAM_DATA . '/subtitles/') === 0, 'missing folder falls back to data/subtitles');
}
check(OpenSubtitles::languages('de, EN ,../x') === 'de,en', 'language list sanitised');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
