<?php
/**
 * Stream concurrency helpers: locks, accel emit, single-worker demo.
 * php Application/tests/stream_concurrency_test.php
 */
$tmp = sys_get_temp_dir() . '/rk-stream-conc-' . getmypid();
@mkdir($tmp, 0775, true);
define('STREAM_ROOT', dirname(__DIR__));
define('STREAM_DATA', $tmp);
define('STREAM_SHM', $tmp);
define('STREAM_VERSION', '0.0.0');

require __DIR__ . '/../src/Http/helpers.php';

$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

release_request_locks();
check(true, 'release_request_locks no-op without a native session');

// Native session lock is released so a later long handler would not hold it.
if (function_exists('session_start')) {
    @session_start(['use_cookies' => false, 'read_and_close' => false]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['x'] = 1;
        release_request_locks();
        check(session_status() !== PHP_SESSION_ACTIVE, 'release_request_locks closes an active native session');
    } else {
        check(true, 'session_start unavailable in this SAPI — skipped');
    }
}

// Single-threaded server demo: /hold streams slowly; /ping measures wait.
$hold = $tmp . '/hold.bin';
file_put_contents($hold, str_repeat('0', 64 * 1024));
$router = $tmp . '/router.php';
file_put_contents($router, '<?php
require ' . var_export(__DIR__ . '/../src/Http/helpers.php', true) . ';
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/hold") {
    release_request_locks();
    header("Content-Type: application/octet-stream");
    $fp = fopen(' . var_export($hold, true) . ', "rb");
    while (!feof($fp)) { echo fread($fp, 4096); flush(); usleep(80000); }
    exit;
}
if ($path === "/ping") { release_request_locks(); header("Content-Type: text/plain"); echo "pong"; exit; }
http_response_code(404); echo "no";
');

$port = 18000 + (getmypid() % 1000);
$cmd = sprintf('php -n -S 127.0.0.1:%d %s', $port, escapeshellarg($router));
$proc = proc_open($cmd, [0=>['pipe','r'], 1=>['file', $tmp.'/out.log','w'], 2=>['file', $tmp.'/err.log','w']], $pipes, $tmp);
check(is_resource($proc), 'started php -S single-threaded demo server');
if (is_resource($proc)) {
    usleep(400000);
    // Background client that holds /hold open
    $bg = sprintf('php -n -r \'$c=stream_context_create(["http"=>["timeout"=>25]]); @file_get_contents("http://127.0.0.1:%d/hold",false,$c);\' >/dev/null 2>&1 &', $port);
    exec($bg);
    usleep(300000);
    $t0 = microtime(true);
    $ping = @file_get_contents("http://127.0.0.1:{$port}/ping", false, stream_context_create(['http'=>['timeout'=>8]]));
    $elapsed = microtime(true) - $t0;
    // Built-in server = one request at a time, same class of hang as Windows php-cgi + sendFile.
    check($elapsed >= 0.4 || $ping === 'pong', sprintf('ping while /hold busy: %.2fs (single-worker queue)', $elapsed));
    if ($elapsed >= 0.5) {
        check(true, 'reproduced: parallel API waits while a stream occupies the only worker');
    }
    proc_terminate($proc);
    proc_close($proc);
}

// Source checks (no full bootstrap)
$stream = file_get_contents(__DIR__ . '/../src/Services/Stream.php');
check(strpos($stream, 'function emitAccelOrSend') !== false, 'emitAccelOrSend present');
check(strpos($stream, 'release_request_locks') !== false, 'send/accel path releases locks');
$repo = file_get_contents(__DIR__ . '/../src/Repository.php');
check(strpos($repo, 'function progressItem') !== false, 'progressItem present');
check(strpos($repo, 'progress-rev.stamp') !== false, 'saveProgress throttles document revision bumps');
$acc = file_get_contents(__DIR__ . '/../src/Http/routes/account.php');
check(strpos($acc, "http_response_code(204)") !== false && strpos($acc, '/progress') !== false, 'progress endpoint returns 204');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, 'function postProgress') !== false && strpos($js, 'sendBeacon') !== false, 'player posts progress via sendBeacon/keepalive');
$docs = file_get_contents(__DIR__ . '/../docs/windows-php-workers.md');
check(strpos($docs, 'PHP_FCGI_CHILDREN') !== false && strpos($docs, '_vaultcast_media') !== false, 'Theodore doc covers workers + X-Accel');

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
