<?php
declare(strict_types=1);

trait CompatibilityViewing {
    private static function viewingPost(string $view,array $body): ?array {
        $action=(string)($body['compat_action'] ?? '');$id=(string)($_GET['id'] ?? '');
        if($action==='cinema-create'){$target=self::target($id);if(empty($target['item_id']))throw new RuntimeException('Choose an episode or film.');$result=HomeHub::createRoom(self::$viewer,['item_id'=>$target['item_id']]);return ['view'=>'cinema','id'=>$result['room']['id']];}
        if($action==='cinema-message'){HomeHub::roomUpdate($id,self::$viewer,['message'=>(string)($body['message'] ?? '')]);return ['view'=>'cinema','id'=>$id];}
        if($action==='cinema-end'){HomeHub::roomUpdate($id,self::$viewer,['end'=>true]);return ['view'=>'cinema'];}
        if($action==='cinema-invite'){HomeHub::invite(self::$viewer,['kind'=>'cinema','room_id'=>$id,'user_id'=>(string)($body['user_id'] ?? '')]);return ['view'=>'cinema','id'=>$id];}
        if($action==='subtitle-upload'||$action==='subtitle-search'||$action==='subtitle-download'){
            self::allow('library');$item=Catalogue::item($id);if(!$item||($item['media_kind'] ?? '')!=='video')throw new RuntimeException('Choose a video.');
            if($action==='subtitle-upload'){
                if(!Subtitles::uploadAllowed(self::$viewer))throw new RuntimeException('Only administrators can upload subtitles.');$file=self::uploaded('subtitle',2097152);if(!$file)throw new RuntimeException('Choose a subtitle file.');$result=Subtitles::upload((string)$item['path'],(string)file_get_contents($file['tmp_name']),(string)($body['language'] ?? ''));
            }elseif($action==='subtitle-search'){
                if(!OpenSubtitles::configured()||!OpenSubtitles::allowed(self::$viewer))throw new RuntimeException('Subtitle search is unavailable for this account.');$result=OpenSubtitles::search($item,(string)($body['language'] ?? ''));if(empty($result['ok']))throw new RuntimeException($result['error'] ?? 'Subtitle search failed.');return self::keepResult('subtitles',$result,['item_id'=>$id]);
            }else{
                $saved=self::readResult((string)($body['result'] ?? ''));if(($saved['context']['item_id'] ?? '')!==$id)throw new RuntimeException('Search again for subtitles.');$row=($saved['result']['results'] ?? [])[(int)($body['index'] ?? -1)] ?? null;if(!$row||!OpenSubtitles::allowed(self::$viewer))throw new RuntimeException('Choose a subtitle result.');$result=OpenSubtitles::download($item,(int)$row['file_id'],(string)($row['language'] ?? ''));
            }
            if(empty($result['ok']))throw new RuntimeException($result['error'] ?? 'Could not save subtitles.');return ['view'=>'subtitles','id'=>$id,'saved'=>'1'];
        }
        if($action==='convert-request')return self::keepResult('conversion-request',CompatibilityApi::call('/convert/request','POST',['item_id'=>$id,'reason'=>'playback_failed','device'=>'Compatibility browser','format'=>(string)($body['format'] ?? '')]));
        return null;
    }
    private static function viewingPage(string $view,string $id): ?string {
        if($view==='subtitles'){
            if(!self::$signed)throw new RuntimeException('Sign in to manage subtitles.');self::allow('library');$item=Catalogue::item($id);if(!$item||($item['media_kind'] ?? '')!=='video')throw new RuntimeException('Choose a video.');$html=self::heading('PLAYBACK','Subtitles · '.(string)$item['title']).self::link('Back to video',['view'=>'item','id'=>$id,'paused'=>'1']);if(!empty($_GET['saved']))$html.='<p class="compat-success">Subtitles saved. Reopen the player to use them.</p>';
            if(Subtitles::uploadAllowed(self::$viewer))$html.=self::beginForm(['view'=>'subtitles','id'=>$id],'subtitle-upload',true).'<label>Subtitle file · SRT, VTT, ASS or SSA up to 2 MiB<input type="file" name="subtitle" accept=".srt,.vtt,.ass,.ssa"></label>'.self::field('Language code','language','en','text',12).'<button type="submit">Upload subtitles</button></form>';
            if(OpenSubtitles::configured()&&OpenSubtitles::allowed(self::$viewer))$html.=self::beginForm(['view'=>'subtitles','id'=>$id],'subtitle-search').self::field('Language code','language',(string)self::preference('subtitle_language','en'),'text',12).'<button type="submit">Search OpenSubtitles</button></form>';return $html;
        }
        if($view==='convert-request')return self::heading('PLAYBACK','Request a compatible video').self::beginForm(['view'=>'convert-request','id'=>$id],'convert-request').'<p>Ask the administrator to prepare a browser-compatible copy of this video.</p><button type="submit">Send conversion request</button></form>';
        if($view!=='cinema'&&$view!=='cinema-create')return null;
        if(!self::$signed)throw new RuntimeException('Sign in to watch together.');self::allow('library');
        if($view==='cinema-create'){$target=self::target($id);if(empty($target['item_id']))throw new RuntimeException('Choose an episode or film.');return self::heading('FILM NIGHT','Watch together').self::beginForm(['view'=>'cinema-create','id'=>$id],'cinema-create').'<p>You control playback. Friends can join with their own stream.</p><button type="submit">Start film night</button></form>';}
        if($id===''){$html=self::heading('YOUR PEOPLE','Watch together','Open a film or episode and choose Watch together to host a room.');foreach(HomeHub::rooms(self::$viewer)['rooms'] as $room)$html.='<div class="compat-panel"><h3>'.self::esc($room['title']).'</h3><p>Hosted by '.self::esc($room['host']['name']).'</p>'.self::link('Join',['view'=>'cinema','id'=>$room['id']]).'</div>';return $html;}
        $room=HomeHub::room($id,self::$viewer);HomeHub::roomUpdate($id,self::$viewer,[]);$host=$room['host_id']===self::$viewer['id'];$item=Catalogue::item($room['item_id']);$play=Stream::issueToken(self::$viewer,$item['id']);$html=self::heading('FILM NIGHT',(string)$room['title']);
        $html.=self::player($play['url'],false,$play['subtitles'] ?? [],['autoplay'=>false,'cinema'=>['id'=>$id,'host'=>$host,'position'=>$room['position']],'item_id'=>$item['id'],'save_progress'=>true]);
        $html.='<div class="compat-panel"><h3>In the room</h3><p id="compat-cinema-status" role="status">'.($host?'You control playback.':'Playback follows the host. Press Play if your browser requests it.').'</p><div id="compat-cinema-people">';
        foreach($room['members'] as $person)$html.='<span class="compat-person">'.(!empty($person['avatar_url'])?'<img class="compat-avatar" width="40" height="40" src="'.self::esc($person['avatar_url']).'" alt="">':'').self::esc($person['name']).'</span>';$html.='</div><div id="compat-cinema-messages">';
        foreach(array_slice($room['messages'] ?? [],-30) as $message)$html.='<p><strong>'.self::esc($message['name'] ?? $message['user']['name'] ?? '').'</strong> '.self::esc($message['text'] ?? '').'</p>';$html.='</div></div>'.str_replace('<form ','<form id="compat-cinema-compose" ',self::beginForm(['view'=>'cinema','id'=>$id],'cinema-message')).'<label>Room message<textarea name="message" rows="2" maxlength="400"></textarea></label><button type="submit">Send</button></form>';
        $html.='<label>Invite link<input type="text" readonly="readonly" value="'.self::esc(self::url(['view'=>'cinema','id'=>$id])).'"></label>'.self::link('Leave film night',['view'=>'cinema']);
        if($host){$choices=[];foreach(Chat::listing(self::$viewer,true)['friends'] as $friend)$choices[$friend['id']]=$friend['name'];if($choices)$html.=self::beginForm(['view'=>'cinema','id'=>$id],'cinema-invite').self::select('Invite a friend','user_id',$choices,'').'<button type="submit">Send invitation</button></form>';$html.=self::form(['view'=>'cinema','id'=>$id],'End film night','cinema-end');}return $html;
    }
}
