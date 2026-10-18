<?php
declare(strict_types=1);

final class CompatibilityBrowser {
    private static $base;
    private static $viewer;
    private static function esc($text): string { return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function url(array $params=[]): string { return self::$base.'?'.http_build_query(['compat'=>'on']+$params,'','&',PHP_QUERY_RFC3986); }
    private static function link(string $label,array $params=[]): string { return '<a class="compat-button" href="'.self::esc(self::url($params)).'">'.self::esc($label).'</a>'; }
    private static function form(array $params,string $label,string $action, array $fields=[]): string {
        $html='<form method="post" action="'.self::esc(self::url($params)).'"><input type="hidden" name="compat_action" value="'.self::esc($action).'">';
        foreach($fields as $key=>$value)$html.='<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
        return $html.'<button type="submit">'.self::esc($label).'</button></form>';
    }
    private static function allow(string $area): void { if(!AreaAccess::allowed(self::$viewer,$area))throw new RuntimeException('Your account does not have access to this section.'); }
    private static function searchForm(array $params,string $query): string {
        $html='<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on">';
        foreach($params as $key=>$value)$html.='<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
        return $html.'<label>Search <input type="text" name="q" value="'.self::esc($query).'" maxlength="200"></label><button type="submit">Search</button></form>';
    }
    private static function pages(array $params,int $page,bool $more): string {
        return '<p>'.($page>1?self::link('Previous',['page'=>$page-1]+$params):'').' Page '.$page.' '.($more?self::link('Next',['page'=>$page+1]+$params):'').'</p>';
    }
    private static function cards(array $items): string {
        $html='<ul class="compat-list">';
        foreach($items as $item){if(($item['media_kind'] ?? '')==='rom'||($item['type'] ?? '')==='roms')continue;
            $show=($item['kind'] ?? '')==='show'||($item['media_kind'] ?? '')==='show';
            $html.='<li>'.self::link(($item['title'] ?? 'Untitled').(!empty($item['year'])?' ('.$item['year'].')':''),['view'=>$show?'show':'item','id'=>$show?($item['show_key'] ?? $item['id']):$item['id']]).'</li>';
        }
        return $html.'</ul>';
    }
    private static function player(string $url,bool $audio=false,array $tracks=[],array $config=[]): string {
        if(!preg_match('#^(?:https?://|/)#i',$url))throw new RuntimeException('Invalid stream address.');
        $config['base']=self::$base;if(!empty($config['hls']))$config['hls_url']=PlayerAssets::manifest()['hls'];$tag=$audio?'audio':'video';$html='<'.$tag.' id="compat-media" controls="controls" preload="none" width="640" height="360" src="'.self::esc($url).'">';
        foreach($tracks as $track)$html.='<track kind="subtitles" src="'.self::esc($track['url'] ?? '').'" label="'.self::esc($track['label'] ?? 'Subtitles').'" srclang="'.self::esc($track['language'] ?? 'en').'">';
        return $html.'</'.$tag.'><p id="compat-player-message" role="status"></p><script>window.__VC_COMPAT_PLAYER__='.json_encode($config+['url'=>$url],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><p><a class="compat-button" href="'.self::esc($url).'">Open stream directly</a></p><p class="compat-note">Use the player controls to start. If this browser cannot play the stream, open the link with a compatible media player. HLS and video codecs depend on your device.</p>';
    }
    private static function content(array $home): string {
        $view=(string)($_GET['view'] ?? 'home');$id=(string)($_GET['id'] ?? '');$page=max(1,min(1000,(int)($_GET['page'] ?? 1)));$query=mb_substr(trim((string)($_GET['q'] ?? '')),0,200);
        if($view==='home'){
            $html='<h2>Your library</h2><ul class="compat-list">';
            foreach($home['sections'] ?? [] as $section)if(($section['type'] ?? '')!=='roms')$html.='<li>'.self::link($section['label'] ?? $section['name'] ?? $section['type'],['view'=>'section','id'=>$section['id']]).'</li>';
            return $html.'</ul>'.self::searchForm(['view'=>'search'],'');
        }
        if($view==='search'){
            $html='<h2>Search</h2>'.self::searchForm(['view'=>'search'],$query);if($query==='')return $html;
            $result=BrowseIndex::search($query,16,$page,self::$viewer);
            return $html.self::cards($result['catalogue']).self::pages(['view'=>'search','q'=>$query],$page,$result['has_more']);
        }
        if($view==='section'){
            self::allow('library');$section=null;foreach($home['sections'] ?? [] as $s)if($s['id']===$id)$section=$s;
            if(!$section||($section['type'] ?? '')==='roms')throw new RuntimeException('Library unavailable.');
            if(in_array($section['type'],['tv','anime'],true)){
                $rows=array_values(array_filter(BrowseIndex::shows($id),static function($row)use($query){return $query===''||mb_stripos($row['title'],$query)!==false;}));
                $more=$page*16<count($rows);$items=array_slice($rows,($page-1)*16,16);
            }else{$result=\Vaultcast\Repository::browse($id,$query,'','',$page,16);$items=$result['items'];$more=$result['has_more'];}
            return '<h2>'.self::esc($section['label'] ?? $section['type']).'</h2>'.self::searchForm(['view'=>'section','id'=>$id],$query).self::cards($items).self::pages(['view'=>'section','id'=>$id,'q'=>$query],$page,$more);
        }
        if($view==='show'){
            self::allow('library');$detail=Catalogue::show($id);if(!$detail)throw new RuntimeException('Show not found.');
            $html='<h2>'.self::esc($detail['show']['title']).'</h2>';if(!empty($detail['show']['synopsis']))$html.='<p>'.self::esc(strip_tags($detail['show']['synopsis'])).'</p>';
            $seasons=$detail['seasons'] ?? [];$season=(int)($_GET['season'] ?? ($seasons[0]['season'] ?? 0));$html.='<form method="get" action="'.self::esc(self::$base).'"><input type="hidden" name="compat" value="on"><input type="hidden" name="view" value="show"><input type="hidden" name="id" value="'.self::esc($id).'"><label>Season <select name="season">';$episodes=[];
            foreach($seasons as $row){$selected=(int)$row['season']===$season;$html.='<option value="'.(int)$row['season'].'"'.($selected?' selected="selected"':'').'>'.self::esc($row['label']).'</option>';if($selected)$episodes=$row['episodes'];}
            $html.='</select></label><button type="submit">Open season</button></form><ul class="compat-list">';$episodes=array_values(array_filter($episodes,static function($ep){return !empty($ep['id'])&&($ep['available'] ?? true)!==false&&($ep['status'] ?? '')!=='missing';}));
            foreach(array_slice($episodes,($page-1)*16,16) as $ep)$html.='<li>'.self::link('Episode '.($ep['episode'] ?? '?').' - '.($ep['episode_title'] ?? $ep['title'] ?? 'Play'),['view'=>'item','id'=>$ep['id']]).'</li>';
            return $html.'</ul>'.self::pages(['view'=>'show','id'=>$id,'season'=>$season],$page,$page*16<count($episodes));
        }
        if($view==='item'){
            self::allow('library');$item=Catalogue::item($id);if(!$item||AreaAccess::itemArea($item)!=='library')throw new RuntimeException('Media not found.');
            $detail=Catalogue::itemDetail($id);$card=$detail['item'];$html='<h2>'.self::esc($card['title']).'</h2>';
            if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST'&&($_POST['compat_action'] ?? '')==='play'){
                if(!is_file((string)($item['path'] ?? '')))throw new RuntimeException('The media file is unavailable.');
                $play=Stream::issueToken(self::$viewer,$id);$html.=self::player($play['url'],($play['media_kind'] ?? '')==='audio',$play['subtitles'] ?? [],['item_id'=>$id,'progress'=>$play['progress'] ?? null,'save_progress'=>(self::$viewer['id'] ?? '')!=='public','remux_url'=>!empty($play['remux_allowed'])?($play['remux_url'] ?? null):null]);
                if(!empty($play['remux_allowed'])&&!empty($play['remux_url']))$html.='<p><a class="compat-button" data-compat-remux="1" href="'.self::esc($play['remux_url']).'">Open compatible stream</a></p>';
            }else $html.=self::form(['view'=>'item','id'=>$id],'Open player','play');
            if(!empty($card['synopsis']))$html.='<p>'.self::esc(strip_tags($card['synopsis'])).'</p>';
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
        self::$base=$base;self::$viewer=$startup['me']['user'] ?? guest_user();AreaAccess::remember(self::$viewer);
        if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST'&&($_POST['compat_action'] ?? '')==='logout'){Auth::destroySession();header('Location: '.self::url(),true,303);return;}
        $home=$startup['home'] ?? null;$body='';
        if(!$home){
            if(!empty($startup['me']['user']))$body='<p>'.self::esc(Access::message($startup['me']['user']['access_state']['state'] ?? 'pending')).'</p>';
            else $body='<h2>Sign in</h2><form method="post" action="'.self::esc(self::url()).'"><input type="hidden" name="first_action" value="login"><p><label>Username <input type="text" name="username" autocomplete="username"></label></p><p><label>Password <input type="password" name="password" autocomplete="current-password"></label></p><button type="submit">Sign in</button></form><p>'.self::esc($error).'</p>';
        }else{try{$body=self::content($home);}catch(Throwable $e){http_response_code(400);$body='<h2>Unable to open this page</h2><p>'.self::esc(get_class($e)==='RuntimeException'?$e->getMessage():'Please try again.').'</p>'.self::link('Home');error_log('Vaultcast compatibility browser: '.$e->getMessage());}}
        $css=(string)file_get_contents(STREAM_ROOT.'/assets/css/compatibility.css');$scriptVersion=substr(hash_file('sha256',STREAM_ROOT.'/assets/js/compatibility.js'),0,16);release_request_locks();
        header('Content-Type: text/html; charset=utf-8');echo '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::esc($site).'</title><style>'.$css.'</style></head><body><div id="compat-shell"><h1>'.self::esc($site).'</h1><p>Compatibility mode</p><div class="compat-nav">'.self::link('Home').(AreaAccess::allowed(self::$viewer,'library')?self::link('Search',['view'=>'search']):'').(!empty($home['features']['live'])?self::link('Watch Live',['view'=>'live']):'').'<a class="compat-button" href="'.self::esc($base.'?compat=auto').'">Automatic detection</a><a class="compat-button" href="'.self::esc($base.'?compat=off').'">Full interface</a>'.(!empty($startup['me']['user'])?self::form([],'Sign out','logout'):'').'</div>'.$body.'</div><script src="'.self::esc($base.'index.php?asset=assets/js/compatibility.js&v='.$scriptVersion).'"></script></body></html>';
    }
}
