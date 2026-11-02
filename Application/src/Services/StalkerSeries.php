<?php
declare(strict_types=1);

/** Show presentation metadata, separate from provider commands and playback IDs. */
final class StalkerSeries {
    private static function key(array $feed,string $id): string {return 'stalker_show_v1_'.hash('sha256',json_encode([$feed,$id,StalkerCatalogues::active($feed,'series')]));}
    public static function stored(array $feed,string $id): ?array {
        $item=\Vaultcast\Repository::cacheGet(self::key($feed,$id));
        if(!$item){$item=StalkerVod::entry($feed,$id,'series');if($item)self::remember($feed,$id,$item);}
        return $item;
    }
    public static function remember(array $feed,string $id,array $item): void {\Vaultcast\Repository::cachePut(self::key($feed,$id),$item,86400);}
    public static function metadata(array $feed,array $row,array $parent=[]): array {
        // Keep alternate artwork locations so a missing poster can be retried on demand.
        $fields=['screenshot_uri','cover','poster','movie_image','pic','image','icon','logo','cover_big','cover_url','poster_url','image_url','screenshot','thumbnail','thumb'];
        $locations=[];$seen=[];
        require_once __DIR__.'/../Live/iptv_hls.php';
        foreach ([$row,is_array($row['info']??null)?$row['info']:[],is_array($row['movie_info']??null)?$row['movie_info']:[]] as $section) {
            foreach($fields as $field){
                $raw=$section[$field]??null;
                if(!is_scalar($raw)||trim((string)$raw)==='')continue;
                $candidate=iptv_hls_resolve_uri(stalker_endpoint($feed),trim((string)$raw));
                $parts=parse_url($candidate);
                // TMDB image base URLs without a poster path are not actual artwork.
                if(preg_match('~^https?://image\\.tmdb\\.org/t/p/[^/?#]+/?(?:[?#].*)?$~i',$candidate))continue;
                if(!$parts||!in_array(strtolower($parts['scheme']??''),['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||preg_match('/[\r\n]/',$candidate)||strlen($candidate)>2048||isset($seen[$candidate]))continue;
                $seen[$candidate]=true;$locations[]=['field'=>$field,'url'=>$candidate];
                if(count($locations)>=8)break 2;
            }
        }
        $poster=$locations[0]['url']??'';
        $description='';
        foreach([$row,is_array($row['info']??null)?$row['info']:[],is_array($row['movie_info']??null)?$row['movie_info']:[]] as $section){
            foreach(['description','descr','plot','summary','overview'] as $field){
                if(!is_scalar($section[$field]??null))continue;
                $value=trim(html_entity_decode(strip_tags((string)$section[$field]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                if($value===''||preg_match('/^(?:n\\/?a|none|null|unknown|no description)$/i',$value))continue;
                $description=$value;break 2;
            }
        }
        $original=(string)($row['name']??$row['o_name']??$row['id']??'Provider show');
        $display=preg_replace('/(?<=\\p{L}|\\p{N})\\.(?=\\p{L}|\\p{N})/u',' ',$original);
        $display=trim(preg_replace('/\\s+/u',' ',$display));
        $year='';
        foreach(['year','movie_year','release_date','premiered'] as $field){
            if(preg_match('/^((?:19|20)\\d{2})(?:$|[-\\/])/D',(string)($row[$field]??''),$m)){$year=$m[1];break;}
        }
        if($year===''&&preg_match('/(?:^|[\\s.(])((?:19|20)\\d{2})(?:[\\s.)]|$)/',$original,$m))$year=$m[1];
        return ['name'=>$display?:$original,'poster'=>$poster?:($parent['poster']??''),'posterCandidates'=>$locations,'description'=>mb_substr($description?:($parent['description']??''),0,5000),'year'=>$year?:($parent['year']??'')];
    }
}
