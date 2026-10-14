<?php
/** Fix Match: the picked result's synopsis is pinned and never replaced by a title lookup. php Application/tests/fix_match_test.php */
$tmp = sys_get_temp_dir() . '/rk-fixmatch-test-' . getmypid();
define('STREAM_DATA', $tmp);
@mkdir($tmp, 0775, true);
require __DIR__ . '/../src/Services/Matching.php';
require __DIR__ . '/../src/Services/Posters.php';
require __DIR__ . '/../src/Services/PublicMetadata.php';
require __DIR__ . '/../src/Services/MetadataPins.php';
MetadataPins::$memory = [];
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null) { return $l === null ? substr($s, $a) : substr($s, $a, $l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower($s); } }
if (!class_exists('Catalogue')) { class Catalogue { public static function fixText($s) { return (string)$s; } } }
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

$id = 'abcdef0123456789';
$runner = 'Runner is a 2026 American action film starring Owen Wilson and Alan Richardson.';
$body = ['title' => 'Runner', 'year' => 2026, 'match' => ['source' => 'wikipedia', 'id' => 81234567,
    'title' => 'Runner', 'year' => 2026, 'summary' => $runner, 'url' => 'https://en.wikipedia.org/wiki/Runner_(2026_film)']];

$rec = Matching::chosenMovieSynopsis($body, 'Runner', 2026);
check(is_array($rec) && $rec['pinned'] && $rec['summary'] === $runner, 'chosen result synopsis becomes a pinned record');
check($rec['provider'] === 'wikipedia' && $rec['provider_id'] === '81234567', 'record keeps the exact provider and id');
check(Matching::chosenMovieSynopsis(['title' => 'Runner'], 'Runner', 2026) === null, 'no pick -> no pinned record');
check(Matching::chosenMovieSynopsis(['match' => ['source' => 'x', 'id' => 1, 'summary' => '']], 'Runner', null) === null, 'empty summary is not pinned');

// a stale title-guess cache (the locomotion article) is what the admin saw; rematch replaces it with the pinned one
$cache = Posters::movieSynopsisCachePath($id);
file_put_contents($cache, json_encode(['title' => 'Runner', 'summary' => 'Running is a method of terrestrial locomotion...']));
file_put_contents($cache, json_encode($rec));
check(Posters::movieSynopsis($id, 'Runner', 2026) === $runner, 'detail page shows the picked film, not "Running"');
check(Posters::movieSynopsis($id, 'Runner', 2026, true) === $runner, 'a refresh does not re-guess a pinned synopsis by title');

// Poster: the chosen Runner result has no Wikipedia thumbnail; the old code then fell back to a
// bare "Runner" lookup that returned the Running article's photo.
$runnerMatch = $body['match'] + ['page_title' => 'Runner (2026 American film)', 'image' => null];
$pin = Matching::chosenMoviePoster(['match' => $runnerMatch]);
check(is_array($pin) && $pin['pinned'] && $pin['provider'] === 'wikipedia' && $pin['page_title'] === 'Runner (2026 American film)', 'poster pin keeps the chosen page');
$asked = [];
$urls = Posters::pinnedPosterUrls($pin, function ($page) use (&$asked) { $asked[] = $page; return null; });
check($urls === [] && $asked === ['Runner (2026 American film)'], 'pinned poster only looks at the chosen page, never bare "Runner"');
$pin2 = Matching::chosenMoviePoster(['match' => $runnerMatch, 'image' => 'https://example.org/runner.jpg']);
check(Posters::pinnedPosterUrls($pin2, function () { return null; }) === ['https://example.org/runner.jpg'], 'chosen image URL is used first');
check(Matching::chosenMoviePoster(['image' => 'https://x/y.jpg']) === null, 'no pick -> no poster pin');
check(!Posters::wikiIsFilm(['title' => 'Running', 'description' => 'Method of terrestrial locomotion', 'extract' => 'Running is a method of terrestrial locomotion...']), 'title fallback rejects the Running article');
check(Posters::wikiIsFilm(['title' => 'Runner (2026 American film)', 'description' => '2026 film']), 'title fallback accepts a film page');

// Source choice: OMDb without a key is listed but unavailable with a Settings hint, never an error.
PublicMetadata::$omdbKey = '';
$src = []; foreach (PublicMetadata::sources('movie') as $x) $src[$x['id']] = $x;
check(isset($src['wikipedia'], $src['itunes'], $src['omdb']) && !isset($src['tvmaze']), 'movie sources: Wikipedia, iTunes, OMDb');
check(!$src['omdb']['available'] && strpos($src['omdb']['hint'], 'Settings') !== false, 'OMDb without key is unavailable with a Settings hint');
$tvSrc = array_column(PublicMetadata::sources('tv'), 'id');
check(in_array('tvmaze', $tvSrc, true) && in_array('omdb', $tvSrc, true) && in_array('wikipedia', $tvSrc, true), 'TV sources include TVmaze, Wikipedia, OMDb');
$calls = 0;
PublicMetadata::$fetch = function ($url) use (&$calls) { $calls++; return []; };
$r = PublicMetadata::search('movie', 'Runner 2026', 'omdb');
check($r['ok'] && $r['results'] === [] && !empty($r['unavailable']) && $calls === 0, 'searching OMDb without key returns a hint, no request');

// With a key, OMDb rows carry their own id, poster and plot, labelled by source.
PublicMetadata::$omdbKey = 'k';
PublicMetadata::$fetch = function ($url) {
    if (strpos($url, 'omdbapi.com') !== false && strpos($url, 's=Runner') !== false) return ['Search' => [['imdbID' => 'tt9990001', 'Title' => 'Runner', 'Year' => '2026', 'Poster' => 'https://img.example/runner.jpg']]];
    if (strpos($url, 'i=tt9990001') !== false) return ['Plot' => 'Owen Wilson and Alan Richardson star.', 'Genre' => 'Action, Drama'];
    return [];
};
$r = PublicMetadata::search('movie', 'Runner 2026', 'omdb');
$o = $r['results'][0] ?? [];
check(($o['source'] ?? '') === 'omdb' && $o['id'] === 'tt9990001' && $o['source_label'] === 'OMDb', 'OMDb result keeps its IMDb id and label');
check($o['image'] === 'https://img.example/runner.jpg' && strpos($o['summary'], 'Owen Wilson') !== false, 'OMDb result has poster + synopsis preview');
$pinO = Matching::chosenMoviePoster(['match' => $o]);
check(Posters::pinnedPosterUrls($pinO, function () { return null; }) === ['https://img.example/runner.jpg'], 'picked OMDb poster is the pinned poster');
check(Matching::chosenMovieSynopsis(['match' => $o], 'Runner', 2026)['provider_id'] === 'tt9990001', 'picked OMDb synopsis pins the IMDb id');

// TV: a non-TVmaze pick pins its own synopsis/poster for the show.
$meta = Matching::chosenShowMeta(['source' => 'wikipedia', 'id' => 42, 'summary' => 'A TV series.', 'image' => 'https://img.example/s.jpg']);
$showKey = '0123456789abcdef';
file_put_contents(Posters::showMetaPinPath($showKey), json_encode($meta));
check(Posters::showSynopsis($showKey, 'Whatever') === 'A TV series.', 'pinned show synopsis wins over a TVmaze name lookup');

array_map('unlink', glob($tmp . '/synopsis/*') ?: []); @rmdir($tmp . '/synopsis'); @rmdir($tmp);
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
