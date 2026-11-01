<?php
declare(strict_types=1);

/**
 * Provider-native Live TV discovery: one small genres call per connection, followed
 * by one paginated channel request only after the viewer selects a folder.
 * No catalogue tables, background jobs, or poster storage.
 */
final class StalkerLiveBrowse {
    private static function key(array $feed,string $suffix): string {
        return 'stalker_live_direct_v1_'.hash('sha256',json_encode([$feed,$suffix]));
    }
    public static function categories(array $feed): array {
        $key=self::key($feed,'genres');
        $cached=\Vaultcast\Repository::cacheGet($key);
        if($cached!==null)return ['ok'=>true,'categories'=>$cached];
        $result=stalker_call($feed,'get_genres',[],'itv');
        $rows=$result['data']??$result;
        if(!is_array($rows))throw new RuntimeException('Provider returned invalid Live TV categories.');
        $categories=[];
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id']))continue;
            $id=(string)$row['id'];if($id===''||strlen($id)>120)continue;
            $title=mb_substr(trim((string)($row['title']??$row['name']??$id)),0,160);
            $categories[]=['id'=>$id,'name'=>$title,'country'=>StalkerCountries::country($title)??'ZZ'];
            if(count($categories)>=1000)break;
        }
        \Vaultcast\Repository::cachePut($key,$categories,$categories?3600:180);
        return ['ok'=>true,'categories'=>$categories];
    }
    public static function channels(array $feed,array $opts): array {
        $category=mb_substr((string)($opts['category']??''),0,120);
        if($category==='')throw new InvalidArgumentException('Choose a provider category.');
        // Never permit arbitrary category IDs as a way to trigger cross-provider scans.
        $categories=self::categories($feed)['categories'];
        $selected=null;
        foreach($categories as $candidate)if($candidate['id']===$category){$selected=$candidate;break;}
        if($selected===null)throw new InvalidArgumentException('Unknown provider category.');
        $page=max(1,min(10000,(int)($opts['page']??1)));
        $key=self::key($feed,'channels:'.json_encode([$category,$page]));
        $cached=\Vaultcast\Repository::cacheGet($key);
        if($cached!==null)return $cached;
        $result=stalker_ordered_page($feed,'itv',['sortby'=>'number','genre'=>$category,'search'=>'','fav'=>0],$page);
        $rows=$result['data']??$result;
        if(!is_array($rows))throw new RuntimeException('Provider returned invalid channels.');
        $items=[];
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id'])||empty($row['cmd']))continue;
            $id=(string)$row['id'];if($id===''||strlen($id)>120)continue;
            $name=mb_substr((string)($row['name']??$id),0,300);
            $playback=stalker_live_entry($row);
            \Vaultcast\Repository::cachePut(self::key($feed,'item:'.$id),$playback,7200);
            $items[]=['id'=>$id,'name'=>$name,'description'=>$selected['name'],'feed'=>$feed['id'],
                'connection'=>$feed['name'],'kind'=>'live','country'=>StalkerCountries::country($name)??$selected['country']];
        }
        $count=count($rows);$perPage=max(1,(int)($result['max_page_items']??$count));
        $total=isset($result['total_items'])&&is_numeric($result['total_items'])?max(0,(int)$result['total_items']):null;
        $out=['ok'=>true,'items'=>$items,'page'=>$page,'total'=>$total,
            'hasMore'=>$total!==null?$page*$perPage<$total:$count>0&&$count>=$perPage];
        \Vaultcast\Repository::cachePut($key,$out,600);
        return $out;
    }
    public static function entry(array $feed,string $id): ?array {
        $playback=\Vaultcast\Repository::cacheGet(self::key($feed,'item:'.$id));
        return $playback===null?null:['playback'=>$playback];
    }
}
