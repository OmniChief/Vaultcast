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
        $art='';foreach(['screenshot_uri','cover','poster','movie_image','pic'] as $field)if(is_scalar($row[$field]??null)&&trim((string)$row[$field])!==''){$art=trim((string)$row[$field]);break;}
        $poster='';
        if($art!==''){
            require_once __DIR__.'/../Live/iptv_hls.php';$candidate=iptv_hls_resolve_uri(stalker_endpoint($feed),$art);$parts=parse_url($candidate);
            if($parts&&in_array(strtolower($parts['scheme']??''),['http','https'],true)&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&!preg_match('/[\r\n]/',$candidate))$poster=$candidate;
        }
        $description='';foreach(['description','descr','plot','summary'] as $field)if(is_scalar($row[$field]??null)&&trim((string)$row[$field])!==''){$description=trim(html_entity_decode(strip_tags((string)$row[$field]),ENT_QUOTES|ENT_HTML5,'UTF-8'));break;}
        return ['name'=>(string)($row['name']??$row['o_name']??$row['id']??'Provider show'),'poster'=>$poster?:($parent['poster']??''),'description'=>mb_substr($description?:($parent['description']??''),0,5000),'year'=>preg_match('/^(?:19|20)\d{2}$/D',(string)($row['year']??''))?(string)$row['year']:($parent['year']??'')];
    }
}
