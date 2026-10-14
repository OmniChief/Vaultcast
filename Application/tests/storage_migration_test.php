<?php
/** Storage audit migrations: Fix Match pins and IPTV status move into the DB, idempotently. php Application/tests/storage_migration_test.php */
namespace Vaultcast { class Repository { public static $docs = array(); public static $writes = 0;
    public static function document($n, $d = null) { return self::$docs[$n] ?? $d; }
    public static function putDocument($n, $v) { self::$docs[$n] = $v; self::$writes++; } } }
namespace {
$tmp = sys_get_temp_dir() . '/rk-migrate-test-' . getmypid();
define('STREAM_ROOT', dirname(__DIR__));
define('STREAM_DATA', $tmp);
@mkdir($tmp . '/live', 0775, true);
@mkdir($tmp . '/synopsis', 0775, true);
require __DIR__ . '/../src/Live/iptv.php';
require __DIR__ . '/../src/Services/Posters.php';
require __DIR__ . '/../src/Services/MetadataPins.php';
use Vaultcast\Repository as R;
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
MetadataPins::$memory = [];

// --- Fix Match pins ---
$m = 'abcdef0123456789'; $s = '0123456789abcdef';
file_put_contents($tmp . "/synopsis/movie_$m.json", json_encode(['pinned' => true, 'summary' => 'Chosen film.']));
file_put_contents($tmp . "/synopsis/movie_$m.poster.json", json_encode(['pinned' => true, 'provider' => 'wikipedia', 'page_title' => 'Runner (2026 film)']));
file_put_contents($tmp . "/synopsis/show_$s.meta.json", json_encode(['pinned' => true, 'summary' => 'A show.']));
file_put_contents($tmp . "/synopsis/movie_fedcba9876543210.json", json_encode(['summary' => 'Unpinned cache.']));
check(MetadataPins::importLegacy() === 3, 'three pinned files imported, plain cache left alone');
check(MetadataPins::importLegacy() === 0, 're-running imports nothing (idempotent)');
check(MetadataPins::get('movie_synopsis', $m)['summary'] === 'Chosen film.', 'synopsis pin read from DB');
check(Posters::moviePosterPin($m)['page_title'] === 'Runner (2026 film)', 'poster pin read from DB');
check(Posters::showSynopsis($s, 'x') === 'A show.', 'show meta pin read from DB');
check(Posters::movieSynopsis($m, 'Runner', 2026, true) === 'Chosen film.', 'pinned synopsis survives refresh');
check(MetadataPins::get('movie_synopsis', 'fedcba9876543210') === null, 'unpinned cache file is not a pin');
// DB wins over a stale file
MetadataPins::put('show_meta', $s, ['summary' => 'Newer.']);
check(MetadataPins::importLegacy() === 0 && Posters::showMetaPin($s)['summary'] === 'Newer.', 'existing DB pin is never overwritten by a file');
// clearing sticks even if a legacy file reappears
MetadataPins::clear('show_meta', $s);
file_put_contents($tmp . "/synopsis/show_$s.meta.json", json_encode(['pinned' => true, 'summary' => 'Stale.']));
check(Posters::showMetaPin($s) === null, 'cleared pin is not resurrected from a stale file');
// lazy import on first read
$m2 = '1111222233334444';
file_put_contents($tmp . "/synopsis/movie_$m2.poster.json", json_encode(['pinned' => true, 'image' => 'https://x/y.jpg']));
check(Posters::moviePosterPin($m2)['image'] === 'https://x/y.jpg' && isset(MetadataPins::$memory["movie_poster:$m2"]), 'first read imports a legacy pin');

// --- IPTV status ---
$state = ['version' => 1, 'entries' => [iptv_status_key('http://a') => ['ok' => true, 'checked' => 1]]];
file_put_contents(iptv_status_file(), json_encode($state));
check(iptv_status_load() === $state, 'legacy iptv_status.json imported on first load');
check(R::$docs['live_iptv_status'] === $state, 'stored in document live_iptv_status');
check(!is_file(iptv_status_file()) && is_file(iptv_status_file() . '.migrated'), 'legacy file retired (renamed, not deleted)');
$w = R::$writes;
iptv_status_load(); iptv_status_load();
check(R::$writes === $w, 'later loads read the document only');
check(iptv_status_import_legacy() === null, 're-running the import is a no-op');
$state['entries']['x'] = ['ok' => false];
iptv_status_save($state);
check(iptv_status_load()['entries']['x'] === ['ok' => false] && !is_file(iptv_status_file()), 'saves go to the DB, no file written');
R::$docs = [];
check(iptv_status_load() === ['version' => 1, 'entries' => []], 'fresh install starts empty');

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
}
