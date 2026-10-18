<?php
declare(strict_types=1);

final class PlaybackSequence {
    public static function next(array $user,string $id,string $collection=''): ?array {
        $item=Catalogue::item($id);
        if(!$item || AreaAccess::itemArea($item)!=='library' || !AreaAccess::allowed($user,'library') || ($item['media_kind'] ?? '')!=='video')return null;
        if($collection!==''){
            if(!Collections::get($collection))return null;
            $parts=Collections::status($collection)['parts'];$found=false;
            foreach($parts as $part){
                $candidate=(string)($part['item_id'] ?? $part['item']['id'] ?? '');
                if(!$found){if($candidate===$id)$found=true;continue;}
                if(!$candidate || $candidate===$id || empty($part['present']))continue;
                $next=Catalogue::item($candidate);
                if(self::playable($next,$user) && empty($next['show']))return Catalogue::publicItem($next,false);
            }
            return null;
        }
        if(empty($item['show']) || !isset($item['season'],$item['episode']))return null;
        $season=(int)$item['season'];$episode=(int)$item['episode'];$pdo=\Vaultcast\Database::connection();
        if(BrowseIndex::ready()){
            $q=$pdo->prepare("SELECT i.payload FROM vaultcast_browse_items b JOIN vaultcast_items i ON i.id=b.item_id WHERE b.show_key=? AND b.media_kind='video' AND (b.season>? OR (b.season=? AND b.episode>?)) ORDER BY b.season,b.episode,b.item_id LIMIT 32");
            $q->execute([Catalogue::showKey((string)$item['section'],(string)$item['show']),$season,$season,$episode]);
        }else{
            $s="CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.season')) AS UNSIGNED)";$e="CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.episode')) AS UNSIGNED)";
            $q=$pdo->prepare("SELECT payload FROM vaultcast_items WHERE section_id=? AND show_title=? AND ($s>? OR ($s=? AND $e>?)) ORDER BY $s,$e,id LIMIT 32");
            $q->execute([$item['section'],$item['show'],$season,$season,$episode]);
        }
        foreach($q as $row){$next=json_decode($row['payload'],true);if(self::playable($next,$user))return Catalogue::publicItem($next,false);}
        return null;
    }
    private static function playable(?array $item,array $user): bool {
        return $item && ($item['media_kind'] ?? '')==='video' && AreaAccess::itemArea($item)==='library' && AreaAccess::allowed($user,'library') && is_file((string)($item['path'] ?? ''));
    }
}
