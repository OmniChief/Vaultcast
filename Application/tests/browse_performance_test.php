<?php
/** Recent cards must project only the selected rows and reuse revision-managed views. */
namespace Vaultcast {
    class Database { public static function installed() { return false; } }
}
namespace {
    set_error_handler(function ($severity, $message, $file, $line) { if (error_reporting() & $severity) throw new \ErrorException($message, 0, $severity, $file, $line); return false; });
    define('STREAM_DATA', sys_get_temp_dir() . '/vc-browse-perf-' . getmypid());
    define('STREAM_SHM', STREAM_DATA . '/cache');
    require __DIR__ . '/../src/Repository.php';
    require __DIR__ . '/../src/Services/Catalogue.php';
    class Store {
        public static $rev = ['catalogue'=>1,'browse_epoch'=>1,'settings'=>1];
        public static $items = [], $reads = 0;
        public static function revision($name) { return self::$rev[$name] ?? 0; }
        public static function get($name, $default = null) { self::$reads++; return ['items'=>self::$items]; }
    }
    class MediaFacts {
        public static $preloaded = [];
        public static function preload($items) { self::$preloaded = $items; }
        public static function get($item) {
            if (!in_array($item['id'], array_column(self::$preloaded, 'id'), true)) throw new \RuntimeException('Unbatched facts lookup');
            return null;
        }
    }
    class Slugs { public static $calls = 0; public static function show($key, $name, $year = null) { self::$calls++; return $key; } }
    function check($yes, $message) { if (!$yes) throw new \RuntimeException($message); echo "ok $message\n"; }
    $shows = [];
    for ($s = 0; $s < 120; $s++) {
        $name = 'Show ' . $s; $key = Catalogue::showKey('tv', $name);
        $shows[] = ['id'=>$key, 'show_key'=>$key, 'title'=>$name, 'section'=>'tv', 'type'=>'tv', 'has_poster'=>true];
        for ($e = 1; $e <= 50; $e++) Store::$items[] = ['id'=>md5($name . $e), 'show'=>$name, 'title'=>'Episode',
            'mtime'=>($s % 2 && $e === 1) ? time()-90*86400 : time()-$s*100-$e,
            'section'=>'tv', 'type'=>'tv', 'year'=>2020, 'season'=>1, 'episode'=>$e, 'episode_title'=>'An episode',
            'media_kind'=>'video', 'ext'=>'mp4', 'size'=>100, 'path'=>'', 'rel'=>'', 'system'=>null];
    }
    Catalogue::browseCachePut('section_shows', 'tv', ['shows'=>$shows]);
    $start = microtime(true); $first = Catalogue::recentlyUpdatedPublic('tv', 16); $cold = microtime(true)-$start;
    check(count($first['items']) === 16 && !$first['cached'], 'cold recent response has sixteen cards');
    check(Slugs::$calls === 16, 'only sixteen selected cards resolve slugs, not all 120 shows');
    check(count(MediaFacts::$preloaded) > 0 && count(MediaFacts::$preloaded) <= 16, 'selected episode facts are loaded as one bounded batch');
    check(count(array_unique(array_column($first['items'], 'show_key'))) === 16, 'show and episode mix contains no duplicate shows');
    $reads = Store::$reads; $start = microtime(true); $second = Catalogue::recentlyUpdatedPublic('tv', 16); $warm = microtime(true)-$start;
    check($second['cached'] && Store::$reads === $reads && Slugs::$calls === 16, 'warm recent response does not read episodes or project again');
    check($second['items'] === $first['items'], 'cache preserves ordering, labels and public projections');
    Catalogue::browseCacheGet('facets_movies', 'movies');
    Catalogue::browseCachePut('facets_movies', 'movies', ['facets'=>['genres'=>['Comedy'=>4]]]);
    check(Catalogue::browseCacheGet('facets_movies', 'movies')['facets']['genres']['Comedy'] === 4, 'facet pack is reusable');
    foreach (glob(STREAM_SHM . '/kv/*/*.json') as $file) {
        $entry = json_decode(file_get_contents($file), true);
        check($entry['e'] === 0, 'shared summaries have no arbitrary hourly expiry');
    }
    Store::$rev['browse_epoch']++;
    check(Catalogue::browseCacheGet('facets_movies', 'movies') === null, 'metadata invalidation refuses old summaries');
    Catalogue::browseCacheGet('section_shows', 'tv');
    Catalogue::browseCachePut('section_shows', 'tv', ['shows'=>$shows]);
    Slugs::$calls = 0;
    check(!Catalogue::recentlyUpdatedPublic('tv', 16)['cached'] && Slugs::$calls === 16, 'recent cache rebuilds after metadata changes');
    Store::$rev['settings']++;
    check(Catalogue::browseCacheGet('section_shows', 'tv') === null, 'settings invalidates shared views');
    Catalogue::browseCacheGet('facets_movies', 'movies');
    Store::$rev['catalogue']++;
    Catalogue::browseCachePut('facets_movies', 'movies', ['stale'=>true]);
    check(Catalogue::browseCacheGet('facets_movies', 'movies') === null, 'a build from an old revision is not stamped as new');
    echo sprintf("fixture: 6000 episodes / 120 shows; cold %.2f ms, warm %.2f ms\n", $cold*1000, $warm*1000);
    \Vaultcast\Repository::cacheClear();
    foreach (glob(STREAM_SHM . '/kv/*', GLOB_ONLYDIR) ?: [] as $dir) @rmdir($dir);
    @rmdir(STREAM_SHM . '/kv'); @rmdir(STREAM_SHM); @rmdir(STREAM_DATA);
    echo "all passed\n";
}
