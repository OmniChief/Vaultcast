<?php
/** Standalone checks for the TV Studio scheduler: php Application/tests/tvstudio_test.php */
namespace Vaultcast { class Repository { public static $docs = array();
    public static function document($n, $d = null) { return self::$docs[$n] ?? $d; }
    public static function putDocument($n, $v) { self::$docs[$n] = $v; } } }
namespace {
date_default_timezone_set('Europe/London');
function relay_load_station() { return $GLOBALS['station']; }
function relay_save_station($s) { $GLOBALS['station'] = $s; return $s; }
require __DIR__ . '/../src/Live/schedule.php';
require __DIR__ . '/../src/Live/tvstudio.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$m = function ($id, $kind, $min, $extra = array()) { return array_merge(array('id' => $id, 'kind' => $kind, 'title' => $id, 'url' => "/m/$id.mp4", 'durationMs' => $min * 60000), $extra); };
$GLOBALS['station'] = array('mediaBaseUrl' => '', 'channels' => array(array('id' => 'c1', 'number' => 1, 'name' => 'One', 'enabled' => true)), 'cycles' => array(),
  'media' => array($m('mov', 'movie', 100), $m('ep1', 'episode', 22, array('title' => '', 'series' => 'Show', 'season' => 1, 'episode' => 1)), $m('ep2', 'episode', 22, array('title' => '', 'series' => 'Show', 'season' => 1, 'episode' => 2)),
    $m('f1', 'bumper', 7), $m('f2', 'bumper', 5), $m('ad1', 'commercial', 0.5), $m('ad2', 'commercial', 0.5)));
$st = tvs_save(array('timezone' => 'Europe/London', 'epg' => array('includeIptv' => false),
  'fillerLists' => array(array('id' => 'fl', 'name' => 'Filler', 'mediaIds' => array('f1', 'f2'), 'order' => 'sequential')),
  'adPools' => array(array('id' => 'ap', 'name' => 'Ads', 'mediaIds' => array('ad1', 'ad2'), 'order' => 'sequential')),
  'channels' => array('c1' => array('mode' => 'calendar', 'fillerListId' => 'fl',
     'ads' => array('enabled' => true, 'poolId' => 'ap', 'everyMinutes' => 15, 'breakSeconds' => 60),
     'slots' => array(
        array('id' => 's1', 'title' => 'Movie night', 'days' => array(0,1,2,3,4,5,6), 'startMin' => 20 * 60, 'durationMin' => 120, 'contentType' => 'media', 'mediaIds' => array('mov')),
        array('id' => 's2', 'title' => 'Show', 'days' => array(1, 3), 'startMin' => 18 * 60, 'durationMin' => 30, 'contentType' => 'series', 'series' => 'Show', 'ads' => false))))));
$station = $GLOBALS['station'];
$tue = (new DateTime('2026-10-13 00:00', new DateTimeZone('Europe/London')))->getTimestamp() * 1000;
$day = relay_materialize($station, 'c1', $tue, $tue + 86400000);
$cov = 0; $prevEnd = $tue; $gap = false;
foreach ($day as $b) { if ($b['startUnix'] > $prevEnd + 1) $gap = true; $prevEnd = max($prevEnd, $b['endUnix']); }
check(!$gap && $prevEnd >= $tue + 86400000, 'calendar mode covers the whole day with no gaps');
$at20 = relay_air_block($station, 'c1', $tue + 20 * 3600000 + 60000);
check($at20 && $at20['mediaId'] === 'mov' && $at20['slotId'] === 's1', 'movie slot airs at 20:01');
$ads = array_filter($day, function ($b) { return !empty($b['advert']) && ($b['slotId'] ?? '') === 's1'; });
check(count($ads) >= 6, 'ad breaks inserted into movie slot (' . count($ads) . ' adverts)');
$resume = array_values(array_filter($day, function ($b) { return $b['mediaId'] === 'mov' && $b['mediaOffsetMs'] > 0; }));
check($resume && $resume[0]['mediaOffsetMs'] === 15 * 60000, 'programme resumes at its offset after a break');
$at18 = relay_air_block($station, 'c1', $tue + 18 * 3600000 + 60000);
check($at18 && $at18['kind'] === 'episode', 'Tuesday 18:00 series slot airs an episode');
$at1801b = relay_air_block($station, 'c1', $tue + 18 * 3600000 + 60000);
check($at18['mediaId'] === $at1801b['mediaId'], 'deterministic for the same instant');
$at10 = relay_air_block($station, 'c1', $tue + 10 * 3600000);
check($at10 && !empty($at10['filler']), 'filler plays when nothing is scheduled');
$a = relay_materialize($station, 'c1', $tue + 10 * 3600000, $tue + 11 * 3600000);
$b = relay_materialize($station, 'c1', $tue + 9 * 3600000, $tue + 12 * 3600000);
$bk = array(); foreach ($b as $x) $bk[$x['startUnix']] = $x['mediaId'];
$same = true; foreach ($a as $x) if (($bk[$x['startUnix']] ?? null) !== $x['mediaId']) $same = false;
check($same, 'different windows agree on the same timeline');
$wed = relay_air_block($station, 'c1', $tue + 86400000 + 18 * 3600000 + 60000);
check($wed && !empty($wed['filler']), 'no series slot on Wednesday');
$thu = relay_air_block($station, 'c1', $tue + 2 * 86400000 + 18 * 3600000 + 60000);
check($thu && $thu['mediaId'] !== $at18['mediaId'], 'series advances to the next episode on the next airing');
// overlay mode keeps the Relay cycle outside slots
$s2 = tvs_load(); $s2['channels']['c1']['mode'] = 'overlay'; tvs_save($s2);
$station['cycles']['c1'] = array('epoch' => 0, 'totalMs' => 100 * 60000, 'blocks' => array(array('mediaId' => 'mov', 'durationMs' => 100 * 60000, 'title' => 'Cycle', 'subtitle' => '', 'kind' => 'movie', 'generated' => false, 'url' => '/m/mov.mp4')));
$o = relay_air_block($station, 'c1', $tue + 10 * 3600000);
check($o && $o['title'] === 'Cycle', 'overlay mode falls back to the Relay cycle outside slots');
$xml = tvs_xmltv($station, 1);
check(strpos($xml, '<programme') !== false && (!function_exists('simplexml_load_string') || @simplexml_load_string($xml) !== false), 'XMLTV export is valid XML with programmes');
check(count(tvs_lint(tvs_load(), $station)) === 0, 'no lint warnings for a valid setup');
$bad = tvs_load(); $bad['channels']['c1']['slots'][] = array('id' => 'x', 'days' => array(0), 'startMin' => 20 * 60 + 30, 'durationMin' => 60, 'contentType' => 'media', 'mediaIds' => array('mov'));
check(count(tvs_lint(tvs_normalize_state($bad), $station)) === 1, 'overlapping slot is reported');
echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
}
