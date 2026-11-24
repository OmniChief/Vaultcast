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
        $html='<form class="compat-action'.($action==='play'?' compat-play-primary':'').'" method="post" action="'.self::esc(self::url($params)).'"><input type="hidden" name="compat_action" value="'.self::esc($action).'"><input type="hidden" name="compat_token" value="'.self::esc(self::token()).'">';
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
        if($page===1&&!$more)return '';
        return '<div class="compat-pages" role="navigation" aria-label="Pages">'.($page>1?self::link('← Previous',['page'=>$page-1]+$params):'').'<span class="compat-page-current" aria-current="page">Page '.$page.'</span>'.($more?self::link('Next →',['page'=>$page+1]+$params):'').'</div>';
    }
    private static function choice(string $label,array $params,bool $active=false): string {
        return '<a class="compat-choice'.($active?' active':'').'"'.($active?' aria-current="true"':'').' href="'.self::esc(self::url($params)).'">'.self::esc($label).'</a>';
    }
    private static function browseRow(string $title,string $meta,array $params,string $number='',bool $active=false): string {
        return '<a class="compat-browse-row'.($active?' active':'').'"'.($active?' aria-current="true"':'').' href="'.self::esc(self::url($params)).'">'.($number!==''?'<span class="compat-row-number">'.self::esc($number).'</span>':'').'<span class="compat-browse-copy"><strong>'.self::esc($title).'</strong>'.($meta!==''?'<small>'.self::esc($meta).'</small>':'').'</span><span class="compat-row-arrow" aria-hidden="true">&#8250;</span></a>';
    }
    private static function disclosure(string $id,string $label,string $content,bool $open=false): string {
        return '<div class="compat-disclosure"><button type="button" class="compat-disclosure-toggle" data-compat-disclosure="'.$id.'" aria-controls="'.$id.'" aria-expanded="true" style="display:none">'.self::esc($label).'</button><div class="compat-disclosure-content" id="'.$id.'" data-compat-open="'.($open?'1':'0').'">'.$content.'</div></div>';
    }
    private static function token(): string { return hash('sha256','compat-form|'.($_COOKIE[Auth::COOKIE] ?? 'anonymous')); }
    private static function personal(): array {
        if(self::$personal===null)self::$personal=self::$signed ? \Vaultcast\Repository::document('home-hub:'.self::$viewer['id'],[]) : [];
        return self::$personal;
    }
    private static function isShow(array $item): bool {return ($item['kind'] ?? '')==='show'||($item['media_kind'] ?? '')==='show'||(!empty($item['show_key'])&&empty($item['media_kind']));}
    private static function mediaUrl(array $item,array $context=[]): string {return self::url($context+['view'=>self::isShow($item)?'show':'item','id'=>self::isShow($item)?($item['show_key'] ?? $item['id']):$item['id']]);}
    private static function poster(array $item,string $size='card',int $percent=0): string {
        $show=self::isShow($item);$id=$show?($item['show_key'] ?? $item['id']):($item['id'] ?? '');$title=$item['title'] ?? 'Untitled';
        $url=self::$base.'api/'.($show?'shows/':'items/').rawurlencode((string)$id).'/poster?variant='.($size==='detail'?'full':'medium');
        $html='<span class="compat-poster compat-poster-'.$size.'"><span class="compat-poster-fallback">'.self::esc($size==='card'?(string)$title:mb_substr((string)$title,0,1)).'</span>';
        if($id!=='' && ($item['has_poster'] ?? true))$html.='<img data-compat-poster="1" loading="lazy" src="'.self::esc($url).'" alt="'.self::esc($title).'" width="240" height="360">';
        if($percent>0)$html.='<span class="compat-progress compat-poster-progress"><span style="width:'.min(100,$percent).'%"></span></span>';
        return $html.'</span>';
    }
    private static function cards(array $items,array $context=[]): string {
        $html='<ul class="compat-cards">';$count=0;
        foreach($items as $item){if(($item['media_kind'] ?? '')==='rom'||($item['type'] ?? '')==='roms'||!AreaAccess::allowed(self::$viewer,AreaAccess::itemArea($item))||!Parental::allows(self::$viewer,$item))continue;
            $count++;$meta=[];if(!empty($item['year']))$meta[]=$item['year'];if(!empty($item['season_count']))$meta[]=$item['season_count'].' seasons';if(!empty($item['episode']))$meta[]='Episode '.$item['episode'];
            $progress=$item['progress'] ?? [];$duration=(float)($progress['duration'] ?? 0);$position=(float)($progress['position'] ?? 0);$percent=$duration>0&&$position>0?min(100,(int)round($position/$duration*100)):0;
            $html.='<li><a class="compat-card" href="'.self::esc(self::mediaUrl($item,$context)).'" title="'.self::esc($item['title'] ?? 'Untitled').'">'.self::poster($item,'card',$percent).'<span class="compat-card-caption"><strong>'.self::esc($item['title'] ?? 'Untitled').'</strong><small>'.self::esc(implode(' · ',$meta)).'</small></span></a></li>';
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
        $html='<div class="compat-hero">'.self::artwork().'<div class="compat-hero-copy">'.self::heading('YOUR SPACE. YOUR STORIES.',$name!==''?'Welcome back, '.$name:'Make yourself at home.','Your favourites, your next episode and something new to discover.').'<div class="compat-actions">'.(AreaAccess::allowed(self::$viewer,'library')?'<a class="compat-button compat-primary" href="'.self::esc(self::url(['view'=>'library'])).'">Explore the library</a>':'').(!empty($home['features']['live'])?self::link('Live',['view'=>'live']):'').'</div></div></div>';
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
        $config+=['autoplay'=>(self::$viewer['prefs']['autoplay_on_open'] ?? true)!==false];$config['base']=self::$base;if(!empty($config['hls']))$config['hls_url']=PlayerAssets::manifest()['hls'];$tag=$audio?'audio':'video';$html=($audio?'':'<div class="compat-edge-crop-control"><button id="compat-edge-crop" type="button" aria-pressed="false">TV edge crop: Off</button><span>Toggle before entering fullscreen</span></div><div class="compat-video-stage">').'<'.$tag.' id="compat-media" controls="controls"'.(!empty($config['autoplay'])?' autoplay="autoplay"':'').' preload="none" width="640" height="360" src="'.self::esc($url).'">';
        $preferred=(string)(self::$viewer['prefs']['subtitle_language'] ?? '');$chosen=false;$trackLinks='';$selector='<div id="compat-subtitle-controls" style="display:none"><label>Subtitles <select id="compat-subtitles"><option value="-1">Off</option>';
        foreach($tracks as $index=>$track){$default=!$chosen && $preferred!=='' && ($track['language'] ?? '')===$preferred;if($default)$chosen=true;$html.='<track kind="subtitles" src="'.self::esc($track['url'] ?? '').'" label="'.self::esc($track['label'] ?? 'Subtitles').'" srclang="'.self::esc($track['language'] ?? 'en').'"'.($default?' default="default"':'').'>';$selector.='<option value="'.(int)$index.'"'.($default?' selected="selected"':'').'>'.self::esc($track['label'] ?? 'Subtitles').'</option>';$trackLinks.='<a class="compat-button" href="'.self::esc($track['url'] ?? '').'">'.self::esc($track['label'] ?? 'Subtitles').' file</a>';}
        $selector.='</select></label></div>';
        return $html.'</'.$tag.'>'.($audio?'':'<button id="compat-centre-play" class="compat-centre-play" type="button" style="display:none" aria-label="Play video"><span aria-hidden="true">&#9654;</span><span id="compat-centre-label">Play</span></button></div>').($tracks?$selector.'<div class="compat-subtitle-files">'.$trackLinks.'</div>':'').'<p id="compat-player-message" role="status"></p><script>window.__VC_COMPAT_PLAYER__='.json_encode($config+['url'=>$url],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><p><a class="compat-button" href="'.self::esc($url).'">Open stream directly</a></p><p class="compat-note">Use the player controls to start. If this browser cannot play the stream, open the link with a compatible media player. HLS and video codecs depend on your device.</p>';
    }

    private static function miscCheck(string $label,string $name,bool $checked=false): string {
        return '<label class="compat-check"><input type="checkbox" name="'.self::esc($name).'" value="1"'.($checked?' checked="checked"':'').'> '.self::esc($label).'</label>';
    }
    private static function eventForm(array $params,array $choices,array $event=[]): string {
        $editing=!empty($event['id']);$allDay=!empty($event['all_day']);$tzName=(string)($event['tz'] ?? date_default_timezone_get());$tz=new DateTimeZone($tzName);
        $start=(new DateTimeImmutable('@'.(int)($event['series_start'] ?? time())))->setTimezone($tz);
        $end=(new DateTimeImmutable('@'.(int)($event['series_end'] ?? time()+3600)))->setTimezone($tz);
        if($allDay)$end=$end->modify('-1 day');
        $repeat=(array)($event['repeat'] ?? []);
        $html=self::beginForm($params,$editing?'extra-event-edit':'extra-event-create',false,['tz'=>$tzName]).'<h3>'.($editing?'Edit event / entire series':'Add event').'</h3>'.self::select('Calendar','calendar_id',$choices,(string)($event['calendar_id'] ?? key($choices))).self::field('Title','title',(string)($event['title'] ?? ''),'text',200).self::miscCheck('All-day event','all_day',$allDay).self::field('Start (YYYY-MM-DD HH:MM, date only for all-day)','start',$start->format($allDay?'Y-m-d':'Y-m-d H:i')).self::field('End (optional; inclusive date for all-day)','end',$editing?$end->format($allDay?'Y-m-d':'Y-m-d H:i'):'').self::field('Location','location',(string)($event['location'] ?? ''),'text',200).'<label>Notes<textarea name="notes" rows="4" maxlength="4000">'.self::esc($event['notes'] ?? '').'</textarea></label>'.self::select('Repeat','repeat',[''=>'None','DAILY'=>'Daily','WEEKLY'=>'Weekly','MONTHLY'=>'Monthly','YEARLY'=>'Yearly'],(string)($repeat['freq'] ?? '')).self::field('Repeat every','interval',(string)($repeat['interval'] ?? 1),'number').'<p class="compat-note">Optional weekly days (default: start day)</p>';
        foreach(['MO'=>'Monday','TU'=>'Tuesday','WE'=>'Wednesday','TH'=>'Thursday','FR'=>'Friday','SA'=>'Saturday','SU'=>'Sunday'] as $key=>$label)$html.=self::miscCheck($label,'days[]',in_array($key,$repeat['days'] ?? [],true));$html.=self::field('Repeat until (YYYY-MM-DD, optional)','until',(string)($repeat['until'] ?? '')).self::field('Number of occurrences (0 = no limit)','count',(string)($repeat['count'] ?? 0),'number').self::field('Alert minutes before event (comma-separated, blank = none)','reminders',implode(',',(array)($event['reminders'] ?? []))).'<button type="submit">Save event</button></form>';
        return $html;
    }
    private static function compatPictureRows(string $source,array $settings=[]): array {
        $rows=[];
        if(in_array($source,['library','both'],true)&&AreaAccess::allowed(self::$viewer,'library')&&!Parental::restricted(self::$viewer)){
            $data=CompatibilityApi::call('/misc/ambient/stills','GET',[],['kind'=>in_array($settings['art'] ?? '',['posters','frames','mixed'],true)?$settings['art']:'posters','type'=>in_array($settings['library'] ?? '',['both','movies','tv'],true)?$settings['library']:'both','n'=>12,'order'=>($settings['order'] ?? '')==='random'?'random':'recent','variant'=>'medium']);
            foreach($data['stills'] ?? [] as $row)$rows[]=['url'=>rtrim(self::$base,'/').$row['url'],'title'=>$row['title']];
        }
        if(in_array($source,['mine','both'],true)){
            $data=CompatibilityApi::call('/misc/pictures');
            $pictures=$data['pictures'] ?? [];
            if(($settings['mode'] ?? '')==='single'&&!empty($settings['picture'])){
                foreach($pictures as $row)if($row['id']===$settings['picture']){$pictures=[$row];break;}
            }
            foreach(array_slice($pictures,0,30) as $row)$rows[]=['url'=>rtrim(self::$base,'/').$row['url'],'title'=>$row['name'],'id'=>$row['id']];
        }
        if($source==='urls')foreach(array_slice(preg_split('/\s+/',trim((string)($settings['urls'] ?? ''))),0,30) as $url)if(Webcams::validUrl($url))$rows[]=['url'=>$url,'title'=>''];
        return $rows;
    }
    private static function compatSlideshow(array $rows,array $settings=[]): string {
        if(!$rows)return '<p class="compat-empty">No pictures available. Choose another source or upload pictures.</p>';
        if(!empty($settings['shuffle']))shuffle($rows);
        $rows=array_values(array_slice($rows,0,36));$first=$rows[0];$seconds=max(4,min(3600,(int)($settings['seconds'] ?? 20)));
        $html='<div class="compat-slideshow" data-compat-slides="1" data-seconds="'.$seconds.'"><div class="compat-slide-stage'.(($settings['fit'] ?? '')==='cover'?' compat-slide-cover':'').'"><img class="compat-slide-image" src="'.self::esc($first['url']).'" alt="'.self::esc($first['title'] ?? '').'"></div><p class="compat-slide-caption">'.self::esc($first['title'] ?? '').'</p>';
        if(count($rows)>1){
            $html.='<div class="compat-slide-buttons" style="display:none"><button type="button" data-slide-action="prev">Previous</button><button type="button" data-slide-action="toggle">Play slideshow</button><button type="button" data-slide-action="next">Next</button><span class="compat-slide-index">1 / '.count($rows).'</span></div>';
            $html.='<div class="compat-slide-list"><p class="compat-note">Select a picture</p>';
            foreach($rows as $row)$html.='<a class="compat-slide-source" href="'.self::esc($row['url']).'" data-slide-title="'.self::esc($row['title'] ?? '').'">'.self::esc(($row['title'] ?? '')?:'Picture').'</a> ';
            $html.='</div>';
        }
        return $html.'</div>';
    }
    private static function compatCamera(array $cam): string {
        $url=(string)($cam['url'] ?? '');if(!Webcams::validUrl($url))return '<p>Camera address unavailable.</p>';
        $kind=(string)($cam['kind'] ?? 'auto');$name=(string)($cam['name'] ?? 'Webcam');
        if($kind==='auto')$kind=preg_match('/\.(?:jpg|jpeg|png|gif|webp)(?:[?#]|$)/i',$url)?'image':(preg_match('/\.(?:mp4|webm|m3u8)(?:[?#]|$)/i',$url)?'video':'embed');
        $html='<div class="compat-camera"><h3>'.self::esc($name).'</h3>';
        if(in_array($kind,['image','mjpeg'],true))$html.='<img class="compat-extra-picture" src="'.self::esc($url).'" alt="'.self::esc($name).'">';
        elseif($kind==='video')$html.='<video controls="controls" preload="none" playsinline="playsinline" src="'.self::esc($url).'">Your device does not support native video.</video>';
        else $html.='<p class="compat-note">This camera opens in its own player. Embedded and YouTube players may require a newer browser.</p>';
        return $html.'<a class="compat-button" href="'.self::esc($url).'" target="_blank" rel="noopener noreferrer">Open camera source</a></div>';
    }
    private static function compatCamFields(array $cam=[],bool $admin=false): string {
        $types=['auto'=>'Automatic','image'=>'Still image','mjpeg'=>'MJPEG','video'=>'Native video / HLS','youtube'=>'YouTube','embed'=>'External player'];
        $html=self::field('Name','name',(string)($cam['name'] ?? ''),'text',120);
        if(!$admin||!$cam)$html.=self::field('Camera URL','url',(string)($cam['url'] ?? ''),'text',1000);
        $html.=self::select('Source type','kind',$types,(string)($cam['kind'] ?? 'auto'));
        if($admin)$html.=self::miscCheck('Available in Ambient','enabled_ambient',!empty($cam['enabled_ambient']));
        else $html.=self::field('Refresh interval (seconds)','refresh',(string)($cam['refresh'] ?? 30),'number');
        return $html;
    }
    private static function extraPost(string $view,array $body): ?array {
        $action=(string)($body['compat_action'] ?? '');
        if(strpos($action,'extra-')!==0)return null;
        $id=(string)($_GET['id'] ?? '');
        if(!self::$signed)throw new RuntimeException('Sign in first.');
        if(in_array($action,['extra-parental-lock','extra-parental-unlock','extra-parental-save'],true)){
            if($action==='extra-parental-lock')CompatibilityApi::call('/me/parental/lock','POST');
            elseif($action==='extra-parental-unlock')CompatibilityApi::call('/me/parental/unlock','POST',['pin'=>(string)($body['current_pin'] ?? '')]);
            else CompatibilityApi::call('/me/parental','POST',self::parentalPayload($body));
            return ['view'=>'parental'];
        }
        if($action==='extra-admin-parental'){
            self::toolPermission('admin');
            CompatibilityApi::call('/admin/users/'.rawurlencode($id).'/parental','POST',self::parentalPayload($body,true));
            return ['view'=>'edit-user','id'=>$id];
        }
        if(strpos($action,'extra-request-')===0){
            self::allow('library');
            $tab=(string)($_GET['tab'] ?? 'mine');
            if($action==='extra-request-create'){
                $payload=['media_type'=>(string)($body['media_type'] ?? ''),'source'=>(string)($body['source'] ?? ''),'ext_id'=>(string)($body['ext_id'] ?? ''),'note'=>(string)($body['note'] ?? ''),'seasons'=>!empty($body['season'])?[(int)$body['season']]:'all'];
                CompatibilityApi::call('/requests','POST',$payload);$tab='mine';
            }elseif($action==='extra-request-cancel'){
                CompatibilityApi::call('/requests/'.rawurlencode($id).'/cancel','POST');
            }elseif($action==='extra-request-decide'){
                $change=(string)($body['change'] ?? '');
                if($change==='delete'&&($body['confirm'] ?? '')!=='DELETE')throw new RuntimeException('Type DELETE to confirm deletion.');
                CompatibilityApi::call('/requests/manage/decide','POST',['ids'=>[(int)$id],'action'=>$change,'reason'=>(string)($body['reason'] ?? ''),'acquire'=>null]);$tab='manage';
            }else throw new RuntimeException('Unknown request action.');
            return ['view'=>'requests','tab'=>$tab];
        }
        $tab=(string)($_GET['tab'] ?? 'calendar');
        if(strpos($action,'extra-webcam-admin-')===0){
            self::toolPermission('admin');$path='/misc/admin/webcams';
            $payload=['name'=>(string)($body['name'] ?? ''),'kind'=>(string)($body['kind'] ?? 'auto'),'enabled_ambient'=>!empty($body['enabled_ambient'])];
            if($action==='extra-webcam-admin-add')CompatibilityApi::call($path,'POST',$payload+['url'=>(string)($body['url'] ?? '')]);
            elseif($action==='extra-webcam-admin-edit')CompatibilityApi::call($path.'/'.rawurlencode($id),'POST',$payload);
            elseif($action==='extra-webcam-admin-delete'){if(($body['confirm'] ?? '')!=='DELETE')throw new RuntimeException('Type DELETE to confirm.');CompatibilityApi::call($path.'/'.rawurlencode($id),'DELETE');}
            elseif($action==='extra-webcam-admin-settings')CompatibilityApi::call('/misc/admin/ambient','POST',['idle_minutes'=>(int)($body['idle_minutes'] ?? 0),'radio'=>(string)($body['radio'] ?? '')]);
            else throw new RuntimeException('Unknown webcam action.');
            return ['view'=>'misc','tab'=>'ambient-admin'];
        }
        if(strpos($action,'extra-webcam-')===0){
            $data=CompatibilityApi::call('/misc/prefs');$cams=$data['webcams'] ?? [];
            if($action==='extra-webcam-add'||$action==='extra-webcam-edit'){
                $url=trim((string)($body['url'] ?? ''));if(!Webcams::validUrl($url))throw new RuntimeException('Enter a valid HTTP or HTTPS camera address.');
                $cam=['name'=>(string)($body['name'] ?? ''),'url'=>$url,'kind'=>(string)($body['kind'] ?? 'auto'),'refresh'=>(int)($body['refresh'] ?? 30)];
                if($action==='extra-webcam-add'){if(count($cams)>=40)throw new RuntimeException('Camera limit reached.');$cams[]=$cam+['id'=>bin2hex(random_bytes(4))];}
                else {$found=false;foreach($cams as &$old)if($old['id']===$id){$old=$cam+['id'=>$id];$found=true;break;}unset($old);if(!$found)throw new RuntimeException('Camera not found.');}
            }elseif($action==='extra-webcam-delete'){$cams=array_values(array_filter($cams,static function($cam)use($id){return $cam['id']!==$id;}));}
            else throw new RuntimeException('Unknown webcam action.');
            CompatibilityApi::call('/misc/prefs','POST',['webcams'=>$cams]);return ['view'=>'misc','tab'=>'webcams'];
        }
        if($action==='extra-ambient-prefs'){
            $data=CompatibilityApi::call('/misc/prefs');$prefs=(array)($data['ambient'] ?? []);
            $bg=(string)($body['bg'] ?? 'gradient');if(!in_array($bg,['gradient','library','pictures','webcam'],true))throw new RuntimeException('Choose an available background.');
            $prefs['bg']=$bg;$prefs['library']=(string)($body['library'] ?? 'posters');$prefs['webcam']=(string)($body['webcam'] ?? '');$prefs['clock']=array_replace((array)($prefs['clock'] ?? []),['show'=>!empty($body['clock']),'date'=>!empty($body['date'])]);
            CompatibilityApi::call('/misc/prefs','POST',['ambient'=>$prefs]);return ['view'=>'misc','tab'=>'ambient'];
        }
        if(strpos($action,'extra-drop-admin-')===0){
            self::toolPermission('admin');
            if($action==='extra-drop-admin-settings'){
                $payload=[];foreach(['enabled','user_role_upload','public_links','anonymous','confirm_anonymous','nginx_downloads'] as $k)$payload[$k]=!empty($body[$k]);
                foreach(['default_quota_mb','max_file_mb','anon_max_file_mb','anon_quota_mb','anon_max_days'] as $k)$payload[$k]=(int)($body[$k] ?? 0);
                $payload['quotas']=(array)($body['quotas'] ?? []);
                CompatibilityApi::call('/drop/admin/settings','POST',$payload);
            }elseif($action==='extra-drop-admin-delete'){
                if(($body['confirm'] ?? '')!=='DELETE')throw new RuntimeException('Type DELETE to confirm.');
                CompatibilityApi::call('/drop/admin/items/'.rawurlencode($id),'DELETE');
            }elseif($action==='extra-drop-admin-cleanup'){
                CompatibilityApi::call('/drop/admin/cleanup','POST');
                return ['view'=>'tool','id'=>'jobs'];
            }else throw new RuntimeException('Unknown Drop administration action.');
            return ['view'=>'misc','tab'=>'drop-admin'];
        }
        if(strpos($action,'extra-board-')===0){
            $data=CompatibilityApi::call('/misc/boards');$boards=$data['boards'] ?? [];$default=$data['default'] ?? '';
            if($action==='extra-board-create'){
                if(count($boards)>=12)throw new RuntimeException('Board limit reached.');
                $id=bin2hex(random_bytes(5));$boards[]=['id'=>$id,'name'=>(string)($body['name'] ?? 'Board'),'widgets'=>[]];
                if(!$default)$default=$id;
            }else{
                $found=false;
                foreach($boards as $i=>&$board){
                    if($board['id']!==$id)continue;$found=true;
                    if($action==='extra-board-default'){$default=$id;break;}
                    if($action==='extra-board-lock'){$board['locked']=empty($board['locked']);break;}
                    if(!empty($board['locked']))throw new RuntimeException('Unlock this board before editing.');
                    if($action==='extra-board-rename'){$board['name']=(string)($body['name'] ?? 'Board');if(isset($body['theme']))$board['theme']=(string)$body['theme'];if(isset($body['color']))$board['color']=(string)$body['color'];}
                    elseif($action==='extra-board-delete'){
                        if(($body['confirm'] ?? '')!=='DELETE')throw new RuntimeException('Type DELETE to confirm.');
                        unset($boards[$i]);if($default===$id)$default='';$id='';
                    }elseif($action==='extra-board-add'){
                        $type=(string)($body['type'] ?? '');
                        if(!in_array($type,$data['widgets'] ?? [],true))throw new RuntimeException('Invalid widget.');
                        if(count($board['widgets'] ?? [])>=40)throw new RuntimeException('Widget limit reached.');
                        $y=0;foreach($board['widgets'] ?? [] as $w)$y=max($y,(int)$w['y']+(int)$w['h']);
                        if($y>400)throw new RuntimeException('Board is full.');
                        $board['widgets'][]=['id'=>bin2hex(random_bytes(4)),'type'=>$type,'x'=>0,'y'=>$y,'w'=>12,'h'=>3,'settings'=>[]];
                    }elseif(in_array($action,['extra-board-remove-widget','extra-board-edit-widget'],true)){
                        $foundWidget=false;
                        foreach($board['widgets'] as $j=>&$widget){
                            if($widget['id']!==($body['widget'] ?? ''))continue;$foundWidget=true;
                            if($action==='extra-board-remove-widget')unset($board['widgets'][$j]);
                            elseif($widget['type']==='notes'&&isset($body['text']))$widget['settings']['text']=(string)$body['text'];
                            elseif($widget['type']==='rss'&&isset($body['url']))$widget['settings']['url']=(string)$body['url'];
                            elseif($widget['type']==='weather'&&isset($body['lat'],$body['lon'])){
                                if(!is_numeric($body['lat'])||!is_numeric($body['lon'])||abs((float)$body['lat'])>90||abs((float)$body['lon'])>180)throw new RuntimeException('Enter valid latitude and longitude.');
                                $widget['settings']['loc']=['name'=>(string)($body['name'] ?? ''),'region'=>(string)($body['region'] ?? ''),'lat'=>(float)$body['lat'],'lon'=>(float)$body['lon']];
                                $widget['settings']['units']=($body['units'] ?? '')==='f'?'f':'c';
                            }
                            if($action==='extra-board-edit-widget'){
                                foreach(['x','y','w','h'] as $key)if(isset($body[$key]))$widget[$key]=(int)$body[$key];
                                if(in_array($widget['type'],['frame','slideshow'],true)&&isset($body['seconds'])){
                                    $s=&$widget['settings'];$s['seconds']=max(4,min(3600,(int)$body['seconds']));$s['fit']=($body['fit'] ?? '')==='cover'?'cover':'contain';$s['shuffle']=!empty($body['shuffle']);
                                    if($widget['type']==='frame'){$s['mode']=($body['mode'] ?? '')==='single'?'single':'slideshow';$s['picture']=(string)($body['picture'] ?? '');}
                                    else {$s['source']=(string)($body['source'] ?? 'library');$s['art']=(string)($body['art'] ?? 'posters');$s['library']=(string)($body['library'] ?? 'both');$s['order']=(string)($body['order'] ?? 'recent');$s['urls']=(string)($body['urls'] ?? '');}
                                    unset($s);
                                }
                                if($widget['type']==='webcams'&&isset($body['camera_selection']))$widget['settings']['ids']=array_values(array_filter(array_map('strval',(array)($body['camera_ids'] ?? []))));
                            }
                            break;
                        }unset($widget);
                        if(!$foundWidget)throw new RuntimeException('Widget not found.');
                        $board['widgets']=array_values($board['widgets']);
                    }else throw new RuntimeException('Unknown board action.');
                    break;
                }unset($board);
                if(!$found)throw new RuntimeException('Board not found.');
            }
            CompatibilityApi::call('/misc/boards','POST',['boards'=>array_values($boards),'default'=>$default,'rotate'=>(int)($data['rotate'] ?? 0)]);
            return ['view'=>'misc','tab'=>'board']+($id!==''?['id'=>$id]:[]);
        }
        $urlId=rawurlencode($id);
        switch($action){
            case 'extra-calendar-create':
            case 'extra-calendar-edit':
                CompatibilityApi::call('/misc/cal/calendars'.($action==='extra-calendar-edit'?'/'.$urlId:''),'POST',['name'=>(string)($body['name'] ?? ''),'kind'=>(string)($body['kind'] ?? 'local'),'url'=>(string)($body['url'] ?? ''),'episodes'=>(string)($body['episodes'] ?? ''),'show_reminders'=>!empty($body['show_reminders']),'colour'=>(string)($body['colour'] ?? '#59e9ff')]);break;
            case 'extra-calendar-feed': CompatibilityApi::call('/misc/cal/calendars/'.$urlId.'/feed','POST',['action'=>(string)($body['change'] ?? '')]);break;
            case 'extra-calendar-share': CompatibilityApi::call('/misc/cal/calendars/'.$urlId.'/share','POST',['username'=>(string)($body['username'] ?? ''),'role'=>(string)($body['role'] ?? 'view')]);break;
            case 'extra-calendar-import': CompatibilityApi::call('/misc/cal/calendars/'.$urlId.'/import','POST',['ics'=>(string)($body['ics'] ?? '')]);break;
            case 'extra-event-create':
            case 'extra-event-edit':
                $allDay=!empty($body['all_day']);$start=trim((string)($body['start'] ?? ''));$end=trim((string)($body['end'] ?? ''));$tzName=(string)($body['tz'] ?? date_default_timezone_get());$tz=new DateTimeZone($tzName);
                if(!$allDay){
                    $parse=static function(string $value)use($tz){$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value,$tz);if(!$d||$d->format('Y-m-d H:i')!==$value)throw new RuntimeException('Enter dates and times as YYYY-MM-DD HH:MM.');return $d->getTimestamp();};
                    $start=$parse($start);$end=$end===''?$start+3600:$parse($end);
                }else{$end=$end?:$start;}
                $reminders=[];foreach(explode(',',(string)($body['reminders'] ?? '')) as $minute)if(trim($minute)!==''){if(!ctype_digit(trim($minute)))throw new RuntimeException('Enter alert minutes as numbers.');$reminders[]=(int)$minute;}
                CompatibilityApi::call('/misc/cal/events'.($action==='extra-event-edit'?'/'.$urlId:''),'POST',['calendar_id'=>(string)($body['calendar_id'] ?? ''),'title'=>(string)($body['title'] ?? ''),'all_day'=>$allDay,'start'=>$start,'end'=>$end,'tz'=>$tzName,'location'=>(string)($body['location'] ?? ''),'notes'=>(string)($body['notes'] ?? ''),'repeat'=>['freq'=>(string)($body['repeat'] ?? ''),'interval'=>(int)($body['interval'] ?? 1),'days'=>(array)($body['days'] ?? []),'until'=>(string)($body['until'] ?? ''),'count'=>(int)($body['count'] ?? 0)],'reminders'=>$reminders]);
                return ['view'=>'misc','tab'=>'calendar','offset'=>(int)($_GET['offset'] ?? 0),'page'=>(int)($_GET['page'] ?? 1)];
            case 'extra-event-delete': CompatibilityApi::call('/misc/cal/events/'.$urlId,'DELETE',[],['occurrence'=>(int)($body['occurrence'] ?? 0)]);break;
            case 'extra-calendar-delete': CompatibilityApi::call('/misc/cal/calendars/'.$urlId,'DELETE');break;
            case 'extra-reminder-create':
                CompatibilityApi::call('/misc/reminders','POST',['title'=>(string)($body['title'] ?? ''),'note'=>(string)($body['note'] ?? ''),'time'=>(string)($body['time'] ?? ''),'calendar'=>true,'schedule'=>['type'=>(string)($body['repeat'] ?? 'once'),'start'=>(string)($body['start'] ?? ''),'every'=>max(1,(int)($body['every'] ?? 1))]]);break;
            case 'extra-reminder-edit':
                $data=CompatibilityApi::call('/misc/reminders');$old=null;foreach($data['reminders'] ?? [] as $row)if($row['id']===$id)$old=$row;
                if(!$old)throw new RuntimeException('Reminder not found.');
                $old['title']=(string)($body['title'] ?? '');$old['note']=(string)($body['note'] ?? '');$old['time']=(string)($body['time'] ?? '');$old['lead']=(int)($body['lead'] ?? 0);$old['calendar']=!empty($body['calendar']);
                CompatibilityApi::call('/misc/reminders/'.$urlId,'POST',$old);break;
            case 'extra-reminder-done': CompatibilityApi::call('/misc/reminders/'.$urlId.'/done','POST');break;
            case 'extra-reminder-snooze': CompatibilityApi::call('/misc/reminders/'.$urlId.'/snooze','POST',['minutes'=>(int)($body['minutes'] ?? 60)]);break;
            case 'extra-reminder-delete': CompatibilityApi::call('/misc/reminders/'.$urlId,'DELETE');break;
            case 'extra-picture-upload':
                CompatibilityApi::call('/misc/pictures','POST');break;
            case 'extra-picture-rename':
                CompatibilityApi::call('/misc/pictures/'.$urlId,'POST',['name'=>(string)($body['name'] ?? '')]);break;
            case 'extra-picture-delete': CompatibilityApi::call('/misc/pictures/'.$urlId,'DELETE');break;
            case 'extra-drop-create':
                CompatibilityApi::call('/drop/text','POST',['kind'=>(string)($body['kind'] ?? 'text'),'name'=>(string)($body['name'] ?? ''),'body'=>(string)($body['body'] ?? ''),'inbox'=>!empty($body['inbox']),'expires_hours'=>(float)($body['expires_hours'] ?? 24),'max_downloads'=>(int)($body['max_downloads'] ?? 0)]);break;
            case 'extra-drop-update':
                $payload=['name'=>(string)($body['name'] ?? ''),'max_downloads'=>(int)($body['max_downloads'] ?? 0),'inbox'=>!empty($body['inbox'])];
                if(trim((string)($body['expires_hours'] ?? ''))!=='')$payload['expires_hours']=(float)$body['expires_hours'];
                CompatibilityApi::call('/drop/items/'.$urlId,'POST',$payload);break;
            case 'extra-drop-expire': CompatibilityApi::call('/drop/items/'.$urlId,'POST',['expire_now'=>true]);break;
            case 'extra-drop-share':
                CompatibilityApi::call('/drop/items/'.$urlId.'/links','POST',['require_login'=>($body['access'] ?? 'login')!=='public','password'=>(string)($body['password'] ?? ''),'link_hours'=>(float)($body['link_hours'] ?? 0)]);break;
            case 'extra-drop-extend': CompatibilityApi::call('/drop/links/'.$urlId.'/extend','POST',['hours'=>(float)($body['hours'] ?? 0)]);break;
            case 'extra-drop-revoke': CompatibilityApi::call('/drop/links/'.$urlId.'/revoke','POST');break;
            case 'extra-drop-delete': CompatibilityApi::call('/drop/items/'.$urlId,'DELETE');break;
            default: throw new RuntimeException('Unknown action.');
        }
        return ['view'=>'misc','tab'=>$tab];
    }
    private static function extraRequestForm(array $row,int $season=0): string {
        return self::beginForm(['view'=>'requests','tab'=>'mine'],'extra-request-create',false,['media_type'=>$row['media_type'],'source'=>$row['source'],'ext_id'=>$row['ext_id'],'season'=>$season]).
            self::field('Note (optional)','note','','text',300).'<button type="submit">'.($season>0?'Request season '.$season:($row['media_type']==='tv'?'Request whole show':'Request film')).'</button></form>';
    }
    private static function extraRequests(int $page,string $query): string {
        self::allow('library');$status=CompatibilityApi::call('/requests/status');
        $tab=(string)($_GET['tab'] ?? 'discover');$base=['view'=>'requests'];
        $html=self::heading('LIBRARY','Media requests','Find missing titles and follow their progress.').'<div class="compat-categories">'.self::link('Discover',$base+['tab'=>'discover']).self::link('My requests',$base+['tab'=>'mine']);
        if(!empty($status['manage']))$html.=self::link('Manage requests',$base+['tab'=>'manage']);
        $html.='</div>';
        if(empty($status['enabled'])&&empty($status['manage']))return $html.'<p>Media requests are disabled.</p>';
        if($tab==='discover'){
            if(empty($status['create']))return $html.'<p>Your account cannot request titles.</p>';
            $type=($_GET['type'] ?? '')==='tv'?'tv':'movie';
            $html.='<form class="compat-panel compat-fields" method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="requests">'.self::field('Title','q',$query,'text',200).self::select('Type','type',['movie'=>'Films','tv'=>'TV shows'],$type).'<button type="submit">Find titles</button></form>';
            if($query!==''){
                $data=CompatibilityApi::call('/requests/search','GET',[],['type'=>$type,'q'=>$query]);
                if(!empty($data['notice']))$html.='<p>'.self::esc($data['notice']).'</p>';
                foreach($data['results'] ?? [] as $row){
                    $html.='<div class="compat-panel"><h3>'.self::esc($row['title']).' '.self::esc($row['year'] ?? '').'</h3><p>'.self::esc(strip_tags((string)($row['overview'] ?? ''))).'</p>';
                    if(!empty($row['library'])){
                        $lib=$row['library'];$html.=self::link('Open in library',['view'=>$lib['type']==='movie'?'item':'show','id'=>$lib['item_id'] ?? $lib['show_key']]);
                    }
                    foreach($row['requests'] ?? [] as $request)$html.='<p class="compat-note">'.self::esc(($request['season']>0?'Season '.$request['season'].' · ':'').$request['status_label']).'</p>';
                    if($type==='tv')$html.=self::link('Choose seasons',$base+['tab'=>'seasons','source'=>$row['source'],'id'=>$row['ext_id']]);
                    elseif(empty($row['in_library']))$html.=self::extraRequestForm($row);
                    $html.='</div>';
                }
                if(empty($data['results']))$html.='<p>No matching titles.</p>';
            }
            return $html;
        }
        if($tab==='seasons'){
            $data=CompatibilityApi::call('/requests/seasons','GET',[],['source'=>(string)($_GET['source'] ?? ''),'id'=>(string)($_GET['id'] ?? '')]);
            $row=$data['show'];$html.='<h3>'.self::esc($row['title']).'</h3>'.self::extraRequestForm($row);
            foreach($data['seasons'] ?? [] as $season){$html.='<div class="compat-panel"><h3>Season '.(int)$season['number'].'</h3>';
                if(!empty($season['in_library']))$html.='<p>In your library</p>';
                else $html.=self::extraRequestForm($row,(int)$season['number']);
                $html.='</div>';
            }return $html;
        }
        $manage=$tab==='manage';$filter=(string)($_GET['status'] ?? 'pending');
        if($manage){
            $html.='<form class="compat-panel compat-fields" method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="requests"><input type="hidden" name="tab" value="manage">'.self::select('Status','status',['all'=>'All']+MediaRequests::STATUSES,$filter).self::field('Title','q',$query).'<button type="submit">Filter</button></form>';
            $data=CompatibilityApi::call('/requests/manage','GET',[],['status'=>$filter,'q'=>$query,'page'=>$page]);
        }else $data=CompatibilityApi::call('/requests/mine');
        $rows=$data['requests'] ?? [];
        foreach($manage?$rows:array_slice($rows,($page-1)*16,16) as $row){
            $params=$base+['tab'=>$manage?'manage':'mine','id'=>$row['id']];
            $html.='<div class="compat-panel"><h3>'.self::esc($row['title']).' '.self::esc($row['year']).'</h3><p>'.self::esc($row['scope_label'].' · '.$row['status_label']).'</p><p>'.self::esc($row['reason'] ?: $row['note']).'</p>';
            if(!empty($row['can_cancel']))$html.=self::form($params,'Cancel my request','extra-request-cancel');
            if($manage)$html.=self::beginForm($params,'extra-request-decide').self::select('Action','change',['approve'=>'Approve','decline'=>'Decline','retry'=>'Retry acquisition','available'=>'Mark available','reopen'=>'Reopen','delete'=>'Delete'],'approve').self::field('Reason (optional)','reason','','text',400).self::field('For deletion only, type DELETE','confirm').'<button type="submit">Apply</button></form>';
            $html.='</div>';
        }
        return $html.(!$rows?'<p>No requests found.</p>':'').self::pages($base+['tab'=>$tab,'status'=>$filter,'q'=>$query],$page,$manage?!empty($data['has_more']):$page*16<count($rows));
    }
    private static function extraMisc(int $page): string {
        if(!self::$signed)throw new RuntimeException('Sign in to use Misc.');
        $tab=(string)($_GET['tab'] ?? 'calendar');$base=['view'=>'misc','tab'=>$tab];
        $names=['calendar'=>'Calendar','reminders'=>'Reminders','pictures'=>'Pictures','drop'=>'Drop','board'=>'Boards','ambient'=>'Ambient','webcams'=>'My webcams','ambient-admin'=>'Ambient administration','drop-admin'=>'Drop administration'];
        if(!isset($names[$tab]))throw new RuntimeException('Misc page not found.');
        $html=self::heading('MISC',$names[$tab]);
        if($tab==='drop-admin'){
            self::toolPermission('admin');$data=CompatibilityApi::call('/drop/admin');$settings=$data['settings'];
            $html.=self::link('My Drop',['view'=>'misc','tab'=>'drop']).'<div class="compat-panel"><h3>Storage</h3><p>'.self::esc(number_format((float)$data['total_bytes']/1048576,1)).' MiB used · '.self::esc(number_format((float)($data['reclaimable']['bytes'] ?? 0)/1048576,1)).' MiB reclaimable</p>'.self::form($base,'Run cleanup','extra-drop-admin-cleanup').'</div>';
            $html.=self::beginForm($base,'extra-drop-admin-settings').'<h3>Access and limits</h3>';
            foreach(['enabled'=>'Enable Drop','user_role_upload'=>'Allow standard users to upload','public_links'=>'Allow public links','anonymous'=>'Allow anonymous uploads','nginx_downloads'=>'Use nginx downloads'] as $key=>$label)$html.=self::miscCheck($label,$key,!empty($settings[$key]));
            $html.=self::miscCheck('Confirm enabling anonymous uploads','confirm_anonymous');
            foreach(['default_quota_mb'=>'Default quota (MB)','max_file_mb'=>'Maximum file size (MB)','anon_max_file_mb'=>'Anonymous file limit (MB)','anon_quota_mb'=>'Anonymous quota (MB)','anon_max_days'=>'Anonymous expiry limit (days)'] as $key=>$label)$html.=self::field($label,$key,(string)$settings[$key],'number');
            foreach($data['roles'] ?? [] as $key=>$role)$html.=self::field(($role['name'] ?? $key).' quota (MB; blank uses default)','quotas['.$key.']',(string)($settings['quotas'][$key] ?? ''),'number');
            $html.='<button type="submit">Save Drop settings</button></form>';
            foreach($data['notes'] ?? [] as $note)if(is_scalar($note))$html.='<p class="compat-note">'.self::esc($note).'</p>';
            $items=$data['items'] ?? [];$html.='<h3>Stored items</h3>';
            foreach(array_slice($items,($page-1)*16,16) as $row){$p=$base+['id'=>$row['id'],'page'=>$page];$html.='<div class="compat-panel"><strong>'.self::esc($row['name']).'</strong><p>'.self::esc(($row['owner']['name'] ?? 'User').' · '.$row['status'].' · '.number_format($row['bytes']/1048576,1).' MiB').'</p>'.self::link('Delete item',$p+['confirm'=>'drop-admin']);if(($_GET['confirm'] ?? '')==='drop-admin'&&($_GET['id'] ?? '')===$row['id'])$html.=self::beginForm($p,'extra-drop-admin-delete').self::field('Type DELETE','confirm').'<button type="submit">Confirm delete</button></form>';$html.='</div>';}
            return $html.self::pages($base,$page,$page*16<count($items));
        }
        if($tab==='calendar'){
            $data=CompatibilityApi::call('/misc/cal/calendars');$choices=[];
            $html.=self::beginForm($base,'extra-calendar-create').'<h3>Create a calendar</h3>'.self::field('Name','name').self::select('Type','kind',['local'=>'Personal','subscribed'=>'Subscribed calendar'],'local').self::field('Subscription URL (optional)','url','','text',2000).self::select('Episode calendar','episodes',[''=>'None','favourites'=>'Favourite shows','library'=>'Library shows'],'').'<label class="compat-check"><input type="checkbox" name="show_reminders" value="1"> Show reminders</label><button type="submit">Create calendar</button></form>';
            foreach($data['calendars'] ?? [] as $cal){
                if(in_array($cal['role'],['owner','edit'],true)&&$cal['kind']==='local')$choices[$cal['id']]=$cal['name'];
                $p=$base+['id'=>$cal['id']];
                $html.='<div class="compat-panel"><h3>'.self::esc($cal['name']).'</h3><p>'.self::esc($cal['role']).'</p>';
                if($cal['kind']!=='subscribed')$html.='<a class="compat-button" href="'.self::esc(self::$base.'api/misc/cal/calendars/'.rawurlencode($cal['id']).'/export').'">Export ICS</a>';
                if($cal['role']==='owner'){
                    $html.=self::link('Manage calendar',$p+['edit'=>'calendar']);
                    if(($_GET['edit'] ?? '')==='calendar'&&($_GET['id'] ?? '')===$cal['id']){
                        $html.=self::beginForm($p,'extra-calendar-edit',false,['kind'=>$cal['kind']]).self::field('Name','name',$cal['name']).self::field('Colour (#RRGGBB)','colour',$cal['colour']);
                        if($cal['kind']==='subscribed')$html.=self::field('Subscription URL','url',(string)($cal['url'] ?? ''),'text',2000);
                        else $html.=self::select('Episode calendar','episodes',[''=>'None','favourites'=>'Favourite shows','library'=>'Library shows'],(string)$cal['episodes']);
                        $html.='<label class="compat-check"><input type="checkbox" name="show_reminders" value="1"'.(!empty($cal['show_reminders'])?' checked="checked"':'').'> Show reminders</label><button type="submit">Save calendar</button></form>';
                        $html.=self::beginForm($p,'extra-calendar-share').self::field('Username','username').self::select('Access','role',['view'=>'Can view','edit'=>'Can edit','none'=>'Remove access'],'view').'<button type="submit">Update sharing</button></form>';
                        foreach($cal['shares'] ?? [] as $share)$html.='<p>'.self::esc(($share['user']['name'] ?? 'User').' · '.$share['role']).'</p>';
                        if(!empty($cal['feed']))$html.='<label>Private ICS feed<input type="text" readonly="readonly" value="'.self::esc($cal['feed']).'"></label>'.self::form($p,'Revoke feed','extra-calendar-feed',['change'=>'revoke']);
                        else $html.=self::form($p,'Create private ICS feed','extra-calendar-feed',['change'=>'create']);
                        if($cal['kind']==='local')$html.=self::beginForm($p,'extra-calendar-import').'<label>Paste ICS calendar data<textarea name="ics" rows="5"></textarea></label><button type="submit">Import events</button></form>';
                        $html.=self::link('Delete calendar',$p+['confirm'=>'calendar']);
                    }
                    if(($_GET['confirm'] ?? '')==='calendar'&&($_GET['id'] ?? '')===$cal['id'])$html.='<p>Delete this calendar and all its events?</p>'.self::form($p,'Confirm delete calendar','extra-calendar-delete');
                }
                $html.='</div>';
            }
            if($choices)$html.=self::disclosure('compat-add-event','Add event',self::eventForm($base,$choices));
            $offset=max(-24,min(24,(int)($_GET['offset'] ?? 0)));$start=strtotime('today')+$offset*30*86400;$end=$start+30*86400;
            $events=CompatibilityApi::call('/misc/cal/events','GET',[],['from'=>$start,'to'=>$end]);
            $html.='<h3>'.self::esc(date('j M Y',$start).' – '.date('j M Y',$end-1)).'</h3>'.self::link('Previous 30 days',$base+['offset'=>$offset-1]).self::link('Next 30 days',$base+['offset'=>$offset+1]).'<ul class="compat-list">';
            foreach(array_slice($events['events'] ?? [],($page-1)*16,16) as $event){
                $html.='<li><strong>'.self::esc($event['title']).'</strong><p>'.self::esc($event['start_date'] ?? date('j M Y H:i',(int)$event['start'])).'</p>'.self::esc($event['location'] ?? '').'<p>'.self::esc($event['notes'] ?? '').'</p>';
                if(!empty($event['editable'])){
                    $p=$base+['offset'=>$offset,'id'=>$event['id'],'page'=>$page];$html.=self::link('Edit event',$p+['edit'=>'event','occurrence'=>$event['start']]).self::link('Delete event',$p+['confirm'=>'event','occurrence'=>$event['start']]);
                    if(($_GET['edit'] ?? '')==='event'&&($_GET['id'] ?? '')===$event['id']&&(int)($_GET['occurrence'] ?? 0)===(int)$event['start'])$html.=self::eventForm($p,$choices,$event);
                    if(($_GET['confirm'] ?? '')==='event'&&($_GET['id'] ?? '')===$event['id']&&(int)($_GET['occurrence'] ?? 0)===(int)$event['start']){
                        $html.=self::form($p,'Confirm delete'.(!empty($event['repeat'])?' entire series':''),'extra-event-delete');
                        if(!empty($event['repeat']))$html.=self::form($p,'Delete this occurrence only','extra-event-delete',['occurrence'=>$event['start']]);
                    }
                }$html.='</li>';
            }
            return $html.'</ul>'.self::pages($base+['offset'=>$offset],$page,$page*16>0&&$page*16<count($events['events'] ?? []));
        }
        if($tab==='reminders'){
            $data=CompatibilityApi::call('/misc/reminders');
            $html.=self::beginForm($base,'extra-reminder-create').'<h3>New reminder</h3>'.self::field('Title','title').self::field('First date (YYYY-MM-DD)','start',date('Y-m-d')).self::field('Time (HH:MM)','time').self::select('Repeat','repeat',['once'=>'Once','daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly'],'once').self::field('Repeat every','every','1','number').self::field('Note','note','','text',200).'<button type="submit">Add reminder</button></form>';
            foreach($data['reminders'] ?? [] as $row){$p=$base+['id'=>$row['id']];$html.='<div class="compat-panel"><h3>'.self::esc($row['title']).'</h3><p>'.self::esc(($row['due'] ?? 'No next date').' · '.$row['state']).'</p><p>'.self::esc($row['note']).'</p>'.self::form($p,'Done','extra-reminder-done').self::form($p,'Snooze 15 min','extra-reminder-snooze',['minutes'=>15]).self::form($p,'Snooze 1 hour','extra-reminder-snooze',['minutes'=>60]).self::form($p,'Snooze 1 day','extra-reminder-snooze',['minutes'=>1440]).self::link('Edit',$p+['edit'=>'reminder']).self::link('Delete',$p+['confirm'=>'reminder']).'</div>';if(($_GET['edit'] ?? '')==='reminder'&&($_GET['id'] ?? '')===$row['id'])$html.=self::beginForm($p,'extra-reminder-edit').self::field('Title','title',$row['title']).self::field('Note','note',$row['note'],'text',200).self::field('Time (HH:MM)','time',$row['time']).self::field('Alert before due (minutes)','lead',(string)$row['lead'],'number').'<label class="compat-check"><input type="checkbox" name="calendar" value="1"'.(!empty($row['calendar'])?' checked="checked"':'').'> Show on calendar</label><button type="submit">Save reminder</button></form>';if(($_GET['confirm'] ?? '')==='reminder'&&($_GET['id'] ?? '')===$row['id'])$html.=self::form($p,'Confirm delete','extra-reminder-delete');}
            return $html;
        }
        if($tab==='pictures'){
            $data=CompatibilityApi::call('/misc/pictures');
            if(!empty($data['can_upload']))$html.=self::beginForm($base,'extra-picture-upload',true).'<label>Choose pictures<input type="file" name="files[]" multiple="multiple" accept="image/*"></label><button type="submit">Upload</button></form>';
            foreach(array_slice($data['pictures'] ?? [],($page-1)*16,16) as $row){$p=$base+['id'=>$row['id']];$url=rtrim(self::$base,'/').$row['url'];$html.='<div class="compat-panel"><a href="'.self::esc($url).'"><img class="compat-extra-picture" src="'.self::esc($url).'" alt="'.self::esc($row['name']).'"></a>'.self::beginForm($p,'extra-picture-rename').self::field('Name','name',$row['name']).'<button type="submit">Rename</button></form>'.self::link('Delete',$p+['confirm'=>'picture']);if(($_GET['confirm'] ?? '')==='picture'&&($_GET['id'] ?? '')===$row['id'])$html.=self::form($p,'Confirm delete','extra-picture-delete');$html.='</div>';}
            return $html.self::pages($base,$page,$page*16<count($data['pictures'] ?? []));
        }
        if($tab==='drop'){
            $data=CompatibilityApi::call('/drop/state');
            if((self::$viewer['role'] ?? '')==='admin')$html.=self::link('Drop administration',['view'=>'misc','tab'=>'drop-admin']);
            $html.='<p class="compat-note">'.self::esc(number_format((float)$data['used']/1048576,1)).' MiB used'.($data['quota']>0?' / '.self::esc(number_format($data['quota']/1048576,1)).' MiB':'').'</p>';
            if(!empty($data['can_upload']))$html.=self::disclosure('compat-drop-create','Save a note or link',self::beginForm($base,'extra-drop-create').self::select('Type','kind',['text'=>'Text','link'=>'Web link'],'text').self::field('Name','name').'<label>Content<textarea name="body" rows="5"></textarea></label>'.self::field('Available for (hours)','expires_hours','24','number').self::field('Maximum downloads (0 = unlimited)','max_downloads','0','number').self::miscCheck('Send to my inbox','inbox').'<button type="submit">Save</button></form>');
            if(!empty($data['can_upload']))$html.='<div class="compat-panel" id="compat-drop-upload" style="display:none"><h3>Upload a file</h3><label>File<input id="compat-drop-file" type="file"></label><button id="compat-drop-send" type="button">Upload</button><p id="compat-drop-status" role="status"></p></div>';
            foreach(array_slice($data['items'] ?? [],($page-1)*16,16) as $row){$p=$base+['id'=>$row['id']];$html.='<div class="compat-panel"><h3>'.self::esc($row['name']).'</h3><p>'.self::esc($row['status']).'</p>';
                if($row['kind']==='file')$html.='<a class="compat-button" href="'.self::esc(self::$base.'api/drop/items/'.rawurlencode($row['id']).'/download').'">Download</a>';
                else $html.='<p class="compat-message-text">'.self::esc($row['body'] ?? '').'</p>';
                $html.='<p class="compat-note">'.(int)$row['downloads'].' downloads'.($row['max_downloads']?' / '.(int)$row['max_downloads']:'').' · '.($row['expires_at']?'Expires '.self::esc(date('j M Y H:i',$row['expires_at'])):'No expiry').'</p>';
                $html.=self::disclosure('compat-drop-edit-'.$row['id'],'Item settings',self::beginForm($p,'extra-drop-update').self::field('Name','name',$row['name'],'text',200).self::field('Extend availability by hours from now (blank keeps expiry)','expires_hours','','number').self::field('Maximum downloads (0 = unlimited)','max_downloads',(string)$row['max_downloads'],'number').self::miscCheck('Show in inbox','inbox',!empty($row['inbox'])).'<button type="submit">Save settings</button></form>'.self::form($p,'Expire now','extra-drop-expire'));
                if(!empty($data['can_upload'])&&$row['status']==='available')$html.=self::disclosure('compat-drop-share-'.$row['id'],'Create share link',self::beginForm($p,'extra-drop-share').self::select('Access','access',['login'=>'Require sign-in']+(!empty($data['public_links'])?['public'=>'Anyone with link']:[]),'login').self::field('Password (optional)','password','','password',72).self::field('Link duration in hours (0 = no separate expiry)','link_hours','0','number').'<button type="submit">Create link</button></form>');
                foreach($row['links'] ?? [] as $link)if(empty($link['revoked'])){
                    $lp=$base+['id'=>$link['token']];
                    $html.='<div class="compat-panel"><a href="'.self::esc($link['url']).'">'.self::esc($link['url']).'</a><p class="compat-note">'.($link['require_login']?'Sign-in required':'Public link').($link['password']?' · Password protected':'').' · '.($link['expires_at']?'Expires '.self::esc(date('j M Y H:i',$link['expires_at'])):'No separate expiry').'</p>'.self::form($lp,'Revoke link','extra-drop-revoke').self::disclosure('compat-drop-extend-'.$link['token'],'Change link expiry',self::beginForm($lp,'extra-drop-extend').self::field('Hours from now (0 = no separate expiry)','hours','24','number').'<button type="submit">Save expiry</button></form>').'</div>';
                }
                $html.=self::link('Delete',$p+['confirm'=>'drop']);if(($_GET['confirm'] ?? '')==='drop'&&($_GET['id'] ?? '')===$row['id'])$html.=self::form($p,'Confirm delete','extra-drop-delete');$html.='</div>';
            }return $html.self::pages($base,$page,$page*16<count($data['items'] ?? []));
        }
        if($tab==='ambient-admin'){
            self::toolPermission('admin');$site=CompatibilityApi::call('/misc/ambient');$data=CompatibilityApi::call('/misc/admin/webcams');
            $html.=self::link('Ambient viewer',['view'=>'misc','tab'=>'ambient']).self::beginForm($base,'extra-webcam-admin-settings').'<h3>Site Ambient defaults</h3>'.self::field('Idle timeout in minutes (0 = disabled)','idle_minutes',(string)$site['idle_minutes'],'number').self::field('Default radio station ID','radio',(string)$site['radio']).'<button type="submit">Save defaults</button></form>';
            $html.=self::disclosure('compat-cam-create','Register a webcam',self::beginForm($base,'extra-webcam-admin-add').self::compatCamFields([],true).'<button type="submit">Add webcam</button></form>');
            $rows=$data['webcams'] ?? [];foreach(array_slice($rows,($page-1)*16,16) as $cam){$p=$base+['id'=>$cam['id'],'page'=>$page];$html.='<div class="compat-panel"><h3>'.self::esc($cam['name']?:'Webcam').'</h3><p>'.self::esc($cam['kind']).' · '.(!empty($cam['enabled_ambient'])?'Enabled in Ambient':'Not enabled in Ambient').'</p><p class="compat-message-text">'.self::esc($cam['url']).'</p>'.self::disclosure('compat-cam-'.$cam['id'],'Edit webcam',self::beginForm($p,'extra-webcam-admin-edit').self::compatCamFields($cam,true).'<button type="submit">Save webcam</button></form>').self::link('Remove webcam',$p+['confirm'=>'camera']);
                if(($_GET['confirm'] ?? '')==='camera'&&($_GET['id'] ?? '')===$cam['id'])$html.=self::beginForm($p,'extra-webcam-admin-delete').self::field('Type DELETE','confirm').'<button type="submit">Confirm removal</button></form>';
                $html.='</div>';}
            return $html.self::pages($base,$page,$page*16<count($rows));
        }
        if($tab==='webcams'){
            $prefs=CompatibilityApi::call('/misc/prefs');$cams=$prefs['webcams'] ?? [];
            $html.=self::disclosure('compat-my-cam-add','Add webcam',self::beginForm($base,'extra-webcam-add').self::compatCamFields().'<button type="submit">Save webcam</button></form>');
            foreach($cams as $cam){$p=$base+['id'=>$cam['id']];$html.='<div class="compat-panel"><h3>'.self::esc($cam['name']?:'Webcam').'</h3>'.self::link('View webcam',$p+['watch'=>'1']).self::disclosure('compat-my-cam-'.$cam['id'],'Edit webcam',self::beginForm($p,'extra-webcam-edit').self::compatCamFields($cam).'<button type="submit">Save webcam</button></form>').self::link('Remove',$p+['confirm'=>'camera']);
                if(($_GET['id'] ?? '')===$cam['id']&&!empty($_GET['watch']))$html.=self::compatCamera($cam);
                if(($_GET['id'] ?? '')===$cam['id']&&($_GET['confirm'] ?? '')==='camera')$html.=self::form($p,'Confirm removal','extra-webcam-delete');
                $html.='</div>';}
            return $html.(!$cams?'<p class="compat-empty">No saved webcams.</p>':'');
        }
        if($tab==='ambient'){
            $prefs=CompatibilityApi::call('/misc/prefs');$s=(array)($prefs['ambient'] ?? []);$site=CompatibilityApi::call('/misc/ambient');$cams=[''=>'Choose camera'];foreach($site['webcams'] ?? [] as $cam)$cams[$cam['id']]=$cam['name'];
            $bg=(string)($s['bg'] ?? 'gradient');$kind=in_array($_GET['kind'] ?? '',['posters','frames','mixed'],true)?$_GET['kind']:(string)($s['library'] ?? 'posters');$mode=(string)($_GET['mode'] ?? $bg);if(!in_array($mode,['gradient','library','pictures','webcam'],true))$mode='gradient';
            $html.='<div class="compat-choicebar">'.self::choice('Clock',$base+['mode'=>'gradient'],$mode==='gradient').self::choice('Library artwork',$base+['mode'=>'library'],$mode==='library').self::choice('My pictures',$base+['mode'=>'pictures'],$mode==='pictures').self::choice('Webcams',$base+['mode'=>'webcam'],$mode==='webcam').'</div>';
            if($mode==='library'||$mode==='pictures'){
                if($mode==='library')$html.='<div class="compat-choicebar">'.self::choice('Posters',$base+['mode'=>'library','kind'=>'posters'],$kind==='posters').self::choice('Film stills',$base+['mode'=>'library','kind'=>'frames'],$kind==='frames').self::choice('Mixed',$base+['mode'=>'library','kind'=>'mixed'],$kind==='mixed').'</div>';
                $html.=self::compatSlideshow(self::compatPictureRows($mode==='pictures'?'mine':'library',['art'=>$kind,'order'=>'recent']),['seconds'=>20,'fit'=>'contain']);
            }elseif($mode==='webcam'){
                $camId=(string)($_GET['camera'] ?? ($s['webcam'] ?? ''));foreach($site['webcams'] ?? [] as $cam)$html.=self::choice($cam['name'],$base+['mode'=>'webcam','camera'=>$cam['id']],$cam['id']===$camId);
                foreach($site['webcams'] ?? [] as $cam)if($cam['id']===$camId)$html.=self::compatCamera($cam);
                if(count($cams)===1)$html.='<p>No webcams enabled by the administrator.</p>';
            }
            if($mode==='gradient'||!empty($s['clock']['show']))$html.='<div class="compat-ambient-clock" data-compat-clock="1" data-date="'.($mode==='gradient'||!empty($s['clock']['date'])?'1':'0').'">'.self::esc(date('H:i')).'</div>';
            $html.=self::disclosure('compat-ambient-prefs','Ambient preferences',self::beginForm($base,'extra-ambient-prefs').self::select('Default background','bg',['gradient'=>'Clock','library'=>'Library artwork','pictures'=>'My pictures','webcam'=>'Webcam'],in_array($bg,['gradient','library','pictures','webcam'],true)?$bg:'gradient').self::select('Library artwork','library',['posters'=>'Posters','frames'=>'Film stills','mixed'=>'Mixed'],$kind).self::select('Default webcam','webcam',$cams,(string)($s['webcam'] ?? '')).self::miscCheck('Show clock','clock',(bool)($s['clock']['show'] ?? true)).self::miscCheck('Show date','date',(bool)($s['clock']['date'] ?? true)).'<button type="submit">Save preferences</button></form>');
            $html.=self::link('Manage my webcams',['view'=>'misc','tab'=>'webcams']).self::link('Manage pictures',['view'=>'misc','tab'=>'pictures']);
            if((self::$viewer['role'] ?? '')==='admin')$html.=self::link('Ambient administration',['view'=>'misc','tab'=>'ambient-admin']);
            return $html;
        }
        $data=CompatibilityApi::call('/misc/boards');$boards=$data['boards'] ?? [];
        $selected=(string)($_GET['id'] ?? ($data['default'] ?: ($boards[0]['id'] ?? '')));
        foreach($boards as $board)$html.=self::link($board['name'],$base+['id'=>$board['id']]);
        $html.=self::beginForm($base,'extra-board-create').self::field('New board name','name','','text',40).'<button type="submit">Create board</button></form>';
        foreach($boards as $board)if($board['id']===$selected){
            $params=$base+['id'=>$board['id']];$locked=!empty($board['locked']);
            $html.='<h3>'.self::esc($board['name']).'</h3>'.self::form($params,$locked?'Unlock board':'Lock board','extra-board-lock').self::form($params,'Set as default','extra-board-default');
            if(!$locked){
                $choices=[];foreach($data['widgets'] ?? [] as $type)$choices[$type]=ucfirst($type);
                $html.=self::beginForm($params,'extra-board-rename').self::field('Board name','name',$board['name'],'text',40).self::select('Widget theme','theme',['glass'=>'Glass','solid'=>'Solid','clear'=>'Clear'],(string)($board['theme'] ?? 'glass')).self::field('Board colour (#RRGGBB)','color',(string)($board['color'] ?? '')).'<button type="submit">Save board settings</button></form>';
                $html.=self::beginForm($params,'extra-board-add').self::select('Widget type','type',$choices,'clock').'<button type="submit">Add widget</button></form>';
            }
            $widgets=$board['widgets'] ?? [];usort($widgets,static function($a,$b){return [(int)$a['y'],(int)$a['x']]<=>[(int)$b['y'],(int)$b['x']];});
            foreach($widgets as $widget){
            $type=$widget['type'];$html.='<div class="compat-panel"><h3>'.self::esc(ucfirst($type)).'</h3>';
            if($type==='clock')$html.='<div class="compat-ambient-clock" data-compat-clock="1" data-date="1">'.self::esc(date('D j M Y H:i')).'</div>';
            elseif($type==='notes')$html.='<p class="compat-message-text">'.self::esc($widget['settings']['text'] ?? '').'</p>';
            elseif($type==='weather'){
                $loc=$widget['settings']['loc'] ?? null;$units=($widget['settings']['units'] ?? '')==='f'?'f':'c';
                if(is_array($loc)&&isset($loc['lat'],$loc['lon'])){try{
                    $wx=CompatibilityApi::call('/misc/widget/weather','GET',[],['lat'=>$loc['lat'],'lon'=>$loc['lon'],'units'=>$units]);$now=$wx['current'];
                    $html.='<div class="compat-weather"><p>'.self::esc($loc['name'] ?? '').'</p><strong>'.self::esc($now['temp'] ?? '—').self::esc($wx['units']).'</strong><p>Feels like '.self::esc($now['feels'] ?? '—').self::esc($wx['units']).' · Humidity '.self::esc($now['humidity'] ?? '—').'% · Wind '.self::esc($now['wind'] ?? '—').' '.self::esc($wx['wind_units']).'</p><ul class="compat-list">';
                    foreach(array_slice($wx['days'] ?? [],0,5) as $day)$html.='<li>'.self::esc($day['date']).' · '.self::esc($day['min']).' / '.self::esc($day['max']).self::esc($wx['units']).' · Rain '.self::esc($day['rain']).'%</li>';
                    $html.='</ul></div>';
                }catch(Throwable $err){$html.='<p class="compat-note">Weather unavailable. Refresh to retry.</p>';}}
                else $html.='<p class="compat-note">Choose a location below.</p>';
                if(!$locked){
                    $html.='<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="misc"><input type="hidden" name="tab" value="board"><input type="hidden" name="id" value="'.self::esc($board['id']).'"><input type="hidden" name="weather_widget" value="'.self::esc($widget['id']).'">'.self::field('Find a town or city','weather_query').'<button type="submit">Find location</button></form>';
                    if(($_GET['weather_widget'] ?? '')===$widget['id']&&trim((string)($_GET['weather_query'] ?? ''))!=='')try{
                        $places=CompatibilityApi::call('/misc/widget/geocode','GET',[],['q'=>mb_substr((string)$_GET['weather_query'],0,100)]);
                        foreach($places['places'] ?? [] as $place)$html.=self::form($params,($place['name'].' · '.$place['region']),'extra-board-edit-widget',['widget'=>$widget['id'],'name'=>$place['name'],'region'=>$place['region'],'lat'=>$place['lat'],'lon'=>$place['lon'],'units'=>$units]);
                        if(empty($places['places']))$html.='<p>No places found.</p>';
                    }catch(Throwable $err){$html.='<p>Location search unavailable.</p>';}
                    $html.=self::beginForm($params,'extra-board-edit-widget',false,['widget'=>$widget['id']]).self::field('Location name','name',(string)($loc['name'] ?? '')).self::field('Latitude','lat',(string)($loc['lat'] ?? ''),'number').self::field('Longitude','lon',(string)($loc['lon'] ?? ''),'number').self::select('Units','units',['c'=>'Celsius','f'=>'Fahrenheit'],$units).'<button type="submit">Save location and units</button></form>';
                }
            }
            elseif(in_array($type,['calendar','reminders','drop'],true)){
                try{
                    if($type==='calendar'){$events=CompatibilityApi::call('/misc/cal/events','GET',[],['from'=>time(),'to'=>time()+14*86400]);$rows=array_slice($events['events'] ?? [],0,6);$html.='<ul class="compat-list">';foreach($rows as $row)$html.='<li><strong>'.self::esc($row['title']).'</strong> · '.self::esc($row['start_date'] ?? date('j M H:i',(int)$row['start'])).'</li>';}
                    elseif($type==='reminders'){$dataR=CompatibilityApi::call('/misc/reminders');$rows=array_slice(array_values(array_filter($dataR['reminders'] ?? [],static function($r){return !empty($r['active']);})),0,6);$html.='<ul class="compat-list">';foreach($rows as $row)$html.='<li><strong>'.self::esc($row['title']).'</strong> · '.self::esc($row['due'] ?? 'No date').'</li>';}
                    else {$dataD=CompatibilityApi::call('/drop/state');$rows=array_slice(array_values(array_filter($dataD['items'] ?? [],static function($r){return $r['status']==='available';})),0,5);$html.='<ul class="compat-list">';foreach($rows as $row)$html.='<li>'.self::esc($row['name']).($row['kind']==='file'?' <a class="compat-button" href="'.self::esc(self::$base.'api/drop/items/'.rawurlencode($row['id']).'/download').'">Download</a>':'').'</li>';}
                    $html.=(!$rows?'<li>Nothing upcoming.</li>':'').'</ul>';
                }catch(Throwable $err){$html.='<p class="compat-note">Widget data unavailable.</p>';}
                $html.=self::link('Open '.ucfirst($type),['view'=>'misc','tab'=>$type]);
            }
            elseif(in_array($type,['health','recent','nowplaying','downloads','recordings'],true)){
                try{$result=CompatibilityApi::call('/misc/widget/'.$type);$html.=self::readable($result);}catch(RuntimeException $e){$html.='<p>'.self::esc($e->getMessage()).'</p>';}
            }elseif($type==='rss'){
                $url=(string)($widget['settings']['url'] ?? '');
                if($url==='')$html.='<p>Enter a feed URL below.</p>';
                else try{
                    $result=CompatibilityApi::call('/misc/widget/rss','GET',[],['url'=>$url]);
                    foreach($result['items'] ?? [] as $row){
                        $href=(string)($row['link'] ?? '');
                        $html.='<p>'.(preg_match('#^https?://#i',$href)?'<a href="'.self::esc($href).'">'.self::esc($row['title']).'</a>':self::esc($row['title'])).'</p>';
                    }
                }catch(Throwable $e){$html.='<p>'.self::esc($e->getMessage()).'</p>';}
            }elseif($type==='leaderboard')$html.=self::link('View leaderboards',['view'=>'play','tab'=>'leaderboards']);
            elseif($type==='media')$html.=self::link('Browse library',['view'=>'library']);
            elseif($type==='pseudotv')$html.=self::link('Watch live TV',['view'=>'live']);
            elseif(in_array($type,['frame','slideshow'],true)){
                $s=(array)($widget['settings'] ?? []);$source=$type==='frame'?'mine':(string)($s['source'] ?? 'library');
                if(in_array($source,['frames','posters','mixed'],true)){$s['art']=$source;$source='library';}
                try{
                    $rows=self::compatPictureRows($source,$s);
                    if($type==='frame'&&($s['mode'] ?? '')==='single'){$chosen=null;foreach($rows as $row)if(($row['id'] ?? '')===($s['picture'] ?? '')){$chosen=$row;break;}$rows=$chosen?[$chosen]:array_slice($rows,0,1);}
                    $html.=self::compatSlideshow($rows,$s);
                }catch(Throwable $err){$html.='<p class="compat-note">Pictures unavailable.</p>';}
                if(!$locked){
                    $f=self::beginForm($params,'extra-board-edit-widget',false,['widget'=>$widget['id']]);
                    if($type==='frame'){$pics=CompatibilityApi::call('/misc/pictures');$choices=[''=>'Newest picture'];foreach($pics['pictures'] ?? [] as $pic)$choices[$pic['id']]=$pic['name'];$f.=self::select('Show','mode',['slideshow'=>'Slideshow','single'=>'Single picture'],(string)($s['mode'] ?? 'slideshow')).self::select('Picture','picture',$choices,(string)($s['picture'] ?? ''));}
                    else $f.=self::select('Source','source',['library'=>'Library artwork','mine'=>'My pictures','both'=>'Both','urls'=>'Image links'],$source).self::select('Artwork','art',['posters'=>'Posters','frames'=>'Film stills','mixed'=>'Mixed'],(string)($s['art'] ?? 'posters')).self::select('Library','library',['both'=>'Films and TV','movies'=>'Films','tv'=>'TV'],(string)($s['library'] ?? 'both')).self::select('Order','order',['recent'=>'Recent','random'=>'Random'],(string)($s['order'] ?? 'recent')).'<label>Image links (one per line)<textarea name="urls" rows="3">'.self::esc($s['urls'] ?? '').'</textarea></label>';
                    $f.=self::field('Seconds per picture','seconds',(string)($s['seconds'] ?? 20),'number').self::select('Fit','fit',['contain'=>'Whole image','cover'=>'Fill frame'],(string)($s['fit'] ?? 'contain')).self::miscCheck('Shuffle','shuffle',$s['shuffle'] ?? false).'<button type="submit">Save picture settings</button></form>';
                    $html.=self::disclosure('compat-picture-'.$widget['id'],'Picture settings',$f);
                }
                $html.=self::link('Manage pictures',['view'=>'misc','tab'=>'pictures']);
            }elseif($type==='webcams'){
                $dataC=CompatibilityApi::call('/misc/prefs');$cams=$dataC['webcams'] ?? [];$s=(array)($widget['settings'] ?? []);$ids=(array)($s['ids'] ?? []);
                $visibleCams=!empty($s['cams'])&&!isset($s['ids'])?(array)$s['cams']:array_values(array_filter($cams,static function($cam)use($ids){return !$ids||in_array($cam['id'],$ids,true);}));
                foreach(array_slice($visibleCams,0,6) as $cam){$html.=self::link(($cam['name'] ?? '')?:'Webcam',$params+['camera_widget'=>$widget['id'],'camera'=>(string)($cam['id'] ?? sha1($cam['url']))]);if(($_GET['camera_widget'] ?? '')===$widget['id']&&($_GET['camera'] ?? '')===(string)($cam['id'] ?? sha1($cam['url'])))$html.=self::compatCamera($cam);}
                if(!$visibleCams)$html.='<p>No webcams configured.</p>';
                if(!$locked){$f=self::beginForm($params,'extra-board-edit-widget',false,['widget'=>$widget['id'],'camera_selection'=>'1']);foreach($cams as $cam)$f.=self::miscCheck($cam['name']?:'Webcam','camera_ids[]',in_array($cam['id'],$ids,true));$html.=self::disclosure('compat-cam-widget-'.$widget['id'],'Choose webcams',$f.'<p class="compat-note">No selection shows all saved cameras.</p><button type="submit">Save selection</button></form>');}
                $html.=self::link('Manage webcams',['view'=>'misc','tab'=>'webcams']);
            }
            else $html.='<p class="compat-note">This widget requires additional browser capabilities.</p>';
            if(!$locked){
                if(in_array($type,['notes','rss'],true)){
                    $key=$type==='notes'?'text':'url';
                    $html.=self::beginForm($params,'extra-board-edit-widget',false,['widget'=>$widget['id']]).self::field($type==='notes'?'Note':'Feed URL',$key,(string)($widget['settings'][$key] ?? ''),'text',2000).'<button type="submit">Save widget</button></form>';
                }
                $layout=self::beginForm($params,'extra-board-edit-widget',false,['widget'=>$widget['id']]);
                foreach(['x'=>'Column (0–23)','y'=>'Row (0–400)','w'=>'Width (1–24)','h'=>'Height (1–24)'] as $key=>$label)$layout.=self::field($label,$key,(string)($widget[$key] ?? 0),'number');
                $html.=self::disclosure('compat-widget-layout-'.$widget['id'],'Edit widget layout',$layout.'<button type="submit">Save layout</button></form>').self::form($params,'Remove widget','extra-board-remove-widget',['widget'=>$widget['id']]);
            }
            $html.='</div>';
        }
            if(!$locked)$html.=self::beginForm($params,'extra-board-delete').self::field('Type DELETE to remove this board','confirm').'<button type="submit">Delete board</button></form>';
        }
        return $html.(!$boards?'<p>No boards saved.</p>':'').self::link('Refresh board',$base+['id'=>$selected]);
    }

    private static function socialAllowed(): bool {
        return !Parental::restricted(self::$viewer)||!empty(Parental::settingsFor(self::$viewer)['social']);
    }
    private static function guardView(string $view,string $id): void {
        if(!self::socialAllowed()&&in_array($view,['friends','profile','inbox','chat','compose','create-room','delete-message','chat-options','chat-confirm','edit-message','cinema','cinema-create','invitations','reviews'],true))throw new RuntimeException('Social features are turned off on this profile.');
        if(in_array($view,['item','subtitles','convert-request'],true)){
            $item=Catalogue::item($id);
            if(!$item||!Parental::allows(self::$viewer,$item))throw new RuntimeException('This title is not available on this profile.');
        }
        if($view==='show'&&!Parental::allowsShowKey(self::$viewer,$id))throw new RuntimeException('This title is not available on this profile.');
        if($view==='vlc-link'&&strpos($id,'show:')===0&&!Parental::allowsShowKey(self::$viewer,substr($id,5)))throw new RuntimeException('This title is not available on this profile.');
    }
    private static function parentalFields(array $settings,bool $admin=false): string {
        $html='';$labels=['enabled'=>'Enable content restrictions','kid'=>'Kids profile','live'=>'Allow Live TV','play'=>'Allow Play','social'=>'Allow social features'];
        if($admin)$labels['self_manage']='Allow this user to manage their own restrictions';
        foreach($labels as $key=>$label)$html.='<label class="compat-check"><input type="checkbox" name="parental['.$key.']" value="1"'.(!empty($settings[$key])?' checked="checked"':'').'> '.self::esc($label).'</label>';
        $ratings=[];foreach(Parental::ratingChoices() as $rating)$ratings[$rating['value']]=$rating['label'];
        return $html.self::select('Maximum age rating','parental[ceiling]',$ratings,(string)($settings['ceiling'] ?? 5)).self::select('Unrated titles','parental[unrated]',['allow'=>'Allow','hide'=>'Hide'],(string)($settings['unrated'] ?? 'allow'));
    }
    private static function parentalPayload(array $body,bool $admin=false): array {
        $raw=(array)($body['parental'] ?? []);$settings=[];
        foreach(['enabled','kid','live','play','social','self_manage'] as $key)$settings[$key]=!empty($raw[$key]);
        $settings['ceiling']=(int)($raw['ceiling'] ?? 5);$settings['unrated']=(string)($raw['unrated'] ?? 'allow');
        return ['settings'=>$settings,'current_pin'=>(string)($body['current_pin'] ?? ''),'new_pin'=>(string)($body['new_pin'] ?? ''),'pin'=>(string)($body['new_pin'] ?? ''),'clear_pin'=>$admin&&!empty($body['clear_pin'])];
    }
    private static function parentalPage(): string {
        if(!self::$signed)throw new RuntimeException('Sign in first.');
        $data=CompatibilityApi::call('/me/parental');
        $html=self::heading('SETTINGS','Parental controls');
        if(!empty($data['admin']))return $html.'<p>Administrator accounts are not restricted. Manage other profiles under Users.</p>'.self::link('Manage users',['view'=>'users']);
        $html.='<p>'.(!empty($data['restricted'])?'Restrictions are active.':(!empty($data['unlocked_for'])?'Temporarily unlocked.':'Restrictions are not active.')).'</p>';
        if(!empty($data['has_pin'])){
            if(!empty($data['unlocked_for']))$html.=self::form(['view'=>'parental'],'Lock this profile','extra-parental-lock');
            else $html.=self::beginForm(['view'=>'parental'],'extra-parental-unlock').self::field('PIN','current_pin','','password',6).'<button type="submit">Unlock for one hour</button></form>';
        }
        if(!empty($data['can_manage']))$html.=self::beginForm(['view'=>'parental'],'extra-parental-save').self::parentalFields($data['settings']).self::field('Current PIN (if set)','current_pin','','password',6).self::field('New PIN (4–6 digits)','new_pin','','password',6).'<button type="submit">Save restrictions</button></form>';
        else $html.='<p>These settings are managed by an administrator.</p>';
        return $html;
    }
    private static function offlineAvailable(): bool {
        $settings=Parental::settingsFor(self::$viewer);
        return self::$signed&&Offline::allowedFor(self::$viewer)&&empty($settings['enabled'])&&empty($settings['kid']);
    }
    private static function offlinePage(string $id): string {
        if(!self::offlineAvailable())throw new RuntimeException('Offline downloads are not available on this profile.');
        $item=null;if($id!==''){
            $item=Catalogue::item($id);
            if(!$item||($item['media_kind'] ?? '')!=='video'||!Parental::allows(self::$viewer,$item))throw new RuntimeException('Video not found.');
        }
        $config=['base'=>self::$base,'user'=>['id'=>self::$viewer['id']],'item'=>$item?['id'=>$id,'title'=>$item['title']]:null,'version'=>STREAM_VERSION,'native'=>AssetDelivery::nativeBase(self::$base)!==''];
        $asset=AssetDelivery::url(self::$base,'assets/js/vc-offline.js',substr(hash_file('sha256',STREAM_ROOT.'/assets/js/vc-offline.js'),0,16));
        $adapter=AssetDelivery::url(self::$base,'assets/js/compatibility-offline.js',substr(hash_file('sha256',STREAM_ROOT.'/assets/js/compatibility-offline.js'),0,16));
        return self::heading('ON THIS DEVICE','Offline downloads').($item?self::link('Back to video',['view'=>'item','id'=>$id,'paused'=>'1']):self::link('Browse library',['view'=>'library'])).'<p id="compat-offline-status" role="status">Checking local storage capabilities…</p><div id="compat-offline-host"></div><script>window.__VC_COMPAT_OFFLINE__='.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script src="'.self::esc($asset).'"></script><script src="'.self::esc($adapter).'"></script>';
    }
    private static function content(array $home): string {
        $view=(string)($_GET['view'] ?? 'home');$id=(string)($_GET['id'] ?? '');$page=max(1,min(1000,(int)($_GET['page'] ?? 1)));$query=mb_substr(trim((string)($_GET['q'] ?? '')),0,200);
        self::guardView($view,$id);
        if($view==='parental')return self::parentalPage();
        if($view==='offline')return self::offlinePage($id);
        if($view==='resolve-link'){
            $route=CompatibilityApi::call('/resolve','GET',[],['path'=>(string)($_GET['path'] ?? '')]);
            switch($route['view'] ?? ''){
                case 'movie': $params=['view'=>'item','id'=>$route['movie_id']];break;
                case 'game': $params=['view'=>'game','id'=>$route['item_id']];break;
                case 'games_system': $params=['view'=>'play','tab'=>'games','system'=>$route['system']];break;
                case 'show':
                    $params=['view'=>'show','id'=>$route['show_key']];
                    if($route['season']!==null)$params['season']=$route['season'];
                    if(!empty($route['item_id'])||$route['episode']!==null){
                        $detail=Catalogue::show($route['show_key']);
                        foreach($detail['seasons'] ?? [] as $season)if((int)$season['season']===(int)$route['season'])foreach($season['episodes'] ?? [] as $ep){
                            if(!empty($ep['id'])&&($ep['available'] ?? true)!==false&&(!empty($route['item_id'])?$ep['id']===$route['item_id']:(int)$ep['episode']===(int)$route['episode'])){$params=['view'=>'item','id'=>$ep['id']];break 2;}
                        }
                    }break;
                default: throw new RuntimeException('Title not found.');
            }
            header('Location: '.self::url($params),true,302);return '';
        }
        if($view==='requests')return self::extraRequests($page,$query);
        if($view==='misc')return self::extraMisc($page);
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
            foreach($visible as $ep){$watched=!empty($seen[$ep['id']]);$title=(!$watched&&self::preference('hide_spoilers',false))?'Details hidden':($ep['episode_title'] ?? $ep['title'] ?? 'Play');$html.='<li class="compat-episode-row"><a class="compat-episode-link" href="'.self::esc(self::url(['view'=>'item','id'=>$ep['id']])).'"><span class="compat-episode-number">'.self::esc(sprintf('%02d',(int)($ep['episode'] ?? 0))).'</span><span class="compat-episode-copy"><strong>'.self::esc($title).'</strong><small>'.($watched?'Watched':'Episode '.self::esc($ep['episode'] ?? '?')).'</small></span><span class="compat-episode-play" aria-hidden="true">&#9654;</span></a></li>';}
            return $html.'</ul>'.self::pages(['view'=>'show','id'=>$id,'season'=>$season],$page,$page*16<count($episodes));
        }
        if($view==='item'){
            self::allow('library');$item=Catalogue::item($id);if(!$item||AreaAccess::itemArea($item)!=='library')throw new RuntimeException('Media not found.');
            $collection=mb_substr((string)($_GET['collection'] ?? ''),0,100);$playParams=['view'=>'item','id'=>$id];if($collection!=='')$playParams['collection']=$collection;
            $detail=Catalogue::itemDetail($id);$card=$detail['item'];$heading=self::heading(!empty($card['show'])?'EPISODE':'LIBRARY',(string)$card['title']);$html=$heading;
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
                $html=preg_replace('/<form class="compat-action compat-play-primary"/','<form id="compat-open-play" class="compat-action compat-play-primary"',$html,1);
            }
            $next=isset($play)?null:self::nextEpisode($item);if($next)$html.=self::link('Next episode · S'.$next['season'].' E'.$next['episode'],['view'=>'item','id'=>$next['item_id']]);
            $controls='';if(!isset($play)){$controls='<div class="compat-title-play">'.substr($html,strlen($heading)).'</div>';$html='';}
            $html.='<div class="compat-detail compat-media-detail">'.self::poster($card,'detail').'<div class="compat-detail-copy">'.(isset($play)?'<h3>About this title</h3>':$heading).'<p class="compat-note compat-title-meta">'.self::esc(implode(' · ',array_filter([$card['year'] ?? null,strtoupper($card['ext'] ?? '')]))).'</p>'.$controls.(!empty($card['synopsis'])?'<p class="compat-synopsis">'.self::esc(strip_tags($card['synopsis'])).'</p>':'').self::shelfActions($card).self::watchedControl($item).self::titleFeatures($card).'</div></div>';
            return $html;
        }
        if($view==='live')return self::live($id,$page,$query);
        throw new RuntimeException('Page not found.');
    }
    private static function stalkerRequest(string $action,array $selection): array {
        // Delegate through the ordinary authenticated Node endpoints. A temporary
        // filesystem ticket is unnecessary for requests originating in this page.
        // Keep the original session on loopback only, never forward it to a portal.
        $allowed=['stalker','stalker_live_categories','stalker_live_channels','stalker_live_guide','stalker_vod_catalogue','stalker_play'];
        if(!in_array($action,$allowed,true))throw new RuntimeException('Invalid provider action.');
        $session=$_COOKIE[Auth::COOKIE]??'';
        $authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
        $bearer=preg_match('/^Bearer[ \t]+(vc_[a-f0-9]{64})$/iD',$authorization,$match);
        if($bearer)$session=$match[1];
        if(!self::$signed||!is_string($session)||!preg_match('/^(?:vc_)?[a-f0-9]{64}$/D',$session))
            throw new RuntimeException('Sign in to use the provider catalogue.');
        $headers=['Connection: close','Accept: application/json',$bearer?'Authorization: Bearer '.$session:'Cookie: '.Auth::COOKIE.'='.$session];
        $play=$action==='stalker_play';
        $path='/application/live/'.$action;
        $options=['timeout'=>$play?125:65,'ignore_errors'=>true,'follow_location'=>0,'method'=>$play?'POST':'GET'];
        if($play){
            $selection['compatibility']=true;
            $options['content']=json_encode($selection,JSON_THROW_ON_ERROR);
            $headers[]='Content-Type: application/json';
        }elseif($selection)$path.='?'.http_build_query($selection,'','&',PHP_QUERY_RFC3986);
        $options['header']=implode("\r\n",$headers);
        $body=@file_get_contents(NodeRuntime::loopback($path),false,stream_context_create(['http'=>$options]),0,4194305);
        $status=0;foreach($http_response_header??[] as $header)if(preg_match('#^HTTP/\S+\s+(\d{3})#',$header,$m))$status=(int)$m[1];
        $data=is_string($body)&&strlen($body)<=4194304?json_decode($body,true):null;
        if($status>=200&&$status<300&&!empty($data['ok']))return $data;
        $errors=[401=>'Your TV session is no longer signed in. Sign in again.',403=>'The Node provider request was rejected (HTTP 403).',404=>'The provider service or selection was not found.',410=>'This provider subscription has expired.',422=>'The provider selection is no longer available. Refresh the list.',429=>'The provider is rate limiting requests. Try again later.',502=>'The provider did not return a usable response.',503=>'The Node provider service is unavailable.',504=>'The provider request timed out.'];
        // Expose only known, safe backend error messages, not provider URLs or credentials.
        $safeMessages=['Select a saved Stalker connection.','Select a valid catalogue entry.','This catalogue entry is unavailable. Refresh the catalogue and select it again.','This channel is no longer on its provider page. Refresh Live TV and select it again.','The provider rejected the saved device or account.','The provider has rate limited requests.','The provider request could not be completed.','This provider subscription has expired.'];
        if(in_array($data['error']??null,$safeMessages,true))throw new RuntimeException($data['error']);
        if($status===403){
            if(($data['code']??'')==='area_denied')throw new RuntimeException('Live access is disabled for this profile.');
            if(($data['code']??'')==='access_denied')throw new RuntimeException('This profile does not have active account access.');
            if(($data['error']??'')==='Provider catalogues are unavailable for restricted profiles.')throw new RuntimeException('Provider catalogues are unavailable for restricted profiles.');
        }
        throw new RuntimeException($errors[$status]??($status?'Provider request failed (HTTP '.$status.').':'The Node provider service did not respond.'));
    }
    private static function stalkerTitles(array $feed,array $options): array {
        return self::stalkerRequest('stalker_vod_catalogue',$options);
    }
    private static function stalkerSource(array $feed,string $id,string $kind,array $page=[]): array {
        $native=self::stalkerRequest('stalker_play',['feed'=>$feed['id'],'id'=>$id,'kind'=>$kind]+$page);
        $url=$native['source']['url']??'';
        $path=is_string($url)?parse_url($url,PHP_URL_PATH):null;
        $endpoint='netplay/application/live/stalker_stream';
        $native=$kind==='live'&&in_array($path,['/'.$endpoint.'/tv.ts',self::$base.$endpoint.'/tv.ts'],true);
        if((!$native&&!in_array($path,['/'.$endpoint,self::$base.$endpoint],true))||parse_url($url,PHP_URL_HOST)!==null)
            throw new RuntimeException('Invalid playback selection.');
        $query=(string)parse_url($url,PHP_URL_QUERY);
        parse_str($query,$params);
        return [self::$base.$endpoint.($native?'/tv.ts':'').'?'.$query,!$native&&($params['format']??'')==='.m3u8'];
    }
    /** Samsung-safe Stalker country browsing across all enabled connections. */
    private static function stalker(int $page): string {
        self::allow('live');
        if(class_exists('Parental') && Parental::filtering())throw new RuntimeException('Provider catalogues are unavailable for restricted profiles.');
        // Use the same validated connection list and expiry state as the full interface.
        $base=['view'=>'live','tab'=>'stalker'];
        $feedError='';$feeds=[];$expired=0;
        try{$connections=self::stalkerRequest('stalker',[])['connections']??[];foreach($connections as $feed){if(empty($feed['enabled']))continue;if(!empty($feed['expired'])){$expired++;continue;}$feeds[]=$feed;}}
        catch(RuntimeException $e){$feedError=$e->getMessage();}
        $kind=(string)($_GET['kind']??'live');
        if(!in_array($kind,['live','vod','series'],true))$kind='live';
        $html=self::heading('LIVE · PROVIDERS','Stalker').'<div class="compat-choicebar" role="navigation" aria-label="Stalker content">';
        foreach(['live'=>'Live TV','vod'=>'Movies','series'=>'Series'] as $tab=>$label)
            $html.=self::choice($label,$base+['kind'=>$tab],$kind===$tab);
        $html.='</div>';
        if($feedError!=='')return $html.'<p class="compat-error">'.self::esc($feedError).'</p>'.self::link('Retry',$base+['kind'=>$kind]);
        if(!$feeds)return $html.'<p>'.($expired?'All enabled provider subscriptions have expired.':'No enabled Stalker connections are available.').'</p>';
        if($expired)$html.='<p class="compat-note">'.$expired.' expired connection(s) omitted.</p>';
        if($kind!=='live')return $html.self::stalkerOnDemand($feeds,$kind,$page);
        $country=strtoupper(mb_substr((string)($_GET['country']??''),0,3));
        $section=mb_substr((string)($_GET['section']??''),0,80);
        $folderId=mb_substr((string)($_GET['folder']??''),0,120);
        $folderFeed=mb_substr((string)($_GET['folder_feed']??''),0,120);
        $sourceOffset=max(0,min(1000,(int)($_GET['source_offset']??0)));
        $selectedChannel=mb_substr((string)($_GET['channel']??''),0,120);
        $selectedFeed=mb_substr((string)($_GET['feed']??''),0,120);
        $channelQuery=mb_substr(trim((string)($_GET['q']??'')),0,120);
        // Quality variants are grouped together and the best available source is first.
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
                $data=self::stalkerRequest('stalker_live_categories',['feed'=>$feed['id']]);
                $categories=$data['categories'];
                foreach($categories as $folder){
                    $folder['feed']=$feed['id'];$folder['connection']=$feed['name'];
                    $folder['name']=$cleanLabel((string)$folder['name']);
                    $folder['section']=$sectionFor($folder['name']);
                    $folders[]=$folder;
                }
            }catch(RuntimeException $e){$errors[]=(string)$feed['name'].': '.$e->getMessage();}catch(Throwable $e){$errors[]=(string)$feed['name'].': categories unavailable';}
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
            $html.='<h3>Countries</h3><ul class="compat-directory">';
            foreach($codes as $code)$html.='<li>'.self::browseRow($countryName($code),$counts[$code].' provider categories',$base+['country'=>$code],$code==='ZZ'?'':$code).'</li>';
            return $html.'</ul>'.(!$codes?'<p>No provider categories could be loaded.</p>':'');
        }
        $html.=self::link('Countries',$base);
        if($errors)$html.='<p class="compat-note">'.self::esc(implode('; ',$errors)).'</p>';
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
                $html.='<section class="compat-panel compat-provider-group"><h3>'.self::browseRow($name,count($groups[$name]).' folders · Browse all channels',$base+['country'=>$country,'section'=>$name]).'</h3>';
                $folderRows='<ul class="compat-directory">';
                foreach($groups[$name] as $entry){
                    $first=$entry['sources'][0];
                    $folderRows.='<li>'.self::browseRow($entry['name'],count($entry['sources']).' connection(s)',
                        $base+['country'=>$country,'folder'=>$first['id'],'folder_feed'=>$first['feed']]).'</li>';
                }
                $html.=self::disclosure('compat-provider-'.substr(sha1($name),0,12),'Show individual folders',$folderRows.'</ul>',$name==='General & entertainment').'</section>';
            }
            return $html;
        }
        $chosenFolder=null;
        if($folderId!=='')foreach($countryFolders as $f)if((string)$f['id']===$folderId&&(string)$f['feed']===$folderFeed){$chosenFolder=$f;break;}
        $target=array_values(array_filter($countryFolders,static function($f)use($section,$chosenFolder){
            return $section!==''?$f['section']===$section:
                ($chosenFolder!==null&&$f['section']===$chosenFolder['section']&&mb_strtolower($f['name'])===mb_strtolower($chosenFolder['name']));
        }));
        if(!$target)return $html.'<p>This provider group is no longer available.</p>';
        $label=$section!==''?$section:$target[0]['name'];
        $html.=self::link('Categories',$base+['country'=>$country]).'<h3>'.self::esc($label).'</h3>';
        $window=4;
        $slice=array_slice($target,$sourceOffset,$window);
        $channels=[];$more=false;$failures=[];
        // Only one provider page per selected folder per request, max four
        // folder sources. Paging avoids a multi-provider scan on small NAS units.
        foreach($slice as $source){
            $feed=null;foreach($feeds as $candidate)if((string)$candidate['id']===(string)$source['feed']){$feed=$candidate;break;}
            if(!$feed)continue;
            try{
                $options=['feed'=>$feed['id'],'category'=>$source['id'],'page'=>$page];
                $data=self::stalkerRequest('stalker_live_channels',$options);
                $more=$more||!empty($data['hasMore']);
                foreach($data['items']??[] as $item){$item['compatProviderPage']=!empty($data['providerPage']);$channels[]=$item;}
            }catch(RuntimeException $e){$failures[]=$source['connection'].' · '.$e->getMessage();}catch(Throwable $e){$failures[]=$source['connection'].' · '. $source['name'];}
        }
        $byName=[];$selectedRaw=null;$selectedCopies=[];
        foreach($channels as $item){
            $name=(string)$item['name'];
            for($n=0;$n<3;$n++){
                $next=preg_replace('/^\s*(?:(?:UK|GB)\s*[-–—:|]|\[\s*(?:UK|GB)\s*\]|[|┃│¦]\s*(?:UK|GB)\s*[|┃│¦])\s*/iu','',$name);
                if($next===$name)break;$name=trim($next);
            }
            // A previously selected channel must remain playable even after
            // the viewer changes a display-only name/quality filter.
            if($selectedChannel!==''&&(string)$item['id']===$selectedChannel&&(string)$item['feed']===$selectedFeed)$selectedRaw=$item;
            $upper=mb_strtoupper($name);
            $quality='';$rank=0;
            foreach(['UHD'=>'(?:UHD|4K|2160P)','FHD'=>'(?:FHD|FULL\s*HD|1080P)','HD'=>'(?:HD|720P)','SD'=>'(?:SD|480P|576P)'] as $q=>$pattern){
                if(preg_match('/(?:^|[^A-Z0-9])'.$pattern.'(?:$|[^A-Z0-9])/',$upper)){$quality=$q;$rank=['UHD'=>4,'FHD'=>3,'HD'=>2,'SD'=>1][$q];break;}
            }
            // Strip only terminal quality/codec labels, never channel numbers or regional names.
            $clean=trim(preg_replace('/(?:\s*[-|:]\s*|\s+|\s*[\[(])(?:UHD|4K|2160P|FHD|FULL\s*HD|1080P|HD|720P|SD|480P|576P|HEVC|H\.?265|H\.?264)(?:[\])])?\s*$/iu','',$name));
            for($i=0;$i<3;$i++){$next=trim(preg_replace('/(?:\s*[-|:]\s*|\s+|\s*[\[(])(?:UHD|4K|2160P|FHD|FULL\s*HD|1080P|HD|720P|SD|480P|576P|HEVC|H\.?265|H\.?264)(?:[\])])?\s*$/iu','',$clean));if($next===$clean)break;$clean=$next;}
            if($clean==='')$clean=$name;
            if($channelQuery!==''&&mb_stripos($name,$channelQuery)===false&&mb_stripos($clean,$channelQuery)===false)continue;
            $key=mb_strtolower(trim(preg_replace('/\s+/u',' ',$clean)));
            if($key==='')continue;
            $item['compatQuality']=$quality;$item['compatQualityRank']=$rank;
            if(!isset($byName[$key]))$byName[$key]=['name'=>$clean,'copies'=>[]];
            $byName[$key]['copies'][(string)$item['feed'].'|'.(string)$item['id']]=$item;
        }
        foreach($byName as &$group){$group['copies']=array_values($group['copies']);usort($group['copies'],static function($a,$b){return $b['compatQualityRank']<=>$a['compatQualityRank'] ?: strcmp((string)$a['feed'],(string)$b['feed']) ?: strcmp((string)$a['id'],(string)$b['id']);});}unset($group);
        // The following links retain the provider page and selected folder window.
        $rootParams=$base+['country'=>$country]+($section!==''?['section'=>$section]:['folder'=>$folderId,'folder_feed'=>$folderFeed])+['source_offset'=>$sourceOffset];
        $params=$rootParams+['q'=>$channelQuery];
        if($selectedChannel==='')$html.=self::searchForm($rootParams,$channelQuery);
        $html.='<p class="compat-note">'.count($byName).' matching channels on this page · '.count($slice).' provider folders'.($failures?' · Unavailable: '.self::esc(implode(', ',$failures)):'').'</p>';
        if($selectedChannel!==''){
            $item=null;$copies=[];
            foreach($byName as $group)foreach($group['copies'] as $candidate)if((string)$candidate['id']===$selectedChannel&&(string)$candidate['feed']===$selectedFeed){$item=$candidate;$copies=$group['copies'];break 2;}
            if(!$item)$item=$selectedRaw;
            if(!$item)return $html.'<p>This channel is no longer on the selected provider page.</p>'.self::link('Back to channels',$params+['page'=>$page]);
            $html.=self::link('Back to channels',$params+['page'=>$page]).'<h3>'.self::esc($item['name']).'</h3>';
            $watchParams=$params+['page'=>$page,'feed'=>$selectedFeed,'channel'=>$selectedChannel];
            if(!empty($_GET['guide'])){
                $html.=self::link('Watch channel',$watchParams).'<div class="compat-panel"><h3>Programme guide</h3>';
                try{
                    $data=self::stalkerRequest('stalker_live_guide',['feed'=>$selectedFeed,'category'=>(string)($item['category'] ?? '*'),'page'=>(int)($item['page'] ?? $page),'provider_page'=>!empty($item['compatProviderPage'])?1:0]);
                    $events=$data['programmes'][(string)$item['id']] ?? [];
                    if(!$events)$html.='<p class="compat-note">No programme information is available for this channel.</p>';
                    foreach(array_slice($events,0,16) as $event){$event['startUnix']=(int)($event['start'] ?? 0)*1000;$event['endUnix']=(int)($event['end'] ?? 0)*1000;$event['subtitle']=(string)($event['description']??'');$html.=self::programme($event);}
                }catch(Throwable $e){$html.='<p class="compat-note">The programme guide could not be loaded. Try again later.</p>';}
                return $html.'</div>';
            }
            $html.=self::link('Programme guide',$watchParams+['guide'=>'1']);
            $switches='';$fallback='';$attempt=max(0,min(7,(int)($_GET['attempt'] ?? 0)));$limit=min(8,count($copies));$nextLabel='';
            $auto=(self::$viewer['prefs']['autoplay_on_open'] ?? true)!==false;
            if(count($copies)>1){
                $choices='<div class="compat-choicebar">';
                foreach($copies as $copy)$choices.=self::choice((string)$copy['connection'].(!empty($copy['compatQuality'])?' · '.$copy['compatQuality']:''),$params+['page'=>$page,'feed'=>$copy['feed'],'channel'=>$copy['id']],(string)$copy['feed']===$selectedFeed&&(string)$copy['id']===$selectedChannel);
                $switches.=self::disclosure('compat-live-sources','Available sources ('.count($copies).')',$choices.'</div>');
                foreach($copies as $n=>$copy)if((string)$copy['feed']===$selectedFeed&&(string)$copy['id']===$selectedChannel){
                    $next=$copies[($n+1)%count($copies)];
                    $switches.=self::link('Try next source',$params+['page'=>$page,'feed'=>$next['feed'],'channel'=>$next['id']]);
                    if($attempt+1<$limit){$fallback=self::url($params+['page'=>$page,'feed'=>$next['feed'],'channel'=>$next['id'],'attempt'=>$attempt+1]);$nextLabel=(string)$next['connection'].(!empty($next['compatQuality'])?' · '.$next['compatQuality']:'');}
                    break;
                }
            }
            $html.='<div class="compat-live-source-info"><strong>Current source:</strong> '.self::esc((string)($item['connection'] ?? 'Provider').(!empty($item['compatQuality'])?' · '.$item['compatQuality']:'')).
                ($attempt>0?' <span class="compat-note">Automatic attempt '.($attempt+1).'</span>':'').'</div>';
            if($fallback!=='')$html.='<p class="compat-note">Playback failures will try the next available source automatically.</p>';
            try{
                $feed=null;foreach($feeds as $candidate)if((string)$candidate['id']===$selectedFeed){$feed=$candidate;break;}
                if(!$feed)throw new RuntimeException('Connection unavailable.');
                [$stream,$hls]=self::stalkerSource($feed,(string)$item['id'],'live',['category'=>$item['category']??'','page'=>$item['page']??$page]);
                if($hls)$html.='<p class="compat-note">This source requires HLS playback.</p>';
                else $html.='<p class="compat-note">Native TV stream · compatible HLS transport segments are joined without transcoding.</p>';
                $html.=self::player($stream,false,[],['hls'=>$hls,'live_source'=>true,'fallback_url'=>$fallback,'fallback_label'=>$nextLabel,'autoplay'=>$auto||$attempt>0]);
            }catch(Throwable $e){
                $html.='<p class="compat-error">'.self::esc($e instanceof RuntimeException?$e->getMessage():'This source could not be opened.').'</p>';
                if($fallback!=='')$html.='<a class="compat-button" id="compat-failed-source" data-compat-auto="'.($auto||$attempt>0?'1':'0').'" href="'.self::esc($fallback).'">Try '.self::esc($nextLabel).'</a>';
                else $html.='<p class="compat-note">No further automatic alternatives. Select a source manually or return to the channels.</p>';
            }
            $keys=array_keys($byName);$index=null;
            foreach($keys as $i=>$key)foreach($byName[$key]['copies'] as $copy)if((string)$copy['id']===$selectedChannel&&(string)$copy['feed']===$selectedFeed){$index=$i;break 2;}
            if($index!==null){
                $html.='<div class="compat-choicebar compat-channel-step">';
                foreach([-1=>'← ',1=>''] as $step=>$prefix)if(isset($keys[$index+$step])){$near=$byName[$keys[$index+$step]];$first=$near['copies'][0];$html.=self::link($prefix.$near['name'].($step===1?' →':''),$params+['page'=>$page,'feed'=>$first['feed'],'channel'=>$first['id']]);}
                $html.='</div>';
            }
            return $html.$switches;
        }
        $html.='<ul class="compat-directory compat-channel-directory">';
        foreach($byName as $group){
            $item=$group['copies'][0];
            $qualities=[];$connections=[];foreach($group['copies'] as $copy){if(!empty($copy['compatQuality']))$qualities[$copy['compatQuality']]=true;$connections[$copy['feed']]=true;}
            $html.='<li>'.self::browseRow($group['name'],($qualities?implode(' / ',array_keys($qualities)).' · ':'').count($connections).' connection(s) · '.count($group['copies']).' source(s)',$params+['page'=>$page,'feed'=>$item['feed'],'channel'=>$item['id']]).'</li>';
        }
        if(!$byName)$html.='<li class="compat-empty">No matching channels on this provider page. Try a different search or the next provider folder.</li>';
        $html.='</ul>';
        $html.=self::pages($params,$page,$more);
        if($sourceOffset>0)$html.=self::link('Previous provider folders',array_merge($params,['source_offset'=>max(0,$sourceOffset-$window),'page'=>1]));
        if($sourceOffset+$window<count($target))$html.=self::link('More provider folders',array_merge($params,['source_offset'=>$sourceOffset+$window,'page'=>1]));
        return $html;
    }
    private static function providerPoster(array $item,string $title): string {
        $url=(string)($item['poster'] ?? '');
        $valid=Webcams::validUrl($url)&&parse_url($url,PHP_URL_USER)===null&&parse_url($url,PHP_URL_PASS)===null;
        return '<span class="compat-poster compat-poster-card"><span class="compat-poster-fallback">'.self::esc($title).'</span>'.($valid?'<img data-compat-poster="1" loading="lazy" referrerpolicy="no-referrer" src="'.self::esc($url).'" alt="" width="240" height="360">':'').'</span>';
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
            if(preg_match('/(?:[|┃│¦]\s*([A-Z]{2,3})\s*[|┃│¦]|\[\s*([A-Z]{2,3})\s*\])/u',$name,$m))$code=(string)(($m[1]??'')!==''?$m[1]:($m[2]??''));
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
            try{$data=self::stalkerTitles($feed,['kind'=>'series','feed'=>$feed['id'],'parent'=>$parent,'page'=>$page]);}
            catch(Throwable $e){return $html.'<p>Could not retrieve the current episodes.</p>';}
            $items=$data['items']??[];
            usort($items,static function($a,$b){
                return (int)($b['season']??0)<=>(int)($a['season']??0)
                    ?: (int)($b['episode']??0)<=>(int)($a['episode']??0);
            });
            $episodeList='<ul class="compat-list compat-provider-episodes">';
            $lastSeason=null;$selectedEpisode=null;
            foreach($items as $item){
                $season=(string)($item['season']??'?');$episode=(string)($item['episode']??'?');
                if($lastSeason!==$season){$episodeList.='<li class="compat-season-heading"><h3>Season '.self::esc($season).'</h3></li>';$lastSeason=$season;}
                $episodeList.='<li>'.self::browseRow((string)($item['episodeTitle']??$item['name']??('Episode '.$episode)),'Episode '.$episode,$base+['lang'=>$lang,'parent'=>$parent,'feed'=>$feed['id'],'page'=>$page,'item'=>$item['id']],$episode,(string)$item['id']===$selectedItem).'</li>';
                if((string)$item['id']===$selectedItem&&!empty($item['playable']))$selectedEpisode=$item;
            }
            $episodeList.='</ul>'.self::pages($base+['lang'=>$lang,'parent'=>$parent,'feed'=>$feed['id']],$page,!empty($data['hasMore']));
            if($selectedEpisode!==null)return $html.self::stalkerOnDemandPlayer($feed,$selectedEpisode).self::disclosure('compat-vod-episodes','Browse other episodes',$episodeList);
            if($selectedItem!=='')$html.='<p>The selected episode is no longer on this page.</p>';
            return $html.$episodeList;
        }
        $folders=[];$errors=[];
        foreach($feeds as $feed){
            try{
                $data=self::stalkerTitles($feed,['kind'=>$kind,'feed'=>$feed['id'],'categories_only'=>1]);
                foreach($data['categories']??[] as $category){
                    $folders[]=['feed'=>$feed['id'],'connection'=>$feed['name'],'id'=>(string)$category['id'],
                        'name'=>$name((string)$category['name']),'language'=>$language((string)$category['name'])];
                }
            }catch(RuntimeException $e){$errors[]=$feed['name'].': '.$e->getMessage();}catch(Throwable $e){$errors[]=$feed['name'];}
        }
        $available=['UK'=>true];
        foreach($folders as $folder)$available[$folder['language']]=true;
        $langs=array_keys($available);
        usort($langs,static function($a,$b){return $a==='UK'?-1:($b==='UK'?1:strcmp($a,$b));});
        $html.='<div class="compat-choicebar">'.self::choice('English',$base+['lang'=>'UK'],$lang==='UK').self::choice('All languages',$base+['lang'=>'all'],$lang==='all');
        $other='';foreach($langs as $value)if($value!=='UK')$other.=self::choice($value,$base+['lang'=>$value],$lang===$value);
        if($other!=='')$html.=self::disclosure('compat-vod-languages','Other languages',$other,!in_array($lang,['UK','all'],true));
        $html.='</div>';
        $html.=self::searchForm($base+['lang'=>$lang]+($folderId!==''?['folder'=>$folderId,'folder_feed'=>$folderFeed]:[]),$query);
        if($errors)$html.='<p class="compat-note">'.self::esc(implode('; ',$errors)).'</p>';
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
            $html.='<h3>Provider folders</h3><ul class="compat-directory">';
            foreach($entries as $entry){
                $first=$entry['sources'][0];
                $html.='<li>'.self::browseRow($entry['name'],count($entry['sources']).' connection(s)',
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
        $window=3;$sourceList=array_slice($filtered,$sourceOffset,$window);
        $items=[];$failed=0;$more=false;
        foreach($sourceList as $src){
            $feed=$feedById[$src['feed']]??null;
            if(!$feed)continue;
            try{
                $data=self::stalkerTitles($feed,[
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
        $html.='<p class="compat-note">'.count($groups).' titles loaded'.($failed?' · '.$failed.' unavailable folders':'').'</p>';$titleList='<ul class="compat-cards compat-provider-cards">';
        $params=$base+['lang'=>$lang,'q'=>$query,'source_offset'=>$sourceOffset]+($folderId!==''?['folder'=>$folderId,'folder_feed'=>$folderFeed]:[]);
        foreach($groups as $group){
            $first=$group['sources'][0];
            $art=$first;foreach($group['sources'] as $candidate)if(!empty($candidate['poster'])){$art=$candidate;break;}
            $titleList.='<li><a class="compat-card" href="'.self::esc(self::url($params+['page'=>$page,'feed'=>$first['feed'],'item'=>$first['id']])).'" title="'.self::esc($group['title']).'">'.self::providerPoster($art,$group['title']).'<span class="compat-card-caption"><strong>'.self::esc($group['title']).'</strong><small>'.self::esc(($group['year']?$group['year'].' · ':'').count($group['sources']).' source(s)').'</small></span></a></li>';
        }
        if(!$groups)$titleList.='<li class="compat-empty">No titles on this page.</li>';
        $titleList.='</ul>';
        if($selectedItem!==''){
            $found=null;$copies=[];
            foreach($groups as $group)foreach($group['sources'] as $item)
                if((string)$item['id']===$selectedItem&&(string)$item['feed']===$selectedFeed){$found=$item;$copies=$group['sources'];break 2;}
            if($found!==null){
                $html.=self::link('Back to results',$params+['page'=>$page]);
                if(!empty($found['playable']))$html.=self::stalkerOnDemandPlayer($feedById[$selectedFeed],$found);
                else $html.='<div class="compat-detail compat-media-detail">'.self::providerPoster($found,(string)($found['name'] ?? 'Series')).'<div class="compat-detail-copy"><h2>'.self::esc($found['name']??'Series').'</h2><p class="compat-note">'.self::esc($found['year'] ?? '').'</p><p class="compat-synopsis">'.self::esc(strip_tags((string)($found['description'] ?? ''))).'</p>'.self::link('Browse seasons and episodes',$base+['lang'=>$lang,'parent'=>$found['id'],'feed'=>$selectedFeed]).'</div></div>';
                if(count($copies)>1){
                    $sources='<div class="compat-choicebar">';
                    foreach($copies as $source)$sources.=self::choice((string)$source['connection'],
                        $params+['page'=>$page,'feed'=>$source['feed'],'item'=>$source['id']],(string)$source['feed']===$selectedFeed&&(string)$source['id']===$selectedItem);
                    $html.=self::disclosure('compat-vod-sources','Available connections ('.count($copies).')',$sources.'</div>');
                    foreach($copies as $n=>$copy)if((string)$copy['feed']===$selectedFeed&&(string)$copy['id']===$selectedItem){
                        $next=$copies[($n+1)%count($copies)];
                        $html.=self::link('Try next connection',$params+['page'=>$page,'feed'=>$next['feed'],'item'=>$next['id']]);
                        break;
                    }
                }
            }else $html.='<p>This title is no longer on the selected page.</p>';
        }
        $html.=$selectedItem!==''?self::disclosure('compat-vod-results','Browse other titles',$titleList):$titleList;
        $html.=self::pages($params,$page,$more);
        if($sourceOffset>0)$html.=self::link('Previous provider folders',$base+['lang'=>$lang,'q'=>$query,'source_offset'=>max(0,$sourceOffset-$window)]+($folderId!==''?['folder'=>$folderId,'folder_feed'=>$folderFeed]:[]));
        if($sourceOffset+$window<count($filtered))$html.=self::link('More provider folders',$base+['lang'=>$lang,'q'=>$query,'source_offset'=>$sourceOffset+$window]+($folderId!==''?['folder'=>$folderId,'folder_feed'=>$folderFeed]:[]));
        return $html;
    }
    /** Playback remains ticket-scoped and uses the existing Samsung-safe player. */
    private static function stalkerOnDemandPlayer(array $feed,array $item): string {
        try{
            [$stream,$hls]=self::stalkerSource($feed,(string)$item['id'],(string)($item['kind']??'vod'));
            $title=(string)($item['name']??'Provider video');
            $details='<div class="compat-detail compat-media-detail">'.self::providerPoster($item,$title).'<div class="compat-detail-copy"><h2>'.self::esc($title).'</h2><p class="compat-note">'.self::esc($item['year'] ?? '').'</p><p class="compat-synopsis">'.self::esc(strip_tags((string)($item['description'] ?? ''))).'</p></div></div>';
            return $details.self::player($stream,false,[],['hls'=>$hls]);
        }catch(Throwable $e){
            return '<p class="compat-note">Provider video unavailable. Try another connection or return to the catalogue.</p>';
        }
    }
    private static function live(string $id,int $page,string $query): string {
        self::allow('live');if(empty(Store::settings()['features']['live']))throw new RuntimeException('Live is disabled.');
        $tab=in_array($_GET['tab'] ?? '',['now','relay','iptv','radio','stalker','xtream'],true)?$_GET['tab']:'now';
        if($tab==='stalker')return self::stalker($page);
        if($tab==='xtream')return self::heading('LIVE','Xtream Codes','Browse your configured connections.').'<div id="compat-xtream"><p id="compat-xtream-status" role="status">Loading connections…</p><noscript><p>Enable JavaScript to browse this provider.</p></noscript><div id="compat-xtream-controls"></div><div id="compat-xtream-player"></div><div id="compat-xtream-results"></div></div>';
        if($tab==='radio'){
            $stations=Radio::stations();$html='<h2>Radio</h2>'.self::link('Live television',['view'=>'live']);
            if($id!==''){foreach($stations as $radio)if($radio['id']===$id){$html.='<section class="compat-panel"><h3>'.self::esc($radio['name']).'</h3><audio controls="controls" preload="none" src="'.self::esc($radio['url']).'">Your browser needs support for this audio format.</audio><p>'.self::esc($radio['description'] ?: ($radio['tags'] ?? '')).'</p></section>';break;}}
            $html.=self::searchForm(['view'=>'live','tab'=>'radio'],$query);$stations=array_values(array_filter($stations,static function($radio)use($query){return $query===''||mb_stripos($radio['name'].' '.($radio['country'] ?? '').' '.($radio['tags'] ?? ''),$query)!==false;}));
            $html.='<ul class="compat-list">';foreach(array_slice($stations,($page-1)*16,16) as $radio)$html.='<li>'.self::link($radio['name'],['view'=>'live','tab'=>'radio','id'=>$radio['id']]).'<p class="compat-note">'.self::esc(($radio['country'] ?? '').' · '.($radio['tags'] ?? '')).'</p></li>';
            return $html.'</ul>'.self::pages(['view'=>'live','tab'=>'radio','q'=>$query],$page,$page*16<count($stations));
        }
        require_once STREAM_ROOT.'/src/Live/store.php';require_once STREAM_ROOT.'/src/Live/epg.php';require_once STREAM_ROOT.'/src/Live/schedule.php';
        $schedule=mb_substr((string)($_GET['schedule'] ?? ''),0,100);$station=relay_load_station();$guide=LiveGuideCache::read(2,$schedule==='',self::$viewer,static function($hours,$slim)use($station){return epg_unified_guide($station,$hours,$slim,self::$viewer);});
        $html=self::heading('LIVE TELEVISION',$tab==='iptv'?'IPTV':($tab==='relay'?'Relay channels':'On now'));
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
                parse_str((string)parse_url($url,PHP_URL_QUERY),$params);
                $isStalker=is_string($params['feed']??null)&&is_string($params['channel']??null)
                    &&$url===stalker_channel_url($params['feed'],$params['channel']);
                if($isStalker){
                    $feed=iptv_feed_by_id(iptv_load(),$params['feed']);
                    if(!$feed||($feed['type']??'')!=='stalker'||empty($feed['enabled']))throw new RuntimeException('Portal source unavailable.');
                    // The stream endpoint applies the saved-lineup access policy on older installs.
                    if(!Parental::filtering()){
                        $native=self::stalkerRequest('stalker_play',['feed'=>$feed['id'],'id'=>$params['channel'],'kind'=>'live']);
                        if($native!==null)$url=$native['source']['url'];
                    }
                }elseif(preg_match('/[.]m3u8(?:[?]|$)/i',$url)){
                    $url=self::$base.'api/live/iptv_hls?'.http_build_query(['u'=>$url,'jar'=>bin2hex(random_bytes(8))]+(!empty($src['userAgent'])?['ua'=>$src['userAgent']]:[])+(!empty($src['referrer'])?['ref'=>$src['referrer']]:[]),'','&',PHP_QUERY_RFC3986);
                }
                $html.=self::player($url,false,[],['hls'=>(bool)preg_match('/[.]m3u8(?:[?]|$)/i',$isStalker?$url:$src['url'])]);
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
        usort($channels,static function($a,$b){return (int)($a['number'] ?? 0)<=>(int)($b['number'] ?? 0);});
        $browse=['view'=>'live','tab'=>$tab,'q'=>$query,'page'=>$page];
        if($id!==''){
            foreach($channels as $index=>$ch)if((string)$ch['id']===$id){
                $html.='<div class="compat-choicebar compat-channel-step">';
                if($index>0)$html.=self::link('← '.$channels[$index-1]['name'],$browse+['id'=>$channels[$index-1]['id']]);
                $html.=self::link('Programme guide',$browse+['schedule'=>$id]);
                if(isset($channels[$index+1]))$html.=self::link($channels[$index+1]['name'].' →',$browse+['id'=>$channels[$index+1]['id']]);
                $html.='</div>';break;
            }
        }
        $html.='<p class="compat-note">'.count($channels).' channels</p><ul class="compat-list compat-lineup compat-live-grid">';
        $nowMs=(int)(microtime(true)*1000);
        foreach(array_slice($channels,($page-1)*16,16) as $ch){
            $current=(array)($ch['now'] ?? []);$next=(array)($ch['next'] ?? []);$start=(int)($current['startUnix'] ?? 0);$end=(int)($current['endUnix'] ?? 0);
            $percent=$end>$start?max(0,min(100,(int)round(100*($nowMs-$start)/($end-$start)))):0;
            $logo=(string)($ch['logo'] ?? '');$name=(string)($ch['name'] ?? 'Channel');$logoHtml='';
            if(preg_match('#^https?://[^\s<>"\\\\]+$#iD',$logo)||preg_match('#^/(?!/)[^\s<>"\\\\]+$#D',$logo))$logoHtml='<img class="compat-channel-logo" src="'.self::esc($logo).'" alt="">';
            $html.='<li class="compat-live-tile'.((string)$ch['id']===$id?' active':'').'"><a class="compat-live-watch" href="'.self::esc(self::url($browse+['id'=>$ch['id']])).'"'.((string)$ch['id']===$id?' aria-current="page"':'').'><span class="compat-live-top"><strong class="compat-live-number">'.self::esc($ch['number'] ?? '').'</strong>'.$logoHtml.'<span class="compat-live-badge">'.(($ch['kind'] ?? '')==='iptv'?'IPTV':'LIVE').'</span></span><span class="compat-live-channel">'.self::esc($name).'</span><strong class="compat-live-title">'.self::esc($current['title'] ?? 'Watch channel').'</strong>';
            if($start&&$end>$start)$html.='<span class="compat-live-time">'.self::esc(gmdate('H:i',(int)($start/1000)).'–'.gmdate('H:i',(int)($end/1000))).' UTC</span><span class="compat-progress"><span style="width:'.$percent.'%"></span></span>';
            if(!empty($next['title']))$html.='<span class="compat-live-next">Next · '.self::esc($next['title']).'</span>';
            $html.='</a>'.self::link('Programme guide',$browse+['schedule'=>$ch['id']]).'</li>';
        }
        if(!$channels)$html.='<li class="compat-empty">No matching channels.</li>';
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
            elseif(in_array($route,['movies','tvshows','games'],true)&&!empty($parts[1])&&!in_array($parts[1],['leaderboards','lobby'],true)){$_GET['view']='resolve-link';$_GET['path']='/'.$path;}
            elseif($route==='search'){$_GET['view']='search';}
            elseif($route==='collections'){$_GET['view']=empty($parts[1])?'collections':'collection';if(!empty($parts[1]))$_GET['id']=$parts[1];}
            elseif($route==='play'){$_GET['view']='play';if(isset(self::playTabs()[$parts[1] ?? ''])){$_GET['tab']=$parts[1];$_GET['sub']=implode('/',array_slice($parts,2));}}
            elseif($route==='home'){$_GET['view']=($parts[1] ?? '')==='overview'?'home':($parts[1] ?? 'home');if(!empty($parts[2]))$_GET['id']=$parts[2];}
            elseif($route==='requests'){$_GET['view']='requests';$_GET['tab']=$parts[1] ?? 'discover';}
            elseif($route==='misc'){$_GET['view']='misc';$_GET['tab']=$parts[1] ?? 'board';if(!empty($parts[2]))$_GET['id']=$parts[2];}
            elseif($route==='settings'){
                $section=$parts[1] ?? '';
                $map=['inbox'=>'inbox','parental'=>'parental','users'=>'users','roles'=>'roles','general'=>'configuration','live'=>'live-admin','live-stalker'=>'live-admin','live-xtream'=>'live-admin','live-iptv'=>'live-admin','updates'=>'tool','system'=>'tool','maintenance'=>'tool','tools'=>'tools'];
                if($section==='live-stalker')$_GET['provider']='stalker';elseif($section==='live-xtream')$_GET['provider']='xtream';
                $_GET['view']=$map[$section] ?? 'account';if(in_array($section,['updates','system','maintenance'],true))$_GET['id']=$section;
            }
            elseif($route==='offline'){$_GET['view']='offline';}
            elseif($route==='inbox'||$route==='login'){$_GET['view']=$route;}
            elseif($route==='games'&&in_array($parts[1] ?? '',['','leaderboards'],true)){$_GET['view']='play';$_GET['tab']=($parts[1] ?? '')==='leaderboards'?'leaderboards':'games';}
            elseif($route==='live'){$_GET['view']='live';if(in_array($parts[1] ?? '',['now','relay','iptv','radio','stalker','xtream'],true)){$_GET['tab']=$parts[1];if($parts[1]==='radio'&&!empty($parts[2]))$_GET['id']=$parts[2];}elseif(!empty($parts[1]))$_GET['id']=$parts[1];}
        }
        $home=$startup['home'] ?? null;$body='';$view=(string)($_GET['view'] ?? 'home');
        if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['compat_action'])){
            if(!hash_equals(self::token(),(string)($_POST['compat_token'] ?? ''))){http_response_code(403);$body='<p class="compat-error">This form expired. Reload the page and try again.</p>';}
            elseif($_POST['compat_action']==='logout'){Auth::destroySession();header('Location: '.self::url(),true,303);return;}
            elseif(!in_array($_POST['compat_action'],['shelf','play'],true)){
                try{if((!self::$signed && !in_array(($_POST['compat_action'] ?? ''),['report','convert-request'],true)) || !$home)throw new RuntimeException('Sign in to use this form.');self::guardView($view,(string)($_GET['id'] ?? ''));$destination=self::enhancedPost($view,$_POST);header('Location: '.self::url($destination),true,303);return;}
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
        $nav='<div class="compat-header"><div class="compat-header-inner"><div class="compat-topbar"><a class="compat-brand" href="'.self::esc(self::url()).'"><span class="compat-logo">&#9654;</span> <span class="compat-brand-name">'.self::esc($site).'</span></a><div class="compat-major-nav" role="navigation" aria-label="Main navigation">';
        $tabs=['home'=>'Home'];if($home && AreaAccess::allowed(self::$viewer,'library'))$tabs['library']='Library';if(!empty($home['features']['live']))$tabs['live']='Live';if($home && AreaAccess::allowed(self::$viewer,'games'))$tabs['play']='Play';if($home&&self::$signed)$tabs['misc']='Misc';if($home&&self::offlineAvailable())$tabs['offline']='Downloads';$tabs['account']=self::$signed?'Settings':'Sign in';$navRight='';
        foreach($tabs as $key=>$label){$target=$key==='account'&&!self::$signed?'login':$key;$selected=$view===$target || ($key==='play'&&in_array($view,['game','emulator'],true)) || ($key==='library'&&in_array($view,['section','show','item','search','collections','collection','requests'],true)) || ($key==='account'&&in_array($view,['parental','tools','tool','tool-result','configuration','users','edit-user','roles','edit-role','live-admin','factory-reset'],true)) || ($key==='home'&&in_array($view,['favourites','watchlist','calendar','history','arrivals','tonight','cinema','invitations','home-options','lists','friends','inbox','chat'],true));$anchor='<a'.($selected?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>$target])).'">'.self::esc($label).'</a>';if(in_array($key,['offline','account'],true))$navRight.=$anchor;else $nav.=$anchor;}
        $nav.='</div><div class="compat-header-right">'.$headerSearch.'<div class="compat-account-nav" role="navigation" aria-label="Account and downloads">'.$navRight.'</div></div></div></div></div>';
        if($home){
            $nav.='<div class="compat-subnav" role="navigation" aria-label="Section navigation"><div class="compat-subnav-inner">';
            if(in_array($view,['library','section','show','item','search','collections','collection','requests'],true)){
                $libraryTabs=[['label'=>'Discover','params'=>['view'=>'library'],'active'=>$view==='library']];
                foreach($home['sections'] ?? [] as $section)if(($section['type'] ?? '')!=='roms')$libraryTabs[]=['label'=>$section['label'] ?? $section['type'],'params'=>['view'=>'section','id'=>$section['id']],'active'=>$view==='section'&&($_GET['id'] ?? '')===$section['id']];
                $libraryTabs[]=['label'=>'Collections','params'=>['view'=>'collections'],'active'=>in_array($view,['collections','collection'],true)];
                if(self::$signed&&(MediaRequests::config()['enabled']||MediaRequests::canManage(self::$viewer)))$libraryTabs[]=['label'=>'Requests','params'=>['view'=>'requests'],'active'=>$view==='requests'];
                foreach($libraryTabs as $tab)$nav.='<a'.($tab['active']?' class="active" aria-current="page"':'').' href="'.self::esc(self::url($tab['params'])).'">'.self::esc($tab['label']).'</a>';
            }elseif(in_array($view,['play','game','emulator'],true)){
                foreach(self::playTabs() as $tab=>$label)$nav.='<a'.(($view==='play'?($_GET['tab'] ?? 'games'):$tab)===$tab && ($view==='play'||$tab==='games')?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>'play','tab'=>$tab])).'">'.self::esc($label).'</a>';
            }elseif($view==='misc'){
                $miscTabs=['calendar'=>'Calendar','reminders'=>'Reminders','pictures'=>'Pictures','drop'=>'Drop','board'=>'Boards','ambient'=>'Ambient','webcams'=>'Webcams'];if((self::$viewer['role'] ?? '')==='admin')$miscTabs+=['drop-admin'=>'Drop settings','ambient-admin'=>'Ambient settings'];foreach($miscTabs as $tab=>$label)$nav.='<a'.(($_GET['tab'] ?? 'calendar')===$tab?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>'misc','tab'=>$tab])).'">'.self::esc($label).'</a>';
            }elseif(in_array($view,['account','parental','tools','tool','tool-result','configuration','users','edit-user','roles','edit-role','live-admin','factory-reset'],true)){
                $settings=['account'=>'Account','parental'=>'Parental controls','tools'=>'Tools'];if((self::$viewer['role'] ?? '')==='admin')$settings+=['configuration'=>'Configuration','users'=>'Users','roles'=>'Roles','live-admin'=>'Live settings'];foreach($settings as $tab=>$label)$nav.='<a'.($view===$tab?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>$tab])).'">'.self::esc($label).'</a>';
            }elseif($view==='live'){
                foreach(['now'=>'On now','relay'=>'Relay','iptv'=>'IPTV','stalker'=>'Stalker','xtream'=>'Xtream','radio'=>'Radio'] as $tab=>$label){$selected=($_GET['tab'] ?? 'now')===$tab;$nav.='<a'.($selected?' class="active" aria-current="page"':'').' href="'.self::esc(self::url(['view'=>'live','tab'=>$tab])).'">'.self::esc($label).'</a>';}
            }elseif($view==='offline'){
                $nav.=self::link('My downloads',['view'=>'offline']).self::link('Browse library',['view'=>'library']);
            }else{
                $main=['home'=>'Overview','favourites'=>'Favourites','watchlist'=>'Watchlist','calendar'=>'Release calendar','arrivals'=>'New arrivals','friends'=>'Friends','inbox'=>'Inbox'];
                $secondary=['history'=>'History','tonight'=>'Find a watch','cinema'=>'Watch together','invitations'=>'Invitations','lists'=>'Custom lists','chat'=>'Chat','home-options'=>'Customise Home'];
                $more='';
                foreach([$main,$secondary] as $groupIndex=>$group)foreach($group as $key=>$label){
                    if($key!=='home'&&!self::$signed)continue;
                    if(in_array($key,['friends','inbox','chat','cinema','invitations'],true)&&!self::socialAllowed())continue;
                    $anchor='<a'.($view===$key?' class="active" aria-current="page"':'').' href="'.self::esc(self::url($key==='home'?['view'=>'home','overview'=>'1']:['view'=>$key])).'">'.self::esc($label).'</a>';
                    if($groupIndex===0)$nav.=$anchor;else $more.=$anchor;
                }
                if($more!=='')$nav.=self::disclosure('compat-home-more','More Home options',$more,isset($secondary[$view]));
            }
            $nav.='</div></div>';
        }
        if($home && $view!=='home'){
            $back=['view'=>'home'];$label='Back to Home';
            if($view==='item'){$current=Catalogue::item((string)($_GET['id'] ?? ''));if(!empty($current['show'])){$back=['view'=>'show','id'=>Catalogue::showKey((string)$current['section'],(string)$current['show']),'season'=>(int)($current['season'] ?? 0)];$label='Back to show';}elseif(!empty($current['section'])){$back=['view'=>'section','id'=>$current['section']];$label='Back to library';}}
            elseif(in_array($view,['play','game','emulator'],true)){$back=['view'=>'play'];$label='Back to Play';}
            elseif(in_array($view,['section','show','collections','collection','search','requests'],true)){$back=['view'=>'library'];$label='Back to Library';}
            elseif($view==='live'){
                $tab=(string)($_GET['tab'] ?? 'now');$back=['view'=>'live','tab'=>$tab];$label='Back to Live';
                if($tab==='stalker'){
                    $label='Back to Stalker';$back['kind']=(string)($_GET['kind'] ?? 'live');
                    if(!empty($_GET['channel'])||!empty($_GET['item'])||!empty($_GET['guide'])){
                        foreach(['country','section','folder','folder_feed','source_offset','lang','q','page'] as $field)if(isset($_GET[$field])&&is_scalar($_GET[$field]))$back[$field]=(string)$_GET[$field];
                        if(!empty($_GET['parent'])&&!empty($_GET['feed'])){$back['parent']=(string)$_GET['parent'];$back['feed']=(string)$_GET['feed'];}
                        $label='Back to results';
                    }elseif(!empty($_GET['parent'])){$back['lang']=(string)($_GET['lang'] ?? 'UK');$label='Back to Series';}
                    elseif(!empty($_GET['section'])||!empty($_GET['folder'])){
                        foreach(['country','lang'] as $field)if(isset($_GET[$field])&&is_scalar($_GET[$field]))$back[$field]=(string)$_GET[$field];
                        $label='Back to categories';
                    }
                }else foreach(['q','page'] as $field)if(isset($_GET[$field])&&is_scalar($_GET[$field]))$back[$field]=(string)$_GET[$field];
            }
            elseif(in_array($view,['parental','tools','tool','tool-result','configuration','users','edit-user','roles','edit-role','live-admin','factory-reset'],true)){$back=['view'=>'account'];$label='Back to Settings';}
            elseif(in_array($view,['profile','compose','create-room'],true)){$back=['view'=>'friends'];$label='Back to Friends';}
            elseif(in_array($view,['chat','inbox'],true)&&!empty($_GET['id'])){$back=['view'=>$view];$label='Back to conversations';}
            $body='<div class="compat-back">'.str_replace('<a class=', '<a data-compat-back="1" class=', self::link('← '.$label,$back)).'</div>'.$body;
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
        $cssVersion=substr(hash_file('sha256',STREAM_ROOT.'/assets/css/compatibility.css'),0,16);$scriptVersion=substr(hash_file('sha256',STREAM_ROOT.'/assets/js/compatibility.js'),0,16);release_request_locks();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::esc(($view==='home'?'Home':ucwords(str_replace('-',' ',$view))).' · '.$site).'</title><link rel="stylesheet" href="'.self::esc(AssetDelivery::url($base,'assets/css/compatibility.css',$cssVersion)).'"></head><body class="'.self::esc('compat-'.self::$theme.' ').(self::preference('home_compact',false)?'compat-compact ':'').'compat-view-'.self::esc(preg_replace('/[^a-z0-9-]/','',$view)).'"><a class="compat-skip" href="#compat-content">Skip to content</a>'.$nav.'<div id="compat-shell"><div id="compat-content" role="main">'.$body.'</div><div class="compat-footer"><span>Compatibility mode · '.self::esc(STREAM_VERSION).'</span><a href="'.self::esc($base.'?compat=auto').'">Automatic detection</a><a href="'.self::esc($base.'?compat=off').'">Full interface</a></div></div><script>window.__VC_COMPAT_BASE__='.json_encode($base,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script src="'.self::esc(AssetDelivery::url($base,'assets/js/compatibility.js',$scriptVersion)).'"></script></body></html>';
    }
}
