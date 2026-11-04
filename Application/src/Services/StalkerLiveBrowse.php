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
    /** Opt-in admin diagnostic: one paginated Live TV response, no playback resolution or guide crawl. */
    public static function fieldProbe(array $feed,array $opts): array {
        $requested=(string)($opts['category']??'');
        if($requested!==''&&!preg_match('/^[0-9]{1,12}$/D',$requested))
            throw new InvalidArgumentException('Choose a valid channel category.');
        $page=max(1,min(20,(int)($opts['page']??1)));
        $categories=self::categories($feed)['categories'];
        $selected=null;
        foreach($categories as $candidate){
            if($requested!==''&&(string)$candidate['id']!==$requested)continue;
            if(!preg_match('/^[0-9]{1,12}$/D',(string)$candidate['id']))continue;
            $selected=$candidate;break;
        }
        if($selected===null)throw new InvalidArgumentException('No usable Live TV category was found.');
        $result=stalker_ordered_page($feed,'itv',['sortby'=>'number','genre'=>$selected['id'],'search'=>'','fav'=>0],$page);
        $rows=$result['data']??$result;
        if(!is_array($rows))throw new RuntimeException('Provider returned an invalid Live TV page.');
        $examples=['name','number','tv_genre_id','group_title','xmltv_id','epg_id','epg_enabled',
            'has_archive','archive','archive_days','archive_range','tv_archive_duration',
            'enable_tv_archive','allow_pvr','allow_local_pvr','allow_remote_pvr',
            'allow_local_timeshift','logo','country','language','hd','quality',
            'aspect_ratio','description','tv_genre','genre','censored','is_favorite','status'];
        $numeric=['number','archive','archive_days','tv_archive_duration','enable_tv_archive',
            'allow_pvr','allow_local_pvr','allow_remote_pvr','hd','censored','tv_genre_id'];
        $safe=function($value,int $length=120): ?string {
            if(!is_scalar($value))return null;
            $sample=trim(html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if($sample===''||preg_match('/^(?:n\/?a|none|null|unknown|undefined)$/i',$sample))return null;
            if(preg_match('~(?:https?://|[a-z][a-z0-9+.-]*://|(?:token|auth|pass|session|cookie)[=:]|[?&][a-z_]+=)~i',$sample))return null;
            return mb_substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$sample),0,$length);
        };
        $inventory=[];$records=[];$inspected=0;$excluded=0;
        foreach($rows as $row){
            if(!is_array($row))continue;
            ++$inspected;
            foreach($row as $key=>$value){
                if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,49}$/i',$key))continue;
                if(preg_match('/(?:token|auth|pass|secret|cookie|key|mac|serial|device|url|link|cmd|stream|ticket|host|ip|login|account|user|pin|drm|license|proxy|path|file|play)/i',$key))continue;
                $type=is_array($value)?'array':(is_object($value)?'object':gettype($value));
                if(!isset($inventory[$key]))$inventory[$key]=['type'=>$type,'present'=>0,'populated'=>0,'useful'=>0];
                $inventory[$key]['present']++;
                if($value!==null&&$value!==''&&$value!==[])$inventory[$key]['populated']++;
                $sample=$safe($value);
                if($sample!==null){
                    $inventory[$key]['useful']++;
                    if(in_array($key,$examples,true)&&!in_array($key,['logo','epg'],true)&&!isset($inventory[$key]['example']))
                        $inventory[$key]['example']=$sample;
                    if(in_array($key,$numeric,true)&&is_numeric($sample)){
                        if(!isset($inventory[$key]['values']))$inventory[$key]['values']=[];
                        if(count($inventory[$key]['values'])<12||array_key_exists($sample,$inventory[$key]['values']))
                            $inventory[$key]['values'][$sample]=($inventory[$key]['values'][$sample]??0)+1;
                    }
                }
            }
            // Per-channel examples can reveal data relationships that field inventories cannot.
            // Never include ids, commands, URLs, tokens, full logo paths, or unchecked provider fields.
            if(count($records)>=15)continue;
            $record=[];
            foreach(['name','number','tv_genre_id','xmltv_id','epg_id','archive','archive_days',
                'tv_archive_duration','enable_tv_archive','allow_pvr','allow_local_pvr',
                'allow_remote_pvr','allow_local_timeshift','hd','quality','censored'] as $field){
                if(!array_key_exists($field,$row))continue;
                $value=$safe($row[$field],$field==='name'?140:90);
                if($value!==null)$record[$field]=$value;
            }
            $record['logo_present']=isset($row['logo'])&&is_string($row['logo'])&&trim($row['logo'])!=='';
            $record['embedded_epg_entries']=is_array($row['epg']??null)?min(count($row['epg']),10000):0;
            $record['separator_like']=isset($row['name'])&&is_string($row['name'])&&
                (bool)preg_match('/^\s*[#═━\-]{3,}|[#═━\-]{3,}\s*$/u',$row['name']);
            if($record['separator_like'])$excluded++;
            $records[]=$record;
        }
        ksort($inventory);
        return ['ok'=>true,'connection'=>$feed['name'],'category'=>$selected['name'],
            'category_id'=>$selected['id'],'page'=>$page,'rows_inspected'=>$inspected,
            'provider_total'=>isset($result['total_items'])&&is_numeric($result['total_items'])?(int)$result['total_items']:null,
            'fields'=>$inventory,'channel_samples'=>$records,'separator_samples'=>$excluded,
            'note'=>'One catalogue page only; safe per-channel metadata without playback commands, URLs, IDs or secrets. Flags represent provider claims, not verified functionality.'];
    }

    public static function entry(array $feed,string $id): ?array {
        $playback=\Vaultcast\Repository::cacheGet(self::key($feed,'item:'.$id));
        return $playback===null?null:['playback'=>$playback];
    }
}
