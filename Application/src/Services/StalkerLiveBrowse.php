<?php
declare(strict_types=1);

/** Live TV snapshots are fetched by the catalogue worker and read locally by viewers. */
final class StalkerLiveBrowse {
    private static function key(array $feed,string $suffix): string {
        return 'stalker_live_snapshot_'.hash('sha256',json_encode([StalkerCatalogues::config($feed),$suffix]));
    }
    public static function presentation(array $feed,string $id): array {
        $value=\Vaultcast\Repository::cacheGet(self::key($feed,'presentation:v3:'.$id));
        return is_array($value)?$value:[];
    }
    public static function categories(array $feed,bool $refresh=false): array {
        $snapshot=self::snapshot($feed);
        return ['ok'=>true,'categories'=>$snapshot['categories']??[],'catalogueLoading'=>$snapshot===null];
    }
    public static function snapshot(array $feed): ?array {
        if(StalkerCatalogues::expired($feed))return null;
        return \Vaultcast\Repository::stalkerSnapshot(self::key($feed,'imported-live'));
    }
    public static function fetchSnapshot(array $feed): array {
        $result=stalker_call($feed,'get_genres',[],'itv');
        $rows=$result['data']??$result;
        if(!is_array($rows))throw new RuntimeException('Provider returned invalid Live TV categories.');
        $categories=[];
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id']))continue;
            $id=(string)$row['id'];if($id===''||strlen($id)>120)continue;
            $title=mb_substr(trim((string)($row['title']??$row['name']??$id)),0,160);
            $categories[]=['id'=>$id,'name'=>$title,'country'=>StalkerCountries::country($title)??'ZZ',
                'genre'=>StalkerCountries::group($title,$title)];
            if(count($categories)>=1000)break;
        }
        $result=stalker_call($feed,'get_all_channels',[],'itv');
        $rows=$result['data']??$result;
        if(!is_array($rows)||count($rows)>50000||(isset($result['total_items'])&&(int)$result['total_items']>count($rows)))
            throw new RuntimeException('Provider returned an incomplete or oversized Live TV list.');
        $channels=[];
        foreach($rows as $row)if(is_array($row)&&isset($row['id'])&&is_scalar($row['id'])&&!empty($row['cmd']))$channels[(string)$row['id']]=$row;
        if(!$channels)throw new RuntimeException('Provider returned no playable Live TV channels.');
        uasort($channels,static function($a,$b){
            return ((int)($a['number']??0)<=> (int)($b['number']??0)) ?: strcmp((string)$a['id'],(string)$b['id']);
        });
        $guide=[];
        try{
            $response=stalker_call($feed,'get_epg_info',['period'=>48],'itv');
            $data=$response['data']??$response;
            if(is_array($data))$guide=$data;
        }catch(RuntimeException $ignored){}
        return ['categories'=>$categories,'channels'=>$channels,'guide'=>$guide,'imported'=>time()];
    }
    public static function saveSnapshot(array $feed,array $snapshot): void {
        \Vaultcast\Repository::saveStalkerSnapshot(self::key($feed,'imported-live'),$snapshot);
    }
    public static function channels(array $feed,array $opts): array {
        $category=mb_substr((string)($opts['category']??''),0,120);
        if($category==='')throw new InvalidArgumentException('Choose a provider category.');
        // Never permit arbitrary category IDs as a way to trigger cross-provider scans.
        $snapshot=self::snapshot($feed);
        if($snapshot===null)return ['ok'=>true,'items'=>[],'total'=>0,'hasMore'=>false,'catalogueLoading'=>true];
        $categories=$snapshot['categories'];
        $selected=null;
        foreach($categories as $candidate)if($candidate['id']===$category){$selected=$candidate;break;}
        if($selected===null)throw new InvalidArgumentException('Unknown provider category.');
        $page=max(1,min(10000,(int)($opts['page']??1)));
        $rows=array_filter($snapshot['channels'],static function($row)use($category){return $category==='*'||(string)($row['tv_genre_id']??'')===$category;});
        $total=count($rows);$perPage=60;
        $rows=array_slice($rows,($page-1)*$perPage,$perPage);
        $items=[];
        foreach($rows as $row){
            if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id'])||empty($row['cmd']))continue;
            $id=(string)$row['id'];if($id===''||strlen($id)>120)continue;
            $name=mb_substr((string)($row['name']??$id),0,300);
            if(preg_match('/[#═━☰]{3,}/u',$name))continue;
            // Carry presentation metadata already included in the same provider page.
            // Keep commands and private provider URLs in the existing server-side cache.
            $xmltvId=trim((string)($row['xmltv_id']??''));
            if(strlen($xmltvId)>160||preg_match('~(?:/|\\\\|https?:|[?&=]|[\\x00-\\x1f])~',$xmltvId))$xmltvId='';
            $number=filter_var($row['number']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>1000000]]);
            $flag=static function($value): bool {
                return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
            };
            $items[]=['id'=>$id,'name'=>$name,'description'=>$selected['name'],'feed'=>$feed['id'],
                'connection'=>$feed['name'],'kind'=>'live','country'=>StalkerCountries::country($name)??$selected['country'],
                'number'=>$number===false?null:$number,'xmltvId'=>$xmltvId,
                'epgAvailable'=>$xmltvId!=='',
                'archive'=>$flag($row['archive']??0)&&$flag($row['enable_tv_archive']??0),
                'archiveHours'=>max(0,min(720,(int)($row['tv_archive_duration']??0))),
                'pvr'=>$flag($row['allow_pvr']??0),'timeshift'=>$flag($row['allow_local_timeshift']??0),
                'logoAvailable'=>false,'logoUrl'=>''];
        }
        return ['ok'=>true,'items'=>$items,'page'=>$page,'total'=>$total,'hasMore'=>$page*$perPage<$total];
    }
    /**
     * Load a short on-demand guide for one visible provider channel page.
     * Only selected channel programmes are cached, never the whole provider guide.
     */
    /**
     * Match known quality variants without collapsing regional services.
     * Do not remove region names, +1 timeshifts or channel numbers generally.
     */
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

    public static function guidePage(array $feed,array $opts): array {
        $category=(string)($opts['category']??'');
        if(!preg_match('/^[0-9]{1,12}$/D',$category))
            throw new InvalidArgumentException('Select a live TV category.');
        $page=max(1,min(10000,(int)($opts['page']??1)));
        $refresh=!empty($opts['refresh']);
        $indexRevision=[];
        foreach(['gb','gb-freeview','us'] as $region){
            $indexPath=STREAM_DATA.'/live/epg_cache/stalker-open-'.$region.'.json';
            $indexRevision[$region]=is_file($indexPath)?(int)@filemtime($indexPath):0;
        }
        $snapshot=self::snapshot($feed);
        if($snapshot===null)return ['ok'=>true,'programmes'=>[],'available'=>false,'channelCount'=>0,'catalogueLoading'=>true];
        $key=self::key($feed,'imported-guide:'.json_encode([$snapshot['imported']??0,$category,$page,$indexRevision]));
        $cached=$refresh?null:\Vaultcast\Repository::cacheGet($key);
        if($cached!==null)return $cached;
        $visible=self::channels($feed,['category'=>$category,'page'=>$page]);
        $ids=[];
        foreach($visible['items'] as $item)$ids[(string)$item['id']]=true;
        if(!$ids)return ['ok'=>true,'programmes'=>[],'available'=>false,'channelCount'=>0];
        $data=$snapshot['guide'];
        $portalFailed=!$data;
        $now=time();$programmes=[];$total=0;
        $portalMatched=[];$openMatched=[];$openEpgIndexStatus=[];$channelLogos=[];
        foreach($data as $channelId=>$rows){
            if(!is_array($rows))continue;
            $channel=(string)$channelId;
            if(!isset($ids[$channel])){
                // Some portals index EPG arrays differently; their row ch_id takes precedence.
                $first=null;
                foreach($rows as $candidate){if(is_array($candidate)){$first=$candidate;break;}}
                $channel=is_array($first)?(string)($first['ch_id']??$channel):$channel;
            }
            if(!isset($ids[$channel]))continue;
            foreach($rows as $row){
                if(!is_array($row))continue;
                $actual=(string)($row['ch_id']??$channel);
                if(!isset($ids[$actual]))continue;
                $start=$row['start_timestamp']??null;$end=$row['stop_timestamp']??null;
                if(!is_numeric($start)||!is_numeric($end))continue;
                $start=(int)$start;$end=(int)$end;
                if($start<1000000000||$end<=$start||$end-$start>86400
                    ||$end<$now-3600||$start>$now+172800)continue;
                $name=trim(strip_tags((string)($row['name']??$row['title']??'')));
                if($name==='')continue;
                $description=trim(strip_tags((string)($row['descr']??'')));
                $portalMatched[$actual]=true;
                $programmes[$actual][]= array_merge(StalkerProgramme::provider($row),[
                    'title'=>mb_substr($name,0,180),'description'=>StalkerProgramme::text($row['descr']??$row['description']??'',5000),
                    'start'=>$start,'end'=>$end,'source'=>'provider'
                ]);
                if(++$total>=240)break 2;
            }
        }
        // Match only the requested country's cached Open EPG guide.
        // Never use a US programme for a UK channel or vice versa.
        foreach(['GB','GB-freeview','US'] as $region){
            $country=substr($region,0,2);
            $indexPath=STREAM_DATA.'/live/epg_cache/stalker-open-'.strtolower($region).'.json';
            if(!is_file($indexPath)){$openEpgIndexStatus[$region]='missing';continue;}
            if(@filesize($indexPath)>16777216){$openEpgIndexStatus[$region]='oversized';continue;}
            $raw=@file_get_contents($indexPath);
            $open=$raw!==false?json_decode($raw,true):null;
            if(!is_array($open)||!is_array($open['programmes']??null)){
                $openEpgIndexStatus[$region]='invalid';continue;
            }
            if((int)($open['updatedAt']??0)<=$now-86400){
                $openEpgIndexStatus[$region]='stale';continue;
            }
            $openEpgIndexStatus[$region]='ready';
            // Build candidate sets rather than treating HD/SD versions of the
            // same unregionalised service as an immediate ambiguous match.
            $nameMap=[];$regionalMap=[];
            foreach(($open['channels']??[]) as $xmlId=>$label){
                if(!isset($open['programmes'][$xmlId]))continue;
                $match=self::epgMatchName((string)$label);
                if($match==='')continue;
                $nameMap[$match][(string)$xmlId]=(string)$label;
                $root=self::epgRegionalRoot($match);
                if($root!==$match)$regionalMap[$root][(string)$xmlId]=(string)$label;
            }
            foreach($visible['items'] as $channel){
                if(strtoupper((string)($channel['country']??''))!==$country)continue;
                $id=(string)($channel['id']??'');
                if($id==='')continue;
                $sourceId=trim((string)($channel['xmltvId']??''));
                $match=self::epgMatchName((string)($channel['name']??''));
                $candidates=$nameMap[$match]??[];$regionalConsensus=false;
                if(!isset($open['programmes'][$sourceId])){
                    if(!$candidates&&isset($regionalMap[$match])){
                        $candidates=$regionalMap[$match];
                        $regionalConsensus=count($candidates)>1;
                    }
                    if(count($candidates)===1){
                        $sourceId=(string)array_key_first($candidates);
                    } elseif(count($candidates)>1){
                        // Prefer one HD/UHD candidate for an HD/UHD portal channel,
                        // but never collapse differing regional channel labels.
                        $preferred=[];
                        $quality=(string)($channel['name']??'');
                        if(preg_match('/\\b(?:UHD|4K|FHD|HD)\\s*$/iu',$quality)){
                            foreach($candidates as $candidateId=>$label){
                                if(preg_match('/\\b(?:UHD|4K|HD)\\s*$/iu',$label))$preferred[]=$candidateId;
                            }
                        }
                        if(count($preferred)===1)$sourceId=(string)$preferred[0];
                    }
                }
                if($sourceId!=='' && isset($open['logos'][$sourceId])
                    && is_string($open['logos'][$sourceId]))
                    $channelLogos[$id]=$open['logos'][$sourceId];
                elseif($candidates){
                    foreach($candidates as $candidateId=>$label){
                        if(isset($open['logos'][$candidateId])&&is_string($open['logos'][$candidateId])){
                            $channelLogos[$id]=$open['logos'][$candidateId];break;
                        }
                    }
                }
                $additional=$open['programmes'][$sourceId]??null;
                if(!empty($regionalConsensus)&&$sourceId===''){
                    // Generic ITV1 has no single national XMLTV channel. Add only
                    // events shared by a majority of regional schedules; this fills
                    // nationwide programme slots without assigning local news to
                    // the wrong region. Keep +1 consensus separate.
                    $votes=[];$regionalIds=array_keys($candidates);$regionalCount=count($regionalIds);
                    foreach($regionalIds as $regionalId){
                        $seen=[];
                        foreach($open['programmes'][$regionalId]??[] as $event){
                            if(!is_array($event))continue;
                            $signature=(int)($event['start']??0).'|'.(int)($event['end']??0).'|'.mb_strtolower(trim((string)($event['title']??'')));
                            if(isset($seen[$signature]))continue;
                            $seen[$signature]=true;
                            if(!isset($votes[$signature]))$votes[$signature]=['count'=>0,'event'=>$event];
                            $votes[$signature]['count']++;
                        }
                    }
                    $threshold=(int)ceil($regionalCount*0.6);
                    $additional=[];
                    foreach($votes as $vote)if($vote['count']>=$threshold)$additional[]=$vote['event'];
                }
                if(!is_array($additional))continue;
                if(!isset($programmes[$id]))$programmes[$id]=[];
                foreach($additional as $event){
                    if(!is_array($event))continue;
                    $start=(int)($event['start']??0);$end=(int)($event['end']??0);
                    if($end<$now-3600||$start>$now+172800||$end<=$start||$end-$start>86400)continue;
                    $overlaps=false;
                    foreach($programmes[$id] as &$existing){
                        if($start<(int)$existing['end']&&$end>(int)$existing['start']){
                            // Enrich the same programme only, never an unrelated
                            // regional listing that happens to overlap in time.
                            if($start===(int)$existing['start']&&mb_strtolower(trim((string)($event['title']??'')))===mb_strtolower(trim($existing['title']))){
                                foreach(['description','cast','director','presenter','categories','rating','ratingSystem','episode','language','subtitles','poster'] as $field)
                                    if(empty($existing[$field])&&!empty($event[$field]))$existing[$field]=$event[$field];
                            }
                            $overlaps=true;break;
                        }
                    }
                    unset($existing);
                    if($overlaps)continue;
                    $title=trim((string)($event['title']??''));
                    if($title==='')continue;
                    $openMatched[$id]=true;
                    $programmes[$id][]=array_merge(array_intersect_key($event,array_flip(['cast','director','presenter','categories','rating','ratingSystem','episode','language','subtitles','poster'])),['title'=>mb_substr($title,0,180),
                        'description'=>mb_substr((string)($event['description']??''),0,5000),
                        'start'=>$start,'end'=>$end,'source'=>$region==='GB-freeview'?'freeview':'open-epg']);
                    if(count($programmes[$id])>=60)break;
                }
            }
        }
        foreach($programmes as $id=>$entries){
            usort($entries,static function($a,$b){return $a['start']<=>$b['start'];});
            // The provider may overlap or duplicate entries; retain the nearest
            // programme candidates without reordering the channel list.
            $unique=[];$previous=[];
            foreach($entries as $entry){
                $signature=$entry['start'].'|'.$entry['end'].'|'.$entry['title'];
                if(isset($previous[$signature]))continue;
                $previous[$signature]=true;$unique[]=$entry;
                if(count($unique)>=60)break;
            }
            $programmes[$id]=$unique;
        }
        if($portalFailed && !$programmes)
            throw new RuntimeException('Provider EPG is unavailable and no matching cached Open EPG listings were found.');
        // Reuse the same matched schedule on the player page, without another
        // portal scan or any browser-supplied metadata becoming authoritative.
        foreach($visible['items'] as $channel){
            $id=(string)$channel['id'];$events=[];
            foreach($programmes[$id]??[] as $event)$events[]=array_merge($event,[
                'startUnix'=>$event['start']*1000,'endUnix'=>$event['end']*1000,
                'subtitle'=>$event['description']??'','kind'=>'epg']);
            \Vaultcast\Repository::cachePut(self::key($feed,'presentation:v3:'.$id),[
                'name'=>$channel['name'],'logo'=>'',
                'programs'=>$events],600);
        }
        $out=['ok'=>true,'programmes'=>$programmes,'available'=>count($programmes)>0,
            'channelCount'=>count($ids),'guideChannels'=>count($programmes),'limited'=>$total>=240,
            'providerEpgUnavailable'=>$portalFailed,
            'providerMatchedChannels'=>count($portalMatched),
            'openEpgIndexStatus'=>$openEpgIndexStatus,
            'channelLogos'=>$channelLogos,
            'openEpgMatchedChannels'=>count($openMatched),
            'openEpgAddedProgrammeChannels'=>count(array_diff_key($openMatched,$portalMatched))];
        \Vaultcast\Repository::cachePut($key,$out,180);
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

    /** One opt-in short guide request. Never expose provider access or transport fields. */
    public static function epgProbe(array $feed,array $opts): array {
        $period=max(1,min(2,(int)($opts['period']??1)));
        // Smallest meaningful provider guide window; no background scans or writes.
        $response=stalker_request($feed,'itv','get_epg_info',['period'=>$period],stalker_session($feed));
        $data=$response['data']??$response;
        if(!is_array($data))throw new RuntimeException('Provider returned an invalid EPG response.');
        $inventory=[];$examples=[];$channels=0;$events=0;$earliest=null;$latest=null;$channelsWithEvents=0;
        $safe=static function($value,int $limit=160): ?string {
            if(!is_scalar($value))return null;
            $str=trim(html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if($str===''||preg_match('/^(?:n\/?a|none|null|unknown|undefined)$/i',$str))return null;
            if(preg_match('~(?:https?://|[a-z][a-z0-9+.-]*://|(?:token|auth|pass|session|cookie)[=:]|[?&][a-z_]+=)~i',$str))return null;
            return mb_substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$str),0,$limit);
        };
        foreach($data as $channelId=>$rows){
            if(!is_array($rows))continue;
            ++$channels;
            $had=false;
            foreach($rows as $row){
                if(!is_array($row))continue;
                $had=true;++$events;
                foreach($row as $key=>$value){
                    if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,49}$/i',$key))continue;
                    if(preg_match('/(?:token|auth|pass|secret|cookie|key|mac|serial|device|url|link|cmd|stream|ticket|host|ip|login|account|user|pin|drm|license|proxy|path|file|play)/i',$key))continue;
                    if(!isset($inventory[$key]))$inventory[$key]=['type'=>gettype($value),'present'=>0,'nonempty'=>0];
                    $inventory[$key]['present']++;
                    $v=$safe($value);
                    if($v!==null){
                        $inventory[$key]['nonempty']++;
                        if(in_array($key,['name','title','descr','description','start_timestamp','stop_timestamp',
                            'time','time_to','ch_id','category','genre','duration'],true)
                            &&!isset($inventory[$key]['example']))$inventory[$key]['example']=$v;
                    }
                }
                $start=$row['start_timestamp']??$row['time']??null;
                $end=$row['stop_timestamp']??$row['time_to']??null;
                $start=is_numeric($start)?(int)$start:(is_string($start)?strtotime($start):false);
                $end=is_numeric($end)?(int)$end:(is_string($end)?strtotime($end):false);
                if(is_int($start)&&$start>0)$earliest=$earliest===null?$start:min($earliest,$start);
                if(is_int($end)&&$end>0)$latest=$latest===null?$end:max($latest,$end);
                if(count($examples)<12){
                    $entry=['channel_ref'=>substr(hash('sha256',(string)($row['ch_id']??$channelId)),0,12)];
                    foreach(['name','title','descr','description','category','genre'] as $field){
                        if(isset($row[$field])&&($v=$safe($row[$field],$field==='descr'||$field==='description'?240:150))!==null)
                            $entry[$field]=$v;
                    }
                    if(is_int($start)&&$start>0)$entry['start_utc']=gmdate('c',$start);
                    if(is_int($end)&&$end>0)$entry['end_utc']=gmdate('c',$end);
                    $examples[]=$entry;
                }
                // Defend the NAS against unexpectedly large provider guide responses.
                if($events>=1500)break;
            }
            if($had)++$channelsWithEvents;
            if($events>=1500||$channels>=5000)break;
        }
        ksort($inventory);
        return ['ok'=>true,'connection'=>$feed['name'],'period'=>$period,
            'channels_seen'=>$channels,'channels_with_events'=>$channelsWithEvents,
            'events_inspected'=>$events,'truncated'=>$events>=1500||$channels>=5000,
            'earliest_start_utc'=>$earliest===null?null:gmdate('c',$earliest),
            'latest_end_utc'=>$latest===null?null:gmdate('c',$latest),
            'fields'=>$inventory,'programme_samples'=>$examples,
            'note'=>'One bounded EPG request. Channel references hashed. Credentials, playback links, provider URLs and unrecognised values omitted.'];
    }

    public static function entry(array $feed,string $id): ?array {
        $row=self::snapshot($feed)['channels'][$id]??null;
        return $row===null?null:['id'=>$id,'name'=>(string)($row['name']??$id),'description'=>'','playback'=>stalker_live_entry($row)];
    }
}
