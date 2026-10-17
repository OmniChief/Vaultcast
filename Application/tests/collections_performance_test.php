<?php
namespace Vaultcast {
    class Repository {
        public static $cache=[], $rev=['collections'=>1,'catalogue'=>1], $reads=0;
        public static function revision($name){return self::$rev[$name]??0;}
        public static function cacheGet($key){return self::$cache[$key]??null;}
        public static function cachePut($key,$data,$ttl){self::$cache[$key]=$data;}
        public static function rows($sql,$args){self::$reads++;return [['id'=>'movie','title'=>'Film']];}
    }
}
namespace {
    class Store { public static $reads=0;public static function get($name,$default){self::$reads++;return [['id'=>'c','name'=>'Collection','refreshed_at'=>'now','parts'=>[['item_id'=>'movie','item'=>['id'=>'movie','title'=>'Film']]]]];} }
    require __DIR__.'/../src/Services/Collections.php';
    $a=Collections::publicList();$b=Collections::publicList();
    if($a!==$b||\Vaultcast\Repository::$reads!==1||Store::$reads!==1)throw new \RuntimeException('Warm collection list reread all records');
    \Vaultcast\Repository::$rev['catalogue']++;Collections::publicList();
    if(\Vaultcast\Repository::$reads!==2)throw new \RuntimeException('Library changes did not update collection availability');
    \Vaultcast\Repository::$rev['collections']++;Collections::publicList();
    if(Store::$reads!==3)throw new \RuntimeException('Collection edits did not invalidate summary');
    echo "all passed\n";
}
