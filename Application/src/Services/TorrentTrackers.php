<?php
declare(strict_types=1);

/**
 * Tracker handling for torrents handed to Transmission.
 * - Magnets lifted from RSS/HTML keep every tr= (HTML entities such as &amp; decoded, cut at quotes/tags).
 * - tr= values are read without parse_str(), which keeps only the LAST of repeated keys.
 * - Hash-only magnets get the feed's trackers plus the configured defaults; when the feed also offers a
 *   .torrent file, that file (with its own announce list) is sent instead of a tracker-less magnet.
 */
final class TorrentTrackers {
    /** Public trackers used when Settings has no default_trackers. */
    const BUILTIN = [
        'udp://tracker.opentrackr.org:1337/announce',
        'udp://open.stealth.si:80/announce',
        'udp://tracker.torrent.eu.org:451/announce',
        'udp://exodus.desync.com:6969/announce',
        'udp://open.demonii.com:1337/announce',
        'udp://tracker.openbittorrent.com:6969/announce',
        'udp://explodie.org:6969/announce',
        'udp://tracker.dler.org:6969/announce',
    ];

    /** First magnet URI in a text blob (HTML, CDATA, plain), entity-decoded and trimmed. */
    public static function extractMagnet(string $text): string {
        if (!preg_match('#magnet:\?[^\s"\'<>]+#i', $text, $m)) return '';
        $mag = $m[0];
        for ($i = 0; $i < 3; $i++) {
            $d = html_entity_decode($mag, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($d === $mag) break;
            $mag = $d;
        }
        return rtrim($mag, ').,;');
    }

    public static function isTrackerUrl(string $u): bool {
        return (bool)preg_match('#^(https?|udp|wss?)://[^\s/?\#]+#i', $u) && strlen($u) <= 2048 && strpos($u, "\0") === false;
    }

    /** All tr= values of a magnet, decoded, in order, de-duplicated. */
    public static function trackersOf(string $magnet): array {
        $q = strpos($magnet, '?');
        if ($q === false) return [];
        $out = [];
        foreach (explode('&', substr($magnet, $q + 1)) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) !== 2) continue;
            $k = strtolower(rawurldecode($kv[0]));
            if ($k !== 'tr' && !preg_match('/^tr\.\d+$/', $k)) continue;
            $v = trim(rawurldecode(str_replace('+', '%20', $kv[1])));
            if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
        }
        return $out;
    }

    /** Normalise a tracker list (array or newline/comma text). */
    public static function clean($list): array {
        if (is_string($list)) $list = preg_split('/[\s,]+/', $list);
        $out = [];
        foreach ((array)$list as $t) {
            $t = trim((string)$t);
            if ($t !== '' && self::isTrackerUrl($t) && !in_array($t, $out, true)) $out[] = $t;
        }
        return $out;
    }

    /** Append trackers not already in the magnet. Non-magnets are returned unchanged. */
    public static function withTrackers(string $magnet, array $trackers): string {
        if (stripos($magnet, 'magnet:?') !== 0) return $magnet;
        $have = self::trackersOf($magnet);
        foreach (self::clean($trackers) as $t) {
            if (in_array($t, $have, true)) continue;
            $magnet .= '&tr=' . rawurlencode($t);
            $have[] = $t;
        }
        return $magnet;
    }

    public static function isTorrentUrl(string $u): bool {
        return (bool)preg_match('#^https?://#i', $u) && (bool)preg_match('#\.torrent$#i', (string)parse_url($u, PHP_URL_PATH));
    }

    /** What to give torrent-add: a magnet with trackers, else a .torrent URL, else whatever exists. */
    public static function chooseSource(string $magnet, string $url): string {
        $magnet = trim($magnet); $url = trim($url);
        if ($magnet !== '' && self::trackersOf($magnet)) return $magnet;
        if ($url !== '' && self::isTorrentUrl($url)) return $url;
        return $magnet !== '' ? $magnet : $url;
    }
}
