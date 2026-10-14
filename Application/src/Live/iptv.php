<?php
if (!defined('STREAM_ROOT')) require_once dirname(__DIR__) . '/bootstrap.php';


function iptv_cache_dir() {
    $dir = STREAM_DATA . '/live/iptv_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Sources shipped with a fresh install. Seeded once (see iptv_seed_defaults); a removed default stays removed. */
const IPTV_DEFAULT_FEED_URL = 'https://iptv-org.github.io/iptv/index.m3u';
function iptv_default_feeds() {
    return array(array('id' => 'iptv-org', 'name' => 'iptv-org (free public channels)', 'url' => IPTV_DEFAULT_FEED_URL, 'enabled' => true, 'preinstalled' => true));
}

/**
 * Add each default source exactly once per install. `seededDefaults` remembers what was
 * offered, so a user who deletes the source never gets it back on the next load or upgrade,
 * and an existing install that already has the same URL is not duplicated.
 */
function iptv_seed_defaults($state) {
    $seeded = isset($state['seededDefaults']) && is_array($state['seededDefaults']) ? $state['seededDefaults'] : array();
    foreach (iptv_default_feeds() as $def) {
        if (!empty($seeded[$def['id']])) continue;
        $seeded[$def['id']] = gmdate('c');
        $dupe = false;
        foreach ($state['feeds'] as $f) {
            if ((isset($f['id']) && $f['id'] === $def['id']) || (isset($f['url']) && rtrim(strtolower($f['url']), '/') === rtrim(strtolower($def['url']), '/'))) { $dupe = true; break; }
        }
        if (!$dupe) $state['feeds'][] = $def;
    }
    $state['seededDefaults'] = $seeded;
    return $state;
}

function iptv_default_state() {
    return array(
        'version' => 2,
        'numberStart' => 100,
        'epgUrls' => array(),
        'defaultCountry' => 'uk',
        'activeFeedId' => 'iptv-org',
        'feeds' => array(),
        'seededDefaults' => array(),
        'channels' => array(),
        'updatedAt' => null,
    );
}

function iptv_slug_id($name) {
    $id = strtolower(trim((string)$name));
    $id = preg_replace('/[^a-z0-9]+/', '-', $id);
    $id = trim($id, '-');
    if ($id === '') $id = 'feed';
    return substr($id, 0, 48);
}

function iptv_normalize_feed($incoming, $fallbackId = '') {
    if (!is_array($incoming)) {
        throw new InvalidArgumentException('Invalid feed');
    }
    $url = isset($incoming['url']) ? trim($incoming['url']) : '';
    $name = isset($incoming['name']) ? trim($incoming['name']) : '';
    if ($url === '') {
        throw new InvalidArgumentException('Feed needs a playlist URL (.m3u / .m3u8)');
    }
    if (!preg_match('#^https?://#i', $url)) {
        throw new InvalidArgumentException('Feed URL must be http(s)');
    }
    if ($name === '') {
        $host = parse_url($url, PHP_URL_HOST);
        $name = $host ? $host : 'Feed';
    }
    $id = isset($incoming['id']) ? trim((string)$incoming['id']) : '';
    if ($id === '') $id = $fallbackId !== '' ? $fallbackId : iptv_slug_id($name);
    $id = preg_replace('/[^A-Za-z0-9._-]+/', '-', $id);
    $id = trim($id, '-.');
    if ($id === '') $id = 'feed-' . substr(sha1($url), 0, 8);
    return array(
        'id' => $id,
        'name' => $name,
        'url' => $url,
        'enabled' => !isset($incoming['enabled']) || !!$incoming['enabled'],
        'preinstalled' => !empty($incoming['preinstalled']),
    );
}

function iptv_migrate_state($state) {
    if (!is_array($state)) $state = iptv_default_state();
    if (!isset($state['channels']) || !is_array($state['channels'])) $state['channels'] = array();
    if (!isset($state['epgUrls']) || !is_array($state['epgUrls'])) $state['epgUrls'] = array();
    if (!isset($state['numberStart'])) $state['numberStart'] = 100;
    if (!isset($state['defaultCountry'])) $state['defaultCountry'] = 'uk';
    if (!isset($state['feeds']) || !is_array($state['feeds'])) {
        $state['feeds'] = array();
    } else {
        $norm = array();
        $seen = array();
        foreach ($state['feeds'] as $f) {
            try {
                $nf = iptv_normalize_feed($f);
                $base = $nf['id'];
                $n = 2;
                while (isset($seen[$nf['id']])) {
                    $nf['id'] = $base . '-' . $n;
                    $n++;
                }
                $seen[$nf['id']] = true;
                $norm[] = $nf;
            } catch (Exception $e) {
                // skip invalid
            }
        }
        $state['feeds'] = $norm;
    }
    $state = iptv_seed_defaults($state);
    if (!isset($state['activeFeedId']) || $state['activeFeedId'] === '') {
        $state['activeFeedId'] = isset($state['feeds'][0]) ? $state['feeds'][0]['id'] : 'all';
    }
    if ($state['activeFeedId'] !== 'all') {
        $ok = false;
        foreach ($state['feeds'] as $f) {
            if ($f['id'] === $state['activeFeedId']) { $ok = true; break; }
        }
        if (!$ok) $state['activeFeedId'] = isset($state['feeds'][0]) ? $state['feeds'][0]['id'] : 'all';
    }
    if (!isset($state['version']) || (int)$state['version'] < 2) {
        $state['version'] = 2;
    }
    return $state;
}

function iptv_load() {
    $raw = \Vaultcast\Repository::document('live_iptv', null);
    $state = iptv_migrate_state(is_array($raw) ? $raw : iptv_default_state());
    // persist the one-time seed so a later removal is remembered
    if (!is_array($raw) || ($raw['seededDefaults'] ?? null) != $state['seededDefaults']) iptv_save($state);
    return $state;
}
function iptv_save($state) {
    $state = iptv_migrate_state($state);
    $state['updatedAt'] = gmdate('c');
    \Vaultcast\Repository::putDocument('live_iptv', $state);
    return $state;
}
function iptv_http_get($url, $timeout = 25) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . ' (+iptv)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => array('Accept: */*'),
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status >= 400) throw new RuntimeException('IPTV request failed.');
        return $body;
    }
    $ctx = stream_context_create(array(
        'http' => array(
            'timeout' => $timeout,
            'header' => "User-Agent: Vaultcast/" . STREAM_VERSION . "\r\nAccept: */*\r\n",
            'follow_location' => 1,
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('HTTP fetch failed: ' . $url);
    }
    return $body;
}

function iptv_feed_by_id($state, $id) {
    foreach ($state['feeds'] as $f) {
        if ($f['id'] === $id) return $f;
    }
    return null;
}

function iptv_enabled_feeds($state = null) {
    if ($state === null) $state = iptv_load();
    $out = array();
    foreach ($state['feeds'] as $f) {
        if (!isset($f['enabled']) || $f['enabled']) $out[] = $f;
    }
    return $out;
}

/** True for .m3u8 and extensionless/redirector HTTP(S) URLs; false for obvious non-HLS (.mpd etc.). */
function iptv_url_likely_hls($url) {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    if (preg_match('/\.m3u8(\?|$)/i', $url)) {
        return true;
    }
    if (preg_match('/\.(mpd|mp4|mkv|avi|mov|webm)(\?|$)/i', $url)) {
        return false;
    }
    return true;
}

/** Name/id search: substring with word-ish boundaries so "ITV" does not match every "*iTV" id. */
function iptv_channel_matches_query($ch, $q) {
    $q = trim((string)$q);
    if ($q === '') return true;
    $fields = array(
        isset($ch['name']) ? $ch['name'] : '',
        isset($ch['id']) ? $ch['id'] : '',
        isset($ch['tvgId']) ? $ch['tvgId'] : '',
        isset($ch['group']) ? $ch['group'] : '',
        isset($ch['sourceFeedName']) ? $ch['sourceFeedName'] : '',
    );
    $pattern = '/(^|[^A-Za-z0-9])' . preg_quote($q, '/') . '([^A-Za-z0-9]|$)/i';
    foreach ($fields as $f) {
        if ($f === '') continue;
        if (stripos($f, $q) === 0) return true;
        if (preg_match($pattern, $f)) return true;
    }
    return false;
}

function iptv_cache_path_feed($feedId) {
    $safe = preg_replace('/[^a-z0-9._-]+/i', '-', strtolower((string)$feedId));
    if ($safe === '') $safe = 'feed';
    return iptv_cache_dir() . '/feed-' . $safe . '.m3u';
}

/**
 * Legacy cache paths for iptv-org country/category subsets (optional).
 */
function iptv_cache_path($kind, $key = '') {
    $kind = strtolower(trim((string)$kind));
    if ($kind === 'master' || $kind === 'index' || $kind === 'all' || $kind === 'feed') {
        return iptv_cache_path_feed($key !== '' ? $key : 'iptv-org');
    }
    $safe = preg_replace('/[^a-z0-9_-]+/i', '', strtolower($key));
    return iptv_cache_dir() . '/' . $kind . '-' . $safe . '.m3u';
}

function iptv_playlist_url($kind, $key = '') {
    $kind = strtolower(trim((string)$kind));
    if ($kind === 'feed') {
        $state = iptv_load();
        $f = iptv_feed_by_id($state, $key);
        if (!$f) throw new InvalidArgumentException('Unknown feed: ' . $key);
        return $f['url'];
    }
    if ($kind === 'master' || $kind === 'index' || $kind === 'all') {
        $state = iptv_load();
        $f = iptv_feed_by_id($state, 'iptv-org');
        if ($f) return $f['url'];
        return 'https://iptv-org.github.io/iptv/index.m3u';
    }
    $key = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $key));
    if ($key === '') {
        throw new InvalidArgumentException('Missing playlist key');
    }
    if ($kind === 'country') {
        return 'https://iptv-org.github.io/iptv/countries/' . $key . '.m3u';
    }
    if ($kind === 'category') {
        return 'https://iptv-org.github.io/iptv/categories/' . $key . '.m3u';
    }
    if ($kind === 'language') {
        return 'https://iptv-org.github.io/iptv/languages/' . $key . '.m3u';
    }
    throw new InvalidArgumentException('Unknown playlist kind');
}

function iptv_fetch_feed_body($feed, $maxAge = 86400, $forceRefresh = false) {
    $path = iptv_cache_path_feed($feed['id']);
    if (!$forceRefresh && is_file($path) && (time() - filemtime($path)) < $maxAge) {
        return file_get_contents($path);
    }
    $body = iptv_http_get($feed['url'], 60);
    file_put_contents($path, $body);
    return $body;
}

function iptv_fetch_playlist($kind, $key = '', $maxAge = 86400, $forceRefresh = false) {
    $kind = strtolower(trim((string)$kind));
    if ($kind === 'feed') {
        $state = iptv_load();
        $f = iptv_feed_by_id($state, $key);
        if (!$f) throw new InvalidArgumentException('Unknown feed: ' . $key);
        return iptv_fetch_feed_body($f, $maxAge, $forceRefresh);
    }
    $path = iptv_cache_path($kind, $key);
    if (!$forceRefresh && is_file($path) && (time() - filemtime($path)) < $maxAge) {
        return file_get_contents($path);
    }
    $body = iptv_http_get(iptv_playlist_url($kind, $key), 60);
    file_put_contents($path, $body);
    return $body;
}

function iptv_cache_meta_feed($feedId) {
    $path = iptv_cache_path_feed($feedId);
    if (!is_file($path)) {
        return array('cached' => false, 'path' => $path, 'ageSec' => null, 'bytes' => 0, 'mtime' => null);
    }
    $mtime = filemtime($path);
    return array(
        'cached' => true,
        'path' => $path,
        'ageSec' => time() - $mtime,
        'bytes' => filesize($path),
        'mtime' => gmdate('c', $mtime),
    );
}

function iptv_cache_meta($kind, $key = '') {
    $kind = strtolower(trim((string)$kind));
    if ($kind === 'feed' || $kind === 'master' || $kind === 'index' || $kind === 'all') {
        return iptv_cache_meta_feed($key !== '' ? $key : 'iptv-org');
    }
    $path = iptv_cache_path($kind, $key);
    if (!is_file($path)) {
        return array('cached' => false, 'path' => $path, 'ageSec' => null, 'bytes' => 0, 'mtime' => null);
    }
    $mtime = filemtime($path);
    return array(
        'cached' => true,
        'path' => $path,
        'ageSec' => time() - $mtime,
        'bytes' => filesize($path),
        'mtime' => gmdate('c', $mtime),
    );
}

function iptv_attr($line, $name) {
    if (preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $line, $m)) {
        return $m[1];
    }
    return '';
}

function iptv_parse_m3u($text) {
    $lines = preg_split("/\n|\n|\r/", $text);
    $out = array();
    $epgUrls = array();
    $pending = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (stripos($line, '#EXTM3U') === 0) {
            if (preg_match('/(?:url-tvg|x-tvg-url)="([^"]+)"/i', $line, $m)) {
                foreach (preg_split('/\s*,\s*/', $m[1]) as $u) {
                    $u = trim($u);
                    if ($u !== '') $epgUrls[] = $u;
                }
            }
            continue;
        }
        if (stripos($line, '#EXTINF:') === 0) {
            $comma = strrpos($line, ',');
            $name = $comma !== false ? trim(substr($line, $comma + 1)) : '';
            $tvgId = iptv_attr($line, 'tvg-id');
            $logo = iptv_attr($line, 'tvg-logo');
            $group = iptv_attr($line, 'group-title');
            $ua = iptv_attr($line, 'http-user-agent');
            $ref = iptv_attr($line, 'http-referrer');
            if ($ref === '') $ref = iptv_attr($line, 'referrer');
            $channelId = $tvgId;
            $feed = '';
            if (strpos($tvgId, '@') !== false) {
                $parts = explode('@', $tvgId, 2);
                $channelId = $parts[0];
                $feed = isset($parts[1]) ? $parts[1] : '';
            }
            $pending = array(
                'id' => $channelId !== '' ? $channelId : ('stream-' . substr(sha1($name . $logo), 0, 10)),
                'tvgId' => $tvgId,
                'feed' => $feed,
                'name' => $name,
                'logo' => $logo,
                'group' => $group,
                'userAgent' => $ua !== '' ? $ua : null,
                'referrer' => $ref !== '' ? $ref : null,
                'url' => '',
                'quality' => '',
                'labels' => array(),
            );
            if (preg_match('/\((\d+p|HD|SD|FHD|4K)\)/i', $name, $qm)) {
                $pending['quality'] = $qm[1];
            }
            if (preg_match_all('/\[([^\]]+)\]/', $name, $lm)) {
                $pending['labels'] = $lm[1];
            }
            continue;
        }
        if ($line[0] === '#') continue;
        if ($pending) {
            $pending['url'] = $line;
            $out[] = $pending;
            $pending = null;
        }
    }
    return array('channels' => $out, 'epgUrls' => array_values(array_unique($epgUrls)));
}

/** ISO country from tvg-country or the iptv-org tvg-id suffix (BBCOne.uk@HD -> UK). */
function iptv_channel_country($ch, $line = '') {
    $c = isset($ch['country']) ? $ch['country'] : '';
    if ($c === '' && preg_match('/\.([a-z]{2})$/i', (string)$ch['id'], $m)) $c = $m[1];
    $c = strtoupper(substr(trim(explode(';', (string)$c)[0]), 0, 8));
    return $c === 'UK' ? 'GB' : $c;
}

function iptv_channel_categories($group) {
    $out = array();
    foreach (preg_split('/\s*;\s*/', (string)$group) as $g) {
        $g = trim($g);
        if ($g !== '' && strcasecmp($g, 'Undefined') !== 0) $out[] = $g;
    }
    return $out;
}

/**
 * Parsed + enriched channel list for a feed, cached as JSON next to the raw playlist so a
 * 10k-entry source (iptv-org) is parsed once per download, not on every browse page.
 */
function iptv_feed_index($feed, $forceRefresh = false) {
    $body = iptv_fetch_feed_body($feed, 86400, $forceRefresh);
    $raw = iptv_cache_path_feed($feed['id']);
    $sig = is_file($raw) ? (filemtime($raw) . ':' . filesize($raw)) : sha1($body);
    $idx = $raw . '.index.json';
    if (!$forceRefresh && is_file($idx)) {
        $j = json_decode((string)file_get_contents($idx), true);
        if (is_array($j) && ($j['sig'] ?? '') === $sig && isset($j['channels'])) return $j;
    }
    $parsed = iptv_parse_m3u($body);
    foreach ($parsed['channels'] as &$ch) {
        $ch['country'] = iptv_channel_country($ch);
        $ch['categories'] = iptv_channel_categories($ch['group']);
    }
    unset($ch);
    $out = array('sig' => $sig, 'builtAt' => gmdate('c'), 'channels' => $parsed['channels'], 'epgUrls' => $parsed['epgUrls']);
    @file_put_contents($idx, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $out;
}

/** Count values for browse filters (countries / categories) across rows. */
function iptv_facets($rows) {
    $countries = array(); $cats = array();
    foreach ($rows as $ch) {
        $c = isset($ch['country']) ? $ch['country'] : '';
        $countries[$c === '' ? '?' : $c] = ($countries[$c === '' ? '?' : $c] ?? 0) + 1;
        foreach ((isset($ch['categories']) ? $ch['categories'] : array()) as $g) $cats[$g] = ($cats[$g] ?? 0) + 1;
    }
    arsort($countries); arsort($cats);
    $f = function ($m) { $o = array(); foreach ($m as $k => $n) $o[] = array('key' => (string)$k, 'count' => $n); return $o; };
    return array('countries' => $f($countries), 'categories' => $f($cats));
}

/**
 * Resolve which feeds to browse. $feedId: 'all' | feed id | empty (uses activeFeedId).
 */
function iptv_resolve_browse_feeds($opts, $state = null) {
    if ($state === null) $state = iptv_load();
    $feedId = isset($opts['feed']) ? trim((string)$opts['feed']) : '';
    if ($feedId === '') {
        $feedId = isset($state['activeFeedId']) ? $state['activeFeedId'] : 'all';
    }
    // Legacy kind=master → treat as active / iptv-org feed
    $kind = isset($opts['kind']) ? strtolower(trim($opts['kind'])) : '';
    if ($feedId === '' && ($kind === 'master' || $kind === 'index')) {
        $feedId = iptv_feed_by_id($state, 'iptv-org') ? 'iptv-org' : (isset($state['feeds'][0]['id']) ? $state['feeds'][0]['id'] : 'all');
    }
    if ($feedId === 'all') {
        return array('mode' => 'all', 'feeds' => iptv_enabled_feeds($state), 'feedId' => 'all');
    }
    $f = iptv_feed_by_id($state, $feedId);
    if (!$f) {
        throw new InvalidArgumentException('Unknown feed: ' . $feedId);
    }
    return array('mode' => 'one', 'feeds' => array($f), 'feedId' => $feedId);
}

function iptv_browse($opts) {
    $state = iptv_load();
    $kind = isset($opts['kind']) ? strtolower(trim($opts['kind'])) : '';
    $key = isset($opts['key']) ? $opts['key'] : '';
    $q = isset($opts['q']) ? trim($opts['q']) : '';
    $group = isset($opts['group']) ? trim($opts['group']) : '';
    $limit = isset($opts['limit']) ? max(1, min(200, (int)$opts['limit'])) : 80;
    $offset = isset($opts['offset']) ? max(0, (int)$opts['offset']) : 0;
    $hlsOnly = !isset($opts['hlsOnly']) || $opts['hlsOnly'];
    $activeOnly = !empty($opts['activeOnly']);
    $forceRefresh = !empty($opts['refresh']);

    // Legacy country/category browse (iptv-org CDN subsets)
    if ($kind === 'country' || $kind === 'category' || $kind === 'language') {
        if ($key === '') $key = ($kind === 'category') ? 'news' : 'uk';
        $parsed = iptv_parse_m3u(iptv_fetch_playlist($kind, $key, 86400, $forceRefresh));
        $source = iptv_playlist_url($kind, $key);
        $cache = iptv_cache_meta($kind, $key);
        $feedId = $kind;
        $feedLabel = $kind . '/' . $key;
        $channelsRaw = $parsed['channels'];
        foreach ($channelsRaw as &$ch) {
            $ch['sourceFeedId'] = $feedId;
            $ch['sourceFeedName'] = $feedLabel;
        }
        unset($ch);
        $epgUrls = $parsed['epgUrls'];
        $sources = array($source);
    } else {
        $resolved = iptv_resolve_browse_feeds($opts, $state);
        $feedErrors = array();
        $channelsRaw = array();
        $epgUrls = array();
        $sources = array();
        $seenUrl = array();
        $cacheParts = array();
        foreach ($resolved['feeds'] as $feed) {
            try {
                $parsed = iptv_feed_index($feed, $forceRefresh);
            } catch (Exception $e) {
                $feedErrors[$feed['id']] = $e->getMessage();
                continue;
            }
            $sources[] = $feed['url'];
            $cacheParts[$feed['id']] = iptv_cache_meta_feed($feed['id']);
            foreach ($parsed['epgUrls'] as $u) $epgUrls[] = $u;
            foreach ($parsed['channels'] as $ch) {
                $ch['sourceFeedId'] = $feed['id'];
                $ch['sourceFeedName'] = $feed['name'];
                $u = isset($ch['url']) ? $ch['url'] : '';
                if ($u !== '' && isset($seenUrl[$u])) continue;
                if ($u !== '') $seenUrl[$u] = true;
                $channelsRaw[] = $ch;
            }
        }
        $epgUrls = array_values(array_unique($epgUrls));
        $feedId = $resolved['feedId'];
        $cache = count($cacheParts) === 1 ? reset($cacheParts) : array('feeds' => $cacheParts);
        $source = count($sources) === 1 ? $sources[0] : ('all:' . count($sources) . ' feeds');
        $kind = 'feed';
        $key = $feedId;
    }

    $fav = $state;
    $favIds = array();
    foreach ($fav['channels'] as $c) {
        $favIds[$c['id'] . '|' . (isset($c['tvgId']) ? $c['tvgId'] : '')] = true;
        $favIds[$c['id']] = true;
    }

    $country = isset($opts['country']) ? strtoupper(trim((string)$opts['country'])) : '';
    if ($country === 'UK') $country = 'GB';
    $category = isset($opts['category']) ? trim((string)$opts['category']) : '';
    $rows = array();
    $facetRows = array();
    foreach ($channelsRaw as $ch) {
        if (!isset($ch['country'])) $ch['country'] = iptv_channel_country($ch);
        if (!isset($ch['categories'])) $ch['categories'] = iptv_channel_categories($ch['group']);
        if ($hlsOnly && !iptv_url_likely_hls($ch['url'])) {
            continue;
        }
        if ($group !== '' && stripos($ch['group'], $group) === false) {
            continue;
        }
        if ($q !== '' && !iptv_channel_matches_query($ch, $q)) {
            continue;
        }
        $facetRows[] = $ch;
        if ($country !== '' && ($country === '?' ? $ch['country'] !== '' : $ch['country'] !== $country)) continue;
        if ($category !== '' && !in_array(strtolower($category), array_map('strtolower', $ch['categories']), true)) continue;
        $ch['inLineup'] = isset($favIds[$ch['id']]) || isset($favIds[$ch['id'] . '|' . $ch['tvgId']]);
        $rows[] = $ch;
    }

    // status lookup only for the page we return when no status filter is active (big feeds)
    if (!$activeOnly) {
        $page = iptv_attach_status(array_slice($rows, $offset, $limit));
        array_splice($rows, $offset, count($page), $page);
    } else {
        $rows = iptv_attach_status($rows);
    }
    if ($activeOnly) {
        $filtered = array();
        foreach ($rows as $ch) {
            if (isset($ch['streamStatus']) && $ch['streamStatus'] === 'bad') continue;
            $filtered[] = $ch;
        }
        $rows = $filtered;
    }

    $total = count($rows);
    $slice = array_slice($rows, $offset, $limit);
    return array(
        'ok' => true,
        'kind' => $kind !== '' ? $kind : 'feed',
        'key' => $key,
        'feed' => isset($feedId) ? $feedId : $key,
        'q' => $q,
        'group' => $group,
        'activeOnly' => $activeOnly,
        'refreshed' => $forceRefresh,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'playlistEpgUrls' => $epgUrls,
        'channels' => $slice,
        'country' => $country,
        'category' => $category,
        'facets' => iptv_facets($facetRows),
        'feedErrors' => isset($feedErrors) ? $feedErrors : array(),
        'source' => $source,
        'sources' => $sources,
        'cache' => $cache,
        'activeFeedId' => isset($state['activeFeedId']) ? $state['activeFeedId'] : 'all',
        'feeds' => $state['feeds'],
    );
}

function iptv_find_fav($state, $id) {
    foreach ($state['channels'] as $ch) {
        if ($ch['id'] === $id || (isset($ch['tvgId']) && $ch['tvgId'] === $id)) {
            return $ch;
        }
    }
    return null;
}

function iptv_normalize_channel($incoming) {
    if (!is_array($incoming)) {
        throw new InvalidArgumentException('Invalid channel');
    }
    $url = isset($incoming['url']) ? trim($incoming['url']) : '';
    $name = isset($incoming['name']) ? trim($incoming['name']) : '';
    if ($url === '' || $name === '') {
        throw new InvalidArgumentException('Channel needs name and url');
    }
    if (!preg_match('#^https?://#i', $url)) {
        throw new InvalidArgumentException('Channel url must be http(s)');
    }
    $tvgId = isset($incoming['tvgId']) ? trim($incoming['tvgId']) : '';
    $id = isset($incoming['id']) ? trim($incoming['id']) : '';
    if ($id === '' && $tvgId !== '') {
        $id = strpos($tvgId, '@') !== false ? explode('@', $tvgId, 2)[0] : $tvgId;
    }
    if ($id === '') {
        $id = 'iptv-' . substr(sha1($url), 0, 12);
    }
    $id = preg_replace('/[^A-Za-z0-9._@-]+/', '', $id);
    return array(
        'id' => $id,
        'tvgId' => $tvgId,
        'feed' => isset($incoming['feed']) ? $incoming['feed'] : '',
        'sourceFeedId' => isset($incoming['sourceFeedId']) ? $incoming['sourceFeedId'] : '',
        'name' => $name,
        'logo' => isset($incoming['logo']) ? $incoming['logo'] : '',
        'group' => isset($incoming['group']) ? $incoming['group'] : '',
        'url' => $url,
        'quality' => isset($incoming['quality']) ? $incoming['quality'] : '',
        'labels' => isset($incoming['labels']) && is_array($incoming['labels']) ? $incoming['labels'] : array(),
        'userAgent' => !empty($incoming['userAgent']) ? $incoming['userAgent'] : null,
        'referrer' => !empty($incoming['referrer']) ? $incoming['referrer'] : null,
        'enabled' => !isset($incoming['enabled']) || !!$incoming['enabled'],
        'addedAt' => isset($incoming['addedAt']) ? $incoming['addedAt'] : gmdate('c'),
    );
}

function iptv_add_channel($incoming) {
    $state = iptv_load();
    $ch = iptv_normalize_channel($incoming);
    $found = false;
    foreach ($state['channels'] as $i => $existing) {
        if ($existing['id'] === $ch['id'] && (string)(isset($existing['tvgId']) ? $existing['tvgId'] : '') === (string)$ch['tvgId']) {
            $ch['addedAt'] = isset($existing['addedAt']) ? $existing['addedAt'] : $ch['addedAt'];
            $state['channels'][$i] = $ch;
            $found = true;
            break;
        }
        if ($existing['id'] === $ch['id'] && $ch['tvgId'] === '') {
            $ch['addedAt'] = isset($existing['addedAt']) ? $existing['addedAt'] : $ch['addedAt'];
            $state['channels'][$i] = $ch;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $state['channels'][] = $ch;
    }
    iptv_save($state);
    return array('ok' => true, 'channel' => $ch, 'count' => count($state['channels']));
}

function iptv_remove_channel($id) {
    $state = iptv_load();
    $before = count($state['channels']);
    $state['channels'] = array_values(array_filter($state['channels'], function ($c) use ($id) {
        return !($c['id'] === $id || (isset($c['tvgId']) && $c['tvgId'] === $id));
    }));
    iptv_save($state);
    return array('ok' => true, 'removed' => $before - count($state['channels']), 'count' => count($state['channels']));
}

function iptv_update_meta($incoming) {
    $state = iptv_load();
    if (isset($incoming['epgUrls']) && is_array($incoming['epgUrls'])) {
        $urls = array();
        foreach ($incoming['epgUrls'] as $u) {
            $u = trim((string)$u);
            if ($u !== '' && preg_match('#^https?://#i', $u)) $urls[] = $u;
        }
        $state['epgUrls'] = array_values(array_unique($urls));
    }
    if (isset($incoming['defaultCountry'])) {
        $state['defaultCountry'] = strtolower(preg_replace('/[^a-z]/i', '', $incoming['defaultCountry']));
    }
    if (isset($incoming['numberStart'])) {
        $state['numberStart'] = max(1, (int)$incoming['numberStart']);
    }
    if (isset($incoming['feeds']) && is_array($incoming['feeds'])) {
        $norm = array();
        $seen = array();
        foreach ($incoming['feeds'] as $f) {
            $nf = iptv_normalize_feed($f);
            $base = $nf['id'];
            $n = 2;
            while (isset($seen[$nf['id']])) {
                $nf['id'] = $base . '-' . $n;
                $n++;
            }
            $seen[$nf['id']] = true;
            $norm[] = $nf;
        }
        // zero sources is allowed; removed defaults stay removed (seededDefaults is kept)
        $state['feeds'] = $norm;
    }
    if (isset($incoming['activeFeedId'])) {
        $aid = trim((string)$incoming['activeFeedId']);
        if ($aid === '') $aid = 'all';
        if ($aid !== 'all') {
            $ok = false;
            foreach ($state['feeds'] as $f) {
                if ($f['id'] === $aid) { $ok = true; break; }
            }
            if (!$ok) throw new InvalidArgumentException('activeFeedId not in feeds: ' . $aid);
        }
        $state['activeFeedId'] = $aid;
    }
    if (isset($incoming['channels']) && is_array($incoming['channels'])) {
        $norm = array();
        foreach ($incoming['channels'] as $c) {
            $norm[] = iptv_normalize_channel($c);
        }
        $state['channels'] = $norm;
    }
    iptv_save($state);
    return array('ok' => true, 'iptv' => $state);
}

function iptv_lineup_channels($state = null) {
    if ($state === null) $state = iptv_load();
    $start = (int)$state['numberStart'];
    $out = array();
    $n = 0;
    foreach ($state['channels'] as $ch) {
        if (isset($ch['enabled']) && !$ch['enabled']) continue;
        $out[] = array(
            'id' => $ch['id'],
            'tvgId' => isset($ch['tvgId']) ? $ch['tvgId'] : '',
            'number' => $start + $n,
            'name' => $ch['name'],
            'logo' => isset($ch['logo']) ? $ch['logo'] : '',
            'group' => isset($ch['group']) ? $ch['group'] : '',
            'url' => $ch['url'],
            'quality' => isset($ch['quality']) ? $ch['quality'] : '',
            'labels' => isset($ch['labels']) ? $ch['labels'] : array(),
            'userAgent' => isset($ch['userAgent']) ? $ch['userAgent'] : null,
            'referrer' => isset($ch['referrer']) ? $ch['referrer'] : null,
            'kind' => 'iptv',
        );
        $n++;
    }
    return $out;
}

function iptv_countries() {
    return array(
        array('code' => 'uk', 'name' => 'United Kingdom'),
        array('code' => 'us', 'name' => 'United States'),
        array('code' => 'ie', 'name' => 'Ireland'),
        array('code' => 'fr', 'name' => 'France'),
        array('code' => 'de', 'name' => 'Germany'),
        array('code' => 'es', 'name' => 'Spain'),
        array('code' => 'it', 'name' => 'Italy'),
        array('code' => 'nl', 'name' => 'Netherlands'),
        array('code' => 'au', 'name' => 'Australia'),
        array('code' => 'ca', 'name' => 'Canada'),
        array('code' => 'in', 'name' => 'India'),
        array('code' => 'jp', 'name' => 'Japan'),
        array('code' => 'kr', 'name' => 'South Korea'),
        array('code' => 'qa', 'name' => 'Qatar'),
        array('code' => 'int', 'name' => 'International'),
    );
}

function iptv_categories() {
    return array('news', 'sports', 'movies', 'music', 'documentary', 'entertainment', 'kids', 'education', 'culture', 'business', 'comedy', 'religious', 'weather', 'general');
}


function iptv_status_file() {
    return STREAM_DATA . '/live/iptv_status.json';
}

/** Channel probe/health status: DB document 'live_iptv_status' (was data/live/iptv_status.json). */
function iptv_status_load() {
    $empty = array('version' => 1, 'entries' => array());
    $state = \Vaultcast\Repository::document('live_iptv_status', null);
    if ($state === null) {
        // One-time import of the legacy file; Migrations does the same in bulk.
        $state = iptv_status_import_legacy();
    }
    if (!is_array($state) || !isset($state['entries']) || !is_array($state['entries'])) return $empty;
    return $state;
}

function iptv_status_import_legacy() {
    $file = iptv_status_file();
    if (!is_file($file)) return null;
    $state = json_decode((string)file_get_contents($file), true);
    if (!is_array($state) || !isset($state['entries']) || !is_array($state['entries'])) return null;
    if (\Vaultcast\Repository::document('live_iptv_status', null) === null) {
        \Vaultcast\Repository::putDocument('live_iptv_status', $state);
    }
    @rename($file, $file . '.migrated');
    return $state;
}

function iptv_status_save($state) {
    \Vaultcast\Repository::putDocument('live_iptv_status', $state);
    return $state;
}

function iptv_status_key($url) {
    return substr(sha1($url), 0, 24);
}

function iptv_status_lookup($url) {
    $state = iptv_status_load();
    $key = iptv_status_key($url);
    if (!isset($state['entries'][$key])) return null;
    $e = $state['entries'][$key];
    $e['key'] = $key;
    return $e;
}

/**
 * On-demand probe of a single stream URL. HEAD first; GET Range fallback.
 * Runs only when an administrator requests a stream check.
 */
function iptv_probe_url($url, $timeout = 8) {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        throw new InvalidArgumentException('Invalid stream url');
    }
    $started = microtime(true);
    $ok = false;
    $code = 0;
    $method = 'HEAD';
    $err = '';
    $finalUrl = $url;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . ' (+iptv-probe)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => array('Accept: */*'),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 405 || $code === 501) {
            $method = 'GET';
            $received = 0;
            curl_setopt_array($ch, array(
                CURLOPT_NOBODY => false,
                CURLOPT_RANGE => '0-1023',
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$received) {
                    $received += strlen($chunk);
                    return $received > 1024 ? 0 : strlen($chunk);
                },
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($received > 0 && $code >= 200 && $code < 400) $body = '';
        }
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($body === false) $err = curl_error($ch);
        curl_close($ch);
        $ok = ($body !== false && $code >= 200 && $code < 400);
    } else {
        $method = 'GET';
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => "User-Agent: Vaultcast/" . STREAM_VERSION . "\nAccept: */*\nRange: bytes=0-1023\n",
                'follow_location' => 1,
                'ignore_errors' => true,
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $body = @file_get_contents($url, false, $ctx, 0, 1024);
        if (isset($http_response_header) && is_array($http_response_header) && count($http_response_header)) {
            if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $code = (int)$m[1];
            }
        }
        $ok = ($body !== false && $code >= 200 && $code < 400) || ($body !== false && $code === 0);
        if ($body === false) $err = 'fetch failed';
    }

    $ms = (int) round((microtime(true) - $started) * 1000);
    $entry = array(
        'url' => $url,
        'ok' => $ok,
        'code' => $code,
        'ms' => $ms,
        'method' => $method,
        'error' => $err !== '' ? $err : null,
        'checkedAt' => gmdate('c'),
        'finalUrl' => $finalUrl,
    );

    $state = iptv_status_load();
    $key = iptv_status_key($url);
    $state['entries'][$key] = array(
        'ok' => $entry['ok'],
        'code' => $entry['code'],
        'ms' => $entry['ms'],
        'method' => $entry['method'],
        'error' => $entry['error'],
        'checkedAt' => $entry['checkedAt'],
        'url' => $url,
    );
    if (count($state['entries']) > 5000) {
        uasort($state['entries'], function ($a, $b) {
            return strcmp(isset($a['checkedAt']) ? $a['checkedAt'] : '', isset($b['checkedAt']) ? $b['checkedAt'] : '');
        });
        $state['entries'] = array_slice($state['entries'], -4000, null, true);
    }
    iptv_status_save($state);
    $entry['key'] = $key;
    $entry['status'] = $ok ? 'ok' : 'bad';
    return $entry;
}

/** Probe selected channels only (max 25). Never the full catalogue. */
function iptv_probe_channels($items) {
    if (!is_array($items)) $items = array();
    $items = array_slice($items, 0, 25);
    $out = array();
    foreach ($items as $item) {
        $url = '';
        $id = '';
        if (is_string($item)) {
            $id = $item;
        } elseif (is_array($item)) {
            $id = isset($item['id']) ? $item['id'] : '';
            $url = isset($item['url']) ? $item['url'] : '';
        }
        if ($url === '' && $id !== '') {
            $fav = iptv_find_fav(iptv_load(), $id);
            if ($fav && !empty($fav['url'])) $url = $fav['url'];
        }
        if ($url === '') {
            $out[] = array('id' => $id, 'ok' => false, 'error' => 'Missing url', 'status' => 'bad');
            continue;
        }
        try {
            $r = iptv_probe_url($url);
            $r['id'] = $id;
            $out[] = $r;
        } catch (Exception $e) {
            $out[] = array('id' => $id, 'url' => $url, 'ok' => false, 'error' => $e->getMessage(), 'status' => 'bad', 'checkedAt' => gmdate('c'));
        }
    }
    return array('ok' => true, 'results' => $out, 'probed' => count($out));
}

function iptv_toggle_channel($id, $enabled) {
    $state = iptv_load();
    $found = false;
    foreach ($state['channels'] as $i => $ch) {
        if ($ch['id'] === $id || (isset($ch['tvgId']) && $ch['tvgId'] === $id)) {
            $state['channels'][$i]['enabled'] = !!$enabled;
            $found = true;
            $id = $ch['id'];
            break;
        }
    }
    if (!$found) {
        throw new RuntimeException('Channel not in favourites: ' . $id);
    }
    iptv_save($state);
    return array('ok' => true, 'id' => $id, 'enabled' => !!$enabled, 'iptv' => $state);
}

function iptv_attach_status($channels) {
    $cache = iptv_status_load();
    $entries = $cache['entries'];
    foreach ($channels as &$ch) {
        $url = isset($ch['url']) ? $ch['url'] : '';
        $key = $url !== '' ? iptv_status_key($url) : '';
        if ($key !== '' && isset($entries[$key])) {
            $e = $entries[$key];
            $ch['streamStatus'] = !empty($e['ok']) ? 'ok' : 'bad';
            $ch['streamCheckedAt'] = isset($e['checkedAt']) ? $e['checkedAt'] : null;
            $ch['streamCode'] = isset($e['code']) ? $e['code'] : null;
            $ch['streamMs'] = isset($e['ms']) ? $e['ms'] : null;
        } else {
            $ch['streamStatus'] = 'unknown';
            $ch['streamCheckedAt'] = null;
            $ch['streamCode'] = null;
            $ch['streamMs'] = null;
        }
    }
    unset($ch);
    return $channels;
}


function iptv_favourites_with_status($state = null) {
    if ($state === null) $state = iptv_load();
    $chs = iptv_attach_status($state['channels']);
    $start = (int)$state['numberStart'];
    $n = 0;
    foreach ($chs as &$ch) {
        $ch['number'] = $start + $n;
        $n++;
        if (!isset($ch['enabled'])) $ch['enabled'] = true;
    }
    unset($ch);
    return $chs;
}
