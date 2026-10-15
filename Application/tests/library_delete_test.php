<?php
/** Admin file delete: traversal/symlink/root protection, sidecars, empty-dir walk-up, catalogue cleanup. */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/vc-del-' . getmypid();
define('STREAM_DATA', $tmp . '/data');
class Store { public static $inv = 0; public static function settings() { return []; } public static function invalidate($n = null) { self::$inv++; } }
class Catalogue { public static $cat = []; public static $saves = 0; public static function get(): array { return self::$cat; } public static function save(array $c): bool { self::$cat = $c; self::$saves++; return true; } }
require __DIR__ . '/../src/Paths.php';
require __DIR__ . '/../src/Services/Subtitles.php';
require __DIR__ . '/../src/Services/LibraryDelete.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
function put($p, $s = 'x') { @mkdir(dirname($p), 0777, true); file_put_contents($p, $s); }

$lib = $tmp . '/Movies';
$out = $tmp . '/outside';
put($lib . '/Alien (1979)/Alien (1979).mkv');
put($lib . '/Alien (1979)/Alien (1979).en.srt');
put($lib . '/Alien (1979)/Alien (1979).fr.uploaded.vtt');
put($lib . '/Alien (1979)/Alien (1979).nfo');
put($lib . '/Alien (1979)/Alien (1979)-fanart.jpg');
put($lib . '/Alien (1979)/poster.jpg');
put($lib . '/Alien (1979)/Thumbs.db');
put($lib . '/Shows/Show/Season 1/Show S01E01.mkv');
put($lib . '/Shows/Show/Season 1/Show S01E02.mkv');
put($lib . '/Shows/Show/tvshow.nfo');
put($lib . '/Shows/Show/Season 1/notes.txt');
put($lib . '/Keep/Deep/Nested/Film.mp4');
put($lib . '/rootfile.mkv');
put($out . '/secret.txt');
@symlink($out . '/secret.txt', $lib . '/link.txt');
@symlink($out, $lib . '/linkdir');
LibraryDelete::$roots = [$lib];
$real = realpath($lib);

// --- validation ---
check(!LibraryDelete::resolve($lib . '/Alien (1979)/../../outside/secret.txt')['ok'], 'traversal with .. rejected');
check(!LibraryDelete::resolve($lib . '/../outside/secret.txt')['ok'], 'escape above the root rejected');
check(!LibraryDelete::resolve($out . '/secret.txt')['ok'], 'files outside every library root rejected');
check(!LibraryDelete::resolve($lib . '/link.txt')['ok'] && is_file($out . '/secret.txt'), 'symlinked file rejected');
check(!LibraryDelete::resolve($lib . '/linkdir/secret.txt')['ok'], 'path through a symlinked folder rejected');
check(!LibraryDelete::resolve($lib)['ok'] && !LibraryDelete::resolve($lib . '/')['ok'] && strpos(LibraryDelete::resolve($lib)['error'], 'library folder itself') !== false, 'library root itself can never be deleted');
check(!LibraryDelete::resolve($lib . '/Alien (1979)')['ok'], 'folders are not deleted by this action');
check(!LibraryDelete::resolve("$lib/rootfile.mkv\0.txt")['ok'] && !LibraryDelete::resolve('')['ok'], 'NUL byte / empty path rejected');
check(!LibraryDelete::resolve($lib . 'X/evil.mkv')['ok'], 'sibling folder with the root as name prefix rejected');
put($lib . 'X/evil.mkv');
check(!LibraryDelete::resolve($lib . 'X/evil.mkv')['ok'], 'prefix-named sibling folder is not inside the root');

// --- delete a movie: sidecars, folder art, empty folder walk-up, catalogue ---
$movie = realpath($lib . '/Alien (1979)/Alien (1979).mkv');
$store = Subtitles::storeDir($movie);
put($store . '/en.opensubtitles.vtt', "WEBVTT\n");
Catalogue::$cat = ['items' => [
    ['id' => 'm1', 'path' => $movie, 'title' => 'Alien'],
    ['id' => 'e1', 'path' => realpath($lib . '/Shows/Show/Season 1/Show S01E01.mkv'), 'title' => 'Pilot', 'show' => 'Show'],
    ['id' => 'e2', 'path' => realpath($lib . '/Shows/Show/Season 1/Show S01E02.mkv'), 'title' => 'Two', 'show' => 'Show'],
], 'item_count' => 3];
$r = LibraryDelete::delete($lib . '/Alien (1979)/Alien (1979).mkv');
check($r['ok'] && !file_exists($lib . '/Alien (1979)'), 'movie deleted, folder (sidecars + poster + Thumbs.db) removed');
check(count($r['removed_files']) >= 7 && !is_dir($store), 'subtitles, nfo, artwork and the app subtitle store removed');
check(is_dir($lib) && $r['removed_dirs'] === [$real . '/Alien (1979)'], 'walk-up stops at the root (root kept)');
check($r['removed_items'] === ['m1'] && array_column(Catalogue::$cat['items'], 'id') === ['e1', 'e2'] && Catalogue::$cat['item_count'] === 2, 'catalogue item removed, list stays a list');
check(Catalogue::$saves === 1 && Store::$inv > 0, 'catalogue saved (derived caches cleared)');

// --- episode: sibling keeps the folder ---
$r = LibraryDelete::delete($lib . '/Shows/Show/Season 1/Show S01E01.mkv');
check($r['ok'] && $r['removed_dirs'] === [] && is_file($lib . '/Shows/Show/Season 1/Show S01E02.mkv') && is_file($lib . '/Shows/Show/Season 1/notes.txt'), 'other episode and unknown files untouched');
check(array_column(Catalogue::$cat['items'], 'id') === ['e2'], 'episode entry removed');
unlink($lib . '/Shows/Show/Season 1/notes.txt');
$r = LibraryDelete::delete($lib . '/Shows/Show/Season 1/Show S01E02.mkv');
check($r['ok'] && !is_dir($lib . '/Shows') && is_dir($lib), 'last episode: Season, Show (tvshow.nfo) and Shows folders removed up to the root');

// --- deep nesting walk-up ---
$r = LibraryDelete::delete($lib . '/Keep/Deep/Nested/Film.mp4');
check($r['ok'] && count($r['removed_dirs']) === 3 && !is_dir($lib . '/Keep') && is_dir($lib), 'empty parents removed, walking up to (not including) the root');

// --- file directly in the root ---
$r = LibraryDelete::delete($lib . '/rootfile.mkv');
check($r['ok'] && $r['removed_dirs'] === [] && is_dir($lib), 'file in the root: root never removed even if empty');
check(is_file($out . '/secret.txt'), 'nothing outside the library touched');

// --- route requires JSON + matching confirm name ---
$route = file_get_contents(__DIR__ . '/../src/Http/routes/admin-media.php');
check(strpos($route, "'/admin/media/files/delete'") !== false && strpos($route, 'application/json') !== false && strpos($route, "'confirm'") !== false, 'route: JSON-only and confirm name required (behind the /admin/ requireAdmin gate)');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, "Are you sure you want to permanently delete “' + ent.name") !== false, 'UI confirmation names the file');

exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($lib . 'X'));
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
