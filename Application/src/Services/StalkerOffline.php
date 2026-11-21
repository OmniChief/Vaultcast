<?php
declare(strict_types=1);

final class StalkerOffline {
    private static function root(): string { $p=STREAM_DATA.'/stalker-offline';if(!is_dir($p)&&!@mkdir($p,0700,true))throw new RuntimeException('Cannot create offline storage.');return $p; }
    private static function job(string $id): array {if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new InvalidArgumentException('Invalid offline download.');$j=\Vaultcast\Repository::document('stalker-offline:'.$id,[]);if(!$j)throw new RuntimeException('Offline download not found.');return $j;}
    private static function save(array $j): void {if(is_file(self::root().'/'.$j['id'].'.delete'))return;\Vaultcast\Repository::putDocument('stalker-offline:'.$j['id'],$j);}
    public static function listing(): array {
        $rows=\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");
        foreach($rows as &$row){
            if(!in_array($row['status']??'', ['queued','downloading'],true))continue;
            $dir=self::root().'/'.$row['id'];$bytes=0;
            foreach(glob($dir.'/*')?:[] as $file)if(is_file($file)&&preg_match('/^(?:source(?:\.part)?|segment-\d+(?:\.part)?|video(?:\.part)?\.mkv)$/',basename($file)))$bytes+=(int)filesize($file);
            $progressMeta=$dir.'/source.part.json';if(!is_file($progressMeta))$progressMeta=$dir.'/source.json';
            $meta=is_file($progressMeta)?json_decode((string)file_get_contents($progressMeta),true):[];
            $row['bytes']=max((int)($row['bytes']??0),$bytes,(int)($meta['downloaded_bytes']??0));
            $row['speedBytesPerSecond']=time()-(int)($meta['progress_at']??0)<=5?(int)($meta['speed_bytes_per_second']??0):0;
            $row['providerHttpStatus']=(int)($meta['http_status']??0);
            $row['percent']=null;$row['totalBytes']=0;
            if(!empty($row['is_hls'])&&(int)($row['segments_total']??0)>0)$row['percent']=(int)floor(min(1,(int)($row['segments_done']??0)/(int)$row['segments_total'])*100);
            else{
                $row['totalBytes']=(int)($meta['total_bytes']??0);if($row['totalBytes']>0)$row['percent']=(int)floor(min(1,$row['bytes']/$row['totalBytes'])*100);
            }
        }unset($row);
        usort($rows,static function($a,$b){return strcmp($b['created']??'',$a['created']??'');});
        return array_map(static function($j){
            $item=is_array($j['item']??null)?$j['item']:[];$season=$item['seasonName']??'';
            if($season===''&&is_numeric($item['season']??null))$season=(int)$item['season']===0?'Specials':'Season '.(int)$item['season'];
            return array_merge(array_intersect_key($j,array_flip(['id','name','status','bytes','error','created','updated','destination','stage','connection','delete_requested'])),[
                'percent'=>$j['status']==='ready'||$j['status']==='imported'?100:($j['percent']??null),'totalBytes'=>(int)($j['totalBytes']??0),'speedBytesPerSecond'=>(int)($j['speedBytesPerSecond']??0),'providerHttpStatus'=>(int)($j['providerHttpStatus']??0),'elapsedSeconds'=>$j['status']==='downloading'?max(0,time()-(int)(strtotime($j['created']??'now')?:time())):0,
                'showName'=>(string)($item['showName']??$item['seriesName']??''),'season'=>is_numeric($item['season']??null)?(int)$item['season']:null,'seasonName'=>(string)$season,
                'episode'=>(string)($item['episode']??$item['episode_number']??''),'episodeTitle'=>(string)($item['episodeTitle']??$item['episode_title']??'')
            ]);
        },$rows);
    }
    public static function torrents(): array {
        $rows=\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");$out=[];
        foreach($rows as $j)if(($j['destination']??'library')!=='browser'&&in_array($j['status'],['ready','imported'],true)&&preg_match('/^[a-f0-9]{24}$/D',$j['id'])){$dir=self::root().'/'.$j['id'];$file=$dir.'/video.mkv';if(is_file($file))$out[]=['stalker_offline'=>$j['id'],'hashString'=>'stalker-'.$j['id'],'id'=>0,'name'=>$j['name'],'percentDone'=>1,'downloadDir'=>$dir,'files'=>[['name'=>'video.mkv','length'=>filesize($file)]]];}
        return $out;
    }
    public static function files(array $tor): array {
        $id=(string)($tor['stalker_offline']??'');self::job($id);$path=self::root().'/'.$id.'/video.mkv';
        return is_file($path)?[['path'=>$path,'rel'=>'video.mkv','name'=>$tor['name'].'.mkv','ext'=>'mkv','size'=>filesize($path)]]:[];
    }
    public static function imported(array $tor): array {
        $j=self::job($tor['stalker_offline']);$j['status']='imported';self::save($j);
        $dir=self::root().'/'.$j['id'];
        // This is a flat, owned staging folder. Keep any completed copy that the
        // import left behind; only its now-unneeded download intermediates go.
        foreach(glob($dir.'/*')?:[] as $file)if(is_file($file)&&!is_link($file)&&basename($file)!=='video.mkv')@unlink($file);
        return ['ok'=>true,'removed'=>false,'left'=>0,'message'=>'Offline video imported into the library.'];
    }
}
