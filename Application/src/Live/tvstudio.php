<?php
/**
 * TV Studio: Tvheadend-style programming layer on top of Relay channels.
 *
 * Stored as the `live_tvstudio` document, separate from `live_station`, so the
 * existing Relay editor keeps working unchanged. Per channel it adds:
 *   - a recurring weekly calendar (slots: days of week, start minute, length, content)
 *   - a filler list that plays whenever no slot is on air (calendar mode)
 *   - advert rules (pool, break every N minutes, break length, pre-roll)
 *   - numbering/logo/access overrides, recordings (DVR entries) and EPG settings.
 *
 * Everything is deterministic from wall-clock time, like the Relay cycle, so
 * /now, the guide, XMLTV and M3U all agree without a running playout process.
 */
if (!function_exists('relay_load_station')) require_once __DIR__ . '/store.php';
require_once __DIR__ . '/schedule.php';

const TVS_WEEK_MIN = 10080;

function tvs_default_state() {
    return array(
        'version' => 1,
        'timezone' => date_default_timezone_get(),
        'channels' => array(),      // channelId => channel config
        'fillerLists' => array(),   // {id,name,mediaIds[],order}
        'adPools' => array(),       // {id,name,mediaIds[],order}
        'recordings' => array(),    // {id,channelId,title,startUnix,endUnix,status,createdAt}
        'epg' => array('exportDays' => 2, 'includeIptv' => true),
        'updatedAt' => null,
    );
}

function tvs_default_channel() {
    return array(
        'mode' => 'overlay',        // overlay: slots over the Relay cycle; calendar: slots + filler only
        'fillerListId' => '',
        'logo' => '',
        'group' => '',
        'access' => array('visibility' => 'everyone', 'users' => array()),
        'timeshift' => true,
        'ads' => array('enabled' => false, 'poolId' => '', 'everyMinutes' => 15, 'breakSeconds' => 120, 'preRoll' => false, 'kinds' => array('movie', 'episode')),
        'slots' => array(),
    );
}

function tvs_id($prefix, $seed = '') {
    return $prefix . '-' . substr(md5($seed . microtime(true) . mt_rand()), 0, 10);
}

function tvs_str($v, $max = 200) { $v = trim((string)$v); return function_exists("mb_substr") ? mb_substr($v, 0, $max) : substr($v, 0, $max); }

function tvs_ids($list) {
    $out = array();$seen=array();
    foreach ((is_array($list) ? $list : array()) as $id) {
        $id = tvs_str($id, 120);
        if ($id !== '' && !isset($seen[$id])) {$out[] = $id;$seen[$id]=true;}
    }
    return $out;
}

function tvs_normalize_list($l, $prefix) {
    if (!is_array($l)) return null;
    $order = isset($l['order']) && in_array($l['order'], array('sequential', 'shuffle'), true) ? $l['order'] : 'shuffle';
    $name = tvs_str($l['name'] ?? '', 80);
    return array(
        'id' => !empty($l['id']) ? tvs_str($l['id'], 60) : tvs_id($prefix, $name),
        'name' => $name !== '' ? $name : 'Untitled',
        'mediaIds' => tvs_ids($l['mediaIds'] ?? array()),
        'order' => $order,
    );
}

function tvs_normalize_slot($s) {
    if (!is_array($s)) return null;
    $days = array();
    foreach ((array)($s['days'] ?? array()) as $d) { $d = (int)$d; if ($d >= 0 && $d <= 6 && !in_array($d, $days, true)) $days[] = $d; }
    sort($days);
    if (!$days) return null;
    $start = max(0, min(1439, (int)($s['startMin'] ?? 0)));
    $dur = max(5, min(1440, (int)($s['durationMin'] ?? 60)));
    $type = in_array($s['contentType'] ?? '', array('media', 'list', 'series', 'filler'), true) ? $s['contentType'] : 'list';
    return array(
        'id' => !empty($s['id']) ? tvs_str($s['id'], 60) : tvs_id('slot'),
        'title' => tvs_str($s['title'] ?? '', 120),
        'days' => $days,                       // 0 = Monday … 6 = Sunday
        'startMin' => $start,                  // minutes after local midnight
        'durationMin' => $dur,
        'contentType' => $type,                // media | list | series | filler
        'mediaIds' => tvs_ids($s['mediaIds'] ?? array()),
        'series' => tvs_str($s['series'] ?? '', 160),
        'fillerListId' => tvs_str($s['fillerListId'] ?? '', 60),
        'order' => ($s['order'] ?? '') === 'shuffle' ? 'shuffle' : 'sequential',
        'singleEpisode' => !empty($s['singleEpisode']),
        'fitOnly' => !empty($s['fitOnly']),
        'overrun' => in_array($s['overrun'] ?? '', array('shift', 'skip'), true) ? $s['overrun'] : 'boundary',
        'paddingEnabled' => !empty($s['paddingEnabled']),
        'paddingFillerListId' => tvs_str($s['paddingFillerListId'] ?? '', 60),
        'paddingAdPoolId' => tvs_str($s['paddingAdPoolId'] ?? '', 60),
        'ads' => !isset($s['ads']) || !empty($s['ads']),
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($s['color'] ?? '')) ? $s['color'] : '',
        'validFrom' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($s['validFrom'] ?? '')) ? $s['validFrom'] : '',
        'validTo' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($s['validTo'] ?? '')) ? $s['validTo'] : '',
    );
}

function tvs_normalize_channel($c) {
    $d = tvs_default_channel();
    if (!is_array($c)) return $d;
    $out = $d;
    $out['overrunEpoch'] = max(0, (int)($c['overrunEpoch'] ?? 0));
    $out['mode'] = ($c['mode'] ?? '') === 'calendar' ? 'calendar' : 'overlay';
    $out['fillerListId'] = tvs_str($c['fillerListId'] ?? '', 60);
    $logo = tvs_str($c['logo'] ?? '', 500);
    $out['logo'] = ($logo === '' || preg_match('#^(https?://|/)#i', $logo)) ? $logo : '';
    $out['group'] = tvs_str($c['group'] ?? '', 60);
    $acc = is_array($c['access'] ?? null) ? $c['access'] : array();
    $vis = in_array($acc['visibility'] ?? '', array('everyone', 'signed-in', 'admins', 'users'), true) ? $acc['visibility'] : 'everyone';
    $out['access'] = array('visibility' => $vis, 'users' => tvs_ids($acc['users'] ?? array()));
    $out['timeshift'] = !isset($c['timeshift']) || !empty($c['timeshift']);
    $ads = is_array($c['ads'] ?? null) ? $c['ads'] : array();
    $out['ads'] = array(
        'enabled' => !empty($ads['enabled']),
        'poolId' => tvs_str($ads['poolId'] ?? '', 60),
        'everyMinutes' => max(5, min(120, (int)($ads['everyMinutes'] ?? 15))),
        'breakSeconds' => max(10, min(600, (int)($ads['breakSeconds'] ?? 120))),
        'preRoll' => !empty($ads['preRoll']),
        'kinds' => array_values(array_intersect(tvs_ids($ads['kinds'] ?? array('movie', 'episode')), array('movie', 'episode', 'other'))),
    );
    $slots = array();
    foreach ((array)($c['slots'] ?? array()) as $s) { $n = tvs_normalize_slot($s); if ($n) $slots[] = $n; }
    usort($slots, function ($a, $b) { return ($a['days'][0] * 1440 + $a['startMin']) - ($b['days'][0] * 1440 + $b['startMin']); });
    $out['slots'] = $slots;
    return $out;
}

function tvs_normalize_recording($r) {
    if (!is_array($r) || empty($r['channelId'])) return null;
    $s = (int)($r['startUnix'] ?? 0); $e = (int)($r['endUnix'] ?? 0);
    if ($s <= 0 || $e <= $s) return null;
    $status = in_array($r['status'] ?? '', array('scheduled', 'cancelled', 'completed', 'failed'), true) ? $r['status'] : 'scheduled';
    return array(
        'id' => !empty($r['id']) ? tvs_str($r['id'], 60) : tvs_id('rec'),
        'channelId' => tvs_str($r['channelId'], 120),
        'title' => tvs_str($r['title'] ?? 'Recording', 160),
        'startUnix' => $s, 'endUnix' => $e, 'status' => $status,
        'createdAt' => (int)($r['createdAt'] ?? round(microtime(true) * 1000)),
    );
}

function tvs_normalize_state($in) {
    $st = tvs_default_state();
    if (!is_array($in)) return $st;
    $tz = (string)($in['timezone'] ?? '');
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) $st['timezone'] = $tz;
    foreach ((array)($in['channels'] ?? array()) as $cid => $c) {
        $cid = tvs_str($cid, 120);
        if ($cid !== '') $st['channels'][$cid] = tvs_normalize_channel($c);
    }
    foreach ((array)($in['fillerLists'] ?? array()) as $l) { $n = tvs_normalize_list($l, 'fill'); if ($n) $st['fillerLists'][] = $n; }
    foreach ((array)($in['adPools'] ?? array()) as $l) { $n = tvs_normalize_list($l, 'ads'); if ($n) $st['adPools'][] = $n; }
    foreach ((array)($in['recordings'] ?? array()) as $r) { $n = tvs_normalize_recording($r); if ($n) $st['recordings'][] = $n; }
    $epg = is_array($in['epg'] ?? null) ? $in['epg'] : array();
    $st['epg'] = array('exportDays' => max(1, min(7, (int)($epg['exportDays'] ?? 2))), 'includeIptv' => !isset($epg['includeIptv']) || !empty($epg['includeIptv']));
    $st['updatedAt'] = $in['updatedAt'] ?? null;
    return $st;
}

function tvs_load() {
    static $cache = null;
    if ($cache !== null && empty($GLOBALS['__tvs_nocache'])) return $cache;
    $cache = tvs_normalize_state(\Vaultcast\Repository::document('live_tvstudio', null));
    return $cache;
}

function tvs_save($in) {
    $st = tvs_normalize_state($in);
    $previous = tvs_load();
    foreach ($st['channels'] as $id => &$channel) {
        $channel['overrunEpoch'] = (int)($previous['channels'][$id]['overrunEpoch'] ?? 0);
        foreach ($channel['slots'] as $slot) if ($slot['overrun'] === 'shift' && !$channel['overrunEpoch']) {
            $channel['overrunEpoch'] = tvs_week_start((int)round(microtime(true) * 1000), $st['timezone']); break;
        }
    }
    unset($channel);
    $st['updatedAt'] = gmdate('c');
    \Vaultcast\Repository::putDocument('live_tvstudio', $st);
    $GLOBALS['__tvs_nocache'] = true;
    return $st;
}

/** Validation warnings shown in the admin (overlaps, empty content, missing durations). */
function tvs_lint($st, $station) {
    $byId = relay_media_map($station);
    $warn = array();
    foreach ($st['channels'] as $cid => $c) {
        $occ = array();
        foreach ($c['slots'] as $s) {
            foreach ($s['days'] as $d) $occ[] = array($d * 1440 + $s['startMin'], $d * 1440 + $s['startMin'] + $s['durationMin'], $s);
            $items = tvs_slot_items($st, $station, $s);
            if (!$items) $warn[] = array('channelId' => $cid, 'slotId' => $s['id'], 'message' => 'Slot "' . ($s['title'] ?: 'untitled') . '" has no playable media with a known duration.');
        }
        usort($occ, function ($a, $b) { return $a[0] - $b[0]; });
        for ($i = 0; $i + 1 < count($occ); $i++) {
            if ($occ[$i][1] > $occ[$i + 1][0]) $warn[] = array('channelId' => $cid, 'slotId' => $occ[$i + 1][2]['id'], 'message' => 'Slot "' . ($occ[$i + 1][2]['title'] ?: 'untitled') . '" overlaps the previous slot; the earlier one is cut short.');
        }
        if ($c['mode'] === 'calendar' && $c['fillerListId'] === '') $warn[] = array('channelId' => $cid, 'message' => 'Calendar mode without a filler list: gaps will be off air.');
        if ($c['ads']['enabled'] && $c['ads']['poolId'] === '') $warn[] = array('channelId' => $cid, 'message' => 'Adverts are on but no ad pool is selected.');
    }
    return $warn;
}

function tvs_list_by_id($lists, $id) {
    foreach ($lists as $l) if ($l['id'] === $id) return $l;
    return null;
}

function tvs_playable($byId, $ids) {
    $out = array();
    foreach ($ids as $id) {
        if (isset($byId[$id]) && (int)($byId[$id]['durationMs'] ?? 0) > 0) $out[] = $byId[$id];
    }
    return $out;
}

/** Media items a slot draws from, in base order. */
function tvs_slot_items($st, $station, $slot) {
    $byId = relay_media_map($station);
    if ($slot['contentType'] === 'filler') {
        $l = tvs_list_by_id($st['fillerLists'], $slot['fillerListId']);
        return $l ? tvs_playable($byId, $l['mediaIds']) : array();
    }
    if ($slot['contentType'] === 'series') {
        $eps = array();
        foreach ($station['media'] ?? array() as $m) {
            if (($m['kind'] ?? '') === 'episode' && strcasecmp(trim((string)($m['series'] ?? '')), $slot['series']) === 0 && (int)($m['durationMs'] ?? 0) > 0) $eps[] = $m;
        }
        usort($eps, function ($a, $b) {
            $s = (int)($a['season'] ?? 0) - (int)($b['season'] ?? 0);
            return $s !== 0 ? $s : (int)($a['episode'] ?? 0) - (int)($b['episode'] ?? 0);
        });
        return $eps;
    }
    return tvs_playable($byId, $slot['mediaIds']);
}

function tvs_shuffled($items, $seed) {
    $rand = relay_mulberry32(relay_hash($seed));
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = (int)floor($rand() * ($i + 1));
        $t = $items[$i]; $items[$i] = $items[$j]; $items[$j] = $t;
    }
    return $items;
}

function tvs_block($station, $channelId, $item, $start, $dur, $offset, $extra = array()) {
    $meta = relay_prog_fields($item);
    $full = (int)$item['durationMs'];
    return array_merge(array(
        'id' => $channelId . ':' . $start,
        'channelId' => $channelId,
        'startUnix' => $start,
        'endUnix' => $start + $dur,
        'mediaId' => $item['id'],
        'title' => relay_guide_title($item),
        'subtitle' => isset($item['subtitle']) ? $item['subtitle'] : '',
        'kind' => $item['kind'] ?? 'other',
        'generated' => false,
        'url' => relay_resolve_url($item['url'] ?? '', $station['mediaBaseUrl'] ?? '', $item['id']),
        'durationMs' => $dur,
        'mediaOffsetMs' => $offset,
        'split' => ($offset > 0 || $offset + $dur < $full),
        'estimated' => false,
        'showTitle' => $meta['showTitle'], 'episodeTitle' => $meta['episodeTitle'],
        'season' => $meta['season'], 'episode' => $meta['episode'], 'catalogueId' => $meta['catalogueId'],
    ), $extra);
}

/**
 * Fill [start, end) from a list of items, inserting ad breaks per the channel rules.
 * Deterministic: depends only on start/end/seed. Programmes are cut at `end`.
 */
function tvs_fill_window($st, $station, $channelId, $items, $start, $end, $seed, $order, $adsOn, $firstIndex = 0, $tag = array(), $maxItems = 0, $fitOnly = false, $once = false) {
    $out = array();
    if (!$items || $end <= $start) return $out;
    if ($order === 'shuffle') $items = tvs_shuffled($items, $seed);
    // Trial each complete programme including its actual ad breaks. Rejecting a
    // candidate also rejects its adverts, so an oversized episode cannot leave a pre-roll.
    if ($fitOnly) {
        $t = $start; $i = $firstIndex; $played = 0;
        while ($t < $end && $played < 2000 && (!$maxItems || $played < $maxItems)) {
            $found = false;
            for ($skip = 0; $skip < count($items) && (!$once || $i - $firstIndex < count($items)); $skip++) {
                $item = $items[$i++ % count($items)];
                if ((int)$item['durationMs'] > $end - $t) continue;
                $trial = tvs_fill_window($st, $station, $channelId, array($item), $t, $end, $seed, 'sequential', $adsOn, 0, $tag, 1);
                $duration = 0;
                foreach ($trial as $b) if (empty($b['advert'])) $duration += $b['durationMs'];
                if ($duration !== (int)$item['durationMs']) continue;
                foreach ($trial as $b) $out[] = $b;
                $t = $trial[count($trial) - 1]['endUnix'];
                $played++; $found = true; break;
            }
            if (!$found) break;
        }
        return $out;
    }
    $cfg = $st['channels'][$channelId] ?? tvs_default_channel();
    $ads = $cfg['ads'];
    $pool = array();
    if ($adsOn && $ads['enabled']) {
        $p = tvs_list_by_id($st['adPools'], $ads['poolId']);
        if ($p) $pool = tvs_playable(relay_media_map($station), $p['mediaIds']);
        if ($pool && $p['order'] === 'shuffle') $pool = tvs_shuffled($pool, $seed . ':ads');
    }
    $adIdx = 0;
    $t = $start;
    $n = count($items);
    $i = $firstIndex;
    $guard = 0;
    $breakEveryMs = $ads['everyMinutes'] * 60000;
    $breakMs = $ads['breakSeconds'] * 1000;
    $runBreak = function () use (&$t, &$out, &$adIdx, $pool, $breakMs, $end, $station, $channelId, $tag) {
        if (!$pool) return;
        $stop = min($end, $t + $breakMs);
        $g = 0;
        while ($t < $stop && $g++ < 50) {
            $ad = $pool[$adIdx % count($pool)];
            $adIdx++;
            $d = min((int)$ad['durationMs'], $end - $t);
            if ($t + $d > $stop + 15000 && $t > $stop - $breakMs) { if ($g > 1) break; } // do not overrun a break by more than 15 s
            $out[] = tvs_block($station, $channelId, $ad, $t, $d, 0, array_merge($tag, array('generated' => true, 'advert' => true)));
            $t += $d;
        }
    };
    while ($t < $end && $guard++ < 2000 && (!$maxItems || $guard <= $maxItems)) {
        $item = $items[$i % $n];
        $i++;
        $isProg = in_array($item['kind'] ?? 'other', $ads['kinds'], true) || (!in_array($item['kind'] ?? '', array('movie', 'episode'), true) && in_array('other', $ads['kinds'], true));
        $withAds = $pool && $isProg;
        if ($withAds && $ads['preRoll']) $runBreak();
        $full = (int)$item['durationMs'];
        $off = 0;
        while ($off < $full && $t < $end) {
            $seg = $full - $off;
            if ($withAds && $seg > $breakEveryMs + 120000) $seg = $breakEveryMs; // no break in the last 2 minutes
            $seg = min($seg, $end - $t);
            $out[] = tvs_block($station, $channelId, $item, $t, $seg, $off, $tag);
            $t += $seg; $off += $seg;
            if ($withAds && $off < $full && $t < $end) $runBreak();
        }
    }
    return $out;
}

/** Local week start (Monday 00:00) in ms for the week containing $ms. */
function tvs_week_start($ms, $tz) {
    $dt = new DateTime('@' . intdiv($ms, 1000));
    $dt->setTimezone(new DateTimeZone($tz));
    $dow = (int)$dt->format('N') - 1;
    $dt->setTime(0, 0, 0);
    $dt->modify('-' . $dow . ' days');
    return $dt->getTimestamp() * 1000;
}

/** Concrete slot occurrences intersecting [from, to), DST-safe (local wall clock). */
function tvs_occurrences($st, $channelId, $from, $to, $station = null) {
    $cfg = $st['channels'][$channelId] ?? null;
    if (!$cfg || !$cfg['slots']) return array();
    $tz = new DateTimeZone($st['timezone']);
    $occ = array();
    $elastic = false;
    foreach ($cfg['slots'] as $slot) if (($slot['overrun'] ?? '') === 'shift') $elastic = $station !== null;
    $epoch = (int)($cfg['overrunEpoch'] ?? 0) ?: tvs_week_start((int)round(microtime(true) * 1000), $st['timezone']);
    // Replay from a fixed activation week so /now and every guide window agree,
    // including a delay that crosses midnight or a week boundary.
    $week = tvs_week_start($from - 86400000, $st['timezone']);
    if ($elastic) $week = min($week, tvs_week_start($epoch, $st['timezone']));
    $guard = 0;
    while ($week < $to && ($elastic || $guard++ < 60)) {
        $base = (new DateTime('@' . intdiv($week, 1000)))->setTimezone($tz);
        foreach ($cfg['slots'] as $s) {
            foreach ($s['days'] as $d) {
                $dt = clone $base;
                $dt->modify('+' . $d . ' days');
                $date = $dt->format('Y-m-d');
                if ($s['validFrom'] !== '' && $date < $s['validFrom']) continue;
                if ($s['validTo'] !== '' && $date > $s['validTo']) continue;
                $dt->setTime(intdiv($s['startMin'], 60), $s['startMin'] % 60);
                $a = $dt->getTimestamp() * 1000;
                $b = $a + $s['durationMin'] * 60000;
                if ((!$elastic && $b <= $from - 86400000) || $a >= $to) continue;
                $occ[] = array('start' => $a, 'end' => $b, 'slot' => $s, 'week' => intdiv($week, 604800000));
            }
        }
        $week = tvs_week_start($week + 8 * 86400000, $st['timezone']);
    }
    usort($occ, function ($a, $b) { return $a['start'] - $b['start']; });
    for ($k = 0; $k + 1 < count($occ); $k++) {
        if ($occ[$k]['end'] > $occ[$k + 1]['start']) $occ[$k]['end'] = $occ[$k + 1]['start'];
    }
    if ($elastic) {
        $cursor = 0;
        foreach ($occ as &$o) {
            $o['scheduledStart'] = $o['start']; $o['scheduledEnd'] = $o['end'];
            if ($o['start'] >= $epoch && ($o['start'] < $cursor || ($o['slot']['overrun'] ?? '') === 'shift')) {
                $o['start'] = max($o['start'], $cursor);
                $core = tvs_slot_programme_blocks($st, $station, $channelId, $o, ($o['slot']['overrun'] ?? '') !== 'skip');
                $o['end'] = max($o['end'], $core ? $core[count($core) - 1]['endUnix'] : $o['start']);
                $o['elastic'] = true;
                if ($o['end'] > $from && $o['start'] < $to) $o['coreBlocks'] = $core;
            }
            $cursor = max($cursor, $o['end']);
        }
        unset($o);
    }
    return array_values(array_filter($occ, function ($o) use ($elastic, $from, $to) {
        return $o['end'] > $o['start'] && (!$elastic || ($o['end'] > $from && $o['start'] < $to));
    }));
}

function tvs_slot_programme_blocks($st, $station, $channelId, $o, $measure = false) {
    $s = $o['slot'];
    $items = tvs_slot_items($st, $station, $s);
    if (!$items) return array();
    $first = 0;
    $scheduledStart = $o['scheduledStart'] ?? $o['start'];
    // series: advance one episode per airing so a weekly slot walks through the show
    if ($s['contentType'] === 'series') {
        $airings = max(0, $o['week']) * count($s['days']) + array_search((int)(new DateTime('@' . intdiv($scheduledStart, 1000)))->setTimezone(new DateTimeZone($st['timezone']))->format('N') - 1, $s['days'], true);
        $first = $airings % count($items);
    }
    $order = $s['contentType'] === 'filler' ? (tvs_list_by_id($st['fillerLists'], $s['fillerListId'])['order'] ?? 'shuffle') : $s['order'];
    $tag = array('slotId' => $s['id'], 'slotTitle' => $s['title']);
    if ($s['color'] !== '') $tag['color'] = $s['color'];
    $seed = $channelId . ':' . $s['id'] . ':' . $scheduledStart;
    $single = !empty($s['singleEpisode']);
    $fitOnly = !empty($s['fitOnly']);
    $padding = !empty($s['paddingEnabled']) || ($s['overrun'] ?? '') === 'skip';
    $limit = $single || $s['contentType'] === 'series' || $s['contentType'] === 'media' ? 1 : count($items);
    return tvs_fill_window($st, $station, $channelId, $items, $o['start'], $measure ? $o['start'] + 30 * 86400000 : $o['end'], $seed, $order, $s['ads'], $first, $tag, $padding || $measure ? $limit : ($single ? 1 : 0), !$measure && ($fitOnly || $padding), $padding);
}

/** Fill a slot tail with complete items from its selected pools. */
function tvs_slot_padding($st, $station, $channelId, $slot, $start, $end, $seed) {
    $items = array();
    $byId = relay_media_map($station);
    foreach (array(array('fillerLists', 'paddingFillerListId', false), array('adPools', 'paddingAdPoolId', true)) as $source) {
        $list = tvs_list_by_id($st[$source[0]], $slot[$source[1]] ?? '');
        if (!$list) continue;
        $pool = tvs_playable($byId, $list['mediaIds']);
        if ($list['order'] === 'shuffle') $pool = tvs_shuffled($pool, $seed . ':' . $source[0]);
        foreach ($pool as $item) if ($item['durationMs'] <= $end - $start) $items[] = array('media' => $item, 'advert' => $source[2]);
    }
    if (!$items || $end <= $start) return array();
    // Find the fullest reachable duration, rather than greedily leaving a gap
    // when a different combination of filler and adverts would fit exactly.
    $durations = array();
    foreach ($items as $i => $item) {
        $duration = (int)$item['media']['durationMs'];
        if (!isset($durations[$duration])) $durations[$duration] = $i;
    }
    $reachable = array(0 => null); $queue = array(0); $depth = array(0 => 0); $best = 0;
    $gap = $end - $start;
    for ($q = 0; $q < count($queue) && count($reachable) < 100000; $q++) {
        $used = $queue[$q];
        if ($depth[$used] >= 2000) continue;
        foreach ($durations as $duration => $index) {
            $next = $used + $duration;
            if ($next > $gap || array_key_exists($next, $reachable)) continue;
            $reachable[$next] = array($used, $index); $queue[] = $next; $depth[$next] = $depth[$used] + 1;
            $best = max($best, $next);
            if ($best === $gap) break 2;
        }
    }
    $chosen = array();
    for ($cursor = $best; $cursor > 0;) {
        list($cursor, $index) = $reachable[$cursor]; $chosen[] = $index;
    }
    $out = array(); $cursor = $start; $rotation = array();
    foreach (array_reverse($chosen) as $index) {
        $duration = (int)$items[$index]['media']['durationMs'];
        // Rotate equal-length items too, so a pool does not repeat one advert.
        $matches = array();
        foreach ($items as $item) if ((int)$item['media']['durationMs'] === $duration) $matches[] = $item;
        $turn = $rotation[$duration] ?? 0; $item = $matches[$turn % count($matches)]; $rotation[$duration] = $turn + 1;
        $out[] = tvs_block($station, $channelId, $item['media'], $cursor, $duration, 0, array('slotId' => $slot['id'], 'slotTitle' => $slot['title'], 'padding' => true, 'filler' => true, 'advert' => $item['advert'], 'generated' => true));
        $cursor += $duration;
    }
    return $out;
}

function tvs_occurrence_blocks($st, $station, $channelId, $o) {
    $s = $o['slot'];
    $out = $o['coreBlocks'] ?? tvs_slot_programme_blocks($st, $station, $channelId, $o, !empty($o['elastic']));
    $cursor = $out ? $out[count($out) - 1]['endUnix'] : $o['start'];
    if (!empty($s['paddingEnabled']) || ($s['overrun'] ?? '') === 'skip') return array_merge($out, tvs_slot_padding($st, $station, $channelId, $s, $cursor, $o['end'], $channelId . ':' . $s['id'] . ':' . $o['start']));
    $single = !empty($s['singleEpisode']); $fitOnly = !empty($s['fitOnly']);
    $items = tvs_slot_items($st, $station, $s);
    $seed = $channelId . ':' . $s['id'] . ':' . $o['start'];
    $tag = array('slotId' => $s['id'], 'slotTitle' => $s['title']);
    if ($s['color'] !== '') $tag['color'] = $s['color'];
    if (!$single && !$fitOnly && empty($o['elastic'])) return $out;
    $cursor = $out ? $out[count($out) - 1]['endUnix'] : $o['start'];
    $cfg = $st['channels'][$channelId];
    $filler = tvs_list_by_id($st['fillerLists'], $cfg['fillerListId']);
    $pools = array();
    if ($filler) $pools[] = array(tvs_playable(relay_media_map($station), $filler['mediaIds']), $filler['order']);
    // Use other channel programmes when the filler list cannot fill the remainder.
    $ids = array();
    foreach (($station['cycles'][$channelId]['blocks'] ?? array()) as $b) $ids[] = $b['mediaId'];
    $excluded = $single ? array_column($items, 'id') : array();
    $pools[] = array(tvs_playable(relay_media_map($station), array_values(array_diff(array_unique($ids), $excluded))), 'sequential');
    foreach ($pools as $pool) {
        $tail = tvs_fill_window($st, $station, $channelId, $pool[0], $cursor, $o['end'], $seed . ':remainder', $pool[1], $s['ads'], 0, array_merge($tag, array('filler' => true)), 0, true);
        foreach ($tail as $b) $out[] = $b;
        if ($tail) $cursor = $tail[count($tail) - 1]['endUnix'];
    }
    return $out;
}

function tvs_clip($blocks, $from, $to) {
    return array_values(array_filter($blocks, function ($b) use ($from, $to) { return $b['endUnix'] > $from && $b['startUnix'] < $to; }));
}

/** Cut existing blocks around overlays (keeps media offsets for resumed tails). */
function tvs_overlay($blocks, $overlays, $channelId) {
    if (!$overlays) return $blocks;
    $out = array();
    foreach ($blocks as $b) {
        $pieces = array($b);
        foreach ($overlays as $ov) {
            $next = array();
            foreach ($pieces as $p) {
                if ($ov['endUnix'] <= $p['startUnix'] || $ov['startUnix'] >= $p['endUnix']) { $next[] = $p; continue; }
                $off = (int)($p['mediaOffsetMs'] ?? 0);
                if ($p['startUnix'] < $ov['startUnix']) {
                    $l = $p; $l['endUnix'] = $ov['startUnix']; $l['durationMs'] = $l['endUnix'] - $l['startUnix']; $l['split'] = true; $next[] = $l;
                }
                if ($ov['endUnix'] < $p['endUnix']) {
                    $r = $p; $r['startUnix'] = $ov['endUnix']; $r['durationMs'] = $p['endUnix'] - $ov['endUnix'];
                    $r['mediaOffsetMs'] = $off + ($ov['endUnix'] - $p['startUnix']); $r['id'] = $channelId . ':' . $r['startUnix']; $r['split'] = true; $next[] = $r;
                }
            }
            $pieces = $next;
        }
        foreach ($pieces as $p) if ($p['endUnix'] > $p['startUnix']) $out[] = $p;
    }
    return $out;
}

/**
 * Hook used by relay_materialize(): apply the weekly calendar, filler and adverts.
 * Returns $blocks untouched when the channel has no studio configuration.
 */
function tvs_apply($station, $channelId, $blocks, $from, $to) {
    try { $st = tvs_load(); } catch (Throwable $e) { return $blocks; }
    $cfg = $st['channels'][$channelId] ?? null;
    if (!$cfg) return $blocks;
    $occ = tvs_occurrences($st, $channelId, $from, $to, $station);
    $sched = array();
    foreach ($occ as $o) {
        if ($o['end'] <= $from) continue;
        foreach (tvs_occurrence_blocks($st, $station, $channelId, $o) as $b) $sched[] = $b;
    }
    if ($cfg['mode'] === 'calendar') {
        // filler fills every gap between occurrences; gaps are anchored on their real start so any window agrees
        $filler = tvs_list_by_id($st['fillerLists'], $cfg['fillerListId']);
        $fItems = $filler ? tvs_playable(relay_media_map($station), $filler['mediaIds']) : array();
        $gaps = array();
        $cursor = tvs_week_start($from - 86400000, $st['timezone']);
        foreach ($occ as $o) {
            if ($o['start'] > $cursor) $gaps[] = array($cursor, $o['start']);
            $cursor = max($cursor, $o['end']);
        }
        $tail = max($to, $cursor) ;
        if ($cursor < $to) {
            // open-ended gap: anchor filler on local week boundaries so it stays deterministic
            $wk = $cursor;
            while ($wk < $to) {
                $nextWeek = tvs_week_start($wk + 8 * 86400000, $st['timezone']);
                $gEnd = min($nextWeek, $tail);
                $gaps[] = array($wk, $nextWeek); $wk = $nextWeek;
                if ($gEnd >= $to) break;
            }
        }
        foreach ($gaps as $g) {
            if ($g[1] <= $from || $g[0] >= $to || !$fItems) continue;
            foreach (tvs_fill_window($st, $station, $channelId, $fItems, $g[0], $g[1], $channelId . ':filler:' . $g[0], $filler['order'], true, 0, array('filler' => true)) as $b) $sched[] = $b;
            // fill_window walks from the gap start; drop what ended before the window
        }
        usort($sched, function ($a, $b) { return $a['startUnix'] - $b['startUnix']; });
        return tvs_clip($sched, $from, $to);
    }
    if (!$sched && !$occ) return $blocks;
    $sched = tvs_clip($sched, $from, $to);
    $reserved = $sched;
    foreach ($occ as $o) if (($o['slot']['overrun'] ?? '') === 'skip' || !empty($o['elastic']) || !empty($o['slot']['singleEpisode']) || !empty($o['slot']['fitOnly']) || !empty($o['slot']['paddingEnabled'])) $reserved[] = array('startUnix' => $o['start'], 'endUnix' => $o['end']);
    $out = array_merge(tvs_overlay($blocks, $reserved, $channelId), $sched);
    usort($out, function ($a, $b) { return $a['startUnix'] - $b['startUnix']; });
    return $out;
}

/** True when a channel needs materialising even without a Relay cycle (calendar-only channels). */
function tvs_has_calendar($channelId) {
    try { $st = tvs_load(); } catch (Throwable $e) { return false; }
    return isset($st['channels'][$channelId]) && ($st['channels'][$channelId]['mode'] === 'calendar' || $st['channels'][$channelId]['slots']);
}

/** Channel visibility for the current viewer (admins always see everything). */
function tvs_channel_visible($channelId, $user) {
    try { $st = tvs_load(); } catch (Throwable $e) { return true; }
    $acc = $st['channels'][$channelId]['access'] ?? array('visibility' => 'everyone', 'users' => array());
    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    if ($role === 'admin') return true;
    switch ($acc['visibility']) {
        case 'signed-in': return is_array($user);
        case 'admins': return false;
        case 'users': return is_array($user) && (in_array((string)($user['username'] ?? ''), $acc['users'], true) || in_array((string)($user['id'] ?? ''), $acc['users'], true));
        default: return true;
    }
}

function tvs_xml($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/** XMLTV export of Relay channels (plus IPTV lineup programmes already indexed). */
function tvs_xmltv($station, $days = null) {
    $st = tvs_load();
    $days = $days ?: $st['epg']['exportDays'];
    $now = (int) round(microtime(true) * 1000);
    $from = $now - 2 * 3600000; $to = $now + $days * 86400000;
    $fmt = function ($ms) { return gmdate('YmdHis', intdiv($ms, 1000)) . ' +0000'; };
    $x = array('<?xml version="1.0" encoding="UTF-8"?>', '<!DOCTYPE tv SYSTEM "xmltv.dtd">', '<tv generator-info-name="Vaultcast TV Studio">');
    $progs = array();
    foreach ($station['channels'] ?? array() as $ch) {
        if (empty($ch['enabled'])) continue;
        $id = 'relay.' . $ch['id'];
        $x[] = '  <channel id="' . tvs_xml($id) . '"><display-name>' . tvs_xml($ch['name']) . '</display-name><display-name>' . tvs_xml($ch['number']) . '</display-name>'
            . (!empty($st['channels'][$ch['id']]['logo']) ? '<icon src="' . tvs_xml($st['channels'][$ch['id']]['logo']) . '"/>' : '') . '</channel>';
        foreach (relay_guide_blocks(relay_materialize($station, $ch['id'], $from, $to)) as $b) {
            $progs[] = array($id, $b);
        }
    }
    if ($st['epg']['includeIptv']) {
        require_once __DIR__ . '/epg.php';
        $idx = epg_load_iptv_index();
        foreach (iptv_lineup_channels() as $c) {
            $id = 'iptv.' . $c['id'];
            $x[] = '  <channel id="' . tvs_xml($id) . '"><display-name>' . tvs_xml($c['name']) . '</display-name>' . (!empty($c['logo']) ? '<icon src="' . tvs_xml($c['logo']) . '"/>' : '') . '</channel>';
            $list = $idx['programs'][$c['id']] ?? ($idx['programs'][$c['tvgId'] ?? ''] ?? array());
            foreach ($list as $p) if ($p['endUnix'] > $from && $p['startUnix'] < $to) $progs[] = array($id, $p);
        }
    }
    foreach ($progs as $pp) {
        list($id, $p) = $pp;
        $title = !empty($p['showTitle']) ? $p['showTitle'] : ($p['title'] ?? '');
        $line = '  <programme start="' . $fmt($p['startUnix']) . '" stop="' . $fmt($p['endUnix']) . '" channel="' . tvs_xml($id) . '"><title>' . tvs_xml($title) . '</title>';
        if (!empty($p['episodeTitle'])) $line .= '<sub-title>' . tvs_xml($p['episodeTitle']) . '</sub-title>';
        if (!empty($p['subtitle'])) $line .= '<desc>' . tvs_xml($p['subtitle']) . '</desc>';
        if (isset($p['season']) && $p['season'] !== null && isset($p['episode']) && $p['episode'] !== null) $line .= '<episode-num system="xmltv_ns">' . max(0, $p['season'] - 1) . '.' . max(0, $p['episode'] - 1) . '.</episode-num>';
        if (!empty($p['kind']) && in_array($p['kind'], array('movie', 'episode'), true)) $line .= '<category>' . ($p['kind'] === 'movie' ? 'Movie' : 'Series') . '</category>';
        $x[] = $line . '</programme>';
    }
    $x[] = '</tv>';
    return implode("\n", $x) . "\n";
}

/** Whole-lineup M3U for external players / Tvheadend-style clients. */
function tvs_playlist_m3u($station, $base) {
    $st = tvs_load();
    $lines = array('#EXTM3U url-tvg="' . $base . 'api/live/xmltv"');
    $chs = $station['channels'] ?? array();
    usort($chs, function ($a, $b) { return (int)$a['number'] - (int)$b['number']; });
    require_once __DIR__ . '/channel_slugs.php';
    list($slugs) = live_channel_slugs_current($station);
    $sidx = live_channel_slug_index($slugs);
    foreach ($chs as $ch) {
        if (empty($ch['enabled'])) continue;
        $c = $st['channels'][$ch['id']] ?? tvs_default_channel();
        $slug = $sidx['relay'][$ch['id']] ?? $ch['id'];
        // tvg-id stays the permanent relay.<id> so guide mappings survive renumbering; only the URL is numbered
        $lines[] = '#EXTINF:-1 tvg-id="relay.' . $ch['id'] . '" tvg-chno="' . (int)$ch['number'] . '"' . ($c['logo'] ? ' tvg-logo="' . str_replace('"', '', $c['logo']) . '"' : '') . ' group-title="' . str_replace('"', '', $c['group'] ?: 'Relay') . '",' . str_replace(',', ' ', $ch['name']);
        $lines[] = $base . 'api/live/tune?channel=' . rawurlencode($slug);
    }
    require_once __DIR__ . '/iptv.php';
    foreach (iptv_lineup_channels() as $c) {
        $lines[] = '#EXTINF:-1 tvg-id="iptv.' . $c['id'] . '" tvg-chno="' . (int)$c['number'] . '"' . (!empty($c['logo']) ? ' tvg-logo="' . str_replace('"', '', $c['logo']) . '"' : '') . ' group-title="' . str_replace('"', '', $c['group'] ?: 'IPTV') . '",' . str_replace(',', ' ', $c['name']);
        $lines[] = $c['url'];
    }
    return implode("\n", $lines) . "\n";
}

/** Health/status summary: Relay channel readiness, IPTV probe results, EPG freshness. */
function tvs_health($station) {
    require_once __DIR__ . '/iptv.php';
    require_once __DIR__ . '/epg.php';
    $now = (int) round(microtime(true) * 1000);
    $relay = array();
    foreach ($station['channels'] ?? array() as $ch) {
        $b = relay_air_block($station, $ch['id'], $now);
        $next = relay_materialize($station, $ch['id'], $now, $now + 6 * 3600000);
        $relay[] = array(
            'id' => $ch['id'], 'number' => (int)$ch['number'], 'name' => $ch['name'], 'enabled' => !empty($ch['enabled']),
            'onAir' => $b ? array('title' => $b['title'], 'endUnix' => $b['endUnix'], 'advert' => !empty($b['advert']), 'filler' => !empty($b['filler'])) : null,
            'state' => !$b ? 'off-air' : (!empty($b['estimated']) ? 'estimated' : 'ok'),
            'scheduledNext6h' => count($next),
        );
    }
    $iptv = array();
    foreach (iptv_favourites_with_status() as $f) {
        $iptv[] = array('id' => $f['id'], 'name' => $f['name'] ?? '', 'number' => (int)($f['number'] ?? 0), 'enabled' => !isset($f['enabled']) || !empty($f['enabled']), 'status' => $f['streamStatus'] ?? 'unknown', 'checkedAt' => $f['streamCheckedAt'] ?? null);
    }
    $idx = epg_load_iptv_index();
    return array('ok' => true, 'now' => $now, 'relay' => $relay, 'iptv' => $iptv, 'epg' => array('updatedAt' => $idx['updatedAt'] ?? null, 'errors' => $idx['errors'] ?? array()));
}

/** Admin payload: studio state plus the station objects the editor needs. */
function tvs_admin_payload() {
    $station = relay_load_station();
    require_once __DIR__ . '/iptv.php';
    $iptv = iptv_load();
    $st = tvs_load();
    $media = array();
    foreach ($station['media'] ?? array() as $m) {
        $media[] = array('id' => $m['id'], 'kind' => $m['kind'] ?? 'other', 'title' => relay_guide_title($m), 'series' => $m['series'] ?? '', 'season' => $m['season'] ?? null, 'episode' => $m['episode'] ?? null, 'durationMs' => (int)($m['durationMs'] ?? 0));
    }
    $chs = array();
    foreach ($station['channels'] ?? array() as $c) $chs[] = array('id' => $c['id'], 'number' => (int)$c['number'], 'name' => $c['name'], 'slogan' => $c['slogan'] ?? '', 'enabled' => !empty($c['enabled']));
    $users = array();
    try {
        foreach ((class_exists('Auth') ? Auth::users() : array()) as $u) if (is_array($u) && !empty($u['username'])) $users[] = array('username' => $u['username'], 'role' => $u['role'] ?? 'user');
    } catch (Throwable $e) {}
    return array(
        'ok' => true, 'studio' => $st, 'channels' => $chs, 'media' => $media,
        'feeds' => $iptv['feeds'] ?? array(), 'epgUrls' => $iptv['epgUrls'] ?? array(),
        'historyDays' => isset($station['historyDays']) ? (int)$station['historyDays'] : 7,
        'warnings' => tvs_lint($st, $station), 'timezones' => timezone_identifiers_list(), 'users' => $users,
    );
}

/** Apply channel numbering / naming edits made in the studio to the station document. */
function tvs_apply_channel_edits($edits) {
    if (!is_array($edits) || !$edits) return;
    $station = relay_load_station();
    $seen = array();
    foreach ($station['channels'] as $i => $c) {
        foreach ($edits as $e) {
            if (!is_array($e) || ($e['id'] ?? '') !== $c['id']) continue;
            if (isset($e['number'])) $station['channels'][$i]['number'] = max(1, min(9999, (int)$e['number']));
            if (isset($e['name']) && trim((string)$e['name']) !== '') $station['channels'][$i]['name'] = tvs_str($e['name'], 80);
            if (isset($e['enabled'])) $station['channels'][$i]['enabled'] = !empty($e['enabled']);
        }
        $n = (int)$station['channels'][$i]['number'];
        if (isset($seen[$n])) throw new InvalidArgumentException('Channel number ' . $n . ' is used twice.');
        $seen[$n] = true;
    }
    relay_save_station($station);
}
