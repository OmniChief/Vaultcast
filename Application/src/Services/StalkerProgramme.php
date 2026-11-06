<?php
declare(strict_types=1);

/** Presentation metadata only; never copy transport/authentication fields. */
final class StalkerProgramme {
    public static function text($value,int $limit=1200): string {
        if(!is_scalar($value))return '';
        return mb_substr(trim(html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8')),0,$limit);
    }
    public static function artwork($value): string {
        $url=self::text($value,501);
        // Public, token-free artwork only. No proxy/download requests from the NAS.
        if(strlen($url)>500||!preg_match('~^https://[a-z0-9.-]+(?::443)?/[a-z0-9_./%+@-]+$~iD',$url))return '';
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        if($host==='localhost'||substr($host,-6)==='.local'||strpos($host,'.')===false)return '';
        if(filter_var($host,FILTER_VALIDATE_IP)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return '';
        return $url;
    }
    public static function names($value): array {
        if(!is_array($value))$value=preg_split('/\s*[,;|]\s*/u',self::text($value));
        $out=[];
        foreach(array_slice($value,0,20) as $entry){
            if(is_array($entry))$entry=$entry['name']??'';
            $text=self::text($entry,120);if($text!=='')$out[]=$text;
        }
        return array_values(array_unique($out));
    }
    public static function provider(array $row): array {
        $out=[];
        foreach(['cast'=>['actors','cast'],'director'=>['director'],'presenter'=>['presenter'],'categories'=>['categories','genre','category']] as $field=>$keys){
            foreach($keys as $key){$value=self::names($row[$key]??[]);if($value){$out[$field]=$value;break;}}
        }
        foreach(['rating'=>['rating_mpaa','age'],'episode'=>['episode_title','episode'],'language'=>['language'],'description'=>['description','descr','plot','summary']] as $field=>$keys){
            foreach($keys as $key){$value=self::text($row[$key]??'', $field==='description'?5000:160);if($value!==''){$out[$field]=$value;break;}}
        }
        foreach(['poster','icon','image','screenshot_uri'] as $key){$url=self::artwork($row[$key]??'');if($url!==''){$out['poster']=$url;break;}}
        return $out;
    }
    public static function xml($xml): array {
        $out=[];$categories=[];
        foreach($xml->category as $category){$text=self::text((string)$category,100);if($text!=='')$categories[]=$text;}
        if($categories)$out['categories']=array_slice(array_unique($categories),0,8);
        foreach(['actor'=>'cast','director'=>'director','presenter'=>'presenter'] as $tag=>$key){
            $values=[];foreach($xml->credits->{$tag}??[] as $person)$values[]=(string)$person;
            if($values)$out[$key]=self::names($values);
        }
        $rating=self::text((string)($xml->rating->value??''),40);
        if($rating!==''){$out['rating']=$rating;$out['ratingSystem']=self::text((string)($xml->rating['system']??''),40);}
        $subtitle=self::text((string)($xml->{'sub-title'}??''),180);
        if($subtitle!=='')$out['episode']=$subtitle;
        foreach($xml->{'episode-num'} as $number){
            if((string)$number['system']!=='xmltv_ns')continue;
            $parts=explode('.',trim((string)$number));
            $season=trim(explode('/',$parts[0]??'')[0]);$episode=trim(explode('/',$parts[1]??'')[0]);
            $label=($season!==''&&ctype_digit($season)?'S'.((int)$season+1):'').($episode!==''&&ctype_digit($episode)?' E'.((int)$episode+1):'');
            if(trim($label)!=='')$out['episode']=trim($label).($subtitle!==''?' · '.$subtitle:'');break;
        }
        $language=self::text((string)($xml->language??''),60);if($language!=='')$out['language']=$language;
        if(isset($xml->subtitles))$out['subtitles']=true;
        $poster=self::artwork((string)($xml->icon['src']??''));if($poster!=='')$out['poster']=$poster;
        return $out;
    }
}
