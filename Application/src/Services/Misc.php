<?php
declare(strict_types=1);

/** Misc → Board (dashboards and widget data) and Ambient (screensaver stills, webcams). */
final class Misc {
    const WIDGETS = ['clock', 'weather', 'calendar', 'reminders', 'media', 'recordings', 'downloads', 'health', 'recent', 'nowplaying', 'notes', 'slideshow', 'rss', 'drop', 'leaderboard', 'frame', 'webcams'];
    /** Older or alternative spellings of widget types; saved boards using them keep working and are stored under the current key. */
    const WIDGET_ALIASES = ['recently_added' => 'recent', 'recentlyadded' => 'recent', 'recently-added' => 'recent', 'now_playing' => 'nowplaying', 'now-playing' => 'nowplaying',
        'bins' => 'reminders', 'binday' => 'reminders', 'bin_day' => 'reminders', 'bin-day' => 'reminders', 'picture_frame' => 'frame', 'pictureframe' => 'frame', 'webcam' => 'webcams',
        'nas_health' => 'health', 'nashealth' => 'health', 'news' => 'rss', 'note' => 'notes', 'slides' => 'slideshow', 'recording' => 'recordings', 'download' => 'downloads'];
    public static function widgetType(string $t): string {
        $k = strtolower(trim($t));
        return in_array($k, self::WIDGETS, true) ? $k : (self::WIDGET_ALIASES[$k] ?? '');
    }

    // ---------------------------------------------------------------- boards
    public static function boards(array $user): array {
        Arcade::signed($user);
        $doc = (array)\Vaultcast\Repository::document('misc-boards:' . $user['id'], []);
        return ['ok' => true, 'boards' => array_values((array)($doc['boards'] ?? [])), 'default' => (string)($doc['default'] ?? ''), 'rotate' => (int)($doc['rotate'] ?? 0), 'widgets' => self::WIDGETS, 'aliases' => self::WIDGET_ALIASES,
            'features' => ['live' => !empty(Store::settings()['features']['live']), 'downloads' => !empty(Store::settings()['features']['downloads']), 'library' => AreaAccess::allowed($user, 'library'), 'admin' => ($user['role'] ?? '') === 'admin']];
    }
    private static function clean($v, int $depth = 0) {
        if ($depth > 5) return null;
        if (is_array($v)) { $o = []; $i = 0; foreach ($v as $k => $x) { if (++$i > 200) break; $o[is_int($k) ? $k : mb_substr((string)$k, 0, 40)] = self::clean($x, $depth + 1); } return $o; }
        if (is_string($v)) return mb_substr($v, 0, 4000);
        if (is_bool($v) || is_int($v) || is_float($v) || $v === null) return $v;
        return null;
    }
    public static function saveBoards(array $user, array $body): array {
        Arcade::signed($user);
        $boards = [];
        foreach (array_slice((array)($body['boards'] ?? []), 0, 12) as $b) {
            if (!is_array($b)) continue;
            $id = preg_match('/^[a-z0-9]{6,16}$/', (string)($b['id'] ?? '')) ? (string)$b['id'] : bin2hex(random_bytes(5));
            $widgets = [];
            foreach (array_slice((array)($b['widgets'] ?? []), 0, 40) as $w) {
                if (!is_array($w) || ($type = self::widgetType((string)($w['type'] ?? ''))) === '') continue;
                $widgets[] = ['id' => preg_match('/^[a-z0-9]{4,16}$/', (string)($w['id'] ?? '')) ? (string)$w['id'] : bin2hex(random_bytes(4)), 'type' => $type,
                    'x' => max(0, min(23, (int)($w['x'] ?? 0))), 'y' => max(0, min(400, (int)($w['y'] ?? 0))), 'w' => max(1, min(24, (int)($w['w'] ?? 4))), 'h' => max(1, min(24, (int)($w['h'] ?? 3))),
                    'settings' => Pictures::scrub((string)$user['id'], self::clean((array)($w['settings'] ?? [])))];
                // Free placement: positions are stored exactly as sent, only clamped so a widget never spills past the 24 columns.
                $last = count($widgets) - 1; $widgets[$last]['x'] = min($widgets[$last]['x'], 24 - $widgets[$last]['w']);
            }
            $boards[] = ['id' => $id, 'name' => mb_substr(trim((string)($b['name'] ?? '')), 0, 40) ?: 'Board', 'theme' => in_array($b['theme'] ?? '', ['glass', 'solid', 'clear'], true) ? $b['theme'] : 'glass',
                'background' => (string)Pictures::scrub((string)$user['id'], self::background((string)($b['background'] ?? ''))), 'color' => self::colour((string)($b['color'] ?? '')), 'locked' => !empty($b['locked']), 'widgets' => $widgets];
        }
        $json = json_encode($boards);
        if (strlen((string)$json) > 400000) throw new DomainException('These boards are too large to save. Remove some widgets or long notes.');
        $default = (string)($body['default'] ?? '');
        if (!in_array($default, array_column($boards, 'id'), true)) $default = $boards[0]['id'] ?? '';
        $rotate = (int)($body['rotate'] ?? 0); $rotate = $rotate <= 0 ? 0 : max(15, min(3600, $rotate));
        \Vaultcast\Repository::putDocument('misc-boards:' . $user['id'], ['boards' => $boards, 'default' => $default, 'rotate' => $rotate, 'updated_at' => time()]);
        if ($legacy = Webcams::fromBoards($boards)) Webcams::record((string)$user['id'], $legacy);
        Achievements::boards((string)$user['id'], $boards);
        return ['ok' => true, 'boards' => $boards, 'default' => $default, 'rotate' => $rotate];
    }
    /** Board backgrounds end up inside CSS url(): only plain http(s) or same-site paths without quotes, parentheses or escapes. */
    private static function background(string $v): string {
        $v = trim($v);
        if ($v === '' || strlen($v) > 400) return '';
        if (!preg_match('#^(https?://|/)[^\s"\'()<>\\\\]+$#i', $v) || strpos($v, '//') === 0) return '';
        return $v;
    }
    private static function colour(string $v): string {
        $v = trim($v);
        return preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $v) ? strtolower($v) : '';
    }

    // ---------------------------------------------------------------- widget data
    private static function cached(string $key, int $ttl, callable $fn): array {
        $hit = \Vaultcast\Repository::cacheGet('misc-w:' . sha1($key));
        if (is_array($hit) && ($hit['at'] ?? 0) > time() - $ttl) return $hit['data'];
        try { $data = $fn(); } catch (DomainException $e) { if (is_array($hit)) return $hit['data'] + ['stale' => true]; throw $e; }
        \Vaultcast\Repository::cachePut('misc-w:' . sha1($key), ['at' => time(), 'data' => $data], max($ttl * 6, 3600));
        return $data;
    }
    private static function rate(array $user, string $what, int $perMinute): void {
        $k = 'misc-rate:' . $what . ':' . ($user['id'] ?? 'x') . ':' . date('YmdHi');
        $n = (int)((\Vaultcast\Repository::cacheGet($k) ?: ['n' => 0])['n']);
        if ($n >= $perMinute) throw new DomainException('Too many requests. Wait a moment.');
        \Vaultcast\Repository::cachePut($k, ['n' => $n + 1], 120);
    }
    public static function geocode(array $user, string $q): array {
        $q = trim($q); if (mb_strlen($q) < 2) return ['ok' => true, 'places' => []];
        self::rate($user, 'geo', 20);
        return ['ok' => true] + self::cached('geo:' . mb_strtolower($q), 86400 * 7, function () use ($q) {
            $j = WebFetch::json('https://geocoding-api.open-meteo.com/v1/search?count=6&language=en&format=json&name=' . rawurlencode($q), 6);
            $out = [];
            foreach ((array)($j['results'] ?? []) as $r) $out[] = ['name' => $r['name'] ?? '', 'region' => trim(($r['admin1'] ?? '') . ', ' . ($r['country'] ?? ''), ', '), 'lat' => (float)$r['latitude'], 'lon' => (float)$r['longitude'], 'tz' => $r['timezone'] ?? ''];
            return ['places' => $out];
        });
    }
    public static function weather(array $user, float $lat, float $lon, string $units): array {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) throw new DomainException('Choose a location for the weather.');
        $lat = round($lat, 2); $lon = round($lon, 2); $f = $units === 'f';
        return ['ok' => true] + self::cached('wx:' . $lat . ':' . $lon . ':' . ($f ? 'f' : 'c'), 600, function () use ($lat, $lon, $f) {
            $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon . '&timezone=auto&forecast_days=6'
                . '&current=temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m,is_day,precipitation'
                . '&hourly=temperature_2m,precipitation_probability,weather_code&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset'
                . ($f ? '&temperature_unit=fahrenheit&wind_speed_unit=mph' : '');
            $j = WebFetch::json($url, 6);
            if (!$j || empty($j['current'])) throw new DomainException('The weather service did not answer.');
            $hours = [];
            $now = substr((string)$j['current']['time'], 0, 13);
            foreach ((array)($j['hourly']['time'] ?? []) as $i => $t) { if (substr($t, 0, 13) < $now) continue; $hours[] = ['time' => $t, 'temp' => $j['hourly']['temperature_2m'][$i] ?? null, 'rain' => $j['hourly']['precipitation_probability'][$i] ?? null, 'code' => $j['hourly']['weather_code'][$i] ?? null]; if (count($hours) >= 12) break; }
            $days = [];
            foreach ((array)($j['daily']['time'] ?? []) as $i => $t) $days[] = ['date' => $t, 'code' => $j['daily']['weather_code'][$i] ?? null, 'max' => $j['daily']['temperature_2m_max'][$i] ?? null, 'min' => $j['daily']['temperature_2m_min'][$i] ?? null, 'rain' => $j['daily']['precipitation_probability_max'][$i] ?? null, 'sunrise' => $j['daily']['sunrise'][$i] ?? null, 'sunset' => $j['daily']['sunset'][$i] ?? null];
            $c = $j['current'];
            return ['current' => ['temp' => $c['temperature_2m'] ?? null, 'feels' => $c['apparent_temperature'] ?? null, 'humidity' => $c['relative_humidity_2m'] ?? null, 'code' => $c['weather_code'] ?? null, 'wind' => $c['wind_speed_10m'] ?? null, 'day' => !empty($c['is_day']), 'precip' => $c['precipitation'] ?? null],
                'hours' => $hours, 'days' => $days, 'units' => $f ? '°F' : '°C', 'wind_units' => $f ? 'mph' : 'km/h'];
        });
    }
    private static function external(array $user, string $url): string {
        $url = trim($url);
        if (strlen($url) > 2000 || !preg_match('#^(https?|webcal)://#i', $url)) throw new DomainException('Use an http(s) or webcal address.');
        return preg_replace('#^webcal://#i', 'https://', $url);
    }
    public static function rss(array $user, string $url): array {
        $url = self::external($user, $url); self::rate($user, 'rss', 30); $admin = ($user['role'] ?? '') === 'admin';
        return ['ok' => true] + self::cached('rss:' . $url, 900, function () use ($url, $admin) {
            $r = WebFetch::get($url, 8, 3000000, $admin);
            if ($r['status'] !== 200) throw new DomainException('That feed answered with HTTP ' . $r['status'] . '.');
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($r['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            libxml_use_internal_errors($prev);
            if (!$xml) throw new DomainException('That address is not an RSS or Atom feed.');
            $items = []; $title = '';
            if (isset($xml->channel)) {
                $title = (string)$xml->channel->title;
                foreach ($xml->channel->item as $i) { $items[] = ['title' => trim((string)$i->title), 'link' => (string)$i->link, 'date' => strtotime((string)$i->pubDate) ?: null]; if (count($items) >= 25) break; }
            } else {
                $title = (string)$xml->title;
                foreach ($xml->entry as $e) { $link = ''; foreach ($e->link as $l) { if ((string)$l['rel'] === '' || (string)$l['rel'] === 'alternate') { $link = (string)$l['href']; break; } } $items[] = ['title' => trim((string)$e->title), 'link' => $link, 'date' => strtotime((string)($e->updated ?: $e->published)) ?: null]; if (count($items) >= 25) break; }
            }
            $items = array_values(array_filter($items, function ($i) { return $i['title'] !== '' && ($i['link'] === '' || preg_match('#^https?://#i', $i['link'])); }));
            return ['title' => mb_substr($title, 0, 120), 'items' => $items];
        });
    }
    private static function icsDate(string $v, array $params): ?int {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', trim($v), $m)) return null;
        $allDay = !isset($m[4]) || $m[4] === '';
        try {
            $tz = !$allDay && empty($m[7]) && !empty($params['TZID']) ? new DateTimeZone(trim($params['TZID'], '"')) : (!empty($m[7]) ? new DateTimeZone('UTC') : null);
        } catch (Throwable $e) { $tz = null; }
        $d = new DateTime(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $allDay ? '00' : $m[4], $allDay ? '00' : $m[5], $allDay ? '00' : $m[6]), $tz ?: new DateTimeZone(date_default_timezone_get()));
        return $d->getTimestamp();
    }
    public static function ics(array $user, string $url, int $days): array {
        $url = self::external($user, $url); self::rate($user, 'ics', 20); $admin = ($user['role'] ?? '') === 'admin'; $days = max(1, min(60, $days));
        return ['ok' => true] + self::cached('ics:' . $url . ':' . $days . ':' . date('YmdH'), 900, function () use ($url, $admin, $days) {
            $r = WebFetch::get($url, 8, 4000000, $admin);
            if ($r['status'] !== 200 || strpos($r['body'], 'BEGIN:VCALENDAR') === false) throw new DomainException('That address is not an iCalendar (.ics) feed.');
            $text = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $r['body']));
            $from = strtotime('today'); $to = $from + $days * 86400; $events = [];
            foreach (explode('BEGIN:VEVENT', $text) as $i => $chunk) {
                if ($i === 0) continue; $chunk = explode('END:VEVENT', $chunk)[0]; $ev = []; $p = [];
                foreach (explode("\n", $chunk) as $line) {
                    if (!preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) continue;
                    $params = []; foreach (array_filter(explode(';', $m[2])) as $kv) { $x = explode('=', $kv, 2); if (count($x) === 2) $params[strtoupper($x[0])] = $x[1]; }
                    $ev[$m[1]] = $m[3]; $p[$m[1]] = $params;
                }
                if (empty($ev['DTSTART'])) continue;
                $start = self::icsDate($ev['DTSTART'], $p['DTSTART'] ?? []); if ($start === null) continue;
                $end = !empty($ev['DTEND']) ? (self::icsDate($ev['DTEND'], $p['DTEND'] ?? []) ?: $start) : $start;
                $allDay = !preg_match('/T\d{6}/', $ev['DTSTART']);
                $summary = str_replace(['\\n', '\\,', '\\;', '\\\\'], [' ', ',', ';', '\\'], (string)($ev['SUMMARY'] ?? 'Event'));
                $loc = str_replace(['\\n', '\\,', '\\;', '\\\\'], [' ', ',', ';', '\\'], (string)($ev['LOCATION'] ?? ''));
                $occ = [$start];
                if (!empty($ev['RRULE'])) {
                    $rule = []; foreach (explode(';', $ev['RRULE']) as $kv) { $x = explode('=', $kv, 2); if (count($x) === 2) $rule[strtoupper($x[0])] = $x[1]; }
                    $step = ['DAILY' => '+%d day', 'WEEKLY' => '+%d week', 'MONTHLY' => '+%d month', 'YEARLY' => '+%d year'][$rule['FREQ'] ?? ''] ?? null;
                    if ($step) {
                        $interval = max(1, (int)($rule['INTERVAL'] ?? 1)); $count = (int)($rule['COUNT'] ?? 0); $until = !empty($rule['UNTIL']) ? self::icsDate(substr($rule['UNTIL'], 0, 16), []) : null;
                        $occ = []; $t = $start; $n = 0;
                        while ($t < $to && $n < 2000) { if ($until && $t > $until) break; if ($count && $n >= $count) break; $occ[] = $t; $n++; $t = strtotime(sprintf($step, $interval), $t); }
                    }
                }
                $exdates = [];
                if (!empty($ev['EXDATE'])) foreach (explode(',', $ev['EXDATE']) as $x) { $ts = self::icsDate($x, $p['EXDATE'] ?? []); if ($ts) $exdates[$ts] = 1; }
                foreach ($occ as $o) { $e = $o + ($end - $start); if ($e < $from || $o >= $to || isset($exdates[$o])) continue; $events[] = ['start' => $o, 'end' => $e, 'all_day' => $allDay, 'title' => mb_substr($summary, 0, 160), 'location' => mb_substr($loc, 0, 160)]; }
                if (count($events) > 400) break;
            }
            usort($events, function ($a, $b) { return $a['start'] <=> $b['start']; });
            return ['events' => array_slice($events, 0, 60)];
        });
    }
    public static function health(array $user): array {
        if (($user['role'] ?? '') !== 'admin') return ['ok' => true, 'limited' => true, 'status' => 'online', 'time' => time()];
        return ['ok' => true] + self::cached('health', 15, function () {
            $s = SystemAdmin::status();
            $keep = []; foreach (['version', 'uptime_seconds', 'uptime_human', 'load', 'cpu', 'cpu_percent', 'memory', 'mem', 'disk', 'media_disk', 'data_disk', 'temperature', 'temp', 'services'] as $k) if (array_key_exists($k, $s)) $keep[$k] = $s[$k];
            if (isset($keep['services'])) $keep['services'] = array_map(function ($x) { return ['name' => $x['label'] ?? $x['name'] ?? $x['unit'] ?? '', 'active' => $x['active'] ?? '']; }, (array)$keep['services']);
            return $keep + ['time' => time()];
        });
    }
    public static function recent(array $user, int $n): array {
        if (!AreaAccess::allowed($user, 'library')) return ['ok' => true, 'items' => []];
        $n = max(4, min(30, $n));
        // Parental controls: build a longer shared list, then keep only what this viewer may see.
        if (Parental::restricted($user)) {
            $all = self::recent(['role' => 'admin', 'id' => ''] + $user, 30);
            $items = array_values(array_filter((array)($all['items'] ?? []), static function ($r) use ($user) { return Parental::allows($user, ['id' => $r['id'], 'title' => $r['title'], 'media_kind' => 'video', 'show_key' => $r['show_key'] ?? '']); }));
            return ['ok' => true, 'items' => array_slice($items, 0, $n)];
        }
        // The same list for every viewer with Library access: build it at most once a minute.
        return ['ok' => true] + self::cached('recent:' . $n, 60, function () use ($n) {
        $rows = \Vaultcast\Repository::rows("SELECT payload FROM vaultcast_items WHERE media_kind='video' ORDER BY added_at DESC, id LIMIT 120");
        $out = []; $shows = [];
        foreach ($rows as $it) {
            $card = Catalogue::publicItem($it, false);
            $key = (string)($card['show_key'] ?? '');
            if ($key !== '') { if (isset($shows[$key])) continue; $shows[$key] = 1; }
            $out[] = ['id' => $card['id'], 'title' => $key !== '' ? ($card['show_title'] ?? $card['show'] ?? $card['title']) : $card['title'], 'subtitle' => $key !== '' ? ($card['title'] ?? '') : (string)($card['year'] ?? ''), 'show_key' => $key ?: null,
                'type' => $card['type'] ?? '', 'poster' => '/api/items/' . rawurlencode((string)$card['id']) . '/poster?fetch=0&variant=medium', 'added_at' => (int)($it['added_at'] ?? $it['mtime'] ?? 0)];
            if (count($out) >= $n) break;
        }
        return ['items' => $out];
        });
    }
    /** Who is watching right now: admins see everyone, others see themselves and friends. */
    public static function nowPlaying(array $user): array {
        if (!AreaAccess::allowed($user, 'library')) return ['ok' => true, 'sessions' => []];
        // One shared snapshot every 15 s for all viewers (progress has no updated_at index), then filtered per viewer.
        $all = self::cached('nowplaying', 15, function () {
            $rows = Arcade::query("SELECT p.user_id, p.item_id, p.updated_at, p.payload, i.title, i.show_title FROM vaultcast_progress p JOIN vaultcast_items i ON i.id=p.item_id WHERE p.updated_at>=? AND i.media_kind='video' ORDER BY p.updated_at DESC LIMIT 40", [time() - 180])->fetchAll(PDO::FETCH_ASSOC);
            $people = Arcade::people(array_values(array_unique(array_column($rows, 'user_id')))); $out = [];
            foreach ($rows as $r) {
                $p = json_decode((string)$r['payload'], true) ?: [];
                $pos = (float)($p['position'] ?? $p['t'] ?? 0); $dur = (float)($p['duration'] ?? $p['d'] ?? 0);
                $out[] = ['uid' => (string)$r['user_id'], 'user' => $people[$r['user_id']] ?? null, 'item_id' => $r['item_id'], 'title' => $r['show_title'] !== '' ? $r['show_title'] . ' · ' . $r['title'] : $r['title'], 'percent' => $dur > 0 ? (int)round(100 * $pos / $dur) : null, 'updated_at' => (int)$r['updated_at'],
                    'poster' => '/api/items/' . rawurlencode((string)$r['item_id']) . '/poster?fetch=0&variant=thumb'];
            }
            return ['rows' => $out];
        });
        $admin = ($user['role'] ?? '') === 'admin'; $friends = array_flip(Social::friends((string)$user['id'])); $out = [];
        foreach ((array)($all['rows'] ?? []) as $r) {
            if (!$admin && $r['uid'] !== $user['id'] && !isset($friends[$r['uid']])) continue;
            if (Parental::restricted($user) && (!($it = Catalogue::item((string)$r['item_id'])) || !Parental::allows($user, $it))) continue;
            unset($r['uid']); $out[] = $r;
        }
        return ['ok' => true, 'sessions' => $out];
    }
    public static function downloads(array $user): array {
        if (empty(Store::settings()['features']['downloads']) || !Permissions::allowed($user, 'downloads_request')) return ['ok' => true, 'items' => [], 'unavailable' => true];
        return ['ok' => true] + self::cached('downloads', 10, function () {
            $out = []; $full = Acquisition::downloadStatus();
            $label = function ($show, $season, $episode, string $fallback): string {
                $s = is_numeric($season) ? (int)$season : -1; $e = is_numeric($episode) ? (int)$episode : -1;
                $show = trim((string)$show);
                if ($show !== '' && $s >= 0 && $e > 0) return $show . ' ' . sprintf('S%02dE%02d', $s, $e);
                return trim($fallback) !== '' ? trim($fallback) : ($show !== '' ? $show : 'Download');
            };
            foreach ((array)($full['transmission']['torrents'] ?? []) as $t) {
                $st = (string)($t['status'] ?? '');
                if (in_array($st, ['finished', 'seeding', 'seed_wait'], true)) continue;
                $m = is_array($t['match'] ?? null) ? $t['match'] : [];
                $size = (int)($t['size'] ?? 0); $left = (int)($t['left'] ?? 0);
                $pct = isset($t['percent']) && is_numeric($t['percent']) ? (float)$t['percent'] : null;
                if (($pct === null || $pct <= 0) && $size > 0 && $left >= 0 && $left <= $size) $pct = round(($size - $left) * 100 / $size, 1);
                $eta = (int)($t['eta'] ?? -1);
                $out[] = ['id' => 't' . ($t['id'] ?? count($out)), 'name' => $label($m['show_guess'] ?? null, $m['season'] ?? null, $m['episode'] ?? null, (string)($t['name'] ?? '')),
                    'percent' => $pct, 'status' => $st !== '' ? $st : 'downloading', 'rate' => max(0, (int)($t['rate_download'] ?? 0)),
                    'eta' => $eta > 0 ? $eta : null, 'size' => $size > 0 ? $size : null, 'left' => $size > 0 ? max(0, $left) : null];
            }
            foreach ((array)($full['local_queue'] ?? []) as $e) {
                $st = (string)($e['status'] ?? 'queued');
                if (!in_array($st, ['queued', 'queued_local'], true)) continue;
                $out[] = ['id' => 'q' . ($e['id'] ?? count($out)), 'name' => $label($e['show'] ?? null, $e['season'] ?? null, $e['episode'] ?? null, (string)(($e['title'] ?? '') ?: ($e['query'] ?? ''))),
                    'percent' => null, 'status' => 'queued', 'rate' => 0, 'eta' => null, 'size' => null, 'left' => null];
            }
            return ['items' => array_slice($out, 0, 30)];
        });
    }
    public static function recordings(array $user): array {
        if (empty(Store::settings()['features']['live'])) return ['ok' => true, 'recording' => [], 'upcoming' => [], 'unavailable' => true];
        return ['ok' => true] + self::cached('recordings', 30, function () {
        require_once STREAM_ROOT . '/src/Live/tvstudio.php';
        $st = tvs_load(); $now = time(); $rec = []; $up = [];
        $names = []; foreach ((array)($st['channels'] ?? []) as $c) if (isset($c['id'])) $names[$c['id']] = $c['name'] ?? $c['title'] ?? '';
        foreach ((array)($st['recordings'] ?? []) as $r) {
            $row = ['title' => $r['title'] ?? 'Recording', 'channel' => $names[$r['channelId'] ?? ''] ?? '', 'start' => (int)($r['startUnix'] ?? 0), 'end' => (int)($r['endUnix'] ?? 0), 'status' => $r['status'] ?? ''];
            if ($row['start'] <= $now && $row['end'] > $now) $rec[] = $row; elseif ($row['start'] > $now) $up[] = $row;
        }
        usort($up, function ($a, $b) { return $a['start'] <=> $b['start']; });
        return ['recording' => $rec, 'upcoming' => array_slice($up, 0, 12)];
        });
    }

    /** Now playing from a radio stream's ICY metadata (shared stations, or a public custom URL). */
    public static function radioNow(array $user, string $id, string $url): array {
        $allowPrivate = false; $target = '';
        if ($id !== '') { foreach (Radio::stations() as $s) if ((string)($s['id'] ?? '') === $id) { $target = (string)$s['url']; $allowPrivate = true; } }
        elseif ($url !== '') $target = self::external($user, $url);
        if ($target === '' || !function_exists('curl_init')) return ['ok' => true, 'title' => null];
        self::rate($user, 'radio', 30);
        return ['ok' => true] + self::cached('radio-now:' . $target, 20, function () use ($target, $allowPrivate) {
            $p = parse_url($target); $host = (string)($p['host'] ?? '');
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : WebFetch::resolve($host);
            if (!$ips) return ['title' => null];
            if (!$allowPrivate) foreach ($ips as $ip) if (!WebFetch::publicIp($ip)) return ['title' => null];
            $metaint = 0; $buf = ''; $title = null;
            $ch = curl_init($target);
            $port = (int)($p['port'] ?? (strtolower((string)($p['scheme'] ?? '')) === 'https' ? 443 : 80));
            curl_setopt_array($ch, [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (strpos($ips[0], ':') !== false ? '[' . $ips[0] . ']' : $ips[0])], CURLOPT_HTTPHEADER => ['Icy-MetaData: 1'], CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Vaultcast/1',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$metaint) { if (stripos($line, 'icy-metaint:') === 0) $metaint = (int)trim(substr($line, 12)); return strlen($line); },
                CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf, &$metaint) { if ($metaint <= 0 || $metaint > 65536) return 0; $buf .= $chunk; return strlen($buf) > $metaint + 4081 ? 0 : strlen($chunk); }]);
            curl_exec($ch); curl_close($ch);
            if ($metaint > 0 && strlen($buf) > $metaint) {
                $len = ord($buf[$metaint]) * 16; $meta = substr($buf, $metaint + 1, $len);
                if (preg_match("/StreamTitle='(.*?)';/s", $meta, $m)) $title = trim(mb_convert_encoding($m[1], 'UTF-8', 'UTF-8, ISO-8859-1')) ?: null;
            }
            return ['title' => $title ? mb_substr($title, 0, 200) : null];
        });
    }

    // ---------------------------------------------------------------- media widget: movie picking
    public static function movies(array $user, string $q, string $source, int $n): array {
        if (!AreaAccess::allowed($user, 'library')) throw new DomainException('Your account does not have access to the Library.');
        $n = max(1, min(60, $n)); $params = [];
        $sql = "SELECT item_id, title, year FROM vaultcast_browse_items WHERE media_kind='video' AND show_key='' AND type<>'roms'";
        if ($q !== '') { $sql .= ' AND title LIKE ?'; $params[] = '%' . addcslashes($q, '%_\\') . '%'; }
        if (preg_match('/^collection:(.+)$/', $source, $m)) return ['ok' => true, 'items' => self::collectionItems($m[1])];
        if (preg_match('/^genre:(.{1,40})$/', $source, $m)) {
            $ids = self::genreIds($m[1]);
            if (!$ids) return ['ok' => true, 'items' => []];
            $ids = array_slice($ids, 0, 2000); $sql .= ' AND item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $params = array_merge($params, $ids);
        }
        $sql .= $q !== '' ? ' ORDER BY title LIMIT ' . $n : ' ORDER BY RAND() LIMIT ' . $n;
        $rows = Arcade::query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        return ['ok' => true, 'items' => array_map([self::class, 'movieCard'], $rows)];
    }
    private static function movieCard(array $r): array {
        return ['id' => (string)$r['item_id'], 'title' => (string)$r['title'], 'year' => $r['year'] ? (int)$r['year'] : null, 'poster' => '/api/items/' . rawurlencode((string)$r['item_id']) . '/poster?fetch=0&variant=thumb'];
    }
    private static function collectionItems(string $id): array {
        $c = Collections::get($id); if (!$c) return [];
        $ids = []; foreach ((array)($c['parts'] ?? []) as $p) if (!empty($p['item_id'])) $ids[] = (string)$p['item_id'];
        if (!$ids) return [];
        $rows = Arcade::query('SELECT item_id, title, year FROM vaultcast_browse_items WHERE item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll(PDO::FETCH_ASSOC);
        $by = []; foreach ($rows as $r) $by[$r['item_id']] = $r; $out = [];
        foreach ($ids as $i) if (isset($by[$i])) $out[] = self::movieCard($by[$i]);
        return $out;
    }
    private static function genreIds(string $genre): array {
        $ids = [];
        foreach (Arcade::query("SELECT DISTINCT section_id FROM vaultcast_browse_items WHERE media_kind='video' AND show_key=''")->fetchAll(PDO::FETCH_COLUMN) as $section) {
            $pack = Catalogue::browseCacheGet('facets_movies', (string)$section);
            foreach ((array)($pack['genres_by_id'] ?? []) as $id => $gs) if (in_array($genre, (array)$gs, true)) $ids[] = (string)$id;
        }
        return $ids;
    }
    public static function movieSources(array $user): array {
        if (!AreaAccess::allowed($user, 'library')) return ['ok' => true, 'collections' => [], 'genres' => []];
        return ['ok' => true] + self::cached('movie-sources', 300, function () {
        $cols = []; foreach ((array)(Collections::publicList()['collections'] ?? []) as $c) if ((int)$c['available_count'] > 0 && ($c['type'] ?? 'movies') !== 'shows') $cols[] = ['id' => $c['id'], 'name' => $c['name'], 'count' => (int)$c['available_count']];
        $genres = [];
        foreach (Arcade::query("SELECT DISTINCT section_id FROM vaultcast_browse_items WHERE media_kind='video' AND show_key=''")->fetchAll(PDO::FETCH_COLUMN) as $section) {
            $pack = Catalogue::browseCacheGet('facets_movies', (string)$section);
            foreach ((array)($pack['facets']['genres'] ?? []) as $g => $count) { $name = is_array($count) ? (string)($count['name'] ?? $g) : (string)$g; $genres[$name] = ($genres[$name] ?? 0) + (is_array($count) ? (int)($count['count'] ?? 0) : (int)$count); }
        }
        arsort($genres);
        return ['collections' => array_slice($cols, 0, 200), 'genres' => array_map(function ($g, $n) { return ['name' => $g, 'count' => $n]; }, array_keys($genres), array_values($genres))];
        });
    }
    /** What to play after $after finishes, from a collection order, a genre, the user's queue or anything unwatched. */
    public static function nextMovie(array $user, string $after, string $source, array $queue): array {
        if (!AreaAccess::allowed($user, 'library')) throw new DomainException('Your account does not have access to the Library.');
        if ($source === 'queue') {
            $queue = array_values(array_filter(array_map('strval', $queue)));
            $i = array_search($after, $queue, true); $next = $queue[$i === false ? 0 : $i + 1] ?? null;
            if ($next) { $r = Arcade::query('SELECT item_id, title, year FROM vaultcast_browse_items WHERE item_id=?', [$next])->fetch(PDO::FETCH_ASSOC); if ($r) return ['ok' => true, 'item' => self::movieCard($r)]; }
            return ['ok' => true, 'item' => null];
        }
        if (preg_match('/^collection:(.+)$/', $source, $m)) {
            $items = self::collectionItems($m[1]); $ids = array_column($items, 'id'); $i = array_search($after, $ids, true);
            return ['ok' => true, 'item' => $items[$i === false ? 0 : $i + 1] ?? null];
        }
        $watched = array_flip(Arcade::query('SELECT item_id FROM vaultcast_progress WHERE user_id=?', [$user['id']])->fetchAll(PDO::FETCH_COLUMN));
        $pool = self::movies($user, '', $source, 40)['items'];
        foreach ($pool as $p) if ($p['id'] !== $after && !isset($watched[$p['id']])) return ['ok' => true, 'item' => $p];
        foreach ($pool as $p) if ($p['id'] !== $after) return ['ok' => true, 'item' => $p];
        return ['ok' => true, 'item' => null];
    }

    // ---------------------------------------------------------------- ambient
    /** Per-user Misc preferences: the Ambient layers and the saved webcams list (shared by the Webcams and Media widgets). */
    public static function prefs(array $user): array {
        Arcade::signed($user);
        $d = (array)\Vaultcast\Repository::document('misc-prefs:' . $user['id'], []);
        $amb = is_array($d['ambient'] ?? null) ? $d['ambient'] : null;
        if ($amb !== null && (string)($amb['webcam'] ?? '') !== '' && !Webcams::ambientEnabled((string)$amb['webcam'])) $amb['webcam'] = '';
        return ['ok' => true, 'ambient' => $amb, 'webcams' => array_values((array)($d['webcams'] ?? []))];
    }
    public static function savePrefs(array $user, array $body): array {
        Arcade::signed($user);
        $uid = (string)$user['id']; $recorded = null;
        HomeHub::mutate('misc-prefs:' . $uid, function (array $d) use ($body, $uid, &$recorded): array {
            if (array_key_exists('ambient', $body) && is_array($body['ambient'])) {
                $amb = Pictures::scrub($uid, self::clean($body['ambient']));
                // Ambient may only use webcams an admin has made available; anything else is dropped.
                if (is_array($amb) && (string)($amb['webcam'] ?? '') !== '' && !Webcams::ambientEnabled((string)$amb['webcam'])) $amb['webcam'] = '';
                $json = json_encode($amb);
                if (strlen((string)$json) > 20000) throw new DomainException('Ambient settings are too large.');
                $d['ambient'] = json_decode((string)$json, true);
            }
            if (array_key_exists('webcams', $body)) {
                $cams = [];
                foreach (array_slice((array)$body['webcams'], 0, 40) as $c) {
                    if (!is_array($c)) continue; $url = trim((string)($c['url'] ?? ''));
                    if (!preg_match('#^https?://[^\s"<>]+$#i', $url)) continue;
                    $id = preg_match('/^[a-z0-9]{4,16}$/', (string)($c['id'] ?? '')) ? (string)$c['id'] : bin2hex(random_bytes(4));
                    $cams[] = ['id' => $id, 'name' => mb_substr(trim((string)($c['name'] ?? '')), 0, 60), 'url' => mb_substr($url, 0, 1000),
                        'kind' => in_array($c['kind'] ?? '', ['auto', 'image', 'mjpeg', 'video', 'youtube', 'embed'], true) ? $c['kind'] : 'auto', 'refresh' => max(5, min(3600, (int)($c['refresh'] ?? 30)))];
                }
                $d['webcams'] = $cams; $recorded = $cams;
            }
            return $d;
        });
        if ($recorded) Webcams::record($uid, $recorded);
        return self::prefs($user);
    }
    public static function ambientSettings(): array {
        $m = (array)(Store::settings()['misc'] ?? []);
        // Webcams come from the site registry: only the ones an admin enabled for Ambient, under the admin-set names.
        return ['idle_minutes' => max(0, min(240, (int)($m['ambient_idle_minutes'] ?? 0))), 'webcams' => Webcams::ambient(), 'radio' => (string)($m['ambient_radio'] ?? '')];
    }
    public static function saveAmbient(array $body): array {
        // Webcams are managed in Administration → Settings → Ambient (the registry); the old site list is left as it was.
        $all = Store::settings();
        $all['misc'] = array_merge((array)($all['misc'] ?? []), ['ambient_idle_minutes' => max(0, min(240, (int)($body['idle_minutes'] ?? 0))), 'ambient_radio' => mb_substr(trim((string)($body['radio'] ?? '')), 0, 120)]);
        Store::saveSettings($all);
        return ['ok' => true, 'settings' => self::ambientSettings()];
    }
    /**
     * Library artwork for Ambient and the Slideshow widget. $type: both | movies | tv; $order: random | recent
     * (newest additions first); $variant: full | medium (posters only; frames are always full size).
     */
    public static function stills(array $user, string $kind, int $n, string $type = 'both', string $order = 'random', string $variant = 'full'): array {
        if (!AreaAccess::allowed($user, 'library')) return ['ok' => true, 'stills' => []];
        $n = max(5, min(60, $n));
        $where = "b.media_kind='video' AND (b.show_key='' OR b.episode=1)";
        if ($type === 'movies') $where .= " AND b.show_key=''";
        elseif ($type === 'tv') $where .= " AND b.show_key<>''";
        $variant = $variant === 'medium' ? 'medium' : 'full';
        $sql = $order === 'recent'
            ? "SELECT b.item_id, b.title, b.year, b.show_key, b.show_name FROM vaultcast_browse_items b JOIN vaultcast_items i ON i.id=b.item_id WHERE $where ORDER BY i.added_at DESC LIMIT " . $n
            : "SELECT b.item_id, b.title, b.year, b.show_key, b.show_name FROM vaultcast_browse_items b WHERE $where ORDER BY RAND() LIMIT " . $n;
        $rows = Arcade::query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $frame = $kind === 'frames' || ($kind === 'mixed' && crc32($r['item_id']) % 2 === 0);
            $out[] = ['title' => $r['show_key'] !== '' ? $r['show_name'] : $r['title'], 'year' => $r['year'] ? (int)$r['year'] : null, 'item_id' => $r['item_id'],
                'kind' => $frame ? 'frame' : 'poster', 'url' => $frame ? '/api/misc/ambient/frame/' . rawurlencode((string)$r['item_id']) : '/api/items/' . rawurlencode((string)$r['item_id']) . '/poster?fetch=0&variant=' . $variant];
        }
        return ['ok' => true, 'stills' => $out];
    }
    public static function frame(array $user, string $itemId): void {
        if (!AreaAccess::allowed($user, 'library') || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $itemId)) { http_response_code(404); exit; }
        // Frames are cached; when one is not ready yet and the extractor is busy, show the poster instead of waiting on ffmpeg.
        $file = Quiz::frame($itemId, false);
        if (!$file) { header('Cache-Control: no-store'); header('Location: ' . rtrim(\Vaultcast\Database::basePath(), '/') . '/api/items/' . rawurlencode($itemId) . '/poster?fetch=0&variant=full', true, 302); exit; }
        header('Content-Type: ' . (@mime_content_type($file) ?: 'image/jpeg')); header('Cache-Control: private, max-age=86400'); header('Content-Length: ' . filesize($file)); readfile($file); exit;
    }
}
