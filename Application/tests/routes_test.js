/* Clean URL helpers: node Application/tests/routes_test.js */
var R = require('../assets/js/routes.js');
var fails = 0;
function check(c, m){ console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) fails++; }
function eq(a, b){ return JSON.stringify(a) === JSON.stringify(b); }

check(R.build({ view:'movie', movieSlug:'runner-2026', movieId:'abc' }) === 'movies/runner-2026', 'movie path uses the slug');
check(R.build({ view:'movie', movieId:'abcdef0123456789' }) === 'movies/abcdef0123456789', 'movie path falls back to the id before the slug is known');
check(R.build({ view:'show', showSlug:'the-office' }) === 'tvshows/the-office', 'show path');
check(R.build({ view:'show', showSlug:'the-office', season:2 }) === 'tvshows/the-office/season-2', 'season path');
check(R.build({ view:'show', showSlug:'the-office', season:2, episode:5 }) === 'tvshows/the-office/season-2/episode-5', 'episode path');
check(R.build({ view:'show', showSlug:'x', season:0, episode:3 }) === 'tvshows/x/season-0/episode-3', 'specials (season 0)');
check(R.build({ view:'live' }) === 'live' && R.build({ view:'live', liveCh:'bbc one' }) === 'live/bbc%20one', 'live + channel');
check(R.build({ view:'play' }) === 'games' && R.build({ view:'play', playTab:'lobby' }) === 'games/lobby', 'games');
check(R.build({ view:'section', section:'movies' }) === 'library/movies', 'library section');
check(R.build({ view:'collections' }) === 'collections' && R.build({ view:'collection', collectionId:'c1' }) === 'collections/c1', 'collections');
check(R.build({ view:'settings', settingsSection:'users' }) === 'settings/users' && R.build({ view:'settings', settingsSection:'account' }) === 'settings', 'settings');
check(R.build({ view:'search', searchQuery:'star wars' }) === 'search?q=star%20wars', 'search');
check(R.build({ view:'home' }) === '', 'home is the base');

check(eq(R.parse('movies/runner-2026'), { view:'movie', slug:'runner-2026', resolve:true }), 'parse movie');
check(R.parse('tvshows/the-office/season-2/episode-5').slug === 'the-office', 'parse show (server resolves season/episode)');
check(eq(R.parse('live/42'), { view:'live', liveCh:'42' }), 'parse live channel');
check(eq(R.parse('games/lobby'), { view:'play', playTab:'lobby' }), 'parse lobby');
check(eq(R.parse('search?q=star+wars'), { view:'search', searchQuery:'star wars' }), 'parse search query');
check(eq(R.parse('settings/users'), { view:'settings', settingsSection:'users' }), 'parse settings');
check(R.parse('nope/x').view === 'notfound' && R.parse('').view === 'home', 'unknown and home');

check(R.legacyHashToPath('#/vod') === '', 'old #/vod -> home');
check(R.legacyHashToPath('#/vod/movies') === 'library/movies', 'old #/vod/<section>');
check(R.legacyHashToPath('#/vod/collections') === 'collections', 'old collections');
check(R.legacyHashToPath('#/live/7') === 'live/7', 'old live channel');
check(R.legacyHashToPath('#/play/lobby') === 'games/lobby', 'old lobby');
check(R.legacyHashToPath('#/admin/users') === 'settings/users' && R.legacyHashToPath('#/settings') === 'settings', 'old admin/settings');
check(R.legacyHashToPath('#section-2') === null && R.legacyHashToPath('') === null, 'non-route hashes are left alone');

check(R.relative('/movies/x', '/') === 'movies/x', 'relative at root');
check(R.relative('/vaultcast/tvshows/a', '/vaultcast/') === 'tvshows/a' && R.relative('/vaultcast', '/vaultcast/') === '', 'relative in a subdirectory');
check(R.relative('/index.php', '/') === '', 'index.php is home');

check(R.absolute('movies/runner-2026', { origin:'http://192.0.2.5:8080', base:'/' }) === 'http://192.0.2.5:8080/movies/runner-2026', 'share link keeps protocol, host and port');
check(R.absolute('movies/a', { origin:'https://h', base:'/rk/' }) === 'https://h/rk/movies/a', 'share link keeps the install base');
check(R.absolute('movies/a', { publicBase:'https://media.example.com/', origin:'http://198.51.100.2', base:'/' }) === 'https://media.example.com/movies/a', 'public base URL wins');
check(R.absolute('movies/a', { publicBase:'not a url', origin:'http://h', base:'/' }) === 'http://h/movies/a', 'invalid public base is ignored');

console.log(fails ? fails + ' failed' : 'all passed');
process.exit(fails ? 1 : 0);
