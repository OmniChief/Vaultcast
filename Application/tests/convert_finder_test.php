<?php
/** Conversion finder must agree with the library without spawning ffprobe. */
class Store { public static function settings() { return []; } }
class MediaProbe { public static function probeSummary(...$args) { throw new RuntimeException('HTTP finder ran ffprobe'); } }
class MediaFacts {
    public static $preloaded = [];
    public static function preload($items) { self::$preloaded=$items; }
    public static function get($it) {
        if ($it['id']==='unknown') return null;
        return ['video_codec'=>$it['id']==='ready'?'h264':'hevc', 'audio_codec'=>'aac', 'needs_convert'=>$it['id']!=='ready'];
    }
}
class Catalogue {
    public static function get() { return ['items'=>[
        ['id'=>'ready','type'=>'movies','media_kind'=>'video','ext'=>'mkv','path'=>'a.mkv'],
        ['id'=>'disney','type'=>'disney','media_kind'=>'video','ext'=>'mp4','path'=>'b.mp4'],
        ['id'=>'unknown','type'=>'movies','media_kind'=>'video','ext'=>'mkv','path'=>'c.mkv'],
        ['id'=>'game','type'=>'roms','media_kind'=>'rom','ext'=>'zip']
    ]]; }
    public static function needsConvert($it,$probe) { if($probe)throw new RuntimeException('Probing enabled'); return $it['ext']==='mkv'; }
    public static function publicItem($it,$fetch) { return $it; }
}
require __DIR__ . '/../src/Services/Convert.php';
$r=Convert::findNeedsPage(0,40,['movies']);
if ($r['total_video']!==3 || array_column($r['items'],'id')!==['disney','unknown'] || count(MediaFacts::$preloaded)!==3) throw new RuntimeException(json_encode($r));
$r=Convert::findNeedsPage(0,1,['disney']);
if ($r['total_video']!==3 || $r['scanned']!==1 || $r['done']) throw new RuntimeException('Pagination or legacy type alias failed');
if (Convert::findNeedsPage(0,40,['games'])['total_video']!==0) throw new RuntimeException('Game filter must be empty');
echo "all passed\n";
