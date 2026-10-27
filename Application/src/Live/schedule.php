<?php
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

    // Reuse stored titles until library metadata changes. Older stations resolve
    // each linked ID once, without loading the library or reading NFO sidecars.
    if (!empty($item['catalogueId']) && relay_try_boot_catalogue()) {
        static $titles = array();
        $id = (string)$item['catalogueId'];
        $revision = Store::revision('catalogue') . '|' . Store::revision('browse_epoch');
        if (empty($item['episode_title']) || ($item['episode_title_revision'] ?? '') !== $revision) {
            $key = $revision . '|' . $id;
            if (!array_key_exists($key, $titles)) {
                $cached = \Vaultcast\Repository::cacheGet('live-episode-title|' . $id);
                if ($cached !== null && ($cached['revision'] ?? '') === $revision) {
                    $titles[$key] = (string)($cached['title'] ?? '');
                } else {
                    $linked = Catalogue::item($id);
                    $titles[$key] = $linked ? Catalogue::resolveEpisodeTitle($linked, false) : '';
                    \Vaultcast\Repository::cachePut('live-episode-title|' . $id, ['revision'=>$revision, 'title'=>$titles[$key]], 0);
                }
            }
            if ($titles[$key] !== '') array_unshift($candidates, $titles[$key]);
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

/** Merge media fields and optional title parsing for guide blocks. */
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

/** Persist cleaned episode_title/title on station media for guide output. */
function relay_enrich_station_episodes($station) {
    if (empty($station['media']) || !is_array($station['media'])) return $station;
    foreach ($station['media'] as &$item) {
        if (!is_array($item) || ($item['kind'] ?? '') !== 'episode') continue;
        $name = relay_episode_name_only($item);
        if ($name === '') continue;
        $item['episode_title'] = $name;
        $item['title'] = $name;
        if (!empty($item['catalogueId']) && relay_try_boot_catalogue()) {
            $item['episode_title_revision'] = Store::revision('catalogue') . '|' . Store::revision('browse_epoch');
        }
    }
    unset($item);
    return $station;
}

function relay_resolve_url($url, $base, $mediaId = '') {
    // An existing server-side media file is not a browser URL. Route it through
    // the same token-backed streamer used for catalogue video playback.
    if ($mediaId !== '' && is_string($url) && (substr($url, 0, 1) === '/' || preg_match('#^[A-Za-z]:[/\\\\]#', $url)) && is_file($url)) {
        return rtrim(\Vaultcast\Database::basePath(), '/') . '/api/live/local_file?id=' . rawurlencode((string)$mediaId);
    }
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

function relay_strip_legacy_schedule($station) {
    if (!is_array($station)) return array();
    if (!isset($station['channels']) || !is_array($station['channels'])) $station['channels'] = array();
    foreach ($station['channels'] as &$channel) {
        if (!is_array($channel)) continue;
        foreach (array('playlist', 'rollingSchedule', 'fillHours', 'commercialEveryMs', 'commercialsPerBreak', 'bumperBefore', 'seed') as $legacy) unset($channel[$legacy]);
    }
    unset($channel);
    unset($station['cycles'], $station['playlists']);
    return $station;
}

function relay_prepare_station($station) {
    return relay_enrich_station_episodes(relay_strip_legacy_schedule($station));
}

function relay_air_block($station, $channelId, $unixMs) {
    require_once __DIR__ . '/tvstudio.php';
    if (!tvs_has_calendar($channelId)) return null;
    $merged = relay_materialize($station, $channelId, $unixMs, $unixMs + 3600000);
    foreach ($merged as $b) {
        if ((int)$b['startUnix'] <= $unixMs && (int)$b['endUnix'] > $unixMs) return $b;
    }
    return null;
}

function relay_materialize($station, $channelId, $from, $to) {
    $out = array();
    // TV Studio is the only schedule source for live channels.
    require_once __DIR__ . '/tvstudio.php';
    $out = tvs_apply($station, $channelId, $from, $to);
    // Resolve local-file URLs from saved media entries on every read.
    $media = relay_media_map($station);
    foreach ($out as &$block) {
        $id = (string)($block['mediaId'] ?? '');
        if ($id === '' || !isset($media[$id])) continue;
        $source = (string)($media[$id]['url'] ?? '');
        if ($source === '') continue;
        $resolved = relay_resolve_url($source, $station['mediaBaseUrl'] ?? '', $id);
        if (strpos($resolved, '/api/live/local_file?id=') !== false) $block['url'] = $resolved;
    }
    unset($block);
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
    // Entries below are individual scheduled programmes, not a single live transport stream.
    $lines = array('#EXTM3U');
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

/** Guide-only rows: keep programmes continuous across inserted advertising. */
function relay_guide_blocks($blocks) {
    $out = array();
    $pendingStart = null;
    $pendingEnd = null;
    $contentEnd = null;
    $offsetEnd = null;
    foreach ($blocks as $b) {
        $last = count($out) - 1;
        if (!empty($b['advert'])) {
            if ($pendingEnd !== $b['startUnix']) $pendingStart = $b['startUnix'];
            $pendingEnd = $b['endUnix'];
            continue;
        }
        $bridge = $pendingStart !== null && $pendingStart === $contentEnd && $pendingEnd === $b['startUnix'];
        $offset = (int)($b['mediaOffsetMs'] ?? 0);
        $continuation = $last >= 0 && $offset > 0 && $offset === $offsetEnd
            && ($out[$last]['mediaId'] ?? null) === ($b['mediaId'] ?? null)
            && ($out[$last]['slotId'] ?? '') === ($b['slotId'] ?? '')
            && ($contentEnd === $b['startUnix'] || $bridge);
        $offsetEnd = $offset + $b['endUnix'] - $b['startUnix'];
        $contentEnd = $b['endUnix'];
        if ($continuation) {
            $out[$last]['endUnix'] = $b['endUnix'];
            $out[$last]['durationMs'] = $b['endUnix'] - $out[$last]['startUnix'];
        } else {
            if ($pendingEnd === $b['startUnix']) $b['startUnix'] = $pendingStart;
            $b['durationMs'] = $b['endUnix'] - $b['startUnix'];
            $out[] = $b;
        }
        $pendingStart = $pendingEnd = null;
    }
    return $out;
}
