<?php
namespace Vaultcast {
    class Repository {
        public static $docs=[];
        public static function document($key,$default=null) { return self::$docs[$key] ?? $default; }
        public static function putDocument($key,$data) { self::$docs[$key]=$data; }
        public static function revision($name) { return 1; }
    }
}
namespace {
    define('STREAM_DATA',sys_get_temp_dir() . '/vc-sidecars-' . getmypid());
    mkdir(STREAM_DATA); mkdir(STREAM_DATA . '/library'); mkdir(STREAM_DATA . '/library/Series'); mkdir(STREAM_DATA . '/library/Series/Season 1'); mkdir(STREAM_DATA . '/cache');
    if (!function_exists('imagewebp')) { echo "skip: GD WebP unavailable\n"; exit; }
    class Store { public static function settings() { return ['sections'=>[['id'=>'movies','path'=>STREAM_DATA . '/library'],['id'=>'tv','path'=>STREAM_DATA . '/library']]]; } }
    class Catalogue {
        public static $items=[];
        public static function get() { return ['items'=>self::$items]; }
        public static function showDirFromItem($it) { return STREAM_DATA . '/library/Series'; }
        public static function showKey($section,$show) { return 'abc'; }
    }
    class ArtworkLibrary { public static $targets=[]; public static function targets() { return self::$targets; } }
    class Posters {
        public static function movieSynopsisCached($id) { return ['summary'=>'A & B <escape>', 'source'=>'test']; }
        public static function showSynopsisCached($key) { return 'A show synopsis'; }
    }
    class MetadataPins { public static function get($kind,$id) { return ['provider'=>'test','provider_id'=>'123']; } }
    class MediaFacts {
        public static function preload($items) {}
        public static function get($it) { return ['video_codec'=>'h264','audio_codec'=>'aac','present'=>1]; }
        public static function guide($key) { return ['id'=>123,'genres'=>['Comedy'], 'episodes'=>[['season'=>1,'number'=>1,'name'=>'A good episode','runtime'=>30,'airdate'=>'2020-01-01']]]; }
    }
    class Maintenance {
        public static $count=0; public static $cancelAfter=PHP_INT_MAX; public static $starts=0;
        public static function checkpoint() { if (++self::$count > self::$cancelAfter) throw new \RuntimeException('Cancelled'); }
        public static function start($task,$actor) { self::$starts++; $job=['id'=>'job-' . self::$starts,'status'=>'queued']; \Vaultcast\Repository::putDocument('maintenance-job:' . $job['id'],$job); return ['ok'=>true,'job'=>$job]; }
    }
    require __DIR__ . '/../src/Services/MetadataSidecars.php';
    function check($c,$message) { if(!$c)throw new \RuntimeException($message);echo 'ok ' . $message . "\n"; }
    function cleanup($dir) { foreach(scandir($dir) as $f) { if($f==='.'||$f==='..')continue;$p=$dir.'/'.$f;if(is_dir($p)&&!is_link($p))cleanup($p);else unlink($p); }rmdir($dir); }
    register_shutdown_function(function(){cleanup(STREAM_DATA);});
    $movie=['id'=>'aa','section'=>'movies','type'=>'movies','title'=>'A & B','year'=>2020,'path'=>STREAM_DATA . '/library/A & B.mkv'];
    $other=['id'=>'bb','section'=>'movies','type'=>'movies','title'=>'Second film','path'=>STREAM_DATA . '/library/Second.mkv'];
    $episode=['id'=>'cc','section'=>'tv','type'=>'tv','title'=>'Series S01E01','show'=>'Series','season'=>1,'episode'=>1,'path'=>STREAM_DATA . '/library/Series/Season 1/Episode.mkv'];
    $outside=['id'=>'dd','section'=>'movies','type'=>'movies','title'=>'Outside','path'=>STREAM_DATA . '/outside.mkv'];
    foreach([$movie,$other,$episode,$outside] as $it)file_put_contents($it['path'],'media');
    Catalogue::$items=[$movie,$other,$episode,$outside];
    $im=imagecreatetruecolor(16,24); imagejpeg($im,STREAM_DATA . '/cache/movie_aa.jpg'); imagewebp($im,STREAM_DATA . '/cache/movie_aa.webp'); imagewebp($im,STREAM_DATA . '/cache/movie_aa_thumb.webp');imagedestroy($im);
    ArtworkLibrary::$targets=[
      ['kind'=>'movies','item'=>$movie,'path'=>STREAM_DATA . '/cache/movie_aa.jpg'],
      ['kind'=>'movies','item'=>$other,'path'=>STREAM_DATA . '/cache/movie_aa.jpg'],
      ['kind'=>'shows','key'=>'abc','item'=>$episode,'path'=>STREAM_DATA . '/cache/movie_aa.jpg'],
      ['kind'=>'seasons','key'=>'abc','season'=>1,'item'=>$episode,'path'=>STREAM_DATA . '/cache/movie_aa.jpg'],
      ['kind'=>'movies','item'=>$outside,'path'=>STREAM_DATA . '/cache/movie_aa.jpg']
    ];
    file_put_contents(STREAM_DATA . '/library/A & B.nfo','External media manager NFO');
    check(MetadataSidecars::run(null,0)['deferred']===6,'bounded pass defers export');
    $out=MetadataSidecars::run();
    check($out['failed']===0 && $out['skipped']===1,'exports configured library only');
    check(file_get_contents(STREAM_DATA . '/library/A & B.nfo')==='External media manager NFO','existing NFO preserved');
    $nfo=file_get_contents(STREAM_DATA . '/library/A & B.vaultcast.nfo');
    check(strpos($nfo,'A &amp; B &lt;escape&gt;')!==false && strpos($nfo,'h264')!==false && strpos($nfo,'provider_id')!==false,'fallback backup includes escaped synopsis, provider and media facts');
    check(strpos($nfo,STREAM_DATA)===false,'NFO does not disclose absolute library paths');
    foreach(['A & B-poster.jpg','A & B-poster.webp','A & B-thumb.webp','Second-poster.jpg','Series/tvshow.nfo','Series/poster.jpg','Series/poster.webp','Series/season01.nfo','Series/season01-poster.jpg','Series/Season 1/Episode.nfo'] as $name)check(is_file(STREAM_DATA . '/library/' . $name),'writes ' . $name);
    check(!is_file(STREAM_DATA . '/library/poster.jpg'),'flat movie library uses per-film artwork names');
    check(strpos(file_get_contents(STREAM_DATA . '/library/Series/Season 1/Episode.nfo'),'<title>A good episode</title>')!==false,'episode NFO uses cached guide metadata');
    check(MetadataSidecars::run()['written']===0,'repeat export is idempotent');
    ArtworkLibrary::$targets[1]['item']['title']='Changed title';MetadataSidecars::run();
    check(strpos(file_get_contents(STREAM_DATA . '/library/Second.nfo'),'Changed title')!==false,'owned NFO updates after metadata changes');
    file_put_contents(STREAM_DATA . '/library/Second.nfo','User edit');MetadataSidecars::run();
    check(file_get_contents(STREAM_DATA . '/library/Second.nfo')==='User edit' && is_file(STREAM_DATA . '/library/Second.vaultcast.nfo'),'manual edits preserved with a separate fetched backup');
    check(!is_file(STREAM_DATA . '/outside.nfo'),'outside-root export rejected');
    Maintenance::$count=0;Maintenance::$cancelAfter=1;
    try { MetadataSidecars::run();throw new \LogicException('Cancellation ignored'); }catch(\RuntimeException $e){check($e->getMessage()==='Cancelled','cancellation escapes exporter');}
    Maintenance::$cancelAfter=PHP_INT_MAX;MetadataSidecars::run();
    check(\Vaultcast\Repository::$docs['sidecar-export-cursor']['offset']===0,'resume completes and resets cursor');
    MetadataSidecars::schedule();MetadataSidecars::schedule();check(Maintenance::$starts===1,'queued preparation coalesces repeated matches');
    \Vaultcast\Repository::$docs['maintenance-job:job-1']['status']='running';
    MetadataSidecars::schedule();MetadataSidecars::schedule();check(Maintenance::$starts===2,'changes during a running snapshot queue only one successor');
    file_put_contents(STREAM_DATA . '/library/Series/poster.jpg','User changed the artwork');
    MetadataSidecars::invalidateShow('abc');
    check(file_get_contents(STREAM_DATA . '/library/Series/poster.jpg')==='User changed the artwork','rematch preserves user-modified show artwork');
    check(!is_file(STREAM_DATA . '/library/Series/poster.webp') && !is_file(STREAM_DATA . '/library/Series/season01-poster.jpg'),'rematch removes managed show and season exports before resolving new art');
    MetadataSidecars::invalidateMovie($movie);
    check(!is_file(STREAM_DATA . '/library/A & B-poster.jpg') && is_file(STREAM_DATA . '/library/Second-poster.jpg'),'movie rematch removes only its own managed art');
    file_put_contents(STREAM_DATA . '/cache/movie_aa.jpg','invalid image');
    check(MetadataSidecars::run()['failed']>0,'unreadable artwork is reported without aborting other exports');
    check(glob(STREAM_DATA . '/library/.vaultcast-*')===[],'atomic writer leaves no temporary files');
    echo "all passed\n";
}
