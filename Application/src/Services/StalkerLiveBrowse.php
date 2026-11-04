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
    public static function categories(array $feed,bool $refresh=false): array {
        $key=self::key($feed,'genres:v3');
        $cached=\Vaultcast\Repository::cacheGet($key);
        if($cached!==null&&!$refresh)return ['ok'=>true,'categories'=>$cached];
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
        if($cached!==null&&empty($opts['refresh'])){
            // Channel-page caching and playback-command caching have independent
            // lifetimes. Rehydrate a missing command when a cached page is used,
            // otherwise a visible channel can fail to resolve on selection.
            $ready=true;
            foreach($cached['items']??[] as $item){
                $id=(string)($item['id']??'');
                if($id!==''&&\Vaultcast\Repository::cacheGet(stalker_live_key($feed,$id))===null
                    &&\Vaultcast\Repository::cacheGet(self::key($feed,'item:'.$id))===null){
                    $ready=false;break;
                }
            }
            if($ready)return $cached;
        }
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
            // The compatibility player uses the same on-demand Stalker resolver.
            \Vaultcast\Repository::cachePut(stalker_live_key($feed,$id),$playback,7200);
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
    /** Inspect one provider Live TV page without exposing commands or URL tokens. */
    public static function fieldProbe(array $feed,array $opts): array {
        $requested=(string)($opts['category']??'');
        if($requested!==''&&!preg_match('/^[0-9]{1,12}$/D',$requested))
            throw new InvalidArgumentException('Choose a valid channel category.');
        $categories=self::categories($feed)['categories'];
        $selected=null;
        foreach($categories as $candidate){
            if($requested!==''&&(string)$candidate['id']!==$requested)continue;
            if(!preg_match('/^[0-9]{1,12}$/D',(string)$candidate['id']))continue;
            $selected=$candidate;break;
        }
        if($selected===null)throw new InvalidArgumentException('No usable Live TV category was found.');
        $result=stalker_ordered_page($feed,'itv',['sortby'=>'number','genre'=>$selected['id'],'search'=>'','fav'=>0],1);
        $rows=$result['data']??$result;
        if(!is_array($rows))throw new RuntimeException('Provider returned an invalid Live TV page.');
        $samples=['name','number','tv_genre_id','group_title','xmltv_id','epg_id',
            'epg','epg_enabled','has_archive','archive','archive_days','archive_range',
            'logo','icon','screenshot_uri','country','language','hd','quality',
            'aspect_ratio','description','tv_genre','genre','censored','is_favorite'];
        $inventory=[];$inspected=0;
        foreach($rows as $row){
            if(!is_array($row))continue;
            $inspect=function(array $section,string $prefix='') use (&$inventory,$samples): void {
                foreach($section as $key=>$value){
                    if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,49}$/i',$key))continue;
                    if(preg_match('/(?:token|auth|pass|secret|cookie|key|mac|serial|device|url|link|cmd|stream|ticket|host|ip|login|account|user|pin|drm|license|proxy|path|file|play)/i',$key))continue;
                    $field=$prefix.$key;
                    $type=is_array($value)?'array':(is_object($value)?'object':gettype($value));
                    if(!isset($inventory[$field]))$inventory[$field]=['type'=>$type,'present'=>0,'populated'=>0];
                    $inventory[$field]['present']++;
                    if($value!==null&&$value!==''&&$value!==[])$inventory[$field]['populated']++;
                    // Only descriptive text/identifiers. Never export provider URLs, logos or transport strings.
                    if(in_array($key,$samples,true)&&!in_array($key,['logo','icon','screenshot_uri','epg'],true)
                        &&is_scalar($value)&&!isset($inventory[$field]['example'])){
                        $sample=trim(html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                        if($sample!==''&&!preg_match('~(?:https?://|[a-z][a-z0-9+.-]*://|(?:token|auth|pass|session|cookie)[=:]|[?&][a-z_]+=)~i',$sample))
                            $inventory[$field]['example']=mb_substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$sample),0,120);
                    }
                }
            };
            $inspect($row);
            if(++$inspected>=15)break;
        }
        ksort($inventory);
        return ['ok'=>true,'connection'=>$feed['name'],'category'=>$selected['name'],
            'category_id'=>$selected['id'],'rows_inspected'=>$inspected,'fields'=>$inventory,
            'note'=>'One provider channel page inspected; credentials, commands and URLs excluded.'];
    }

    public static function entry(array $feed,string $id): ?array {
        $playback=\Vaultcast\Repository::cacheGet(self::key($feed,'item:'.$id));
        return $playback===null?null:['playback'=>$playback];
    }
}
