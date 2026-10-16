<?php
/** File-backed TTL cache (replaces vaultcast_cache). php Application/tests/storage_cache_test.php */
$tmp = sys_get_temp_dir() . '/rk-cache-test-' . getmypid();
define('STREAM_SHM', $tmp);
require __DIR__ . '/../src/Repository.php';
use Vaultcast\Repository as R;
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

check(R::cacheGet('missing') === null, 'miss returns null');
R::cachePut('tvmaze:search:Runner', ['id' => 7, 'name' => 'Runnér'], 60);
check(R::cacheGet('tvmaze:search:Runner') === ['id' => 7, 'name' => 'Runnér'], 'round trip keeps the value');
check(strpos(R::cacheDir(), $tmp) === 0, 'cache lives under data/cache/kv');
R::cachePut('tvmaze:search:Runner', ['id' => 8], 60);
check(R::cacheGet('tvmaze:search:Runner') === ['id' => 8], 'overwrite replaces atomically');
$h = hash('sha256', 'old');
@mkdir($tmp . '/kv/' . substr($h, 0, 2), 0775, true);
file_put_contents($tmp . '/kv/' . substr($h, 0, 2) . '/' . $h . '.json', json_encode(['e' => time() - 5, 'v' => ['x' => 1]]));
check(R::cacheGet('old') === null, 'expired entry is a miss');
check(R::cacheSweep($tmp . '/kv/' . substr($h, 0, 2)) === 1, 'sweep deletes expired entries');
file_put_contents($tmp . '/kv/' . substr($h, 0, 2) . '/' . $h . '.json', '{broken');
check(R::cacheGet('old') === null, 'corrupt entry is a miss, not an error');
check(glob($tmp . '/kv/*/*.tmp') === [], 'no temp files left behind');

R::cachePut('revision-managed', ['revision'=>1], 0);
check(R::cacheGet('revision-managed') === ['revision'=>1], 'zero TTL keeps revision-managed data');
foreach (glob($tmp . '/kv/*', GLOB_ONLYDIR) ?: [] as $dir) R::cacheSweep($dir);
check(R::cacheGet('revision-managed') === ['revision'=>1], 'sweep preserves revision-managed data');
R::cachePut('revision-managed', ['revision'=>2], 0);
check(R::cacheGet('revision-managed') === ['revision'=>2], 'revision-managed entry is replaceable');

R::cachePut('a', ['1'], 60); R::cachePut('b', ['2'], 60);
check(R::cacheStats()['files'] >= 2, 'stats count entries');
R::cacheClear();
check(R::cacheStats()['files'] === 0 && R::cacheGet('a') === null, 'clear removes everything');

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
