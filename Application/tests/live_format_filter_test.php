<?php
/** Watch Live → Add from server library: media-format filter. php Application/tests/live_format_filter_test.php */
namespace Vaultcast { class Database { public static function basePath() { return '/media/'; } } }
namespace {
class Catalogue {
    public static $cat = array();
    public static function get() { return self::$cat; }
    public static function item($id) { return self::$cat['items'][$id] ?? null; }
}
// load the bridge without the app bootstrap (no DB in unit tests)
$src = file_get_contents(__DIR__ . '/../src/Live/library.php');
$src = str_replace("require_once dirname(__DIR__) . '/bootstrap.php';", '', $src);
$src = str_replace('__DIR__', var_export(realpath(__DIR__ . '/../src/Live'), true), $src);
eval('?>' . $src);
require_once __DIR__ . '/../src/Live/formats.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

check(relay_clean_formats(' MKV,.mp4,mkv,,bad ext,avi') === array('avi', 'mkv', 'mp4'), 'formats normalised: case, dots, dupes, junk');
check(relay_clean_formats(array('mp4', '../x', str_repeat('a', 20))) === array('mp4'), 'unsafe values dropped');
check(relay_clean_formats(null) === array(), 'non-list -> empty');
check(relay_item_format(array('rel' => 'A/B.MKV')) === 'mkv' && relay_item_format(array('ext' => '.Mp4')) === 'mp4', 'item format from ext or path');

$it = function ($id, $title, $ext, $extra = array()) { return array_merge(array('id' => $id, 'media_kind' => 'video', 'type' => 'movies', 'section' => 'movies', 'title' => $title, 'rel' => "$title.$ext", 'path' => "/lib/$title.$ext"), $extra); };
Catalogue::$cat = array('sections' => array(array('id' => 'movies', 'label' => 'Movies', 'type' => 'movies'), array('id' => 'tv', 'label' => 'TV', 'type' => 'tv')), 'items' => array(
    'a' => $it('a', 'Alpha', 'mp4'), 'b' => $it('b', 'Bravo', 'mkv'), 'c' => $it('c', 'Charlie', 'avi'), 'd' => $it('d', 'Delta', 'mkv'),
    'e1' => $it('e1', 'Ep1', 'mkv', array('type' => 'tv', 'section' => 'tv', 'show' => 'Show', 'season' => 1, 'episode' => 1)),
    'e2' => $it('e2', 'Ep2', 'mp4', array('type' => 'tv', 'section' => 'tv', 'show' => 'Show', 'season' => 1, 'episode' => 2)),
    'r' => array('id' => 'r', 'media_kind' => 'game', 'type' => 'roms', 'rel' => 'x.zip'),
));
$r = relay_catalogue_query(array());
$exts = array_column($r['formats'], 'ext');
check($r['ok'] && $exts === array('mkv', 'mp4', 'avi'), 'available formats are the ones present (by count), no zip');
check($r['formats'][0]['count'] === 3, 'format counts');
$titles = function ($r) { $t = array_map(function ($x) { return $x['title'] ?? ($x['show'] ?? ''); }, $r['items']); sort($t); return $t; };
check($titles(relay_catalogue_query(array('formats' => 'mkv'))) === array('Bravo', 'Delta', 'Show'), 'mkv only');
check($titles(relay_catalogue_query(array('formats' => 'mp4,avi'))) === array('Alpha', 'Charlie', 'Show'), 'multiple formats');
check($titles(relay_catalogue_query(array('formats' => 'mkv', 'section' => 'movies'))) === array('Bravo', 'Delta'), 'combines with the All video / section filter');
check($titles(relay_catalogue_query(array('formats' => 'mkv', 'q' => 'del'))) === array('Delta'), 'combines with search');
$r = relay_catalogue_query(array('formats' => 'mkv', 'section' => 'movies'));
check(array_column($r['formats'], 'ext') === array('mkv', 'avi', 'mp4') && $r['format_filter'] === array('mkv'), 'chips list stays complete for the section while filtered');
check(array_column(relay_catalogue_query(array('section' => 'tv'))['formats'], 'ext') === array('mkv', 'mp4'), 'chips follow the selected section');
$show = relay_catalogue_query(array('show' => 'Show', 'formats' => 'mkv'));
check($show['ok'] && count($show['items']) === 1, 'Add show imports only episodes in the chosen formats');
check(count(relay_catalogue_query(array('show' => 'Show'))['items']) === 2, 'no filter = all episodes');

$acct = file_get_contents(__DIR__ . '/../src/Http/routes/account.php');
check(strpos($acct, "relay_clean_formats(\$b['live_cat_formats'])") !== false, 'per-user pref saved (sanitised) via /me/prefs');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, 'function liveCatFormatChips(') !== false && strpos($js, 'live_cat_formats: list') !== false && strpos($js, "'&formats=' + encodeURIComponent(cat.formats.join(','))") !== false, 'UI chips next to All video, persisted per user');

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
}
