<?php
/** Find collections: TMDB + Wikidata lookups (mocked), idempotent upsert, manual collections untouched, resumable steps. */
declare(strict_types=1);
class Store {
    public static $docs = []; public static $s = [];
    public static function get(string $n, $d = null) { return self::$docs[$n] ?? $d; }
    public static function set(string $n, $v): bool { self::$docs[$n] = $v; return true; }
    public static function settings(): array { return self::$s; }
}
function json_error($m, $c = 400) { throw new RuntimeException($m); }
require __DIR__ . '/../src/Services/Collections.php';
require __DIR__ . '/../src/Services/CollectionDiscovery.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

CollectionDiscovery::$sleep = function ($s) {};
CollectionDiscovery::$items = [
    'a1' => ['id' => 'a1', 'title' => 'Alien', 'year' => 1979, 'type' => 'movies', 'media_kind' => 'video', 'imdb_id' => 'tt0078748'],
    'a2' => ['id' => 'a2', 'title' => 'Aliens', 'year' => 1986, 'type' => 'movies', 'media_kind' => 'video'],
    'm1' => ['id' => 'm1', 'title' => 'Heat', 'year' => 1995, 'type' => 'movies', 'media_kind' => 'video'],
    'b1' => ['id' => 'b1', 'title' => 'Back to the Future', 'year' => 1985, 'type' => 'movies', 'media_kind' => 'video'],
    'e1' => ['id' => 'e1', 'title' => 'Pilot', 'show' => 'Some Show', 'season' => 1, 'episode' => 1, 'type' => 'tv', 'media_kind' => 'video'],
    'r1' => ['id' => 'r1', 'title' => 'Game', 'type' => 'roms', 'media_kind' => 'rom'],
];
check(array_keys(CollectionDiscovery::films()) === ['a1', 'a2', 'm1', 'b1'], 'only library films are looked up (no episodes / ROMs)');

// Manual collection that must stay untouched
Store::$docs['collections'] = [['id' => 'manual1', 'name' => 'Back to the Future', 'type' => 'movies', 'parts' => [['title' => 'My cut', 'year' => 2000]]]];
$manualBefore = Store::$docs['collections'][0];

// ---- TMDB ----
Store::$s = ['posters' => ['tmdb_api_key' => 'TESTKEY']];
$calls = [];
$rate = 1;
CollectionDiscovery::$http = function ($url, $h) use (&$calls, &$rate) {
    $calls[] = $url;
    $p = parse_url($url); parse_str($p['query'] ?? '', $q);
    $path = substr($p['path'], 2);
    if ($path === '/find/tt0078748') return [200, json_encode(['movie_results' => [['id' => 348]]])];
    if ($path === '/search/movie') {
        if ($q['query'] === 'Aliens' && $rate-- > 0) return [429, '{}'];
        $map = ['Aliens' => [['id' => 679, 'title' => 'Aliens', 'release_date' => '1986-07-18']], 'Heat' => [['id' => 949, 'title' => 'Heat', 'release_date' => '1995-12-15']],
            'Back to the Future' => [['id' => 105, 'title' => 'Back to the Future', 'release_date' => '1985-07-03']]];
        return [200, json_encode(['results' => $map[$q['query']] ?? []])];
    }
    if ($path === '/movie/348') return [200, json_encode(['id' => 348, 'title' => 'Alien', 'release_date' => '1979-05-25', 'belongs_to_collection' => ['id' => 8091, 'name' => 'Alien Collection']])];
    if ($path === '/movie/679') return [200, json_encode(['id' => 679, 'title' => 'Aliens', 'release_date' => '1986-07-18', 'belongs_to_collection' => ['id' => 8091, 'name' => 'Alien Collection']])];
    if ($path === '/movie/949') return [200, json_encode(['id' => 949, 'title' => 'Heat', 'release_date' => '1995-12-15', 'belongs_to_collection' => null])];
    if ($path === '/movie/105') return [200, json_encode(['id' => 105, 'title' => 'Back to the Future', 'release_date' => '1985-07-03', 'belongs_to_collection' => ['id' => 264, 'name' => 'Back to the Future Collection']])];
    if ($path === '/collection/8091') return [200, json_encode(['id' => 8091, 'name' => 'Alien Collection', 'overview' => 'Xenomorphs.', 'poster_path' => '/p.jpg', 'parts' => [
        ['id' => 348, 'title' => 'Alien', 'release_date' => '1979-05-25'], ['id' => 679, 'title' => 'Aliens', 'release_date' => '1986-07-18'], ['id' => 8077, 'title' => 'Alien³', 'release_date' => '1992-05-22']]])];
    if ($path === '/collection/264') return [200, json_encode(['id' => 264, 'name' => 'Back to the Future Collection', 'parts' => [['id' => 105, 'title' => 'Back to the Future', 'release_date' => '1985-07-03']]])];
    return [404, '{}'];
};
check(CollectionDiscovery::source() === 'tmdb', 'TMDB used when a key is configured');
$p = CollectionDiscovery::start();
check($p['status'] === 'running' && $p['total'] === 4 && !isset($p['queue']), 'job started, queue kept server-side');
$p = CollectionDiscovery::step(30);
check(($p['wait_ms'] ?? 0) > 0 && $p['status'] === 'running' && $p['done'] === 1, '429 pauses the step and keeps the film for retry');
$p = CollectionDiscovery::step(30);
check($p['status'] === 'done' && $p['done'] === 4, 'resumes and finishes');
check($p['found'] === 3 && $p['created'] === 1 && $p['skipped_manual'] === 1, 'summary: 3 found, 1 created, manual name left alone (got ' . json_encode([$p['found'], $p['created'], $p['updated'], $p['skipped_manual']]) . ')');
check(strpos(implode(' ', $calls), 'api_key=TESTKEY') !== false && count(array_filter($calls, function ($u) { return strpos($u, '/collection/8091') !== false; })) === 1, 'each collection fetched once per run');
$cols = Store::$docs['collections'];
check(count($cols) === 2 && $cols[0] === $manualBefore, 'manual collection unchanged, no duplicate');
$alien = $cols[1];
check($alien['source_id'] === 'tmdb:collection:8091' && $alien['poster_url'] === 'https://image.tmdb.org/t/p/w500/p.jpg' && $alien['description'] === 'Xenomorphs.', 'collection details and poster stored');
$byTitle = []; foreach ($alien['parts'] as $pt) $byTitle[$pt['title']] = $pt;
check(($byTitle['Alien']['item_id'] ?? '') === 'a1' && ($byTitle['Aliens']['item_id'] ?? '') === 'a2' && isset($byTitle['Alien³']) && empty($byTitle['Alien³']['item_id']), 'library films linked, missing part listed');

// Re-run: idempotent (linked films skipped, nothing duplicated)
$p = CollectionDiscovery::start();
check($p['total'] === 2, 're-run skips films already linked to a collection');
$p = CollectionDiscovery::step(30);
check(count(Store::$docs['collections']) === 2 && $p['created'] === 0, 're-run creates no duplicates');

// Extend: a new part appears upstream -> existing auto collection updated
Store::$docs['collections'][1]['parts'] = array_values(array_filter(Store::$docs['collections'][1]['parts'], function ($x) { return $x['title'] !== 'Alien³'; }));
Store::$docs['collections'][1]['parts'][0]['item_id'] = null; // unlink Alien so it is queued again
$p = CollectionDiscovery::start(); $p = CollectionDiscovery::step(30);
$titles = array_column(Store::$docs['collections'][1]['parts'], 'title');
check($p['updated'] === 1 && $p['created'] === 0 && in_array('Alien³', $titles, true) && count($titles) === 3, 'existing collection extended, not duplicated');

// Cancel + resume
CollectionDiscovery::start();
$c = CollectionDiscovery::cancel();
check($c['status'] === 'cancelled', 'cancel');
$p = CollectionDiscovery::step(30);
check($p['status'] === 'done', 'step after cancel resumes');

// ---- Wikidata fallback (no key) ----
Store::$s = [];
Store::$docs['collections'] = [];
$sparql = [];
CollectionDiscovery::$http = function ($url, $h) use (&$sparql) {
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $sparql[] = $q['query'];
    $ua = implode(' ', $h);
    if (strpos($ua, 'User-Agent: Vaultcast') === false) return [403, ''];
    if (strpos($q['query'], 'P345 "tt0078748"') !== false || strpos($q['query'], 'rdfs:label "Aliens"@en') !== false)
        return [200, json_encode(['results' => ['bindings' => [['film' => ['value' => 'http://www.wikidata.org/entity/Q1'], 'series' => ['value' => 'http://www.wikidata.org/entity/Q2'], 'seriesLabel' => ['value' => 'Alien']]]]])];
    if (strpos($q['query'], 'wdt:P179 wd:Q2') !== false)
        return [200, json_encode(['results' => ['bindings' => [
            ['film' => ['value' => 'http://www.wikidata.org/entity/Q1'], 'filmLabel' => ['value' => 'Alien'], 'y' => ['value' => '1979']],
            ['film' => ['value' => 'http://www.wikidata.org/entity/Q3'], 'filmLabel' => ['value' => 'Aliens'], 'y' => ['value' => '1986']]]]])];
    return [200, json_encode(['results' => ['bindings' => []]])];
};
$p = CollectionDiscovery::start();
check($p['source'] === 'wikidata' && strpos($p['hint'], 'TMDB API key in Settings') !== false, 'no key: Wikidata with a clear hint to add a TMDB key');
$p = CollectionDiscovery::step(30);
$cols = Store::$docs['collections'];
check($p['status'] === 'done' && count($cols) === 1 && $cols[0]['source_id'] === 'wikidata:Q2' && $p['found'] === 2, 'Wikidata series becomes one collection');
check(strpos($sparql[0], 'wdt:P345 "tt0078748"') !== false && strpos(implode(' ', $sparql), 'FILTER(YEAR(?d) >= 1985') !== false, 'IMDb id used when known, else title + year');
check(strpos(CollectionDiscovery::wikidataFilm(['title' => 'Bad "quote\\', 'year' => 2000]) === null ? end($sparql) : '', '"Bad \"quote\\\\"') !== false, 'SPARQL literals escaped');

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
