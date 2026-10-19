<?php
declare(strict_types=1);

final class HomeExtras {
    private static function key(string $uid): string {return 'home-extra:'.$uid;}
    public static function state(array $user): array {
        $data=\Vaultcast\Repository::document(self::key($user['id']),[]);
        return ['ok'=>true,'priorities'=>$data['priorities'] ?? [],'lists'=>array_values($data['lists'] ?? [])];
    }
    private static function friend(array $user,string $other): bool {
        $data=\Vaultcast\Repository::document('home-hub:'.$user['id'],[]);return ($data['friends'][$other] ?? '')==='friend';
    }
    public static function lists(array $user,int $page=1,string $query=''): array {
        $out=[];$home=\Vaultcast\Repository::document('home-hub:'.$user['id'],[]);
        $ids=[$user['id']];foreach ($home['friends'] ?? [] as $id=>$status) if ($status==='friend') $ids[]=$id;
        $profiles=[];$profilesQuery=\Vaultcast\Database::connection()->prepare("SELECT id,username,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.display_name')) AS display_name FROM vaultcast_users WHERE id IN (".implode(',',array_fill(0,count($ids),'?')).')');$profilesQuery->execute($ids);foreach ($profilesQuery as $profile) $profiles[$profile['id']]=($profile['display_name'] && $profile['display_name']!=='null')?$profile['display_name']:$profile['username'];
        $names=array_map(static function($id){return self::key($id);},$ids);
        $q=\Vaultcast\Database::connection()->prepare('SELECT name,payload FROM vaultcast_documents WHERE name IN ('.implode(',',array_fill(0,count($names),'?')).')');$q->execute($names);
        foreach ($q as $row) {
            $owner=substr($row['name'],strlen('home-extra:'));$mine=$owner===$user['id'];$data=json_decode($row['payload'],true);
            foreach ($data['lists'] ?? [] as $list) {
                if ((!$mine && empty($list['shared'])) || ($query!=='' && mb_stripos($list['name'],$query)===false)) continue;
                $list['owner']=$owner;$list['owner_name']=$profiles[$owner] ?? 'Friend';$list['mine']=$mine;$out[]=$list;
            }
        }
        usort($out,static function($a,$b){return ($b['mine']<=>$a['mine']) ?: ($b['created_at']<=>$a['created_at']) ?: strcmp($a['id'],$b['id']);});$page=max(1,$page);
        $pageLists=array_slice($out,($page-1)*12,12);$documents=[];
        foreach($pageLists as $list)$documents[]='personal-list:'.$list['owner'].':'.$list['id'];
        $previews=[];
        if($documents){$stmt=\Vaultcast\Database::connection()->prepare('SELECT name,payload FROM vaultcast_documents WHERE name IN ('.implode(',',array_fill(0,count($documents),'?')).')');$stmt->execute($documents);foreach($stmt as $row)$previews[$row['name']]=json_decode($row['payload'],true);}
        foreach($pageLists as &$list){$entries=$previews['personal-list:'.$list['owner'].':'.$list['id']]['items'] ?? [];$visible=[];foreach($entries as $entry)if(!empty($entry['card'])&&AreaAccess::allowed($user,AreaAccess::itemArea($entry['card'])))$visible[]=$entry['card'];$list['preview_items']=array_slice($visible,0,6);$list['item_count']=count($visible);}unset($list);
        return ['ok'=>true,'lists'=>$pageLists,'has_more'=>$page*12<count($out),'total'=>count($out)];
    }
    public static function collection(array $user,string $owner,string $id): array {
        if (!preg_match('/^[a-f0-9]{16,64}$/D',$owner) || !preg_match('/^[a-f0-9]{16}$/D',$id)) throw new DomainException('Invalid collection.');
        $data=\Vaultcast\Repository::document(self::key($owner),[]);$list=$data['lists'][$id] ?? null;$mine=$owner===$user['id'];
        if (!$list || (!$mine && (empty($list['shared']) || !self::friend($user,$owner)))) throw new DomainException('Collection not found or private.');
        $stored=\Vaultcast\Repository::document('personal-list:'.$owner.':'.$id,[]);
        $list['items']=array_values(array_filter($stored['items'] ?? [],static function($entry) use ($user){return AreaAccess::allowed($user,AreaAccess::itemArea($entry['card']));}));
        foreach ($list['items'] as &$entry) {$votes=$entry['votes'] ?? [];$entry['votes']=count($votes);$entry['voted']=in_array($user['id'],$votes,true);}unset($entry);
        $list['owner']=$owner;$list['mine']=$mine;return ['ok'=>true,'list'=>$list];
    }
    public static function change(array $user,array $body): array {
        $action=(string)($body['action'] ?? '');$owner=(string)($body['owner'] ?? $user['id']);$mine=$owner===$user['id'];
        if (!$mine && (!self::friend($user,$owner) || !in_array($action,['vote','add'],true))) throw new DomainException('This list is not available to your account.');
        $card=null;if ($action==='add') $card=HomeHub::entity((string)($body['key'] ?? ''),$user);
        $created=bin2hex(random_bytes(8));
        HomeHub::mutate(self::key($owner),static function($data) use ($user,$owner,$body,$action,$mine,$card,$created){
            if ($action==='priority') {
                $keys=$body['keys'] ?? [];if (!is_array($keys) || count($keys)>300) throw new DomainException('Invalid watchlist order.');
                foreach ($keys as $key) if (!is_string($key) || !preg_match('/^(item|show):[a-f0-9]{8,64}$/D',$key)) throw new DomainException('Invalid watchlist title.');
                $data['priorities']=array_values(array_unique($keys));return $data;
            }
            $id=(string)($body['id'] ?? '');
            if ($action==='create') {
                if (count($data['lists'] ?? [])>=30) throw new DomainException('You can create up to 30 collections.');
                $name=mb_substr(trim((string)($body['name'] ?? '')),0,80);if ($name==='') throw new DomainException('Give your collection a name.');
                $data['lists'][$created]=['id'=>$created,'name'=>$name,'shared'=>!empty($body['shared']),'item_count'=>0,'created_at'=>time()];return $data;
            }
            if (!isset($data['lists'][$id])) throw new DomainException('Collection not found.');
            $list=&$data['lists'][$id];if (!$mine && empty($list['shared'])) throw new DomainException('This collection is private.');
            if ($action==='delete') {
                unset($data['lists'][$id]);$q=\Vaultcast\Database::connection()->prepare('DELETE FROM vaultcast_documents WHERE name=?');$q->execute(['personal-list:'.$owner.':'.$id]);
            } elseif ($action==='edit') {
                $name=mb_substr(trim((string)($body['name'] ?? $list['name'])),0,80);if ($name==='') throw new DomainException('Choose a name.');$list['name']=$name;$list['shared']=!empty($body['shared']);
            } elseif (in_array($action,['add','remove','vote'],true)) {
                $entries=HomeHub::mutate('personal-list:'.$owner.':'.$id,static function($entries) use ($action,$card,$body,$user){
                    if ($action==='add') {if (count($entries['items'] ?? [])>=100 && !isset($entries['items'][$card['hub_key']])) throw new DomainException('A collection can hold 100 titles.');$entries['items'][$card['hub_key']]=['card'=>$card,'votes'=>$entries['items'][$card['hub_key']]['votes'] ?? [],'added_at'=>time()];}
                    elseif ($action==='remove') unset($entries['items'][(string)($body['key'] ?? '')]);
                    else {
                        $key=(string)($body['key'] ?? '');if (!isset($entries['items'][$key])) throw new DomainException('Title not found.');
                        if (!AreaAccess::allowed($user,AreaAccess::itemArea($entries['items'][$key]['card']))) throw new DomainException('This title is not available to your account.');
                        $votes=$entries['items'][$key]['votes'] ?? [];$has=in_array($user['id'],$votes,true);$votes=array_values(array_diff($votes,[$user['id']]));if (!$has) $votes[]=$user['id'];$entries['items'][$key]['votes']=$votes;
                    }return $entries;
                });$list['item_count']=count($entries['items'] ?? []);
            } else throw new DomainException('Unknown collection action.');
            return $data;
        });return ['ok'=>true,'id'=>$action==='create'?$created:(string)($body['id'] ?? '')];
    }
    private static function unfinished(): string {
        $duration="COALESCE(JSON_EXTRACT(p.payload,'$.duration'),0)";
        $position="COALESCE(JSON_EXTRACT(p.payload,'$.position'),0)";
        return "NOT (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.watched')),'false')='true' OR ($duration>0 AND ($position>$duration*0.92 OR $duration-$position<LEAST(CASE WHEN i.show_title<>'' THEN 180 ELSE 600 END,$duration*0.25))))";
    }
    public static function arrivals(array $user): array {
        if (!AreaAccess::allowed($user,'library')) return ['ok'=>true,'items'=>[]];
        $data=\Vaultcast\Repository::document('home-hub:'.$user['id'],[]);$where=[];$params=[$user['id']];
        foreach ($data['favourites'] ?? [] as $entry) {$card=$entry['card'];if (($card['kind'] ?? '')==='show') {$where[]='(i.section_id=? AND i.show_title=?)';$params[]=$card['section'];$params[]=$card['title'];}}
        if (!$where) return ['ok'=>true,'items'=>[]];$scope=implode(' OR ',$where);$unfinished=self::unfinished();
        $q=\Vaultcast\Database::connection()->prepare("SELECT i.payload,i.added_at,p.payload AS progress FROM vaultcast_items i LEFT JOIN vaultcast_progress p ON p.user_id=? AND p.item_id=i.id WHERE i.media_kind='video' AND ($scope) AND $unfinished ORDER BY i.added_at DESC,i.modified_at DESC,i.title LIMIT 100");$q->execute($params);
        $items=[];$seen=[];
        foreach ($q as $row) {
            $it=json_decode($row['payload'],true);$progress=$row['progress']?json_decode($row['progress'],true):[];
            if (!empty($progress['watched']) || Catalogue::progressFinished($progress,true)) continue;
            $key=$it['section'].'|'.$it['show'].'|'.($it['season'] ?? '').':'.($it['episode'] ?? $it['id']);if (isset($seen[$key])) continue;$seen[$key]=true;
            $it['_added_at']=(int)$row['added_at'];$items[]=$it;if (count($items)>=24) break;
        }
        MediaFacts::preload($items);
        return ['ok'=>true,'items'=>array_map(static function($it){$card=Catalogue::publicItem($it,false);$card['added_at']=$it['_added_at'];return $card;},$items)];
    }
    public static function tonight(array $user,int $minutes): array {
        if (!AreaAccess::allowed($user,'library')) return ['ok'=>true,'items'=>[]];
        $minutes=max(5,min(300,$minutes));$unfinished=self::unfinished();
        $q=\Vaultcast\Database::connection()->prepare("SELECT i.payload,f.duration,f.sig,p.payload AS progress FROM vaultcast_media_facts f JOIN vaultcast_items i ON i.id=f.item_id LEFT JOIN vaultcast_progress p ON p.user_id=? AND p.item_id=i.id WHERE f.duration>0 AND f.duration<=? AND f.present=1 AND i.media_kind='video' AND $unfinished ORDER BY f.duration DESC,i.title LIMIT 100");$q->execute([$user['id'],$minutes*60]);
        $items=[];foreach ($q as $row) {$it=json_decode($row['payload'],true);if(MediaFacts::sig($it)!==$row['sig'])continue;$p=$row['progress']?json_decode($row['progress'],true):[];if (!empty($p['watched']) || Catalogue::progressFinished($p,!empty($it['show']))) continue;$it['_runtime']=(float)$row['duration'];$items[]=$it;if(count($items)>=30)break;}
        MediaFacts::preload($items);return ['ok'=>true,'items'=>array_map(static function($it){$card=Catalogue::publicItem($it,false);$card['duration']=$it['_runtime'];return $card;},$items),'minutes'=>$minutes];
    }
}
