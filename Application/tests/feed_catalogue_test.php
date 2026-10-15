<?php
/** Download-source checklist: built-in catalogue, enabled filter, normalisation/migration, custom validation, IA torrent mapping. */
declare(strict_types=1);
require __DIR__ . '/../src/Services/FeedCatalogue.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

$b = FeedCatalogue::builtins();
check(count($b) >= 3, 'catalogue has built-in feeds');
$ok = true;
foreach ($b as $f) {
    foreach (['id', 'name', 'description', 'url', 'type'] as $k) if (empty($f[$k])) $ok = false;
    if (strpos($f['url'], 'https://archive.org/') !== 0) $ok = false;
    if (!preg_match('/public.?domain|prelinger|cartoon/i', $f['url'] . $f['name'])) $ok = false;
}
check($ok, 'every built-in is a complete Internet Archive public-domain entry');
check(strpos($b[0]['url'], '{query}') !== false && $b[0]['type'] === 'search', 'search built-in has a {query} template');
$bad = '/nyaa|apibay|pirate|rarbg|yts|1337x|eztv|torrentgalaxy/i';
check(!preg_match($bad, json_encode($b)), 'no piracy sites in the catalogue');

// enabled filter (missing = enabled, legacy strings kept)
$feeds = [['url' => 'https://a.example/rss'], ['url' => 'https://b.example/rss', 'enabled' => false], 'https://c.example/rss', ['url' => 'https://d.example/rss', 'enabled' => true]];
check(array_column(array_slice(FeedCatalogue::active($feeds), 0), 'url') === ['https://a.example/rss', 'https://d.example/rss'] && count(FeedCatalogue::active($feeds)) === 3, 'active() drops only enabled:false entries');
check(FeedCatalogue::active(null) === [], 'active() tolerates missing feeds');

// normalise / migrate existing entries
$legacy = [
    ['url' => 'https://feeds.example.org/rss?q={query}', 'name' => 'Mine', 'type' => 'search'],
    'https://plain.example.org/feed.xml',
    ['url' => $b[1]['url']],
    ['url' => 'https://feeds.example.org/rss?q={query}', 'name' => 'Dupe'],
    ['url' => ''], 42,
    ['url' => 'https://off.example.org/rss', 'name' => 'Off', 'enabled' => 0],
];
$n = FeedCatalogue::normalize($legacy);
check($n['ok'] && count($n['feeds']) === 4, 'normalize keeps user entries, drops blanks/junk/duplicates');
check($n['feeds'][0] === ['url' => 'https://feeds.example.org/rss?q={query}', 'name' => 'Mine', 'type' => 'search'], 'custom entry preserved exactly');
check($n['feeds'][1]['url'] === 'https://plain.example.org/feed.xml' && !isset($n['feeds'][1]['enabled']), 'legacy string entry becomes {url} and stays enabled');
check($n['feeds'][2]['builtin'] === $b[1]['id'] && $n['feeds'][2]['name'] === $b[1]['name'], 'entry matching a built-in URL is tagged');
check($n['feeds'][3]['enabled'] === false, 'disabled state preserved');
$again = FeedCatalogue::normalize($n['feeds']);
check($again['ok'] && $again['feeds'] === $n['feeds'], 'normalize is idempotent');
check(!FeedCatalogue::normalize('nope')['ok'], 'non-list rejected');
check(!FeedCatalogue::normalize([['url' => 'javascript:alert(1)', 'name' => 'x']])['ok'], 'invalid custom URL rejected on save');

// custom validation
check(FeedCatalogue::validateCustom('A', 'https://ex.example/rss?q={query}') === null, 'valid custom feed accepted');
check(FeedCatalogue::validateCustom('', 'https://ex.example/rss') !== null, 'name required');
check(FeedCatalogue::validateCustom('A', 'ftp://ex.example/rss') !== null, 'non-http scheme rejected');
check(FeedCatalogue::validateCustom('A', 'https:// spaced url') !== null, 'spaces rejected');
check(FeedCatalogue::validateCustom('A', 'https://') !== null, 'missing host rejected');

// Internet Archive item → official torrent
check(FeedCatalogue::archiveTorrent('https://archive.org/details/night_of_the_living_dead') === 'https://archive.org/download/night_of_the_living_dead/night_of_the_living_dead_archive.torrent', 'IA details link maps to the official torrent');
check(FeedCatalogue::archiveTorrent('https://example.org/details/x') === '' && FeedCatalogue::archiveTorrent('https://archive.org/details/a/../b') === '', 'non-IA or odd links not mapped');

// wiring
$acq = file_get_contents(__DIR__ . '/../src/Services/Acquisition.php');
check(substr_count($acq, 'FeedCatalogue::active(') >= 4 && strpos($acq, 'FeedCatalogue::archiveTorrent(') !== false, 'Acquisition only uses enabled feeds and maps IA torrents');
$set = file_get_contents(__DIR__ . '/../src/Http/routes/admin-settings.php');
check(strpos($set, 'FeedCatalogue::normalize(') !== false && strpos($set, "\$cur['acquisition']['feeds'] = \$newFeeds") !== false, 'settings save normalises and replaces the list (removals stick)');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, 'function feedSourcesEditor(') !== false && strpos($js, "'Add custom feed'") !== false && strpos($js, 'Advanced: edit JSON') !== false && strpos($js, "id:'feeds_json'") === false, 'UI: checklist, add custom feed, advanced JSON; raw textarea gone');
check(!preg_match('/nyaa\.si\/\?page|apibay\.org\/q\.php/', $js), 'UI no longer suggests piracy-site examples');

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
