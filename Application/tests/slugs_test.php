<?php
/** Slugs + clean-URL resolver: php Application/tests/slugs_test.php */
require __DIR__ . '/../src/Services/Slugs.php';
require __DIR__ . '/../src/Services/UrlRoutes.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$doc = [];
Slugs::$load = function () use (&$doc) { return $doc; };
Slugs::$save = function ($d) use (&$doc) { $doc = $d; };

check(Slugs::base('Runner', 2026) === 'runner-2026', 'title + year');
check(Slugs::base('Amélie', 2001) === 'amelie-2001' || Slugs::base('Amélie', 2001) === 'am-lie-2001', 'accents are transliterated (or dropped without iconv)');
check(Slugs::base("Ocean's Eleven", 2001) === 'oceans-eleven-2001', 'apostrophes dropped');
check(Slugs::base('Fast & Furious') === 'fast-and-furious', '& becomes and');
check(Slugs::base('2001: A Space Odyssey', 1968) === '2001-a-space-odyssey-1968', 'punctuation collapsed');
check(Slugs::base('', null) === 'untitled' && Slugs::base('???') === 'untitled', 'empty titles');
check(Slugs::base('Blade Runner 2049', 2017) === 'blade-runner-2049-2017' && Slugs::base('Movie 2020', 2020) === 'movie-2020', 'year not doubled');

$a = Slugs::movie(['id' => 'aaaa000000000001', 'title' => 'Runner', 'year' => 2026]);
$b = Slugs::movie(['id' => 'bbbb000000000002', 'title' => 'Runner', 'year' => 2026]);
check($a === 'runner-2026' && $b === 'runner-2026-2', 'collision gets -2');
check(Slugs::movie(['id' => 'aaaa000000000001', 'title' => 'Runner', 'year' => 2026]) === 'runner-2026', 'same item keeps its slug');
Slugs::flush(); Slugs::reset();
check(Slugs::movie(['id' => 'cccc000000000003', 'title' => 'Runner', 'year' => 2026]) === 'runner-2026-3', 'third duplicate after reload: -3, earlier links unchanged');
check(Slugs::movie(['id' => 'bbbb000000000002', 'title' => 'Runner (renamed)', 'year' => 2026]) === 'runner-2026-2', 'stable even if the title changes later');
check(Slugs::resolve('movie', 'runner-2026') === 'aaaa000000000001' && Slugs::resolve('movie', 'RUNNER-2026-2') === 'bbbb000000000002', 'resolve (case-insensitive)');
check(Slugs::resolve('movie', 'nope') === null, 'unknown slug');
check(Slugs::show('showkey0000000001', 'The Office') === 'the-office', 'show slug without year');
check(Slugs::show('showkey0000000002', 'The Office', 2005) === 'the-office-2005', 'show collision prefers the year');
check(Slugs::show('showkey0000000003', 'The Office', 2005) === 'the-office-2', 'then numbers');
Slugs::flush();

// concurrent request took a slug meanwhile: ours is re-assigned on flush, theirs kept
Slugs::reset();
$mine = Slugs::movie(['id' => 'dddd000000000004', 'title' => 'Heat', 'year' => 1995]);
$doc['movie']['heat-1995'] = 'eeee000000000005';
Slugs::flush(); Slugs::reset();
check($mine === 'heat-1995' && Slugs::resolve('movie', 'heat-1995') === 'eeee000000000005' && Slugs::resolve('movie', 'heat-1995-2') === 'dddd000000000004', 'flush merges with a concurrent writer');
$map = []; check(Slugs::assignIn($map, 'x', 'abcdef0123456789') === 'abcdef0123456789-2', 'slug that looks like a raw id is avoided');

// resolver
$lookup = function ($k, $s) { return Slugs::resolve($k, $s) ?? (($k === 'movie' && $s === 'abcdef0123456789') ? $s : null); };
$r = UrlRoutes::resolve('/movies/runner-2026', $lookup);
check($r['ok'] && $r['view'] === 'movie' && $r['movie_id'] === 'aaaa000000000001', 'resolve /movies/<slug>');
check(UrlRoutes::resolve('/movies/abcdef0123456789', $lookup)['movie_id'] === 'abcdef0123456789', 'raw id still works');
check(!UrlRoutes::resolve('/movies/missing', $lookup)['ok'], 'missing movie');
$r = UrlRoutes::resolve('/tvshows/the-office/season-2/episode-5-dinner-party', $lookup);
check($r['ok'] && $r['show_key'] === 'showkey0000000001' && $r['season'] === 2 && $r['episode'] === 5, 'resolve episode (title suffix allowed)');
$r = UrlRoutes::resolve('/tvshows/the-office/season-0/abcdef0123456789', $lookup);
check($r['season'] === 0 && $r['item_id'] === 'abcdef0123456789' && $r['episode'] === null, 'unnumbered special by item id');
check(UrlRoutes::resolve('/tvshows/the-office', $lookup)['season'] === null, 'show only');

// games: /games/<system>/<game> and /games/<system>
Slugs::reset();
check(Slugs::systemSlug('Genesis') === 'genesis' && Slugs::systemSlug('Sega Mega Drive') === 'sega-mega-drive', 'console slug from the folder name');
check(Slugs::systemSlug('ROMs', 'segaMD') === 'genesis' && Slugs::systemSlug('', 'snes') === 'snes' && Slugs::systemSlug(null, null) === 'games', 'console slug from the core when the folder is generic');
$g = Slugs::game(['id' => 'ffff000000000006', 'title' => 'Sonic The Hedgehog 2', 'system' => 'Genesis', 'emu_core' => 'segaMD']);
check($g === 'genesis/sonic-the-hedgehog-2', 'game path');
$g2 = Slugs::game(['id' => 'ffff000000000007', 'title' => 'Sonic the Hedgehog 2', 'system' => 'Genesis']);
check($g2 === 'genesis/sonic-the-hedgehog-2-2', 'duplicate game gets -2');
check(Slugs::game(['id' => 'ffff000000000006', 'title' => 'Renamed', 'system' => 'Genesis']) === $g, 'game path is stable');
check(Slugs::game(['id' => 'ffff000000000008', 'title' => 'Sonic The Hedgehog 2', 'system' => 'Game Gear']) === 'game-gear/sonic-the-hedgehog-2', 'same title on another console is separate');
$glookup = function ($k, $s) { if ($k === 'system') return $s === 'genesis' ? 'Genesis' : null; return Slugs::resolve($k, $s); };
$r = UrlRoutes::resolve('/games/genesis/sonic-the-hedgehog-2', $glookup);
check($r['ok'] && $r['view'] === 'game' && $r['item_id'] === 'ffff000000000006', 'resolve /games/<system>/<game>');
check(UrlRoutes::resolve('/games/Genesis/SONIC-THE-HEDGEHOG-2', $glookup)['item_id'] === 'ffff000000000006', 'game routes are case-insensitive');
$r = UrlRoutes::resolve('/games/genesis', $glookup);
check($r['ok'] && $r['view'] === 'games_system' && $r['system'] === 'Genesis' && $r['system_slug'] === 'genesis', 'resolve /games/<system>');
check(!UrlRoutes::resolve('/games/genesis/missing', $glookup)['ok'] && !UrlRoutes::resolve('/games/nope', $glookup)['ok'], 'missing game or console');
check(UrlRoutes::resolve('/games/lobby', $glookup)['view'] === 'unknown', 'lobby is not a console');
Slugs::flush();
check(isset($doc['game']['genesis/sonic-the-hedgehog-2']), 'game slugs are persisted');

// dev router + server fallback config
$router = file_get_contents(__DIR__ . '/../router.php');
check(strpos($router, "require __DIR__ . '/index.php'") !== false && strpos($router, 'return false') !== false, 'php -S router falls back to index.php and serves static files');
$nginx = file_get_contents(__DIR__ . '/../deploy/nginx.conf.example');
check(strpos($nginx, 'try_files $uri $uri/ /index.php;') !== false, 'nginx example has the deep-link fallback');
$ht = file_get_contents(__DIR__ . '/../.htaccess');
check(strpos($ht, 'RewriteRule ^ index.php [L]') !== false && strpos($ht, '(src|data|deploy|docs|netplay|tests)') !== false, 'Apache .htaccess fallback keeps private folders denied');
$html = file_get_contents(__DIR__ . '/../src/Views/app.html');
check(strpos($html, '<base href="{{BASE_HREF}}">') !== false, 'app shell sets <base> so assets load on deep links');

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
