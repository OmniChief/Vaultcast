<?php
/** Finder discovers current SQL-backed catalogue sidecars, rather than counting old cache files. */
namespace Vaultcast {
    class Repository {
        public static $cache = [];
        public static $revision = 1;
        public static function document($name,$default=null) { return $default; }
        public static function revision($name) { return self::$revision; }
        public static function cacheGet($name) { return self::$cache[$name] ?? null; }
        public static function cachePut($name,$data,$ttl) { self::$cache[$name]=$data; }
    }
    class Paths { public static function real($path) { return realpath($path) ?: null; } }
}
namespace {
    define('STREAM_DATA',sys_get_temp_dir() . '/vc-art-find-' . getmypid());
    mkdir(STREAM_DATA); mkdir(STREAM_DATA . '/posters');
    class Process { public static function capture($args,$timeout) { return ''; } }
    class Store { public static function settings() { return ['posters'=>['enabled'=>false]]; } }
    class Catalogue {
        public static $items=[]; public static $itemReads=0;
        public static function get() { return ['items'=>self::$items]; }
        public static function item($id) { self::$itemReads++; foreach(self::$items as $it)if($it['id']===$id)return $it; return null; }
    }
    require __DIR__ . '/../src/Services/Posters.php';
    require __DIR__ . '/../src/Services/ArtworkLibrary.php';
    require __DIR__ . '/../src/Services/PosterOptimise.php';
    file_put_contents(STREAM_DATA . '/local.png',str_repeat('x',200));
    file_put_contents(STREAM_DATA . '/posters/movie_deadbeef.jpg',str_repeat('x',200));
    Catalogue::$items=[
        ['id'=>'aa','title'=>'Local sidecar','type'=>'movies','poster'=>STREAM_DATA . '/local.png'],
        ['id'=>'bb','title'=>'No poster','type'=>'movies'],
        ['id'=>'cc','title'=>'Legacy Disney film','type'=>'disney']
    ];
    foreach(['movie_cc.jpg','movie_cc.webp','movie_cc_thumb.webp'] as $file)file_put_contents(STREAM_DATA . '/posters/' . $file,str_repeat('x',200));
    function check($c,$m){if(!$c)throw new \RuntimeException($m);echo 'ok ' . $m . "\n";}
    $r=PosterOptimise::find(0,1,'movies');
    check($r['total']===3 && count($r['items'])===1 && $r['next_offset']===1,'movie inventory matches library, includes unvisited local poster, excludes orphan cache');
    check(is_file(STREAM_DATA . '/posters/movie_aa.jpg'),'sidecar adopted into optimisable canonical cache');
    $r=PosterOptimise::find(1,1,'movies');
    check($r['missing_artwork']===1 && count($r['items'])===0 && $r['scanned']===2,'missing artwork counted separately from optimisable images');
    $r=PosterOptimise::find(2,1,'movies');
    check($r['ready']===1 && $r['done'] && $r['next_offset']===null,'ready poster counted and pagination completes');
    $r=PosterOptimise::find(0,100,'movies,games');
    check($r['total']===3 && $r['ready']+$r['missing_artwork']+count($r['items'])===3,'server-side multiple scopes have consistent totals');
    check(count(PosterOptimise::find(2,1,'movies',true)['items'])===1,'explicit overwrite still finds ready artwork');
    check(PosterOptimise::find(0,100,'games')['total']===0,'empty scope has no movie leakage');
    $before=Catalogue::$itemReads;
    PosterOptimise::summary();
    check(Catalogue::$itemReads===$before,'cache summary does not perform one item query per poster');
    Catalogue::$items[]=['id'=>'dd','type'=>'movies','title'=>'New movie'];
    check(PosterOptimise::find(0,100,'movies')['total']===3,'stable inventory reused during scan');
    \Vaultcast\Repository::$revision++;
    check(PosterOptimise::find(0,100,'movies')['total']===4,'catalogue revision invalidates artwork inventory');
    file_put_contents(STREAM_DATA . '/Portable.mkv','media');
    file_put_contents(STREAM_DATA . '/Portable-poster.jpg',str_repeat('x',200));
    $portable=Posters::resolveItem(['id'=>'aabb','type'=>'movies','media_kind'=>'video','title'=>'Portable','path'=>STREAM_DATA . '/Portable.mkv'],false);
    check($portable===Posters::movieCachePath('aabb'),'per-file exported movie art can refill the cache without network access');
    unlink(STREAM_DATA . '/Portable.mkv');unlink(STREAM_DATA . '/Portable-poster.jpg');
    foreach(glob(STREAM_DATA . '/posters/*') as $file)unlink($file);
    unlink(STREAM_DATA . '/local.png');rmdir(STREAM_DATA . '/posters');rmdir(STREAM_DATA);
    echo "all passed\n";
}
