<?php
declare(strict_types=1);

final class EpisodeSegments {
    private static function json(string $url): array {
        if(!function_exists('curl_init'))return [0,null];
        $body='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'Vaultcast/1.1.0',CURLOPT_WRITEFUNCTION=>static function($handle,$chunk) use (&$body){if(strlen($body)+strlen($chunk)>1048576)return 0;$body.=$chunk;return strlen($chunk);}]);
        curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return [$code,json_decode($body,true)];
    }
    public static function get(array $item,array $user,float $duration): array {
        $empty=['ok'=>true,'segments'=>[],'source'=>'TheIntroDB'];
        if(!AreaAccess::allowed($user,'library')||empty($item['show'])||!is_numeric($item['season'] ?? null)||!is_numeric($item['episode'] ?? null)||($user['prefs']['skip_segments'] ?? true)===false)return $empty;
        $season=(int)$item['season'];$episode=(int)$item['episode'];if($season<0||$episode<1||$season>1000||$episode>10000||$duration<300||$duration>21600)return $empty;
        $showKey=Catalogue::showKey((string)$item['section'],(string)$item['show']);
        $pin=Matching::pinnedTvmazeId($showKey);if($pin===0)return $empty;
        $guide=MediaFacts::guide($showKey);
        if(!$guide){$path=STREAM_DATA.'/tvmaze/show_'.$showKey.'_episodes.json';if(is_file($path))$guide=json_decode((string)file_get_contents($path),true);}
        $tvmazeId=$pin ?? (int)($guide['id'] ?? 0);$externals=$pin&&$pin!==(int)($guide['id'] ?? 0)?[]:($guide['externals'] ?? []);
        if(!$externals&&$tvmazeId>0){$identityKey='skip-identity:'.$tvmazeId;$identity=\Vaultcast\Repository::cacheGet($identityKey);
            if($identity===null){[$status,$record]=self::json('https://api.tvmaze.com/shows/'.$tvmazeId);$identity=['externals'=>$status===200&&is_array($record)?($record['externals'] ?? []):[]];\Vaultcast\Repository::cachePut($identityKey,$identity,$status===200?604800:300);}
            $externals=$identity['externals'] ?? [];
        }
        $query=['season'=>$season,'episode'=>$episode,'mapping'=>'official','duration_ms'=>(int)round($duration*1000),'details'=>'true'];
        if(!empty($externals['thetvdb']))$query['tvdb_id']=(int)$externals['thetvdb'];
        elseif(preg_match('/^tt[0-9]{7,10}$/D',(string)($externals['imdb'] ?? '')))$query['imdb_id']=$externals['imdb'];
        else return $empty;
        $cacheKey='episode-segments-v4:'.hash('sha256',json_encode([$query,$item['mtime'] ?? null,$item['size'] ?? null]));$cached=\Vaultcast\Repository::cacheGet($cacheKey);if(is_array($cached))return $cached;
        [$status,$data]=self::json('https://api.theintrodb.org/v4/media?'.http_build_query($query));
        if($status!==200||!is_array($data)){\Vaultcast\Repository::cachePut($cacheKey,$empty,$status===404?86400:300);return $empty;}
        if(($data['type'] ?? '')!=='tv'||isset($data['season'])&&(int)$data['season']!==$season||isset($data['episode'])&&(int)$data['episode']!==$episode){\Vaultcast\Repository::cachePut($cacheKey,$empty,86400);return $empty;}
        $group=$data['group_duration_ms'] ?? null;
        if(is_numeric($group)&&$group>0&&abs($group/1000-$duration)>max(15,$duration*.02)){\Vaultcast\Repository::cachePut($cacheKey,$empty,86400);return $empty;}
        $segments=[];
        foreach(['intro'=>'intro','credits'=>'outro'] as $field=>$kind){foreach(array_slice((array)($data[$field] ?? []),0,8) as $row){
            if(!is_array($row))continue;$rawStart=$row['start_ms'] ?? null;$rawEnd=$row['end_ms'] ?? null;
            if($rawStart===null&&$kind==='outro')continue;if($rawEnd===null&&$kind==='intro')continue;
            if($rawStart!==null&&!is_numeric($rawStart)||$rawEnd!==null&&!is_numeric($rawEnd))continue;
            $start=$rawStart===null?0:(float)$rawStart/1000;$end=$rawEnd===null?$duration:(float)$rawEnd/1000;
            if($start<0||$end<=$start||$end>$duration+1||$end-$start>($kind==='intro'?600:1800))continue;
            $segments[]=['kind'=>$kind,'start'=>$start,'end'=>min($duration,$end)];
        }}
        usort($segments,static function($a,$b){return $a['start']<=>$b['start'];});$out=$empty;$out['segments']=$segments;
        \Vaultcast\Repository::cachePut($cacheKey,$out,$segments?604800:86400);return $out;
    }
}
