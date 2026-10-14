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
        foreach (epg_channel_aliases(isset($ch['tvgId']) ? $ch['tvgId'] : '', $ch['id']) as $a) {
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
    file_put_contents($path, $body);
    file_put_contents($metaPath, json_encode(array('url' => $url, 'fetchedAt' => gmdate('c'), 'bytes' => strlen($body))));
    return array('path' => $path, 'cached' => false, 'url' => $url);
}

function epg_index_xmltv($path, $wanted, $fromMs, $toMs) {
    $programs = array(); // channelId => list
    if (!class_exists('XMLReader')) {
        return $programs;
    }
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
        return $programs;
    }
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'programme') {
            continue;
        }
        $channel = $reader->getAttribute('channel');
        if ($channel === null || $channel === '') continue;
        $match = null;
        if (isset($wanted[$channel])) {
            $match = $channel;
        } else {
            $base = strpos($channel, '@') !== false ? explode('@', $channel, 2)[0] : $channel;
            if (isset($wanted[$base])) $match = $base;
            else {
                // try matching wanted aliases against this channel
                foreach ($wanted as $w => $_) {
                    if ($w === $channel || (strpos($channel, $w . '@') === 0) || (strpos($w, $channel . '@') === 0)) {
                        $match = $w;
                        break;
                    }
                }
            }
        }
        if ($match === null) {
            // skip subtree quickly
            $reader->next('programme');
            continue;
        }
        $start = epg_parse_xmltv_time($reader->getAttribute('start'));
        $stop = epg_parse_xmltv_time($reader->getAttribute('stop'));
        if ($start === null) {
            $reader->next('programme');
            continue;
        }
        if ($stop === null) $stop = $start + 3600000;
        if ($stop < $fromMs || $start > $toMs) {
            $reader->next('programme');
            continue;
        }
        $node = $reader->readOuterXML();
        $title = '';
        $desc = '';
        if ($node && function_exists('simplexml_load_string')) {
            $xml = @simplexml_load_string($node);
            if ($xml) {
                if (isset($xml->title)) $title = trim((string)$xml->title);
                if (isset($xml->desc)) $desc = trim((string)$xml->desc);
            }
        }
        if ($title === '') $title = 'Programme';
        if (!isset($programs[$channel])) $programs[$channel] = array();
        // also index under base id
        $programs[$channel][] = array(
            'startUnix' => $start,
            'endUnix' => $stop,
            'title' => $title,
            'subtitle' => $desc,
            'kind' => 'epg',
        );
        $base = strpos($channel, '@') !== false ? explode('@', $channel, 2)[0] : $channel;
        if ($base !== $channel) {
            if (!isset($programs[$base])) $programs[$base] = array();
            $programs[$base][] = end($programs[$channel]);
        }
    }
    $reader->close();
    return $programs;
}

function epg_pick_for_channel($programsById, $ch) {
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
    $blocks = relay_materialize($station, $channelId, $from, $to);
    $byId = relay_media_map($station);
    $out = array();
    foreach ($blocks as $b) {
        $media = null;
        if (!empty($b['mediaId']) && isset($byId[$b['mediaId']])) $media = $byId[$b['mediaId']];
        $meta = relay_enrich_block_fields($b, $media);
        // Collapse continuous commercials into one guide row optionally? keep titles.
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
    $iptv = iptv_load();
    $urls = $iptv['epgUrls'];
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
    // Persist a slim index for favourites
    $now = (int) round(microtime(true) * 1000);
    $from = $now - 3600000;
    $to = $now + 48 * 3600000;
    $programsById = array();
    foreach ($fetched as $f) {
        $chunk = epg_index_xmltv($f['path'], $wanted, $from, $to);
        foreach ($chunk as $cid => $list) {
            if (!isset($programsById[$cid])) $programsById[$cid] = array();
            $programsById[$cid] = array_merge($programsById[$cid], $list);
        }
    }
    $slim = array();
    foreach ($iptv['channels'] as $ch) {
        $list = epg_pick_for_channel($programsById, $ch);
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
    $indexPath = epg_cache_dir() . '/favourites.json';
    file_put_contents($indexPath, json_encode(array(
        'updatedAt' => gmdate('c'),
        'urls' => $urls,
        'programs' => $slim,
        'errors' => $errors,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return array(
        'ok' => true,
        'fetched' => count($fetched),
        'errors' => $errors,
        'channelsWithEpg' => count(array_filter($slim, function ($v) { return !empty($v); })),
        'favourites' => count($iptv['channels']),
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

function epg_unified_guide($station, $hours = 12, $slim = false) {
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
            try { epg_refresh(false); $index = epg_load_iptv_index(); } catch (Exception $e) { /* keep going */ }
        }
    }

    $channels = array();
    require_once __DIR__ . '/tvstudio.php';
    $viewer = class_exists('Auth') ? Auth::currentSession() : null;
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
