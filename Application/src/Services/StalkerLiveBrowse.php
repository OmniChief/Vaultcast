<?php
declare(strict_types=1);

/** Live TV snapshots are fetched by the catalogue worker and read locally by viewers. */
final class StalkerLiveBrowse {
    private static function key(array $feed,string $suffix): string {
        return 'stalker_live_snapshot_'.hash('sha256',json_encode([StalkerCatalogues::config($feed),$suffix]));
    }
    public static function snapshot(array $feed): ?array {
        if(StalkerCatalogues::expired($feed))return null;
        return \Vaultcast\Repository::stalkerSnapshot(self::key($feed,'imported-live'));
    }
    public static function lineupGuide(array $feed,?array $ids): ?array {
        if(StalkerCatalogues::expired($feed))return [];
        $snapshot=\Vaultcast\Repository::stalkerSnapshotFile(self::key($feed,'imported-live'));
        if(!is_file($snapshot))return [];
        $node=Process::nodeBinary();
        if($node===null||!function_exists('proc_open'))return null;
        $exchange=tempnam(STREAM_SHM,'lineup_');
        if($exchange===false)return null;
        try{
            if(file_put_contents($exchange,json_encode($ids))===false)return null;
            $worker=STREAM_ROOT.'/src/NodeApp/stalker-lineup-guide.js';
            if(Process::capture([$node,$worker,$snapshot,$feed['id'],$exchange,date_default_timezone_get()],30)!=="indexed\n")return null;
            $result=json_decode((string)file_get_contents($exchange),true);
            return is_array($result)?$result:null;
        }finally{@unlink($exchange);}
    }
    /** Publish imported Stalker channels to the shared IPTV lineup. */
    public static function lineupIndex(array $feed): array {
        $data=self::snapshot($feed);
        $channels=[];
        if($data!==null){
            $groups=array_column($data['categories']??[],'name','id');
            foreach($data['channels']??[] as $row){
                $id=(string)$row['id'];
                $channels[]=['id'=>'stalker-'.substr(hash('sha256',$feed['id'].':'.$id),0,20),
                    'name'=>$row['name']??$id,'group'=>$groups[$row['tv_genre_id']??'']??'',
                    'url'=>stalker_channel_url($feed['id'],$id),'tvgId'=>$row['xmltv_id']??$id];
            }
        }elseif(!StalkerCatalogues::expired($feed)){
            $query=\Vaultcast\Database::connection()->prepare("SELECT item_id,title,category FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind='live' AND parent_id='' ORDER BY title,item_id");
            $query->execute([StalkerCatalogues::active($feed,'live')]);
            while($row=$query->fetch()){
                $id=(string)$row['item_id'];
                $channels[]=['id'=>'stalker-'.substr(hash('sha256',$feed['id'].':'.$id),0,20),
                    'name'=>$row['title'],'group'=>$row['category'],'url'=>stalker_channel_url($feed['id'],$id),'tvgId'=>$id];
            }
        }
        return ['channels'=>$channels,'epgUrls'=>[]];
    }

    private static function epgMatchName(string $name): string {
        $name=trim(html_entity_decode(strip_tags($name),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        for($pass=0;$pass<5;$pass++){
            $name=preg_replace('/^\\s*(?:[|┃│¦]\\s*(?:UK|GB|US|USA)\\s*[|┃│¦]|\\[\\s*(?:UK|GB|US|USA)\\s*\\]|(?:UK|GB|US|USA)\\s*[-:])\\s*/iu','',$name);
            $name=preg_replace('/(?:\\s+|\\s*[-|]\\s*|\\s*\\[)(?:UHD|FHD|FULL\\s*HD|HD|SD|4K|2160P|1080P|720P|HEVC|H\\.?265|H\\.?264)\\]?\\s*$/iu','',$name);
        }
        // XMLTV UK feeds append .uk to channel labels; it is a country marker,
        // not part of a service name such as BBC One.
        $name=preg_replace('/\\.uk$/iu','',$name);
        $name=mb_strtolower(trim(preg_replace('/\\s+/u',' ',$name)));
        // These broadcaster-specific spelling variants are unambiguous.
        $name=preg_replace('/^bbc\\s+(?:1|one)(?=\\s|$)/u','bbc one',$name);
        $name=preg_replace('/^bbc\\s+(?:2|two)(?=\\s|$)/u','bbc two',$name);
        $name=preg_replace('/^bbc\\s+(?:3|three)(?=\\s|$)/u','bbc three',$name);
        $name=preg_replace('/^bbc\\s+(?:4|four)(?=\\s|$)/u','bbc four',$name);
        $name=preg_replace('/^itv\\s+([1-4])(?=\\s|$)/u','itv$1',$name);
        $name=preg_replace('/^4\\s*seven(?=\\s|$)/u','4seven',$name);
        $name=preg_replace('/^(?:u\\s*&\\s*)?alibi(?=\\s|$)/u','alibi',$name);
        $name=preg_replace('/^(?:channel\\s*5|five)(?=\\s|$)/u','5',$name);
        $name=preg_replace('/^more\\s*4(?=\\s|$)/u','more4',$name);
        $name=preg_replace('/^film\\s*4(?=\\s|$)/u','film4',$name);
        return preg_replace('/\\s*\\+\\s*1\\b/u',' +1',$name);
    }
    /** Collapse ITV1 regional labels only for the consensus schedule fallback. */
    private static function epgRegionalRoot(string $name): string {
        $timeshift=preg_match('/\\s+\\+1$/u',$name)?' +1':'';
        $base=$timeshift!==''?preg_replace('/\\s+\\+1$/u','',$name):$name;
        if(preg_match('/^itv1(?:\\s+.+)?$/u',$base))return 'itv1'.$timeshift;
        return $name;
    }

    private static function guideNameMaps(array $channels, array $programmes): array {
        $names=[];
        $regional=[];
        foreach($channels as $id=>$label){
            if(!isset($programmes[$id]))continue;
            $name=self::epgMatchName((string)$label);
            if($name==='')continue;
            $names[$name][$id]=(string)$label;
            $root=self::epgRegionalRoot($name);
            if($root!==$name)$regional[$root][$id]=(string)$label;
        }
        return [$names,$regional];
    }

    /** Publish the channel lookup beside the full index, which also serves older app versions. */
    public static function writeGuideLookup(string $path,int $updatedAt,array $channels,array $logos,array $programmes): void {
        $target=$path.'.lookup';
        $temporary=$target.'.'.bin2hex(random_bytes(4)).'.tmp';
        $file=@fopen($temporary,'xb');
        if(!$file)return;
        $published=false;
        try{
            $offsets=[];
            foreach($programmes as $id=>$entries){
                $offsets[$id]=ftell($file);
                $line=json_encode($entries,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                if($line===false)return;
                $line.="\n";
                if(fwrite($file,$line)!==strlen($line))return;
            }
            $headerStart=ftell($file);
            [$names,$regional]=self::guideNameMaps($channels,$programmes);
            $header=json_encode(['updatedAt'=>$updatedAt,'offsets'=>$offsets,'logos'=>$logos,'names'=>$names,'regional'=>$regional]);
            if($header===false || strlen($header)>2*1024*1024)return;
            $trailer=$header.sprintf('%012d',$headerStart);
            if(fwrite($file,$trailer)!==strlen($trailer) || !fflush($file))return;
            fclose($file);
            $published=@rename($temporary,$target);
        }finally{
            if(is_resource($file))fclose($file);
            if(!$published)@unlink($temporary);
        }
    }

}
