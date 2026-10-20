<?php
declare(strict_types=1);

final class FriendHighlights {
    // Called only after the profile's friendship gate; results are filtered for the viewer.
    public static function build(array $viewer,array $person,array $their): array {
        $library=AreaAccess::allowed($viewer,'library');$gamesAllowed=AreaAccess::allowed($viewer,'games');
        $key='friend-highlights-v1:'.$person['id'].':'.($library?'1':'0').($gamesAllowed?'1':'0');
        $cached=\Vaultcast\Repository::cacheGet($key);
        if($cached!==null)return $cached;
        $trim=static function(array $rows):array{usort($rows,static function($a,$b){return ($b['count']<=>$a['count'])?:strcmp($a['title'],$b['title']);});return array_slice($rows,0,3);};
        $totals=['films'=>0,'episodes'=>0,'series'=>0,'viewings'=>0,'seconds'=>0];$movies=[];$shows=[];
        if($library){
            $q=\Vaultcast\Database::connection()->prepare("SELECT i.id,i.title,i.show_title,i.section_id,p.payload FROM vaultcast_progress p JOIN vaultcast_items i ON i.id=p.item_id WHERE p.user_id=? AND i.media_kind='video'");
            $q->execute([$person['id']]);
            while($row=$q->fetch(PDO::FETCH_ASSOC)){
                $p=json_decode($row['payload'],true)?:[];
                $count=max(0,(int)($p['viewings']??0));
                if(!$count && Catalogue::progressFinished($p,$row['show_title']!==''))$count=1;
                if(!$count)continue;
                $duration=max(0,(float)($p['duration']??0));$totals['seconds']+=(int)($duration*$count);$totals['viewings']+=$count;
                if($row['show_title']!==''){
                    $totals['episodes']++;$group=$row['section_id'].':'.$row['show_title'];
                    if(!isset($shows[$group]))$shows[$group]=['id'=>$row['id'],'title'=>$row['show_title'],'show_key'=>Catalogue::showKey($row['section_id'],$row['show_title']),'count'=>0,'episodes'=>0];
                    $shows[$group]['count']+=$count;$shows[$group]['episodes']++;
                }else{$totals['films']++;$movies[]=['id'=>$row['id'],'title'=>$row['title'],'count'=>$count];if(count($movies)>6)$movies=$trim($movies);}
            }
        }
        $totals['series']=count($shows);
        $rank=$trim;
        $decorate=static function(array $rows):array{
            foreach($rows as &$row){$item=Catalogue::item($row['id']);$row['card']=$item?Catalogue::publicItem($item,false):null;}unset($row);return $rows;
        };
        $games=$gamesAllowed?array_values($their['games']??[]):[];
        usort($games,static function($a,$b){return ($b['seconds']??0)<=>($a['seconds']??0);});
        $topGames=[];foreach(array_slice($games,0,3) as $game){if(empty($game['card']['id']))continue;$topGames[]=['card'=>$game['card'],'seconds'=>(int)($game['seconds']??0),'sessions'=>(int)($game['sessions']??0)];}
        $out=['viewing'=>$totals,'movies'=>$decorate($rank($movies)),'series'=>$decorate($rank(array_values($shows))),'games'=>$topGames];
        \Vaultcast\Repository::cachePut($key,$out,30);return $out;
    }
    public static function favourites(array $viewer,array $their):array {
        $mine=\Vaultcast\Repository::document('home-hub:'.$viewer['id'],[]);$shared=[];$favourites=[];
        foreach(array_reverse(array_keys($their['favourites']??[])) as $key){
            $card=$their['favourites'][$key]['card']??null;
            if(!is_array($card)||!AreaAccess::allowed($viewer,AreaAccess::itemArea($card)))continue;
            if(isset($mine['favourites'][$key]))$shared[]=$card;
            if(count($favourites)<6)$favourites[]=$card;
            if(count($shared)>=6&&count($favourites)>=6)break;
        }
        return ['favourites'=>$favourites,'shared'=>array_slice($shared,0,6)];
    }
}
