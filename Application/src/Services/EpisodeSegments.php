<?php
declare(strict_types=1);

final class EpisodeSegments {
    private static $lastRequest=0.0;
    public static function eligible(array $item): bool {return !in_array($item['media_kind'] ?? '', ['rom','audio'],true)&&(!empty($item['show'])||in_array($item['type'] ?? '',['movies','movie','disney'],true));}
    private static function json(string $url): array {
        if(PHP_SAPI==='cli'){Maintenance::checkpoint();$wait=.3-(microtime(true)-self::$lastRequest);if($wait>0)usleep((int)($wait*1000000));self::$lastRequest=microtime(true);}
        if(!function_exists('curl_init'))return [0,null];
        $body='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'Vaultcast/1.1.0',CURLOPT_WRITEFUNCTION=>static function($handle,$chunk) use (&$body){if(strlen($body)+strlen($chunk)>1048576)return 0;$body.=$chunk;return strlen($chunk);}]);
        curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return [$code,json_decode($body,true)];
    }
    private static function fingerprint(array $item): string { return hash('sha256',json_encode([$item['id'],$item['mtime'] ?? null,$item['size'] ?? null])); }
    public static function saved(array $item): ?array {
        $record=\Vaultcast\Repository::document('episode-segments:'.$item['id']);
        return is_array($record)&&($record['fingerprint'] ?? '')===self::fingerprint($item)?$record:null;
    }
    public static function edit(array $item,array $body): array {
        if(!self::eligible($item))json_error('Movie, TV or anime video required');
        $key='episode-segments:'.$item['id'];
        if(($body['action'] ?? '')==='reset'){\Vaultcast\Repository::putDocument($key,null);return ['ok'=>true,'segments'=>[]];}
        $rows=$body['segments'] ?? null;$duration=$body['duration'] ?? 0;
        if(!is_array($rows)||count($rows)>16||!is_numeric($duration)||!is_finite((float)$duration)||$duration<0||$duration>21600)json_error('Invalid episode timings');
        $segments=[];foreach($rows as $row){
            if(!is_array($row)||!in_array($row['kind'] ?? '',['intro','outro'],true)||!is_numeric($row['start'] ?? null)||!is_numeric($row['end'] ?? null))json_error('Enter valid start and end times');
            $start=(float)$row['start'];$end=(float)$row['end'];
            if(!is_finite($start)||!is_finite($end)||$start<0||$end<=$start||$end>21600||($duration>0&&$end>$duration))json_error('End must follow start and be within the episode');
            $segments[]=['kind'=>$row['kind'],'start'=>$start,'end'=>$end];
        }
        usort($segments,static function($a,$b){return $a['start']<=>$b['start'];});
        $lookup=$body['lookup'] ?? null;if(!is_array($lookup)||strlen(json_encode($lookup))>1000)$lookup=null;
        $record=['lookup'=>$lookup,'ok'=>true,'segments'=>$segments,'source'=>'Admin','duration'=>(float)$duration,'fingerprint'=>self::fingerprint($item),'updated_at'=>time()];
        \Vaultcast\Repository::putDocument($key,$record);return $record;
    }
    private static function persist(array $item,array $out,float $duration,$pin): array {
        $out['duration']=$duration;$out['fingerprint']=self::fingerprint($item);$out['match_pin']=$pin;$out['updated_at']=time();
        // Recheck for an administrator correction after the remote lookup finishes.
        $saved=self::saved($item);if(($saved['source'] ?? '')==='Admin')return $saved;
        \Vaultcast\Repository::putDocument('episode-segments:'.$item['id'],$out);return $out;
    }
    public static function get(array $item,array $user,float $duration): array {
        $empty=['ok'=>true,'segments'=>[],'source'=>'TheIntroDB'];
        if(!AreaAccess::allowed($user,'library')||!self::eligible($item)||($user['prefs']['skip_segments'] ?? true)===false)return $empty;
        if(empty($item['show']))return self::movie($item,$duration);
        $season=(int)$item['season'];$episode=(int)$item['episode'];if($season<0||$episode<0||$season>1000||$episode>10000||!is_finite($duration)||$duration<=0||$duration>21600)return $empty;
        $showKey=Catalogue::showKey((string)$item['section'],(string)$item['show']);
        $pin=Matching::pinnedTvmazeId($showKey);$saved=self::saved($item);if($saved&&(($saved['source'] ?? '')==='Admin'||($saved['match_pin'] ?? null)===$pin))return $saved;if($pin===0)return $empty;
        $identity=self::identity($item);$externals=$identity['externals'];
        $query=['season'=>$season,'episode'=>$episode,'mapping'=>'official','duration_ms'=>(int)round($duration*1000),'details'=>'true','merge_unknown'=>'true'];
        if(!empty($externals['thetvdb']))$query['tvdb_id']=(int)$externals['thetvdb'];
        elseif(preg_match('/^tt[0-9]{7,10}$/D',(string)($externals['imdb'] ?? '')))$query['imdb_id']=$externals['imdb'];
        else return $empty;
        $out=self::lookup($query,$duration,false,$item);
        return !empty($out['segments'])?self::persist($item,$out,$duration,$pin):$out;
    }
    public static function identity(array $item): array {
        if(empty($item['show']))return self::movieIdentity($item);
        $showKey=Catalogue::showKey((string)$item['section'],(string)$item['show']);$pin=Matching::pinnedTvmazeId($showKey);
        $guide=MediaFacts::guide($showKey);
        if(!$guide){$path=STREAM_DATA.'/tvmaze/show_'.$showKey.'_episodes.json';if(is_file($path))$guide=json_decode((string)file_get_contents($path),true);}
        $tvmazeId=$pin ?? (int)($guide['id'] ?? 0);$externals=$pin&&$pin!==(int)($guide['id'] ?? 0)?[]:($guide['externals'] ?? []);
        if(!$externals&&$tvmazeId>0){$identityKey='skip-identity:'.$tvmazeId;$identity=\Vaultcast\Repository::cacheGet($identityKey);
            if($identity===null){[$status,$record]=self::json('https://api.tvmaze.com/shows/'.$tvmazeId);$identity=['externals'=>$status===200&&is_array($record)?($record['externals'] ?? []):[]];\Vaultcast\Repository::cachePut($identityKey,$identity,$status===200?604800:300);}
            $externals=$identity['externals'] ?? [];
        }
        return ['tvmaze_id'=>$tvmazeId,'externals'=>$externals];
    }
    private static function movieIdentity(array $item): array {
        $override=Matching::itemOverride($item['id']) ?? [];$poster=MetadataPins::get('movie_poster',$item['id']) ?? [];$synopsis=MetadataPins::get('movie_synopsis',$item['id']) ?? [];
        foreach([$item['imdb_id'] ?? '',$override['imdb_id'] ?? '',$poster['provider_id'] ?? '',$synopsis['provider_id'] ?? ''] as $id)if(preg_match('/^tt[0-9]{7,10}$/D',(string)$id))return ['externals'=>['imdb'=>$id]];
        $cached=Posters::movieSynopsisCached($item['id']);$source=(string)($cached['source'] ?? '');
        if(preg_match('/^omdb:(tt[0-9]{7,10})$/D',$source,$match))return ['externals'=>['imdb'=>$match[1]]];
        $page=(string)($poster['page_title'] ?? $synopsis['page_title'] ?? '');
        if($page===''&&preg_match('#^https://en\.wikipedia\.org/wiki/(.+)$#D',$source,$match))$page=rawurldecode($match[1]);
        if($page==='')return ['externals'=>[]];
        $key='segment-movie-id:'.hash('sha256',$page);$cached=\Vaultcast\Repository::cacheGet($key);if($cached!==null)return $cached;
        [$status,$summary]=self::json('https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode($page));$entity=$summary['wikibase_item'] ?? '';$id=null;
        if($status===200&&preg_match('/^Q[0-9]+$/D',(string)$entity)){[$status,$claims]=self::json('https://www.wikidata.org/w/api.php?action=wbgetclaims&format=json&entity='.$entity.'&property=P345');foreach($claims['claims']['P345'] ?? [] as $claim){$value=$claim['mainsnak']['datavalue']['value'] ?? '';if(preg_match('/^tt[0-9]{7,10}$/D',(string)$value)){$id=$value;break;}}}
        $out=['externals'=>$id?['imdb'=>$id]:[]];\Vaultcast\Repository::cachePut($key,$out,$id?604800:3600);return $out;
    }
    private static function movie(array $item,float $duration): array {
        $empty=['ok'=>true,'segments'=>[],'source'=>'TheIntroDB'];if(!is_finite($duration)||$duration<=0||$duration>21600)return $empty;
        $saved=self::saved($item);if(($saved['source'] ?? '')==='Admin')return $saved;
        $identity=self::movieIdentity($item);$id=$identity['externals']['imdb'] ?? null;if(!$id)return $empty;
        if($saved&&($saved['lookup']['imdb_id'] ?? '')===$id)return $saved;
        $out=self::lookup(['imdb_id'=>$id,'duration_ms'=>(int)round($duration*1000),'details'=>'true','merge_unknown'=>'true'],$duration,false,$item);
        return !empty($out['segments'])?self::persist($item,$out,$duration,null):$out;
    }
    public static function editor(array $item): array {
        $record=self::saved($item) ?? ['ok'=>true,'segments'=>[],'duration'=>0];
        if(empty($record['duration'])){$facts=MediaFacts::get($item);$record['duration']=(float)($facts['duration'] ?? 0);}
        $record['is_movie']=empty($item['show']);$record['identity']=self::identity($item);$record['season']=(int)($item['season'] ?? 1);$record['episode']=(int)($item['episode'] ?? 1);return $record;
    }
    public static function search(string $text): array {
        $text=trim($text);if(strlen($text)<2||strlen($text)>160)json_error('Enter a show name (2–160 characters)');
        $key='segment-show-search:'.hash('sha256',strtolower($text));$cached=\Vaultcast\Repository::cacheGet($key);if($cached!==null)return $cached;
        [$status,$data]=self::json('https://api.tvmaze.com/search/shows?q='.rawurlencode($text));
        if($status!==200||!is_array($data))json_error('Show search is temporarily unavailable',502);
        $results=[];foreach(array_slice($data,0,15) as $row){$show=$row['show'] ?? [];if(empty($show['externals']['thetvdb'])&&empty($show['externals']['imdb']))continue;
            $results[]=['name'=>$show['name'] ?? '', 'year'=>substr((string)($show['premiered'] ?? ''),0,4),'tvdb_id'=>$show['externals']['thetvdb'] ?? null,'imdb_id'=>$show['externals']['imdb'] ?? null];}
        $out=['ok'=>true,'results'=>$results];\Vaultcast\Repository::cachePut($key,$out,600);return $out;
    }
    public static function preview(array $item,array $body): array {
        $kind=$body['id_type'] ?? 'tvdb_id';$id=trim((string)($body['id'] ?? ''));
        if(!in_array($kind,['tvdb_id','tmdb_id','imdb_id'],true)||($kind==='imdb_id'?!preg_match('/^tt[0-9]{7,8}$/D',$id):!preg_match('/^[1-9][0-9]{0,7}$/D',$id)))json_error('Enter a valid TVDB, TMDB or IMDb show ID');
        $season=filter_var($body['season'] ?? null,FILTER_VALIDATE_INT);$episode=filter_var($body['episode'] ?? null,FILTER_VALIDATE_INT);$mapping=$body['mapping'] ?? 'official';$duration=$body['duration'] ?? 0;
        if($season===false||$season<0||$season>1000||$episode===false||$episode<0||$episode>10000||!in_array($mapping,['official','default','dvd','alternate','absolute','regional'],true)||!is_numeric($duration)||!is_finite((float)$duration)||$duration<=0||$duration>21600)json_error('Enter valid episode numbers and the local episode length');
        $query=[$kind=>$id,'duration_ms'=>(int)round($duration*1000),'details'=>'true','merge_unknown'=>'true'];if(!empty($item['show']))$query+=['season'=>$season,'episode'=>$episode,'mapping'=>$mapping];
        return self::lookup($query,(float)$duration,true,$item);
    }
    private static function lookup(array $query,float $duration,bool $fresh,array $item): array {
        $empty=['ok'=>true,'segments'=>[],'source'=>'TheIntroDB','duration'=>$duration,'lookup'=>$query];
        $cacheKey='episode-segments-v5:'.hash('sha256',json_encode([$query,$item['mtime'] ?? null,$item['size'] ?? null]));
        if(!$fresh){$cached=\Vaultcast\Repository::cacheGet($cacheKey);if(is_array($cached))return $cached;}
        [$status,$data]=self::json('https://api.theintrodb.org/v4/media?'.http_build_query($query));
        if($status!==200||!is_array($data)){$empty['http_status']=$status;$empty['message']=$status===404?'No timestamps found for this show / episode.':($status===429?'TheIntroDB rate limit reached; try again later.':'TheIntroDB lookup failed (HTTP '.$status.').');\Vaultcast\Repository::cachePut($cacheKey,$empty,$status===404?86400:300);return $empty;}
        if(($data['type'] ?? '')!==(empty($item['show'])?'movie':'tv')||isset($query['season'],$data['season'])&&(int)$data['season']!==(int)$query['season']||isset($query['episode'],$data['episode'])&&(int)$data['episode']!==(int)$query['episode']){$empty['message']='The provider returned a different media item or episode.';\Vaultcast\Repository::cachePut($cacheKey,$empty,86400);return $empty;}
        $group=$data['group_duration_ms'] ?? null;$empty['provider_duration']=is_numeric($group)?(float)$group/1000:null;
        $empty['tvdb_id']=$data['tvdb_id'] ?? null;
        if(is_numeric($group)&&$group>0&&abs($group/1000-$duration)>max(15,$duration*.02)){$empty['message']='Provider runtime differs from this file; timings were not applied. Check the show, episode order and release.';\Vaultcast\Repository::cachePut($cacheKey,$empty,86400);return $empty;}
        $segments=[];
        foreach((empty($item['show'])?['credits'=>'outro']:['intro'=>'intro','credits'=>'outro']) as $field=>$kind){foreach(array_slice((array)($data[$field] ?? []),0,8) as $row){
            if(!is_array($row))continue;$rawStart=$row['start_ms'] ?? null;$rawEnd=$row['end_ms'] ?? null;
            if($rawStart===null&&$kind==='outro')continue;if($rawEnd===null&&$kind==='intro')continue;
            if($rawStart!==null&&!is_numeric($rawStart)||$rawEnd!==null&&!is_numeric($rawEnd))continue;
            $start=$rawStart===null?0:(float)$rawStart/1000;$end=$rawEnd===null?$duration:(float)$rawEnd/1000;
            if($start<0||$end<=$start||$end>$duration+1||$end-$start>($kind==='intro'?600:1800))continue;
            $segments[]=['kind'=>$kind,'start'=>$start,'end'=>min($duration,$end),'confidence'=>is_numeric($row['confidence'] ?? null)?(float)$row['confidence']:null,'submission_count'=>is_numeric($row['submission_count'] ?? null)?(int)$row['submission_count']:null];
        }}
        usort($segments,static function($a,$b){return $a['start']<=>$b['start'];});$out=$empty;$out['segments']=$segments;
        if(!$segments)$out['message']='No usable intro or credits timestamps for this episode / release.';elseif(!$group)$out['message']='Timestamps found, but provider runtime is unknown. Verify these timings against your file before saving.';\Vaultcast\Repository::cachePut($cacheKey,$out,$segments?604800:86400);return $out;
    }
}
