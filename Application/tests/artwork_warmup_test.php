<?php
/** Real WebP generation from a local game sidecar, in background maintenance only. */
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
    define('STREAM_DATA', sys_get_temp_dir() . '/vc-art-warm-' . getmypid());
    @mkdir(STREAM_DATA . '/posters', 0775, true);
    if (!function_exists('imagewebp')) { echo "skip: PHP GD WebP is unavailable\n"; exit; }
    class Store { public static function settings() { return []; } }
    class Process { public static function capture($args, $timeout) { return ''; } }
    class MetadataSidecars { public static function run($progress=null) { return ['deferred'=>0, 'written'=>0, 'failed'=>0]; } }
    class Maintenance { public static function checkpoint() {} }
    class Catalogue {
        public static $warmed = 0;
        public static function get() { return ['items'=>[['id'=>'abcdef', 'type'=>'roms', 'media_kind'=>'rom', 'title'=>'Game']]]; }
        public static function item($id) { return ['title'=>'Game']; }
        public static function invalidateDerivedCaches() {}
        public static function warmBrowseViews() { self::$warmed++; }
        public static function homeLibrary() {}
        public static function showPosterHint($key) {}
    }
    class Posters {
        public static $resolutions=0;
        public static function enabled() { return false; }
        public static function beginRemoteBudget($seconds) {}
        public static function endRemoteBudget() {}
        public static function dir() { return STREAM_DATA . '/posters'; }
        public static function gameCachePath($id) { return self::dir() . '/game_' . $id . '.jpg'; }
        public static function resolveItem($item, $remote) { self::$resolutions++; if ($remote) throw new \RuntimeException('Remote request made while disabled'); return STREAM_DATA . '/local.png'; }
        public static function adoptFile($dest, $src) { if (!is_file($dest)) copy($src, $dest); return $dest; }
    }
    require __DIR__ . '/../src/Services/PosterOptimise.php';
    require __DIR__ . '/../src/Services/ArtworkLibrary.php';
    require __DIR__ . '/../src/Services/MetadataWarmup.php';
    $image = imagecreatetruecolor(1200, 1800);
    $colour = imagecolorallocate($image, 80, 120, 200); imagefill($image, 0, 0, $colour);
    imagepng($image, STREAM_DATA . '/local.png'); imagedestroy($image);
    $result = MetadataWarmup::run();
    $thumb = STREAM_DATA . '/posters/game_abcdef_thumb.webp';
    if ($result['artwork_prepared'] !== 1 || $result['artwork_failed'] || !is_file($thumb)) throw new \RuntimeException(json_encode($result));
    $size = getimagesize($thumb);
    if ($size[0] > 320 || filesize($thumb) >= filesize(STREAM_DATA . '/local.png')) throw new \RuntimeException('Artwork was not resized');
    if (PosterOptimise::pickServePath(Posters::gameCachePath('abcdef'), 'thumb', true) !== $thumb) throw new \RuntimeException('Thumbnail is not served');
    if (MetadataWarmup::run()['artwork_prepared'] !== 0 || Catalogue::$warmed !== 2) throw new \RuntimeException('Warmup is not idempotent');
    if (Posters::$resolutions!==1) throw new \RuntimeException('Canonical artwork reread local sidecars');
    echo '1200px local sidecar -> ' . $size[0] . 'px WebP, ' . filesize($thumb) . " bytes\n";
    foreach (glob(STREAM_DATA . '/posters/*') as $file) unlink($file);
    unlink(STREAM_DATA . '/local.png'); rmdir(STREAM_DATA . '/posters'); rmdir(STREAM_DATA);
    echo "all passed\n";
}
