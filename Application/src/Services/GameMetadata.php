<?php
declare(strict_types=1);

final class GameMetadata {
    const VERSION=2;
    private static $indexes=[];
    public static function cleanTitle(string $title): string {
        $title=preg_replace('/\.(?:zip|7z|nes|sfc|smc|n64|z64|v64|gb|gbc|gba|nds|md|gen|smd|sms|gg|bin|cue|chd|iso|pce|a26)$/i','',trim($title));
        $title=preg_replace_callback('/\(([^()]*)\)|\[([^\[\]]*)\]/u',static function($match){
            $tag=trim(($match[1] ?? '')!==''?$match[1]:($match[2] ?? ''));
            $metadata='/^(?:!?|(?:u|us|usa|e|eu|eur|europe|j|jp|jpn|japan|w|world|ue|ju|jue|ej|uk|australia|brazil|korea|china|asia|canada|france|germany|spain|italy)(?:\s*[,+&-]\s*(?:u|us|usa|e|eu|eur|europe|j|jp|jpn|japan|w|world|ue|ju|jue|ej|uk|australia|brazil|korea|china|asia|canada|france|germany|spain|italy))*|(?:rev(?:ision)?|ver(?:sion)?|v|disc|disk|side|track|alt|beta|proto(?:type)?|sample|demo|unl|pirate|hack|h|b|f|o|t|a|p|m|c|t[+-])(?:[ ._-]*[a-z0-9!+.-]+)*|(?:en|fr|de|es|it|pt|ja|jp|ko|zh|nl|sv|no|da|fi|ru)(?:\s*,\s*(?:en|fr|de|es|it|pt|ja|jp|ko|zh|nl|sv|no|da|fi|ru))*|[0-9a-f]{8})$/i';
            return preg_match($metadata,$tag)?' ':$match[0];
        },(string)$title);
        return trim((string)preg_replace('/\s+/u',' ',str_replace('_',' ',(string)$title)));
    }
    public static function key(string $title): string {
        $title=mb_strtolower(self::cleanTitle($title));$title=preg_replace('/\s*\((?:[0-9]{4}\s+)?(?:8-bit |16-bit )?(?:video game|game|arcade game)\)$/i','',$title);
        if(preg_match('/^(.+),\s*(the|a|an)$/i',$title,$m))$title=$m[2].' '.$m[1];
        $title=preg_replace('/\b(?:the|and)\b/u',' ',str_replace('&',' and ',$title));
        $title=preg_replace('/[^\p{L}\p{N}]+/u','',(string)$title);
        // Common short ROM labels refer to the same numbered Sonic game, never another sequel.
        $title=preg_replace('/^sonic(?:hedgehog)?([123])$/','sonic$1',(string)$title);
        return (string)$title;
    }
    public static function queryTitle(string $title): string {
        $title=self::cleanTitle($title);if(preg_match('/^Sonic\s*([123])$/i',$title,$m))return 'Sonic the Hedgehog '.$m[1];return $title;
    }
    public static function regions(string $title): array {
        $out=[];preg_match_all('/\(([^()]*)\)|\[([^\[\]]*)\]/',$title,$matches,PREG_SET_ORDER);$groups=[1=>[]];foreach($matches as $match)$groups[1][]=($match[1] ?? '')!==''?$match[1]:($match[2] ?? '');
        foreach($groups[1] as $group)foreach(preg_split('/[,+&\s]+/',mb_strtolower($group)) as $tag){$map=['u'=>'USA','us'=>'USA','usa'=>'USA','e'=>'Europe','eu'=>'Europe','eur'=>'Europe','europe'=>'Europe','j'=>'Japan','jp'=>'Japan','jpn'=>'Japan','japan'=>'Japan','w'=>'World','world'=>'World'];if(isset($map[$tag]))$out[]=$map[$tag];}
        return array_values(array_unique($out));
    }
    public static function libretroEnabled(): bool {return (Store::settings()['posters']['libretro_enabled'] ?? true)!==false;}
    private static function index(string $system): array {
        if(isset(self::$indexes[$system]))return self::$indexes[$system];$cacheKey='libretro-boxart-index-v2:'.$system;$cache=\Vaultcast\Repository::cacheGet($cacheKey);
        if(is_array($cache) && ($cache['refresh_at'] ?? 0)>time())return self::$indexes[$system]=$cache['names'] ?? [];
        $platform=str_replace('_',' ',$system);$url='https://thumbnails.libretro.com/'.rawurlencode($platform).'/Named_Boxarts/';$html=Posters::gameMetadataRequest($url);
        if($html===null || strlen($html)>8388608){return self::$indexes[$system]=$cache['names'] ?? [];}
        preg_match_all('/href=["\']([^"\']+\.png)["\']/i',$html,$matches);$names=[];
        foreach(array_slice($matches[1],0,30000) as $encoded){$name=rawurldecode(html_entity_decode($encoded,ENT_QUOTES|ENT_HTML5,'UTF-8'));if(strpbrk($name,"/\\")!==false||strlen($name)>500)continue;$key=self::key(substr($name,0,-4));if($key!=='')$names[$key][]=$name;}
        if($names)\Vaultcast\Repository::cachePut($cacheKey,['names'=>$names,'refresh_at'=>time()+604800],1209600);
        return self::$indexes[$system]=$names;
    }
    public static function boxarts(array $item): array {
        if(!self::libretroEnabled())return [];$system=Posters::libretroSystem(strtolower((string)($item['emu_core'] ?? $item['core'] ?? '')),strtolower((string)($item['system'] ?? '')));if(!$system)return [];
        $title=(string)($item['title'] ?? '');$raw=pathinfo(str_replace('\\','/',(string)($item['path'] ?? $item['rel'] ?? '')),PATHINFO_FILENAME);$index=self::index($system);$names=$index[self::key($title)] ?? $index[self::key($raw)] ?? [];$regions=self::regions($raw.' '.$title);
        usort($names,static function($a,$b)use($regions,$title,$raw){$rank=static function($name)use($regions,$title,$raw){$stem=substr($name,0,-4);$score=strcasecmp($stem,$title)===0||strcasecmp($stem,$raw)===0?100:0;$candidate=self::regions($stem);$score+=count(array_intersect($regions,$candidate))*20;if(!$regions){if(in_array('World',$candidate,true))$score+=8;if(in_array('USA',$candidate,true))$score+=7;}if(preg_match('/\((?:Beta|Proto|Demo|Sample)/i',$stem)&&!preg_match('/\((?:Beta|Proto|Demo|Sample)/i',$title.' '.$raw))$score-=30;return $score;};return ($rank($b)<=>$rank($a))?:strcmp($a,$b);});
        if($names)\Vaultcast\Repository::cachePut(self::identityKey($item),['title'=>self::cleanTitle(substr($names[0],0,-4)),'system'=>str_replace('_',' ',$system),'platform'=>$system,'filename'=>$names[0]],604800);
        $out=[];foreach(array_slice($names,0,3) as $name){$url='https://thumbnails.libretro.com/'.rawurlencode(str_replace('_',' ',$system)).'/Named_Boxarts/'.rawurlencode($name);$out[]=['kind'=>'game','source'=>'libretro','source_label'=>'Libretro','id'=>$system.':'.$name,'title'=>self::cleanTitle(substr($name,0,-4)),'image'=>$url,'url'=>$url,'summary'=>'','year'=>null];}return $out;
    }
    private static function identityKey(array $item): string {
        return 'game-identity-v2:'.hash('sha256',json_encode([$item['title'] ?? '',$item['system'] ?? '',$item['emu_core'] ?? '',basename(str_replace('\\','/',(string)($item['path'] ?? $item['rel'] ?? '')))]));
    }
    public static function identity(array $item): array {
        $pin=!empty($item['id'])?Posters::moviePosterPin((string)$item['id']):null;
        if(($pin['provider'] ?? '')==='libretro'&&strpos((string)($pin['provider_id'] ?? ''),':')!==false){[$platform,$file]=explode(':',$pin['provider_id'],2);if(preg_match('/^[A-Za-z0-9_-]+$/D',$platform)&&substr($file,-4)==='.png'&&strpbrk($file,"/\\")===false)return ['title'=>self::cleanTitle(substr($file,0,-4)),'system'=>str_replace('_',' ',$platform),'platform'=>$platform,'filename'=>$file];}
        if(self::libretroEnabled()){$identity=\Vaultcast\Repository::cacheGet(self::identityKey($item));if($identity!==null)return $identity;self::boxarts($item);$identity=\Vaultcast\Repository::cacheGet(self::identityKey($item));if($identity!==null)return $identity;}
        return ['title'=>self::queryTitle((string)($item['title'] ?? '')),'system'=>(string)($item['system'] ?? ''),'platform'=>Posters::libretroSystem(strtolower((string)($item['emu_core'] ?? $item['core'] ?? '')),strtolower((string)($item['system'] ?? '')))];
    }
    public static function automaticWikipedia(array $item): ?array {
        $identity=self::identity($item);$expected=self::key($identity['title']);
        foreach(self::wikipedia($item) as $row)if(self::key($row['page_title'] ?? $row['title'])===$expected)return $row;
        return null;
    }
    public static function wikipedia(array $item): array {
        $identity=self::identity($item);$title=self::queryTitle($identity['title']);if($title==='')return [];$system=$identity['system'];$key='game-wikipedia-v2:'.hash('sha256',self::key($title).'|'.$system);$cached=\Vaultcast\Repository::cacheGet($key);if($cached!==null)return $cached['rows'] ?? [];
        $url='https://en.wikipedia.org/w/api.php?'.http_build_query(['action'=>'query','format'=>'json','formatversion'=>2,'generator'=>'search','gsrsearch'=>$title.' video game','gsrlimit'=>8,'gsrnamespace'=>0,'prop'=>'pageimages|extracts','piprop'=>'original|thumbnail','pithumbsize'=>512,'pilicense'=>'any','exintro'=>1,'explaintext'=>1,'exchars'=>1800]);$raw=Posters::gameMetadataRequest($url);if($raw===null)return [];$data=json_decode($raw,true);if(!is_array($data))return [];$rows=[];
        foreach($data['query']['pages'] ?? [] as $page){$name=(string)($page['title'] ?? '');$summary=(string)($page['extract'] ?? '');$intro=(string)($page['description'] ?? '').' '.mb_substr($summary,0,450);
            if(preg_match('/\((?:film|soundtrack|album)\)$/i',$name)||!preg_match('/\b(?:video game|platform game|platformer|arcade game|racing game|role-playing game|fighting game|video games)\b/i',$intro))continue;
            $score=self::key($name)===self::key($title)?100:Matching::titleScore($title,(string)preg_replace('/\s*\([^)]*\)$/','',$name));
            $lib=$identity['platform'];if($lib==='Sega_-_Mega_Drive_-_Genesis'&&preg_match('/8-bit video game/i',$name))continue;$platform=['Sega_-_Mega_Drive_-_Genesis'=>['mega drive','genesis'],'Sega_-_Master_System_-_Mark_III'=>['master system'],'Sega_-_Game_Gear'=>['game gear'],'Nintendo_-_Super_Nintendo_Entertainment_System'=>['super nintendo','snes'],'Nintendo_-_Nintendo_Entertainment_System'=>['nintendo entertainment system'],'Sony_-_PlayStation'=>['playstation']];foreach($platform[$lib] ?? [] as $word)if(stripos($summary,$word)!==false){$score+=12;break;}
            if($score<75)continue;$rows[]=['kind'=>'game','source'=>'wikipedia','source_label'=>'Wikipedia','id'=>$page['pageid'],'title'=>(string)preg_replace('/\s*\([^)]*\)$/','',$name),'page_title'=>$name,'summary'=>$summary,'image'=>$page['original']['source'] ?? $page['thumbnail']['source'] ?? null,'url'=>'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ','_',$name)),'year'=>null,'match_score'=>$score];
        }
        usort($rows,static function($a,$b){return $b['match_score']<=>$a['match_score'];});$rows=array_slice($rows,0,8);\Vaultcast\Repository::cachePut($key,['rows'=>$rows],$rows?604800:3600);return $rows;
    }
}
