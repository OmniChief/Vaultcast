<?php
/** ?channel=ch-<number> ids: assignment, collisions, legacy ids, renumber aliases, exports. php Application/tests/live_channel_slugs_test.php */
namespace Vaultcast { class Repository { public static $docs = array();
    public static function document($n, $d = null) { return self::$docs[$n] ?? $d; }
    public static function putDocument($n, $v) { self::$docs[$n] = $v; } } }
namespace {
define('STREAM_ROOT', dirname(__DIR__));
date_default_timezone_set('Europe/London');
function relay_load_station() { return $GLOBALS['station']; }
function relay_save_station($s) { $GLOBALS['station'] = $s; return $s; }
require __DIR__ . '/../src/Live/schedule.php';
require __DIR__ . '/../src/Live/tvstudio.php';
require __DIR__ . '/../src/Live/channel_slugs.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

$relay = array(array('id' => 'ch-u8f1qa', 'number' => 1, 'name' => 'One'), array('id' => 'ch-zz9k2p', 'number' => 2, 'name' => 'Two'), array('id' => 'ch-aa1111', 'number' => 100, 'name' => 'Hundred'));
$iptv = array(array('id' => 'bbc1', 'number' => 100, 'name' => 'IPTV A'), array('id' => 'abc', 'number' => 100, 'name' => 'IPTV B'), array('id' => 'news', 'number' => 101, 'name' => 'News'));
$s = live_channel_slugs($relay, $iptv);
$by = array(); foreach ($s as $x) $by[$x['kind'] . ':' . $x['id']] = $x['slug'];
check($by['relay:ch-u8f1qa'] === 'ch-1' && $by['relay:ch-zz9k2p'] === 'ch-2', 'relay channels get ch-<number>');
check($by['iptv:news'] === 'ch-101', 'IPTV channels use the same structure');
check($by['relay:ch-aa1111'] === 'ch-100' && $by['iptv:abc'] === 'ch-100-2' && $by['iptv:bbc1'] === 'ch-100-3', 'collisions: relay keeps ch-N, others get -2, -3 in stable id order');
$s2 = live_channel_slugs(array_reverse($relay), array_reverse($iptv));
$by2 = array(); foreach ($s2 as $x) $by2[$x['kind'] . ':' . $x['id']] = $x['slug'];
check($by2 === $by, 'assignment does not depend on list order');

// resolution
$r = live_channel_resolve('ch-1', $s);
check($r && $r['kind'] === 'relay' && $r['id'] === 'ch-u8f1qa', 'ch-1 resolves');
$r = live_channel_resolve('ch-u8f1qa', $s);
check($r && $r['id'] === 'ch-u8f1qa' && $r['slug'] === 'ch-1', 'old random id still resolves (alias)');
check(live_channel_resolve('relay.ch-zz9k2p', $s)['slug'] === 'ch-2' && live_channel_resolve('iptv.news', $s)['slug'] === 'ch-101', 'old relay./iptv. tvg-ids resolve');
check(live_channel_resolve('CH-101', $s)['id'] === 'news', 'case-insensitive');
check(live_channel_resolve('ch-999', $s) === null && live_channel_resolve('', $s) === null, 'unknown channel -> null');

// renumbering: remember old slugs; current owner wins
list($aliases) = live_channel_remember(array(), $s);
$relay2 = $relay; $relay2[0]['number'] = 5;           // One: 1 -> 5
$s3 = live_channel_slugs($relay2, $iptv);
list($aliases, $changed) = live_channel_remember($aliases, $s3);
$r = live_channel_resolve('ch-1', $s3, $aliases);
check($changed && $r && $r['id'] === 'ch-u8f1qa' && $r['slug'] === 'ch-5', 'after renumber, old ch-1 still opens the same channel (now ch-5)');
$relay3 = $relay2; $relay3[1]['number'] = 1;           // Two takes number 1
$s4 = live_channel_slugs($relay3, $iptv);
list($aliases) = live_channel_remember($aliases, $s4);
check(live_channel_resolve('ch-1', $s4, $aliases)['id'] === 'ch-zz9k2p', 'a channel that now owns the number wins over the old alias');
check(live_channel_resolve('ch-2', $s4, $aliases)['id'] === 'ch-zz9k2p', 'and its own old number still resolves to it');
list(, $again) = live_channel_remember($aliases, $s4);
check($again === false, 'remembering is idempotent');
$gone = live_channel_slugs(array(), array());
check(live_channel_resolve('ch-5', $gone, $aliases) === null, 'alias of a deleted channel does not resolve');

// exports
$GLOBALS['station'] = array('mediaBaseUrl' => '', 'cycles' => array(), 'media' => array(), 'channels' => array(
    array('id' => 'ch-u8f1qa', 'number' => 1, 'name' => 'One', 'enabled' => true), array('id' => 'ch-zz9k2p', 'number' => 2, 'name' => 'Two', 'enabled' => true)));
tvs_save(array('epg' => array('includeIptv' => false)));
$m3u = tvs_playlist_m3u($GLOBALS['station'], 'http://example.local/media/');
check(strpos($m3u, 'api/live/m3u?channel=ch-1') !== false && strpos($m3u, 'api/live/m3u?channel=ch-2') !== false && strpos($m3u, 'ch-u8f1qa') === false, 'lineup M3U uses ch-<number> URLs');
check(strpos($m3u, 'tvg-id="ch-1"') !== false, 'tvg-id matches the channel id');
$xml = tvs_xmltv($GLOBALS['station'], 1);
check(strpos($xml, '<channel id="ch-1">') !== false && strpos($xml, 'relay.ch-u8f1qa') === false, 'XMLTV channel ids match the M3U tvg-ids');
check(isset(\Vaultcast\Repository::$docs['live_channel_aliases']['ch-1']), 'exported slugs are remembered for aliasing');

$route = file_get_contents(__DIR__ . '/../src/Http/routes/live.php');
check(strpos($route, 'live_channel_resolve(') !== false && strpos($route, "'channels'];") !== false, 'm3u endpoint resolves slugs/aliases; channels action listed');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, 'function liveOutputRows(') !== false && strpos($js, "encodeURIComponent(ch.id)") === false, 'player output links use channel numbers');

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
}
