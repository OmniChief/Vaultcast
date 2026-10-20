<?php
declare(strict_types=1);

trait CompatibilityLibraryFeatures {
    private static function libraryPost(string $view,array $body): ?array {
        $action=(string)($body['compat_action'] ?? '');$id=(string)($_GET['id'] ?? '');
        if($action==='watched'){
            HomeHub::watched(self::$viewer,['item_id'=>$id,'watched'=>($body['watched'] ?? '')==='1']);
            return ['view'=>'item','id'=>$id,'paused'=>'1'];
        }
        if($action==='review'){
            HomeHub::review(self::$viewer,['key'=>(string)($body['key'] ?? ''),'text'=>(string)($body['text'] ?? ''),'rating'=>(int)($body['rating'] ?? 0),'delete'=>!empty($body['delete']),'user_id'=>(string)($body['user_id'] ?? self::$viewer['id'])]);
            return ['view'=>'reviews','id'=>(string)($body['key'] ?? '')];
        }
        if($action==='personal_list'){
            $result=HomeExtras::change(self::$viewer,['action'=>(string)($body['list_action'] ?? ''),'id'=>$id,'owner'=>(string)($body['owner'] ?? self::$viewer['id']),'name'=>(string)($body['name'] ?? ''),'shared'=>!empty($body['shared']),'key'=>(string)($body['key'] ?? '')]);
            if(($body['list_action'] ?? '')==='delete')return ['view'=>'lists'];
            return ['view'=>'personal-list','id'=>$result['id'],'owner'=>(string)($body['owner'] ?? self::$viewer['id'])];
        }
        return null;
    }
    private static function titleFeatures(array $card): string {
        $key=(self::isShow($card)?'show:':'item:').(self::isShow($card)?($card['show_key'] ?? $card['id']):$card['id']);
        $html='<div class="compat-actions">'.self::link('Report a problem',['view'=>'report','id'=>$key]).self::link('Ratings and reviews',['view'=>'reviews','id'=>$key]);
        if(!self::isShow($card) && ($card['media_kind'] ?? '')==='video'){
            if(self::$signed)$html.=self::link('Watch together',['view'=>'cinema-create','id'=>'item:'.$card['id']]);
            if(self::$signed && (Subtitles::uploadAllowed(self::$viewer) || (OpenSubtitles::configured()&&OpenSubtitles::allowed(self::$viewer))))$html.=self::link('Find / upload subtitles',['view'=>'subtitles','id'=>$card['id']]);
            $html.=self::link('Request compatible copy',['view'=>'convert-request','id'=>$card['id']]);
        }
        if(self::isShow($card)||in_array($card['media_kind']??'',['video','audio'],true))$html.=self::link('Copy VLC link',['view'=>'vlc-link','id'=>$key]);
        if(self::$signed)$html.=self::link('Add to a custom list',['view'=>'choose-list','id'=>$key]);
        if(Permissions::allowed(self::$viewer,'fix_match'))$html.=self::link('Fix match',['view'=>'match','id'=>$key]);
        if(Permissions::allowed(self::$viewer,'metadata_edit'))$html.=self::link('Edit details / poster',['view'=>'metadata','id'=>$key]);
        if((self::$viewer['role'] ?? '')==='admin')$html.=self::link('Remove from library',['view'=>'remove-library','id'=>$key]);
        return $html.'</div>';
    }
    private static function watchedControl(array $item): string {
        if(!self::$signed || ($item['media_kind'] ?? '')!=='video')return '';
        $progress=\Vaultcast\Repository::progressItem((string)self::$viewer['id'],(string)$item['id']) ?? [];
        $watched=!empty($progress['watched']) || Catalogue::progressFinished($progress,!empty($item['show']));
        return '<p class="compat-note">'.($watched?'Watched':'Not watched').'</p>'.self::form(['view'=>'item','id'=>$item['id']],$watched?'Mark unwatched / reset progress':'Mark watched','watched',['watched'=>$watched?'0':'1']);
    }
    private static function reviews(string $key): string {
        self::allow('library');$data=HomeHub::title($key,self::$viewer);$html=self::heading('COMMUNITY',(string)$data['card']['title']).self::link('Back to title',self::isShow($data['card'])?['view'=>'show','id'=>$data['card']['show_key'] ?? $data['card']['id']]:['view'=>'item','id'=>$data['card']['id']]);
        $html.='<p class="compat-rating">'.($data['rating']===null?'No ratings yet':self::esc($data['rating']).' / 10 · '.(int)$data['rating_count'].' ratings').'</p>';
        if(self::$signed){
            $mine=$data['own_review'];$choices=['0'=>'No rating'];for($i=1;$i<=10;$i++)$choices[(string)$i]=$i.' / 10';
            $html.=self::beginForm(['view'=>'reviews','id'=>$key],'review',false,['key'=>$key]).'<h3>Your review</h3>'.self::select('Rating','rating',$choices,(string)($mine['rating'] ?? 0)).'<label>Review · may contain spoilers<textarea name="text" rows="4" maxlength="2000">'.self::esc($mine['text'] ?? '').'</textarea></label><button type="submit">Save rating / review</button></form>';
        }
        $page=max(1,min(1000,(int)($_GET['page'] ?? 1)));$reveal=($_GET['spoilers'] ?? '')==='1';
        $html.=self::link($reveal?'Hide review text':'Reveal reviews (may contain spoilers)',['view'=>'reviews','id'=>$key,'page'=>$page,'spoilers'=>$reveal?'0':'1']);
        foreach(array_slice($data['reviews'],($page-1)*10,10) as $row){
            $html.='<div class="compat-panel"><strong>'.self::esc($row['user']['name']).'</strong><p>'.((int)$row['rating']?((int)$row['rating'].' / 10'):'Comment').'</p>'.($reveal?'<p class="compat-message-text">'.self::esc($row['text']).'</p>':'<p class="compat-note">Review text hidden.</p>');
            if(self::$signed && ($row['user']['id']===self::$viewer['id'] || (self::$viewer['role'] ?? '')==='admin'))$html.=self::form(['view'=>'reviews','id'=>$key],'Delete review','review',['key'=>$key,'delete'=>'1','user_id'=>$row['user']['id']]);
            $html.='</div>';
        }
        return $html.self::pages(['view'=>'reviews','id'=>$key,'spoilers'=>$reveal?'1':'0'],$page,$page*10<count($data['reviews']));
    }
    private static function personalLists(string $view,string $id,int $page,string $query): string {
        if(!self::$signed)throw new RuntimeException('Sign in to use custom lists.');
        if($view==='choose-list'){
            $card=HomeHub::entity($id,self::$viewer);if(AreaAccess::itemArea($card)!=='library')throw new RuntimeException('Choose a film or show.');
            $html=self::heading('YOUR LISTS','Add '.(string)$card['title']);$data=HomeExtras::lists(self::$viewer,$page);
            foreach($data['lists'] as $list)$html.=self::form(['view'=>'personal-list','id'=>$list['id']],'Add to '.$list['name'].($list['mine']?'':' · '.$list['owner_name']),'personal_list',['list_action'=>'add','key'=>$id,'owner'=>$list['owner']]);
            return $html.self::pages(['view'=>'choose-list','id'=>$id],$page,$data['has_more']).self::link('Create a list',['view'=>'lists']);
        }
        if($view==='lists'){
            $data=HomeExtras::lists(self::$viewer,$page,$query);$html=self::heading('YOUR SPACE','Custom lists','Private collections and shared shortlists with friends.').self::searchForm(['view'=>'lists'],$query);
            foreach($data['lists'] as $list)$html.='<div class="compat-panel"><h3>'.self::esc($list['name']).'</h3><p>'.self::esc($list['mine']?($list['shared']?'Shared with friends':'Private'):$list['owner_name']."’s shared list").' · '.(int)$list['item_count'].' titles</p>'.self::link('Open list',['view'=>'personal-list','id'=>$list['id'],'owner'=>$list['owner']]).'</div>';
            $html.=self::pages(['view'=>'lists','q'=>$query],$page,$data['has_more']);
            return $html.self::beginForm(['view'=>'lists'],'personal_list',false,['list_action'=>'create']).'<h3>Create a list</h3>'.self::field('Name','name','','text',80).'<label class="compat-check"><input type="checkbox" name="shared" value="1"> Share with friends</label><button type="submit">Create list</button></form>';
        }
        $owner=(string)($_GET['owner'] ?? self::$viewer['id']);$list=HomeExtras::collection(self::$viewer,$owner,$id)['list'];$params=['view'=>'personal-list','id'=>$id,'owner'=>$owner];
        if($view==='delete-list')return self::heading('YOUR LISTS','Delete '.$list['name'].'?','This removes the list. Media files remain in the library.').self::form($params,'Delete list','personal_list',['list_action'=>'delete','owner'=>$owner]).self::link('Cancel',$params);
        $html=self::heading('YOUR LISTS',(string)$list['name']).self::link('All lists',['view'=>'lists']);$entries=array_values(array_filter($list['items'],static function($entry){return AreaAccess::itemArea($entry['card'])==='library';}));
        foreach(array_slice($entries,($page-1)*16,16) as $entry){
            $card=$entry['card'];$key=$card['hub_key'] ?? ((self::isShow($card)?'show:':'item:').($card['show_key'] ?? $card['id']));$html.='<div class="compat-panel">'.self::cards([$card]);
            $html.=self::form($params,(!empty($entry['voted'])?'Remove vote':'Vote').' · '.(int)$entry['votes'],'personal_list',['list_action'=>'vote','owner'=>$owner,'key'=>$key]);
            if($list['mine'])$html.=self::form($params,'Remove from list','personal_list',['list_action'=>'remove','owner'=>$owner,'key'=>$key]);$html.='</div>';
        }
        if(!$entries)$html.='<p>Add titles using “Add to a custom list” on their main pages.</p>';
        $html.=self::pages($params,$page,$page*16<count($entries));
        if($list['mine'])$html.=self::beginForm($params,'personal_list',false,['list_action'=>'edit','owner'=>$owner]).'<h3>Edit list</h3>'.self::field('Name','name',$list['name'],'text',80).'<label class="compat-check"><input type="checkbox" name="shared" value="1"'.(!empty($list['shared'])?' checked="checked"':'').'> Share with friends</label><button type="submit">Save list</button></form>'.self::link('Delete list',['view'=>'delete-list','id'=>$id,'owner'=>$owner]);
        return $html;
    }
    private static function programme(array $program,string $label=''): string {
        $start=(int)($program['startUnix'] ?? 0);$end=(int)($program['endUnix'] ?? 0);$description=(string)($program['description'] ?? $program['subtitle'] ?? '');
        return '<div class="compat-programme"><strong>'.self::esc($label.($program['title'] ?? 'Programme')).'</strong><p class="compat-note">'.($start?self::esc(gmdate('D H:i',(int)($start/1000))).'–'.self::esc(gmdate('H:i',(int)($end/1000))).' UTC':'').'</p>'.($description!==''?'<p>'.self::esc(strip_tags($description)).'</p>':'').'</div>';
    }
}
