<?php
declare(strict_types=1);

trait CompatibilityPersonal {
    private static function preference(string $key,$default=null){return self::$viewer['prefs'][$key] ?? $default;}
    private static function personalPost(string $view,array $body): ?array {
        $action=(string)($body['compat_action'] ?? '');$id=(string)($_GET['id'] ?? '');
        if($action==='account_options'){
            CompatibilityApi::call('/me/prefs','POST',['theme'=>(in_array($body['theme']??'',['dark','light','retro-scifi','steampunk','vintage-tv','after-hours'],true)?$body['theme']:'dark'),'password'=>(string)($body['password'] ?? ''),'hide_spoilers'=>!empty($body['hide_spoilers']),'language'=>mb_substr((string)($body['language'] ?? ''),0,20)]);
            return ['view'=>'account'];
        }
        if($action==='home_options'){
            $labels=['recent','watchlist','friends','calendar'];$order=[];
            foreach($labels as $key)if(!empty($body['panels'][$key]))$order[$key]=(int)($body['ranks'][$key] ?? 0);
            asort($order,SORT_NUMERIC);$order=array_keys($order);
            foreach((array)self::preference('home_panels',[]) as $key)if(in_array($key,['games','stats'],true))$order[]=$key;
            $landing=(string)($body['home_landing'] ?? 'overview');if(!in_array($landing,['overview','watchlist','favourites','calendar','arrivals','lists','friends'],true))$landing='overview';
            CompatibilityApi::call('/me/prefs','POST',['home_panels'=>$order,'home_compact'=>!empty($body['home_compact']),'home_landing'=>$landing,'hide_spoilers'=>!empty($body['hide_spoilers'])]);return ['view'=>'home','overview'=>'1'];
        }
        if($action==='priority'){
            $order=HomeExtras::state(self::$viewer)['priorities'];$key=(string)($body['key'] ?? '');HomeHub::entity($key,self::$viewer);
            $order=array_values(array_diff($order,[$key]));array_unshift($order,$key);HomeExtras::change(self::$viewer,['action'=>'priority','keys'=>array_slice($order,0,300)]);return ['view'=>'watchlist'];
        }
        if($action==='report'){
            $target=self::target($id);CompatibilityApi::call('/reports/label','POST',$target+['note'=>(string)($body['note'] ?? '')]);return ['view'=>'report','id'=>$id,'sent'=>'1'];
        }
        if($action==='chat_manage'){
            $change=(string)($body['change'] ?? '');if(!in_array($change,['edit','mute','leave','settings','kick','delete-room'],true))throw new RuntimeException('Unknown chat action.');
            Chat::change(self::$viewer,$id,['action'=>$change,'id'=>(int)($body['message_id'] ?? 0),'text'=>(string)($body['text'] ?? ''),'muted'=>!empty($body['muted']),'title'=>(string)($body['title'] ?? ''),'retention'=>(int)($body['retention'] ?? 86400),'user_id'=>(string)($body['user_id'] ?? '')]);
            return ['view'=>($_GET['room_view'] ?? '')==='inbox'?'inbox':'chat','id'=>in_array($change,['leave','delete-room'],true)?'':$id];
        }
        if($action==='dismiss'){HomeHub::dismiss(self::$viewer,(string)($body['notice_id'] ?? ''));return ['view'=>'invitations'];}
        return null;
    }
    private static function target(string $key): array {
        if(!preg_match('/^(item|show):([a-f0-9]{8,64})$/D',$key,$match))throw new RuntimeException('Choose a library title.');
        self::allow('library');HomeHub::entity($key,self::$viewer);
        return [$match[1]==='show'?'show_key':'item_id'=>$match[2]];
    }
    private static function accountOptions(): string {
        return self::beginForm(['view'=>'account'],'account_options').'<h3>Appearance and security</h3>'.self::select('Theme','theme',['dark'=>'Dark Neon','retro-scifi'=>'Retro Sci-Fi','steampunk'=>'Steampunk','vintage-tv'=>'Vintage TV Retro','after-hours'=>'Each day we stray further from god... (adult)','light'=>'Light'],(string)self::preference('theme','dark')).self::field('Preferred language','language',(string)self::preference('language','en'),'text',20).'<label class="compat-check"><input type="checkbox" name="hide_spoilers" value="1"'.(self::preference('hide_spoilers',false)?' checked="checked"':'').'> Hide unwatched episode spoilers</label>'.self::field('New password · leave blank to keep current password','password','','password',72).'<button type="submit">Save appearance / password</button></form>'.self::link('Customise Home',['view'=>'home-options']);
    }
    private static function homeOptions(): string {
        $labels=['recent'=>'Continue watching','watchlist'=>'Watchlist','friends'=>'Friends','calendar'=>'Release calendar'];$order=(array)self::preference('home_panels',array_keys($labels));
        $html=self::heading('YOUR SPACE','Customise Home').self::beginForm(['view'=>'home-options'],'home_options');
        foreach($labels as $key=>$label){$rank=array_search($key,$order,true);$html.='<div class="compat-panel"><label class="compat-check"><input type="checkbox" name="panels['.$key.']" value="1"'.($rank!==false?' checked="checked"':'').'> '.self::esc($label).'</label>'.self::field('Order (lowest first)','ranks['.$key.']',(string)($rank===false?9:$rank+1),'number',2).'</div>';}
        $html.=self::select('Open Home on','home_landing',['overview'=>'Overview','watchlist'=>'Watchlist','favourites'=>'Favourites','calendar'=>'Release calendar','arrivals'=>'New arrivals','lists'=>'Custom lists','friends'=>'Friends'],(string)self::preference('home_landing','overview'));
        foreach(['home_compact'=>'Compact Home','hide_spoilers'=>'Hide unwatched episode spoilers'] as $key=>$label)$html.='<label class="compat-check"><input type="checkbox" name="'.$key.'" value="1"'.(self::preference($key,false)?' checked="checked"':'').'> '.$label.'</label>';
        return $html.'<button type="submit">Save Home</button></form>';
    }
    private static function personalPage(string $view,string $id,int $page,string $query): ?string {
        if(in_array($view,['chat-options','chat-confirm','edit-message'],true)){
            if(!self::$signed)throw new RuntimeException('Sign in to manage conversations.');$roomView=($_GET['room_view'] ?? '')==='inbox'?'inbox':'chat';
            if($view==='chat-options')return self::chatOptions($roomView,$id);
            if($view==='chat-confirm'){$change=(string)($_GET['change'] ?? 'leave');if(!in_array($change,['leave','delete-room'],true))throw new RuntimeException('Unknown action.');Chat::history(self::$viewer,$id,-1,0);return self::heading('CONVERSATION',$change==='leave'?'Leave conversation?':'Delete this room and its messages?').self::form(['view'=>'chat-options','id'=>$id,'room_view'=>$roomView],'Confirm','chat_manage',['change'=>$change]).self::link('Cancel',['view'=>$roomView,'id'=>$id]);}
            $messageId=(int)($_GET['message_id'] ?? 0);$data=Chat::history(self::$viewer,$id,-1,$messageId+1);$message=null;foreach($data['messages'] as $row)if($row['id']===$messageId&&$row['sender_id']===self::$viewer['id'])$message=$row;if(!$message)throw new RuntimeException('Message unavailable.');
            return self::heading('CONVERSATION','Edit message').self::beginForm(['view'=>'chat-options','id'=>$id,'room_view'=>$roomView],'chat_manage',false,['change'=>'edit','message_id'=>$messageId]).'<label>Message<textarea name="text" rows="5" maxlength="4000">'.self::esc($message['body']).'</textarea></label><button type="submit">Save message</button></form>';
        }
        if($view==='report'){
            self::target($id);$html=self::heading('LIBRARY','Report a problem');if(!empty($_GET['sent']))return $html.'<p class="compat-success">Report sent to the administrator.</p>';
            return $html.self::beginForm(['view'=>'report','id'=>$id],'report').'<label>What is wrong?<textarea name="note" rows="5" maxlength="500"></textarea></label><button type="submit">Send report</button></form>';
        }
        if($view==='home-options'){if(!self::$signed)throw new RuntimeException('Sign in to customise Home.');return self::homeOptions();}
        if($view==='history'){
            if(!self::$signed)throw new RuntimeException('Sign in to view history.');self::allow('library');
            $q=\Vaultcast\Database::connection()->prepare("SELECT i.payload,p.payload AS progress FROM vaultcast_progress p JOIN vaultcast_items i ON i.id=p.item_id WHERE p.user_id=? AND i.media_kind='video' ORDER BY p.updated_at DESC,i.id LIMIT 17 OFFSET ".(($page-1)*16));$q->execute([self::$viewer['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=[];
            foreach(array_slice($rows,0,16) as $row){$card=Catalogue::publicItem(json_decode($row['payload'],true),false);$card['progress']=json_decode($row['progress'],true);$items[]=$card;}
            return self::heading('YOUR SPACE','Watch history').self::cards($items).self::pages(['view'=>'history'],$page,count($rows)>16);
        }
        if($view==='arrivals'){
            self::allow('library');$followed=self::$signed && ($_GET['scope'] ?? '')==='following';$html=self::heading('FRESH IN YOUR LIBRARY','New arrivals').self::link('All new arrivals',['view'=>'arrivals']).(self::$signed?self::link('Unwatched episodes from favourites',['view'=>'arrivals','scope'=>'following']):'');
            if($followed){$items=HomeExtras::arrivals(self::$viewer)['items'];return $html.self::cards(array_slice($items,($page-1)*16,16)).self::pages(['view'=>'arrivals','scope'=>'following'],$page,$page*16<count($items));}
            $q=\Vaultcast\Database::connection()->query("SELECT payload FROM vaultcast_items WHERE media_kind='video' ORDER BY added_at DESC,id LIMIT 17 OFFSET ".(($page-1)*16));$rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=array_map(static function($row){return Catalogue::publicItem(json_decode($row['payload'],true),false);},array_slice($rows,0,16));
            return $html.self::cards($items).self::pages(['view'=>'arrivals'],$page,count($rows)>16);
        }
        if($view==='tonight'){
            self::allow('library');$minutes=max(5,min(300,(int)($_GET['minutes'] ?? 90)));$html=self::heading('LESS SCROLLING. MORE WATCHING.','Find your next watch');
            $html.='<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="tonight">'.self::field('Available minutes','minutes',(string)$minutes,'number',3).'<button type="submit">Fit my time</button><button type="submit" name="random" value="1">Pick something for me</button><button type="submit" name="watchlist_random" value="1">Pick from my watchlist</button></form>';
            if(!empty($_GET['watchlist_random'])){$items=[];foreach(self::personal()['watchlist'] ?? [] as $entry)if(AreaAccess::itemArea($entry['card'] ?? [])==='library')$items[]=$entry['card'];return $html.self::cards($items?[$items[random_int(0,count($items)-1)]]:[]);}
            $items=HomeExtras::tonight(self::$viewer,$minutes)['items'];if(!empty($_GET['random']) && $items)$items=[$items[random_int(0,count($items)-1)]];
            return $html.self::cards($items);
        }
        if($view==='invitations'){
            if(!self::$signed)throw new RuntimeException('Sign in to see invitations.');$html=self::heading('YOUR PEOPLE','Invitations');$rows=HomeHub::inbox(self::$viewer)['notifications'];
            foreach($rows as $notice){if(($notice['kind'] ?? '')==='game')continue;$room=basename((string)$notice['path']);$html.='<div class="compat-panel"><strong>'.self::esc($notice['from']['name']).'</strong><p>'.self::esc($notice['title']).'</p>'.self::link('Join film night',['view'=>'cinema','id'=>$room]).self::form(['view'=>'invitations'],'Dismiss','dismiss',['notice_id'=>$notice['id']]).'</div>';}
            return $html.(!$rows?'<p>No invitations.</p>':'');
        }
        return null;
    }
    private static function watchlistPage(int $page,string $query): string {
        if(!self::$signed)throw new RuntimeException('Sign in to view your watchlist.');self::allow('library');
        $rows=array_values(array_filter(self::personal()['watchlist'] ?? [],static function($row){return AreaAccess::itemArea($row['card'] ?? [])==='library';}));
        $filter=(string)($_GET['status'] ?? 'all');$sort=(string)($_GET['sort'] ?? 'priority');$items=array_column($rows,'card');$ids=[];$shows=[];
        foreach($items as $card){if(self::isShow($card))$shows[]=[$card['section'],$card['title']];else $ids[]=$card['id'];}
        $progress=[];$conditions=[];$args=[self::$viewer['id']];
        if($ids){$conditions[]='i.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=array_merge($args,$ids);}
        foreach($shows as $show){$conditions[]='(i.section_id=? AND i.show_title=?)';$args=array_merge($args,$show);}
        if($conditions){$sql='SELECT i.id,i.section_id,i.show_title,p.payload FROM vaultcast_progress p JOIN vaultcast_items i ON i.id=p.item_id WHERE p.user_id=? AND ('.implode(' OR ',$conditions).')';$q=\Vaultcast\Database::connection()->prepare($sql);$q->execute($args);foreach($q as $row){$key=$row['show_title']!==''?'show:'.Catalogue::showKey($row['section_id'],$row['show_title']):'item:'.$row['id'];$p=json_decode($row['payload'],true);$finished=!empty($p['watched'])||Catalogue::progressFinished($p,$row['show_title']!=='');$progress[$key]['seen']=($progress[$key]['seen'] ?? 0)+($finished?1:0);$progress[$key]['position']=max((float)($progress[$key]['position'] ?? 0),(float)($p['position'] ?? 0));if(!$row['show_title'])$progress[$key]['progress']=$p;}}
        $order=HomeExtras::state(self::$viewer)['priorities'];$rank=array_flip($order);$filtered=[];
        foreach($items as $card){$key=(self::isShow($card)?'show:':'item:').($card['show_key'] ?? $card['id']);$p=$progress[$key] ?? [];$total=self::isShow($card)?(int)($card['available_count'] ?? $card['episode_count'] ?? 0):1;$status=$total>0&&($p['seen'] ?? 0)>=$total?'watched':((($p['seen'] ?? 0)>0||($p['position'] ?? 0)>0)?'progress':'unwatched');if(($query!==''&&mb_stripos($card['title'],$query)===false)||($filter!=='all'&&$filter!==$status))continue;$card['_key']=$key;$card['_status']=$status;$card['progress']=$p['progress'] ?? [];$filtered[]=$card;}
        usort($filtered,static function($a,$b)use($sort,$rank){return $sort==='title'?strcasecmp($a['title'],$b['title']):(($rank[$a['_key']] ?? 999)<=>($rank[$b['_key']] ?? 999));});
        $html=self::heading('YOUR SPACE','Watchlist').'<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="watchlist">'.self::field('Filter titles','q',$query).self::select('Watch status','status',['all'=>'All','unwatched'=>'Not started','progress'=>'In progress','watched'=>'Watched'],$filter).self::select('Order','sort',['priority'=>'My priority','title'=>'Title A–Z'],$sort).'<button type="submit">Apply filters</button></form>';
        foreach(array_slice($filtered,($page-1)*16,16) as $card)$html.='<div class="compat-panel">'.self::cards([$card]).'<p>'.self::esc(ucfirst($card['_status'])).'</p>'.self::form(['view'=>'watchlist'],'Watch next · move to top','priority',['key'=>$card['_key']]).self::form(['view'=>'watchlist'],'Remove from watchlist','shelf',['key'=>$card['_key'],'shelf'=>'watchlist','enabled'=>'0']).'</div>';
        return $html.self::pages(['view'=>'watchlist','q'=>$query,'sort'=>$sort,'status'=>$filter],$page,$page*16<count($filtered));
    }
    private static function chatOptions(string $view,string $id): string {
        $data=Chat::history(self::$viewer,$id,-1,0);$room=$data['room'];$params=['view'=>'chat-options','id'=>$id,'room_view'=>$view];$html=self::heading('CONVERSATION',(string)$room['title']).self::link('Back to messages',['view'=>$view,'id'=>$id]);
        $member=Chat::listing(self::$viewer,$view==='inbox',$id);$muted=false;foreach($member['rooms'] as $row)if($row['id']===$id)$muted=!empty($row['muted']);
        $html.=self::form($params,$muted?'Unmute':'Mute notifications','chat_manage',['change'=>'mute','muted'=>$muted?'0':'1']);
        if($room['can_manage'] && !in_array($room['kind'],['dm','mail'],true))$html.=self::beginForm($params,'chat_manage',false,['change'=>'settings']).'<h3>Room settings</h3>'.self::field('Name','title',$room['title']).self::select('Message expiry','retention',['0'=>'Keep until deleted','3600'=>'1 hour','21600'=>'6 hours','86400'=>'24 hours','604800'=>'7 days','2592000'=>'30 days'],(string)$room['retention']).'<button type="submit">Save room settings</button></form>';
        $html.='<h3>Members</h3><ul class="compat-list">';foreach($data['members'] as $person){$html.='<li>'.self::link($person['name'].(!empty($person['online'])?' · Online':''),['view'=>'profile','id'=>$person['id']]);if($room['can_manage'] && $id!=='general' && !in_array($room['kind'],['dm','mail'],true) && $person['id']!==self::$viewer['id'])$html.=self::form($params,'Remove member','chat_manage',['change'=>'kick','user_id'=>$person['id']]);$html.='</li>';}$html.='</ul>';
        if($id!=='general')$html.=self::link($room['can_manage']&&!in_array($room['kind'],['dm','mail'],true)?'Delete room':'Leave conversation',['view'=>'chat-confirm','id'=>$id,'room_view'=>$view,'change'=>$room['can_manage']&&!in_array($room['kind'],['dm','mail'],true)?'delete-room':'leave']);
        return $html;
    }
}
