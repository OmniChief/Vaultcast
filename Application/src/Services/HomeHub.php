<?php
declare(strict_types=1);

/** Personal and social features use the existing transactional document store. */
final class HomeHub {
    private static function key(string $uid): string { return 'home-hub:' . $uid; }
    public static function mutate(string $name, callable $change): array {
        return \Vaultcast\Database::transaction(function(PDO $pdo) use ($name, $change): array {
            $insert = $pdo->prepare('INSERT IGNORE INTO vaultcast_documents (name,payload) VALUES (?,?)');
            $insert->execute([$name, '{}']);
            $select = $pdo->prepare('SELECT payload FROM vaultcast_documents WHERE name=? FOR UPDATE');
            $select->execute([$name]);
            $value = json_decode((string)$select->fetchColumn(), true) ?: [];
            $value = $change($value);
            \Vaultcast\Repository::putDocument($name, $value);
            return $value;
        });
    }
    private static function personal(string $uid): array { return \Vaultcast\Repository::document(self::key($uid), []); }
    private static function profile(array $user): array {
        return ['id'=>$user['id'], 'name'=>($user['display_name'] ?? '') ?: $user['username'], 'username'=>$user['username'],
            'avatar_url'=>Profiles::avatarUrl($user)];
    }
    private static function visible(array $card, array $user): bool { return AreaAccess::allowed($user, AreaAccess::itemArea($card)); }
    public static function entity(string $key, array $user): array {
        if (!preg_match('/^(item|show):([a-f0-9]{8,64})$/D', $key, $m)) throw new DomainException('Choose a library title.');
        if ($m[1] === 'item') {
            $item = Catalogue::item($m[2]);
            if (!$item) throw new DomainException('This title is no longer in the library.');
            if (!empty($item['show'])) return self::entity('show:' . Catalogue::showKey((string)$item['section'], (string)$item['show']), $user);
            $card = Catalogue::publicItem($item, false);
        } else {
            $show = Catalogue::showSummary($m[2]);
            if (!$show) throw new DomainException('This show is no longer in the library.');
            $card = Catalogue::publicShow($show);
        }
        if (!self::visible($card, $user)) throw new DomainException('Your account cannot access this title.');
        $card['hub_key'] = $key;
        return $card;
    }
    public static function save(array $user, array $body): array {
        $shelf = (string)($body['shelf'] ?? '');
        if (!in_array($shelf, ['favourites','watchlist'], true)) throw new DomainException('Unknown shelf.');
        $enabled = !empty($body['enabled']);
        $key=(string)($body['key'] ?? '');
        if (!preg_match('/^(item|show):[a-f0-9]{8,64}$/D',$key)) throw new DomainException('Invalid title.');
        $card=$enabled ? self::entity($key,$user) : ['hub_key'=>$key];
        self::mutate(self::key($user['id']), function(array $data) use ($card, $shelf, $enabled): array {
            if ($enabled) {
                if (count($data[$shelf] ?? []) >= 300 && !isset($data[$shelf][$card['hub_key']])) throw new DomainException('This shelf holds up to 300 titles.');
                $data[$shelf][$card['hub_key']] = ['card'=>$card, 'added_at'=>time()];
            } else unset($data[$shelf][$card['hub_key']]);
            return $data;
        });
        return ['ok'=>true, 'key'=>$card['hub_key'], 'enabled'=>$enabled];
    }
    public static function inbox(array $user): array {
        $q=\Vaultcast\Database::connection()->prepare("SELECT JSON_EXTRACT(payload,'$.notifications') FROM vaultcast_documents WHERE name=?");
        $q->execute([self::key((string)$user['id'])]);
        $notifications=json_decode((string)$q->fetchColumn(),true);
        return ['ok'=>true,'notifications'=>is_array($notifications)?array_values($notifications):[]];
    }
    public static function lastGame(array $user): array {
        if (!AreaAccess::allowed($user,'games'))return ['ok'=>true,'game'=>null];
        $data=self::personal((string)$user['id']);$games=$data['games'] ?? [];
        uasort($games,static function($a,$b){return ($b['last_played'] ?? 0)<=>($a['last_played'] ?? 0);});
        foreach (array_chunk(array_keys($games),100) as $ids) {
            $rows=\Vaultcast\Repository::rows('SELECT payload FROM vaultcast_items WHERE media_kind=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_merge(['rom'],$ids));
            $items=[];foreach ($rows as $item)$items[$item['id']]=$item;
            foreach ($ids as $id) if (isset($items[$id]) && self::visible($items[$id],$user)) return ['ok'=>true,'game'=>Catalogue::publicItem($items[$id],false),'last_played'=>$games[$id]['last_played'] ?? null];
        }
        return ['ok'=>true,'game'=>null];
    }
    public static function gameFavourites(array $user): array {
        $data = self::personal((string)$user['id']);
        $rows = array_values(array_filter($data['favourites'] ?? [], static function(array $row) use ($user): bool {
            $card = $row['card'] ?? [];
            return ($card['media_kind'] ?? '') === 'rom' && self::visible($card, $user);
        }));
        usort($rows, static function(array $a, array $b): int { return ($b['added_at'] ?? 0) <=> ($a['added_at'] ?? 0); });
        return ['ok'=>true, 'items'=>array_map(static function(array $row): array { return $row['card']; }, $rows)];
    }
    public static function dashboard(array $user): array {
        $uid = (string)($user['id'] ?? '');
        $data = $uid !== '' ? self::personal($uid) : [];
        $shelves = [];
        foreach (['favourites','watchlist'] as $shelf) {
            $rows = array_values(array_filter($data[$shelf] ?? [], function(array $row) use ($user): bool { return self::visible($row['card'], $user); }));
            usort($rows, static function(array $a,array $b): int { return $b['added_at'] <=> $a['added_at']; });
            $shelves[$shelf] = array_map(static function(array $row): array { return $row['card']; }, $rows);
        }
        $recent = []; $history = []; $upcoming = []; $games = [];
        if ($uid !== '' && ($user['role'] ?? '') !== 'guest') {
            $q = \Vaultcast\Database::connection()->prepare('SELECT i.payload AS item,p.payload AS progress FROM vaultcast_progress p JOIN vaultcast_items i ON i.id=p.item_id WHERE p.user_id=? ORDER BY p.updated_at DESC LIMIT 40');
            $q->execute([$uid]);
            $historyRows=$q->fetchAll(PDO::FETCH_ASSOC);
            MediaFacts::preload(array_map(static function(array $row): array {return json_decode($row['item'],true);},$historyRows));
            foreach ($historyRows as $row) {
                $it = json_decode($row['item'], true); $p = json_decode($row['progress'], true);
                if (!self::visible($it,$user)) continue;
                $card = Catalogue::publicItem($it,false); $card['progress']=$p;
                $card['watched']=!empty($p['watched']) || Catalogue::progressFinished($p,!empty($it['show']));
                $history[]=$card;
                if (count($recent)<8) $recent[]=$card;
            }
        }
        $watchShows=array_values(array_filter($shelves['watchlist'],static function($card){return ($card['kind'] ?? '')==='show';}));
        if ($uid!=='' && $watchShows) {
            $scope=[];$params=[$uid];
            foreach ($watchShows as $card) {$scope[]='(i.section_id=? AND i.show_title=?)';$params[]=$card['section'];$params[]=$card['title'];}
            $scope=implode(' OR ',$scope);
            $duration="COALESCE(JSON_EXTRACT(p.payload,'$.duration'),0)";
            $position="COALESCE(JSON_EXTRACT(p.payload,'$.position'),0)";
            $finished="(JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.watched'))='true' OR ($duration>0 AND ($position>$duration*0.92 OR $duration-$position<LEAST(180,$duration*0.25))))";
            $q=\Vaultcast\Database::connection()->prepare("SELECT i.section_id,i.show_title,COUNT(DISTINCT CASE WHEN $finished THEN CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.season')),''),':',COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.episode')),'null'),i.id)) END) AS seen,SUBSTRING_INDEX(GROUP_CONCAT(CONCAT('S',COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.season')),'0'),' E',COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.episode')),'0')) ORDER BY p.updated_at DESC SEPARATOR ','),',',1) AS last_episode FROM vaultcast_progress p JOIN vaultcast_items i ON i.id=p.item_id WHERE p.user_id=? AND ($scope) GROUP BY i.section_id,i.show_title");
            $q->execute($params);$seen=[];
            foreach ($q as $row) $seen[Catalogue::showKey($row['section_id'],$row['show_title'])]=['count'=>(int)$row['seen'],'last'=>$row['last_episode']];
            foreach ($shelves['watchlist'] as &$card) if (($card['kind'] ?? '')==='show') {
                $entry=$seen[$card['show_key']] ?? [];
                $card['watched_episodes']=(int)($entry['count'] ?? 0);
                $card['watch_status']=($entry['count'] ?? 0).' episodes watched'.(!empty($entry['last'])?' · Last: '.$entry['last']:'');
            }
            unset($card);
        }
        $watchIds=[];foreach ($shelves['watchlist'] as $card) if (($card['kind'] ?? '')!=='show') $watchIds[]=$card['id'];
        if ($uid!=='' && $watchIds) {
            $q=\Vaultcast\Database::connection()->prepare('SELECT item_id,payload FROM vaultcast_progress WHERE user_id=? AND item_id IN ('.implode(',',array_fill(0,count($watchIds),'?')).')');$q->execute(array_merge([$uid],$watchIds));$progress=[];
            foreach ($q as $row) $progress[$row['item_id']]=json_decode($row['payload'],true);
            foreach ($shelves['watchlist'] as &$card) if (isset($progress[$card['id']])) {$card['progress']=$progress[$card['id']];$card['watched']=!empty($card['progress']['watched']) || Catalogue::progressFinished($card['progress'],false);}unset($card);
        }
        $favouriteShows=[];
        foreach ($shelves['favourites'] as $card) if (($card['kind'] ?? '')==='show') $favouriteShows[$card['show_key']]=$card;
        foreach (self::releaseDates($uid,array_keys($favouriteShows)) as $ep) {
            $card=$favouriteShows[$ep['show_key']] ?? null;
            if ($card) {unset($ep['show_key']);$ep['card']=$card;$upcoming[]=$ep;}
        }
        foreach ($data['games'] ?? [] as $id=>$game) {
            if (!AreaAccess::allowed($user,'games')) break;
            $games[]=$game;
        }
        usort($games,static function(array $a,array $b): int {return $b['last_played'] <=> $a['last_played'];});
        $friends=[];
        $friendIds=array_keys($data['friends'] ?? []);
        if ($friendIds) foreach (\Vaultcast\Repository::rows('SELECT payload FROM vaultcast_users WHERE id IN ('.implode(',',array_fill(0,count($friendIds),'?')).')',$friendIds) as $friend) {
            $friends[]=array_merge(self::profile($friend),['relationship'=>$data['friends'][$friend['id']]]);
        }
        return array_merge(['ok'=>true,'recent'=>$recent,'history'=>$history,'games'=>array_slice($games,0,20),
            'game_stats'=>['seconds'=>array_sum(array_column($games,'seconds')),'games'=>count($games),'sessions'=>array_sum(array_column($games,'sessions'))],
            'friends'=>$friends,'upcoming'=>$upcoming,'notifications'=>array_values($data['notifications'] ?? []),
            'preferences'=>$data['preferences'] ?? [],'signed_in'=>($user['role'] ?? '')!=='guest'],$shelves);
    }
    public static function releaseCalendar(array $user): array {
        $data=self::personal((string)$user['id']);$shows=[];
        foreach($data['favourites'] ?? [] as $entry){$card=$entry['card'] ?? [];if(($card['kind'] ?? '')==='show' && self::visible($card,$user))$shows[$card['show_key']]=$card;}
        $out=[];foreach(self::releaseDates((string)$user['id'],array_keys($shows)) as $episode){$episode['card']=$shows[$episode['show_key']];unset($episode['show_key']);$out[]=$episode;}return $out;
    }
    private static function releaseDates(string $uid,array $keys): array {
        if (!$keys) return [];
        sort($keys,SORT_STRING);
        $names=array_map(static function($key){return 'tvmaze-guide:'.$key;},$keys);
        $pdo=\Vaultcast\Database::connection();
        $marks=implode(',',array_fill(0,count($names),'?'));
        $q=$pdo->prepare("SELECT name,revision FROM vaultcast_documents WHERE name IN ($marks) ORDER BY name");
        $q->execute($names);
        $revision=hash('sha256',gmdate('Y-m-d').'|'.json_encode($keys).'|'.json_encode($q->fetchAll(PDO::FETCH_ASSOC)));
        $slot='hub-release-dates-v1|'.$uid;
        $cached=\Vaultcast\Repository::cacheGet($slot);
        if (($cached['revision'] ?? '')===$revision) return $cached['episodes'];
        $q=$pdo->prepare("SELECT name,payload FROM vaultcast_documents WHERE name IN ($marks)");$q->execute($names);
        $episodes=[];$today=gmdate('Y-m-d');$until=gmdate('Y-m-d',time()+30*86400);
        foreach ($q as $row) {
            $guide=json_decode($row['payload'],true);$key=substr($row['name'],strlen('tvmaze-guide:'));
            foreach ($guide['episodes'] ?? [] as $ep) {
                $date=(string)($ep['airdate'] ?? '');
                if ($date<$today || $date>$until) continue;
                $episodes[]=['show_key'=>$key,'date'=>$date,'season'=>$ep['season'] ?? 0,'episode'=>$ep['number'] ?? 0,'title'=>$ep['name'] ?? 'New episode'];
            }
        }
        usort($episodes,static function($a,$b){return strcmp($a['date'],$b['date']);});
        // A fixed per-user slot avoids accumulating a file for each changed favourite list.
        \Vaultcast\Repository::cachePut($slot,['revision'=>$revision,'episodes'=>$episodes],86400);
        return $episodes;
    }
    public static function title(string $key,array $user): array {
        $card=self::entity($key,$user); $key=$card['hub_key'];
        $membership=\Vaultcast\Database::connection()->prepare("SELECT JSON_CONTAINS_PATH(payload,'one',?) AS favourite,JSON_CONTAINS_PATH(payload,'one',?) AS watchlist FROM vaultcast_documents WHERE name=?");
        $membership->execute(['$.favourites.'.json_encode($key),'$.watchlist.'.json_encode($key),self::key((string)($user['id'] ?? ''))]);
        $flags=$membership->fetch() ?: [];
        $reviews=\Vaultcast\Repository::document('hub-reviews:'.$key,[]);
        $rows=array_values($reviews); usort($rows,static function(array $a,array $b): int {return $b['updated_at'] <=> $a['updated_at'];});
        $ratings=array_filter(array_column($rows,'rating'),static function($v): bool {return is_numeric($v) && $v>0;});
        $episodes=[];
        if (($card['kind'] ?? '')==='show') {
            // Read only this show's local ids and progress; no second guide/detail build.
            $q=\Vaultcast\Database::connection()->prepare("SELECT i.id,i.show_title,CASE WHEN i.show_title='' THEN i.payload ELSE NULL END AS orphan,p.payload FROM vaultcast_items i LEFT JOIN vaultcast_progress p ON p.item_id=i.id AND p.user_id=? WHERE i.section_id=? AND (i.show_title=? OR i.show_title='')");
            $q->execute([(string)($user['id'] ?? ''),$card['section'],$card['title']]);
            foreach ($q as $row) {
                if ($row['show_title']==='') {
                    $orphan=json_decode($row['orphan'],true);
                    $parts=explode('/',str_replace('\\','/',(string)($orphan['rel'] ?? '')));
                    if (($parts[0] ?: ($orphan['title'] ?? '')) !== $card['title']) continue;
                }
                $p=$row['payload'] ? json_decode($row['payload'],true) : [];
                $episodes[$row['id']]=['watched'=>!empty($p['watched']) || Catalogue::progressFinished($p,true),'position'=>$p['position'] ?? 0];
            }
        }
        return ['ok'=>true,'card'=>$card,'favourite'=>!empty($flags['favourite']),'watchlist'=>!empty($flags['watchlist']),
            'reviews'=>array_slice($rows,0,60),'own_review'=>$reviews[$user['id'] ?? ''] ?? null,'rating'=>$ratings ? round(array_sum($ratings)/count($ratings),1):null,'rating_count'=>count($ratings),'episodes'=>$episodes];
    }
    public static function review(array $user,array $body): array {
        $card=self::entity((string)($body['key'] ?? ''),$user);
        $text=trim((string)($body['text'] ?? '')); $rating=(int)($body['rating'] ?? 0);
        if (mb_strlen($text)>2000 || $rating<0 || $rating>10) throw new DomainException('Use up to 2,000 characters and a rating from 1 to 10.');
        self::mutate('hub-reviews:'.$card['hub_key'],function(array $rows) use ($user,$body,$text,$rating): array {
            $id=($user['role'] ?? '')==='admin' ? (string)($body['user_id'] ?? $user['id']) : $user['id'];
            if (!empty($body['delete'])) unset($rows[$id]);
            else {
                if ($id!==$user['id']) throw new DomainException('You can only write your own review.');
                if ($text==='' && !$rating) throw new DomainException('Write a comment or choose a rating.');
                if (count($rows)>=500 && !isset($rows[$id])) throw new DomainException('This discussion is full.');
                $rows[$id]=['user'=>self::profile($user),'text'=>$text,'rating'=>$rating,'updated_at'=>time()];
            }
            return $rows;
        });
        return ['ok'=>true];
    }
    public static function watched(array $user,array $body): array {
        $item=Catalogue::item((string)($body['item_id'] ?? ''));
        if (!$item || !self::visible($item,$user) || ($item['media_kind'] ?? '')!=='video') throw new DomainException('Choose an accessible video.');
        $old=\Vaultcast\Repository::progressItem($user['id'],$item['id']) ?? [];
        $yes=!empty($body['watched']);
        \Vaultcast\Repository::saveProgress($user['id'],$item['id'],['watched'=>$yes,'position'=>$yes?($old['duration'] ?? 1):0,'duration'=>$old['duration'] ?? 0,'updated_at'=>time()]);
        \Vaultcast\Repository::putDocument('progress',[]);
        return ['ok'=>true];
    }
    public static function game(array $user,array $body): array {
        $item=Catalogue::item((string)($body['item_id'] ?? ''));
        if (!$item || ($item['media_kind'] ?? '')!=='rom' || !self::visible($item,$user)) throw new DomainException('Choose an accessible game.');
        $session=(string)($body['session'] ?? '');
        if (!preg_match('/^[a-f0-9]{16,64}$/D',$session)) throw new DomainException('Invalid play session.');
        $active=!empty($body['active']);$final=!empty($body['final']);
        self::mutate(self::key($user['id']),function(array $data) use ($item,$session,$active,$final): array {
            $now=time(); $previous=$data['play_sessions'][$session] ?? null;
            $row=$data['games'][$item['id']] ?? ['seconds'=>0,'sessions'=>0];
            if ($previous && $previous['item']!==$item['id']) throw new DomainException('Play session belongs to a different game.');
            $row['seconds']+= $previous && !empty($previous['active']) && ($active || $final) ? min(30,max(0,$now-$previous['at'])):0;
            if (!$previous) $row['sessions']++;
            $row['last_played']=$now;
            if (!$previous || empty($row['card'])) $row['card']=Catalogue::publicItem($item,false);
            $data['games'][$item['id']]=$row;
            $data['play_sessions'][$session]=['item'=>$item['id'],'at'=>$now,'active'=>$active];
            $data['play_sessions']=array_slice($data['play_sessions'],-32,null,true);
            return $data;
        });
        return ['ok'=>true];
    }
    public static function people(array $user,string $query): array {
        $q=trim($query); if (mb_strlen($q)<2) return ['ok'=>true,'people'=>[]];
        $rows=\Vaultcast\Repository::rows('SELECT payload FROM vaultcast_users WHERE username LIKE ? AND id<>? ORDER BY username LIMIT 20',['%'.addcslashes($q,'%_\\').'%',$user['id']]);
        return ['ok'=>true,'people'=>array_map([self::class,'profile'],$rows)];
    }
    public static function gameList(array $user,int $page,string $query): array {
        if (!AreaAccess::allowed($user,'games')) throw new DomainException('Games are not enabled for your account.');
        $data=self::personal($user['id']);$query=mb_strtolower(trim($query));
        $games=array_values(array_filter($data['games'] ?? [],static function(array $game) use ($query): bool {return $query==='' || mb_strpos(mb_strtolower((string)$game['card']['title']),$query)!==false;}));
        usort($games,static function(array $a,array $b): int {return $b['seconds'] <=> $a['seconds'];});$page=max(1,$page);
        return ['ok'=>true,'items'=>array_slice($games,($page-1)*24,24),'has_more'=>$page*24<count($games),'total'=>count($games)];
    }
    public static function friendship(array $user,array $body): array {
        $other=\Vaultcast\Repository::user((string)($body['user_id'] ?? ''),true);
        if (!$other || $other['id']===$user['id']) throw new DomainException('Choose another account.');
        $ids=[$user['id'],$other['id']]; sort($ids,SORT_STRING);
        \Vaultcast\Database::transaction(function(PDO $pdo) use ($ids,$user,$other,$body): void {
            $all=[];
            foreach ($ids as $id) {
                $s=$pdo->prepare('INSERT IGNORE INTO vaultcast_documents (name,payload) VALUES (?,?)');$s->execute([self::key($id),'{}']);
                $s=$pdo->prepare('SELECT payload FROM vaultcast_documents WHERE name=? FOR UPDATE');$s->execute([self::key($id)]);
                $all[$id]=json_decode((string)$s->fetchColumn(),true) ?: [];
            }
            $a=&$all[$user['id']]; $b=&$all[$other['id']];
            $action=$body['action'] ?? 'request';
            if ($action==='remove') {unset($a['friends'][$other['id']],$b['friends'][$user['id']]);}
            elseif ($action==='accept') {
                if (($a['friends'][$other['id']] ?? '')!=='incoming') throw new DomainException('No incoming request.');
                $a['friends'][$other['id']]=$b['friends'][$user['id']]='friend';
            } elseif ($action==='request') {
                if (isset($a['friends'][$other['id']])) throw new DomainException('A connection or request already exists.');
                if (count($a['friends'] ?? [])>=200 || count($b['friends'] ?? [])>=200) throw new DomainException('Friend limit reached.');
                $a['friends'][$other['id']]='outgoing'; $b['friends'][$user['id']]='incoming';
            } else throw new DomainException('Unknown friend action.');
            foreach ($all as $id=>$data) \Vaultcast\Repository::putDocument(self::key($id),$data);
        });
        return ['ok'=>true];
    }
    public static function friendProfile(array $user,string $id): array {
        $data=self::personal($user['id']);
        if (($data['friends'][$id] ?? '')!=='friend') throw new DomainException('Only friends can view this profile.');
        $other=\Vaultcast\Repository::user($id,true); if (!$other) throw new DomainException('Account no longer exists.');
        $their=self::personal($id); $games=array_values($their['games'] ?? []);
        return ['ok'=>true,'profile'=>self::profile($other),'stats'=>['games'=>count($games),'seconds'=>array_sum(array_column($games,'seconds'))]];
    }
    public static function invite(array $user,array $body): array {
        $id=(string)($body['user_id'] ?? ''); $data=self::personal($user['id']);
        $recipient=\Vaultcast\Repository::user($id,true);
        if (!$recipient || ($data['friends'][$id] ?? '')!=='friend') throw new DomainException('Invite an accepted friend.');
        $roomId=(string)($body['room_id'] ?? ''); $kind=$body['kind'] ?? '';
        if ($kind==='cinema') {
            $room=self::room($roomId,$user); if ($room['host_id']!==$user['id']) throw new DomainException('Only the host can invite.');
            $card=self::entity('item:'.$room['item_id'],$recipient); $url='home/cinema/'.$roomId;
        } elseif ($kind==='game') {
            $room=Lobby::get($roomId);
            if (!$room || ($room['host_user_id'] ?? '')!==$user['id']) throw new DomainException('Only your active lobby can be shared.');
            $card=self::entity('item:'.$room['item_id'],$recipient); $url='games/'.$card['game_path'].'?room='.$roomId;
        } else throw new DomainException('Choose a cinema room or game lobby.');
        self::mutate(self::key($id),function(array $d) use ($user,$roomId,$kind,$url,$card): array {
            $nid=$kind.':'.$roomId.':'.$user['id'];
            $d['notifications'][$nid]=['id'=>$nid,'from'=>self::profile($user),'title'=>$card['title'],'kind'=>$kind,'path'=>$url,'created_at'=>time()];
            $d['notifications']=array_slice($d['notifications'],-40,null,true);return $d;
        });
        return ['ok'=>true];
    }
    public static function dismiss(array $user,string $id): array {
        self::mutate(self::key($user['id']),static function(array $d) use ($id): array {unset($d['notifications'][$id]);return $d;});return ['ok'=>true];
    }
    public static function rooms(array $user): array {
        $registry=\Vaultcast\Repository::document('hub-cinemas',[]);$rows=[];
        $live=array_filter($registry,static function($summary){return ($summary['expires_at'] ?? 0)>time();});
        $ids=array_values(array_unique(array_column($live,'item_id')));$available=[];
        if ($ids) {
            $q=\Vaultcast\Database::connection()->prepare('SELECT id,media_kind FROM vaultcast_items WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);
            foreach ($q as $item) if (AreaAccess::allowed($user,AreaAccess::itemArea($item))) $available[$item['id']]=true;
        }
        foreach ($live as $summary) if (isset($available[$summary['item_id']])) $rows[]=$summary;
        return ['ok'=>true,'rooms'=>array_slice($rows,0,30)];
    }
    public static function createRoom(array $user,array $body): array {
        $it=Catalogue::item((string)($body['item_id'] ?? ''));
        if (!$it || ($it['media_kind'] ?? '')!=='video' || !self::visible($it,$user)) throw new DomainException('Choose an accessible video.');
        $id=bin2hex(random_bytes(8));$card=Catalogue::publicItem($it,false);
        $room=['id'=>$id,'item_id'=>$it['id'],'card'=>$card,'title'=>$card['title'],'host'=>self::profile($user),'host_id'=>$user['id'],
            'registry_at'=>time(),'position'=>0,'playing'=>false,'updated_at'=>microtime(true),'expires_at'=>time()+120,'members'=>[],'messages'=>[]];
        self::mutate('hub-cinemas',static function(array $all) use ($room,$id): array {
            foreach ($all as $oldId=>$old) if (($old['expires_at'] ?? 0)<time()) {
                $delete=\Vaultcast\Database::connection()->prepare('DELETE FROM vaultcast_documents WHERE name=?');$delete->execute(['hub-cinema:'.$oldId]);
            }
            $all=array_filter($all,static function(array $r): bool {return ($r['expires_at'] ?? 0)>time();});
            if (count($all)>=30) throw new DomainException('There are already 30 cinema rooms.');
            \Vaultcast\Repository::putDocument('hub-cinema:'.$id,$room);
            $all[$id]=array_intersect_key($room,array_flip(['id','item_id','title','host','expires_at']));return $all;
        });
        return ['ok'=>true,'room'=>$room];
    }
    public static function room(string $id,array $user): array {
        if (!preg_match('/^[a-f0-9]{16}$/D',$id)) throw new DomainException('Invalid room.');
        $room=\Vaultcast\Repository::document('hub-cinema:'.$id,[]);
        if (($room['expires_at'] ?? 0)<time()) throw new DomainException('This cinema has ended.');
        $item=Catalogue::item($room['item_id']); if (!$item || !self::visible($item,$user)) throw new DomainException('This room is not available to your account.');
        $room['server_time']=microtime(true);
        $room['members']=array_values(array_filter($room['members'] ?? [],static function(array $m): bool {return $m['at']>time()-50;}));
        return $room;
    }
    public static function roomUpdate(string $id,array $user,array $body): array {
        self::room($id,$user);
        $host=false;$publish=false;
        $room=self::mutate('hub-cinema:'.$id,function(array $room) use ($user,$body,&$host,&$publish): array {
            if (($room['expires_at'] ?? 0)<time()) throw new DomainException('This cinema has ended.');
            $host=$room['host_id']===$user['id'];$now=microtime(true);
            $room['members']=array_filter($room['members'] ?? [],static function(array $m): bool {return $m['at']>time()-50;});
            if (count($room['members'])>=32 && !isset($room['members'][$user['id']])) throw new DomainException('This cinema already has 32 viewers.');
            $room['members'][$user['id']]=array_merge(self::profile($user),['at'=>time()]);
            $room['members']=array_filter($room['members'],static function(array $m): bool {return $m['at']>time()-50;});
            // Messages and membership heartbeats must never change playback,
            // including requests from another device signed into the host account.
            if ($host && ($body['action'] ?? '')==='playback' && empty($body['message']) && array_key_exists('position',$body)) {
                $position=(float)($body['position'] ?? $room['position']);
                if (!is_finite($position) || $position<0 || $position>172800) throw new DomainException('Invalid playback position.');
                $room['position']=$position;$room['playing']=!empty($body['playing']);$room['updated_at']=$now;$room['expires_at']=time()+120;
            }
            if ($host) {
                $room['expires_at']=!empty($body['end']) ? time()-1 : time()+120;
                if (!empty($body['end']) || time()-(int)($room['registry_at'] ?? 0)>=30) {$room['registry_at']=time();$publish=true;}
            }
            $text=trim((string)($body['message'] ?? ''));
            if ($text!=='') {
                if (mb_strlen($text)>400) throw new DomainException('Chat messages can be up to 400 characters.');
                $last=end($room['messages']);
                if ($last && ($last['user']['id'] ?? '')===$user['id'] && $now-$last['at']<1) throw new DomainException('Please wait a moment before sending another message.');
                $room['messages'][]=['user'=>self::profile($user),'text'=>$text,'at'=>$now];$room['messages']=array_slice($room['messages'],-60);
            }
            return $room;
        });
        if ($publish) self::mutate('hub-cinemas',static function(array $all) use ($id,$room): array {
            if ($room['expires_at']<time()) unset($all[$id]);
            else $all[$id]=array_intersect_key($room,array_flip(['id','item_id','title','host','expires_at']));return $all;
        });
        return ['ok'=>true];
    }
}
