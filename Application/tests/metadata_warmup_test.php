<?php
namespace Vaultcast {
    class Repository {
        public static $docs = [];
        public static function revision($name) { return 1; }
        public static function cacheGet($name) { return null; }
        public static function cachePut($name,$data,$ttl) {}
        public static function document($name, $default = null) { return self::$docs[$name] ?? $default; }
        public static function putDocument($name, $value) { self::$docs[$name] = $value; }
    }
}
namespace {
    define('STREAM_DATA', sys_get_temp_dir() . '/vc-metadata-' . getmypid());
    mkdir(STREAM_DATA);
    class Catalogue {
        public static $items = [];
        public static function get() { return ['items'=>self::$items]; }
        public static function showKey($section, $show) { return substr(sha1($section . $show), 0, 16); }
        public static function showDirFromItem($it) { return null; }
        public static function seasonEpisodeFromRel($rel) { return ['season'=>3]; }
        public static function invalidateDerivedCaches() {}
        public static function warmBrowseViews() {}
        public static function homeLibrary() {}
        public static function showPosterHint($key) {}
    }
    class Matching { public static $pins=[]; public static function overrides() { return ['shows'=>self::$pins]; } }
    class MetadataSidecars { public static function run($progress=null) { return ['deferred'=>0, 'written'=>0, 'failed'=>0]; } }
    class Maintenance {
        public static $cancel = false;
        public static function checkpoint() { if (self::$cancel) throw new \RuntimeException('Cancelled'); }
    }
    class Posters {
        public static $calls = []; public static $budget = false;
        public static function enabled() { return true; }
        public static function beginRemoteBudget($s) { self::$budget = true; }
        public static function endRemoteBudget() { self::$budget = false; }
        public static function dir() { return STREAM_DATA; }
        public static function showCachePath($id) { return STREAM_DATA . '/show_' . $id . '.jpg'; }
        public static function seasonCachePath($id,$n) { return STREAM_DATA . '/season_' . $id . '_s' . $n . '.jpg'; }
        public static function movieCachePath($id) { return STREAM_DATA . '/movie_' . $id . '.jpg'; }
        public static function gameCachePath($id) { return STREAM_DATA . '/game_' . $id . '.jpg'; }
        public static function resolveItem($it,$remote) { if (!empty($it['show'])) throw new \RuntimeException('Episode resolver must not run'); if ($remote) self::$calls[]='item:' . $it['id']; return null; }
        public static function resolveShow($key,$name,$local,$remote,$dir) { if ($remote) self::$calls[]='show:' . $key; return null; }
        public static function resolveSeason($key,$name,$n,$local,$remote,$dir) { if ($remote) self::$calls[]='season:' . $key . ':' . $n; return null; }
        public static function movieSynopsis($id,$title,$year) { self::$calls[]='synopsis:' . $id; }
        public static function adoptFile($dest,$src) { return null; }
    }
    class PosterOptimise {
        public static $files = []; public static $optimised = [];
        public static function listOriginals() { return self::$files; }
        public static function statusForFile($file) { return ['needs'=>!in_array($file,self::$optimised,true)]; }
        public static function optimiseOne($file,$opts) { self::$optimised[]=$file; return ['ok'=>true]; }
    }
    require __DIR__ . '/../src/Services/ArtworkLibrary.php';
    require __DIR__ . '/../src/Services/MetadataWarmup.php';
    function check($condition,$message) { if (!$condition) throw new \RuntimeException($message); echo 'ok ' . $message . "\n"; }
    for ($i=0; $i<5000; $i++) Catalogue::$items[]=['id'=>dechex($i+10), 'type'=>'tv', 'show'=>'Series', 'section'=>'tv', 'season'=>($i%2)+1];
    Catalogue::$items[]=['id'=>'aa', 'title'=>'Film', 'type'=>'movies'];
    Catalogue::$items[]=['id'=>'bb', 'title'=>'Disney', 'type'=>'disney'];
    Catalogue::$items[]=['id'=>'cc', 'title'=>'Game', 'type'=>'roms'];
    $targets=ArtworkLibrary::targets();
    check(count($targets)===6,'5000 episodes become one show and two seasons, plus movies and game');
    $phases=[];
    $r=MetadataWarmup::run(function($phase,$done,$total,$file) use (&$phases) { $phases[]=$phase; });
    check($r['processed']===6 && count(Posters::$calls)===8,'unique remote lookups, including both movie synopses');
    check(!Posters::$budget && count($phases)>1,'budget cleared and progress reported');
    Posters::$calls=[];
    MetadataWarmup::run();
    check(Posters::$calls===[],'provider misses are not retried on every task');
    \Vaultcast\Repository::$docs=[];
    $r=MetadataWarmup::run(null,0);
    check($r['deferred']===6 && Posters::$calls===[],'remote budget exhaustion defers work without dropping it');
    $r=MetadataWarmup::run();
    check($r['deferred']===0 && count(Posters::$calls)===8,'next pass resumes deferred remote work');
    Posters::$calls=[];
    Matching::$pins[Catalogue::showKey('tv','Series')]=['provider'=>'tvmaze','tvmaze_id'=>123];
    MetadataWarmup::run();
    check(count(Posters::$calls)===3,'changed show pin bypasses the miss cooldown for show and seasons');
    PosterOptimise::$files=['movie_aa.jpg']; file_put_contents(STREAM_DATA . '/movie_aa.jpg',str_repeat('x',101));
    check(MetadataWarmup::run(null,120,0)['artwork_deferred']===1,'encoding budget defers remaining images');
    check(MetadataWarmup::run()['artwork_prepared']===1,'next pass optimises deferred image');
    check(MetadataWarmup::run()['artwork_prepared']===0,'prepared image is skipped');
    Maintenance::$cancel=true;
    try { MetadataWarmup::run(); throw new \LogicException('Cancellation swallowed'); } catch (\RuntimeException $e) { check($e->getMessage()==='Cancelled','cancellation escapes per-item error handlers'); }
    unlink(STREAM_DATA . '/movie_aa.jpg'); rmdir(STREAM_DATA);
    echo "all passed\n";
}
