<?php
/** Anime absolute episode numbering: parser, conversion (mocked guide + TMDB order), mode round-trip. */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/vc-absnum-' . getmypid();
define('STREAM_DATA', $tmp);
@mkdir($tmp, 0775, true);
class Store {
    public static $docs = []; public static $settings = [];
    public static function get(string $n, $d = null) { return self::$docs[$n] ?? $d; }
    public static function set(string $n, $v): bool { self::$docs[$n] = $v; return true; }
    public static function settings(): array { return self::$settings; }
    public static function invalidate($n = null): void {}
}
class Catalogue {
    public static $cat = ['items' => []];
    public static function get(): array { return self::$cat; }
    public static function save(array $c): bool { self::$cat = $c; return true; }
    public static function fixText($s): string { return trim((string)$s); }
    public static function showKey(string $sid, string $name): string { return substr(sha1($sid . '|' . strtolower($name)), 0, 16); }
    public static function formatEpisodeDisplayTitle($show, $s, $e, $t): string { return trim($show . ' ' . ($s !== null && $e !== null ? sprintf('S%02dE%02d', $s, $e) : '') . ' ' . $t); }
    public static function resolveEpisodeTitle(array $it): string { return (string)($it['episode_title'] ?? ''); }
    public static function seasonEpisodeFromRel(string $rel): array { return ['season' => null, 'episode' => null]; }
    public static function episodeCodeFromName(string $b, bool $f = false): array { return ['season' => null, 'episode' => null, 'explicit' => false, 'method' => '']; }
    public static function localEpisodeLabel(string $rel, string $show = ''): string { return ''; }
    public static function cleanFileTitle(string $b, string $show = ''): string { return ''; }
    public static function parseEpisodeNameFromRel(string $rel, string $show = '', string $h = ''): string { return ''; }
}
require __DIR__ . '/../src/Services/Matching.php';
require __DIR__ . '/../src/Services/AbsoluteNumbering.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
function num($b) { $p = AbsoluteNumbering::parse($b); return $p ? $p['number'] : null; }

// ---- parser
$cases = [
    '[SubsPlease] One Piece - 1071 (1080p) [A1B2C3D4]' => 1071,
    '[Group] Some Title - 123 [1080p]' => 123,
    'Some Title Ep 123' => 123,
    'Some.Title.Ep.07.720p.WEB' => 7,
    'Some Title #123' => 123,
    'Some Title E045 1080p' => 45,
    '[Grp] Title - 12v2 [ABCD1234]' => 12,
    '[Grp] Title - 12 v2 [720p]' => 12,
    '[Grp] Title 2 - 03 [720p]' => 3,
    'Title (2004) - 26 [DVD 480p]' => 26,
    'Title 2019 - 05' => 5,
    '[Grp]_Title_-_08_[BD_1080p_FLAC][9F8E7D6C]' => 8,
    '[Grp] Title - 100 [x265 10bit AAC 2.0]' => 100,
    'Title - 1080p' => null,
    'Title - 2019' => null,
    'Show S01E05 1080p' => null,
    'Show 1x05' => null,
    '[Grp] Title - 01-12 [Batch]' => null,
    '[Grp] Title (01-26) [BD 1080p]' => null,
    'Title 01 ~ 13' => null,
    '[Grp] Title Complete [1080p]' => null,
    'Some Movie Title' => null,
    '[Grp] Title - 0 [720p]' => null,
];
foreach ($cases as $in => $want) check(num($in) === $want, 'parse "' . $in . '" -> ' . var_export($want, true) . ' (got ' . var_export(num($in), true) . ')');
check(AbsoluteNumbering::parse('[Grp] T - 05 [720p]')['fansub'] === true, 'fansub group prefix noticed');

// ---- detection
$fan = ['[Grp] Show - 01 [1080p]', '[Grp] Show - 02 [1080p]', '[Grp] Show - 03 [1080p]'];
check(AbsoluteNumbering::detect($fan, 'tv')['mode'] === 'absolute', 'fansub names -> absolute');
check(AbsoluteNumbering::detect(['Show S01E01', 'Show S01E02'], 'anime')['mode'] === 'season', 'anime with SxxEyy names stays season/episode');
check(AbsoluteNumbering::detect(['Show 01', 'Show 02'], 'tv', ['Anime', 'Action'])['mode'] === 'absolute', 'genre Anime + bare numbers -> absolute');
check(AbsoluteNumbering::detect(['Show 01', 'Show 02'], 'tv', ['Drama'])['mode'] === 'season', 'non-anime bare numbers stay season/episode');

// ---- conversion with a mocked guide: S1 = 3 eps, a special, S2 = 2 eps
$guide = ['id' => 77, 'name' => 'Show', 'genres' => ['Anime'], 'episodes' => [
    ['id' => 1, 'season' => 1, 'number' => 1, 'name' => 'One', 'type' => 'regular'],
    ['id' => 2, 'season' => 1, 'number' => 2, 'name' => 'Two', 'type' => 'regular'],
    ['id' => 3, 'season' => 1, 'number' => 3, 'name' => 'Three', 'type' => 'regular'],
    ['id' => 9, 'season' => 1, 'number' => null, 'name' => 'Recap', 'type' => 'significant_special'],
    ['id' => 8, 'season' => 0, 'number' => 1, 'name' => 'OVA', 'type' => 'regular'],
    ['id' => 5, 'season' => 2, 'number' => 2, 'name' => 'Five', 'type' => 'regular'],
    ['id' => 4, 'season' => 2, 'number' => 1, 'name' => 'Four', 'type' => 'regular'],
]];
$t = AbsoluteNumbering::table($guide);
check($t['source'] === 'cumulative season counts' && count($t['map']) === 5, 'cumulative table excludes specials');
check(AbsoluteNumbering::toSeasonEpisode($t, 4) === ['season' => 2, 'number' => 1, 'name' => 'Four', 'id' => 4], 'abs 4 -> S02E01 Four');
check(AbsoluteNumbering::toAbsolute($t, 2, 2) === 5 && AbsoluteNumbering::toAbsolute($t, 0, 1) === null, 'S02E02 -> 5; specials have no absolute number');
check(AbsoluteNumbering::toSeasonEpisode($t, 6) === null, 'beyond the guide -> null');
$withOrder = $guide + ['absolute_order' => [['absolute' => 1, 'season' => 1, 'number' => 1], ['absolute' => 2, 'season' => 2, 'number' => 1], ['absolute' => 3, 'season' => 1, 'number' => 2]], 'absolute_source' => 'TMDB absolute order'];
$t2 = AbsoluteNumbering::table($withOrder);
check($t2['source'] === 'TMDB absolute order' && $t2['map'][2]['season'] === 2 && $t2['map'][2]['name'] === 'Four', 'provider absolute order wins over cumulative counts');

// TMDB absolute episode group (mocked HTTP)
Store::$settings = ['posters' => ['tmdb_api_key' => 'test-key']];
$asked = [];
AbsoluteNumbering::$http = function ($url) use (&$asked) {
    $asked[] = preg_replace('/api_key=[^&]*/', 'api_key=***', $url);
    if (strpos($url, 'api.tvmaze.com/shows/77') !== false) return json_encode(['id' => 77, 'externals' => ['thetvdb' => 555, 'imdb' => 'tt0000001']]);
    if (strpos($url, '/find/555') !== false) return json_encode(['tv_results' => [['id' => 9001]]]);
    if (strpos($url, '/tv/9001/episode_groups') !== false) return json_encode(['results' => [['id' => 'g-dvd', 'type' => 3], ['id' => 'g-abs', 'type' => 2]]]);
    if (strpos($url, '/tv/episode_group/g-abs') !== false) return json_encode(['groups' => [
        ['order' => 1, 'episodes' => [['order' => 1, 'season_number' => 1, 'episode_number' => 2], ['order' => 0, 'season_number' => 1, 'episode_number' => 1]]],
        ['order' => 0, 'episodes' => [['order' => 0, 'season_number' => 0, 'episode_number' => 1]]],
        ['order' => 2, 'episodes' => [['order' => 0, 'season_number' => 2, 'episode_number' => 1]]],
    ]]);
    return null;
};
$ord = AbsoluteNumbering::tmdbAbsoluteOrder(77, true);
check(is_array($ord) && array_map(function ($o) { return $o['absolute'] . ':' . $o['season'] . 'x' . $o['number']; }, $ord) === ['1:1x1', '2:1x2', '3:2x1'], 'TMDB absolute group parsed in order, specials skipped');
check(count(array_filter($asked, function ($u) { return strpos($u, 'g-dvd') !== false; })) === 0, 'only the Absolute (type 2) group is fetched');
$n = count($asked);
AbsoluteNumbering::tmdbAbsoluteOrder(77);
check(count($asked) === $n, 'absolute order is cached');
Store::$settings = [];
check(AbsoluteNumbering::tmdbAbsoluteOrder(78) === null, 'no TMDB key -> no provider order (falls back to cumulative)');

// ---- preview / apply / round-trip on a mocked show
$files = ['[Grp] Show - 01 [1080p][AAAA1111].mkv', '[Grp] Show - 02 [1080p].mkv', '[Grp] Show - 04v2 [1080p].mkv', '[Grp] Show - 05 [1080p].mkv', 'Show - NCOP.mkv'];
$items = [];
foreach ($files as $i => $f) {
    $id = sprintf('%016x', $i + 1);
    $items[$id] = ['id' => $id, 'type' => 'anime', 'section' => 'anime', 'show' => 'Show', 'rel' => 'Show/' . $f, 'season' => null, 'episode' => $i + 1, 'episode_title' => null, 'title' => 'Show'];
}
$items['0000000000000003']['season'] = 1; $items['0000000000000003']['episode'] = 4; // pre-existing manual fix
Catalogue::$cat = ['items' => $items];
Store::$docs['match_overrides'] = ['items' => ['0000000000000003' => ['season' => 1, 'episode' => 4, 'source' => 'manual', 'by' => 'x', 'at' => 'y']], 'shows' => []];
Store::$docs['progress_probe'] = ['0000000000000004' => 600];
$key = Catalogue::showKey('anime', 'Show');
$pr = AbsoluteNumbering::preview($key, ['_guide' => $guide]);
check($pr['ok'] && $pr['stored_mode'] === 'auto' && $pr['effective'] === 'absolute', 'auto-detects absolute for the fansub show');
$by = []; foreach ($pr['rows'] as $r) $by[$r['file']] = $r;
check($by[$files[2]]['detected'] === 4 && $by[$files[2]]['season'] === 2 && $by[$files[2]]['episode'] === 1 && $by[$files[2]]['episode_title'] === 'Four', 'preview: 04v2 -> S02E01 Four');
check($by[$files[4]]['detected'] === null && !$by[$files[4]]['changed'], 'file without a number left alone');
$pr2 = AbsoluteNumbering::preview($key, ['_guide' => $guide, 'numbers' => ['0000000000000002' => 3]]);
$r2 = []; foreach ($pr2['rows'] as $r) $r2[$r['id']] = $r;
check($r2['0000000000000002']['season'] === 1 && $r2['0000000000000002']['episode'] === 3, 'per-file number override remaps');

$res = AbsoluteNumbering::apply($key, ['mode' => 'absolute', '_guide' => $guide]);
$c = Catalogue::$cat['items'];
check($res['ok'] && $res['changed'] === 4 && AbsoluteNumbering::storedMode($key) === 'absolute', 'apply absolute: mode stored, 4 files relabelled');
check($c['0000000000000001']['season'] === 1 && $c['0000000000000001']['episode'] === 1 && $c['0000000000000004']['season'] === 2 && $c['0000000000000004']['episode'] === 2, 'catalogue now S01E01 … S02E02');
check(array_keys($c) === array_keys($items) && Store::$docs['progress_probe'] === ['0000000000000004' => 600], 'item ids unchanged, so watch progress stays attached');
$again = AbsoluteNumbering::apply($key, ['mode' => 'absolute', '_guide' => $guide]);
check($again['changed'] === 0 && Catalogue::$cat === ['items' => $c] + Catalogue::$cat, 're-running is idempotent');

// Auto-match respects the absolute mode
$prop = (function () use ($key, $guide) {
    // proposeShow fetches the guide itself; reuse the cached by-id file path
    @mkdir(STREAM_DATA . '/tvmaze', 0775, true);
    file_put_contents(STREAM_DATA . '/tvmaze/byid_77.json', json_encode($guide));
    $o = Store::$docs['match_overrides']; $o['shows'][$key] = ['provider' => 'tvmaze', 'tvmaze_id' => 77]; Store::$docs['match_overrides'] = $o;
    return Matching::proposeShow($key);
})();
$m = []; foreach ($prop['rows'] as $r) $m[$r['file']] = $r['proposal'];
check($m[$files[3]]['method'] === 'absolute' && $m[$files[3]]['season'] === 2 && $m[$files[3]]['episode'] === 2, 'Auto-match uses the absolute mapping too');

// switch back: restore earlier labels (incl. the manual override), then forward again
$back = AbsoluteNumbering::apply($key, ['mode' => 'season', '_guide' => $guide]);
$c = Catalogue::$cat['items'];
check($back['ok'] && $back['restored'] === 4 && AbsoluteNumbering::storedMode($key) === 'season', 'switch to season/episode restores 4 files');
check($c['0000000000000001']['season'] === null && $c['0000000000000001']['episode'] === 1 && $c['0000000000000003']['season'] === 1 && $c['0000000000000003']['episode'] === 4, 'previous labels back');
check((Store::$docs['match_overrides']['items']['0000000000000003']['source'] ?? '') === 'manual' && !isset(Store::$docs['match_overrides']['items']['0000000000000001']), 'manual override restored, absolute overrides removed');
$fwd = AbsoluteNumbering::apply($key, ['mode' => 'absolute', '_guide' => $guide, 'rows' => [['id' => '0000000000000002', 'absolute' => 2]]]);
check($fwd['changed'] === 1 && Catalogue::$cat['items']['0000000000000002']['episode'] === 2 && Catalogue::$cat['items']['0000000000000001']['season'] === null, 'apply only selected rows');
check(!AbsoluteNumbering::apply($key, ['mode' => 'weird'])['ok'], 'unknown mode rejected');

// wiring
$routes = file_get_contents(__DIR__ . '/../src/Http/routes/admin-library.php');
check(strpos($routes, '/numbering/preview') !== false && strpos($routes, "unset(\$nb['_guide'])") !== false, 'admin routes added (test-only guide input stripped)');
$js = file_get_contents(__DIR__ . '/../assets/js/matching.js');
check(strpos($js, 'function numberingPanel(') !== false && strpos($js, "'Detected #'") !== false && strpos($js, 'Absolute (overall episode number)') !== false, 'Fix Match UI: mode toggle + preview table');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
