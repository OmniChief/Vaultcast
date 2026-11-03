<?php
declare(strict_types=1);

final class CompatibilityBrowser {
    use CompatibilityEnhancements;
    use CompatibilityLibraryFeatures;
    use CompatibilityPersonal;
    use CompatibilityTools;
    use CompatibilityAdministration;
    use CompatibilityViewing;
    use CompatibilityPlay;
    private static $base;
    private static $viewer;
    private static $personal;
    private static $theme='dark';
    private static $signed=false;
    private static function esc($text): string { return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function url(array $params=[]): string { return self::$base.'?'.http_build_query(['compat'=>'on']+$params,'','&',PHP_QUERY_RFC3986); }
    private static function link(string $label,array $params=[]): string { return '<a class="compat-button" href="'.self::esc(self::url($params)).'">'.self::esc($label).'</a>'; }
    private static function form(array $params,string $label,string $action, array $fields=[]): string {
        $html='<form class="compat-action" method="post" action="'.self::esc(self::url($params)).'"><input type="hidden" name="compat_action" value="'.self::esc($action).'"><input type="hidden" name="compat_token" value="'.self::esc(self::token()).'">';
        foreach($fields as $key=>$value)$html.='<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
        return $html.'<button type="submit">'.self::esc($label).'</button></form>';
    }
    private static function allow(string $area): void { if(!AreaAccess::allowed(self::$viewer,$area))throw new RuntimeException('Your account does not have access to this section.'); }
    private static function searchForm(array $params,string $query): string {
        $html='<form class="compat-search" method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on">';
        foreach($params as $key=>$value)$html.='<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
        return $html.'<label>Search <input type="text" name="q" value="'.self::esc($query).'" maxlength="200"></label><button type="submit">Search</button></form>';
    }
    private static function pages(array $params,int $page,bool $more): string {
        return '<div class="compat-pages">'.($page>1?self::link('Previous',['page'=>$page-1]+$params):'').' Page '.$page.' '.($more?self::link('Next',['page'=>$page+1]+$params):'').'</div>';
    }
    private static function token(): string { return hash('sha256','compat-form|'.($_COOKIE[Auth::COOKIE] ?? 'anonymous')); }
    private static function personal(): array {
        if(self::$personal===null)self::$personal=self::$signed ? \Vaultcast\Repository::document('home-hub:'.self::$viewer['id'],[]) : [];
        return self::$personal;
    }
    private static function isShow(array $item): bool {return ($item['kind'] ?? '')==='show'||($item['media_kind'] ?? '')==='show'||(!empty($item['show_key'])&&empty($item['media_kind']));}
    private static function mediaUrl(array $item,array $context=[]): string {return self::url($context+['view'=>self::isShow($item)?'show':'item','id'=>self::isShow($item)?($item['show_key'] ?? $item['id']):$item['id']]);}
    private static function poster(array $item,string $size='card'): string {
        $show=self::isShow($item);$id=$show?($item['show_key'] ?? $item['id']):($item['id'] ?? '');$title=$item['title'] ?? 'Untitled';
        $url=self::$base.'api/'.($show?'shows/':'items/').rawurlencode((string)$id).'/poster?variant='.($size==='detail'?'full':'medium');
        $html='<span class="compat-poster compat-poster-'.$size.'"><span class="compat-poster-fallback">'.self::esc(mb_substr((string)$title,0,1)).'</span>';
        if($id!=='' && ($item['has_poster'] ?? true))$html.='<img data-compat-poster="1" loading="lazy" src="'.self::esc($url).'" alt="'.self::esc($title).'" width="240" height="360">';
        return $html.'</span>';
    }
    private static function cards(array $items,array $context=[]): string {
        $html='<ul class="compat-cards">';$count=0;
        foreach($items as $item){if(($item['media_kind'] ?? '')==='rom'||($item['type'] ?? '')==='roms'||!AreaAccess::allowed(self::$viewer,AreaAccess::itemArea($item)))continue;
            $count++;$meta=[];if(!empty($item['year']))$meta[]=$item['year'];if(!empty($item['season_count']))$meta[]=$item['season_count'].' seasons';if(!empty($item['episode']))$meta[]='Episode '.$item['episode'];
            $html.='<li><a class="compat-card" href="'.self::esc(self::mediaUrl($item,$context)).'">'.self::poster($item).'<strong>'.self::esc($item['title'] ?? 'Untitled').'</strong><small>'.self::esc(implode(' · ',$meta)).'</small>';
            $progress=$item['progress'] ?? [];$duration=(float)($progress['duration'] ?? 0);$position=(float)($progress['position'] ?? 0);
            if($duration>0 && $position>0)$html.='<span class="compat-progress"><span style="width:'.min(100,(int)round($position/$duration*100)).'%"></span></span>';
            $html.='</a></li>';
        }
        return $count?$html.'</ul>':'<p class="compat-empty">Nothing here yet.</p>';
    }
    private static function shelfActions(array $card): string {
        if(!self::$signed)return '<p class="compat-note">Sign in to save favourites and a watchlist.</p>';
        $key=(!empty($card['show_key'])?'show:':'item:').(!empty($card['show_key'])?$card['show_key']:$card['id']);$personal=self::personal();$html='<div class="compat-actions">';
        foreach(['favourites'=>'Favourite','watchlist'=>'Watchlist'] as $shelf=>$label){$active=isset($personal[$shelf][$key]);$html.=self::form(['view'=>self::isShow($card)?'show':'item','id'=>self::isShow($card)?($card['show_key'] ?? $card['id']):$card['id']],$active?'Remove from '.$label:'Add to '.$label,'shelf',['key'=>$key,'shelf'=>$shelf,'enabled'=>$active?'0':'1']);}
        return $html.'</div>';
    }
    private static function heading(string $eyebrow,string $title,string $description=''): string {return '<div class="compat-heading"><p class="compat-eyebrow">'.self::esc($eyebrow).'</p><h2>'.self::esc($title).'</h2>'.($description!==''?'<p class="compat-note">'.self::esc($description).'</p>':'').'</div>';}
    private static function recentProgress(): array {
        if(!self::$signed || !AreaAccess::allowed(self::$viewer,'library'))return [];
        $q=\Vaultcast\Database::connection()->prepare('SELECT i.payload AS item,p.payload AS progress FROM vaultcast_progress p JOIN vaultcast_items i ON i.id=p.item_id WHERE p.user_id=? ORDER BY p.updated_at DESC LIMIT 24');$q->execute([self::$viewer['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=[];$seen=[];
        foreach($rows as $row){$item=json_decode($row['item'],true);$progress=json_decode($row['progress'],true);if(!is_array($item)||!is_array($progress)||AreaAccess::itemArea($item)!=='library'||!empty($progress['watched'])||($progress['position'] ?? 0)<5||Catalogue::progressFinished($progress,!empty($item['show'])))continue;
            $key=!empty($item['show'])?Catalogue::showKey((string)$item['section'],(string)$item['show']):$item['id'];if(isset($seen[$key]))continue;$seen[$key]=true;$card=Catalogue::publicItem($item,false);$card['progress']=$progress;$items[]=$card;if(count($items)>=6)break;
        }
        return $items;
    }
    private static function artwork(string $kind='home'): string {
        $route=in_array($kind,SiteArtwork::SECTIONS,true)?'home-section/'.$kind:$kind.'-poster';
        return '<img class="compat-hero-art" data-compat-poster="1" src="'.self::esc(self::$base.'api/site/'.$route.'?theme='.rawurlencode(self::$theme)).'" alt="" width="240" height="360">';
    }
    private static function home(array $home): string {
        $name=self::$signed?((self::$viewer['display_name'] ?? '') ?: self::$viewer['username']):'';
        $html='<div class="compat-hero">'.self::artwork().'<div class="compat-hero-copy">'.self::heading('YOUR SPACE. YOUR STORIES.',$name!==''?'Welcome back, '.$name:'Make yourself at home.','Your favourites, your next episode and something new to discover.').'<div class="compat-actions">'.(AreaAccess::allowed(self::$viewer,'library')?self::link('Explore the library',['view'=>'library']):'').(!empty($home['features']['live'])?self::link('Live',['view'=>'live']):'').'</div></div></div>';
        $panels=[];$continue=array_slice($home['continue_watching'] ?? [],0,6);if(!$continue)$continue=self::recentProgress();
        if($continue)$panels['recent']='<section class="compat-shelf"><h2>Continue watching</h2>'.self::cards($continue).'</section>';
        $personal=self::personal();$favourites=[];
        foreach($personal['favourites'] ?? [] as $row){$card=$row['card'] ?? [];if(($card['media_kind'] ?? '')!=='rom' && AreaAccess::allowed(self::$viewer,AreaAccess::itemArea($card)))$favourites[]=$card;if(count($favourites)>=6)break;}
        if($favourites)$html.='<section class="compat-shelf"><div class="compat-row-head"><h2>Your favourites</h2>'.self::link('View all',['view'=>'favourites']).'</div>'.self::cards($favourites).'</section>';
        $watch=[];foreach($personal['watchlist'] ?? [] as $entry)if(AreaAccess::itemArea($entry['card'] ?? [])==='library'&&AreaAccess::allowed(self::$viewer,'library')){$watch[]=$entry['card'];if(count($watch)>=6)break;}
        if($watch)$panels['watchlist']='<section class="compat-shelf"><h2>Your next watch</h2>'.self::cards($watch).self::link('Manage watchlist',['view'=>'watchlist']).'</section>';
        if(self::$signed){$panels['friends']='<section class="compat-panel"><h2>Your people</h2>'.self::link('Friends',['view'=>'friends']).self::link('Messages',['view'=>'inbox']).self::link('Invitations',['view'=>'invitations']).'</section>';$panels['calendar']='<section class="compat-panel"><h2>Coming up</h2>'.self::link('Release calendar',['view'=>'calendar']).self::link('New episodes',['view'=>'arrivals','scope'=>'following']).'</section>';}
        foreach((array)self::preference('home_panels',['recent','watchlist','friends','calendar']) as $key)if(isset($panels[$key]))$html.=$panels[$key];
        $html.='<div class="compat-actions">'.self::link('Find a watch',['view'=>'tonight']).self::link('New arrivals',['view'=>'arrivals']).(self::$signed?self::link('Customise Home',['view'=>'home-options']):'').'</div>';
        $html.='<section class="compat-shelf"><h2>Your library</h2><div class="compat-categories">';$featured=[];
        foreach($home['sections'] ?? [] as $section){if(($section['type'] ?? '')==='roms')continue;$html.=self::link(($section['label'] ?? $section['type']).' · '.($section['count'] ?? $section['item_count'] ?? 0),['view'=>'section','id'=>$section['id']]);foreach(array_slice($section['items'] ?? [],0,2) as $item)if(count($featured)<8)$featured[]=$item;}
        $html.='</div></section>';if($featured)$html.='<section class="compat-shelf"><h2>Discover</h2>'.self::cards($featured).'</section>';
        return $html;
    }
    private static function player(string $url,bool $audio=false,array $tracks=[],array $config=[]): string {
        if(!preg_match('#^(?:https?://|/)#i',$url))throw new RuntimeException('Invalid stream address.');
        $config+=['autoplay'=>(self::$viewer['prefs']['autoplay_on_open'] ?? true)!==false];$config['base']=self::$base;if(!empty($config['hls']))$config['hls_url']=PlayerAssets::manifest()['hls'];$tag=$audio?'audio':'video';$html=($audio?'':'<div class="compat-video-stage">').'<'.$tag.' id="compat-media" controls="controls"'.(!empty($config['autoplay'])?' autoplay="autoplay"':'').' preload="none" width="640" height="360" src="'.self::esc($url).'">';
        $preferred=(string)(self::$viewer['prefs']['subtitle_language'] ?? '');$chosen=false;$trackLinks='';$selector='<div id="compat-subtitle-controls" style="display:none"><label>Subtitles <select id="compat-subtitles"><option value="-1">Off</option>';
        foreach($tracks as $index=>$track){$default=!$chosen && $preferred!=='' && ($track['language'] ?? '')===$preferred;if($default)$chosen=true;$html.='<track kind="subtitles" src="'.self::esc($track['url'] ?? '').'" label="'.self::esc($track['label'] ?? 'Subtitles').'" srclang="'.self::esc($track['language'] ?? 'en').'"'.($default?' default="default"':'').'>';$selector.='<option value="'.(int)$index.'"'.($default?' selected="selected"':'').'>'.self::esc($track['label'] ?? 'Subtitles').'</option>';$trackLinks.='<a class="compat-button" href="'.self::esc($track['url'] ?? '').'">'.self::esc($track['label'] ?? 'Subtitles').' file</a>';}
        $selector.='</select></label></div>';
        return $html.'</'.$tag.'>'.($audio?'':'<button id="compat-centre-play" class="compat-centre-play" type="button" style="display:none" aria-label="Play video"><span aria-hidden="true">&#9654;</span><span id="compat-centre-label">Play</span></button></div>').($tracks?$selector.'<div class="compat-subtitle-files">'.$trackLinks.'</div>':'').'<p id="compat-player-message" role="status"></p><script>window.__VC_COMPAT_PLAYER__='.json_encode($config+['url'=>$url],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><p><a class="compat-button" href="'.self::esc($url).'">Open stream directly</a></p><p class="compat-note">Use the player controls to start. If this browser cannot play the stream, open the link with a compatible media player. HLS and video codecs depend on your device.</p>';
    }
    private static function content(array $home): string {
        $view=(string)($_GET['view'] ?? 'home');$id=(string)($_GET['id'] ?? '');$page=max(1,min(1000,(int)($_GET['page'] ?? 1)));$query=mb_substr(trim((string)($_GET['q'] ?? '')),0,200);
        $play=self::playPage($home,$view,$id,$page,$query);if($play!==null)return $play;
        $viewing=self::viewingPage($view,$id);if($viewing!==null)return $viewing;
        $admin=self::administrationPage($view,$id);if($admin!==null)return $admin;
        $tool=self::toolPage($view,$id);if($tool!==null)return $tool;
        $personal=self::personalPage($view,$id,$page,$query);if($personal!==null)return $personal;
        if($view==='home'){
            $landing=(string)self::preference('home_landing','overview');
            if(empty($_GET['overview'])&&in_array($landing,['watchlist','favourites','calendar','arrivals','lists','friends'],true)){header('Location: '.self::url(['view'=>$landing]),true,302);return ''; }
            return self::home($home);
        }
        if($view==='reviews')return self::reviews($id);
        if(in_array($view,['lists','personal-list','choose-list','delete-list'],true))return self::personalLists($view,$id,$page,$query);
        if($view==='library'){
            self::allow('library');$html=self::heading('BROWSE YOUR WORLD','Library','Films, shows, anime and more, all in one place.').'<div class="compat-categories">';
            foreach($home['sections'] ?? [] as $section)if(($section['type'] ?? '')!=='roms')$html.=self::link($section['label'] ?? $section['type'],['view'=>'section','id'=>$section['id']]);
            return $html.'</div>'.self::searchForm(['view'=>'search'],'');
        }
        if($view==='watchlist')return self::watchlistPage($page,$query);
        if(in_array($view,['favourites','watchlist'],true)){
            if(!self::$signed)throw new RuntimeException('Sign in to view your personal lists.');$rows=array_values(array_filter(self::personal()[$view] ?? [],static function($row){return AreaAccess::itemArea($row['card'] ?? [])==='library' && AreaAccess::allowed(self::$viewer,'library');}));
            if($query!=='')$rows=array_values(array_filter($rows,static function($row)use($query){return mb_stripos($row['card']['title'] ?? '',$query)!==false;}));
            usort($rows,static function($a,$b){return ($b['added_at'] ?? 0)<=>($a['added_at'] ?? 0);});
            $items=array_map(static function($row){return $row['card'];},array_slice($rows,($page-1)*16,16));
            return self::heading('YOUR SPACE',$view==='favourites'?'Favourites':'Watchlist').self::searchForm(['view'=>$view],$query).self::cards($items).self::pages(['view'=>$view,'q'=>$query],$page,$page*16<count($rows));
        }
        if($view==='calendar'){
            if(!self::$signed)throw new RuntimeException('Sign in to view your release calendar.');$rows=HomeHub::releaseCalendar(self::$viewer);$days=($_GET['days'] ?? '')==='7'?7:30;$type=(string)($_GET['type'] ?? 'all');$until=gmdate('Y-m-d',time()+$days*86400);
            $rows=array_values(array_filter($rows,static function($ep)use($until,$type,$query){return $ep['date']<=$until&&($type==='all'||($ep['card']['type'] ?? '')===$type)&&($query===''||mb_stripos($ep['card']['title'],$query)!==false);}));
            $html=self::heading('COMING UP','Release calendar','Upcoming episodes for your favourite shows and anime.').'<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="calendar">'.self::field('Show title','q',$query).self::select('Type','type',['all'=>'TV and anime','tv'=>'TV','anime'=>'Anime'],$type).self::select('Date range','days',['7'=>'Next 7 days','30'=>'Next 30 days'],(string)$days).'<button type="submit">Apply filters</button></form><ul class="compat-list">';
            foreach(array_slice($rows,($page-1)*16,16) as $ep)$html.='<li><span class="compat-date">'.self::esc($ep['date']).'</span><a href="'.self::esc(self::mediaUrl($ep['card'])).'"><strong>'.self::esc($ep['card']['title']).'</strong><span class="compat-note"> · S'.(int)$ep['season'].' E'.(int)$ep['episode'].' · '.self::esc(self::preference('hide_spoilers',false)?'Episode title hidden':$ep['title']).'</span></a></li>';
            return $html.($rows?'':'<li>No upcoming episodes are currently cached. Favourite a show to follow its releases.</li>').'</ul>'.self::pages(['view'=>'calendar','q'=>$query,'type'=>$type,'days'=>$days],$page,$page*16<count($rows));
        }
        if(in_array($view,['account','friends','profile','inbox','chat','compose','create-room','delete-message'],true)){
            if(!self::$signed)throw new RuntimeException('Sign in to use your account and messaging.');
            if($view==='delete-message'){$roomView=($_GET['room_view'] ?? '')==='inbox'?'inbox':'chat';$messageId=max(0,(int)($_GET['message_id'] ?? 0));return self::heading('MESSAGE','Delete this message?','This permanently removes the message.').self::form(['view'=>$roomView,'id'=>$id],'Delete message','chat_delete',['message_id'=>$messageId]).self::link('Cancel',['view'=>$roomView,'id'=>$id]);}
            if($view==='account')return self::account();if($view==='friends')return self::friends($query);if($view==='profile')return self::profile($id);if($view==='compose'||$view==='create-room')return self::compose($id,$view==='create-room');return self::messages($view,$id);
        }
        if($view==='collections'){
            self::allow('library');$rows=Collections::publicList()['collections'] ?? [];if($query!=='')$rows=array_values(array_filter($rows,static function($row)use($query){return mb_stripos($row['name'],$query)!==false;}));
            $html=self::heading('LIBRARY','Collections').self::searchForm(['view'=>'collections'],$query).'<ul class="compat-cards">';
            foreach(array_slice($rows,($page-1)*16,16) as $collection){$cover=$collection['cover_item'] ?? ['id'=>'','title'=>$collection['name'],'has_poster'=>false];$html.='<li><a class="compat-card" href="'.self::esc(self::url(['view'=>'collection','id'=>$collection['id']])).'">'.self::poster($cover).'<strong>'.self::esc($collection['name']).'</strong><small>'.(int)$collection['available_count'].' / '.(int)$collection['part_count'].' available</small></a></li>';}
            return $html.'</ul>'.self::pages(['view'=>'collections','q'=>$query],$page,$page*16<count($rows));
        }
        if($view==='collection'){
            self::allow('library');if(!Collections::get($id))throw new RuntimeException('Collection not found.');$collection=Collections::status($id);$html=self::heading('COLLECTION',(string)$collection['collection']['name'],strip_tags((string)($collection['collection']['description'] ?? '')));$items=[];$missing=[];
            foreach(array_slice($collection['parts'],($page-1)*16,16) as $part){if(!empty($part['present']) && !empty($part['item']))$items[]=$part['item'];else $missing[]=$part['title'] ?? 'Unavailable title';}
            $html.=self::cards($items,['collection'=>$id]);if($missing){$html.='<h3>Not in your library</h3><ul class="compat-list">';foreach($missing as $title)$html.='<li>'.self::esc($title).'</li>';$html.='</ul>';}
            return $html.self::pages(['view'=>'collection','id'=>$id],$page,$page*16<count($collection['parts']));
        }
        if($view==='search'){
            $html='<h2>Search</h2>'.self::searchForm(['view'=>'search'],$query);if($query==='')return $html;
            $result=BrowseIndex::search($query,16,$page,self::$viewer);
            return $html.self::cards($result['catalogue']).self::pages(['view'=>'search','q'=>$query],$page,$result['has_more']);
        }
        if($view==='section'){
            self::allow('library');$section=null;foreach($home['sections'] ?? [] as $s)if($s['id']===$id)$section=$s;
            if(!$section||($section['type'] ?? '')==='roms')throw new RuntimeException('Library unavailable.');
            return self::browseSection($section,$query,$page);
        }
        if($view==='show'){
            self::allow('library');$detail=Catalogue::show($id);if(!$detail)throw new RuntimeException('Show not found.');
            $show=$detail['show'];$show['id']=$id;$show['show_key']=$id;$show['kind']='show';$html='<div class="compat-detail">'.self::poster($show,'detail').'<div class="compat-detail-copy">'.self::heading('SERIES',(string)$show['title']).(!empty($show['synopsis'])?'<p class="compat-synopsis">'.self::esc(strip_tags($show['synopsis'])).'</p>':'').self::shelfActions($show).self::titleFeatures($show).'</div></div>';
            $seasons=$detail['seasons'] ?? [];$season=(int)($_GET['season'] ?? ($seasons[0]['season'] ?? 0));$html.='<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="show"><input type="hidden" name="id" value="'.self::esc($id).'"><label>Season <select name="season">';$episodes=[];
            foreach($seasons as $row){$selected=(int)$row['season']===$season;$html.='<option value="'.(int)$row['season'].'"'.($selected?' selected="selected"':'').'>'.self::esc($row['label']).'</option>';if($selected)$episodes=$row['episodes'];}
            $html.='</select></label><button type="submit">Open season</button></form><ul class="compat-list">';$episodes=array_values(array_filter($episodes,static function($ep){return !empty($ep['id'])&&($ep['available'] ?? true)!==false&&($ep['status'] ?? '')!=='missing';}));
            $visible=array_slice($episodes,($page-1)*16,16);$seen=[];
            if(self::$signed&&$visible){$ids=array_column($visible,'id');$q=\Vaultcast\Database::connection()->prepare('SELECT item_id,payload FROM vaultcast_progress WHERE user_id=? AND item_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute(array_merge([self::$viewer['id']],$ids));foreach($q as $row){$p=json_decode($row['payload'],true);$seen[$row['item_id']]=!empty($p['watched'])||Catalogue::progressFinished($p,true);}}
            foreach($visible as $ep){$watched=!empty($seen[$ep['id']]);$title=(!$watched&&self::preference('hide_spoilers',false))?'Details hidden':($ep['episode_title'] ?? $ep['title'] ?? 'Play');$html.='<li>'.self::link('Episode '.($ep['episode'] ?? '?').' - '.$title.($watched?' · Watched':''),['view'=>'item','id'=>$ep['id']]).'</li>';}
            return $html.'</ul>'.self::pages(['view'=>'show','id'=>$id,'season'=>$season],$page,$page*16<count($episodes));
        }
        if($view==='item'){
            self::allow('library');$item=Catalogue::item($id);if(!$item||AreaAccess::itemArea($item)!=='library')throw new RuntimeException('Media not found.');
            $collection=mb_substr((string)($_GET['collection'] ?? ''),0,100);$playParams=['view'=>'item','id'=>$id];if($collection!=='')$playParams['collection']=$collection;
            $detail=Catalogue::itemDetail($id);$card=$detail['item'];$html=self::heading(!empty($card['show'])?'EPISODE':'LIBRARY',(string)$card['title']);
            if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST'&&($_POST['compat_action'] ?? '')==='play'){
                if(!is_file((string)($item['path'] ?? '')))throw new RuntimeException('The media file is unavailable.');
                $play=Stream::issueToken(self::$viewer,$id);$html.=self::player($play['url'],($play['media_kind'] ?? '')==='audio',$play['subtitles'] ?? [],['autoplay'=>true,'signed'=>self::$signed,'item_id'=>$id,'episode_skip'=>EpisodeSegments::eligible($item)&&(self::$viewer['prefs']['skip_segments'] ?? true)!==false,'auto_skip_intro'=>!empty(self::$viewer['prefs']['auto_skip_intro']),'auto_skip_outro'=>!empty(self::$viewer['prefs']['auto_skip_outro']),'progress'=>(!empty($_POST['from_start']) || !empty($_POST['sequence']))?null:($play['progress'] ?? null),'save_progress'=>(self::$viewer['id'] ?? '')!=='public','remux_url'=>!empty($play['remux_allowed'])?($play['remux_url'] ?? null):null]);
                if(($play['media_kind'] ?? '')==='video'){
                    $next=PlaybackSequence::next(self::$viewer,$id,$collection);
                    if($next){$nextParams=['view'=>'item','id'=>$next['id']];if($collection!=='')$nextParams['collection']=$collection;
                        $html.='<div class="compat-panel compat-next"><label class="compat-check"><input id="compat-auto-next" type="checkbox"'.((self::$viewer['prefs']['autoplay_next'] ?? true)!==false?' checked="checked"':'').'> Automatically play '.($collection!==''?'the next film':'the next episode').'</label><p>Up next: '.self::esc($next['title']).'</p>'.self::beginForm($nextParams,'play',false,['sequence'=>'1','from_start'=>'1']).'<button id="compat-next-play" type="submit">Play next now</button></form></div>';
                    }
                }
                if(!empty($play['remux_allowed'])&&!empty($play['remux_url']))$html.='<p><a class="compat-button" data-compat-remux="1" href="'.self::esc($play['remux_url']).'">Open compatible stream</a></p>';
            }else{
                $saved=self::$signed?\Vaultcast\Repository::progressItem((string)self::$viewer['id'],$id):null;
                if(($saved['position'] ?? 0)>=5 && !Catalogue::progressFinished($saved,!empty($item['show'])))$html.='<p class="compat-note">Resume at '.gmdate(($saved['position'] ?? 0)>=3600?'H:i:s':'i:s',(int)$saved['position']).'</p>'.self::form($playParams,'Resume','play').self::form($playParams,'Start from beginning','play',['from_start'=>'1']);
                else $html.=self::form($playParams,'Play','play',['from_start'=>'1']);
            }
            if(!isset($play) && empty($_GET['paused']) && (self::$viewer['prefs']['autoplay_on_open'] ?? true)!==false){
                $html=preg_replace('/<form class="compat-action"/','<form id="compat-open-play" class="compat-action"',$html,1);
            }
            $next=isset($play)?null:self::nextEpisode($item);if($next)$html.=self::link('Next episode · S'.$next['season'].' E'.$next['episode'],['view'=>'item','id'=>$next['item_id']]);
            $html.='<div class="compat-detail">'.self::poster($card,'detail').'<div class="compat-detail-copy"><h3>'.self::esc($card['title']).'</h3><p class="compat-note">'.self::esc(implode(' · ',array_filter([$card['year'] ?? null,strtoupper($card['ext'] ?? '')]))).'</p>'.(!empty($card['synopsis'])?'<p class="compat-synopsis">'.self::esc(strip_tags($card['synopsis'])).'</p>':'').self::shelfActions($card).self::watchedControl($item).self::titleFeatures($card).'</div></div>';
            return $html;
        }
        if($view==='live')return self::live($id,$page,$query);
        throw new RuntimeException('Page not found.');
    }
    /** Samsung-safe Stalker country browsing across all enabled connections. */
    private static function stalker(int $page): string {
        self::allow('live');
        if(class_exists('Parental') && Parental::filtering())throw new RuntimeException('Provider catalogues are unavailable for restricted profiles.');
        require_once STREAM_ROOT.'/src/Live/iptv.php';
        $state=iptv_load();
        $feeds=array_values(array_filter($state['feeds']??[],static function($f){return ($f['type']??'')==='stalker'&&!empty($f['enabled']);}));
        $base=['view'=>'live','tab'=>'stalker'];
        $kind=(string)($_GET['kind']??'live');
        if(!in_array($kind,['live','vod','series'],true))$kind='live';
        $html='<h2>Stalker</h2>'.self::link('Back to Live TV',['view'=>'live']);
        foreach(['live'=>'Live TV','vod'=>'Movies','series'=>'Series'] as $tab=>$label)
            $html.=self::link(($kind===$tab?'✓ ':'').$label,$base+['kind'=>$tab]);
        if(!$feeds)return $html.'<p>No enabled Stalker connections are available.</p>';
        if($kind!=='live')return $html.self::stalkerOnDemand($feeds,$kind,$page);
        $country=strtoupper(mb_substr((string)($_GET['country']??''),0,3));
        $section=mb_substr((string)($_GET['section']??''),0,80);
        $folderId=mb_substr((string)($_GET['folder']??''),0,120);
        $folderFeed=mb_substr((string)($_GET['folder_feed']??''),0,120);
        $sourceOffset=max(0,min(1000,(int)($_GET['source_offset']??0)));
        $selectedChannel=mb_substr((string)($_GET['channel']??''),0,120);
        $selectedFeed=mb_substr((string)($_GET['feed']??''),0,120);
        $sections=['General & entertainment','Sports','Football','Live events & PPV','Movies & on-demand','Kids & family','News, documentary & music','Replays & catch-up','Other categories'];
        $sectionFor=static function(string $label): string {
            $n=mb_strtoupper($label);
            if(preg_match('/\b(?:REPLAY|CATCH.?UP)\b/u',$n))return 'Replays & catch-up';
            if(preg_match('/\b(?:PPV|PAY PER VIEW|EVENTS?|UFC|BOXING|PDC|F1 TV|RACES)\b/u',$n))return 'Live events & PPV';
            if(preg_match('/\b(?:EPL|EFL|SOCCER|FOOTBALL|LEAGUE|CHAMPIONSHIP|LA LIGA|SERIE A|SPFL|SCOTTISH CUP|UEFA)\b/u',$n))return 'Football';
            if(preg_match('/\b(?:SPORTS?|ESPN|BEIN|DAZN|SUPERSPORT|VIAPLAY)\b/u',$n))return 'Sports';
            if(preg_match('/\b(?:KIDS?|BABY|CARTOON|CHILDREN|FAMILY)\b/u',$n))return 'Kids & family';
            if(preg_match('/^(?:OD\b|ON DEMAND\b)|\b(?:MOVIES?|CINEMA|SKY STORE|NETFLIX|DISNEY|IPLAY|CINE)\b/u',$n))return 'Movies & on-demand';
            if(preg_match('/\b(?:NEWS|DOCUMENTARY|LIFESTYLE|MUSIC|RADIO)\b/u',$n))return 'News, documentary & music';
            if(preg_match('/\b(?:GENERAL|ENTERTAINMENT|24\/7|AMAZON|ITVX|DISCOVERY|SKY MIX)\b/u',$n))return 'General & entertainment';
            return 'Other categories';
        };
        $cleanLabel=static function(string $raw): string {
            $label=trim($raw);
            for($n=0;$n<3;$n++){
                $next=preg_replace('/^\s*(?:[|┃│¦]\s*[A-Z]{2,3}\s*[|┃│¦]|\[\s*[A-Z]{2,3}\s*\]|\(\s*[A-Z]{2,3}\s*\))\s*[-–—:|┃│¦]*\s*/iu','',$label);
                if($next===$label)break;
                $label=trim($next);
            }
            return $label!==''?$label:$raw;
        };
        $countryName=static function(string $code): string {
            if($code==='ZZ')return 'Unlabelled / mixed';
            if($code==='GB')return 'United Kingdom';
            if($code==='US')return 'United States';
            if(class_exists('Locale')){
                $label=\Locale::getDisplayRegion('und-'.$code,'en');
                if(is_string($label)&&$label!==''&&strtoupper($label)!==$code)return $label;
            }
            return $code;
        };
        $folders=[];$errors=[];
        // Provider categories are cached on the NAS. No SQL catalogue, browser
        // JavaScript, or upfront live channel scan is required.
        foreach($feeds as $feed){
            try{
                $categories=StalkerLiveBrowse::categories($feed)['categories'];
                foreach($categories as $folder){
                    $folder['feed']=$feed['id'];$folder['connection']=$feed['name'];
                    $folder['name']=$cleanLabel((string)$folder['name']);
                    $folder['section']=$sectionFor($folder['name']);
                    $folders[]=$folder;
                }
            }catch(Throwable $e){$errors[]=(string)$feed['name'].': categories unavailable';}
        }
        if($country===''){
            $counts=[];
            foreach($folders as $folder){$code=$folder['country']??'ZZ';$counts[$code]=($counts[$code]??0)+1;}
            $codes=array_keys($counts);
            usort($codes,static function($a,$b)use($countryName){
                $rank=static function($code){return $code==='GB'?0:($code==='US'?1:($code==='ZZ'?3:2));};
                return $rank($a)<=>$rank($b) ?: strcmp($countryName($a),$countryName($b));
            });
            $html.='<p class="compat-note">'.count($folders).' categories across '.count($feeds).' connections'.($errors?' · '.self::esc(implode('; ',$errors)):'').'</p>';
            $html.='<h3>Countries</h3><ul class="compat-list">';
            foreach($codes as $code)$html.='<li>'.self::link($countryName($code).' ('.$counts[$code].')',$base+['country'=>$code]).'</li>';
            return $html.'</ul>'.(!$codes?'<p>No provider categories could be loaded.</p>':'');
        }
        $html.=self::link('Countries',$base);
        $countryFolders=array_values(array_filter($folders,static function($f)use($country){return ($f['country']??'ZZ')===$country;}));
        if($section===''&&$folderId===''){
            $html.='<h3>'.self::esc($countryName($country)).' · Provider categories</h3>';
            if(!$countryFolders)return $html.'<p>No categories for this country.</p>';
            $groups=array_fill_keys($sections,[]);
            $merged=[];
            foreach($countryFolders as $folder){
                $key=$folder['section'].'|'.mb_strtolower($folder['name']);
                if(!isset($merged[$key]))$merged[$key]=['name'=>$folder['name'],'section'=>$folder['section'],'sources'=>[]];
                $merged[$key]['sources'][]=$folder;
            }
            foreach($merged as $item)$groups[$item['section']][]=$item;
            $html.='<p class="compat-note">Select a section to load its channels, or select an individual provider folder.</p>';
            foreach($sections as $name){
                if(!$groups[$name])continue;
                usort($groups[$name],static function($a,$b){
                    $rank=static function($x){return strtoupper($x)==='GENERAL'?0:(strtoupper($x)==='ENTERTAINMENT'?1:2);};
                    return $rank($a['name'])<=>$rank($b['name']) ?: strcasecmp($a['name'],$b['name']);
                });
                $html.='<section class="compat-panel"><h3>'.self::link($name.' ('.count($groups[$name]).')',$base+['country'=>$country,'section'=>$name]).'</h3>';
                // Plain links remain operable on older Samsung Tizen browsers.
                $html.='<ul class="compat-list">';
                foreach($groups[$name] as $entry){
                    $first=$entry['sources'][0];
                    $html.='<li>'.self::link($entry['name'].' · '.count($entry['sources']).' connection(s)',
                        $base+['country'=>$country,'folder'=>$first['id'],'folder_feed'=>$first['feed']]).'</li>';
                }
                $html.='</ul></section>';
            }
            return $html;
        }
        $target=array_values(array_filter($countryFolders,static function($f)use($section,$folderId,$folderFeed){
            return $section!==''?$f['section']===$section:
                ((string)$f['id']===$folderId&&(string)$f['feed']===$folderFeed);
        }));
        if(!$target)return $html.'<p>This provider group is no longer available.</p>';
        $label=$section!==''?$section:$target[0]['name'];
        $html.=self::link('Categories',$base+['country'=>$country]).'<h3>'.self::esc($label).'</h3>';
        $window=8;
        $slice=array_slice($target,$sourceOffset,$window);
        $channels=[];$more=false;$failures=[];
        // Only one provider page per selected folder per request, max eight
        // folder sources. Paging avoids a multi-provider scan on small NAS units.
        foreach($slice as $source){
            $feed=null;foreach($feeds as $candidate)if((string)$candidate['id']===(string)$source['feed']){$feed=$candidate;break;}
            if(!$feed)continue;
            try{
                $data=StalkerLiveBrowse::channels($feed,['category'=>$source['id'],'page'=>$page]);
                $more=$more||!empty($data['hasMore']);
                foreach($data['items']??[] as $item)$channels[]=$item;
            }catch(Throwable $e){$failures[]=$source['connection'].' · '. $source['name'];}
        }
        $byName=[];
        foreach($channels as $item){
            $name=(string)$item['name'];
            for($n=0;$n<3;$n++){
                $next=preg_replace('/^\s*(?:(?:UK|GB)\s*[-–—:|]|\[\s*(?:UK|GB)\s*\]|[|┃│¦]\s*(?:UK|GB)\s*[|┃│¦])\s*/iu','',$name);
                if($next===$name)break;$name=trim($next);
            }
            $key=mb_strtolower(trim(preg_replace('/\s+/u',' ',$name)));
            if($key==='')continue;
            if(!isset($byName[$key]))$byName[$key]=['name'=>$name,'copies'=>[]];
            $byName[$key]['copies'][]=$item;
        }
        $html.='<p class="compat-note">'.count($byName).' channels on this page · '.count($slice).' provider folders'.($failures?' · Unavailable: '.self::esc(implode(', ',$failures)):'').'</p>';
        $params=$base+['country'=>$country]+($section!==''?['section'=>$section]:['folder'=>$folderId,'folder_feed'=>$folderFeed])+['source_offset'=>$sourceOffset];
        if($selectedChannel!==''){
            $item=null;$copies=[];
            foreach($byName as $group)foreach($group['copies'] as $candidate)if((string)$candidate['id']===$selectedChannel&&(string)$candidate['feed']===$selectedFeed){$item=$candidate;$copies=$group['copies'];break 2;}
            if(!$item)return $html.'<p>This channel is no longer on the selected provider page.</p>'.self::link('Back to channels',$params+['page'=>$page]);
            $html.=self::link('Back to channels',$params+['page'=>$page]).'<h3>'.self::esc($item['name']).'</h3>';
            if(count($copies)>1){
                $html.='<p>Available connections:</p><ul class="compat-list">';
                foreach($copies as $copy)$html.='<li>'.self::link($copy['connection'], $params+['page'=>$page,'feed'=>$copy['feed'],'channel'=>$copy['id']]).'</li>';
                $html.='</ul>';
            }
            try{
                $feed=null;foreach($feeds as $candidate)if((string)$candidate['id']===$selectedFeed){$feed=$candidate;break;}
                if(!$feed)throw new RuntimeException('Connection unavailable.');
                $ticket=bin2hex(random_bytes(16));$url=stalker_resolve($feed,(string)$item['id']);
                \Vaultcast\Repository::cachePut('stalker_play_ticket_'.hash('sha256',$ticket),[
                    'viewer'=>(string)self::$viewer['id'],'feed'=>$feed['id'],
                    'fingerprint'=>hash('sha256',json_encode($feed)),
                    'kind'=>'live','id'=>(string)$item['id'],'url'=>$url,'created'=>time(),'item'=>null
                ],21600);
                $stream=self::$base.'api/live/stalker_stream?'.http_build_query([
                    'feed'=>$feed['id'],'channel'=>$item['id'],'ticket'=>$ticket,'format'=>'.m3u8'
                ],'','&',PHP_QUERY_RFC3986);
                $html.=self::player($stream,false,[],['hls'=>true]);
            }catch(Throwable $e){$html.='<p class="compat-note">Playback unavailable. Try another connection or retry the channel.</p>';}
            return $html;
        }
        $html.='<ul class="compat-list">';
        foreach($byName as $group){
            $item=$group['copies'][0];
            $html.='<li>'.self::link($group['name'],$params+['page'=>$page,'feed'=>$item['feed'],'channel'=>$item['id']]);
            if(count($group['copies'])>1)$html.='<small> · '.count($group['copies']).' connections</small>';
            $html.='</li>';
        }
        $html.='</ul>';
        $html.=self::pages($params,$page,$more);
        if($sourceOffset>0)$html.=self::link('Previous provider folders',$base+['country'=>$country]+($section!==''?['section'=>$section]:['folder'=>$folderId,'folder_feed'=>$folderFeed])+['source_offset'=>max(0,$sourceOffset-$window)]);
        if($sourceOffset+$window<count($target))$html.=self::link('More provider folders',$base+['country'=>$country]+($section!==''?['section'=>$section]:['folder'=>$folderId,'folder_feed'=>$folderFeed])+['source_offset'=>$sourceOffset+$window]);
        return $html;
    }
    /** Samsung-friendly Movies/Series browsing via the same direct provider pages. */
    private static function stalkerOnDemand(array $feeds,string $kind,int $page): string {
        $base=['view'=>'live','tab'=>'stalker','kind'=>$kind];
        $lang=(string)($_GET['lang']??'UK');
        $folderId=mb_substr((string)($_GET['folder']??''),0,120);
        $folderFeed=mb_substr((string)($_GET['folder_feed']??''),0,120);
        $query=mb_substr(trim((string)($_GET['q']??'')),0,160);
        $parent=mb_substr((string)($_GET['parent']??''),0,120);
        $selectedFeed=mb_substr((string)($_GET['feed']??''),0,120);
        $selectedItem=mb_substr((string)($_GET['item']??''),0,120);
        $sourceOffset=max(0,min(1000,(int)($_GET['source_offset']??0)));
        $html='';
        $language=static function(string $raw): string {
            $name=mb_strtoupper($raw);
            $code='';
            if(preg_match('/(?:[|┃│¦]\s*([A-Z]{2,3})\s*[|┃│¦]|\[\s*([A-Z]{2,3})\s*\])/u',$name,$m))$code=(string)($m[1]?:($m[2]??''));
            $codes=['EN'=>'UK','GB'=>'UK','UK'=>'UK','US'=>'UK','FR'=>'French','DE'=>'German','IT'=>'Italian','ES'=>'Spanish','PT'=>'Portuguese','NL'=>'Dutch','TR'=>'Turkish','IR'=>'Iran','AR'=>'Arabic'];
            if($code!=='')return $codes[$code]??$code;
            if(strpos($name,'MULTISUB')!==false)return 'UK';
            return 'UK';
        };
        $name=static function(string $raw): string {
            $value=preg_replace('/^\s*(?:[|┃│¦]\s*[A-Z]{2,3}\s*[|┃│¦]|\[\s*[A-Z]{2,3}\s*\])\s*/iu','',$raw);
            return trim($value)!==''?trim($value):$raw;
        };
        $feedById=[];
        foreach($feeds as $feed)$feedById[(string)$feed['id']]=$feed;
        if($parent!==''){
            $feed=$feedById[$selectedFeed]??null;
            if(!$feed)return '<p>This series connection is no longer available.</p>'.self::link('Back to Series',$base+['lang'=>$lang]);
            $html.=self::link('Back to Series',$base+['lang'=>$lang]).'<h3>Seasons and episodes</h3>';
            try{$data=StalkerDirect::browse(['feeds'=>[$feed]],['kind'=>'series','feed'=>$feed['id'],'parent'=>$parent,'page'=>$page]);}
            catch(Throwable $e){return $html.'<p>Could not retrieve the current episodes.</p>';}
            $items=$data['items']??[];
            usort($items,static function($a,$b){
                return (int)($b['season']??0)<=>(int)($a['season']??0)
                    ?: (int)($b['episode']??0)<=>(int)($a['episode']??0);
            });
            $html.='<ul class="compat-list">';
            foreach($items as $item){
                $label='Season '.($item['season']??'?').' · Episode '.($item['episode']??'?');
                if(!empty($item['episodeTitle']))$label.=' · '.$item['episodeTitle'];
                $html.='<li>'.self::link($label,$base+['lang'=>$lang,'parent'=>$parent,'feed'=>$feed['id'],'page'=>$page,'item'=>$item['id']]).'</li>';
            }
            $html.='</ul>'.self::pages($base+['lang'=>$lang,'parent'=>$parent,'feed'=>$feed['id']],$page,!empty($data['hasMore']));
            if($selectedItem!==''){
                foreach($items as $item)if((string)$item['id']===$selectedItem&&$item['playable']){
                    return $html.self::stalkerOnDemandPlayer($feed,$item);
                }
                $html.='<p>The selected episode is no longer on this page.</p>';
            }
            return $html;
        }
        $folders=[];$errors=[];
        foreach($feeds as $feed){
            try{
                $data=StalkerDirect::browse(['feeds'=>[$feed]],['kind'=>$kind,'feed'=>$feed['id'],'categories_only'=>1]);
                foreach($data['categories']??[] as $category){
                    $folders[]=['feed'=>$feed['id'],'connection'=>$feed['name'],'id'=>(string)$category['id'],
                        'name'=>$name((string)$category['name']),'language'=>$language((string)$category['name'])];
                }
            }catch(Throwable $e){$errors[]=$feed['name'];}
        }
        $available=['UK'=>true];
        foreach($folders as $folder)$available[$folder['language']]=true;
        $langs=array_keys($available);
        usort($langs,static function($a,$b){return $a==='UK'?-1:($b==='UK'?1:strcmp($a,$b));});
        $html.='<p>Language:</p>';
        foreach($langs as $value)$html.=self::link(($lang===$value?'✓ ':'').($value==='UK'?'English':$value),$base+['lang'=>$value]);
        $html.=self::link(($lang==='all'?'✓ ':'').'All languages',$base+['lang'=>'all']);
        $html.=self::searchForm($base+['lang'=>$lang]+($folderId!==''?['folder'=>$folderId,'folder_feed'=>$folderFeed]:[]),$query);
        if($errors)$html.='<p class="compat-note">Some provider folders were unavailable.</p>';
        $filtered=array_values(array_filter($folders,static function($f)use($lang){return $lang==='all'||$f['language']===$lang;}));
        if($query===''&&$folderId===''){
            $grouped=[];
            foreach($filtered as $folder){
                $key=mb_strtolower($folder['name']);
                if(!isset($grouped[$key]))$grouped[$key]=['name'=>$folder['name'],'sources'=>[]];
                $grouped[$key]['sources'][]=$folder;
            }
            $entries=array_values($grouped);
            usort($entries,static function($a,$b){return strcasecmp($a['name'],$b['name']);});
            $html.='<h3>Provider folders</h3><ul class="compat-list">';
            foreach($entries as $entry){
                $first=$entry['sources'][0];
                $html.='<li>'.self::link($entry['name'].' · '.count($entry['sources']).' connection(s)',
                    $base+['lang'=>$lang,'folder'=>$first['id'],'folder_feed'=>$first['feed']]).'</li>';
            }
            return $html.'</ul>'.(!$entries?'<p>No folders in this language.</p>':'');
        }
        if($folderId!==''){
            $target=null;
            foreach($folders as $folder)if($folder['feed']===$folderFeed&&$folder['id']===$folderId){$target=$folder;break;}
            if(!$target)return $html.'<p>Provider folder no longer exists.</p>';
            $filtered=array_values(array_filter($filtered,static function($f)use($target){return mb_strtolower($f['name'])===mb_strtolower($target['name']);}));
            $html.='<h3>'.self::esc($target['name']).'</h3>'.self::link('All folders',$base+['lang'=>$lang]);
        }else{
            $html.='<h3>Search results</h3>';
        }
        $window=6;$sourceList=array_slice($filtered,$sourceOffset,$window);
        $items=[];$failed=0;$more=false;
        foreach($sourceList as $src){
            $feed=$feedById[$src['feed']]??null;
            if(!$feed)continue;
            try{
                $data=StalkerDirect::browse(['feeds'=>[$feed]],[
                    'kind'=>$kind,'feed'=>$feed['id'],'category'=>$src['id'],'q'=>$query,'page'=>$page
                ]);
                $more=$more||!empty($data['hasMore']);
                foreach($data['items']??[] as $item)$items[]=$item;
            }catch(Throwable $e){$failed++;}
        }
        $groups=[];
        foreach($items as $item){
            $title=(string)($item['name']??'Untitled');
            $key=mb_strtolower($title).'|'.($item['year']??'');
            if(!isset($groups[$key]))$groups[$key]=['title'=>$title,'year'=>$item['year']??'','sources'=>[]];
            $groups[$key]['sources'][]=$item;
        }
        uasort($groups,static function($a,$b){return strcasecmp($a['title'],$b['title']);});
        $html.='<p class="compat-note">'.count($groups).' titles loaded'.($failed?' · '.$failed.' unavailable folders':'').'</p><ul class="compat-list">';
        $params=$base+['lang'=>$lang,'q'=>$query,'source_offset'=>$sourceOffset]+($folderId!==''?['folder'=>$folderId,'folder_feed'=>$folderFeed]:[]);
        foreach($groups as $group){
            $first=$group['sources'][0];
            $html.='<li>'.self::link($group['title'].($group['year']?' ('.$group['year'].')':''),
                $params+['page'=>$page,'feed'=>$first['feed'],'item'=>$first['id']]);
            if(count($group['sources'])>1)$html.='<small> · '.count($group['sources']).' connections</small>';
            $html.='</li>';
        }
        $html.='</ul>';
        if($selectedItem!==''){
            $found=null;$copies=[];
            foreach($groups as $group)foreach($group['sources'] as $item)
                if((string)$item['id']===$selectedItem&&(string)$item['feed']===$selectedFeed){$found=$item;$copies=$group['sources'];break 2;}
            if($found!==null){
                if(count($copies)>1){
                    $html.='<h3>Other available connections</h3><ul class="compat-list">';
                    foreach($copies as $source)$html.='<li>'.self::link($source['connection'],
                        $params+['page'=>$page,'feed'=>$source['feed'],'item'=>$source['id']]).'</li>';
                    $html.='</ul>';
                }
                if(!empty($found['playable']))$html.=self::stalkerOnDemandPlayer($feedById[$selectedFeed],$found);
                else $html.=self::link('Browse seasons and episodes',$base+['lang'=>$lang,'parent'=>$found['id'],'feed'=>$selectedFeed]);
            }else $html.='<p>This title is no longer on the selected page.</p>';
        }
        $html.=self::pages($params,$page,$more);
        if($sourceOffset>0)$html.=self::link('Previous provider folders',$base+['lang'=>$lang,'q'=>$query,'source_offset'=>max(0,$sourceOffset-$window)]+($folderId!==''?['folder'=>$folderId,'folder_feed'=>$folderFeed]:[]));
        if($sourceOffset+$window<count($filtered))$html.=self::link('More provider folders',$base+['lang'=>$lang,'q'=>$query,'source_offset'=>$sourceOffset+$window]+($folderId!==''?['folder'=>$folderId,'folder_feed'=>$folderFeed]:[]));
        return $html;
    }
    /** Playback remains ticket-scoped and uses the existing Samsung-safe player. */
    private static function stalkerOnDemandPlayer(array $feed,array $item): string {
        try{
            $id=(string)$item['id'];$url=stalker_vod_resolve($feed,$id);
            $ticket=bin2hex(random_bytes(16));
            \Vaultcast\Repository::cachePut('stalker_play_ticket_'.hash('sha256',$ticket),[
                'viewer'=>(string)self::$viewer['id'],'feed'=>$feed['id'],
                'fingerprint'=>hash('sha256',json_encode($feed)),
                'kind'=>(string)($item['kind']??'vod'),'id'=>$id,'url'=>$url,'created'=>time(),
                'item'=>stalker_vod_entry($feed,$id)
            ],21600);
            $stream=self::$base.'api/live/stalker_stream?'.http_build_query([
                'feed'=>$feed['id'],'channel'=>$id,'ticket'=>$ticket
            ],'','&',PHP_QUERY_RFC3986);
            $hls=(bool)preg_match('/\.m3u8(?:[?#]|$)/i',$url);
            if($hls)$stream.='&format=.m3u8';
            return '<h3>'.self::esc($item['name']??'Provider video').'</h3>'.self::player($stream,false,[],['hls'=>$hls]);
        }catch(Throwable $e){
            return '<p class="compat-note">Provider video unavailable. Try another connection or return to the catalogue.</p>';
        }
    }
    private static function live(string $id,int $page,string $query): string {
        self::allow('live');if(empty(Store::settings()['features']['live']))throw new RuntimeException('Live is disabled.');
        $tab=in_array($_GET['tab'] ?? '',['now','relay','iptv','radio','stalker'],true)?$_GET['tab']:'now';
        if($tab==='stalker')return self::stalker($page);
        if($tab==='radio'){
            $stations=Radio::stations();$html='<h2>Radio</h2>'.self::link('Live television',['view'=>'live']);
            if($id!==''){foreach($stations as $radio)if($radio['id']===$id){$html.='<section class="compat-panel"><h3>'.self::esc($radio['name']).'</h3><audio controls="controls" preload="none" src="'.self::esc($radio['url']).'">Your browser needs support for this audio format.</audio><p>'.self::esc($radio['description'] ?: ($radio['tags'] ?? '')).'</p></section>';break;}}
            $html.=self::searchForm(['view'=>'live','tab'=>'radio'],$query);$stations=array_values(array_filter($stations,static function($radio)use($query){return $query===''||mb_stripos($radio['name'].' '.($radio['country'] ?? '').' '.($radio['tags'] ?? ''),$query)!==false;}));
            $html.='<ul class="compat-list">';foreach(array_slice($stations,($page-1)*16,16) as $radio)$html.='<li>'.self::link($radio['name'],['view'=>'live','tab'=>'radio','id'=>$radio['id']]).'<p class="compat-note">'.self::esc(($radio['country'] ?? '').' · '.($radio['tags'] ?? '')).'</p></li>';
            return $html.'</ul>'.self::pages(['view'=>'live','tab'=>'radio','q'=>$query],$page,$page*16<count($stations));
        }
        require_once STREAM_ROOT.'/src/Live/store.php';require_once STREAM_ROOT.'/src/Live/epg.php';require_once STREAM_ROOT.'/src/Live/schedule.php';
        $schedule=mb_substr((string)($_GET['schedule'] ?? ''),0,100);$station=relay_load_station();$guide=LiveGuideCache::read(2,$schedule==='',self::$viewer,static function($hours,$slim)use($station){return epg_unified_guide($station,$hours,$slim,self::$viewer);});
        $html='<h2>Live</h2>'.self::link('Radio',['view'=>'live','tab'=>'radio']);
        $html.='<p class="compat-note">Type a channel number on your remote or keyboard. Three digits tune immediately; shorter numbers tune after a brief pause.</p><div id="compat-channel-number" role="status" aria-live="polite" style="display:none"></div><div id="compat-channel-map" style="display:none">';
        // Include the complete authorised lineup, regardless of pagination or search.
        foreach($guide['channels'] ?? [] as $ch){
            $number=(int)($ch['number'] ?? 0);if($number<1)continue;
            $html.='<a data-channel-number="'.$number.'" href="'.self::esc(self::url(['view'=>'live','tab'=>($ch['kind'] ?? '')==='iptv'?'iptv':'relay','id'=>$ch['id']])).'">'.self::esc($ch['name'] ?? 'Channel').'</a>';
        }
        $html.='</div>';if($id!==''){
            $channel=null;foreach($guide['channels'] ?? [] as $ch)if((string)$ch['id']===$id)$channel=$ch;
            if(!$channel)throw new RuntimeException('Channel unavailable.');$html.='<h3>'.self::esc($channel['name']).'</h3>';
            if(($channel['kind'] ?? '')==='iptv'){
                $src=null;foreach(iptv_lineup_channels() as $entry)if($entry['id']===$channel['iptvId'])$src=$entry;
                if(!$src||empty($src['url']))throw new RuntimeException('IPTV source unavailable.');$url=$src['url'];
                if(preg_match('/[.]m3u8(?:[?]|$)/i',$url)){$url=self::$base.'api/live/iptv_hls?'.http_build_query(['u'=>$url,'jar'=>bin2hex(random_bytes(8))]+(!empty($src['userAgent'])?['ua'=>$src['userAgent']]:[])+(!empty($src['referrer'])?['ref'=>$src['referrer']]:[]),'','&',PHP_QUERY_RFC3986);}
                $html.=self::player($url,false,[],['hls'=>(bool)preg_match('/[.]m3u8(?:[?]|$)/i',$src['url'])]);
            }else{
                $now=(int)round(microtime(true)*1000);$block=relay_air_block($station,$id,$now);
                if(!empty($block['url'])){$url=$block['url'];if(preg_match('#(?:^|/)api[.]php[?]action=([a-z_]+)&?(.*)$#',$url,$m))$url=self::$base.'api/live/'.$m[1].(!empty($m[2])?'?'.$m[2]:'');if(!preg_match('#^(?:https?://|/)#i',$url))$url=self::$base.'live/'.$url;
                    $offset=max(0,((int)($block['mediaOffsetMs'] ?? 0)+$now-(int)$block['startUnix'])/1000);$html.=self::player($url.'#t='.(int)$offset,false,[],['relay'=>['id'=>$id,'seek'=>$offset,'end'=>(int)($block['endUnix'] ?? 0)]]);
                }else $html.='<p>This channel is currently off air.</p>';
                $html.=self::link('Retune to live',['view'=>'live','tab'=>$tab,'id'=>$id]).'<p><a class="compat-button" href="'.self::esc(self::$base.'api/live/m3u?channel='.rawurlencode($id)).'">Open channel playlist</a></p><p class="compat-note">If automatic retuning is unavailable, use the channel playlist or retune when this programme ends.</p>';
            }
        }
        if($schedule!==''){
            $selected=null;foreach($guide['channels'] ?? [] as $channel)if((string)$channel['id']===$schedule)$selected=$channel;
            if(!$selected)throw new RuntimeException('Channel unavailable.');
            $html.='<section class="compat-panel"><h3>'.self::esc($selected['name']).' · Programme guide</h3>'.self::link('Watch channel',['view'=>'live','tab'=>$tab,'id'=>$schedule]).'<p class="compat-note">Times shown in UTC. Schedule covers the next two hours.</p>';
            $programs=array_values(array_filter($selected['programs'] ?? [],static function($program){return (int)($program['endUnix'] ?? 0)>(int)(microtime(true)*1000);}));
            $schedulePage=max(1,min(1000,(int)($_GET['schedule_page'] ?? 1)));
            foreach(array_slice($programs,($schedulePage-1)*16,16) as $program)$html.=self::programme($program);
            if(!$programs)$html.='<p>No programme details available.</p>';
            if($schedulePage>1)$html.=self::link('Earlier programmes',['view'=>'live','tab'=>$tab,'schedule'=>$schedule,'schedule_page'=>$schedulePage-1]);
            if($schedulePage*16<count($programs))$html.=self::link('Later programmes',['view'=>'live','tab'=>$tab,'schedule'=>$schedule,'schedule_page'=>$schedulePage+1]);
            $html.='</section>';
        }
        $html.=self::searchForm(['view'=>'live','tab'=>$tab],$query);$channels=array_values(array_filter($guide['channels'] ?? [],static function($ch)use($query,$tab){return ($tab==='now'||($tab==='iptv'?($ch['kind'] ?? '')==='iptv':($ch['kind'] ?? '')!=='iptv'))&&($query===''||mb_stripos($ch['name'] ?? '',$query)!==false);}));
        usort($channels,static function($a,$b){return (int)($a['number'] ?? 0)<=>(int)($b['number'] ?? 0);});$html.='<ul class="compat-list">';
        foreach(array_slice($channels,($page-1)*16,16) as $ch){
            $html.='<li>'.self::link(($ch['number'] ?? '').' - '.($ch['name'] ?? 'Channel'),['view'=>'live','tab'=>$tab,'id'=>$ch['id']]).self::link('Programme guide',['view'=>'live','tab'=>$tab,'schedule'=>$ch['id']]);
            if(!empty($ch['now']))$html.=self::programme($ch['now'],'Now · ');
            if(!empty($ch['next']))$html.=self::programme($ch['next'],'Next · ');
            $html.='</li>';
        }
        return $html.'</ul>'.self::pages(['view'=>'live','tab'=>$tab,'q'=>$query],$page,$page*16<count($channels));
    }
    public static function render(array $startup,string $base,string $site,string $error=''): void {
        self::$base=$base;self::$signed=!empty($startup['me']['user']);self::$personal=null;
        self::$viewer=$startup['me']['user'] ?? guest_user();AreaAccess::remember(self::$viewer);
        $theme=(string)self::preference('theme',Store::settings()['theme'] ?? 'dark');
        self::$theme=in_array($theme,SiteArtwork::THEMES,true)?$theme:'dark';
        if(!isset($_GET['view'])){
            $path=(string)parse_url($_SERVER['REQUEST_URI'] ?? $base,PHP_URL_PATH);
            if(strpos($path,$base)===0)$path=substr($path,strlen($base));
            $parts=array_map('rawurldecode',explode('/',trim($path,'/')));$route=$parts[0] ?? '';
            if($route==='library'){$_GET['view']=empty($parts[1])?'library':'section';if(!empty($parts[1]))$_GET['id']=$parts[1];}
            elseif($route==='collections'){$_GET['view']=empty($parts[1])?'collections':'collection';if(!empty($parts[1]))$_GET['id']=$parts[1];}
            elseif($route==='play'){$_GET['view']='play';if(isset(self::playTabs()[$parts[1] ?? ''])){$_GET['tab']=$parts[1];$_GET['sub']=implode('/',array_slice($parts,2));}}
            elseif($route==='live'){$_GET['view']='live';if(in_array($parts[1] ?? '',['now','relay','iptv','radio'],true)){$_GET['tab']=$parts[1];if($parts[1]==='radio'&&!empty($parts[2]))$_GET['id']=$parts[2];}elseif(!empty($parts[1]))$_GET['id']=$parts[1];}
        }
        $home=$startup['home'] ?? null;$body='';$view=(string)($_GET['view'] ?? 'home');
        if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['compat_action'])){
            if(!hash_equals(self::token(),(string)($_POST['compat_token'] ?? ''))){http_response_code(403);$body='<p class="compat-error">This form expired. Reload the page and try again.</p>';}
            elseif($_POST['compat_action']==='logout'){Auth::destroySession();header('Location: '.self::url(),true,303);return;}
            elseif(!in_array($_POST['compat_action'],['shelf','play'],true)){
                try{if((!self::$signed && !in_array(($_POST['compat_action'] ?? ''),['report','convert-request'],true)) || !$home)throw new RuntimeException('Sign in to use this form.');$destination=self::enhancedPost($view,$_POST);header('Location: '.self::url($destination),true,303);return;}
                catch(Throwable $e){http_response_code(400);$body='<div class="compat-panel compat-error">'.self::esc($e instanceof DomainException || $e instanceof RuntimeException || $e instanceof InvalidArgumentException?$e->getMessage():'Could not save this change.').'</div>'.self::link('Back',['view'=>$view,'id'=>(string)($_GET['id'] ?? '')]+($view==='play'?['tab'=>(string)($_GET['tab'] ?? 'games'),'sub'=>(string)($_GET['sub'] ?? '')]:[]));}
            }
            elseif($_POST['compat_action']==='shelf'){
                try{
                    if(!self::$signed || !$home)throw new RuntimeException('Sign in to manage your lists.');
                    HomeHub::save(self::$viewer,['key'=>(string)($_POST['key'] ?? ''),'shelf'=>(string)($_POST['shelf'] ?? ''),'enabled'=>($_POST['enabled'] ?? '')==='1']);
                    header('Location: '.self::url(['view'=>$view,'id'=>(string)($_GET['id'] ?? '')]),true,303);return;
                }catch(Throwable $e){http_response_code(400);$body='<p class="compat-error">'.self::esc($e instanceof DomainException || $e instanceof RuntimeException?$e->getMessage():'Could not save your list.').'</p>';}
            }
        }
        if($body===''){
            if(!$home || ($view==='login'&&!self::$signed)){
                if(self::$signed)$body='<div class="compat-panel"><p>'.self::esc(Access::message($startup['me']['user']['access_state']['state'] ?? 'pending')).'</p></div>';
                else $body='<div class="compat-login compat-panel">'.self::heading('WELCOME BACK','Sign in','Your library, your favourites and your next episode.').'<form method="post" action="'.self::esc(self::url()).'"><input type="hidden" name="first_action" value="login"><p><label>Username <input type="text" name="username" autocomplete="username"></label></p><p><label>Password <input type="password" name="password" autocomplete="current-password"></label></p><button type="submit">Sign in</button></form><p class="compat-error">'.self::esc($error).'</p></div>';
            }else{try{$body=self::content($home);}catch(Throwable $e){http_response_code(400);$body='<div class="compat-panel"><h2>Unable to open this page</h2><p>'.self::esc($e instanceof RuntimeException || $e instanceof DomainException?$e->getMessage():'Please try again.').'</p>'.self::link('Home').'</div>';error_log('Vaultcast compatibility browser: '.$e->getMessage());}}
        }
        $headerSearch='';
        if($home && AreaAccess::allowed(self::$viewer,'library'))$headerSearch='<form class="compat-header-search" role="search" method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="search"><label class="compat-header-search-label" for="compat-header-query">Search movies and shows</label><input id="compat-header-query" type="text" name="q" value="'.self::esc($view==='search'?(string)($_GET['q'] ?? ''):'').'" placeholder="Search movies &amp; shows…" maxlength="200"><button type="submit">Search</button></form>';
        $nav='<div class="compat-header"><a class="compat-brand" href="'.self::esc(self::url()).'"><span class="compat-logo">&#9654;</span> '.self::esc($site).'</a>'.$headerSearch.'<div class="compat-major-nav">';
        $tabs=['home'=>'Home'];if($home && AreaAccess::allowed(self::$viewer,'library'))$tabs['library']='Library';if(!empty($home['features']['live']))$tabs['live']='Live';if($home && AreaAccess::allowed(self::$viewer,'games'))$tabs['play']='Play';$tabs['account']=self::$signed?'Account':'Sign in';
        foreach($tabs as $key=>$label){$target=$key==='account'&&!self::$signed?'login':$key;$selected=$view===$target || ($key==='play'&&in_array($view,['game','emulator'],true)) || ($key==='library'&&in_array($view,['section','show','item','search','collections','collection'],true));$nav.='<a'.($selected?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>$target])).'">'.self::esc($label).'</a>';}
        $nav.='</div></div>';
        if($home){
            $nav.='<div class="compat-subnav">';
            if(in_array($view,['library','section','show','item','search','collections','collection'],true)){
                $libraryTabs=[['label'=>'Discover','params'=>['view'=>'library'],'active'=>$view==='library']];
                foreach($home['sections'] ?? [] as $section)if(($section['type'] ?? '')!=='roms')$libraryTabs[]=['label'=>$section['label'] ?? $section['type'],'params'=>['view'=>'section','id'=>$section['id']],'active'=>$view==='section'&&($_GET['id'] ?? '')===$section['id']];
                $libraryTabs[]=['label'=>'Collections','params'=>['view'=>'collections'],'active'=>in_array($view,['collections','collection'],true)];
                foreach($libraryTabs as $tab)$nav.='<a'.($tab['active']?' class="active" aria-current="page"':'').' href="'.self::esc(self::url($tab['params'])).'">'.self::esc($tab['label']).'</a>';
            }elseif(in_array($view,['play','game','emulator'],true)){
                foreach(self::playTabs() as $tab=>$label)$nav.='<a'.(($view==='play'?($_GET['tab'] ?? 'games'):$tab)===$tab && ($view==='play'||$tab==='games')?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>'play','tab'=>$tab])).'">'.self::esc($label).'</a>';
            }elseif($view==='live'){
                foreach(['now'=>'On now','relay'=>'Relay','iptv'=>'IPTV','stalker'=>'Stalker','radio'=>'Radio'] as $tab=>$label){$selected=($_GET['tab'] ?? 'now')===$tab;$nav.='<a'.($selected?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>'live','tab'=>$tab])).'">'.self::esc($label).'</a>';}
            }else{foreach(['home'=>'Overview','favourites'=>'Favourites','watchlist'=>'Watchlist','calendar'=>'Release calendar','history'=>'History','arrivals'=>'New arrivals','tonight'=>'Find a watch','cinema'=>'Watch together','invitations'=>'Invitations','home-options'=>'Customise Home','lists'=>'Custom lists','friends'=>'Friends','inbox'=>'Inbox','chat'=>'Chat'] as $key=>$label){if($key!=='home'&&!self::$signed)continue;$nav.='<a'.($view===$key?' class="active" aria-current="page"':'').' href="'.self::esc(self::url($key==='home'?['view'=>'home','overview'=>'1']:['view'=>$key])).'">'.self::esc($label).'</a>';}}
            $nav.='</div>';
        }
        if($home && $view!=='home'){
            $back=['view'=>'home'];$label='Back to Home';
            if($view==='item'){$current=Catalogue::item((string)($_GET['id'] ?? ''));if(!empty($current['show'])){$back=['view'=>'show','id'=>Catalogue::showKey((string)$current['section'],(string)$current['show']),'season'=>(int)($current['season'] ?? 0)];$label='Back to show';}elseif(!empty($current['section'])){$back=['view'=>'section','id'=>$current['section']];$label='Back to library';}}
            elseif(in_array($view,['play','game','emulator'],true)){$back=['view'=>'play'];$label='Back to Play';}
            elseif(in_array($view,['section','show','collections','collection','search'],true)){$back=['view'=>'library'];$label='Back to Library';}
            elseif(in_array($view,['profile','compose','create-room'],true)){$back=['view'=>'friends'];$label='Back to Friends';}
            elseif(in_array($view,['chat','inbox'],true)&&!empty($_GET['id'])){$back=['view'=>$view];$label='Back to conversations';}
            $body='<div class="compat-back">'.str_replace('<a class=', '<a data-compat-back="1" class=', self::link($label,$back)).'</div>'.$body;
        }
        if ((self::$viewer['role'] ?? '')==='admin') {
            $required=MaintenanceRequirements::status()['required'];
            if ($required) {
                $notice='<div class="compat-panel"><h2>Maintenance needs your attention</h2><p>Updates do not start these tasks automatically.</p><ul>';
                foreach ($required as $item) $notice.='<li><strong>'.self::esc($item['label']).'</strong>: '.self::esc($item['reason']).'</li>';
                $body=$notice.'</ul>'.self::link('Open maintenance tools',['view'=>'tools']).'</div>'.$body;
            }
        }
        if(in_array($view,array_merge(SiteArtwork::SECTIONS,['collections']),true)){
            $heading='<div class="compat-heading">';$at=strpos($body,$heading);
            if($at!==false)$body=substr_replace($body,'<div class="compat-heading compat-section-heading">'.self::artwork($view),$at,strlen($heading));
        }
        $body=str_replace(['<section ','</section>'],['<div ','</div>'],$body);
        $css=(string)file_get_contents(STREAM_ROOT.'/assets/css/compatibility.css');$scriptVersion=substr(hash_file('sha256',STREAM_ROOT.'/assets/js/compatibility.js'),0,16);release_request_locks();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::esc($site).'</title><style>'.$css.'</style></head><body class="'.self::esc('compat-'.self::$theme.' ').(self::preference('home_compact',false)?'compat-compact':'').'"><a class="compat-skip" href="#compat-content">Skip to content</a>'.$nav.'<div id="compat-shell"><div id="compat-content" role="main">'.$body.'</div><div class="compat-footer"><span>Compatibility mode · '.self::esc(STREAM_VERSION).'</span><a href="'.self::esc($base.'?compat=auto').'">Automatic detection</a><a href="'.self::esc($base.'?compat=off').'">Full interface</a></div></div><script>window.__VC_COMPAT_BASE__='.json_encode($base,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script src="'.self::esc($base.'index.php?asset=assets/js/compatibility.js&v='.$scriptVersion).'"></script></body></html>';
    }
}
