<?php
/** Default iptv-org source: seeding, no duplicates, removal sticks, cached browse. php Application/tests/iptv_default_feed_test.php */
namespace Vaultcast { class Repository { public static $docs = array(); public static $writes = 0;
    public static function document($n, $d = null) { return self::$docs[$n] ?? $d; }
    public static function putDocument($n, $v) { self::$docs[$n] = $v; self::$writes++; } } }
namespace {
$tmp = sys_get_temp_dir() . '/rk-iptv-test-' . getmypid();
define('STREAM_ROOT', dirname(__DIR__));
define('STREAM_DATA', $tmp);
@mkdir($tmp . '/live/iptv_cache', 0775, true);
require __DIR__ . '/../src/Live/iptv.php';
use Vaultcast\Repository as R;
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
function feeds() { return iptv_load()['feeds']; }
function ids() { return array_map(function ($f) { return $f['id']; }, feeds()); }

// fresh install
$f = feeds();
check(count($f) === 1 && $f[0]['url'] === IPTV_DEFAULT_FEED_URL && $f[0]['preinstalled'], 'fresh install gets the iptv-org source');
check(!empty(R::$docs['live_iptv']['seededDefaults']['iptv-org']), 'seed is persisted');
iptv_load(); iptv_load();
check(count(feeds()) === 1, 'reloading does not duplicate it');

// remove it -> stays removed
iptv_update_meta(array('feeds' => array()));
check(count(feeds()) === 0, 'user can remove the last source (zero sources allowed)');
check(iptv_load()['activeFeedId'] === 'all', 'active feed falls back to all with no sources');
check(count(feeds()) === 0, 'removed default is not re-added on load');

// disable / re-enable / re-add manually
iptv_update_meta(array('feeds' => array(array('id' => 'iptv-org', 'name' => 'mine', 'url' => IPTV_DEFAULT_FEED_URL, 'enabled' => false))));
check(count(feeds()) === 1 && feeds()[0]['enabled'] === false, 'source can be re-added and disabled');

// migration: older install that already has the URL under another id
R::$docs['live_iptv'] = array('version' => 2, 'feeds' => array(array('id' => 'my-list', 'name' => 'Mine', 'url' => 'https://iptv-org.github.io/iptv/index.m3u/')), 'channels' => array());
check(ids() === array('my-list'), 'migration does not duplicate an existing identical URL');
R::$docs['live_iptv'] = array('version' => 2, 'feeds' => array(array('id' => 'other', 'name' => 'Other', 'url' => 'https://example.com/a.m3u')), 'channels' => array());
check(ids() === array('other', 'iptv-org'), 'migration adds the default once beside existing sources');
iptv_update_meta(array('feeds' => array(array('id' => 'other', 'name' => 'Other', 'url' => 'https://example.com/a.m3u'))));
check(ids() === array('other'), 'removed after migration stays removed');

// browse: cached index, search, pagination, country/category facets
R::$docs['live_iptv'] = null; iptv_load();
$m3u = "#EXTM3U x-tvg-url=\"https://epg.example/guide.xml\"\n";
for ($i = 0; $i < 250; $i++) {
    $cc = array('uk', 'us', 'fr')[$i % 3]; $grp = array('News', 'Sports;Entertainment', 'Undefined')[$i % 3];
    $m3u .= "#EXTINF:-1 tvg-id=\"Chan$i.$cc@HD\" tvg-logo=\"https://l/$i.png\" group-title=\"$grp\",Channel $i (720p)\nhttps://s.example/$i/index.m3u8\n";
}
$m3u .= "#EXTINF:-1 tvg-id=\"\" group-title=\"Music\",No Country\nhttps://s.example/x.m3u8\n";
file_put_contents(iptv_cache_path_feed('iptv-org'), $m3u);
$r = iptv_browse(array('feed' => 'iptv-org', 'limit' => 50, 'offset' => 0));
check($r['ok'] && $r['total'] === 251 && count($r['channels']) === 50, 'paginated browse: 251 total, 50 per page');
check(is_file(iptv_cache_path_feed('iptv-org') . '.index.json'), 'parsed index cached on disk');
$r2 = iptv_browse(array('feed' => 'iptv-org', 'limit' => 50, 'offset' => 200));
check(count($r2['channels']) === 50 && $r2['channels'][0]['name'] === 'Channel 200 (720p)', 'offset paging');
$byCountry = array(); foreach ($r['facets']['countries'] as $c) $byCountry[$c['key']] = $c['count'];
check(($byCountry['GB'] ?? 0) === 84 && ($byCountry['US'] ?? 0) === 83 && ($byCountry['?'] ?? 0) === 1, 'country facet from tvg-id (uk -> GB)');
$cats = array(); foreach ($r['facets']['categories'] as $c) $cats[$c['key']] = $c['count'];
check(($cats['Sports'] ?? 0) === 83 && ($cats['Entertainment'] ?? 0) === 83 && !isset($cats['Undefined']), 'category facet splits ; and drops Undefined');
$r3 = iptv_browse(array('feed' => 'iptv-org', 'country' => 'uk', 'category' => 'news', 'limit' => 200));
check($r3['total'] === 84, 'country + category filter');
$r4 = iptv_browse(array('feed' => 'iptv-org', 'q' => 'Channel 12', 'limit' => 200));
check($r4['total'] >= 1 && $r4['channels'][0]['name'] === 'Channel 12 (720p)', 'search');
check($r['playlistEpgUrls'] === array('https://epg.example/guide.xml'), 'playlist EPG url exposed');
iptv_update_meta(array('feeds' => array(array('id' => 'iptv-org', 'name' => 'x', 'url' => IPTV_DEFAULT_FEED_URL, 'enabled' => false))));
$r5 = iptv_browse(array('feed' => 'all'));
check($r5['total'] === 0, 'disabled source is excluded from All feeds');

array_map('unlink', glob($tmp . '/live/iptv_cache/*')); @rmdir($tmp . '/live/iptv_cache'); @rmdir($tmp . '/live'); @rmdir($tmp);
echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
}
