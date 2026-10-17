<?php
/** Find & rename → query the library: wildcard/fuzzy search, label/match/file fixes, idempotency, progress, root safety. */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/vc-query-' . getmypid();
define('STREAM_DATA', $tmp . '/data');
@mkdir(STREAM_DATA, 0777, true);
class MetadataSidecars { public static $calls=0; public static function schedule() { self::$calls++; return ['ok'=>true]; } }
class Store {
    public static $docs = [];
    public static function get(string $n, $d = null) { return self::$docs[$n] ?? $d; }
    public static function set(string $n, $v): bool { self::$docs[$n] = $v; return true; }
    public static function settings(): array { return []; }
    public static function invalidate($n = null): void {}
}
class Catalogue {
    public static $cat = ['items' => []]; public static $sectionRoot = '';
    public static function get(): array { return self::$cat; }
    public static function save(array $c): bool { self::$cat = $c; return true; }
    public static function fixText($s): string { return trim((string)$s); }
    public static function showKey(string $sid, string $name): string { return substr(sha1($sid . '|' . strtolower($name)), 0, 16); }
    public static function formatEpisodeDisplayTitle($show, $s, $e, $t): string { return trim($show . ' ' . ($s !== null && $e !== null ? sprintf('S%02dE%02d', $s, $e) : '') . ' ' . $t); }
    public static function resolveEpisodeTitle(array $it): string { return (string)($it['episode_title'] ?? ''); }
    public static function ingestFile(string $full, string $sid): ?array {
        $real = realpath($full); if ($real === false) return null;
        $rel = substr($real, strlen(realpath(self::$sectionRoot)) + 1);
        $id = substr(sha1($sid . '|' . $rel), 0, 16);
        $it = ['id' => $id, 'section' => $sid, 'type' => 'movies', 'path' => $real, 'rel' => $rel, 'title' => pathinfo($rel, PATHINFO_FILENAME), 'year' => null];
        self::$cat['items'][$id] = $it; return $it;
    }
}
require __DIR__ . '/../src/Services/Matching.php';
require __DIR__ . '/../src/Services/LibraryDelete.php';
require __DIR__ . '/../src/Services/LibraryQuery.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
function put($p, $s = 'x') { @mkdir(dirname($p), 0777, true); file_put_contents($p, $s); }

// ---- scoring
check(LibraryQuery::score('matrix', 'The Matrix') === 90, 'substring match');
check(LibraryQuery::score('the matrix', 'The.Matrix') === 100, 'dots/case ignored');
check(LibraryQuery::score('dragonball z kai', 'Dragon Ball Z Kai') === 98, 'spacing-insensitive (Dragonball vs Dragon Ball)');
check(LibraryQuery::score('drag*kai', 'Dragon Ball Z Kai') === 95 && LibraryQuery::score('drag*', 'Dragon Ball Z Kai') === 95 && LibraryQuery::score('ball*k', 'Dragon Ball Z Kai') === 85, 'wildcards * (full and partial)');
check(LibraryQuery::score('m?trix', 'The Matrix') === 85, 'wildcard ?');
check(LibraryQuery::score('the matrx', 'The Matrix') >= 75, 'fuzzy: one typo still matches');
check(LibraryQuery::score('mtrix reloaded', 'The Matrix Reloaded') >= 75, 'fuzzy multi-word window');
check(LibraryQuery::score('godfather', 'The Matrix') === 0, 'unrelated term does not match');
check(LibraryQuery::terms("Alien\n\n  Matrix ;Alien") === ['Alien', 'Matrix'], 'one term per line, deduped');

// ---- library on disk
$lib = $tmp . '/Movies';
put($lib . '/The Matrx (1999)/The Matrx (1999).mkv');
put($lib . '/The Matrx (1999)/The Matrx (1999).en.srt');
put($lib . '/The Matrx (1999)/The Matrx (1999)-poster.jpg');
put($lib . '/Alien (1979)/Alien (1979).mp4');
put($lib . '/Alien (1979)/Taken.mp4');
put($tmp . '/outside/secret.mkv');
LibraryDelete::$roots = [$lib];
Catalogue::$sectionRoot = $lib;
$m1 = Catalogue::ingestFile($lib . '/The Matrx (1999)/The Matrx (1999).mkv', 'movies'); $m1id = $m1['id'];
Catalogue::$cat['items'][$m1id]['title'] = 'The Matrx'; Catalogue::$cat['items'][$m1id]['year'] = 1999;
$a = Catalogue::ingestFile($lib . '/Alien (1979)/Alien (1979).mp4', 'movies'); $aid = $a['id'];
Catalogue::$cat['items'][$aid]['title'] = 'Alien';
Catalogue::$cat['items']['ep1'] = ['id' => 'ep1', 'type' => 'anime', 'section' => 'anime', 'show' => 'Dragonball Z Kai', 'season' => 1, 'episode' => 1, 'episode_title' => 'Prologue', 'rel' => 'Dragonball Z Kai/Dragonball Z Kai 01 Prologue.mkv', 'path' => '/nonexistent/x.mkv', 'title' => 'x'];
Catalogue::$cat['items']['ep2'] = ['id' => 'ep2', 'type' => 'anime', 'section' => 'anime', 'show' => 'Dragonball Z Kai', 'season' => 1, 'episode' => 2, 'episode_title' => 'Brother', 'rel' => 'Dragonball Z Kai/Dragonball Z Kai 02.mkv', 'path' => '/nonexistent/y.mkv', 'title' => 'y'];
Catalogue::$cat['items']['evil'] = ['id' => 'evil', 'type' => 'movies', 'section' => 'movies', 'title' => 'Evil', 'rel' => '../outside/secret.mkv', 'path' => $lib . '/../outside/secret.mkv'];

$r = LibraryQuery::search(['q' => "the matrix\ndragon ball*"]);
$ids = array_column($r['items'], 'id');
check($r['ok'] && in_array($m1id, $ids, true) && in_array('ep1', $ids, true) && in_array('ep2', $ids, true) && !in_array($aid, $ids, true), 'multi-term search finds fuzzy title + wildcard show, not others');
check($r['counts']['the matrix'] === 1 && $r['counts']['dragon ball*'] === 2, 'per-term counts');
$r = LibraryQuery::search(['q' => 'prologue', 'fields' => ['episode_title']]);
check(array_column($r['items'], 'id') === ['ep1'], 'search episodes only');
$r = LibraryQuery::search(['q' => 'prologue', 'fields' => ['title']]);
check($r['items'] === [], 'field filter respected');
check(!LibraryQuery::search(['q' => "  \n "])['ok'], 'empty query rejected');

// ---- label fix: title + year (preview, apply, idempotent, id unchanged)
$fix = ['ids' => [$m1id], 'fix' => ['kind' => 'title', 'value' => 'The Matrix', 'year' => 1999]];
$p = LibraryQuery::preview($fix);
check($p['ok'] && $p['changes'] === 1 && $p['rows'][0]['before'] === 'The Matrx' && $p['rows'][0]['after'] === 'The Matrix', 'preview shows before/after');
check(Catalogue::$cat['items'][$m1id]['title'] === 'The Matrx', 'preview changes nothing');
$res = LibraryQuery::apply($fix);
check($res['changed'] === 1 && Catalogue::$cat['items'][$m1id]['title'] === 'The Matrix' && isset(Catalogue::$cat['items'][$m1id]), 'apply relabels in the DB, same item id');
check(LibraryQuery::preview($fix)['changes'] === 0 && LibraryQuery::apply($fix)['changed'] === 0, 're-applying is a no-op');

// ---- show rename for episodes; movies refuse
$res = LibraryQuery::apply(['ids' => ['ep1', 'ep2', $aid], 'fix' => ['kind' => 'show', 'value' => 'Dragon Ball Z Kai']]);
check($res['changed'] === 2 && $res['failed'] === 1 && Catalogue::$cat['items']['ep2']['show'] === 'Dragon Ball Z Kai', 'show renamed on episodes only');
// template tokens
$p = LibraryQuery::preview(['ids' => ['ep1'], 'fix' => ['kind' => 'title', 'value' => 'Episode {episode} of {show}']]);
check($p['rows'][0]['after'] === 'Episode 01 of Dragon Ball Z Kai', 'template tokens');

// ---- match: movie rematch, show pin (once per show)
$res = LibraryQuery::apply(['ids' => [$aid], 'fix' => ['kind' => 'match', 'value' => 'Alien', 'year' => 1979, 'imdb_id' => 'tt0078748']]);
check($res['changed'] === 1 && Catalogue::$cat['items'][$aid]['year'] === 1979, 'movie match target applied');
$p = LibraryQuery::preview(['ids' => ['ep1', 'ep2'], 'fix' => ['kind' => 'match', 'tvmaze_id' => 0]]);
check(!$p['rows'][0]['ok'], 'episode match needs a TVMaze id');

// ---- file rename on disk
Store::$docs['progress'] = [$m1id => 1234];
LibraryQuery::$migrateProgress = function (array $old, string $new) { $n = 0; foreach ($old as $o) if (isset(Store::$docs['progress'][$o])) { Store::$docs['progress'][$new] = Store::$docs['progress'][$o]; unset(Store::$docs['progress'][$o]); $n++; } return $n; };
$fix = ['ids' => [$m1id], 'fix' => ['kind' => 'filename', 'value' => '{title} ({year})']];
$p = LibraryQuery::preview($fix);
check($p['rows'][0]['after'] === 'The Matrix (1999).mkv' && count($p['rows'][0]['sidecars']) === 2, 'file rename preview incl. sidecars, extension kept');
$res = LibraryQuery::apply($fix);
$newId = $res['results'][0]['new_id'] ?? '';
check($res['changed'] === 1 && is_file($lib . '/The Matrx (1999)/The Matrix (1999).mkv') && !is_file($lib . '/The Matrx (1999)/The Matrx (1999).mkv'), 'file renamed on disk');
check(is_file($lib . '/The Matrx (1999)/The Matrix (1999).en.srt') && is_file($lib . '/The Matrx (1999)/The Matrix (1999)-poster.jpg'), 'sidecars renamed with it');
check($newId !== '' && !isset(Catalogue::$cat['items'][$m1id]) && isset(Catalogue::$cat['items'][$newId]), 'catalogue re-indexed under the new id');
check(Store::$docs['progress'] === [$newId => 1234], 'watch progress moved to the new id');
check((Catalogue::$cat['items'][$newId]['title'] ?? '') === 'The Matrix' && isset(Store::$docs['match_overrides']['items'][$newId]), 'saved title fix carried over');
check(LibraryQuery::apply(['ids' => [$newId], 'fix' => ['kind' => 'filename', 'value' => '{title} ({year})']])['changed'] === 0, 're-running the rename is a no-op');

// ---- rename safety
$p = LibraryQuery::preview(['ids' => [$aid], 'fix' => ['kind' => 'filename', 'value' => '../../outside/pwn']]);
check(!$p['rows'][0]['ok'] && $p['changes'] === 0, 'traversal / separators in the new name rejected');
$p = LibraryQuery::preview(['ids' => [$aid], 'fix' => ['kind' => 'filename', 'value' => 'Taken']]);
check(!$p['rows'][0]['ok'], 'existing target file is never overwritten');
$p = LibraryQuery::preview(['ids' => ['evil'], 'fix' => ['kind' => 'filename', 'value' => 'x']]);
check(!$p['rows'][0]['ok'] && is_file($tmp . '/outside/secret.mkv'), 'items pointing outside the library roots refused');
@symlink($tmp . '/outside/secret.mkv', $lib . '/link.mkv');
Catalogue::$cat['items']['lnk'] = ['id' => 'lnk', 'type' => 'movies', 'section' => 'movies', 'title' => 'L', 'rel' => 'link.mkv', 'path' => $lib . '/link.mkv'];
check(!LibraryQuery::preview(['ids' => ['lnk'], 'fix' => ['kind' => 'filename', 'value' => 'y']])['rows'][0]['ok'], 'symlinked file refused');
check(LibraryQuery::validStem('CON') !== null && LibraryQuery::validStem('a:b') !== null && LibraryQuery::validStem('ok name') === null, 'Windows-reserved / illegal names rejected');
$p = LibraryQuery::preview(['ids' => [$aid, $newId], 'fix' => ['kind' => 'filename', 'value' => 'Same']]);
check($p['rows'][0]['ok'] && $p['rows'][1]['ok'], 'same name in different folders is fine');
check(!LibraryQuery::preview(['ids' => [$aid], 'fix' => ['kind' => 'nope', 'value' => 'x']])['ok'], 'unknown fix kind rejected');

check(MetadataSidecars::$calls > 0, 'metadata changes queue background sidecar preparation');
// wiring
$routes = file_get_contents(__DIR__ . '/../src/Http/routes/admin-library.php');
check(strpos($routes, "'/admin/library/query/apply'") !== false && strpos($routes, 'application/json') !== false, 'routes: search, preview, apply (JSON only)');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, 'function libraryQueryPanel(') !== false && strpos($js, "'Query the library'") !== false, 'Find & rename UI panel');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
