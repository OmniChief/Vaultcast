<?php
declare(strict_types=1);

/** Private, bounded live catalogue shared by country browsing and playback. */
final class StalkerCountries {
    const CODES = 'AF AX AL DZ AS AD AO AI AQ AG AR AM AW AU AT AZ BS BH BD BB BY BE BZ BJ BM BT BO BQ BA BW BV BR IO BN BG BF BI CV KH CM CA KY CF TD CL CN CX CC CO KM CK CR HR CU CW CY CZ CI CD DK DJ DM DO EC EG SV GQ ER EE SZ ET FK FO FM FJ FI FR GF PF TF GA GM GE DE GH GI GR GL GD GP GU GT GG GN GW GY HT HM VA HN HK HU IS IN ID IR IQ IE IM IL IT JM JP JE JO KZ KE KI KW KG LA LV LB LS LR LY LI LT LU MO MG MW MY MV ML MT MH MQ MR MU YT MX MD MC MN ME MS MA MZ MM NA NR NP NL NC NZ NI NE NG NU NF KP MK MP NO OM PK PW PA PG PY PE PH PN PL PT PR QA CG RO RU RW RE BL SH KN LC MF PM VC WS SM ST SA SN RS SC SL SG SX SK SI SB SO ZA GS KR SS ES LK PS SD SR SJ SE CH SY TW TJ TZ TH TL TG TK TO TT TN TM TC TV TR UG UA AE GB UM US UY UZ VU VE VN VG VI WF EH YE ZM ZW';
    const GROUPS = ['Entertainment','News','Documentary','Sports','Movies','Kids','Music','Lifestyle','Education','Shopping','Religious','Adult','Other'];

    private static function key(array $feed): string {return 'stalker_countries_v1_'.hash('sha256',json_encode($feed));}
    private static function initial(): array {return ['rows'=>[],'next'=>1,'processed'=>0,'total'=>null,'done'=>false,'bulk'=>true,'signatures'=>[]];}
    public static function entry(array $feed,string $id): ?array {
        $index=\Vaultcast\Repository::cacheGet(self::key($feed));return $index['rows'][$id]??null;
    }
    private static function country(string $text): ?string {
        $aliases=['UK'=>'GB','USA'=>'US','UAE'=>'AE','GER'=>'DE','DEU'=>'DE','FRA'=>'FR','JPN'=>'JP','CAN'=>'CA','AUS'=>'AU'];
        // Bracketed markers can occur anywhere; bare codes must be a prefix.
        preg_match_all('/\[\s*([A-Z]{2,3})\s*\]|\(\s*([A-Z]{2,3})\s*\)|^\s*([A-Z]{2,3})\s*[|:._-]/i',$text,$matches,PREG_SET_ORDER);
        foreach($matches as $match){$code=strtoupper(($match[1]??'')?:($match[2]??'')?:($match[3]??''));$code=$aliases[$code]??$code;if(strpos(' '.self::CODES.' ',' '.$code.' ')!==false)return $code;}
        return null;
    }
    private static function group(string $name,string $genre): string {
        $rules=[
            'Adult'=>'adult|xxx|porn', 'News'=>'news|bbc parliament|cnn|al jazeera|euronews',
            'Sports'=>'sports?|espn|racing|football|cricket|golf|tennis',
            'Documentary'=>'documentar(?:y|ies)|discovery|national geographic|nat geo|history|animal planet|wildlife',
            'Kids'=>'kids?|children|cartoon|nickelodeon|cbeebies|cbbc|disney junior',
            'Movies'=>'movies?|cinema|films?', 'Music'=>'music|mtv|vevo',
            'Shopping'=>'shopping|qvc|teleshopping', 'Religious'=>'religio(?:n|us)|faith|christian|islam|church|worship',
            'Education'=>'education(?:al)?|learning', 'Lifestyle'=>'lifestyle|cooking|food|travel|home|garden',
            'Entertainment'=>'entertainment|general|drama|comedy|series|variety'];
        foreach([$name,$genre] as $text)foreach($rules as $group=>$pattern)if(preg_match('/\b(?:'.$pattern.')\b/iu',$text))return $group;
        return 'Other';
    }
    private static function reset(array $feed): void {
        $key=self::key($feed);$dir=sys_get_temp_dir().'/vaultcast_stalker';if(!is_dir($dir))@mkdir($dir,0700,true);
        $lock=@fopen($dir.'/countries-'.hash('sha256',$key).'.lock','c');
        if(!$lock)throw new RuntimeException('Channel cache storage is unavailable.');
        try{if(flock($lock,LOCK_EX|LOCK_NB)){\Vaultcast\Repository::cachePut($key,self::initial(),7200);flock($lock,LOCK_UN);}}finally{fclose($lock);}
    }
    private static function advance(array $feed): void {
        $key=self::key($feed);$dir=sys_get_temp_dir().'/vaultcast_stalker';if(!is_dir($dir))@mkdir($dir,0700,true);
        $lock=@fopen($dir.'/countries-'.hash('sha256',$key).'.lock','c');
        if(!$lock)throw new RuntimeException('Channel cache storage is unavailable.');
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return;}
        try {
            $index=\Vaultcast\Repository::cacheGet($key)??self::initial();
            if($index['done']||($index['retryAfter']??0)>time())return;
            unset($index['error'],$index['retryAfter']);
            try {
                $groups=stalker_live_groups($feed);$bulk=$index['bulk'];$result=null;
                if($bulk){
                    $index['bulk']=false;
                    try{
                        $result=stalker_call($feed,'get_all_channels');
                        if(!isset($result['data'])&&isset($result[0])&&is_array($result[0]))$result=['data'=>$result];
                        if(!is_array($result['data']??null)||!$result['data'])$result=null;
                    }catch(RuntimeException $ignored){}
                }
                if($result===null){
                    if($index['next']>10000)throw new RuntimeException('Provider catalogue exceeded the page limit.');
                    $result=stalker_ordered_page($feed,'itv',['sortby'=>'number','genre'=>'*','search'=>'','fav'=>0],$index['next']);$bulk=false;
                }
                $rows=$result['data']??null;if(!is_array($rows))throw new RuntimeException('Provider returned an invalid channel list.');
                $signature=hash('sha256',json_encode(array_column($rows,'id')));
                if(!$bulk&&$rows&&isset($index['signatures'][$signature]))throw new RuntimeException('Provider repeated a page before the catalogue was complete.');
                if(!$bulk&&$rows)$index['signatures'][$signature]=true;
                foreach($rows as $row){
                    if(!is_array($row)||!isset($row['id'])||empty($row['cmd']))continue;
                    $id=(string)$row['id'];$name=(string)($row['name']??$id);$genre=$groups[(string)($row['tv_genre_id']??'')]??(string)($row['group_title']??'');
                    $index['rows'][$id]=['id'=>$id,'name'=>$name,'description'=>$genre,'kind'=>'live','country'=>self::country($name)??self::country($genre)??'ZZ','group'=>self::group($name,$genre),'playback'=>stalker_live_entry($row)];
                }
                if(isset($result['total_items'])&&is_numeric($result['total_items'])&&(int)$result['total_items']>0)$index['total']=(int)$result['total_items'];
                if($bulk){
                    // A few portals paginate even their "all" endpoint. Start ordered paging if advertised as partial.
                    $index['done']=$index['total']===null||count($rows)>=$index['total'];
                    $index['processed']=$index['done']?count($rows):0;
                }else{
                    $index['processed']+=count($rows);$index['next']++;
                    $index['done']=$index['total']!==null?$index['processed']>=$index['total']:!$rows;
                    if(!$rows&&!$index['done'])throw new RuntimeException('Provider stopped returning channels before the catalogue was complete.');
                }
            }catch(RuntimeException $e){$index['error']='Could not finish loading this connection. Retry or check the connection in settings.';$index['retryAfter']=time()+120;}
            \Vaultcast\Repository::cachePut($key,$index,7200);
            unset($index);
            if(\Vaultcast\Repository::cacheGet($key)===null)throw new RuntimeException('Could not save the channel catalogue. Check server storage.');
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    /** Advance a single provider page in a short-lived CLI worker, never in a web request. */
    public static function run(string $feedId): array {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Stalker indexing requires CLI.');
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $feed=iptv_feed_by_id(iptv_load(),$feedId);
        if (!$feed || ($feed['type']??'')!=='stalker' || empty($feed['enabled'])) return ['ok'=>false,'error'=>'Connection unavailable'];
        // One provider scan at a time across the NAS, regardless of concurrent viewers.
        $path=STREAM_DATA.'/stalker-countries.worker.lock';
        $lock=@fopen($path,'c');
        if (!$lock) return ['ok'=>false,'error'=>'Index lock unavailable'];
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock);return ['ok'=>true,'busy'=>true]; }
        try { self::advance($feed); return ['ok'=>true]; }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private static function queue(array $feed): void {
        $dir=STREAM_DATA.'/runtime';
        if (!is_dir($dir)) @mkdir($dir,0700,true);
        $path=$dir.'/stalker-country-'.hash('sha256',self::key($feed)).'.launch';
        $lock=@fopen($path,'c+');
        if (!$lock) return;
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock);return; }
        try {
            // Avoid repeated spawns while a slow portal request is still outstanding.
            $last=(int)stream_get_contents($lock);
            if ($last>time()-35) return;
            ftruncate($lock,0);rewind($lock);fwrite($lock,(string)time());fflush($lock);
            try { Process::startPhpWorker('stalker-countries',(string)$feed['id']); }
            catch (Throwable $e) {
                // Permit a retry on the next browse rather than holding a stale throttle.
                ftruncate($lock,0);
            }
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public static function browse(array $state,array $opts): array {
        $country=strtoupper((string)($opts['country']??''));
        if(!preg_match('/^[A-Z]{2}$/D',$country)||($country!=='ZZ'&&strpos(' '.self::CODES.' ',' '.$country.' ')===false))throw new InvalidArgumentException('Choose a country.');
        $feeds=array_values(array_filter($state['feeds']??[],static function($feed){return ($feed['type']??'')==='stalker'&&!empty($feed['enabled']);}));
        $candidate=null;$least=PHP_INT_MAX;
        foreach($feeds as $feed){
            if(!empty($opts['refresh']))self::reset($feed);
            $index=\Vaultcast\Repository::cacheGet(self::key($feed));
            if(empty($index['done'])&&($index['retryAfter']??0)<=time()&&($index['processed']??0)<$least){$candidate=$feed;$least=$index['processed']??0;}
            unset($index);
        }
        if($candidate)self::queue($candidate);
        $offset=max(0,(int)($opts['offset']??0));$query=mb_strtolower(mb_substr(trim((string)($opts['q']??'')),0,200));$selected=(string)($opts['group']??'');
        $items=[];$counts=array_fill_keys(self::GROUPS,0);$total=0;$indexing=false;$progress=[];
        foreach($feeds as $feed){
            $index=\Vaultcast\Repository::cacheGet(self::key($feed))??self::initial();
            $indexing=$indexing||(!$index['done']&&empty($index['error']));
            $progress[]=['name'=>$feed['name'],'scanned'=>count($index['rows']),'total'=>$index['total'],'done'=>$index['done'],'error'=>$index['error']??null];
            foreach($index['rows'] as $item){
                if($item['country']!==$country||($query!==''&&mb_strpos(mb_strtolower($item['name'].' '.$item['description']),$query)===false))continue;
                $counts[$item['group']]++;
                if($selected!==''&&$item['group']!==$selected)continue;
                if($total>=$offset&&count($items)<120){unset($item['playback']);$item['feed']=$feed['id'];$item['connection']=$feed['name'];$items[]=$item;}
                $total++;
            }
            unset($index);
        }
        return ['ok'=>true,'items'=>$items,'groups'=>$counts,'total'=>$total,'hasMore'=>$offset+count($items)<$total,'indexing'=>$indexing,'connections'=>$progress];
    }
}
