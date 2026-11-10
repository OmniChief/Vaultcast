<?php
/**
 * Unified guide: PseudoTV (Relay cycles) + iptv-org favourites (XMLTV).
 */

require_once __DIR__ . '/iptv.php';
require_once __DIR__ . '/schedule.php';

function epg_cache_dir() {
    $dir = STREAM_DATA . '/live/epg_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function epg_parse_xmltv_time($s) {
    // 20261005120000 +0000 or 20261005120000
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\s*([+-]\d{4}|[A-Z]+)?/', $s, $m)) {
        $tz = isset($m[7]) && $m[7] !== '' ? $m[7] : '+0000';
        if (preg_match('/^[A-Z]+$/', $tz)) {
            try {
                $dt = new DateTime($m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':' . $m[6], new DateTimeZone($tz));
                return (int) ($dt->format('U') * 1000);
            } catch (Exception $e) {
                $tz = '+0000';
            }
        }
        if (preg_match('/^([+-])(\d{2})(\d{2})$/', $tz, $tm)) {
            $sign = $tm[1] === '-' ? -1 : 1;
            $offset = $sign * ((int)$tm[2] * 3600 + (int)$tm[3] * 60);
            $utc = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
            return (int) (($utc - $offset) * 1000);
        }
    }
    $ts = strtotime($s);
    return $ts ? (int) ($ts * 1000) : null;
}

function epg_channel_aliases($tvgId, $id) {
    $aliases = array();
    foreach (array($tvgId, $id) as $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $aliases[$v] = true;
        if (strpos($v, '@') !== false) {
            $base = explode('@', $v, 2)[0];
            $aliases[$base] = true;
        }
    }
    return array_keys($aliases);
}

function epg_wanted_ids($iptvState) {
    $wanted = array();
    foreach ($iptvState['channels'] as $ch) {
        foreach (epg_channel_aliases(!empty($ch['epgId']) ? $ch['epgId'] : ($ch['tvgId'] ?? ''), $ch['id']) as $a) {
            $wanted[$a] = true;
        }
    }
    return $wanted;
}

function epg_fetch_xml($url, $maxAge = 21600) {
    $hash = substr(sha1($url), 0, 16);
    $path = epg_cache_dir() . '/' . $hash . '.xml';
    $metaPath = epg_cache_dir() . '/' . $hash . '.meta.json';
    if (is_file($path) && (time() - filemtime($path)) < $maxAge) {
        return array('path' => $path, 'cached' => true, 'url' => $url);
    }
    $body = iptv_http_get($url, 60);
    // gzip magic
    if (strlen($body) >= 2 && ord($body[0]) === 0x1f && ord($body[1]) === 0x8b) {
        if (!function_exists('gzdecode')) {
            throw new RuntimeException('gzdecode unavailable for ' . $url);
        }
        $decoded = @gzdecode($body);
        if ($decoded === false) {
            throw new RuntimeException('Failed to gunzip EPG: ' . $url);
        }
        $body = $decoded;
    } elseif (preg_match('/\.gz(\?|$)/i', $url) && function_exists('gzdecode')) {
        $decoded = @gzdecode($body);
        if ($decoded !== false) $body = $decoded;
    }
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save the downloaded guide.');
    }
    file_put_contents($metaPath, json_encode(array('url' => $url, 'fetchedAt' => gmdate('c'), 'bytes' => strlen($body))));
    return array('path' => $path, 'cached' => false, 'url' => $url);
}

function epg_index_xmltv($path, $wanted, $fromMs, $toMs) {
    $programs=[];if(!class_exists('XMLReader'))return $programs;
    $reader=new XMLReader();if(!@$reader->open($path,null,LIBXML_NONET|LIBXML_COMPACT))return $programs;
    $bases=[];foreach($wanted as $id=>$_)$bases[explode('@',(string)$id,2)[0]]=true;
    $available=$reader->read();
    while($available){
        if($reader->nodeType!==XMLReader::ELEMENT||$reader->localName!=='programme'){$available=$reader->read();continue;}
        $channel=(string)$reader->getAttribute('channel');$base=explode('@',$channel,2)[0];
        if(isset($wanted[$channel])||isset($wanted[$base])||isset($bases[$base])){
            $start=epg_parse_xmltv_time($reader->getAttribute('start'));$stop=epg_parse_xmltv_time($reader->getAttribute('stop'));
            if($start!==null){$stop=$stop ?? $start+3600000;
                if($stop>=$fromMs&&$start<=$toMs){$title='Programme';$description='';$metadata=[];$node=$reader->readOuterXML();
                    if($node&&function_exists('simplexml_load_string')){$xml=@simplexml_load_string($node,'SimpleXMLElement',LIBXML_NONET);if($xml){$title=StalkerProgramme::text((string)($xml->title ?? ''),180) ?: 'Programme';$description=StalkerProgramme::text((string)($xml->desc ?? ''),5000);$metadata=StalkerProgramme::xml($xml);}}
                    $row=array_merge($metadata,['startUnix'=>$start,'endUnix'=>$stop,'title'=>$title,'subtitle'=>$description,'kind'=>'epg']);$programs[$channel][]=$row;if($base!==$channel)$programs[$base][]=$row;
                }
            }
        }
        // next() already positions on the following programme: do not read() past it.
        $available=$reader->next('programme');
    }
    $reader->close();return $programs;
}

/**
 * Build Stalker-only fallback indexes from the UK and US Open EPG guides.
 * Streaming reads and a short window keep the generated index small on a NAS.
 * Never guess regional variants and never replace an existing index on failure.
 */
function epg_build_stalker_open_index(string $path,string $country,int $fromMs,int $toMs,string $source='open-epg'): array {
    if(!in_array($country,['GB','US'],true)||!class_exists('XMLReader'))throw new RuntimeException('XMLTV parser unavailable.');
    $reader=new XMLReader();
    if(!@$reader->open($path,null,LIBXML_NONET|LIBXML_COMPACT))throw new RuntimeException('Cannot open downloaded XMLTV guide.');
    $wanted=[];$names=[];$logos=[];$channelCount=0;
    while($reader->read()){
        if($reader->nodeType!==XMLReader::ELEMENT)continue;
        if($reader->localName==='programme')break;
        if($reader->localName!=='channel')continue;
        $id=trim((string)$reader->getAttribute('id'));
        if($id===''||strlen($id)>160||isset($wanted[$id]))continue;
        if(++$channelCount>1000)break;
        $wanted[$id]=true;
        $xml=$reader->readOuterXML();
        if($xml&&function_exists('simplexml_load_string')){
            $node=@simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET);
            if($node){
                $label=trim((string)($node->{'display-name'}??''));
                if($label!=='')$names[$id]=mb_substr($label,0,160);
                // Only expose public HTTPS artwork, never private portal links,
                // credentials, query tokens or arbitrary local file paths.
                $icon=$node->icon ?? null;
                $url=$icon ? trim((string)($icon['src']??'')) : '';
                if(strlen($url)<=500&&preg_match('~^https://[a-z0-9.-]+(?::443)?/[a-z0-9_./%+@-]+$~iD',$url)
                    && !preg_match('~(?:^https://(?:localhost|127\\.|10\\.|192\\.168\\.|172\\.(?:1[6-9]|2[0-9]|3[01])\\.|169\\.254\\.|[^/]*\\.local/))~i',$url))
                    $logos[$id]=$url;
            }
        }
    }
    $reader->close();
    if(!$wanted)throw new RuntimeException('No channel IDs found in the XMLTV guide.');
    $programmes=epg_index_xmltv($path,$wanted,$fromMs,$toMs);
    $slim=[];$total=0;
    foreach($wanted as $id=>$_){
        if(!isset($programmes[$id]))continue;
        $entries=[];
        foreach($programmes[$id] as $event){
            if($event['endUnix']<=$event['startUnix'])continue;
            $entries[]=array_merge(array_intersect_key($event,array_flip(['cast','director','presenter','categories','rating','ratingSystem','episode','language','subtitles','poster'])),['start'=>(int)floor($event['startUnix']/1000),
                'end'=>(int)floor($event['endUnix']/1000),
                'title'=>mb_substr((string)$event['title'],0,180),
                'description'=>mb_substr((string)($event['subtitle']??''),0,5000),'source'=>$source]);
            if(count($entries)>=60)break;
        }
        if($entries){$slim[$id]=$entries;$total+=count($entries);}
    }
    if(!$slim)throw new RuntimeException('No programmes found in the current 48-hour guide window.');
    $payload=json_encode(['schema'=>2,'updatedAt'=>time(),'channels'=>$names,'logos'=>$logos,'programmes'=>$slim],
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($payload===false)throw new RuntimeException('Could not encode the Stalker guide index.');
    // Rich credits/posters must not make an otherwise usable guide disappear.
    // Keep the nearest programmes for every channel within the existing NAS budget.
    for($limit=30;strlen($payload)>16777216&&$limit>=6;$limit=(int)floor($limit/2)){
        $total=0;
        foreach($slim as &$entries){usort($entries,static function($a,$b){return $a['start']<=>$b['start'];});$entries=array_slice($entries,0,$limit);$total+=count($entries);}
        unset($entries);
        $payload=json_encode(['schema'=>2,'updatedAt'=>time(),'channels'=>$names,'logos'=>$logos,'programmes'=>$slim],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($payload===false)throw new RuntimeException('Could not encode the Stalker guide index.');
    }
    if(strlen($payload)>16777216)throw new RuntimeException('Stalker guide index exceeds the 16 MiB cache limit.');
    $pathOut=epg_cache_dir().'/stalker-open-'.strtolower($country).($source==='freeview'?'-freeview':'').'.json';
    $tmp=$pathOut.'.'.bin2hex(random_bytes(4)).'.tmp';
    if(@file_put_contents($tmp,$payload,LOCK_EX)===false || !@rename($tmp,$pathOut)){
        @unlink($tmp);
        throw new RuntimeException('Could not save the Stalker guide index.');
    }
    return ['country'=>$country,'channelsInXmltv'=>count($wanted),
        'channelsWithProgrammes'=>count($slim),'programmes'=>$total,'updatedAt'=>time()];
}

function epg_pick_for_channel($programsById, $ch) {
    if (!empty($ch['epgId'])) return $programsById[$ch['epgId']] ?? [];
    $aliases = epg_channel_aliases(isset($ch['tvgId']) ? $ch['tvgId'] : '', $ch['id']);
    $best = array();
    foreach ($aliases as $a) {
        if (!empty($programsById[$a]) && count($programsById[$a]) > count($best)) {
            $best = $programsById[$a];
        }
    }
    return $best;
}

function epg_relay_programs($station, $channelId, $from, $to) {
    $blocks = relay_guide_blocks(relay_materialize($station, $channelId, $from, $to));
    $byId = relay_media_map($station);
    $out = array();
    foreach ($blocks as $b) {
        $media = null;
        if (!empty($b['mediaId']) && isset($byId[$b['mediaId']])) $media = $byId[$b['mediaId']];
        $meta = relay_enrich_block_fields($b, $media);
        $out[] = array(
            'startUnix' => $b['startUnix'],
            'endUnix' => $b['endUnix'],
            'title' => $b['title'],
            'subtitle' => isset($b['subtitle']) ? $b['subtitle'] : '',
            'kind' => $b['kind'],
            'mediaId' => isset($b['mediaId']) ? $b['mediaId'] : null,
            'showTitle' => $meta['showTitle'],
            'episodeTitle' => $meta['episodeTitle'],
            'season' => $meta['season'],
            'episode' => $meta['episode'],
            'catalogueId' => $meta['catalogueId'],
            'advert' => !empty($b['advert']),
            'filler' => !empty($b['filler']),
            'slotTitle' => isset($b['slotTitle']) ? $b['slotTitle'] : null,
        );
    }
    return $out;
}

function epg_live_placeholder($name, $from, $to) {
    return array(array(
        'startUnix' => $from,
        'endUnix' => $to,
        'title' => 'Live · ' . $name,
        'subtitle' => 'No XMLTV match — stream is live. Add an EPG URL in Settings → IPTV.',
        'kind' => 'live',
    ));
}

function epg_refresh($force = false) {
    $lock = @fopen(epg_cache_dir() . '/refresh.lock', 'c');
    if (!$lock) throw new RuntimeException('Could not open the guide refresh lock.');
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return ['ok'=>true,'busy'=>true];
        return epg_refresh_locked($force);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function epg_refresh_locked($force) {
    $iptv = iptv_load();
    // Stalker Open EPG sources are managed automatically. Preserve any user IPTV guides.
    $openSources=['GB'=>'https://www.open-epg.com/files/unitedkingdom.xml.gz',
        'GB-freeview'=>'https://raw.githubusercontent.com/dp247/Freeview-EPG/master/epg.xml',
        'US'=>'https://www.open-epg.com/files/unitedstates1.xml.gz'];
    $hasStalker=false;
    foreach($iptv['feeds'] as $feed) if(($feed['type']??'')==='stalker'&&!empty($feed['enabled'])){$hasStalker=true;break;}
    if(!$hasStalker)$openSources=[];
    $urls = array_values(array_unique(array_merge($iptv['epgUrls'],array_values($openSources))));
    if ($urls && !class_exists('XMLReader')) throw new RuntimeException('PHP XMLReader is required to import XMLTV programme guides.');
    $wanted = epg_wanted_ids($iptv);
    $fetched = array();
    $errors = array();
    $maxAge = $force ? 0 : 21600;
    foreach ($urls as $url) {
        try {
            $fetched[] = epg_fetch_xml($url, $maxAge);
        } catch (Exception $e) {
            $errors[] = array('url' => $url, 'error' => $e->getMessage());
        }
    }
    // A provider outage must not replace a usable previous guide with an empty one.
    if ($urls && !$fetched) return ['ok'=>false,'error'=>'No programme guide source could be downloaded.','errors'=>$errors];
    // Persist a slim index for favourites
    $now = (int) round(microtime(true) * 1000);
    $from = $now - 3600000;
    $to = $now + 48 * 3600000;
    $programsById = array();
    foreach ($fetched as $f) {
        // Open EPG is indexed separately for on-demand Stalker channels, not IPTV favourites.
        if(in_array((string)($f['url']??''),$openSources,true))continue;
        $chunk = epg_index_xmltv($f['path'], $wanted, $from, $to);
        foreach ($chunk as $cid => $list) {
            if (!isset($programsById[$cid])) $programsById[$cid] = array();
            $programsById[$cid] = array_merge($programsById[$cid], $list);
        }
    }
    $slim = array();
    $portalPrograms = [];
    $failedPortalFeeds = [];
    foreach ($iptv['feeds'] as $feed) {
        if (($feed['type'] ?? '') !== 'stalker' || empty($feed['enabled'])) continue;
        $selected = false;
        foreach ($iptv['channels'] as $channel) if (($channel['sourceFeedId'] ?? '') === $feed['id']) { $selected = true; break; }
        if (!$selected) continue;
        try { $portalPrograms = array_merge($portalPrograms, stalker_guide($feed)); }
        catch (RuntimeException $e) { $errors[] = ['url'=>$feed['url'],'error'=>$e->getMessage()];$failedPortalFeeds[$feed['id']]=true; }
    }
    $previousPortalGuide = $failedPortalFeeds ? epg_load_iptv_index() : [];
    foreach ($iptv['channels'] as $ch) {
        $list = epg_pick_for_channel($programsById, $ch);
        if (empty($ch['epgId']) && isset($portalPrograms[$ch['id']])) $list = $portalPrograms[$ch['id']];
        if (!$list && isset($failedPortalFeeds[$ch['sourceFeedId'] ?? ''])) $list = $previousPortalGuide['programs'][$ch['id']] ?? [];
        // dedupe by start
        $seen = array();
        $deduped = array();
        foreach ($list as $p) {
            $k = $p['startUnix'] . ':' . $p['title'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $deduped[] = $p;
        }
        usort($deduped, function ($a, $b) { return $a['startUnix'] - $b['startUnix']; });
        $slim[$ch['id']] = $deduped;
        if (!empty($ch['tvgId'])) $slim[$ch['tvgId']] = $deduped;
    }
    epg_build_channel_catalogue();
    // Re-index only as part of the normal guide refresh, not Stalker browsing.
    $stalkerOpenEpg=[];
    foreach($openSources as $country=>$url)$stalkerOpenEpg[$country]=['status'=>'download_failed','channelsWithProgrammes'=>0];
    foreach($fetched as $source){
        $url=(string)($source['url']??'');
        $country=array_search($url,$openSources,true);
        if($country===false)continue;
        try{
            $stalkerOpenEpg[$country]=array_merge(['status'=>'indexed'],
                epg_build_stalker_open_index($source['path'],substr($country,0,2),$from,$to,$country==='GB-freeview'?'freeview':'open-epg'));
        } catch(Throwable $error){
            $stalkerOpenEpg[$country]=['status'=>'index_failed','error'=>$error->getMessage(),
                'retainedPreviousCache'=>is_file(epg_cache_dir().'/stalker-open-'.strtolower($country).'.json')];
            error_log('Open EPG Stalker fallback index: '.$error->getMessage());
        }
    }
    $indexPath = epg_cache_dir() . '/favourites.json';
    $tempPath=$indexPath.'.'.bin2hex(random_bytes(4));
    $encoded = json_encode(array(
        'updatedAt' => gmdate('c'),
        'urls' => $urls,
        'programs' => $slim,
        'errors' => $errors,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || file_put_contents($tempPath, $encoded, LOCK_EX) === false || !@rename($tempPath, $indexPath)) {
        @unlink($tempPath);
        throw new RuntimeException('Could not save the refreshed guide.');
    }
    \Vaultcast\Repository::putDocument('live_epg_revision',['at'=>microtime(true)]);
    return array(
        'ok' => true,
        'fetched' => count($fetched),
        'errors' => $errors,
        'channelsWithEpg' => count(array_filter($slim, function ($v) { return !empty($v); })),
        'favourites' => count($iptv['channels']),
        'stalkerOpenEpg' => $stalkerOpenEpg,
        'note' => 'Open EPG channelsWithProgrammes counts XMLTV channel IDs, not Stalker matches. Stalker matches are reported by the live guide page.',
    );
}

function epg_load_iptv_index() {
    $path = epg_cache_dir() . '/favourites.json';
    if (!is_file($path)) return array('programs' => array(), 'updatedAt' => null, 'errors' => array());
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) return array('programs' => array(), 'updatedAt' => null, 'errors' => array());
    if (!isset($data['programs'])) $data['programs'] = array();
    return $data;
}

function epg_unified_guide($station, $hours = 12, $slim = false, $viewerOverride = null) {
    $hours = max(1, min(48, (int)$hours));
    $now = (int) round(microtime(true) * 1000);
    $from = $now - 15 * 60000;
    $to = $now + $hours * 3600000;
    $iptv = iptv_load();
    $index = epg_load_iptv_index();
    // Auto-refresh if stale and urls configured
    if (!empty($iptv['epgUrls'])) {
        $stale = empty($index['updatedAt']) || (time() - strtotime($index['updatedAt'])) > 21600;
        if ($stale) {
            LiveGuideCache::queueEpg(); // The viewer reads the previous index; network work runs in CLI.
        }
    }

    $channels = array();
    require_once __DIR__ . '/tvstudio.php';
    $viewer = func_num_args() >= 4 ? $viewerOverride : (class_exists('Auth') ? Auth::currentSession() : null);
    $studio = tvs_load();
    foreach ($station['channels'] as $ch) {
        if (empty($ch['enabled'])) continue;
        if (!tvs_channel_visible($ch['id'], $viewer)) continue;
        $programs = epg_relay_programs($station, $ch['id'], $from, $to);
        $nowProg = null;
        $nextProg = null;
        foreach ($programs as $p) {
            if ($p['startUnix'] <= $now && $p['endUnix'] > $now) $nowProg = $p;
            elseif ($p['startUnix'] > $now && !$nextProg) $nextProg = $p;
        }
        $channels[] = array(
            'id' => $ch['id'],
            'kind' => 'relay',
            'section' => 'Relay',
            'number' => (int)$ch['number'],
            'name' => $ch['name'],
            'slogan' => isset($ch['slogan']) ? $ch['slogan'] : '',
            'logo' => !empty($studio['channels'][$ch['id']]['logo']) ? $studio['channels'][$ch['id']]['logo'] : null,
            'watchUrl' => \Vaultcast\Database::basePath() . '#/live/' . rawurlencode($ch['id']),
            'now' => $nowProg,
            'next' => $nextProg,
            'programs' => $programs,
        );
    }

    foreach (iptv_lineup_channels($iptv) as $ch) {
        $programs = array();
        if (!empty($index['programs'][$ch['id']])) {
            $programs = $index['programs'][$ch['id']];
        } elseif (!empty($ch['tvgId']) && !empty($index['programs'][$ch['tvgId']])) {
            $programs = $index['programs'][$ch['tvgId']];
        }
        // filter window
        $programs = array_values(array_filter($programs, function ($p) use ($from, $to) {
            return $p['endUnix'] >= $from && $p['startUnix'] <= $to;
        }));
        if (!$programs) {
            $programs = epg_live_placeholder($ch['name'], $now - 60000, $to);
        }
        $nowProg = null;
        $nextProg = null;
        foreach ($programs as $p) {
            if ($p['startUnix'] <= $now && $p['endUnix'] > $now) $nowProg = $p;
            elseif ($p['startUnix'] > $now && !$nextProg) $nextProg = $p;
        }
        $channels[] = array(
            'id' => 'iptv:' . $ch['id'],
            'iptvId' => $ch['id'],
            'tvgId' => $ch['tvgId'],
            'kind' => 'iptv',
            'section' => 'IPTV',
            'number' => (int)$ch['number'],
            'name' => $ch['name'],
            'slogan' => $ch['group'],
            'logo' => $ch['logo'],
            'watchUrl' => \Vaultcast\Database::basePath() . '#/live/iptv:' . rawurlencode($ch['id']),
            'now' => $nowProg,
            'next' => $nextProg,
            'programs' => $programs,
        );
    }

    /* Watch Live / guide — channel number ascending (Relay + numbered IPTV). */
    usort($channels, function ($a, $b) {
        $an = isset($a['number']) ? (int)$a['number'] : 0;
        $bn = isset($b['number']) ? (int)$b['number'] : 0;
        if ($an === 0 && $bn !== 0) return 1;
        if ($bn === 0 && $an !== 0) return -1;
        if ($an !== $bn) return $an - $bn;
        $ak = isset($a['kind']) ? $a['kind'] : '';
        $bk = isset($b['kind']) ? $b['kind'] : '';
        if ($ak !== $bk) return strcmp($ak, $bk);
        return strcmp(isset($a['id']) ? $a['id'] : '', isset($b['id']) ? $b['id'] : '');
    });

    if ($slim) {
        foreach ($channels as &$ch) {
            unset($ch['programs']);
        }
        unset($ch);
    }
    return array(
        'ok' => true,
        'generatedAt' => $now,
        'hours' => $hours,
        'slim' => $slim ? true : false,
        'historyDays' => isset($station['historyDays']) ? max(1, min(14, (int)$station['historyDays'])) : 7,
        'epgUpdatedAt' => isset($index['updatedAt']) ? $index['updatedAt'] : null,
        'epgErrors' => isset($index['errors']) ? $index['errors'] : array(),
        'channels' => $channels,
    );
}

function epg_build_channel_catalogue() {
    if (!class_exists('XMLReader')) throw new RuntimeException('PHP XMLReader is required to read XMLTV channels.');
    $channels=[];
    foreach (glob(epg_cache_dir().'/*.xml') ?: [] as $path) {
        $reader=new XMLReader();if(!@$reader->open($path,null,LIBXML_NONET|LIBXML_COMPACT))continue;
        while($reader->read()) {
            if($reader->nodeType!==XMLReader::ELEMENT)continue;
            if($reader->localName==='programme')break;
            if($reader->localName!=='channel')continue;
            $id=(string)$reader->getAttribute('id');if($id===''||isset($channels[$id]))continue;
            $name=$id;$node=$reader->readOuterXML();
            if(function_exists('simplexml_load_string')){$xml=@simplexml_load_string($node,'SimpleXMLElement',LIBXML_NONET);if($xml&&isset($xml->{'display-name'}))$name=(string)$xml->{'display-name'};}
            $channels[$id]=['id'=>$id,'name'=>$name];if(count($channels)>=100000)break;
        }
        $reader->close();
    }
    $target=epg_cache_dir().'/channels.json';$tmp=$target.'.'.bin2hex(random_bytes(4));
    file_put_contents($tmp,json_encode(array_values($channels),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);rename($tmp,$target);
    return array_values($channels);
}
function epg_search_channels($query) {
    $path=epg_cache_dir().'/channels.json';
    $channels=is_file($path)?json_decode(file_get_contents($path),true):epg_build_channel_catalogue();
    $query=mb_strtolower(trim(mb_substr($query,0,120)));$matches=[];
    foreach($channels ?: [] as $row)if($query===''||mb_strpos(mb_strtolower($row['id'].' '.$row['name']),$query)!==false){$matches[]=$row;if(count($matches)>=60)break;}
    return ['ok'=>true,'channels'=>$matches,'total'=>count($channels ?: [])];
}
function epg_map_channel(array $body) {
    $id=(string)($body['id'] ?? '');$epgId=trim((string)($body['epg_id'] ?? ''));if(strlen($epgId)>240)throw new InvalidArgumentException('Invalid XMLTV ID.');
    $state=iptv_load();$found=false;$selected=null;
    foreach($state['channels'] as &$channel)if($channel['id']===$id){$channel['epgId']=$epgId;$found=true;$selected=$channel;}
    unset($channel);if(!$found)throw new InvalidArgumentException('Channel is no longer in your IPTV lineup.');
    iptv_save($state);
    $now=(int)round(microtime(true)*1000);$programs=[];$wanted=epg_wanted_ids(['channels'=>[$selected]]);
    foreach(glob(epg_cache_dir().'/*.xml') ?: [] as $path){$chunk=epg_index_xmltv($path,$wanted,$now-3600000,$now+48*3600000);foreach($chunk as $key=>$rows)$programs[$key]=array_merge($programs[$key] ?? [],$rows);}
    $rows=epg_pick_for_channel($programs,$selected);$unique=[];foreach($rows as $row)$unique[$row['startUnix'].':'.$row['title']]=$row;$rows=array_values($unique);usort($rows,function($a,$b){return $a['startUnix']<=>$b['startUnix'];});
    $index=epg_load_iptv_index();$index['programs'][$id]=$rows;$index['updatedAt']=gmdate('c');
    $path=epg_cache_dir().'/favourites.json';$tmp=$path.'.'.bin2hex(random_bytes(4));file_put_contents($tmp,json_encode($index),LOCK_EX);rename($tmp,$path);
    \Vaultcast\Repository::putDocument('live_epg_revision',['at'=>microtime(true)]);
    return ['ok'=>true,'programmes'=>count($rows),'epg_id'=>$epgId];
}
