<?php
declare(strict_types=1);

final class CompatibilityBrowser {
    use CompatibilityEnhancements;
    private static $base;
    private static $viewer;
    private static $personal;
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
    private static function mediaUrl(array $item): string {return self::url(['view'=>self::isShow($item)?'show':'item','id'=>self::isShow($item)?($item['show_key'] ?? $item['id']):$item['id']]);}
    private static function poster(array $item,string $size='card'): string {
        $show=self::isShow($item);$id=$show?($item['show_key'] ?? $item['id']):($item['id'] ?? '');$title=$item['title'] ?? 'Untitled';
        $url=self::$base.'api/'.($show?'shows/':'items/').rawurlencode((string)$id).'/poster?variant='.($size==='detail'?'full':'medium');
        $html='<span class="compat-poster compat-poster-'.$size.'"><span class="compat-poster-fallback">'.self::esc(mb_substr((string)$title,0,1)).'</span>';
        if($id!=='' && ($item['has_poster'] ?? true))$html.='<img data-compat-poster="1" loading="lazy" src="'.self::esc($url).'" alt="'.self::esc($title).'" width="240" height="360">';
        return $html.'</span>';
    }
    private static function cards(array $items): string {
        $html='<ul class="compat-cards">';$count=0;
        foreach($items as $item){if(($item['media_kind'] ?? '')==='rom'||($item['type'] ?? '')==='roms'||!AreaAccess::allowed(self::$viewer,AreaAccess::itemArea($item)))continue;
            $count++;$meta=[];if(!empty($item['year']))$meta[]=$item['year'];if(!empty($item['season_count']))$meta[]=$item['season_count'].' seasons';if(!empty($item['episode']))$meta[]='Episode '.$item['episode'];
            $html.='<li><a class="compat-card" href="'.self::esc(self::mediaUrl($item)).'">'.self::poster($item).'<strong>'.self::esc($item['title'] ?? 'Untitled').'</strong><small>'.self::esc(implode(' · ',$meta)).'</small>';
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
    private static function home(array $home): string {
        $name=self::$signed?((self::$viewer['display_name'] ?? '') ?: self::$viewer['username']):'';
        $html='<div class="compat-hero">'.self::heading('YOUR SPACE. YOUR STORIES.',$name!==''?'Welcome back, '.$name:'Make yourself at home.','Your favourites, your next episode and something new to discover.').'<div class="compat-actions">'.(AreaAccess::allowed(self::$viewer,'library')?self::link('Explore the library',['view'=>'library']):'').(!empty($home['features']['live'])?self::link('Watch Live',['view'=>'live']):'').'</div></div>';
        $continue=array_slice($home['continue_watching'] ?? [],0,6);if(!$continue)$continue=self::recentProgress();
        if($continue)$html.='<section class="compat-shelf"><h2>Continue watching</h2>'.self::cards($continue).'</section>';
        $personal=self::personal();$favourites=[];
        foreach($personal['favourites'] ?? [] as $row){$card=$row['card'] ?? [];if(($card['media_kind'] ?? '')!=='rom' && AreaAccess::allowed(self::$viewer,AreaAccess::itemArea($card)))$favourites[]=$card;if(count($favourites)>=6)break;}
        if($favourites)$html.='<section class="compat-shelf"><div class="compat-row-head"><h2>Your favourites</h2>'.self::link('View all',['view'=>'favourites']).'</div>'.self::cards($favourites).'</section>';
        $html.='<section class="compat-shelf"><h2>Your library</h2><div class="compat-categories">';$featured=[];
        foreach($home['sections'] ?? [] as $section){if(($section['type'] ?? '')==='roms')continue;$html.=self::link(($section['label'] ?? $section['type']).' · '.($section['count'] ?? $section['item_count'] ?? 0),['view'=>'section','id'=>$section['id']]);foreach(array_slice($section['items'] ?? [],0,2) as $item)if(count($featured)<8)$featured[]=$item;}
        $html.='</div></section>';if($featured)$html.='<section class="compat-shelf"><h2>Discover</h2>'.self::cards($featured).'</section>';
        return $html;
    }
    private static function player(string $url,bool $audio=false,array $tracks=[],array $config=[]): string {
        if(!preg_match('#^(?:https?://|/)#i',$url))throw new RuntimeException('Invalid stream address.');
        $config['base']=self::$base;if(!empty($config['hls']))$config['hls_url']=PlayerAssets::manifest()['hls'];$tag=$audio?'audio':'video';$html='<'.$tag.' id="compat-media" controls="controls"'.(!empty($config['autoplay'])?' autoplay="autoplay"':'').' preload="none" width="640" height="360" src="'.self::esc($url).'">';
        $preferred=(string)(self::$viewer['prefs']['subtitle_language'] ?? '');$chosen=false;$trackLinks='';$selector='<div id="compat-subtitle-controls" style="display:none"><label>Subtitles <select id="compat-subtitles"><option value="-1">Off</option>';
        foreach($tracks as $index=>$track){$default=!$chosen && $preferred!=='' && ($track['language'] ?? '')===$preferred;if($default)$chosen=true;$html.='<track kind="subtitles" src="'.self::esc($track['url'] ?? '').'" label="'.self::esc($track['label'] ?? 'Subtitles').'" srclang="'.self::esc($track['language'] ?? 'en').'"'.($default?' default="default"':'').'>';$selector.='<option value="'.(int)$index.'"'.($default?' selected="selected"':'').'>'.self::esc($track['label'] ?? 'Subtitles').'</option>';$trackLinks.='<a class="compat-button" href="'.self::esc($track['url'] ?? '').'">'.self::esc($track['label'] ?? 'Subtitles').' file</a>';}
        $selector.='</select></label></div>';
        return $html.'</'.$tag.'>'.($tracks?$selector.'<div class="compat-subtitle-files">'.$trackLinks.'</div>':'').'<p id="compat-player-message" role="status"></p><script>window.__VC_COMPAT_PLAYER__='.json_encode($config+['url'=>$url],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><p><a class="compat-button" href="'.self::esc($url).'">Open stream directly</a></p><p class="compat-note">Use the player controls to start. If this browser cannot play the stream, open the link with a compatible media player. HLS and video codecs depend on your device.</p>';
    }
    private static function content(array $home): string {
        $view=(string)($_GET['view'] ?? 'home');$id=(string)($_GET['id'] ?? '');$page=max(1,min(1000,(int)($_GET['page'] ?? 1)));$query=mb_substr(trim((string)($_GET['q'] ?? '')),0,200);
        if($view==='home')return self::home($home);
        if($view==='library'){
            self::allow('library');$html=self::heading('BROWSE YOUR WORLD','Library','Films, shows, anime and more, all in one place.').'<div class="compat-categories">';
            foreach($home['sections'] ?? [] as $section)if(($section['type'] ?? '')!=='roms')$html.=self::link($section['label'] ?? $section['type'],['view'=>'section','id'=>$section['id']]);
            return $html.'</div>'.self::searchForm(['view'=>'search'],'');
        }
        if(in_array($view,['favourites','watchlist'],true)){
            if(!self::$signed)throw new RuntimeException('Sign in to view your personal lists.');$rows=array_values(array_filter(self::personal()[$view] ?? [],static function($row){return AreaAccess::itemArea($row['card'] ?? [])==='library' && AreaAccess::allowed(self::$viewer,'library');}));
            usort($rows,static function($a,$b){return ($b['added_at'] ?? 0)<=>($a['added_at'] ?? 0);});
            $items=array_map(static function($row){return $row['card'];},array_slice($rows,($page-1)*16,16));
            return self::heading('YOUR SPACE',$view==='favourites'?'Favourites':'Watchlist').self::cards($items).self::pages(['view'=>$view],$page,$page*16<count($rows));
        }
        if($view==='calendar'){
            if(!self::$signed)throw new RuntimeException('Sign in to view your release calendar.');$rows=HomeHub::releaseCalendar(self::$viewer);$html=self::heading('COMING UP','Release calendar','Upcoming episodes for your favourite shows and anime.').'<ul class="compat-list">';
            foreach(array_slice($rows,($page-1)*16,16) as $ep)$html.='<li><span class="compat-date">'.self::esc($ep['date']).'</span><a href="'.self::esc(self::mediaUrl($ep['card'])).'"><strong>'.self::esc($ep['card']['title']).'</strong><span class="compat-note"> · S'.(int)$ep['season'].' E'.(int)$ep['episode'].' · '.self::esc($ep['title']).'</span></a></li>';
            return $html.($rows?'':'<li>No upcoming episodes are currently cached. Favourite a show to follow its releases.</li>').'</ul>'.self::pages(['view'=>'calendar'],$page,$page*16<count($rows));
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
            $html.=self::cards($items);if($missing){$html.='<h3>Not in your library</h3><ul class="compat-list">';foreach($missing as $title)$html.='<li>'.self::esc($title).'</li>';$html.='</ul>';}
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
            $show=$detail['show'];$show['id']=$id;$show['show_key']=$id;$show['kind']='show';$html='<div class="compat-detail">'.self::poster($show,'detail').'<div class="compat-detail-copy">'.self::heading('SERIES',(string)$show['title']).(!empty($show['synopsis'])?'<p class="compat-synopsis">'.self::esc(strip_tags($show['synopsis'])).'</p>':'').self::shelfActions($show).'</div></div>';
            $seasons=$detail['seasons'] ?? [];$season=(int)($_GET['season'] ?? ($seasons[0]['season'] ?? 0));$html.='<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="show"><input type="hidden" name="id" value="'.self::esc($id).'"><label>Season <select name="season">';$episodes=[];
            foreach($seasons as $row){$selected=(int)$row['season']===$season;$html.='<option value="'.(int)$row['season'].'"'.($selected?' selected="selected"':'').'>'.self::esc($row['label']).'</option>';if($selected)$episodes=$row['episodes'];}
            $html.='</select></label><button type="submit">Open season</button></form><ul class="compat-list">';$episodes=array_values(array_filter($episodes,static function($ep){return !empty($ep['id'])&&($ep['available'] ?? true)!==false&&($ep['status'] ?? '')!=='missing';}));
            foreach(array_slice($episodes,($page-1)*16,16) as $ep)$html.='<li>'.self::link('Episode '.($ep['episode'] ?? '?').' - '.($ep['episode_title'] ?? $ep['title'] ?? 'Play'),['view'=>'item','id'=>$ep['id']]).'</li>';
            return $html.'</ul>'.self::pages(['view'=>'show','id'=>$id,'season'=>$season],$page,$page*16<count($episodes));
        }
        if($view==='item'){
            self::allow('library');$item=Catalogue::item($id);if(!$item||AreaAccess::itemArea($item)!=='library')throw new RuntimeException('Media not found.');
            $detail=Catalogue::itemDetail($id);$card=$detail['item'];$html=self::heading(!empty($card['show'])?'EPISODE':'LIBRARY',(string)$card['title']);
            if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST'&&($_POST['compat_action'] ?? '')==='play'){
                if(!is_file((string)($item['path'] ?? '')))throw new RuntimeException('The media file is unavailable.');
                $play=Stream::issueToken(self::$viewer,$id);$html.=self::player($play['url'],($play['media_kind'] ?? '')==='audio',$play['subtitles'] ?? [],['autoplay'=>!empty(self::$viewer['prefs']['autoplay']),'item_id'=>$id,'progress'=>!empty($_POST['from_start'])?null:($play['progress'] ?? null),'save_progress'=>(self::$viewer['id'] ?? '')!=='public','remux_url'=>!empty($play['remux_allowed'])?($play['remux_url'] ?? null):null]);
                if(!empty($play['remux_allowed'])&&!empty($play['remux_url']))$html.='<p><a class="compat-button" data-compat-remux="1" href="'.self::esc($play['remux_url']).'">Open compatible stream</a></p>';
            }else{
                $saved=self::$signed?\Vaultcast\Repository::progressItem((string)self::$viewer['id'],$id):null;
                if(($saved['position'] ?? 0)>=5 && !Catalogue::progressFinished($saved,!empty($item['show'])))$html.='<p class="compat-note">Resume at '.gmdate(($saved['position'] ?? 0)>=3600?'H:i:s':'i:s',(int)$saved['position']).'</p>'.self::form(['view'=>'item','id'=>$id],'Resume','play').self::form(['view'=>'item','id'=>$id],'Start from beginning','play',['from_start'=>'1']);
                else $html.=self::form(['view'=>'item','id'=>$id],'Play','play',['from_start'=>'1']);
            }
            $next=self::nextEpisode($item);if($next)$html.=self::link('Next episode · S'.$next['season'].' E'.$next['episode'],['view'=>'item','id'=>$next['item_id']]);
            $html.='<div class="compat-detail">'.self::poster($card,'detail').'<div class="compat-detail-copy"><h3>'.self::esc($card['title']).'</h3><p class="compat-note">'.self::esc(implode(' · ',array_filter([$card['year'] ?? null,strtoupper($card['ext'] ?? '')]))).'</p>'.(!empty($card['synopsis'])?'<p class="compat-synopsis">'.self::esc(strip_tags($card['synopsis'])).'</p>':'').self::shelfActions($card).'</div></div>';
            return $html;
        }
        if($view==='live')return self::live($id,$page,$query);
        throw new RuntimeException('Page not found.');
    }
    private static function live(string $id,int $page,string $query): string {
        self::allow('live');if(empty(Store::settings()['features']['live']))throw new RuntimeException('Watch Live is disabled.');
        require_once STREAM_ROOT.'/src/Live/store.php';require_once STREAM_ROOT.'/src/Live/epg.php';require_once STREAM_ROOT.'/src/Live/schedule.php';
        $station=relay_load_station();$guide=LiveGuideCache::read(2,true,self::$viewer,static function($hours,$slim)use($station){return epg_unified_guide($station,$hours,$slim,self::$viewer);});
        $html='<h2>Watch Live</h2>';if($id!==''){
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
                $html.=self::link('Retune to live',['view'=>'live','id'=>$id]).'<p><a class="compat-button" href="'.self::esc(self::$base.'api/live/m3u?channel='.rawurlencode($id)).'">Open channel playlist</a></p><p class="compat-note">If automatic retuning is unavailable, use the channel playlist or retune when this programme ends.</p>';
            }
        }
        $html.=self::searchForm(['view'=>'live'],$query);$channels=array_values(array_filter($guide['channels'] ?? [],static function($ch)use($query){return $query===''||mb_stripos($ch['name'] ?? '',$query)!==false;}));
        usort($channels,static function($a,$b){return (int)($a['number'] ?? 0)<=>(int)($b['number'] ?? 0);});$html.='<ul class="compat-list">';
        foreach(array_slice($channels,($page-1)*16,16) as $ch)$html.='<li>'.self::link(($ch['number'] ?? '').' - '.($ch['name'] ?? 'Channel'),['view'=>'live','id'=>$ch['id']]).(!empty($ch['now']['title'])?' '.self::esc($ch['now']['title']):'').'</li>';
        return $html.'</ul>'.self::pages(['view'=>'live','q'=>$query],$page,$page*16<count($channels));
    }
    public static function render(array $startup,string $base,string $site,string $error=''): void {
        self::$base=$base;self::$signed=!empty($startup['me']['user']);self::$personal=null;
        self::$viewer=$startup['me']['user'] ?? guest_user();AreaAccess::remember(self::$viewer);
        $home=$startup['home'] ?? null;$body='';$view=(string)($_GET['view'] ?? 'home');
        if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['compat_action'])){
            if(!hash_equals(self::token(),(string)($_POST['compat_token'] ?? ''))){http_response_code(403);$body='<p class="compat-error">This form expired. Reload the page and try again.</p>';}
            elseif($_POST['compat_action']==='logout'){Auth::destroySession();header('Location: '.self::url(),true,303);return;}
            elseif(!in_array($_POST['compat_action'],['shelf','play'],true)){
                try{if(!self::$signed || !$home)throw new RuntimeException('Sign in to use this form.');$destination=self::enhancedPost($view,$_POST);header('Location: '.self::url($destination),true,303);return;}
                catch(Throwable $e){http_response_code(400);$body='<div class="compat-panel compat-error">'.self::esc($e instanceof DomainException || $e instanceof RuntimeException || $e instanceof InvalidArgumentException?$e->getMessage():'Could not save this change.').'</div>'.self::link('Back',['view'=>$view,'id'=>(string)($_GET['id'] ?? '')]);}
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
        $nav='<div class="compat-header"><a class="compat-brand" href="'.self::esc(self::url()).'"><span class="compat-logo">&#9654;</span> '.self::esc($site).'</a><div class="compat-major-nav">';
        $tabs=['home'=>'Home'];if($home && AreaAccess::allowed(self::$viewer,'library'))$tabs['library']='Library';if(!empty($home['features']['live']))$tabs['live']='Watch Live';$tabs['account']=self::$signed?'Account':'Sign in';
        foreach($tabs as $key=>$label){$target=$key==='account'&&!self::$signed?'login':$key;$selected=$view===$target || ($key==='library'&&in_array($view,['section','show','item','search','collections','collection'],true));$nav.='<a'.($selected?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>$target])).'">'.self::esc($label).'</a>';}
        $nav.='</div></div>';
        if($home){
            $nav.='<div class="compat-subnav">';
            if(in_array($view,['library','section','show','item','search','collections','collection'],true)){
                $nav.=self::link('Discover',['view'=>'library']);foreach($home['sections'] ?? [] as $section)if(($section['type'] ?? '')!=='roms')$nav.=self::link($section['label'] ?? $section['type'],['view'=>'section','id'=>$section['id']]);$nav.=self::link('Collections',['view'=>'collections']);
            }else{foreach(['home'=>'Overview','favourites'=>'Favourites','watchlist'=>'Watchlist','calendar'=>'Release calendar','friends'=>'Friends','inbox'=>'Inbox','chat'=>'Chat'] as $key=>$label){if($key!=='home'&&!self::$signed)continue;$nav.='<a'.($view===$key?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>$key])).'">'.self::esc($label).'</a>';}}
            $nav.='</div>';
        }
        if($home && $view!=='home'){
            $back=['view'=>'home'];$label='Back to Home';
            if($view==='item'){$current=Catalogue::item((string)($_GET['id'] ?? ''));if(!empty($current['show'])){$back=['view'=>'show','id'=>Catalogue::showKey((string)$current['section'],(string)$current['show']),'season'=>(int)($current['season'] ?? 0)];$label='Back to show';}elseif(!empty($current['section'])){$back=['view'=>'section','id'=>$current['section']];$label='Back to library';}}
            elseif(in_array($view,['section','show','collections','collection','search'],true)){$back=['view'=>'library'];$label='Back to Library';}
            elseif(in_array($view,['profile','compose','create-room'],true)){$back=['view'=>'friends'];$label='Back to Friends';}
            elseif(in_array($view,['chat','inbox'],true)&&!empty($_GET['id'])){$back=['view'=>$view];$label='Back to conversations';}
            $body='<div class="compat-back">'.str_replace('<a class=', '<a data-compat-back="1" class=', self::link($label,$back)).'</div>'.$body;
        }
        $css=(string)file_get_contents(STREAM_ROOT.'/assets/css/compatibility.css');$scriptVersion=substr(hash_file('sha256',STREAM_ROOT.'/assets/js/compatibility.js'),0,16);release_request_locks();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::esc($site).'</title><style>'.$css.'</style></head><body><a class="compat-skip" href="#compat-content">Skip to content</a>'.$nav.'<div id="compat-shell"><div id="compat-content" role="main">'.$body.'</div><div class="compat-footer"><span>Compatibility mode · '.self::esc(STREAM_VERSION).'</span><a href="'.self::esc($base.'?compat=auto').'">Automatic detection</a><a href="'.self::esc($base.'?compat=off').'">Full interface</a></div></div><script src="'.self::esc($base.'index.php?asset=assets/js/compatibility.js&v='.$scriptVersion).'"></script></body></html>';
    }
}
