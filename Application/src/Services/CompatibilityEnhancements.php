<?php
declare(strict_types=1);

trait CompatibilityEnhancements {
    private static function beginForm(array $params,string $action,bool $upload=false,array $hidden=[]): string {
        $html='<form class="compat-panel compat-fields" method="post" action="'.self::esc(self::url($params)).'"'.($upload?' enctype="multipart/form-data"':'').'><input type="hidden" name="compat_action" value="'.self::esc($action).'"><input type="hidden" name="compat_token" value="'.self::esc(self::token()).'">';
        foreach($hidden as $key=>$value)$html.='<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';return $html;
    }
    private static function field(string $label,string $name,string $value='',string $type='text',int $max=100): string {return '<label>'.self::esc($label).'<input type="'.self::esc($type).'" name="'.self::esc($name).'" value="'.self::esc($value).'" maxlength="'.$max.'"'.($type==='number'?' step="any"':'').'></label>';}
    private static function select(string $label,string $name,array $choices,string $value): string {
        $html='<label>'.self::esc($label).'<select name="'.self::esc($name).'">';foreach($choices as $key=>$text)$html.='<option value="'.self::esc($key).'"'.((string)$key===$value?' selected="selected"':'').'>'.self::esc($text).'</option>';return $html.'</select></label>';
    }
    private static function account(): string {
        $user=self::$viewer;$prefs=$user['prefs'] ?? [];$html=self::heading('YOUR ACCOUNT',($user['display_name'] ?? '') ?: $user['username']);$avatar=Profiles::avatarUrl($user);
        if($avatar)$html.='<img class="compat-avatar" src="'.self::esc($avatar).'" alt="Your avatar" width="72" height="72">';
        $html.=self::beginForm(['view'=>'account'],'profile',true).'<h3>Profile and playback</h3>'.self::field('Display name','display_name',($user['display_name'] ?? '') ?: $user['username']);
        $html.='<label>Avatar · JPEG or PNG, up to 1 MiB<input type="file" name="avatar" accept="image/jpeg,image/png"></label><label class="compat-check"><input type="checkbox" name="remove_avatar" value="1"> Remove current avatar</label>';
        foreach(['autoplay_on_open'=>'Automatically start videos when opened','autoplay_next'=>'Automatically play the next episode / collection film','reduce_motion'=>'Reduce motion','skip_segments'=>'Offer TV / anime intro and credits skipping, and movie credits (TheIntroDB)','auto_skip_intro'=>'Automatically skip TV / anime intros','auto_skip_outro'=>'Automatically skip TV / anime and movie credits'] as $key=>$label)$html.='<label class="compat-check"><input type="checkbox" name="'.$key.'" value="1"'.((in_array($key,['reduce_motion','auto_skip_intro','auto_skip_outro'],true)?!empty($prefs[$key]):($prefs[$key] ?? true)!==false)?' checked="checked"':'').'> '.$label.'</label>';
        $html.=self::select('Preferred subtitles','subtitle_language',[''=>'Off','en'=>'English','es'=>'Spanish','fr'=>'French','de'=>'German','it'=>'Italian','pt'=>'Portuguese','ja'=>'Japanese','ko'=>'Korean','zh'=>'Chinese'],(string)($prefs['subtitle_language'] ?? '')).'<button type="submit">Save account settings</button></form>';
        return $html.self::link('Tools and settings',['view'=>'tools']).self::accountOptions().self::link('Friends',['view'=>'friends']).self::link('Inbox',['view'=>'inbox']).self::link('Live chat',['view'=>'chat']).self::form([],'Sign out','logout');
    }
    private static function uploaded(string $name,int $limit): ?array {
        $file=$_FILES[$name] ?? null;if(!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
        if(($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? '')) || (int)$file['size']<1 || (int)$file['size']>$limit)throw new RuntimeException('The image upload failed or exceeded the size limit.');return $file;
    }
    private static function enhancedPost(string $view,array $body): array {
        $action=(string)($body['compat_action'] ?? '');$user=self::$viewer;$id=(string)($_GET['id'] ?? '');
        $viewing=self::viewingPost($view,$body);if($viewing!==null)return $viewing;
        $admin=self::administrationPost($view,$body);if($admin!==null)return $admin;
        $tool=self::toolPost($view,$body);if($tool!==null)return $tool;
        $personal=self::personalPost($view,$body);if($personal!==null)return $personal;
        $library=self::libraryPost($view,$body);if($library!==null)return $library;
        if($action==='profile'){
            $name=trim((string)($body['display_name'] ?? ''));if($name==='' || mb_strlen($name)>100)throw new RuntimeException('Use a display name of 1–100 characters.');
            $avatarBody=['remove_avatar'=>!empty($body['remove_avatar'])];$file=self::uploaded('avatar',1048576);
            if($file){$info=@getimagesize($file['tmp_name']);if(!$info || !in_array($info['mime'],['image/jpeg','image/png'],true))throw new RuntimeException('Choose a JPEG or PNG avatar.');$avatarBody['avatar_data']='data:'.$info['mime'].';base64,'.base64_encode((string)file_get_contents($file['tmp_name']));}
            $language=(string)($body['subtitle_language'] ?? '');if(!in_array($language,['','en','es','fr','de','it','pt','ja','ko','zh'],true))throw new RuntimeException('Choose a subtitle language.');$avatar=Profiles::saveAvatar($user,$avatarBody);
            \Vaultcast\Repository::updateUser($user['id'],static function(array $record)use($name,$avatar,$body,$language): array{$record['display_name']=$name;$record['avatar_version']=$avatar;$record['prefs']['autoplay_on_open']=!empty($body['autoplay_on_open']);$record['prefs']['autoplay_next']=!empty($body['autoplay_next']);$record['prefs']['skip_segments']=!empty($body['skip_segments']);$record['prefs']['auto_skip_intro']=!empty($body['auto_skip_intro']);$record['prefs']['auto_skip_outro']=!empty($body['auto_skip_outro']);$record['prefs']['autoplay']=$record['prefs']['autoplay_on_open'];$record['prefs']['reduce_motion']=!empty($body['reduce_motion']);$record['prefs']['subtitle_language']=$language;return $record;});return ['view'=>'account'];
        }
        if($action==='friend'){HomeHub::friendship($user,['user_id'=>(string)($body['user_id'] ?? ''),'action'=>(string)($body['friend_action'] ?? 'request')]);return ['view'=>'profile','id'=>(string)$body['user_id']];}
        if($action==='chat_create'){
            $kind=(string)($body['kind'] ?? 'mail');$text=trim((string)($body['text'] ?? ''));if($kind==='mail' && ($text==='' || mb_strlen($text)>4000))throw new RuntimeException('Write a message of 1–4,000 characters.');$result=Chat::create($user,['kind'=>$kind,'peer_id'=>(string)($body['peer_id'] ?? ''),'title'=>(string)($body['title'] ?? ''),'retention'=>(int)($body['retention'] ?? 86400),'nonce'=>(string)($body['nonce'] ?? '')]);
            if($kind==='mail')Chat::change($user,$result['id'],['action'=>'send','text'=>$text,'nonce'=>substr(hash('sha256',(string)($body['nonce'] ?? '').'|message'),0,32)]);
            return ['view'=>$kind==='mail'?'inbox':'chat','id'=>$result['id']];
        }
        if($action==='chat_invite'){Chat::change($user,$id,['action'=>'invite','user_id'=>(string)($body['user_id'] ?? '')]);return ['view'=>'chat','id'=>$id];}
        if($action==='chat_join'){Chat::join($user,$id);return ['view'=>'chat','id'=>$id];}
        if($action==='chat_send'){
            $file=self::uploaded('message_image',4194304);$attachment=$file?Chat::uploadedImage($user,$id,$file):'';
            Chat::change($user,$id,['action'=>'send','text'=>(string)($body['text'] ?? ''),'attachment'=>$attachment,'nonce'=>(string)($body['nonce'] ?? '')]);return ['view'=>$view==='inbox'?'inbox':'chat','id'=>$id];
        }
        if($action==='chat_delete'){Chat::change($user,$id,['action'=>'delete','id'=>(int)($body['message_id'] ?? 0)]);return ['view'=>$view==='inbox'?'inbox':'chat','id'=>$id];}
        throw new RuntimeException('Unknown form action.');
    }
    private static function friends(string $query): string {
        $html=self::heading('YOUR PEOPLE','Friends','Private messages and direct chats are available between accepted friends.').self::searchForm(['view'=>'friends'],$query);$personal=self::personal();$ids=array_keys($personal['friends'] ?? []);$people=[];
        if($query!=='')$people=HomeHub::people(self::$viewer,$query)['people'];
        elseif($ids){$q=\Vaultcast\Database::connection()->prepare('SELECT payload FROM vaultcast_users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY username');$q->execute($ids);foreach($q as $row)$people[]=Chat::identity(json_decode($row['payload'],true));}
        $html.='<ul class="compat-list">';foreach($people as $person)$html.='<li><a href="'.self::esc(self::url(['view'=>'profile','id'=>$person['id']])).'"><strong>'.self::esc($person['name']).'</strong></a><span class="compat-note"> · '.self::esc($personal['friends'][$person['id']] ?? 'Not connected').'</span></li>';
        return $html.($people?'':'<li>Search for a username to find friends.</li>').'</ul>';
    }
    private static function profile(string $id): string {
        $data=Chat::profile(self::$viewer,$id);$person=$data['profile'];$html=self::heading('PROFILE',(string)$person['name'],'@'.$person['username']);if($person['avatar_url'])$html.='<img class="compat-avatar" src="'.self::esc($person['avatar_url']).'" width="72" height="72" alt="Profile avatar">';
        if($data['full'])$html.='<p>'.(int)$data['stats']['games'].' games played · '.(int)floor($data['stats']['seconds']/60).' minutes played</p>';else $html.='<p class="compat-note">Full profiles are visible only to accepted friends.</p>';
        $relation=$data['relationship'];if($relation==='friend'){
            $html.=self::link('Send private message',['view'=>'compose','id'=>$id]);$html.=self::beginForm(['view'=>'profile','id'=>$id],'chat_create',false,['kind'=>'dm','peer_id'=>$id,'nonce'=>bin2hex(random_bytes(16))]).'<button type="submit">Open direct chat</button></form>';
        }
        if($relation!=='self'){$friendAction=$relation==='incoming'?'accept':($relation==='none'?'request':'remove');$html.=self::form(['view'=>'profile','id'=>$id],['accept'=>'Accept friend request','request'=>'Add friend','remove'=>$relation==='friend'?'Remove friend':'Cancel / decline request'][$friendAction],'friend',['user_id'=>$id,'friend_action'=>$friendAction]);}return $html;
    }
    private static function messages(string $view,string $id): string {
        Chat::pulse(self::$viewer);$mail=$view==='inbox';$list=Chat::listing(self::$viewer,$mail,$id);$html=self::heading($mail?'PRIVATE MESSAGES':'YOUR COMMUNITY',$mail?'Inbox':'Live chat').'<div class="compat-actions">'.self::link('Inbox',['view'=>'inbox']).self::link('Live chat',['view'=>'chat']).self::link('New message',['view'=>'compose']).self::link('Friends',['view'=>'friends']).(!$mail?self::link('Create room',['view'=>'create-room']):'').'</div>';
        if($id===''){$html.='<ul class="compat-list">';foreach($list['rooms'] as $room){$title=$room['title'];if($room['kind']==='dm')foreach($list['friends'] as $person)if($person['id']===($room['owner_id']===self::$viewer['id']?$room['peer_id']:$room['owner_id']))$title=$person['name'];$html.='<li>'.self::link($title.(!empty($room['unread'])?' · '.$room['unread'].' unread':''),['view'=>$view,'id'=>$room['id']]).'</li>';}return $html.($list['rooms']?'':'<li>No conversations yet.</li>').'</ul>';}
        foreach($list['rooms'] as $room)if($room['id']===$id && empty($room['joined']))return $html.self::heading('ROOM',(string)$room['title']).self::form(['view'=>'chat','id'=>$id],'Join room','chat_join');
        $before=max(0,(int)($_GET['before'] ?? 0));$data=Chat::history(self::$viewer,$id,-1,$before);$html.=self::link('Conversation options',['view'=>'chat-options','id'=>$id,'room_view'=>$view]);$html.='<h3>'.self::esc($data['room']['title']).'</h3><div class="compat-actions">'.self::link('Conversation options',['view'=>'chat-options','id'=>$id,'room_view'=>$view]).self::link('Latest messages',['view'=>$view,'id'=>$id]).self::link('Refresh',['view'=>$view,'id'=>$id]);
        $auto=($_GET['refresh'] ?? '')==='1';$html.=self::link($auto?'Disable auto-refresh':'Auto-refresh every 20 seconds',['view'=>$view,'id'=>$id,'refresh'=>$auto?'0':'1']).'</div>';
        if($auto && !$before)$html.='<p id="compat-refresh" data-url="'.self::esc(self::url(['view'=>$view,'id'=>$id,'refresh'=>'1'])).'" class="compat-note">Auto-refresh pauses while you write.</p>';
        if($data['has_more'] && $data['messages'])$html.=self::link('Older messages',['view'=>$view,'id'=>$id,'before'=>$data['messages'][0]['id']]);
        $html.='<p id="compat-chat-status" class="compat-note" role="status"></p><div id="compat-chat-messages" class="compat-messages">';foreach($data['messages'] as $message){$html.='<div class="compat-message'.($message['sender_id']===self::$viewer['id']?' mine':'').'"><p><a href="'.self::esc(self::url(['view'=>'profile','id'=>$message['author']['id']])).'">'.self::esc($message['author']['name']).'</a> <small>'.self::esc(gmdate('d M H:i',(int)$message['created_at'])).' UTC</small></p><p class="compat-message-text">'.self::esc($message['body']).'</p>';
            if($message['attachment'])$html.='<a href="'.self::esc(self::$base.'api/hub/chat/files/'.$message['attachment']).'"><img class="compat-chat-image" src="'.self::esc(self::$base.'api/hub/chat/files/'.$message['attachment']).'" alt="Shared image or GIF" loading="lazy"></a>';
            if($message['sender_id']===self::$viewer['id'])$html.=self::link('Edit',['view'=>'edit-message','room_view'=>$view,'id'=>$id,'message_id'=>$message['id']]);
            if($message['sender_id']===self::$viewer['id'] || $data['room']['can_manage'])$html.=self::link('Delete message',['view'=>'delete-message','room_view'=>$view,'id'=>$id,'message_id'=>$message['id']]);$html.='</div>';
        }
        $html.='</div>'.str_replace('<form ','<form id="compat-chat-compose" ',self::beginForm(['view'=>$view,'id'=>$id],'chat_send',true,['nonce'=>bin2hex(random_bytes(16))])).'<label>Message<textarea name="text" rows="4" maxlength="4000"></textarea></label><label>Image / GIF · up to 4 MiB<input type="file" name="message_image" accept="image/jpeg,image/png,image/gif,image/webp"></label><button type="submit">Send message</button></form>';
        if(!$mail && $id!=='general' && $data['room']['can_manage'] && in_array($data['room']['kind'],['public','private'],true) && $list['friends']){$choices=[];foreach($list['friends'] as $friend)$choices[$friend['id']]=$friend['name'];$html.=self::beginForm(['view'=>'chat','id'=>$id],'chat_invite').self::select('Invite a friend','user_id',$choices,'').'<button type="submit">Invite friend</button></form>';}
        if(!$before)$html.='<script>window.__VC_COMPAT_CHAT__='.json_encode(['id'=>$id,'view'=>$view,'revision'=>$data['room']['revision'],'can_manage'=>!empty($data['room']['can_manage']),'user_id'=>self::$viewer['id']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
        if(!$before && $data['messages'])Chat::change(self::$viewer,$id,['action'=>'pulse','read_id'=>(int)end($data['messages'])['id']]);return $html;
    }
    private static function compose(string $id,bool $room=false): string {
        $html=self::heading($room?'COMMUNITY':'FRIENDS',$room?'Create room':'New private message');$choices=[];foreach(Chat::listing(self::$viewer,true)['friends'] as $friend)$choices[$friend['id']]=$friend['name'];
        if(!$room && !$choices)return $html.'<p>Add a friend before sending private messages.</p>'.self::link('Find friends',['view'=>'friends']);
        $html.=self::beginForm(['view'=>$room?'create-room':'compose'],'chat_create',false,['nonce'=>bin2hex(random_bytes(16))]);
        if($room)$html.=self::select('Visibility','kind',['public'=>'Public','private'=>'Invite only'],'public').self::select('Message expiry','retention',['3600'=>'1 hour','21600'=>'6 hours','86400'=>'24 hours','604800'=>'7 days','0'=>'Keep until deleted'],'86400');
        else $html.='<input type="hidden" name="kind" value="mail">'.self::select('To','peer_id',$choices,$id);
        return $html.self::field($room?'Room name':'Subject','title').(!$room?'<label>Message<textarea name="text" rows="6" maxlength="4000"></textarea></label>':'').'<button type="submit">'.($room?'Create room':'Send message').'</button></form>';
    }
    private static function browseSection(array $section,string $query,int $page): string {
        $id=(string)$section['id'];$shows=in_array($section['type'],['tv','anime'],true);$genre=mb_substr(trim((string)($_GET['genre'] ?? '')),0,80);$year=(string)($_GET['year'] ?? '');if(!preg_match('/^(18|19|20|21)[0-9]{2}$/D',$year))$year='';
        $letter=strtoupper((string)($_GET['letter'] ?? ''));if(!preg_match('/^[A-Z#]$/D',$letter))$letter='';$sort=(string)($_GET['sort'] ?? 'az');if(!in_array($sort,['az','za','newest','oldest'],true))$sort='az';
        $params=['view'=>'section','id'=>$id,'q'=>$query,'genre'=>$genre,'year'=>$year,'letter'=>$letter,'sort'=>$sort];$pack=$shows?Catalogue::browseCacheGet('facets_shows',$id):Catalogue::browseCacheGet('facets_movies',$id);$facets=$pack['facets'] ?? [];$genres=[''=>'All genres'];foreach(array_keys($facets['genres'] ?? []) as $name)$genres[(string)$name]=(string)$name;if($genre!=='')$genres[$genre]=$genre;
        if($shows && count($genres)===1)foreach(['Action','Adventure','Animation','Comedy','Crime','Drama','Fantasy','Horror','Mystery','Romance','Science-Fiction','Thriller'] as $name)$genres[$name]=$name;
        $html=self::heading('LIBRARY',(string)($section['label'] ?? $section['type']));$html.='<form class="compat-panel compat-filters" method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="section"><input type="hidden" name="id" value="'.self::esc($id).'">'.self::field('Search','q',$query,'text',200).self::select('Genre','genre',$genres,$genre).self::field('Year','year',$year,'text',4).self::select('Sort','sort',['az'=>'Title A–Z','za'=>'Title Z–A','newest'=>'Newest year','oldest'=>'Oldest year'],$sort).'<button type="submit">Apply filters</button>'.self::link('Clear',['view'=>'section','id'=>$id]).'</form><div class="compat-alphabet">';
        foreach(array_merge([''=>'All','#'=>'#'],array_combine(range('A','Z'),range('A','Z'))) as $key=>$label)$html.='<a'.($letter===(string)$key?' class="active" aria-current="true"':'').' href="'.self::esc(self::url(['letter'=>$key,'page'=>1]+$params)).'">'.$label.'</a>';$html.='</div>';
        if($shows && !BrowseIndex::ready()){
            $rows=array_values(array_filter(BrowseIndex::shows($id),static function($row)use($query,$genre,$year,$letter){$title=(string)$row['title'];return ($query===''||mb_stripos($title,$query)!==false)&&($year===''||(int)($row['year'] ?? 0)===(int)$year)&&($genre===''||in_array($genre,$row['genres'] ?? [],true))&&($letter===''||($letter==='#'?!preg_match('/^[a-z]/i',$title):mb_strtoupper(mb_substr($title,0,1))===$letter));}));
            usort($rows,static function($a,$b)use($sort){return ($sort==='newest'?((int)($b['year'] ?? 0)<=>(int)($a['year'] ?? 0)):($sort==='oldest'?((int)($a['year'] ?? 0)<=>(int)($b['year'] ?? 0)):0)) ?: ($sort==='za'?strcasecmp($b['title'],$a['title']):strcasecmp($a['title'],$b['title']));});$more=$page*16<count($rows);$items=array_slice($rows,($page-1)*16,16);
        }else{
            $table=$shows?'vaultcast_show_summaries':'vaultcast_items';$where='section_id=?';$args=[$id];$yearExpr="CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.year')) AS UNSIGNED)";
            if($query!==''){$where.=" AND title LIKE ? ESCAPE '!'";$args[]='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
            if($year!==''){$where.=' AND '.$yearExpr.'=?';$args[]=(int)$year;}
            if($letter!==''){if($letter==='#')$where.=" AND title NOT REGEXP '^[A-Za-z]'";else{$where.=" AND title LIKE ?";$args[]=$letter.'%';}}
            if($genre!==''){
                if($shows){$where.=" AND JSON_CONTAINS(COALESCE(JSON_EXTRACT(payload,'$.genres'),'[]'),?)";$args[]=json_encode($genre);}
                else{
                    if(!isset($pack['genres_by_id'])){Catalogue::movieFacetsForBrowse($id);return $html.'<p class="compat-note">Genre metadata is being prepared. Refresh shortly, or clear the genre to browse immediately.</p>';}
                    $ids=[];foreach($pack['genres_by_id'] as $itemId=>$names)if(in_array($genre,$names,true))$ids[]=$itemId;if(!$ids)return $html.'<p class="compat-empty">No titles match these filters.</p>';
                    $where.=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=array_merge($args,$ids);
                }
            }
            $order=['az'=>'title ASC','za'=>'title DESC','newest'=>$yearExpr.' DESC,title ASC','oldest'=>'(COALESCE('.$yearExpr.',0)=0) ASC,'.$yearExpr.' ASC,title ASC'][$sort];$tie=$shows?'show_key':'id';$q=\Vaultcast\Database::connection()->prepare('SELECT payload FROM '.$table.' WHERE '.$where.' ORDER BY '.$order.','.$tie.' LIMIT 17 OFFSET '.(($page-1)*16));$q->execute($args);$rows=[];foreach($q as $row)$rows[]=json_decode($row['payload'],true);$more=count($rows)>16;$items=array_slice($rows,0,16);
        }
        if($shows)foreach($items as &$item){$item['kind']='show';$item['media_kind']='show';}unset($item);
        if(!$shows && count($genres)===1){Catalogue::movieFacetsForBrowse($id);$html.='<p class="compat-note">Genre choices appear after the library metadata has been prepared.</p>';}
        return $html.self::cards($items).self::pages($params,$page,$more);
    }
    private static function nextEpisode(array $item): ?array {
        if(empty($item['show']))return null;$next=PlaybackSequence::next(self::$viewer,(string)$item['id']);
        return $next?['item_id'=>$next['id'],'season'=>$next['season'],'episode'=>$next['episode']]:null;
    }
}
