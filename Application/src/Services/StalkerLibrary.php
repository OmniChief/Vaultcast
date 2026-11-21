<?php
declare(strict_types=1);

/** Update the local-media match index when an item is written by the library. */
final class StalkerLibrary {
    private static function db(): PDO { return \Vaultcast\Database::connection(); }
    private static function query(string $sql,array $args=[]): PDOStatement {$q=self::db()->prepare($sql);$q->execute($args);return $q;}
    private static function text($v): string {return is_scalar($v)?trim(html_entity_decode(strip_tags((string)$v),ENT_QUOTES|ENT_HTML5,'UTF-8')):'';}
    public static function displayTitle(string $s): string {
        return trim(preg_replace('/^(?:(?:4k|uhd|fhd|hd|sd|2160p|1080p|720p|en|eng|uk|us|fr|de|es|it|nl|ru|pt|ar|hi|tr|gr|el|nf|netflix|amz|amazon|hbo|max)\s*[-|:]\s*)+/iu','',self::text($s)));
    }
    public static function title(string $s): string {
        $s=mb_strtolower(self::displayTitle($s));
        $s=preg_replace('/\s*[\[(](?:(?:19|20)\d{2}|4k|uhd|fhd|hd|sd|1080p|720p|2160p)[\])]\s*/iu',' ',$s);
        $s=preg_replace('/\s+(?:4k|uhd|fhd|1080p|720p|2160p)$/iu','',$s);
        $s=preg_replace('/\s+(?:19|20)\d{2}$/u','',$s);
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$s));
    }
    private static function ids(array $row): array {
        $imdb=self::text($row['imdb_id']??$row['imdb']??'');$tmdb=self::text($row['tmdb_id']??$row['tmdb']??'');
        if(preg_match('/^\d{1,12}$/D',$imdb)&&((int)$imdb)>0)$imdb='tt'.str_pad($imdb,7,'0',STR_PAD_LEFT);
        return [preg_match('/^(?:https?:\/\/(?:www\.)?imdb\.com\/title\/)?(tt\d{5,12})\/?$/i',$imdb,$m)?strtolower($m[1]):'',preg_match('/^[1-9]\d{0,11}$/D',$tmdb)?$tmdb:''];
    }
    public static function syncLocal(array $item): void {
        if(empty($item['id']))return;if(($item['media_kind']??'')!=='video'){self::query('DELETE FROM vaultcast_stalker_local WHERE item_id=?',[$item['id']]);return;}
        $show=self::text($item['show']??$item['show_title']??'');$kind=$show!==''?'series':'vod';
        [$imdb,$tmdb]=self::ids($item);$year=(int)($item['year']??0);if($year<1900||$year>2099)$year=null;
        self::query('INSERT INTO vaultcast_stalker_local(item_id,kind,title_key,year,imdb_id,tmdb_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),title_key=VALUES(title_key),year=VALUES(year),imdb_id=VALUES(imdb_id),tmdb_id=VALUES(tmdb_id)',[$item['id'],$kind,hash('sha256',self::title($show!==''?$show:($item['title']??''))),$year,$imdb,$tmdb]);
    }
}
