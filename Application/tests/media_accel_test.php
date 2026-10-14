<?php
/** nginx X-Accel vs PHP streaming fallback per library folder. php Application/tests/media_accel_test.php */
define('STREAM_ROOT', dirname(__DIR__));
$tmp = sys_get_temp_dir() . '/vc-accel-test-' . getmypid();
@mkdir($tmp . '/Films', 0775, true);
define('STREAM_DATA', $tmp); define('STREAM_SHM', $tmp);
class Store { public static $s = []; public static function settings() { return self::$s; } }
require __DIR__ . '/../src/Paths.php';
require __DIR__ . '/../src/Services/MediaAccel.php';
require __DIR__ . '/../src/Services/Stream.php';
require __DIR__ . '/../src/Services/Updater.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

$doc = [];
MediaAccel::$load = function () use (&$doc) { return $doc; };
MediaAccel::$save = function ($s) use (&$doc) { $doc = $s; };
$grants = 0; $grantOk = true;
MediaAccel::$grant = function ($p) use (&$grants, &$grantOk) { $grants++; return $grantOk ? ['ok' => true] : ['ok' => false, 'code' => 'library_acl_failed', 'error' => 'Access is denied.']; };
$root = 'C:/Users/Public/Downloads/Films';

MediaAccel::$os = 'Linux';
check(MediaAccel::rootUsable($root) && $grants === 0, 'non-Windows: nginx path always usable, no grant');

MediaAccel::$os = 'Windows';
check(MediaAccel::rootUsable($root) && $grants === 1, 'Windows, unknown folder: grants once, then uses nginx');
check(MediaAccel::rootUsable($root) && $grants === 1, 'recorded success is reused (no icacls per request)');
check(MediaAccel::rootUsable(strtolower($root)) && $grants === 1, 'Windows folder keys are case-insensitive');

$doc = []; $grants = 0; $grantOk = false;
check(!MediaAccel::rootUsable($root) && $grants === 1, 'grant fails: PHP streams instead');
check(!MediaAccel::rootUsable($root) && $grants === 1, 'failure is not retried on every request');
check(!MediaAccel::rootUsable($root, time() + MediaAccel::RETRY_AFTER + 1) && $grants === 2, 'failure is retried after the retry window');
check(strpos($doc[MediaAccel::key($root)]['error'], 'nginx') !== false, 'failure reason recorded');

// The migration ran while PHP was another account (e.g. LocalService before reinstall): re-grant.
$grantOk = true; $grants = 0;
$doc = [MediaAccel::key($root) => ['ok' => false, 'at' => time(), 'account' => 'some-old-account']];
check(MediaAccel::rootUsable($root) && $grants === 1, 'record from a different service account is re-checked');

// Player fallback marks the folder: PHP for a while, then a fresh grant.
MediaAccel::markFailed($root);
check(!MediaAccel::rootUsable($root), 'after a player fallback, PHP streams the folder');
$grants = 0;
check(MediaAccel::rootUsable($root, time() + MediaAccel::RETRY_AFTER + 1) && $grants === 1, 'after the window, re-grant and try nginx again');

// Stream::toAccelUri wiring
$doc = []; $grantOk = true;
Store::$s = ['streaming' => ['nginx_accel' => true], 'sections' => [['id' => 'films', 'path' => $tmp . '/Films']]];
$file = Vaultcast\Paths::real($tmp . '/Films') . '/Runner (2026) [1080p] #1 & co.mp4';
MediaAccel::$os = 'Linux';
$u = Stream::toAccelUri($file);
check($u !== null && strpos($u, '/_vaultcast_media/') === 0, 'accel URI uses the /_vaultcast_media/ prefix (matches installer nginx config)');
check(rawurldecode($u) === '/_vaultcast_media/' . ltrim($file, '/'), 'accel URI decodes back to the exact file path (spaces, brackets, #, &)');
check(strpos($u, ' ') === false && strpos($u, '#') === false, 'accel URI is percent-encoded');
check(rawurldecode(implode('/', array_map('rawurlencode', explode('/', 'C:/Users/Public/My Films/a.mp4')))) === 'C:/Users/Public/My Films/a.mp4', 'drive-letter path round-trips for the nginx ([A-Za-z]):/(.*) location');
$_GET['direct'] = '1';
check(Stream::toAccelUri($file) === null, 'direct=1 forces PHP streaming');
unset($_GET['direct']);
MediaAccel::$os = 'Windows'; $grantOk = false; $doc = [];
check(Stream::toAccelUri($file) === null, 'Windows with a failed ACL grant: PHP streaming');
Store::$s['streaming']['nginx_accel'] = false; MediaAccel::$os = 'Linux';
check(Stream::toAccelUri($file) === null, 'accel off: PHP streaming');

// PHP fallback keeps range support and releases locks
$src = file_get_contents(__DIR__ . '/../src/Services/Stream.php');
check(preg_match('/function sendFile.*?release_request_locks.*?HTTP_RANGE.*?206/s', $src) === 1, 'PHP sendFile releases locks and serves byte ranges (206)');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, "'direct=1'") !== false && strpos($js, 'directTried') !== false, 'player retries once with direct=1 when it fails before playing');

// Everything this fix touches ships through the in-app updater.
foreach (['src/Services/MediaAccel.php', 'src/Services/Stream.php', 'src/Services/Migrations.php', 'src/Http/routes/admin-settings.php', 'assets/js/app.js', 'tests/media_accel_test.php'] as $f) check(Updater::allowed($f), 'updater allowlist: ' . $f);
$mig = file_get_contents(__DIR__ . '/../src/Services/Migrations.php');
check(preg_match('/const VERSION = (\d+)/', $mig, $m) && (int)$m[1] >= 3, 'migration version bumped so the ACL pass re-runs on existing installs');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
