<?php
function relay_hash($s) {
    $h = 2166136261;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $h ^= ord($s[$i]);
        $h = ($h * 16777619) & 0xFFFFFFFF;
    }
    return $h;
}

function relay_mulberry32($seed) {
    $a = $seed & 0xFFFFFFFF;
    return function () use (&$a) {
        $a = ($a + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = (($a ^ ($a >> 15)) * (1 | $a)) & 0xFFFFFFFF;
        $t = ($t + ((($t ^ ($t >> 7)) * (61 | $t)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $t = ($t ^ ($t >> 14)) & 0xFFFFFFFF;
        return $t / 4294967296;
    };
}

function relay_mod($n, $m) {
    if ($m <= 0) return 0;
    $r = fmod($n, $m);
    if ($r < 0) $r += $m;
    return $r;
}

function relay_try_boot_catalogue() {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    $boot = dirname(__DIR__) . '/bootstrap.php';
    if (!is_file($boot)) return false;
    require_once $boot;
    $ok = class_exists('Catalogue');
    return $ok;
}

/** Strip codec/source/release-group junk from an episode name (Live guide). */
function relay_strip_release_tags($s) {
    $s = (string)$s;
    if ($s === '') return '';
    if (relay_try_boot_catalogue() && method_exists('Catalogue', 'stripReleaseTags')) {
        return Catalogue::stripReleaseTags($s);
    }
    $s = preg_replace('/\[.*?\]/', ' ', $s);
    $s = preg_replace('/\{.*?\}/', ' ', $s);
    $s = preg_replace('/\((?:\d{3,4}p|WEB|BluRay|HDTV|UHD|HDR|DSNP|AMZN|NF|Hulu|HMAX|DDP?|AAC|x264|x265|H\.?26[45]|HEVC)[^)]*\)/i', ' ', $s);
    $tags = '1080p|720p|2160p|480p|4k|uhd|hdr10|hdr|bluray|blu-ray|bdrip|brrip|webrip|web-dl|webdl|hdtv|hdrip|x264|x265|h264|h265|hevc|avc|aac|dts|truehd|atmos|eac3|ac3|10bit|8bit|amzn|nf|hulu|dsnp|hmax|web|ita|eng|mem\.?gp|bone';
    $s = preg_replace('/\b(?:' . $tags . ')\b/i', ' ', $s);
    $s = preg_replace('/\s*[-–]\s*[A-Za-z0-9][A-Za-z0-9._]{1,30}\s*$/', '', $s);
    return trim(preg_replace('/[\s._-]+/', ' ', (string)$s));
}

/** Episode name only: episode_title → cleaned title → catalogue resolve. */
function relay_episode_name_only($item) {
    $series = trim((string)(isset($item['series']) ? $item['series'] : ''));
    $candidates = array();
    if (!empty($item['episode_title'])) $candidates[] = (string)$item['episode_title'];
    if (!empty($item['title'])) $candidates[] = (string)$item['title'];
    if (!empty($item['subtitle'])) $candidates[] = (string)$item['subtitle'];

    // Catalogue / NFO when linked
    if (!empty($item['catalogueId']) && relay_try_boot_catalogue()) {
        $cat = Catalogue::get();
        foreach (($cat['items'] ?? array()) as $it) {
            if (!is_array($it)) continue;
            if ((string)($it['id'] ?? '') !== (string)$item['catalogueId']) continue;
            $resolved = Catalogue::resolveEpisodeTitle($it);
            if ($resolved !== '') array_unshift($candidates, $resolved);
            break;
        }
    }

    foreach ($candidates as $raw) {
        $name = trim((string)$raw);
        if ($name === '') continue;
        if ($series !== '' && stripos($name, $series) === 0) {
            $name = trim(substr($name, strlen($series)), " \t-—–.|");
        }
        $name = preg_replace('/^[Ss]\d{1,2}[Ee]\d{1,3}\s*[-—–|]?\s*/u', '', $name);
        $name = preg_replace('/^\d{1,2}\s*[x×]\s*\d{1,3}\s*[-—–|]?\s*/u', '', $name);
        $name = relay_strip_release_tags($name);
        if ($name === '' || ($series !== '' && strcasecmp($name, $series) === 0)) continue;
        if (preg_match('/\b(?:1080p|720p|WEB-?DL|x264|x265|H264|H265)\b/i', $name)) continue;
        return $name;
    }
    return '';
}


/** Structured guide fields from a station media item (no NFO I/O). */
function relay_prog_fields($item) {
    $fields = array(
        'showTitle' => null,
        'episodeTitle' => null,
        'season' => null,
        'episode' => null,
        'catalogueId' => null,
    );
    if (!is_array($item)) return $fields;
    if (!empty($item['catalogueId'])) $fields['catalogueId'] = (string)$item['catalogueId'];
    $kind = isset($item['kind']) ? $item['kind'] : '';
    if ($kind === 'episode') {
        $series = trim((string)(isset($item['series']) ? $item['series'] : ''));
        if ($series !== '') $fields['showTitle'] = $series;
        $epName = '';
        if (!empty($item['episode_title'])) $epName = trim((string)$item['episode_title']);
        elseif (!empty($item['title'])) $epName = trim((string)$item['title']);
        if ($epName !== '' && $epName !== $series) $fields['episodeTitle'] = $epName;
        if (isset($item['season']) && $item['season'] !== '' && $item['season'] !== null) {
            $fields['season'] = (int)$item['season'];
        }
        if (isset($item['episode']) && $item['episode'] !== '' && $item['episode'] !== null) {
            $fields['episode'] = (int)$item['episode'];
        }
    } elseif ($kind === 'movie') {
        $title = trim((string)(isset($item['title']) ? $item['title'] : ''));
        if ($title !== '') $fields['showTitle'] = $title;
        if (!empty($item['year'])) $fields['episodeTitle'] = (string)$item['year'];
    } else {
        $title = trim((string)(isset($item['title']) ? $item['title'] : ''));
        if ($title !== '') $fields['showTitle'] = $title;
    }
    return $fields;
}

/** Parse "Show · SxxExx · Episode" / "Show · Episode" from a combined guide title. */
function relay_parse_guide_title($title) {
    $title = trim((string)$title);
    $out = array('showTitle' => null, 'episodeTitle' => null, 'season' => null, 'episode' => null);
    if ($title === '') return $out;
    if (preg_match('/^(.+?)\s*[·•]\s*S(\d{1,2})E(\d{1,3})\s*[·•]\s*(.+)$/u', $title, $m)) {
        $out['showTitle'] = trim($m[1]);
        $out['season'] = (int)$m[2];
        $out['episode'] = (int)$m[3];
        $out['episodeTitle'] = trim($m[4]);
        return $out;
    }
    if (preg_match('/^(.+?)\s*[·•]\s*S(\d{1,2})E(\d{1,3})\s*$/u', $title, $m)) {
        $out['showTitle'] = trim($m[1]);
        $out['season'] = (int)$m[2];
        $out['episode'] = (int)$m[3];
        return $out;
    }
    if (preg_match('/^(.+?)\s*[·•]\s*(.+)$/u', $title, $m)) {
        $out['showTitle'] = trim($m[1]);
        $out['episodeTitle'] = trim($m[2]);
        return $out;
    }
    $out['showTitle'] = $title;
    return $out;
}

/** Merge media fields + optional title parse for a cycle/guide block. */
function relay_enrich_block_fields($block, $mediaItem = null) {
    $fields = relay_prog_fields(is_array($mediaItem) ? $mediaItem : array());
    // Prefer already-baked structured fields on the block
    foreach (array('showTitle', 'episodeTitle', 'season', 'episode', 'catalogueId') as $k) {
        if (isset($block[$k]) && $block[$k] !== null && $block[$k] !== '') {
            $fields[$k] = $block[$k];
        }
    }
    if ($fields['showTitle'] === null || ($fields['season'] === null && $fields['episodeTitle'] === null)) {
        $parsed = relay_parse_guide_title(isset($block['title']) ? $block['title'] : '');
        foreach ($parsed as $k => $v) {
            if ($v !== null && ($fields[$k] === null || $fields[$k] === '')) $fields[$k] = $v;
        }
    }
    return $fields;
}

function relay_guide_title($item) {
    if (($item['kind'] ?? '') === 'episode' && !empty($item['series'])) {
        $ep = '';
        if (isset($item['season']) && isset($item['episode']) && $item['season'] !== null && $item['episode'] !== null
            && $item['season'] !== '' && $item['episode'] !== '') {
            $ep = sprintf('S%02dE%02d', (int)$item['season'], (int)$item['episode']);
        }
        $name = relay_episode_name_only($item);
        $series = trim((string)$item['series']);
        if ($name !== '') {
            return $ep !== '' ? ($series . ' · ' . $ep . ' · ' . $name) : ($series . ' · ' . $name);
        }
        return $ep !== '' ? ($series . ' · ' . $ep) : $series;
    }
    if (($item['kind'] ?? '') === 'movie' && !empty($item['year'])) {
        return $item['title'] . ' (' . $item['year'] . ')';
    }
    return isset($item['title']) ? $item['title'] : '';
}

/** Persist cleaned episode_title/title on station media before cycles bake. */
function relay_enrich_station_episodes($station) {
    if (empty($station['media']) || !is_array($station['media'])) return $station;
    foreach ($station['media'] as &$item) {
        if (!is_array($item) || ($item['kind'] ?? '') !== 'episode') continue;
        $name = relay_episode_name_only($item);
        if ($name === '') continue;
        $item['episode_title'] = $name;
        $item['title'] = $name;
    }
    unset($item);
    return $station;
}

function relay_resolve_url($url, $base) {
    if (preg_match('#^https?://#i', $url) || strpos($url, 'blob:') === 0) return $url;
    $base = rtrim($base, '/');
    $path = $url[0] === '/' ? $url : '/' . $url;
    return $base ? $base . $path : $path;
}

function relay_media_map($station) {
    $map = array();
    foreach ($station['media'] as $item) {
        $map[$item['id']] = $item;
    }
    return $map;
}

function relay_build_cycle($station, $channel) {
    $byId = relay_media_map($station);
    $rand = relay_mulberry32(relay_hash($channel['seed'] . ':' . $channel['id'] . ':' . implode(',', $channel['playlist'])));
    $fillTarget = max(1, (float)$channel['fillHours']) * 3600 * 1000;
    $recent = array();
    $blocks = array();
    $totalMs = 0;
    $sinceBreak = 0;

    $pick = function ($ids) use ($byId, $rand, &$recent) {
        $pool = array();
        foreach ($ids as $id) {
            if (isset($byId[$id])) $pool[] = $byId[$id];
        }
        if (!$pool) return null;
        // never schedule unknown-duration media while known ones exist (durationMs 0
        // used to become a 1-second block, so the channel restarted episodes on every tune)
        $known = array();
        foreach ($pool as $item) {
            if ((int)(isset($item['durationMs']) ? $item['durationMs'] : 0) > 0) $known[] = $item;
        }
        if ($known) {
            $pool = $known;
        } else {
            $prog = array();
            foreach ($pool as $item) {
                if ($item['kind'] === 'movie' || $item['kind'] === 'episode') $prog[] = $item;
            }
            if (!$prog) return null; // no 30-min estimated bumpers / ads
            $pool = $prog;
        }
        $fresh = array();
        foreach ($pool as $item) {
            if (!in_array($item['id'], $recent, true)) $fresh[] = $item;
        }
        $use = $fresh ? $fresh : $pool;
        return $use[(int)floor($rand() * count($use))];
    };

    $push = function ($item, $generated) use (&$blocks, &$totalMs, &$recent, $station) {
        // unknown duration (only when nothing better exists) = 30-min estimate, not 1 s
        $estimated = (int)(isset($item['durationMs']) ? $item['durationMs'] : 0) <= 0;
        $dur = $estimated ? 1800000 : max(1000, (int)$item['durationMs']);
        $meta = relay_prog_fields($item);
        $blocks[] = array(
            'mediaId' => $item['id'],
            'durationMs' => $dur,
            'estimated' => $estimated,
            'title' => relay_guide_title($item),
            'subtitle' => isset($item['subtitle']) ? $item['subtitle'] : '',
            'kind' => $item['kind'],
            'url' => relay_resolve_url($item['url'], isset($station['mediaBaseUrl']) ? $station['mediaBaseUrl'] : ''),
            'generated' => $generated,
            'showTitle' => $meta['showTitle'],
            'episodeTitle' => $meta['episodeTitle'],
            'season' => $meta['season'],
            'episode' => $meta['episode'],
            'catalogueId' => $meta['catalogueId'],
        );
        $totalMs += $dur;
        $recent[] = $item['id'];
        if (count($recent) > 8) array_shift($recent);
    };

    $emit = function ($item, $generated) use ($channel, $pick, $push, &$sinceBreak) {
        if (!empty($channel['bumperBefore'])) {
            $bumper = $pick($channel['libraryIds']['bumper']);
            if ($bumper) $push($bumper, true);
        }
        $push($item, $generated);
        $sinceBreak += max(0, (int)$item['durationMs']);
        if ($channel['commercialEveryMs'] > 0 && $sinceBreak >= $channel['commercialEveryMs'] && !empty($channel['libraryIds']['commercial'])) {
            $n = max(1, (int)$channel['commercialsPerBreak']);
            for ($i = 0; $i < $n; $i++) {
                $ad = $pick($channel['libraryIds']['commercial']);
                if ($ad) $push($ad, true);
            }
            $sinceBreak = 0;
        }
    };

    $playlistItems = array();
    $setDuration = 0;
    foreach ($channel['playlist'] as $id) {
        if (!isset($byId[$id])) continue;
        $item = $byId[$id];
        if ($item['kind'] === 'movie' || $item['kind'] === 'episode') {
            $playlistItems[] = $item;
        }
    }
    // skip unknown-duration playlist entries while any have a real duration
    $playlistKnown = array();
    foreach ($playlistItems as $item) {
        if ((int)(isset($item['durationMs']) ? $item['durationMs'] : 0) > 0) $playlistKnown[] = $item;
    }
    if ($playlistKnown) $playlistItems = $playlistKnown;
    foreach ($playlistItems as $item) $setDuration += max(0, (int)$item['durationMs']);
    $loopOnly = count($playlistItems) > 0 && $setDuration >= $fillTarget;
    foreach ($playlistItems as $item) $emit($item, false);

    $programPool = array_merge($channel['libraryIds']['movie'], $channel['libraryIds']['episode']);
    $cap = 24 * 3600 * 1000;
    if (!$loopOnly) {
        while ($totalMs < $fillTarget && $totalMs < $cap && count($blocks) < 4000) {
            $item = $pick($programPool);
            if (!$item) break;
            $emit($item, true);
        }
    }

    if (!$blocks) {
        $off = null;
        foreach ($station['media'] as $m) {
            if ($m['id'] === 'slate-off-air') $off = $m;
        }
        if (!$off) {
            $off = array(
                'id' => 'slate-off-air',
                'kind' => 'bumper',
                'title' => 'Off Air',
                'subtitle' => 'Please stand by',
                'durationMs' => 20000,
                'url' => 'media/off-air.mp4',
            );
        }
        $push($off, true);
    }

    return array(
        'channelId' => $channel['id'],
        'epoch' => 0,
        'totalMs' => max(1, $totalMs),
        'blocks' => $blocks,
    );
}


/** Normalize rolling schedule on a channel (24h wall-clock slots). */
function relay_normalize_rolling($channel) {
    if (!is_array($channel)) return $channel;
    if (!isset($channel['rollingSchedule']) || !is_array($channel['rollingSchedule'])) {
        $channel['rollingSchedule'] = array('enabled' => false, 'slots' => array());
        return $channel;
    }
    $rs = $channel['rollingSchedule'];
    $rs['enabled'] = !empty($rs['enabled']);
    $slots = array();
    foreach ((isset($rs['slots']) && is_array($rs['slots'])) ? $rs['slots'] : array() as $slot) {
        if (!is_array($slot)) continue;
        $type = isset($slot['type']) ? (string)$slot['type'] : 'movie_rotate';
        if ($type !== 'movie_rotate' && $type !== 'episode_daily') $type = 'movie_rotate';
        $hour = isset($slot['hour']) ? (int)$slot['hour'] : 20;
        $minute = isset($slot['minute']) ? (int)$slot['minute'] : 0;
        if ($hour < 0 || $hour > 23) $hour = 20;
        if ($minute < 0 || $minute > 59) $minute = 0;
        $mediaIds = array();
        if (!empty($slot['mediaIds']) && is_array($slot['mediaIds'])) {
            foreach ($slot['mediaIds'] as $id) {
                $id = trim((string)$id);
                if ($id !== '') $mediaIds[] = $id;
            }
        }
        $slots[] = array(
            'id' => isset($slot['id']) && $slot['id'] !== '' ? (string)$slot['id'] : ('rs-' . substr(md5($type . $hour . $minute . implode(',', $mediaIds)), 0, 8)),
            'hour' => $hour,
            'minute' => $minute,
            'type' => $type,
            'mediaIds' => $mediaIds,
            'series' => isset($slot['series']) ? trim((string)$slot['series']) : '',
            'startDate' => isset($slot['startDate']) ? (string)$slot['startDate'] : '',
        );
    }
    $rs['slots'] = $slots;
    $channel['rollingSchedule'] = $rs;
    return $channel;
}

/** Days since startDate (UTC date) for deterministic daily rotation — no NFO / cursor writes. */
function relay_rolling_day_index($unixMs, $startDate = '') {
    $day = (int) floor($unixMs / 86400000);
    if ($startDate !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $startDate, $m)) {
        $start = (int) floor(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) * 1000 / 86400000);
        return max(0, $day - $start);
    }
    return max(0, $day);
}

function relay_rolling_series_episodes($station, $channel, $series) {
    $byId = relay_media_map($station);
    $series = trim((string)$series);
    $out = array();
    $ids = isset($channel['libraryIds']['episode']) && is_array($channel['libraryIds']['episode'])
        ? $channel['libraryIds']['episode'] : array();
    foreach ($ids as $id) {
        if (!isset($byId[$id])) continue;
        $item = $byId[$id];
        if (($item['kind'] ?? '') !== 'episode') continue;
        $s = trim((string)(isset($item['series']) ? $item['series'] : ''));
        if ($series !== '' && strcasecmp($s, $series) !== 0) continue;
        $out[] = $item;
    }
    usort($out, function ($a, $b) {
        $sa = isset($a['season']) ? (int)$a['season'] : 0;
        $sb = isset($b['season']) ? (int)$b['season'] : 0;
        if ($sa !== $sb) return $sa - $sb;
        $ea = isset($a['episode']) ? (int)$a['episode'] : 0;
        $eb = isset($b['episode']) ? (int)$b['episode'] : 0;
        if ($ea !== $eb) return $ea - $eb;
        return strcmp((string)$a['id'], (string)$b['id']);
    });
    return $out;
}

function relay_rolling_pick_item($station, $channel, $slot, $unixMs) {
    $byId = relay_media_map($station);
    $type = isset($slot['type']) ? $slot['type'] : 'movie_rotate';
    $idx = relay_rolling_day_index($unixMs, isset($slot['startDate']) ? $slot['startDate'] : '');
    if ($type === 'episode_daily') {
        $eps = relay_rolling_series_episodes($station, $channel, isset($slot['series']) ? $slot['series'] : '');
        if (!$eps) return null;
        return $eps[$idx % count($eps)];
    }
    $ids = isset($slot['mediaIds']) && is_array($slot['mediaIds']) ? $slot['mediaIds'] : array();
    $pool = array();
    foreach ($ids as $id) {
        if (isset($byId[$id]) && (($byId[$id]['kind'] ?? '') === 'movie' || ($byId[$id]['kind'] ?? '') === 'episode')) {
            $pool[] = $byId[$id];
        }
    }
    if (!$pool) return null;
    return $pool[$idx % count($pool)];
}

/** Local (server TZ) midnight + hour:minute → unix ms for a calendar day containing $aroundMs. */
function relay_rolling_slot_start_ms($aroundMs, $hour, $minute) {
    $local = $aroundMs / 1000;
    $y = (int) date('Y', (int)$local);
    $m = (int) date('n', (int)$local);
    $d = (int) date('j', (int)$local);
    return (int) (mktime((int)$hour, (int)$minute, 0, $m, $d, $y) * 1000);
}

function relay_rolling_block_from_item($station, $channelId, $item, $startUnix) {
    $dur = max(1000, (int)$item['durationMs']);
    $meta = relay_prog_fields($item);
    return array(
        'id' => $channelId . ':roll:' . $startUnix,
        'channelId' => $channelId,
        'startUnix' => $startUnix,
        'endUnix' => $startUnix + $dur,
        'mediaId' => $item['id'],
        'title' => relay_guide_title($item),
        'subtitle' => isset($item['subtitle']) ? $item['subtitle'] : '',
        'kind' => $item['kind'],
        'generated' => false,
        'rolling' => true,
        'url' => relay_resolve_url($item['url'], isset($station['mediaBaseUrl']) ? $station['mediaBaseUrl'] : ''),
        'durationMs' => $dur,
        'mediaOffsetMs' => 0,
        'showTitle' => $meta['showTitle'],
        'episodeTitle' => $meta['episodeTitle'],
        'season' => $meta['season'],
        'episode' => $meta['episode'],
        'catalogueId' => $meta['catalogueId'],
    );
}

/**
 * Overlay deterministic 24h rolling slots onto a materialized guide window.
 * Splits/replaces underlying cycle blocks so Watch Live reflects the schedule
 * without rewriting NFO or persisting daily cursors.
 */
function relay_apply_rolling_schedule($station, $channelId, $blocks, $from, $to) {
    $channel = null;
    foreach ($station['channels'] as $c) {
        if (($c['id'] ?? '') === $channelId) { $channel = $c; break; }
    }
    if (!$channel) return $blocks;
    $channel = relay_normalize_rolling($channel);
    $rs = $channel['rollingSchedule'];
    if (empty($rs['enabled']) || empty($rs['slots'])) return $blocks;

    $overlays = array();
    // Walk each local calendar day that intersects [from, to)
    $cursor = $from - 86400000; // include previous day for late-night spill
    $guard = 0;
    while ($cursor < $to + 86400000 && $guard < 40) {
        $guard++;
        foreach ($rs['slots'] as $slot) {
            $start = relay_rolling_slot_start_ms($cursor, (int)$slot['hour'], (int)$slot['minute']);
            if ($start >= $to) continue;
            $item = relay_rolling_pick_item($station, $channel, $slot, $start);
            if (!$item || (int)(isset($item['durationMs']) ? $item['durationMs'] : 0) <= 0) continue;
            $ov = relay_rolling_block_from_item($station, $channelId, $item, $start);
            // keep a slot that is still on air at $from. It used to be dropped as soon as it
            // had started, so /now (from = now) returned the cycle block underneath while the guide
            // (from = now - 15 min) showed the slot.
            if ($ov['endUnix'] <= $from) continue;
            $overlays[$start] = $ov;
        }
        $cursor += 86400000;
    }
    if (!$overlays) return $blocks;

    usort($overlays, function ($a, $b) { return $a['startUnix'] - $b['startUnix']; });
    // overlapping slots - the earlier one ends where the next begins
    for ($k = 0; $k + 1 < count($overlays); $k++) {
        if ($overlays[$k]['endUnix'] > $overlays[$k + 1]['startUnix']) {
            $overlays[$k]['endUnix'] = $overlays[$k + 1]['startUnix'];
            $overlays[$k]['durationMs'] = $overlays[$k]['endUnix'] - $overlays[$k]['startUnix'];
            $overlays[$k]['split'] = true;
        }
    }

    $out = array();
    foreach ($blocks as $b) {
        $bStart = (int)$b['startUnix'];
        $bEnd = (int)$b['endUnix'];
        $pieces = array(array($bStart, $bEnd, $b));
        foreach ($overlays as $ov) {
            $oStart = (int)$ov['startUnix'];
            $oEnd = (int)$ov['endUnix'];
            $next = array();
            foreach ($pieces as $p) {
                list($ps, $pe, $src) = $p;
                if ($oEnd <= $ps || $oStart >= $pe) { $next[] = $p; continue; }
                $srcOff = isset($src['mediaOffsetMs']) ? (int)$src['mediaOffsetMs'] : 0;
                if ($ps < $oStart) {
                    $left = $src;
                    $left['startUnix'] = $ps;
                    $left['endUnix'] = $oStart;
                    $left['durationMs'] = $oStart - $ps;
                    $left['id'] = $channelId . ':' . $ps;
                    $left['mediaOffsetMs'] = $srcOff;
                    $left['split'] = true;
                    $next[] = array($ps, $oStart, $left);
                }
                if ($oEnd < $pe) {
                    // the tail of a programme cut by a rolling slot resumes INSIDE the file
                    // (was: same URL with startUnix = slot end, so it replayed from 0:00)
                    $right = $src;
                    $right['startUnix'] = $oEnd;
                    $right['endUnix'] = $pe;
                    $right['durationMs'] = $pe - $oEnd;
                    $right['id'] = $channelId . ':' . $oEnd;
                    $right['mediaOffsetMs'] = $srcOff + ($oEnd - $ps);
                    $right['split'] = true;
                    $next[] = array($oEnd, $pe, $right);
                }
            }
            $pieces = $next;
        }
        foreach ($pieces as $p) {
            if ($p[1] > $p[0]) $out[] = $p[2];
        }
    }
    foreach ($overlays as $ov) {
        if ($ov['endUnix'] > $from && $ov['startUnix'] < $to) $out[] = $ov;
    }
    usort($out, function ($a, $b) {
        if ($a['startUnix'] === $b['startUnix']) return $a['endUnix'] - $b['endUnix'];
        return $a['startUnix'] - $b['startUnix'];
    });
    return $out;
}

function relay_rebuild_cycles($station) {
    $station = relay_enrich_station_episodes($station);
    $cycles = array();
    foreach ($station['channels'] as $i => $channel) {
        $station['channels'][$i] = relay_normalize_rolling($channel);
        $cycles[$station['channels'][$i]['id']] = relay_build_cycle($station, $station['channels'][$i]);
    }
    $station['cycles'] = $cycles;
    return $station;
}

function relay_locate($cycle, $unixMs) {
    if (empty($cycle['blocks']) || $cycle['totalMs'] <= 0) return null;
    $pos = relay_mod($unixMs - $cycle['epoch'], $cycle['totalMs']);
    $acc = 0;
    $count = count($cycle['blocks']);
    for ($i = 0; $i < $count; $i++) {
        $block = $cycle['blocks'][$i];
        $next = $acc + $block['durationMs'];
        if ($pos < $next) {
            $behind = floor(($unixMs - $cycle['epoch']) / $cycle['totalMs']);
            $cycleStart = $cycle['epoch'] + $behind * $cycle['totalMs'];
            return array('index' => $i, 'offsetMs' => $pos - $acc, 'blockStart' => $cycleStart + $acc);
        }
        $acc = $next;
    }
    return null;
}

function relay_air_block($station, $channelId, $unixMs) {
    require_once __DIR__ . '/tvstudio.php';
    if (empty($station['cycles'][$channelId]) && !tvs_has_calendar($channelId)) return null;
    $merged = relay_materialize($station, $channelId, $unixMs, $unixMs + 3600000);
    foreach ($merged as $b) {
        if ((int)$b['startUnix'] <= $unixMs && (int)$b['endUnix'] > $unixMs) return $b;
    }
    return null;
}

function relay_materialize($station, $channelId, $from, $to) {
    $out = relay_materialize_cycle_only($station, $channelId, $from, $to);
    $out = relay_apply_rolling_schedule($station, $channelId, $out, $from, $to);
    // TV Studio weekly calendar, filler and advert rules (no-op for unconfigured channels)
    require_once __DIR__ . '/tvstudio.php';
    return tvs_apply($station, $channelId, $out, $from, $to);
}

/** Cycle-only materialize (no rolling overlay). */
function relay_materialize_cycle_only($station, $channelId, $from, $to) {
    if (empty($station['cycles'][$channelId]) || $to <= $from) return array();
    $cycle = $station['cycles'][$channelId];
    $loc = relay_locate($cycle, $from);
    if (!$loc) return array();
    $out = array();
    $start = $loc['blockStart'];
    $index = $loc['index'];
    $n = count($cycle['blocks']);
    $guard = 0;
    while ($start < $to && $guard < 8000) {
        $block = $cycle['blocks'][$index];
        $end = $start + $block['durationMs'];
        if ($end > $from) {
            $meta = relay_enrich_block_fields($block);
            $out[] = array(
                'id' => $channelId . ':' . $start,
                'channelId' => $channelId,
                'startUnix' => $start,
                'endUnix' => $end,
                'mediaId' => $block['mediaId'],
                'title' => $block['title'],
                'subtitle' => $block['subtitle'],
                'kind' => $block['kind'],
                'generated' => $block['generated'],
                'url' => $block['url'],
                'durationMs' => $block['durationMs'],
                'mediaOffsetMs' => 0,
                'estimated' => !empty($block['estimated']),
                'showTitle' => $meta['showTitle'],
                'episodeTitle' => $meta['episodeTitle'],
                'season' => $meta['season'],
                'episode' => $meta['episode'],
                'catalogueId' => $meta['catalogueId'],
            );
        }
        $start = $end;
        $index = ($index + 1) % $n;
        $guard++;
    }
    return $out;
}

function relay_build_m3u($station, $channelId, $origin) {
    $channel = null;
    foreach ($station['channels'] as $c) {
        if ($c['id'] === $channelId) $channel = $c;
    }
    if (!$channel) return "#EXTM3U\n";
    $now = (int) round(microtime(true) * 1000);
    $current = relay_air_block($station, $channelId, $now);
    $ahead = max((int)$station['lookaheadHours'], 6) * 3600 * 1000;
    $from = $current ? $current['startUnix'] : $now;
    $blocks = relay_materialize($station, $channelId, $from, $now + $ahead);
    $lines = array('#EXTM3U', '#EXTINF:-1,Relay — ' . $channel['number'] . ' ' . $channel['name']);
    foreach ($blocks as $i => $block) {
        $url = $block['url'];
        if (!preg_match('#^https?://#i', $url)) {
            // Absolute paths already include mediaBaseUrl; only prepend scheme+host.
            // Relative paths still need the script-base path from $origin.
            if (isset($url[0]) && $url[0] === '/') {
                if (preg_match('#^(https?://[^/]+)#i', $origin, $m)) {
                    $url = $m[1] . $url;
                } else {
                    $url = rtrim($origin, '/') . $url;
                }
            } else {
                $url = rtrim($origin, '/') . '/' . $url;
            }
        }
        $mOff = isset($block['mediaOffsetMs']) ? (int)$block['mediaOffsetMs'] : 0;
        if ($i === 0 && $current) {
            $offset = max(0, ($now - $block['startUnix']) / 1000) + $mOff / 1000;
            $lines[] = '#EXTVLCOPT:start-time=' . sprintf('%.3f', $offset);
            $remaining = max(1, ($block['endUnix'] - $now) / 1000);
        } else {
            if ($mOff > 0) $lines[] = '#EXTVLCOPT:start-time=' . sprintf('%.3f', $mOff / 1000);
            $remaining = $block['durationMs'] / 1000;
        }
        if (!empty($block['split'])) $lines[] = '#EXTVLCOPT:stop-time=' . sprintf('%.3f', ($mOff + $block['durationMs']) / 1000);
        $lines[] = '#EXTINF:' . sprintf('%.3f', $remaining) . ',' . str_replace(',', ' ', $block['title']);
        $lines[] = $url;
    }
    return implode("\n", $lines) . "\n";
}
