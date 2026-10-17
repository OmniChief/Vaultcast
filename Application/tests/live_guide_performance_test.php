<?php
namespace Vaultcast {
    class Repository {
        public static $cache=[], $rev=[], $ttls=[];
        public static function revision($name) { return self::$rev[$name]??1; }
        public static function cacheGet($key) { return self::$cache[$key]??null; }
        public static function cachePut($key,$data,$ttl) { self::$cache[$key]=$data; self::$ttls[]=$ttl; }
    }
}
namespace {
    require __DIR__.'/../src/Services/LiveGuideCache.php';
    $calls=0;$now=(int)round(microtime(true)*1000);
    $build=function($hours,$slim)use(&$calls,$now){
        $calls++;if($slim)throw new \RuntimeException('Snapshot needs complete programme windows');
        return ['ok'=>true,'hours'=>$hours,'channels'=>[['id'=>'one','now'=>['title'=>'stale'], 'programs'=>[
            ['title'=>'current','startUnix'=>$now-10000,'endUnix'=>$now+10000],
            ['title'=>'next','startUnix'=>$now+10000,'endUnix'=>$now+30000]
        ]]]];
    };
    function check($c,$m){if(!$c)throw new \RuntimeException($m);echo 'ok '.$m."\n";}
    $viewer=['user_id'=>'u','username'=>'user','role'=>'user'];
    $a=LiveGuideCache::read(2,true,$viewer,$build);$b=LiveGuideCache::read(2,true,$viewer,$build);
    check($calls===1,'warm guide avoids loading station and EPG again');
    check(!isset($b['channels'][0]['programs'])&&$b['channels'][0]['now']['title']==='current'&&$b['channels'][0]['next']['title']==='next','slim snapshot advances current/next and omits programme lists');
    check(\Vaultcast\Repository::$ttls[0]===300,'Home teaser caches schedule windows for five minutes');
    LiveGuideCache::read(2,true,['user_id'=>'other','role'=>'user'],$build);
    check($calls===2,'restricted viewers cannot reuse another user response');
    \Vaultcast\Repository::$rev['live_tvstudio']=2;
    LiveGuideCache::read(2,true,$viewer,$build);check($calls===3,'channel permission changes invalidate cached guide');
    LiveGuideCache::read(2,true,['id'=>'a','role'=>'admin'],$build);LiveGuideCache::read(2,true,['id'=>'b','role'=>'admin'],$build);
    check($calls===4,'admin prewarm serves admins with identical unrestricted visibility');
    $full=LiveGuideCache::read(12,false,$viewer,$build);check(isset($full['channels'][0]['programs'])&&end(\Vaultcast\Repository::$ttls)===30,'full guide has a separate short cache and retains programmes');
    echo "all passed\n";
}
