<?php
/**
 * Movie detail must stay instant: disk-cached synopsis only, no remote OMDb/Wikipedia.
 * php Application/tests/item_detail_test.php
 */
$tmp = sys_get_temp_dir() . '/rk-item-detail-' . getmypid();
@mkdir($tmp, 0775, true);
@mkdir($tmp . '/synopsis', 0775, true);
define('STREAM_ROOT', dirname(__DIR__));
define('STREAM_DATA', $tmp);
require __DIR__ . '/../src/Services/MetadataPins.php';
MetadataPins::$memory = [];
require __DIR__ . '/../src/Services/Posters.php';

$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

$id = 'aabbccddeeff00112233445566778899';
$path = Posters::movieSynopsisCachePath($id);
check(strpos($path, $tmp . '/synopsis/movie_') === 0, 'synopsis cache path under STREAM_DATA');

// No cache → pending, no network (we never call movieSynopsis here).
$c = Posters::movieSynopsisCached($id);
check($c['summary'] === null && $c['pending'] === true, 'miss is pending, not a remote fetch');

// Cached miss (previous Wikipedia miss) must NOT be pending — avoids re-hitting the network every open.
file_put_contents($path, json_encode(['title' => 'X', 'summary' => null, 'source' => null, 'fetched_at' => date('c')]));
$c = Posters::movieSynopsisCached($id);
check($c['summary'] === null && $c['pending'] === false, 'cached null miss is final until refresh');

file_put_contents($path, json_encode(['title' => 'Runner', 'summary' => 'A film about running.', 'source' => 'wikipedia', 'fetched_at' => date('c')]));
$c = Posters::movieSynopsisCached($id);
check($c['summary'] === 'A film about running.' && $c['source'] === 'wikipedia' && $c['pending'] === false, 'cached synopsis returned');

// Show cached helper: no TVMaze file → null (no network).
$sk = str_repeat('ab', 16);
check(Posters::showSynopsisCached($sk) === null, 'show synopsis miss without hitting TVMaze');
@mkdir($tmp . '/tvmaze', 0775, true);
file_put_contents($tmp . '/tvmaze/show_' . $sk . '_episodes.json', json_encode(['summary' => 'A show.', 'episodes' => []]));
check(Posters::showSynopsisCached($sk) === 'A show.', 'show synopsis from disk cache');

// itemDetail / itemSynopsisFetch need Catalogue + Repository (DB). Smoke-test the method shapes exist.
require __DIR__ . '/../src/Services/Catalogue.php';
check(method_exists('Catalogue', 'itemSynopsisFetch'), 'itemSynopsisFetch exists for background fill');
check(method_exists('Catalogue', 'itemDetail'), 'itemDetail exists');

// Remote budget: httpGet must refuse when the deadline has passed (Windows single php-cgi).
if (!defined('STREAM_VERSION')) define('STREAM_VERSION', '0.0.0');
$ref = new ReflectionClass('Posters');
$prop = $ref->getProperty('remoteDeadline');
$prop->setAccessible(true);
$prop->setValue(null, microtime(true) - 1); // already expired
$m = $ref->getMethod('httpGet');
$m->setAccessible(true);
check(Posters::remoteBudgetExpired(), 'expired deadline is detected');
check($m->invoke(null, 'https://example.com/') === null, 'httpGet returns null when remote budget expired');
Posters::endRemoteBudget();
check(!Posters::remoteBudgetExpired(), 'endRemoteBudget clears the deadline');

// Tear down
function rmTree($d) {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$d/$f";
        is_dir($p) ? rmTree($p) : @unlink($p);
    }
    @rmdir($d);
}
rmTree($tmp);
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
