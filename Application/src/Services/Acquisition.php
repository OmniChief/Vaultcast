<?php
declare(strict_types=1);

class Acquisition {
    /** Transmission session ids by RPC URL (reused across calls in one request or worker run). */
    private static $rpcSessions = [];

    /** Transmission's current download-dir (session-get), or null when unreachable. */
    public static function transmissionDownloadDir(?array $tr = null): ?string {
        if ($tr === null) $tr = Store::settings()['acquisition']['transmission'] ?? [];
        if (empty($tr['enabled']) || empty($tr['url'])) return null;
        $r = self::transmissionRpc($tr, ['method' => 'session-get', 'arguments' => ['fields' => ['download-dir']]]);
        return !empty($r['ok']) && isset($r['arguments']['download-dir']) ? (string)$r['arguments']['download-dir'] : null;
    }

    /** session-set download-dir so torrents added elsewhere land in the same folder. */
    public static function setTransmissionDownloadDir(string $dir, ?array $tr = null): array {
        if ($tr === null) $tr = Store::settings()['acquisition']['transmission'] ?? [];
        $dir = trim($dir);
        if ($dir === '') return ['ok' => false, 'error' => 'Empty download folder'];
        if (empty($tr['enabled']) || empty($tr['url'])) return ['ok' => false, 'error' => 'Transmission disabled'];
        $r = self::transmissionRpc($tr, ['method' => 'session-set', 'arguments' => ['download-dir' => $dir]]);
        return !empty($r['ok']) ? ['ok' => true] : ['ok' => false, 'error' => $r['message'] ?? 'session-set failed'];
    }

    /**
     * Library roots on a different volume than the download folder (stat dev). Imports from those copy
     * instead of renaming. null = cannot tell (folder missing or dev unavailable, e.g. some Windows/SMB).
     */
    public static function downloadVolumeInfo(?string $dir = null): array {
        $settings = Store::settings();
        if ($dir === null) $dir = (string)($settings['acquisition']['download_path'] ?? '');
        $out = ['download_path' => $dir, 'other_volume' => [], 'same_volume' => null];
        $ds = $dir !== '' && is_dir($dir) ? @stat($dir) : false;
        if (!$ds) return $out;
        $known = false; $same = true;
        foreach ($settings['sections'] ?? [] as $sec) {
            $p = (string)($sec['path'] ?? '');
            if ($p === '' || empty($sec['enabled']) || !is_dir($p)) continue;
            $ss = @stat($p);
            if (!$ss) continue;
            // Windows reports dev as the drive serial; 0 on both sides is not proof of anything for UNC paths.
            if ((int)$ds['dev'] === 0 && (int)$ss['dev'] === 0 && (strpos($dir, '//') === 0 || strpos($p, '//') === 0 || strpos($dir, '\\\\') === 0 || strpos($p, '\\\\') === 0)) continue;
            $known = true;
            if ((int)$ss['dev'] !== (int)$ds['dev']) { $same = false; $out['other_volume'][] = (string)($sec['label'] ?? $sec['id'] ?? $p); }
        }
        $out['same_volume'] = $known ? $same : null;
        return $out;
    }

    public static function checkConnection(array $tr): array {
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'PHP cURL is missing. Enable the curl extension and restart PHP.'];
        $url = trim((string)($tr['url'] ?? ''));
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'Enter the full HTTP or HTTPS Transmission RPC URL.'];
        }
        $tr['url'] = $url;
        $rpc = self::transmissionRpc($tr, ['method' => 'session-get']);
        return !empty($rpc['ok'])
            ? ['ok' => true, 'message' => 'Connected to Transmission. RPC authentication succeeded.']
            : ['ok' => false, 'error' => 'Cannot authenticate with Transmission. Check that it is running, remote access is enabled, this server is allowed, and the RPC URL and credentials are correct.'];
    }

    public static function state(): array {
        $q = Store::get('acquire_queue', []);
        $settings = Store::settings();
        $acq = $settings['acquisition'] ?? [];
        $meta = Store::get('runtime_meta', []);
        return [
            'ok' => true,
            'enabled' => !empty($acq['enabled']),
            'feeds' => $acq['feeds'] ?? [],
            'min_interval_seconds' => $acq['min_interval_seconds'] ?? 120,
            'max_per_hour' => $acq['max_per_hour'] ?? 20,
            'jitter_seconds' => $acq['jitter_seconds'] ?? 30,
            'queue' => is_array($q) ? $q : [],
            'last_fetch_at' => $meta['acq_last_fetch_at'] ?? null,
            'fetches_this_hour' => self::fetchesThisHour($meta),
            'transmission' => self::transmissionStatus($acq['transmission'] ?? []),
        ];
    }

    /** Public/admin Transmission + local acquire queue snapshot. */
    public static function downloadStatus(): array {
        $settings = Store::settings();
        $acq = $settings['acquisition'] ?? [];
        $tr = self::transmissionStatus($acq['transmission'] ?? []);
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) $q = [];
        // Keep last 40 local queue entries, newest first
        $local = array_slice(array_reverse($q), 0, 40);
        return [
            'ok' => true,
            'transmission' => $tr,
            'local_queue' => $local,
        ];
    }

    /**
     * One installation-wide, inexpensive on-access acquisition probe.
     * The cooldown check happens before querying the queue. No feed requests
     * run inside HTTP, and simultaneous viewers cannot start extra workers.
     */
    public static function triggerDueSearches(): array {
        $file = STREAM_DATA . '/acquire_tick.lock';
        $lock = @fopen($file, 'c+');
        if (!$lock) return ['ok' => false, 'reason' => 'Trigger unavailable'];
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['ok' => true, 'started' => false, 'reason' => 'Another request is checking'];
        }
        try {
            $now = time();
            rewind($lock);
            $last = (int)trim((string)stream_get_contents($lock));
            if ($last > $now - 300) return ['ok' => true, 'started' => false, 'reason' => 'Cooldown'];
            // This timestamp is shared by all clients and survives PHP worker restarts.
            // Reserve the next five minutes even if the queue or CLI is unavailable.
            rewind($lock);
            ftruncate($lock, 0);
            fwrite($lock, (string)$now);
            fflush($lock);

            $settings = Store::settings();
            if (empty($settings['features']['downloads']) || empty($settings['acquisition']['enabled'])) {
                return ['ok' => true, 'started' => false, 'reason' => 'Acquisition disabled'];
            }
            $queue = Store::get('acquire_queue', []);
            if (!is_array($queue)) return ['ok' => true, 'started' => false, 'reason' => 'No queue'];
            $due = false;
            foreach ($queue as $entry) {
                if (!is_array($entry)) continue;
                if (!in_array((string)($entry['status'] ?? ''), ['queued', 'queued_local', 'scheduled', 'retry_wait'], true)) continue;
                if (trim((string)($entry['query'] ?? '')) === '' && empty($entry['magnet']) && empty($entry['url'])) continue;
                if ((int)($entry['eligible_at'] ?? 0) > $now || (int)($entry['next_attempt_at'] ?? 0) > $now) continue;
                $due = true;
                break;
            }
            if (!$due) return ['ok' => true, 'started' => false, 'reason' => 'Nothing due'];
            try {
                Maintenance::start('acquisition-tick', 'automatic');
                return ['ok' => true, 'started' => true];
            } catch (\Throwable $e) {
                error_log('Vaultcast acquisition tick: ' . $e->getMessage());
                return ['ok' => false, 'started' => false, 'reason' => 'Worker launch failed'];
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function transmissionStatus(array $tr): array {
        $url = $tr['url'] ?? '';
        $enabled = !empty($tr['enabled']);
        $reachable = $enabled ? self::transmissionReachable($tr) : false;
        $torrents = [];
        $error = null;
        if ($reachable) {
            $rpc = self::transmissionRpc($tr, [
                'method' => 'torrent-get',
                'arguments' => [
                    'fields' => [
                        'id', 'name', 'percentDone', 'eta', 'status',
                        'rateDownload', 'rateUpload', 'sizeWhenDone', 'leftUntilDone',
                        'errorString', 'isFinished', 'isStalled',
                    ],
                ],
            ]);
            if (!empty($rpc['ok']) && is_array($rpc['arguments']['torrents'] ?? null)) {
                foreach ($rpc['arguments']['torrents'] as $t) {
                    if (!is_array($t)) continue;
                    $statusCode = (int)($t['status'] ?? -1);
                    $torrents[] = [
                        'id' => $t['id'] ?? null,
                        'name' => $t['name'] ?? '',
                        'percent' => round(((float)($t['percentDone'] ?? 0)) * 100, 1),
                        'eta' => (int)($t['eta'] ?? -1),
                        'eta_label' => self::formatEta((int)($t['eta'] ?? -1)),
                        'status' => self::torrentStatusLabel($statusCode, $t),
                        'status_code' => $statusCode,
                        'rate_download' => (int)($t['rateDownload'] ?? 0),
                        'rate_upload' => (int)($t['rateUpload'] ?? 0),
                        'size' => (int)($t['sizeWhenDone'] ?? 0),
                        'left' => (int)($t['leftUntilDone'] ?? 0),
                        'error' => $t['errorString'] ?? '',
                        'match' => self::parseEpisodeFromName((string)($t['name'] ?? '')),
                    ];
                }
            } else {
                $error = $rpc['message'] ?? 'RPC torrent-get failed';
            }
        } elseif ($enabled) {
            $error = 'Transmission RPC unreachable at ' . $url;
        } else {
            $error = 'Transmission disabled in settings';
        }
        return [
            'enabled' => $enabled,
            'url' => $url,
            'reachable' => $reachable,
            'offline' => !$reachable,
            'error' => $error,
            'torrents' => $torrents,
            'torrent_count' => count($torrents),
        ];
    }

    private static function torrentStatusLabel(int $code, array $t): string {
        if (!empty($t['errorString'])) return 'error';
        if (!empty($t['isFinished'])) return 'finished';
        $map = [
            0 => 'stopped',
            1 => 'check_wait',
            2 => 'checking',
            3 => 'download_wait',
            4 => 'downloading',
            5 => 'seed_wait',
            6 => 'seeding',
        ];
        $label = $map[$code] ?? ('status_'.$code);
        if (!empty($t['isStalled']) && $label === 'downloading') return 'stalled';
        return $label;
    }

    private static function formatEta(int $eta): string {
        if ($eta < 0) return 'unknown';
        if ($eta === 0) return 'done';
        if ($eta < 60) return $eta . 's';
        if ($eta < 3600) return (int)floor($eta / 60) . 'm';
        if ($eta < 86400) return (int)floor($eta / 3600) . 'h ' . (int)floor(($eta % 3600) / 60) . 'm';
        return (int)floor($eta / 86400) . 'd';
    }

    /** Best-effort SxxExx (+ show guess) from torrent name. */
    public static function parseEpisodeFromName(string $name): ?array {
        if (!preg_match('/^(.*?)\bS(\d{1,2})E(\d{1,3})\b/i', $name, $m)) return null;
        $season = (int)$m[2];
        $episode = (int)$m[3];
        $show = trim(preg_replace('/[._]+/', ' ', $m[1]));
        $show = trim(preg_replace('/\s+/', ' ', $show), " -._");
        return [
            'season' => $season,
            'episode' => $episode,
            'code' => sprintf('S%02dE%02d', $season, $episode),
            'show_guess' => $show !== '' ? $show : null,
        ];
    }

    private static function transmissionRpc(array $tr, array $payload): array {
        $url = $tr['url'] ?? '';
        // One session id per URL for the whole request/batch; refreshed on 409 below.
        $session = self::$rpcSessions[$url] ?? self::transmissionSession($url, $tr);
        if ($session !== null) self::$rpcSessions[$url] = $session;
        if ($session === null) {
            return ['ok' => false, 'message' => 'Transmission RPC unavailable'];
        }
        $body = json_encode($payload);
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'X-Transmission-Session-Id: ' . $session];
        if (!empty($tr['username'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $tr['username'] . ':' . ($tr['password'] ?? ''));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 409) {
            // session rotated; one retry
            unset(self::$rpcSessions[$url]);
            $session = self::transmissionSession($url, $tr);
            if ($session === null) return ['ok' => false, 'message' => 'Session refresh failed'];
            self::$rpcSessions[$url] = $session;
            $ch = curl_init($url);
            $headers = ['Content-Type: application/json', 'X-Transmission-Session-Id: ' . $session];
            if (!empty($tr['username'])) {
                curl_setopt($ch, CURLOPT_USERPWD, $tr['username'] . ':' . ($tr['password'] ?? ''));
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        $j = json_decode((string)$resp, true);
        if ($code === 200 && is_array($j) && ($j['result'] ?? '') === 'success') {
            return ['ok' => true, 'arguments' => $j['arguments'] ?? []];
        }
        return ['ok' => false, 'message' => 'RPC error HTTP '.$code.': '.substr((string)$resp, 0, 180)];
    }


    private static function fetchesThisHour(array $meta): int {
        $bucket = $meta['acq_hour_bucket'] ?? null;
        $hour = date('Y-m-d-H');
        if ($bucket !== $hour) return 0;
        return (int)($meta['acq_hour_count'] ?? 0);
    }

    private static function bumpFetchCount(): void {
        $meta = Store::get('runtime_meta', []);
        if (!is_array($meta)) $meta = [];
        $hour = date('Y-m-d-H');
        if (($meta['acq_hour_bucket'] ?? '') !== $hour) {
            $meta['acq_hour_bucket'] = $hour;
            $meta['acq_hour_count'] = 0;
        }
        $meta['acq_hour_count'] = (int)$meta['acq_hour_count'] + 1;
        $meta['acq_last_fetch_at'] = date('c');
        Store::set('runtime_meta', $meta);
    }

    public static function canFetchNow(array $opts = []): array {
        $settings = Store::settings();
        $acq = $settings['acquisition'] ?? [];
        $allowFallback = !empty($opts['allow_rss_fallback']) && !empty($acq['rss_fallback_search']);
        if (empty($acq['enabled']) && !$allowFallback) return ['ok' => false, 'reason' => 'Acquisition disabled'];
        // Same HTTP batch (multi-quality RSS fallback) reuses one gate.
        if (!empty($opts['skip_rate_limit'])) return ['ok' => true];
        $meta = Store::get('runtime_meta', []);
        if (!is_array($meta)) $meta = [];
        $min = max(0, (int)($acq['min_interval_seconds'] ?? 120));
        $jitter = max(0, (int)($acq['jitter_seconds'] ?? 30));
        // Jitter only when a real min interval is configured (min=0 means "no wait").
        $need = $min > 0 ? ($min + ($jitter > 0 ? random_int(0, $jitter) : 0)) : 0;
        $last = isset($meta['acq_last_fetch_ts']) ? (int)$meta['acq_last_fetch_ts'] : 0;
        $now = time();
        // Clock skew / future stamp must never block forever.
        if ($last > $now + 5) {
            $meta['acq_last_fetch_ts'] = 0;
            Store::set('runtime_meta', $meta);
            $last = 0;
        }
        $elapsed = $last > 0 ? ($now - $last) : $need;
        // A person clicking Search is not polling: only the hourly cap applies to them.
        if (empty($opts['interactive']) && $last && $need > 0 && $elapsed < $need) {
            $retry = max(1, $need - $elapsed);
            return [
                'ok' => false,
                'reason' => 'Rate limited (min interval — retry in ' . $retry . 's)',
                'retry_in' => $retry,
                'min_interval_seconds' => $min,
            ];
        }
        $max = max(1, (int)($acq['max_per_hour'] ?? 20));
        $used = self::fetchesThisHour($meta);
        if ($used >= $max) {
            // Hour bucket rolls over at next clock hour — never "forever".
            $retry = max(1, 3600 - ((int)date('i') * 60 + (int)date('s')));
            return [
                'ok' => false,
                'reason' => 'Rate limited (max ' . $max . '/hour — retry in ~' . $retry . 's)',
                'retry_in' => $retry,
                'max_per_hour' => $max,
                'fetches_this_hour' => $used,
            ];
        }
        return ['ok' => true];
    }

    /** Count configured search-capable feeds ({query}/{q} or type=search|apibay|json). */
    public static function countSearchFeeds(array $feeds = null): int {
        if ($feeds === null) {
            $feeds = Store::settings()['acquisition']['feeds'] ?? [];
        }
        $feeds = FeedCatalogue::active($feeds);
        $n = 0;
        foreach ($feeds as $feed) {
            $url = is_array($feed) ? ($feed['url'] ?? '') : (string)$feed;
            if ($url === '') continue;
            $type = is_array($feed) ? strtolower((string)($feed['type'] ?? '')) : '';
            if (in_array($type, ['search', 'apibay', 'json'], true)
                || strpos($url, '{query}') !== false
                || strpos($url, '{q}') !== false
                || stripos($url, 'apibay.org') !== false) {
                $n++;
            }
        }
        return $n;
    }

    public static function fetchFeeds(): array {
        $gate = self::canFetchNow();
        if (!$gate['ok']) return $gate + ['items' => []];

        $settings = Store::settings();
        $feeds = FeedCatalogue::active($settings['acquisition']['feeds'] ?? []);
        $found = [];
        foreach ($feeds as $feed) {
            $url = is_array($feed) ? ($feed['url'] ?? '') : (string)$feed;
            if ($url === '') continue;
            $xml = self::httpGet($url);
            if ($xml === null) continue;
            $found = array_merge($found, self::parseRss($xml, $url));
        }

        $meta = Store::get('runtime_meta', []);
        if (!is_array($meta)) $meta = [];
        $meta['acq_last_fetch_ts'] = time();
        Store::set('runtime_meta', $meta);
        self::bumpFetchCount();

        return ['ok' => true, 'items' => $found, 'count' => count($found)];
    }

    /** Search URL for one feed and query, or null when the feed is not a search feed. */
    private static function searchFeedUrl($feed, string $query): ?string {
        $url = is_array($feed) ? (string)($feed['url'] ?? '') : (string)$feed;
        if ($url === '') return null;
        $type = is_array($feed) ? strtolower((string)($feed['type'] ?? '')) : '';
        $isSearch = in_array($type, ['search', 'apibay', 'json'], true)
            || (strpos($url, '{query}') !== false)
            || (strpos($url, '{q}') !== false)
            || stripos($url, 'apibay.org') !== false;
        if (!$isSearch) return null;
        $e = rawurlencode($query);
        $built = str_replace(['{query}', '{q}', '{QUERY}', '{Q}'], [$e, $e, $e, $e], $url);
        if (stripos($built, 'apibay.org') !== false && strpos($built, '=') !== false
            && strpos($url, '{query}') === false && strpos($url, '{q}') === false) {
            $built .= (strpos($built, '?') === false ? '?' : '&') . 'q=' . $e;
        }
        return $built;
    }

    private static function searchCacheFile(string $url): string {
        $dir = defined('STREAM_SHM') && is_dir(STREAM_SHM) ? STREAM_SHM : (STREAM_DATA . '/cache');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/acq_search_' . sha1($url) . '.txt';
    }

    /** Feed answers are reused for 10 minutes (same query, same feed). */
    private static function searchCacheGet(string $url): ?string {
        $f = self::searchCacheFile($url);
        if (!is_file($f) || filemtime($f) < time() - 600) return null;
        $b = @file_get_contents($f);
        return $b === false ? null : $b;
    }

    private static function searchCachePut(string $url, string $body): void {
        if (strlen($body) > 4 * 1024 * 1024) return;
        $f = self::searchCacheFile($url);
        if (@file_put_contents($f . '.tmp', $body) !== false && !@rename($f . '.tmp', $f)) @unlink($f . '.tmp');
    }

    /** Parallel GETs with short timeouts. Returns url => body (null on error). */
    private static function httpGetMulti(array $urls, int $timeout = 8): array {
        $out = [];
        $urls = array_values(array_filter($urls, function ($u) { return (bool)preg_match('#^https?://#i', (string)$u); }));
        if (!$urls) return $out;
        if (!function_exists('curl_multi_init') || count($urls) === 1) {
            foreach ($urls as $u) $out[$u] = self::httpGet($u, $timeout);
            return $out;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, self::httpOpts($timeout));
            curl_multi_add_handle($mh, $ch);
            $handles[$u] = $ch;
        }
        $running = 0;
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running && curl_multi_select($mh, 0.5) === -1) usleep(50000);
        } while ($running > 0 && $st === CURLM_OK);
        foreach ($handles as $u => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $out[$u] = ($body === null || $body === '' || $code >= 400 || $code === 0) ? null : $body;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private static function httpOpts(int $timeout): array {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . ' (+acquire)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
        ];
    }

    private static function httpGet(string $url, int $timeout = 20): ?string {
        if (!preg_match('#^https?://#i', $url)) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, self::httpOpts($timeout));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) return null;
        return $body;
    }

    /** Append configured default trackers to a magnet URI. */
    private static function appendDefaultTrackers(string $magnet, array $feedTrackers = []): string {
        if ($magnet === '' || stripos($magnet, 'magnet:?') !== 0) return $magnet;
        return TorrentTrackers::withTrackers($magnet, array_merge($feedTrackers, self::defaultTrackers()));
    }

    /** Configured default trackers (Settings → acquisition.default_trackers). */
    public static function defaultTrackers(): array {
        $set = TorrentTrackers::clean(Store::settings()['acquisition']['default_trackers'] ?? []);
        return $set ? $set : TorrentTrackers::BUILTIN;
    }

    /**
     * The one way a torrent reaches Transmission: picks magnet vs .torrent (chooseSource) and,
     * right before torrent-add, gives a tracker-less magnet the default (or built-in) trackers.
     */
    public static function sendToTransmission(string $magnet, string $url, array $tr): array {
        $src = TorrentTrackers::chooseSource($magnet, $url);
        if (stripos($src, 'magnet:?') === 0 && !TorrentTrackers::trackersOf($src)) {
            $src = TorrentTrackers::withTrackers($src, self::defaultTrackers());
        }
        return self::addToTransmission($src, $tr);
    }

    /** Parse Apibay / ThePirateBay JSON search (`[{name,info_hash,seeders,...}]`). */
    private static function parseApibayJson(string $json, string $feedUrl, array $feedTrackers = []): array {
        $out = [];
        $data = json_decode($json, true);
        if (!is_array($data)) return $out;
        foreach ($data as $row) {
            if (!is_array($row)) continue;
            $name = trim((string)($row['name'] ?? ''));
            $hash = strtolower(trim((string)($row['info_hash'] ?? '')));
            if ($name === '' || !preg_match('/^[a-f0-9]{40}$/', $hash)) continue;
            // Apibay sentinel for empty result set
            if ($hash === '0000000000000000000000000000000000000000') continue;
            $seeds = isset($row['seeders']) ? (int)$row['seeders'] : null;
            $magnet = self::appendDefaultTrackers(
                'magnet:?xt=urn:btih:' . $hash . '&dn=' . rawurlencode($name), $feedTrackers
            );
            $out[] = [
                'title' => $name,
                'link' => 'https://thepiratebay.org/description.php?id=' . rawurlencode((string)($row['id'] ?? '')),
                'enclosure' => '',
                'magnet' => $magnet,
                'guid' => $hash,
                'feed' => $feedUrl,
                'seeds' => $seeds,
                'quality' => self::detectQuality($name),
                'language' => self::detectLanguage($name),
            ];
        }
        return $out;
    }

    /** Parse RSS/Atom or JSON search body into normalized hit list. */
    private static function parseSearchBody(string $body, string $feedUrl, string $type = '', array $feedTrackers = []): array {
        $trim = ltrim($body);
        $type = strtolower($type);
        if ($type === 'apibay' || $type === 'json' || $trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            if ($trim[0] === '[' || (stripos($feedUrl, 'apibay.org') !== false) || $type === 'apibay' || $type === 'json') {
                $j = self::parseApibayJson($body, $feedUrl, $feedTrackers);
                if ($j || $trim[0] === '[') return $j;
            }
        }
        return self::parseRss($body, $feedUrl, $feedTrackers);
    }

    private static function parseRss(string $xml, string $feedUrl, array $feedTrackers = []): array {
        $out = [];
        libxml_use_internal_errors(true);
        $sx = @simplexml_load_string($xml);
        if ($sx === false) return $out;
        $items = $sx->channel->item ?? $sx->entry ?? [];
        foreach ($items as $item) {
            $title = trim((string)($item->title ?? ''));
            $link = trim((string)($item->link ?? ''));
            if ($link === '' && isset($item->link['href'])) $link = (string)$item->link['href'];
            $enclosure = '';
            if (isset($item->enclosure['url'])) $enclosure = (string)$item->enclosure['url'];
            $magnet = '';
            $guid = trim((string)($item->guid ?? $item->id ?? ''));
            $desc = trim((string)($item->description ?? $item->summary ?? ''));
            // Keep every tr= from the source: decode &amp; and stop at quotes/tags in HTML descriptions
            $magnet = TorrentTrackers::extractMagnet($link . ' ' . $desc);
            if ($magnet === '' && stripos($enclosure, 'magnet:?') === 0) $magnet = TorrentTrackers::extractMagnet($enclosure);
            if (stripos($enclosure, 'magnet:?') === 0) $enclosure = '';
            // Nyaa / common torrent RSS infoHash → magnet
            if ($magnet === '' && is_object($item) && method_exists($item, 'children')) {
                foreach (['https://nyaa.si/xmlns/nyaa', 'nyaa'] as $ns) {
                    try {
                        $kids = $ns === 'nyaa' ? $item->children('nyaa', true) : $item->children($ns);
                        if ($kids && isset($kids->infoHash)) {
                            $ih = strtolower(trim((string)$kids->infoHash));
                            if (preg_match('/^[a-f0-9]{40}$/', $ih)) {
                                $magnet = 'magnet:?xt=urn:btih:' . $ih . '&dn=' . rawurlencode($title);
                            }
                        }
                    } catch (Throwable $e) { /* ignore */ }
                }
            }
            if ($magnet === '' && preg_match('/\b([a-fA-F0-9]{40})\b/', $desc, $hm)) {
                $magnet = 'magnet:?xt=urn:btih:' . strtolower($hm[1]) . '&dn=' . rawurlencode($title);
            }
            // Ensure configured default trackers are present on every magnet
            if ($magnet !== '') {
                $magnet = self::appendDefaultTrackers($magnet, $feedTrackers);
            }
            if ($enclosure === '' && $link !== '' && preg_match('/\.torrent(\?|$)/i', $link)) {
                $enclosure = $link;
            }
            // Internet Archive items: use the Archive's official torrent instead of the direct-file enclosure
            $iaTorrent = $magnet === '' ? FeedCatalogue::archiveTorrent($link !== '' ? $link : $guid) : '';
            if ($iaTorrent !== '') $enclosure = $iaTorrent;
            $seeds = self::extractSeeds($item, $desc, $title);
            $quality = self::detectQuality($title . ' ' . $desc);
            $lang = self::detectLanguage($title . ' ' . $desc);
            $out[] = [
                'title' => $title,
                'link' => $link,
                'enclosure' => $enclosure,
                'magnet' => $magnet,
                'guid' => $guid,
                'feed' => $feedUrl,
                'seeds' => $seeds,
                'quality' => $quality,
                'language' => $lang,
            ];
        }
        return $out;
    }

    /** A date-only premiere becomes search-eligible the following UTC calendar day. */
    private static function episodeEligibleAt($airdate): ?int {
        if (!is_string($airdate) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $airdate)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $airdate, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $airdate) return null;
        return $date->modify('+1 day')->getTimestamp();
    }

    /** Coordinate acquisition jobs across HTTP and CLI workers without a new daemon. */
    private static function acquireLock(bool $wait = false) {
        $fh = @fopen(STREAM_DATA . '/acquire_search.lock', 'c');
        if (!$fh) return false;
        $until = microtime(true) + ($wait ? 10 : 0);
        do {
            if (flock($fh, LOCK_EX | LOCK_NB)) return $fh;
            if (!$wait || microtime(true) >= $until) break;
            usleep(50000);
        } while (true);
        fclose($fh);
        return false;
    }

    /** Search retries never spin indefinitely for absent releases. */
    private static function postponeNoHit(array &$entry): void {
        $attempt = (int)($entry['search_attempts'] ?? 0);
        $delays = [3600, 14400, 43200, 86400, 172800, 259200, 604800];
        $entry['status'] = $attempt >= 8 ? 'exhausted' : 'retry_wait';
        $entry['next_attempt_at'] = $attempt >= 8 ? null : time() + $delays[max(0, min(6, $attempt - 1))];
        $entry['updated_at'] = date('c');
    }

    public static function enqueue(array $item): array {
        $lock = self::acquireLock();
        if (!$lock) return ['ok' => false, 'error' => 'Acquisition queue busy; retry shortly'];
        try {
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) $q = [];
        $show = trim((string)($item['show'] ?? ''));
        if ($show !== '' && isset($item['season'], $item['episode'])) {
            foreach ($q as $existing) {
                if (!is_array($existing) || in_array((string)($existing['status'] ?? ''), ['cancelled', 'done', 'removed', 'failed', 'exhausted'], true)) continue;
                if ((int)($existing['season'] ?? -1) !== (int)$item['season'] || (int)($existing['episode'] ?? -1) !== (int)$item['episode']) continue;
                if ((string)($existing['section_id'] ?? '') !== (string)($item['section_id'] ?? '')) continue;
                $same = !empty($item['show_key']) && !empty($existing['show_key'])
                    ? (string)$item['show_key'] === (string)$existing['show_key']
                    : strcasecmp(trim((string)($existing['show'] ?? '')), $show) === 0;
                if ($same) return ['ok' => true, 'deduplicated' => true, 'entry' => $existing];
            }
        }
        if (count($q) >= 2000) return ['ok' => false, 'error' => 'Acquisition queue full (2000 entries); remove old requests before adding more'];
        $eligible = self::episodeEligibleAt($item['airdate'] ?? null);
        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'title' => $item['title'] ?? 'Unknown',
            'magnet' => $item['magnet'] ?? '',
            'url' => ($item['enclosure'] ?? '') ?: ($item['link'] ?? ''),
            'status' => $eligible !== null && $eligible > time() ? 'scheduled' : 'queued',
            'created_at' => date('c'),
            'message' => '',
            'query' => $item['query'] ?? null,
            'show' => $item['show'] ?? null,
            'show_key' => $item['show_key'] ?? null,
            'airdate' => $item['airdate'] ?? null,
            'eligible_at' => $eligible,
            'next_attempt_at' => $eligible !== null ? max($eligible, time()) : time(),
            'search_attempts' => 0,
            'error_attempts' => 0,
            'season' => $item['season'] ?? null,
            'episode' => $item['episode'] ?? null,
            'kind' => $item['kind'] ?? 'manual',
            'section_id' => $item['section_id'] ?? null,
        ];
        if (!empty($entry['magnet']) && preg_match('/btih:([a-fA-F0-9]{40})/', (string)$entry['magnet'], $hm)) {
            $entry['torrent_hash'] = strtolower($hm[1]);
        }
        $q[] = $entry;
        if (!Store::set('acquire_queue', $q)) return ['ok' => false, 'error' => 'Could not persist acquisition request'];

        $settings = Store::settings();
        $tr = $settings['acquisition']['transmission'] ?? [];
        if ($entry['status'] !== 'scheduled' && !empty($tr['enabled']) && ($entry['magnet'] || $entry['url'])) {
            $res = self::sendToTransmission((string)$entry['magnet'], (string)$entry['url'], $tr);
            $entry['status'] = $res['ok'] ? 'sent_transmission' : 'queued_local';
            $entry['message'] = $res['message'] ?? '';
            if (!empty($res['id'])) $entry['torrent_id'] = (int)$res['id'];
            if (!empty($res['hash'])) $entry['torrent_hash'] = strtolower((string)$res['hash']);
            // update last
            $q[count($q)-1] = $entry;
            Store::set('acquire_queue', $q);
        }
        return ['ok' => true, 'entry' => $entry];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function transmissionReachable(array $tr): bool {
        $url = $tr['url'] ?? '';
        if ($url === '' || !function_exists('curl_init')) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 409 || $code === 200 || $code === 401;
    }


    /** Build Transmission seed args from settings. Default: no seeding. */
    public static function seedingPolicy(array $tr = null): array {
        if ($tr === null) {
            $tr = Store::settings()['acquisition']['transmission'] ?? [];
        }
        $allow = !empty($tr['allow_seeding']);
        $ratio = isset($tr['seed_ratio_limit']) ? (float)$tr['seed_ratio_limit'] : 0.0;
        $idle = isset($tr['seed_idle_minutes']) ? (int)$tr['seed_idle_minutes'] : 0;
        if (!$allow) {
            $ratio = 0.0;
            $idle = 0;
        }
        return [
            'allow_seeding' => $allow,
            'seed_ratio_limit' => $ratio,
            'seed_idle_minutes' => max(0, $idle),
            'apply_to_existing' => !isset($tr['apply_to_existing']) || !empty($tr['apply_to_existing']),
        ];
    }

    /**
     * Apply seeding policy to Transmission session (+ optional existing torrents).
     * When allow_seeding=false: ratio 0 + idle 0 so torrents stop when download finishes.
     */
    public static function applyTransmissionSeedingPolicy(array $opts = []): array {
        $tr = Store::settings()['acquisition']['transmission'] ?? [];
        if (isset($tr['enabled']) && !$tr['enabled']) {
            return ['ok' => false, 'reason' => 'Transmission disabled'];
        }
        $policy = self::seedingPolicy($tr);
        $allow = $policy['allow_seeding'];
        $ratio = $policy['seed_ratio_limit'];
        $idle = $policy['seed_idle_minutes'];

        // Session defaults for new torrents that inherit global mode
        $sessionArgs = [
            'seedRatioLimited' => $allow ? ($ratio > 0) : true,
            'seedRatioLimit' => $allow ? max(0.0, $ratio) : 0.0,
            'idle-seeding-limit-enabled' => $allow ? ($idle > 0) : true,
            'idle-seeding-limit' => $allow ? max(0, $idle) : 0,
        ];
        $session = self::transmissionRpc($tr, [
            'method' => 'session-set',
            'arguments' => $sessionArgs,
        ]);
        if (empty($session['ok'])) {
            return ['ok' => false, 'reason' => $session['message'] ?? 'session-set failed', 'policy' => $policy];
        }

        $updated = 0;
        $applyExisting = !empty($opts['apply_existing']) || (!isset($opts['apply_existing']) && $policy['apply_to_existing']);
        if ($applyExisting) {
            $list = self::transmissionRpc($tr, [
                'method' => 'torrent-get',
                'arguments' => ['fields' => ['id']],
            ]);
            $ids = [];
            foreach (($list['arguments']['torrents'] ?? []) as $tor) {
                if (isset($tor['id'])) $ids[] = (int)$tor['id'];
            }
            if ($ids) {
                // seedRatioMode/seedIdleMode: 0=global 1=single 2=unlimited
                $setArgs = [
                    'ids' => $ids,
                    'seedRatioMode' => $allow ? ($ratio > 0 ? 1 : 2) : 1,
                    'seedRatioLimit' => $allow ? max(0.0, $ratio) : 0.0,
                    'seedIdleMode' => $allow ? ($idle > 0 ? 1 : 2) : 1,
                    'seedIdleLimit' => $allow ? max(0, $idle) : 0,
                ];
                $set = self::transmissionRpc($tr, [
                    'method' => 'torrent-set',
                    'arguments' => $setArgs,
                ]);
                if (!empty($set['ok'])) {
                    $updated = count($ids);
                } else {
                    return [
                        'ok' => false,
                        'reason' => $set['message'] ?? 'torrent-set failed',
                        'policy' => $policy,
                        'session' => $sessionArgs,
                    ];
                }
            }
        }

        return [
            'ok' => true,
            'policy' => $policy,
            'session' => $sessionArgs,
            'torrents_updated' => $updated,
        ];
    }

    public static function addToTransmission(string $torrent, array $tr): array {
        $policy = self::seedingPolicy($tr);
        $args = [
            'filename' => $torrent,
            'download-dir' => Store::settings()['acquisition']['download_path'] ?? '',
        ];
        // Per-torrent seed limits (Transmission 3 / RPC 16)
        if ($args['download-dir'] === '') return ['ok'=>false, 'error'=>'Configure a download directory in Settings first.'];
        if (!$policy['allow_seeding']) {
            $args['seedRatioMode'] = 1;   // single
            $args['seedRatioLimit'] = 0.0; // stop when done
            $args['seedIdleMode'] = 1;
            $args['seedIdleLimit'] = 0;
        } else {
            $ratio = $policy['seed_ratio_limit'];
            $idle = $policy['seed_idle_minutes'];
            if ($ratio > 0) {
                $args['seedRatioMode'] = 1;
                $args['seedRatioLimit'] = $ratio;
            } else {
                $args['seedRatioMode'] = 2; // unlimited
            }
            if ($idle > 0) {
                $args['seedIdleMode'] = 1;
                $args['seedIdleLimit'] = $idle;
            } else {
                $args['seedIdleMode'] = 2;
            }
        }
        $rpc = self::transmissionRpc($tr, [
            'method' => 'torrent-add',
            'arguments' => $args,
        ]);
        if (!empty($rpc['ok'])) {
            $argsOut = $rpc['arguments'] ?? [];
            $tor = null;
            $duplicate = false;
            if (isset($argsOut['torrent-added']) && is_array($argsOut['torrent-added'])) {
                $tor = $argsOut['torrent-added'];
            } elseif (isset($argsOut['torrent-duplicate']) && is_array($argsOut['torrent-duplicate'])) {
                $tor = $argsOut['torrent-duplicate'];
                $duplicate = true;
            }
            // A torrent already in Transmission keeps its old (possibly empty) tracker list: add ours.
            $trackersAdded = 0;
            if ($duplicate && is_array($tor) && isset($tor['id'])) {
                $trackersAdded = self::addMissingTrackers($tr, (int)$tor['id'],
                    TorrentTrackers::withTrackers($torrent, self::defaultTrackers()));
            }
            return [
                'ok' => true,
                'trackers_added' => $trackersAdded,
                'message' => $duplicate ? 'Already in Transmission' : 'Added to Transmission',
                'seeding' => $policy,
                'id' => (is_array($tor) && isset($tor['id'])) ? (int)$tor['id'] : null,
                'hash' => (is_array($tor) && isset($tor['hashString'])) ? strtolower((string)$tor['hashString']) : null,
                'duplicate' => $duplicate,
            ];
        }
        return ['ok' => false, 'message' => $rpc['message'] ?? 'Transmission add failed'];
    }

    /** torrent-set trackerAdd for the magnet's trackers the torrent does not have yet. */
    private static function addMissingTrackers(array $tr, int $id, string $magnet): int {
        $want = TorrentTrackers::trackersOf($magnet);
        if (!$want) return 0;
        $get = self::transmissionRpc($tr, ['method' => 'torrent-get', 'arguments' => ['ids' => [$id], 'fields' => ['id', 'trackers']]]);
        if (empty($get['ok'])) return 0;
        $have = [];
        foreach (($get['arguments']['torrents'][0]['trackers'] ?? []) as $t) {
            if (isset($t['announce'])) $have[] = (string)$t['announce'];
        }
        $add = array_values(array_diff($want, $have));
        if (!$add) return 0;
        $set = self::transmissionRpc($tr, ['method' => 'torrent-set', 'arguments' => ['ids' => [$id], 'trackerAdd' => $add]]);
        return !empty($set['ok']) ? count($add) : 0;
    }

    private static function transmissionSession(string $url, array $tr): ?string {
        $ch = curl_init($url);
        if (!empty($tr['username'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $tr['username'] . ':' . ($tr['password'] ?? ''));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp && preg_match('/X-Transmission-Session-Id:\\s*(\\S+)/i', $resp, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Search configured RSS/Torznab feeds for a query (e.g. "Show Name S02E05").
     * Feed URLs may include {query} or {q} placeholders. Rate-limited.
     */
    public static function searchQuery(string $query, array $opts = []): array {
        $query = trim($query);
        if ($query === '') return ['ok' => false, 'reason' => 'Empty query', 'items' => []];

        $settings = Store::settings();
        $acq = $settings['acquisition'] ?? [];
        $feeds = FeedCatalogue::active($acq['feeds'] ?? []);
        if (self::countSearchFeeds($feeds) === 0) {
            return [
                'ok' => false,
                'reason' => 'No search feeds configured (add a feed URL with {query} or type apibay/search in Admin → Acquisition)',
                'items' => [],
                'query' => $query,
                'feeds_searched' => 0,
                'filters' => self::searchFilters($acq),
            ];
        }

        // All query variants x all search feeds, fetched in parallel; recent answers come from a short cache
        $variants = isset($opts['variants']) && is_array($opts['variants']) && $opts['variants'] ? array_values(array_unique(array_map('strval', $opts['variants']))) : [$query];
        $jobs = [];
        foreach ($variants as $vq) {
            foreach ($feeds as $feed) {
                $built = self::searchFeedUrl($feed, $vq);
                if ($built === null) continue;
                $jobs[] = ['feed' => $feed, 'url' => $built, 'query' => $vq];
            }
        }
        $bodies = [];
        $toFetch = [];
        foreach ($jobs as $j) {
            if (array_key_exists($j['url'], $bodies)) continue;
            $c = empty($opts['no_cache']) ? self::searchCacheGet($j['url']) : null;
            if ($c !== null) $bodies[$j['url']] = $c; else $toFetch[$j['url']] = true;
        }
        if ($toFetch) {
            $gate = self::canFetchNow($opts);
            if (!$gate['ok']) {
                if (!$bodies) return $gate + ['items' => [], 'query' => $query, 'feeds_searched' => 0];
                $toFetch = []; // answer from cache only
            }
        }
        $fetched = $toFetch ? self::httpGetMulti(array_keys($toFetch)) : [];
        foreach ($fetched as $u => $b) {
            $bodies[$u] = $b;
            if ($b !== null) self::searchCachePut($u, $b);
        }
        $found = [];
        $searched = count($fetched);
        $errors = 0;
        $parsed = [];
        foreach ($jobs as $j) {
            $body = $bodies[$j['url']] ?? null;
            if ($body === null) { $errors++; continue; }
            if (isset($parsed[$j['url']])) continue;
            $parsed[$j['url']] = true;
            $feed = $j['feed'];
            $type = is_array($feed) ? strtolower((string)($feed['type'] ?? '')) : '';
            $feedTrackers = is_array($feed) ? TorrentTrackers::clean($feed['trackers'] ?? []) : [];
            foreach (self::parseSearchBody($body, $j['url'], $type, $feedTrackers) as $item) {
                $item['query'] = $query;
                $item['search_query'] = $j['query'];
                $found[] = $item;
            }
        }
        if (!$searched && $bodies) $searched = count($bodies);

        // Only consume rate budget when we actually hit a feed.
        if ($fetched && empty($opts['skip_rate_limit'])) {
            $meta = Store::get('runtime_meta', []);
            if (!is_array($meta)) $meta = [];
            $meta['acq_last_fetch_ts'] = time();
            Store::set('runtime_meta', $meta);
            self::bumpFetchCount();
        }

        $filterOpts = $opts;
        $filtered = self::filterSearchHits($found, $acq, $filterOpts);
        return [
            'ok' => true,
            'query' => $query,
            'items' => $filtered,
            'count' => count($filtered),
            'raw_count' => count($found),
            'feeds_searched' => $searched,
            'feed_errors' => $errors,
            'filters' => self::searchFilters($acq),
        ];
    }

    /** Admin-configurable search filters. */
    public static function searchFilters(array $acq = null): array {
        if ($acq === null) {
            $acq = Store::settings()['acquisition'] ?? [];
        }
        $qualities = $acq['preferred_qualities'] ?? ['1080p', '720p'];
        if (!is_array($qualities)) $qualities = ['1080p', '720p'];
        $langs = $acq['language_filters'] ?? [];
        if (!is_array($langs)) $langs = [];
        $containers = $acq['preferred_containers'] ?? ['mp4'];
        if (!is_array($containers) || !$containers) $containers = ['mp4'];
        return [
            'min_seeds' => (int)($acq['min_seeds'] ?? 1),
            'preferred_qualities' => array_values(array_filter(array_map('strval', $qualities))),
            'language_filters' => array_values(array_filter(array_map('strval', $langs))),
            // Missing movies/TV: DirectPlay-friendly containers only (default mp4)
            'preferred_containers' => array_values(array_filter(array_map(function ($c) {
                return strtolower(trim((string)$c));
            }, $containers))),
            'rss_fallback_search' => !empty($acq['rss_fallback_search']),
            'min_interval_seconds' => (int)($acq['min_interval_seconds'] ?? 120),
            'max_per_hour' => (int)($acq['max_per_hour'] ?? 20),
        ];
    }

    /**
     * User-initiated RSS search for movies/TV/anime with quality preferences.
     * Appends preferred qualities to the query when configured.
     */
    public static function searchRssFallback(string $query, array $opts = []): array {
        $query = trim($query);
        if ($query === '') return ['ok' => false, 'reason' => 'Empty query', 'items' => []];
        $acq = Store::settings()['acquisition'] ?? [];
        $filters = self::searchFilters($acq);
        if (empty($filters['rss_fallback_search']) && empty($opts['force'])) {
            return [
                'ok' => false,
                'reason' => 'RSS fallback search disabled in admin settings',
                'items' => [],
                'filters' => $filters,
            ];
        }
        if (self::countSearchFeeds($acq['feeds'] ?? []) === 0) {
            return [
                'ok' => false,
                'reason' => 'No search feeds configured (Admin → Acquisition: add URL with {query}, e.g. Nyaa RSS or Apibay)',
                'items' => [],
                'filters' => $filters,
                'feeds_searched' => 0,
            ];
        }

        $qualities = $filters['preferred_qualities'] ?: ['1080p', '720p'];
        // Prefer bare + quality queries first; container token last (many titles omit mp4).
        $queries = [$query];
        foreach ($qualities as $qual) {
            $queries[] = $query . ' ' . $qual;
        }
        $tried = [];
        $all = [];
        $lastGateFail = null;
        $feedsSearched = 0;
        $first = true;
        // One parallel round for every variant (was: one sequential round per variant)
        $queries = [['variants' => array_values(array_unique($queries))]];
        foreach ($queries as $qv) {
            $qq = $query;
            $variantList = $qv['variants'];
            if (isset($tried[$qq])) continue;
            $tried[$qq] = true;
            $callOpts = [
                'variants' => $variantList,
                'interactive' => true,
                'allow_rss_fallback' => true,
                // Soft container: rank mp4 higher, do not hard-drop mkv/etc for UI search.
                'soft_containers' => true,
            ];
            if (!$first) {
                // One user click = one rate-limit budget; further quality variants reuse it.
                $callOpts['skip_rate_limit'] = true;
            }
            $last = self::searchQuery($qq, $callOpts);
            $first = false;
            $feedsSearched = max($feedsSearched, (int)($last['feeds_searched'] ?? 0));
            if (!($last['ok'] ?? false) && empty($last['items'])) {
                $lastGateFail = $last;
                // Keep any prior hits; do not discard as a bare rate-limit error.
                if ($all) break;
                return $last + ['filters' => $filters, 'tried' => $variantList];
            }
            foreach ($last['items'] ?? [] as $it) {
                $key = ($it['magnet'] ?? '') . '|' . ($it['guid'] ?? '') . '|' . ($it['title'] ?? '');
                if ($key === '||' || isset($all[$key])) continue;
                if (empty($it['search_query'])) $it['search_query'] = $qq;
                $all[$key] = $it;
            }
            if (count($all) >= 25) break;
        }
        $items = array_values($all);
        $containers = array_map('strtolower', $filters['preferred_containers'] ?? ['mp4']);
        usort($items, function ($a, $b) use ($containers) {
            $ca = self::containerBoost($a, $containers);
            $cb = self::containerBoost($b, $containers);
            if ($ca !== $cb) return $cb - $ca;
            $qa = self::qualityRank($a['quality'] ?? '');
            $qb = self::qualityRank($b['quality'] ?? '');
            if ($qa !== $qb) return $qb - $qa;
            return ((int)($b['seeds'] ?? 0)) - ((int)($a['seeds'] ?? 0));
        });
        $slice = array_slice($items, 0, 40);
        $out = [
            'ok' => true,
            'query' => $query,
            'items' => $slice,
            'count' => count($slice),
            'tried' => $variantList ?? [$query],
            'filters' => $filters,
            'feeds_searched' => $feedsSearched,
        ];
        if (!$slice && $lastGateFail) {
            $out['note'] = $lastGateFail['reason'] ?? null;
        }
        if (!$slice) {
            $out['hint'] = 'No torrents matched filters (seeds / quality / language). Feeds responded: '
                . $feedsSearched . '. Try lowering min seeds or widening qualities.';
        }
        return $out;
    }

    private static function containerBoost(array $item, array $containers): int {
        if (!$containers) return 0;
        $blob = strtolower((string)($item['title'] ?? '') . ' ' . (string)($item['description'] ?? ''));
        foreach ($containers as $want) {
            if ($want !== '' && strpos($blob, $want) !== false) return 10;
        }
        return 0;
    }

    public static function filterSearchHits(array $found, array $acq, array $opts = []): array {
        $filters = self::searchFilters($acq);
        $minSeeds = (int)$filters['min_seeds'];
        $qualities = array_map('strtolower', $filters['preferred_qualities']);
        $langs = array_map('strtolower', $filters['language_filters']);
        $containers = array_map('strtolower', $filters['preferred_containers'] ?? ['mp4']);
        $softContainers = !empty($opts['soft_containers']);
        $out = [];
        foreach ($found as $item) {
            $seeds = $item['seeds'] ?? null;
            if ($seeds !== null && $minSeeds > 0 && (int)$seeds < $minSeeds) continue;
            $titleL = strtolower((string)($item['title'] ?? ''));
            $descL = strtolower((string)($item['description'] ?? ($item['desc'] ?? '')));
            $blob = $titleL . ' ' . $descL;
            if ($containers && !$softContainers) {
                $okC = false;
                foreach ($containers as $want) {
                    if ($want !== '' && strpos($blob, $want) !== false) { $okC = true; break; }
                }
                // Reject known non-DirectPlay containers when preferred list is set
                $bad = ['mkv', 'avi', 'wmv', 'mov', 'ts', 'm2ts', 'mpg', 'mpeg', 'flv', 'divx', 'xvid', 'webm'];
                $hasBad = false;
                foreach ($bad as $b) {
                    if (preg_match('/\b' . preg_quote($b, '/') . '\b/i', $blob)) { $hasBad = true; break; }
                }
                if ($hasBad && !$okC) continue;
                // If title clearly names a container and it is not preferred, skip
                if (!$okC && preg_match('/\b(mp4|mkv|avi|wmv|mov|m4v|webm)\b/i', $blob)) continue;
            }
            $q = strtolower((string)($item['quality'] ?? ''));
            if ($qualities) {
                $okQ = false;
                foreach ($qualities as $want) {
                    if ($want !== '' && ($q === $want || strpos($titleL, $want) !== false)) {
                        $okQ = true;
                        break;
                    }
                }
                // Keep unknown quality; drop only when a *different* known quality is present
                if (!$okQ && $q !== '' && !in_array($q, $qualities, true)) {
                    $known = ['2160p','4k','1080p','720p','480p','360p'];
                    if (in_array($q, $known, true)) continue;
                }
            }
            if ($langs) {
                $lang = strtolower((string)($item['language'] ?? ''));
                $okL = false;
                foreach ($langs as $want) {
                    if ($want === '') continue;
                    if ($lang === $want || strpos($titleL, $want) !== false) { $okL = true; break; }
                }
                // Only drop when a language was detected and it does not match filters
                if (!$okL && $lang !== '') continue;
            }
            $out[] = $item;
        }
        usort($out, function ($a, $b) use ($containers) {
            $ca = self::containerBoost($a, $containers);
            $cb = self::containerBoost($b, $containers);
            if ($ca !== $cb) return $cb - $ca;
            $qa = self::qualityRank($a['quality'] ?? '');
            $qb = self::qualityRank($b['quality'] ?? '');
            if ($qa !== $qb) return $qb - $qa;
            return ((int)($b['seeds'] ?? 0)) - ((int)($a['seeds'] ?? 0));
        });
        return $out;
    }

    private static function qualityRank(string $q): int {
        $q = strtolower($q);
        $map = ['2160p'=>400,'4k'=>400,'1080p'=>300,'720p'=>200,'480p'=>100,'360p'=>50];
        return $map[$q] ?? 0;
    }

    private static function extractSeeds($item, string $desc, string $title): ?int {
        // Torznab attr + Nyaa <nyaa:seeders> when present
        if (is_object($item) && method_exists($item, 'children')) {
            foreach (['http://torznab.com/schemas/2014/feed', 'torznab'] as $ns) {
                try {
                    $kids = $ns === 'torznab' ? $item->children('torznab', true) : $item->children($ns);
                    if ($kids && isset($kids->attr)) {
                        foreach ($kids->attr as $attr) {
                            $name = strtolower((string)($attr['name'] ?? ''));
                            if (in_array($name, ['seeders','seeds'], true)) {
                                return (int)((string)($attr['value'] ?? '0'));
                            }
                        }
                    }
                } catch (Throwable $e) { /* ignore */ }
            }
            foreach (['https://nyaa.si/xmlns/nyaa', 'nyaa'] as $ns) {
                try {
                    $kids = $ns === 'nyaa' ? $item->children('nyaa', true) : $item->children($ns);
                    if ($kids && isset($kids->seeders)) {
                        return (int)((string)$kids->seeders);
                    }
                } catch (Throwable $e) { /* ignore */ }
            }
        }
        if (preg_match('/\bseeders?[\s:=]+(\d+)/i', $desc . ' ' . $title, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private static function detectQuality(string $text): string {
        if (preg_match('/\b(2160p|4k|1080p|720p|480p|360p)\b/i', $text, $m)) {
            $q = strtolower($m[1]);
            return $q === '4k' ? '2160p' : $q;
        }
        return '';
    }

    private static function detectLanguage(string $text): string {
        if (preg_match('/\b(ENG|EN|English|MULTI|VOSTFR|FRENCH|GER|GERMAN|SPA|SPANISH|ITA|JAP|JPN)\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }

    /** Minimal public badge list (no full Transmission dump). */
    public static function publicDownloadBadges(): array {
        $cached=\Vaultcast\Repository::cacheGet('download-badges-v1');
        if(is_array($cached))return $cached;
        $lock=@fopen(STREAM_DATA.'/download_badges.lock','c');
        if(!$lock)return ['ok'=>true,'downloading'=>[]];
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return \Vaultcast\Repository::cacheGet('download-badges-last-v1') ?? ['ok'=>true,'downloading'=>[]];}
        try {
        $cached=\Vaultcast\Repository::cacheGet('download-badges-v1');
        if(is_array($cached))return $cached;
        $full = self::downloadStatus();
        $badges = [];
        foreach (($full['transmission']['torrents'] ?? []) as $t) {
            $m = $t['match'] ?? null;
            if (!$m) continue;
            $badges[] = [
                'show_guess' => $m['show_guess'] ?? null,
                'season' => $m['season'] ?? null,
                'episode' => $m['episode'] ?? null,
                'percent' => $t['percent'] ?? null,
                'status' => $t['status'] ?? 'downloading',
                'name' => $t['name'] ?? '',
            ];
        }
        foreach ($full['local_queue'] ?? [] as $e) {
            if (!empty($e['season']) && !empty($e['episode'])) {
                $st = $e['status'] ?? 'queued';
                if (in_array($st, ['cancelled','done','failed','exhausted','paused','removed'], true)) continue;
                $badges[] = [
                    'show_guess' => $e['show'] ?? null,
                    'season' => $e['season'],
                    'episode' => $e['episode'],
                    'percent' => null,
                    'status' => $st,
                    'name' => $e['title'] ?? $e['query'] ?? '',
                    'queue_id' => $e['id'] ?? null,
                ];
            }
        }
        $result = [
            'ok' => true,
            'downloading' => $badges,
            'transmission_reachable' => !empty($full['transmission']['reachable']),
        ];
        \Vaultcast\Repository::cachePut('download-badges-v1',$result,5);
        \Vaultcast\Repository::cachePut('download-badges-last-v1',$result,60);
        return $result;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }

    public static function cancelTransmission(int $torrentId, bool $deleteLocal = false): array {
        if ($torrentId < 1) return ['ok' => false, 'error' => 'Invalid torrent id'];
        $tr = Store::settings()['acquisition']['transmission'] ?? [];
        if (empty($tr['enabled'])) return ['ok' => false, 'error' => 'Transmission disabled'];
        $rpc = self::transmissionRpc($tr, [
            'method' => 'torrent-remove',
            'arguments' => [
                'ids' => [$torrentId],
                'delete-local-data' => $deleteLocal,
            ],
        ]);
        if (empty($rpc['ok'])) {
            return ['ok' => false, 'error' => $rpc['message'] ?? 'torrent-remove failed'];
        }
        return ['ok' => true, 'id' => $torrentId, 'delete_local_data' => $deleteLocal];
    }

    /** Cancel in-progress episode search / matching download by show+SxxExx. */
    public static function cancelEpisodeSearch(string $showTitle, int $season, int $episode): array {
        $code = sprintf('S%02dE%02d', $season, $episode);
        $cancelled = [];
        $lock = self::acquireLock();
        if (!$lock) return ['ok' => false, 'error' => 'Acquisition queue busy; retry shortly'];
        try {
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) $q = [];
        $showL = strtolower(trim($showTitle));
        foreach ($q as &$e) {
            $matchEp = ((int)($e['season'] ?? -1) === $season && (int)($e['episode'] ?? -1) === $episode);
            $qq = strtoupper((string)($e['query'] ?? $e['title'] ?? ''));
            $nameHas = strpos($qq, strtoupper($code)) !== false;
            $showOk = $showL === '' || strpos(strtolower((string)($e['show'] ?? '')), explode(' ', $showL)[0]) !== false
                || strpos(strtolower((string)($e['query'] ?? '')), explode(' ', $showL)[0]) !== false;
            if (($matchEp || $nameHas) && $showOk && !in_array($e['status'] ?? '', ['cancelled','done'], true)) {
                $e['status'] = 'cancelled';
                $e['message'] = 'Cancelled episode search';
                $e['cancelled_at'] = date('c');
                $cancelled[] = ['kind' => 'local', 'id' => $e['id'] ?? null];
            }
        }
        unset($e);
        Store::set('acquire_queue', $q);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $tr = Store::settings()['acquisition']['transmission'] ?? [];
        $st = self::transmissionStatus($tr);
        if (!empty($st['reachable'])) {
            foreach ($st['torrents'] as $t) {
                $m = $t['match'] ?? null;
                $name = strtoupper((string)($t['name'] ?? ''));
                $hit = ($m && (int)$m['season'] === $season && (int)$m['episode'] === $episode)
                    || strpos($name, strtoupper($code)) !== false;
                if (!$hit) continue;
                if ($showL) {
                    $token = explode(' ', $showL)[0];
                    $guess = strtolower((string)(($m['show_guess'] ?? '') . ' ' . ($t['name'] ?? '')));
                    if ($token && strpos($guess, $token) === false) continue;
                }
                $id = (int)($t['id'] ?? 0);
                if ($id > 0) {
                    $r = self::cancelTransmission($id);
                    if (!empty($r['ok'])) $cancelled[] = ['kind' => 'transmission', 'id' => $id, 'name' => $t['name'] ?? ''];
                }
            }
        }
        return ['ok' => true, 'cancelled' => $cancelled, 'count' => count($cancelled), 'query_code' => $code];
    }

    /**
     * Request a missing episode: search RSS with "Show SxxExx", enqueue best hit
     * to Transmission if reachable else local queue. If no search feeds configured,
     * still enqueues a placeholder search request.
     */
    public static function requestEpisode(string $showTitle, int $season, int $episode, array $opts = []): array {
        $showTitle = trim($showTitle);
        if ($showTitle === '' || $season < 0 || $episode < 1) {
            return ['ok' => false, 'error' => 'Valid show, season and episode required'];
        }
        $query = trim((string)($opts['query'] ?? ''));
        if ($query === '') $query = trim($showTitle . ' ' . sprintf('S%02dE%02d', $season, $episode));
        $kind = strtolower(trim((string)($opts['kind'] ?? 'episode_search')));
        $queued = self::enqueue([
            'title' => 'SEARCH: ' . $query,
            'query' => $query,
            'show' => $showTitle,
            'show_key' => $opts['show_key'] ?? null,
            'section_id' => $opts['section_id'] ?? null,
            'season' => $season,
            'episode' => $episode,
            'kind' => $kind,
            'airdate' => $opts['airdate'] ?? null,
        ]);
        if (empty($queued['ok'])) return ['ok' => false, 'error' => $queued['error'] ?? 'Could not queue episode'];
        $entry = $queued['entry'];
        return [
            'ok' => true,
            'queued' => true,
            'searched' => false,
            'deduplicated' => !empty($queued['deduplicated']),
            'query' => $query,
            'entry' => $entry,
            'reason' => ($entry['status'] ?? '') === 'scheduled'
                ? 'Scheduled for the day after the listed air date'
                : 'Queued for bounded background search',
        ];
    }

    /**
     * Resolve queued SEARCH intents (empty magnet + query) by searching feeds
     * and pushing hits to Transmission. Respects min_interval / max_per_hour.
     * Safe to call from cron or after bulk "fetch missing".
     */
    /** Info hash of a search hit (magnet btih or a 40-hex guid), lower case, or ''. */
    private static function itemHash(array $item): string {
        if (preg_match('/btih:([a-fA-F0-9]{40})/', (string)($item['magnet'] ?? ''), $m)) return strtolower($m[1]);
        $g = strtolower(trim((string)($item['guid'] ?? '')));
        return preg_match('/^[a-f0-9]{40}$/', $g) ? $g : '';
    }

    /** Stall settings (Settings -> Acquisition -> Stalled downloads). */
    public static function stallSettings(): array {
        $st = Store::settings()['acquisition']['stall'] ?? [];
        if (!is_array($st)) $st = [];
        return [
            'enabled' => !array_key_exists('enabled', $st) || !empty($st['enabled']),
            'minutes' => max(5, (int)($st['minutes'] ?? 30)),
            'no_peer_minutes' => max(5, (int)($st['no_peer_minutes'] ?? 15)),
            'max_attempts' => max(1, (int)($st['max_attempts'] ?? 3)),
        ];
    }

    /**
     * Torrents Vaultcast queued that made no progress for `minutes`, or had no peer sending data for
     * `no_peer_minutes` after metadata arrived: remove with data, blacklist the hash and search again.
     */
    private static function handleStalledTorrents(array $raw, array $tr): array {
        $cfg = self::stallSettings();
        if (!$cfg['enabled']) return [];
        $lock = self::acquireLock();
        if (!$lock) return [];
        try {
        $watch = Store::get('stall_watch', []);
        if (!is_array($watch)) $watch = [];
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) $q = [];
        $now = time();
        $seen = [];
        $acted = [];
        foreach ($raw as $tor) {
            if (!is_array($tor)) continue;
            $hash = strtolower((string)($tor['hashString'] ?? ''));
            if ($hash === '' || (float)($tor['percentDone'] ?? 0) >= 0.999) continue;
            $seen[$hash] = true;
            $status = (int)($tor['status'] ?? 0);
            $have = (int)($tor['haveValid'] ?? 0);
            $w = $watch[$hash] ?? ['have' => $have, 'progress_at' => $now, 'peer_at' => $now];
            // Only a downloading torrent can stall (stopped/paused or queued by the admin is left alone)
            if ($status !== 4) { $w['progress_at'] = $now; $w['peer_at'] = $now; $w['have'] = $have; $watch[$hash] = $w; continue; }
            if ($have > (int)$w['have']) { $w['have'] = $have; $w['progress_at'] = $now; }
            $meta = (float)($tor['metadataPercentComplete'] ?? 1) >= 1;
            if (!$meta || (int)($tor['peersSendingToUs'] ?? 0) > 0) $w['peer_at'] = $now;
            $watch[$hash] = $w;
            $noProgress = $now - (int)$w['progress_at'] >= $cfg['minutes'] * 60;
            $noPeers = $meta && $now - (int)$w['peer_at'] >= $cfg['no_peer_minutes'] * 60;
            if (!$noProgress && !$noPeers) continue;
            $idx = null;
            foreach ($q as $i => $e) {
                if (is_array($e) && strtolower((string)($e['torrent_hash'] ?? '')) === $hash) { $idx = $i; break; }
            }
            if ($idx === null) continue; // not ours: never touch torrents Vaultcast did not queue
            $rm = self::cancelTransmission((int)($tor['id'] ?? 0), true);
            if (empty($rm['ok'])) continue;
            unset($watch[$hash]);
            $e = $q[$idx];
            $failed = is_array($e['failed_hashes'] ?? null) ? $e['failed_hashes'] : [];
            if (!in_array($hash, $failed, true)) $failed[] = $hash;
            $e['failed_hashes'] = $failed;
            $e['attempts'] = (int)($e['attempts'] ?? 1) + 1;
            $why = $noPeers ? 'no peers for ' . $cfg['no_peer_minutes'] . ' min' : 'no progress for ' . $cfg['minutes'] . ' min';
            if ($e['attempts'] > $cfg['max_attempts'] || trim((string)($e['query'] ?? $e['title'] ?? '')) === '') {
                $e['status'] = 'failed';
                $e['message'] = 'Stalled (' . $why . '); gave up after ' . ($e['attempts'] - 1) . ' attempt(s)';
            } else {
                // Back to a search intent: processPendingSearches picks the best-seeded non-blacklisted hit
                if (trim((string)($e['query'] ?? '')) === '') $e['query'] = (string)$e['title'];
                $e['status'] = 'queued';
                $e['magnet'] = ''; $e['url'] = '';
                unset($e['torrent_id'], $e['torrent_hash']);
                $e['message'] = 'Stalled (' . $why . '); searching for another source';
            }
            $e['updated_at'] = date('c');
            $q[$idx] = $e;
            $acted[] = ['name' => (string)($tor['name'] ?? ''), 'reason' => $why, 'status' => $e['status']];
            self::importLog('STALL ' . ($tor['name'] ?? '') . ' — ' . $e['message']);
        }
        foreach (array_keys($watch) as $h) if (!isset($seen[$h])) unset($watch[$h]);
        Store::set('stall_watch', $watch);
        if ($acted) Store::set('acquire_queue', $q);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        // Never recursively acquire the search lock while holding the queue lock.
        if ($acted) self::processPendingSearches(['limit' => count($acted)]);
        return $acted;
    }

    public static function processPendingSearches(array $opts = []): array {
        $lock = self::acquireLock();
        if (!$lock) return ['ok' => true, 'skipped' => true, 'reason' => 'search already running', 'processed' => 0];
        try {
        $limit = max(1, min(2, (int)($opts['limit'] ?? 2)));
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) $q = [];
        $processed = [];
        $considered = 0;
        $deadline = microtime(true) + 20;
        $skipped = 0;
        $failed = [];
        $rateLimited = false;
        $retryIn = null;

        // Oldest due work goes first, including after a failed payload's cooldown.
        $order = array_keys($q);
        usort($order, static function ($a, $b) use ($q): int {
            $left = is_array($q[$a]) ? max((int)($q[$a]['eligible_at'] ?? 0), (int)($q[$a]['next_attempt_at'] ?? 0)) : 0;
            $right = is_array($q[$b]) ? max((int)($q[$b]['eligible_at'] ?? 0), (int)($q[$b]['next_attempt_at'] ?? 0)) : 0;
            return ($left <=> $right) ?: ($a <=> $b);
        });
        foreach ($order as $i) {
            $entry = $q[$i];
            if (microtime(true) >= $deadline || count($processed) >= $limit) break;
            if (!is_array($entry)) continue;
            $status = (string)($entry['status'] ?? '');
            if (!in_array($status, ['queued', 'queued_local', 'scheduled', 'retry_wait'], true)) continue;
            $eligible = (int)($entry['eligible_at'] ?? 0);
            if ($eligible > time() || (int)($entry['next_attempt_at'] ?? 0) > time()) continue;
            $magnet = trim((string)($entry['magnet'] ?? ''));
            $url = trim((string)($entry['url'] ?? ''));
            $query = trim((string)($entry['query'] ?? ''));
            // Eligibility was checked above, so scheduled payloads may now be sent.
            if ($magnet !== '' || $url !== '') {
                $tr = Store::settings()['acquisition']['transmission'] ?? [];
                // Disabled integrations must not consume the bounded work budget.
                if (empty($tr['enabled'])) continue;
                if (++$considered > $limit) break;
                $res = self::sendToTransmission($magnet, $url, $tr);
                $entry['status'] = !empty($res['ok']) ? 'sent_transmission' : 'queued_local';
                $entry['message'] = $res['message'] ?? '';
                $entry['updated_at'] = date('c');
                // Failed payloads yield to later queue entries on subsequent ticks.
                $entry['next_attempt_at'] = !empty($res['ok']) ? null : time() + 300;
                if (!empty($res['ok'])) {
                    if (!empty($res['id'])) $entry['torrent_id'] = (int)$res['id'];
                    if (!empty($res['hash'])) $entry['torrent_hash'] = strtolower((string)$res['hash']);
                    $processed[] = ['id' => $entry['id'] ?? null, 'title' => $entry['title'] ?? '', 'action' => 'sent_transmission'];
                }
                $q[$i] = $entry;
                Store::set('acquire_queue', $q);
                continue;
            }
            // Entries without actionable work must not consume the search budget.
            if ($query === '') {
                $skipped++;
                continue;
            }
            if (++$considered > $limit) break;
            $gate = self::canFetchNow();
            if (!($gate['ok'] ?? false)) {
                $rateLimited = true;
                $retryIn = $gate['retry_in'] ?? null;
                break;
            }

            $search = self::searchQuery($query);
            // Count completed queries that obtained feed responses, including cached results.
            // Empty/unavailable feeds are operational failures, not evidence of no release.
            $validAnswer = !empty($search['ok']) && (int)($search['feeds_searched'] ?? 0) > (int)($search['feed_errors'] ?? 0);
            if ($validAnswer) $entry['search_attempts'] = (int)($entry['search_attempts'] ?? 0) + 1;
            if (!($search['ok'] ?? false) && empty($search['items'])) {
                $rateLimited = !empty($search['retry_in']);
                $retryIn = $search['retry_in'] ?? $retryIn;
                if ($rateLimited) break;
                $entry['error_attempts'] = (int)($entry['error_attempts'] ?? 0) + 1;
                $entry['status'] = $entry['error_attempts'] >= 5 ? 'paused' : 'retry_wait';
                $entry['next_attempt_at'] = $entry['error_attempts'] >= 5 ? null : time() + min(21600, 600 * (1 << min(5, $entry['error_attempts'] - 1)));
                $entry['message'] = $search['reason'] ?? 'Search provider unavailable';
                $entry['updated_at'] = date('c');
                $q[$i] = $entry;
                Store::set('acquire_queue', $q);
                $failed[] = ['id' => $entry['id'] ?? null, 'query' => $query, 'reason' => $entry['message']];
                continue;
            }

            $picked = null;
            $banned = is_array($entry['failed_hashes'] ?? null) ? $entry['failed_hashes'] : [];
            foreach ($search['items'] ?? [] as $item) {
                if (empty($item['magnet']) && empty($item['enclosure']) && empty($item['link'])) continue;
                $ih = self::itemHash($item);
                if ($ih !== '' && in_array($ih, $banned, true)) continue;
                // Retries after a stall take the best-seeded alternative; first searches keep feed ranking
                if ($picked === null || ($banned && (int)($item['seeds'] ?? 0) > (int)($picked['seeds'] ?? 0))) $picked = $item;
                if (!$banned) break;
            }
            if (!$picked) {
                if ($validAnswer) {
                    self::postponeNoHit($entry);
                } else {
                    $entry['error_attempts'] = (int)($entry['error_attempts'] ?? 0) + 1;
                    $entry['status'] = $entry['error_attempts'] >= 5 ? 'paused' : 'retry_wait';
                    $entry['next_attempt_at'] = $entry['error_attempts'] >= 5 ? null : time() + min(21600, 600 * (1 << min(5, $entry['error_attempts'] - 1)));
                }
                $entry['message'] = 'No usable magnet/link in search results';
                $entry['updated_at'] = date('c');
                $q[$i] = $entry;
                Store::set('acquire_queue', $q);
                $failed[] = ['id' => $entry['id'] ?? null, 'query' => $query, 'reason' => $entry['message']];
                continue;
            }

            $entry['error_attempts'] = 0;
            $entry['next_attempt_at'] = null;
            $entry['title'] = $picked['title'] ?: $entry['title'];
            $entry['magnet'] = $picked['magnet'] ?? '';
            $entry['url'] = ($picked['enclosure'] ?? '') ?: ($picked['link'] ?? '');
            if (!empty($entry['magnet']) && preg_match('/btih:([a-fA-F0-9]{40})/', (string)$entry['magnet'], $hm)) {
                $entry['torrent_hash'] = strtolower($hm[1]);
            }
            $entry['updated_at'] = date('c');
            $entry['message'] = 'Matched: ' . ($picked['title'] ?? '');

            $tr = Store::settings()['acquisition']['transmission'] ?? [];
            if (!empty($tr['enabled']) && ($entry['magnet'] || $entry['url'])) {
                $res = self::sendToTransmission((string)$entry['magnet'], (string)$entry['url'], $tr);
                $entry['status'] = !empty($res['ok']) ? 'sent_transmission' : 'queued_local';
                $entry['message'] = ($entry['message'] . ' — ' . ($res['message'] ?? '')) ?: $entry['message'];
                if (!empty($res['id'])) $entry['torrent_id'] = (int)$res['id'];
                if (!empty($res['hash'])) $entry['torrent_hash'] = strtolower((string)$res['hash']);
            } else {
                $entry['status'] = 'queued_local';
                $entry['message'] = ($entry['message'] . ' — Transmission disabled/unreachable') ?: $entry['message'];
            }
            $q[$i] = $entry;
            Store::set('acquire_queue', $q);
            $processed[] = [
                'id' => $entry['id'] ?? null,
                'title' => $entry['title'] ?? '',
                'query' => $query,
                'status' => $entry['status'],
                'action' => 'searched',
            ];
        }

        return [
            'ok' => true,
            'processed' => count($processed),
            'items' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
            'rate_limited' => $rateLimited,
            'retry_in' => $retryIn,
        ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Store bulk episode intentions in one bounded, locked queue update. */
    private static function enqueueEpisodeBatch(array $requests): array {
        $lock = self::acquireLock();
        if (!$lock) return ['ok' => false, 'error' => 'Acquisition queue busy; retry shortly', 'entries' => []];
        try {
            $q = Store::get('acquire_queue', []);
            if (!is_array($q)) $q = [];
            $seen = [];
            $keyFor = static function (array $e, bool $byTitle = false): string {
                $section = strtolower(trim((string)($e['section_id'] ?? '')));
                $show = $byTitle ? '' : strtolower(trim((string)($e['show_key'] ?? '')));
                if ($show === '') $show = strtolower(trim((string)($e['show'] ?? '')));
                return $section . '|' . $show . '|' . (int)($e['season'] ?? -1) . '|' . (int)($e['episode'] ?? -1);
            };
            foreach ($q as $existing) {
                if (!is_array($existing) || in_array((string)($existing['status'] ?? ''), ['cancelled', 'removed', 'done', 'failed', 'exhausted'], true)) continue;
                $seen[$keyFor($existing)] = $existing;
                // Older installations stored the show title without a canonical key.
                if (empty($existing['show_key'])) $seen[$keyFor($existing, true)] = $existing;
            }
            $entries = [];
            $added = 0;
            $truncated = false;
            foreach ($requests as $item) {
                $key = $keyFor($item);
                $legacyKey = $keyFor($item, true);
                $previous = $seen[$key] ?? ($seen[$legacyKey] ?? null);
                if ($previous !== null) {
                    $entries[] = $previous;
                    continue;
                }
                if ($added >= 400 || count($q) >= 2000) { $truncated = true; continue; }
                $date = $item['airdate'] ?? null;
                $eligible = self::episodeEligibleAt($date);
                $entry = [
                    'id' => bin2hex(random_bytes(8)),
                    'title' => 'SEARCH: ' . $item['query'],
                    'magnet' => '', 'url' => '',
                    'status' => $eligible !== null && $eligible > time() ? 'scheduled' : 'queued',
                    'created_at' => date('c'), 'message' => '',
                    'query' => $item['query'], 'show' => $item['show'],
                    'show_key' => $item['show_key'] ?? null,
                    'airdate' => $date, 'eligible_at' => $eligible,
                    'next_attempt_at' => $eligible !== null ? max($eligible, time()) : time(),
                    'search_attempts' => 0, 'error_attempts' => 0,
                    'season' => $item['season'], 'episode' => $item['episode'],
                    'kind' => $item['kind'], 'section_id' => $item['section_id'],
                ];
                $q[] = $entry;
                $seen[$key] = $entry;
                $seen[$legacyKey] = $entry;
                $entries[] = $entry;
                $added++;
            }
            if ($added && !Store::set('acquire_queue', $q)) {
                return ['ok' => false, 'error' => 'Could not persist acquisition queue', 'entries' => []];
            }
            return ['ok' => true, 'entries' => $entries, 'added' => $added, 'truncated' => $truncated];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Restart a terminal/paused episode search without bypassing release eligibility. */
    public static function retryEpisodeQueueEntry(string $id): array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) return ['ok' => false, 'error' => 'Invalid queue ID'];
        $lock = self::acquireLock(true);
        if (!$lock) return ['ok' => false, 'error' => 'Acquisition queue busy; retry shortly'];
        try {
            $q = Store::get('acquire_queue', []);
            if (!is_array($q)) return ['ok' => false, 'error' => 'Queue unavailable'];
            foreach ($q as $i => $entry) {
                if (!is_array($entry) || (string)($entry['id'] ?? '') !== $id) continue;
                if (!in_array((string)($entry['status'] ?? ''), ['exhausted', 'paused', 'failed'], true)) {
                    return ['ok' => false, 'error' => 'Only paused, failed or exhausted searches may be retried'];
                }
                if (trim((string)($entry['query'] ?? '')) === '') return ['ok' => false, 'error' => 'This entry has no search query'];
                $eligible = self::episodeEligibleAt($entry['airdate'] ?? null);
                $entry['eligible_at'] = $eligible;
                $entry['next_attempt_at'] = $eligible !== null ? max($eligible, time()) : time();
                $entry['status'] = $eligible !== null && $eligible > time() ? 'scheduled' : 'queued';
                $entry['search_attempts'] = 0;
                $entry['error_attempts'] = 0;
                $entry['magnet'] = '';
                $entry['url'] = '';
                $entry['message'] = 'Search reset by administrator';
                $entry['updated_at'] = date('c');
                unset($entry['torrent_id'], $entry['torrent_hash']);
                $q[$i] = $entry;
                if (!Store::set('acquire_queue', $q)) return ['ok' => false, 'error' => 'Unable to persist retry'];
                return ['ok' => true, 'entry' => $entry];
            }
            return ['ok' => false, 'error' => 'Queue entry not found'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Queue fetch for every missing episode in a show detail payload. */
    public static function fetchMissingForShow(array $detail, ?int $onlySeason = null, ?int $onlyEpisode = null): array {
        $show = $detail['show'] ?? [];
        $title = $show['title'] ?? '';
        $queued = [];
        $errors = [];
        $sentNow = 0;
        $requests = [];
        foreach ($detail['seasons'] ?? [] as $seasonBlock) {
            $sNum = (int)($seasonBlock['season'] ?? 0);
            if ($onlySeason !== null && $sNum !== $onlySeason) continue;
            foreach ($seasonBlock['episodes'] ?? [] as $ep) {
                if (!empty($ep['available'])) continue;
                $eNum = $ep['episode'] ?? null;
                if ($eNum === null) continue;
                $eNum = (int)$eNum;
                if ($onlyEpisode !== null && $eNum !== $onlyEpisode) continue;
                // Skip specials (season 0) unless caller asked for that season explicitly
                if ($sNum <= 0 && $onlySeason === null) continue;
                $showSection = (string)($show['section'] ?? '');
                $typeKind = ($showSection === 'anime' || ($show['type'] ?? '') === 'anime') ? 'anime' : 'tv';
                $requests[] = [
                    'show' => $title,
                    'query' => trim($title . ' ' . sprintf('S%02dE%02d', $sNum, $eNum)),
                    'kind' => $typeKind,
                    'show_key' => $show['show_key'] ?? null,
                    'airdate' => $ep['airdate'] ?? null,
                    'section_id' => $showSection !== '' ? $showSection : null,
                    'season' => $sNum,
                    'episode' => $eNum,
                ];
            }
        }

        $batch = self::enqueueEpisodeBatch($requests);
        $queued = $batch['entries'] ?? [];
        if (empty($batch['ok'])) $errors[] = ['error' => $batch['error'] ?? 'Queue write failed'];
        if (!empty($batch['truncated'])) $errors[] = ['error' => 'Only first 400 missing episodes scheduled; repeat for remaining episodes.'];

        // Bulk requests only register intentions; search occurs in bounded worker ticks.
        $drained = ['ok' => true, 'processed' => 0, 'items' => [], 'rate_limited' => false];

        return [
            'ok' => !empty($batch['ok']) && empty($batch['truncated']),
            'show' => $title,
            'queued' => $queued,
            'count' => count($queued),
            'new_requests' => (int)($batch['added'] ?? 0),
            'reason' => !empty($batch['truncated']) ? 'Queue capacity reached; repeat after removing old requests' : ($batch['error'] ?? null),
            'sent_transmission' => $sentNow,
            'pending_searches' => $drained,
            'errors' => $errors,
            'note' => 'Episodes have been scheduled. Due searches are processed by the existing downloads worker.',
        ];
    }

    /**
     * Remove a download from the local queue and from Transmission.
     * delete_local_data deletes that torrent's files only when the download
     * directory is a known download root and not a library section.
     */
    public static function removeFromDownloads(array $b): array {
        $delete = !empty($b['delete_local_data']);
        $kind = (string)($b['kind'] ?? '');
        $id = $b['id'] ?? '';
        $tr = Store::settings()['acquisition']['transmission'] ?? [];
        $warnings = [];
        $errors = [];
        $removedTorrents = [];
        $removedQueue = [];
        $targets = [];

        $rpcUp = !empty($tr['enabled']) && self::transmissionReachable($tr);
        if (!empty($tr['enabled']) && !$rpcUp) {
            if ($delete) {
                return ['ok' => false, 'error' => 'Transmission RPC unreachable; refused to delete files', 'delete_local_data' => true];
            }
            $warnings[] = 'Transmission RPC unreachable; queue updated but the torrent may still be present';
        }

        if ($kind === 'local' || $kind === 'queue') {
            $sid = (string)$id;
            $entry = self::queueEntryById($sid);
            if (!$entry) return ['ok' => false, 'error' => 'Queue entry not found'];
            if ($rpcUp) {
                foreach (self::listTorrentsRaw($tr) as $tor) {
                    if (is_array($tor) && self::torrentMatchesEntry($tor, $entry)) $targets[] = $tor;
                }
                if (!$targets) $warnings[] = 'No matching Transmission torrent';
            }
        } elseif ($kind === 'transmission') {
            $tid = (int)$id;
            if ($tid < 1) return ['ok' => false, 'error' => 'Invalid torrent id'];
            if (empty($tr['enabled'])) return ['ok' => false, 'error' => 'Transmission disabled'];
            if ($rpcUp) {
                foreach (self::listTorrentsRaw($tr) as $tor) {
                    if (is_array($tor) && (int)($tor['id'] ?? 0) === $tid) {
                        $targets[] = $tor;
                        break;
                    }
                }
                if (!$targets) $warnings[] = 'Torrent not in Transmission';
            }
        } else {
            return ['ok' => false, 'error' => 'kind must be local|transmission|queue'];
        }

        foreach ($targets as $tor) {
            $tid = (int)($tor['id'] ?? 0);
            if ($tid < 1) continue;
            if ($delete && !self::downloadDirAllowsDelete((string)($tor['downloadDir'] ?? ''))) {
                $errors[] = 'Refusing to delete files for "' . ($tor['name'] ?? (string)$tid) . '" (not a download folder)';
                continue;
            }
            $r = self::cancelTransmission($tid, $delete);
            if (empty($r['ok'])) {
                $errors[] = $r['error'] ?? ('Failed to remove torrent ' . $tid);
                continue;
            }
            $removedTorrents[] = $tid;
            $removedQueue = array_merge($removedQueue, self::markQueueRemovedForTorrent($tor, $delete));
        }

        if ($errors) {
            return [
                'ok' => false,
                'error' => implode('; ', $errors),
                'warnings' => $warnings,
                'removed_torrents' => $removedTorrents,
                'removed_queue' => array_values(array_unique($removedQueue)),
                'delete_local_data' => $delete,
            ];
        }

        if ($kind === 'local' || $kind === 'queue') {
            $removedQueue = array_merge($removedQueue, self::markQueueRemovedById((string)$id, $delete));
        }

        self::importLog(
            'REMOVE kind=' . $kind
            . ' id=' . (string)$id
            . ' delete=' . ($delete ? '1' : '0')
            . ' torrents=' . implode(',', $removedTorrents)
        );

        return [
            'ok' => true,
            'removed_torrents' => $removedTorrents,
            'removed_queue' => array_values(array_unique(array_filter($removedQueue))),
            'warnings' => $warnings,
            'delete_local_data' => $delete,
        ];
    }

    public static function requestCancelProcessCompleted(): array {
        $f = STREAM_DATA . '/process_completed.cancel';
        @file_put_contents($f, date('c'));
        return ['ok' => true, 'cancel' => true];
    }

    /**
     * Move finished Transmission downloads into the library, then remove the
     * torrent and its leftover download data. Idempotent via acquire_imports.
     */
    public static function processCompletedDownloads(array $opts = []): array {
        $settings = Store::settings();
        $acq = $settings['acquisition'] ?? [];
        $source = (string)($opts['source'] ?? 'manual');

        // Always try to resolve pending SEARCH queue entries (rate-limited).
        // This is what makes bulk "fetch missing" episodes eventually reach Transmission.
        $pendingSearches = self::processPendingSearches([
            'limit' => (int)($opts['search_limit'] ?? ($source === 'cron' ? 2 : 5)),
        ]);

        $autoOn = !array_key_exists('auto_process_completed', $acq) || !empty($acq['auto_process_completed']);
        if ($source === 'cron' && !$autoOn && empty($opts['force'])) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'auto_process_completed disabled',
                'processed' => 0,
                'pending_searches' => $pendingSearches,
            ];
        }
        $tr = $acq['transmission'] ?? [];
        if (empty($tr['enabled'])) {
            return ['ok' => false, 'error' => 'Transmission disabled', 'processed' => 0, 'pending_searches' => $pendingSearches];
        }
        if (!self::transmissionReachable($tr)) {
            return ['ok' => false, 'error' => 'Transmission RPC unreachable', 'processed' => 0, 'pending_searches' => $pendingSearches];
        }

        $lockPath = STREAM_DATA . '/process_completed.lock';
        $lock = @fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return ['ok' => true, 'skipped' => true, 'reason' => 'already running', 'processed' => 0];
        }

        $cancelFile = STREAM_DATA . '/process_completed.cancel';
        @unlink($cancelFile);

        $limit = max(1, min(20, (int)($opts['limit'] ?? ($source === 'cron' ? 3 : 8))));
        $removeTorrent = !array_key_exists('remove_torrent', $opts) || !empty($opts['remove_torrent']);
        $results = [];
        $processed = 0;
        $cancelled = false;
        $seen = 0;

        try {
            $raw = self::listTorrentsRaw($tr);
            $stalled = self::handleStalledTorrents($raw, $tr);
            $queue = Store::get('acquire_queue', []);
            if (!is_array($queue)) $queue = [];
            foreach ($raw as $tor) {
            Maintenance::checkpoint();
                if ($processed >= $limit || $seen >= 40) break;
                if (is_file($cancelFile)) { $cancelled = true; break; }
                if (!is_array($tor)) continue;
                $pct = (float)($tor['percentDone'] ?? 0);
                if ($pct < 0.999) continue;
                $seen++;
                $one = self::importFinishedTorrent($tor, $queue, $tr, $removeTorrent, $acq);
                $results[] = $one;
                $label = !empty($one['ok']) ? 'OK' : 'SKIP';
                self::importLog($label . ' ' . ($tor['name'] ?? '') . ' — ' . ($one['message'] ?? $one['error'] ?? ''));
                if (!empty($one['imported']) || !empty($one['removed'])) $processed++;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($cancelFile);
        }

        return [
            'ok' => true,
            'processed' => $processed,
            'seen' => $seen,
            'cancelled' => $cancelled,
            'results' => $results,
            'source' => $source,
            'stalled' => $stalled ?? [],
            'pending_searches' => $pendingSearches ?? null,
        ];
    }

    private static function importFinishedTorrent(array $tor, array $queue, array $tr, bool $removeTorrent, array $acq): array {
        $name = (string)($tor['name'] ?? '');
        $hash = strtolower((string)($tor['hashString'] ?? ''));
        $tid = (int)($tor['id'] ?? 0);
        $key = $hash !== '' ? $hash : ('id:' . $tid);
        $entry = self::matchQueueEntry($tor, $queue);
        $records = self::importRecords();
        $prev = $records[$key] ?? null;

        // Ingest inbox — ask the admin (TV / Movie / Anime + online match) before importing
        $prevImported = is_array($prev) && ($prev['status'] ?? '') === 'imported';
        if (!$prevImported && class_exists('Ingest') && Ingest::needsReview($tor, $entry, $acq)) {
            Ingest::noteAwaiting($tor, $entry);
            return ['ok' => true, 'imported' => false, 'removed' => false, 'review' => true, 'name' => $name, 'message' => 'Awaiting review in Settings -> Ingest inbox'];
        }

        $files = self::selectImportFiles($tor, $entry, $acq);
        if (isset($files['error'])) {
            return ['ok' => false, 'imported' => false, 'name' => $name, 'error' => $files['error']];
        }
        $picked = $files['files'];

        $dests = [];
        $sources = [];
        if (is_array($prev) && ($prev['status'] ?? '') === 'imported') {
            $prevDests = is_array($prev['dests'] ?? null) ? $prev['dests'] : [];
            $allThere = $prevDests && self::allFilesExist($prevDests);
            if ($allThere) {
                $dests = $prevDests;
                $left = self::otherLargeVideosRemain($tor, []);
                if ($left) {
                    return [
                        'ok' => true,
                        'imported' => false,
                        'removed' => false,
                        'name' => $name,
                        'message' => 'Already imported; left ' . count($left) . ' other video file(s) in the torrent',
                        'dests' => $dests,
                    ];
                }
                if ($removeTorrent) {
                    $rm = self::removeTorrentIfSafe($tor, $tr, $dests);
                    if (empty($rm['ok'])) {
                        return ['ok' => false, 'imported' => false, 'removed' => false, 'name' => $name, 'error' => $rm['error'] ?? 'remove failed', 'dests' => $dests];
                    }
                    self::saveImportRecord($key, $name, $tid, $hash, 'imported', $dests, !empty($rm['removed']));
                    self::markQueueDoneForTorrent($tor, $dests);
                    return ['ok' => true, 'imported' => false, 'removed' => !empty($rm['removed']), 'name' => $name, 'message' => 'Already imported', 'dests' => $dests];
                }
                return ['ok' => true, 'imported' => false, 'name' => $name, 'message' => 'Already imported', 'dests' => $dests];
            }
        }

        if (!$picked) {
            return ['ok' => false, 'imported' => false, 'name' => $name, 'error' => 'No media file to import'];
        }

        $placed = [];
        $stopped = false;
        $toCatalogue = [];
        try {
        foreach ($picked as $file) {
            $src = $file['path'];
            $sources[] = $src;
            $meta = self::metaForFile($src, $entry);
            if (($meta['kind'] ?? '') === 'tv') {
                if (empty($meta['show']) || strcasecmp((string)$meta['show'], 'Unknown Show') === 0 || $meta['season'] === null || $meta['episode'] === null) {
                    return ['ok' => false, 'imported' => false, 'name' => $name, 'error' => 'Could not identify show/episode safely', 'file' => basename($src)];
                }
            } else {
                $title = trim((string)($meta['title'] ?? ''));
                if ($title === '' || strcasecmp($title, 'Unknown') === 0 || strlen($title) < 2) {
                    return ['ok' => false, 'imported' => false, 'name' => $name, 'error' => 'Could not identify movie title safely', 'file' => basename($src)];
                }
            }
            $sectionId = self::chooseSection($meta, $entry);
            if (!$sectionId) {
                return ['ok' => false, 'imported' => false, 'name' => $name, 'error' => 'No library section for this file', 'file' => basename($src)];
            }
            $dup = self::libraryDuplicate($src, $meta);
            if ($dup) {
                $dest = (string)($dup['path'] ?? '');
                if ($dest === '' || !is_file($dest)) {
                    return ['ok' => false, 'imported' => false, 'name' => $name, 'error' => 'Duplicate match had no file'];
                }
                $dests[] = $dest;
                $placed[] = ['source' => $src, 'dest' => $dest, 'section_id' => $dup['section'] ?? $sectionId, 'moved' => false, 'duplicate' => true];
                continue;
            }
            // Seeding torrents hold their files open: stop it right before the first move
            if (!$stopped) { self::stopTorrent($tor); $stopped = true; }
            $place = self::placeImportedFile($src, $meta, $sectionId);
            if (empty($place['ok'])) {
                return [
                    'ok' => false,
                    'imported' => false,
                    'name' => $name,
                    'error' => $place['error'] ?? 'Move failed',
                    'file' => basename($src),
                    'dests' => $dests,
                ];
            }
            $dest = (string)($place['dest'] ?? '');
            if ($dest === '') {
                return ['ok' => false, 'imported' => false, 'name' => $name, 'error' => 'Move did not return a destination'];
            }
            $dests[] = $dest;
            $placed[] = ['source' => $src, 'dest' => $dest, 'section_id' => $sectionId, 'moved' => !empty($place['moved'])];
            if (!empty($place['moved'])) $toCatalogue[] = [$dest, $sectionId];
        }
        } finally {
            // One catalogue write for every file moved in this torrent, also when a later file failed
            if ($toCatalogue) Catalogue::ingestFiles($toCatalogue);
        }

        $removed = false;
        $left = self::otherLargeVideosRemain($tor, $sources);
        if ($left) {
            self::saveImportRecord($key, $name, $tid, $hash, 'imported', $dests, false);
            self::markQueueDoneForTorrent($tor, $dests);
            return [
                'ok' => true,
                'imported' => true,
                'removed' => false,
                'name' => $name,
                'message' => 'Imported ' . count($dests) . ' file(s); left ' . count($left) . ' other video file(s), so torrent data was not deleted',
                'dests' => $dests,
                'placed' => $placed,
            ];
        }
        if ($removeTorrent) {
            $rm = self::removeTorrentIfSafe($tor, $tr, $dests);
            if (empty($rm['ok'])) {
                self::saveImportRecord($key, $name, $tid, $hash, 'imported', $dests, false);
                self::markQueueDoneForTorrent($tor, $dests);
                return [
                    'ok' => false,
                    'imported' => true,
                    'removed' => false,
                    'name' => $name,
                    'error' => 'Imported but torrent remove failed: ' . ($rm['error'] ?? ''),
                    'dests' => $dests,
                    'placed' => $placed,
                ];
            }
            $removed = !empty($rm['removed']);
        }

        self::saveImportRecord($key, $name, $tid, $hash, 'imported', $dests, $removed);
        self::markQueueDoneForTorrent($tor, $dests);
        $msg = 'Imported ' . count($dests) . ' file' . (count($dests) === 1 ? '' : 's');
        if ($removed) $msg .= ' and removed torrent data';
        return ['ok' => true, 'imported' => true, 'removed' => $removed, 'name' => $name, 'message' => $msg, 'dests' => $dests, 'placed' => $placed];
    }


    /** Large video files from this torrent that are still on disk and were not the files just imported. */
    private static function otherLargeVideosRemain(array $tor, array $sources): array {
        $dir = (string)($tor['downloadDir'] ?? '');
        if ($dir === '') return [];
        $taken = [];
        foreach ($sources as $s) {
            if (!is_string($s) || $s === '') continue;
            $real = \Vaultcast\Paths::real($s);
            $taken[$real !== false ? $real : $s] = true;
        }
        $exts = Library::videoExtensions();
        $left = [];
        foreach ($tor['files'] ?? [] as $f) {
            if (!is_array($f)) continue;
            $rel = str_replace('\\', '/', (string)($f['name'] ?? ''));
            if ($rel === '' || strpos($rel, '..') !== false) continue;
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true)) continue;
            if (self::isSampleName($rel)) continue;
            $length = (int)($f['length'] ?? 0);
            if ($length > 0 && $length < 20 * 1024 * 1024) continue;
            $full = rtrim($dir, '/') . '/' . $rel;
            if (!is_file($full)) continue;
            $real = \Vaultcast\Paths::real($full);
            if ($real === false) continue;
            if (isset($taken[$real])) continue;
            $left[] = $real;
        }
        return $left;
    }

    /** torrent-stop before files are moved out, so Transmission stops seeding/verifying files that are going away. */
    public static function stopTorrent(array $tor): bool {
        $tid = (int)($tor['id'] ?? 0);
        $tr = Store::settings()['acquisition']['transmission'] ?? [];
        if ($tid < 1 || empty($tr['enabled'])) return false;
        if (isset($tor['status']) && (int)$tor['status'] === 0) return true;
        $r = self::transmissionRpc($tr, ['method' => 'torrent-stop', 'arguments' => ['ids' => [$tid]]]);
        return !empty($r['ok']);
    }

    private static function removeTorrentIfSafe(array $tor, array $tr, array $dests): array {
        $tid = (int)($tor['id'] ?? 0);
        $dir = (string)($tor['downloadDir'] ?? '');
        if ($tid < 1) return ['ok' => false, 'error' => 'Missing torrent id'];
        if (!self::downloadDirAllowsDelete($dir)) {
            return ['ok' => false, 'error' => 'Refusing to delete torrent data outside download folders'];
        }
        foreach ($dests as $dest) {
            if (!is_string($dest) || $dest === '') return ['ok' => false, 'error' => 'Missing destination'];
            if (!is_file($dest)) return ['ok' => false, 'error' => 'Destination missing, not deleting download data'];
            if (self::pathUnder($dest, $dir)) {
                return ['ok' => false, 'error' => 'Destination is still inside the download folder'];
            }
            if (!self::fileUnderSection($dest)) {
                return ['ok' => false, 'error' => 'Destination is not inside a library section'];
            }
        }
        $r = self::cancelTransmission($tid, true);
        if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'torrent-remove failed'];
        return ['ok' => true, 'removed' => true];
    }


    /** Same-sized catalogue file whose title matches. Skips creating a second copy. */
    private static function libraryDuplicate(string $src, array $meta): ?array {
        $size = @filesize($src) ?: 0;
        if ($size < 50 * 1024 * 1024) return null;
        $srcReal = \Vaultcast\Paths::real($src);
        $want = self::looseName((string)($meta['title'] ?? $meta['show'] ?? ''));
        if (strlen($want) < 6) return null;
        $cat = Catalogue::get();
        foreach ($cat['items'] ?? [] as $it) {
            if (!is_array($it)) continue;
            if ((int)($it['size'] ?? 0) !== (int)$size) continue;
            $p = (string)($it['path'] ?? '');
            if ($p === '' || !is_file($p)) continue;
            $pr = \Vaultcast\Paths::real($p);
            if ($pr !== false && $srcReal !== false && $pr === $srcReal) continue;
            $have = self::looseName((string)($it['title'] ?? '') . ' ' . (string)($it['show'] ?? '') . ' ' . basename($p));
            if ($have === '') continue;
            if ($have === $want || strpos($have, $want) !== false || strpos($want, $have) !== false) {
                return $it;
            }
        }
        return null;
    }

    private static function placeImportedFile(string $src, array $meta, string $sectionId): array {
        $body = [
            'path' => $src,
            'section_id' => $sectionId,
            'show' => $meta['show'] ?? ($meta['title'] ?? ''),
            'title' => $meta['title'] ?? ($meta['show'] ?? ''),
            'year' => $meta['year'] ?? null,
            'season' => $meta['season'] ?? null,
            'episode' => $meta['episode'] ?? null,
        ];
        $preview = Library::preview($body);
        if (empty($preview['ok'])) return $preview;
        if (!empty($preview['conflict'])) {
            $srcSize = @filesize($src) ?: 0;
            $dstSize = @filesize($preview['dest']) ?: 0;
            $close = $srcSize > 0 && $dstSize > 0 && abs($srcSize - $dstSize) <= max(65536, (int)($srcSize * 0.02));
            if (!$close) {
                return ['ok' => false, 'error' => 'Destination already exists with a different size', 'dest' => $preview['dest']];
            }
            return ['ok' => true, 'moved' => false, 'already' => true, 'dest' => $preview['dest'], 'source' => $src];
        }
        return Library::apply($body + ['defer_catalogue' => true]);
    }

    private static function selectImportFiles(array $tor, ?array $entry, array $acq): array {
        $dir = (string)($tor['downloadDir'] ?? '');
        if ($dir === '' || !is_dir($dir)) return ['error' => 'Download directory missing'];
        if (!self::downloadDirAllowsDelete($dir) && !self::dirIsDownloadRoot($dir)) {
            return ['error' => 'Torrent download directory is not a known download folder'];
        }
        $exts = Library::videoExtensions();
        $videos = [];
        foreach ($tor['files'] ?? [] as $f) {
            if (!is_array($f)) continue;
            $rel = str_replace('\\', '/', (string)($f['name'] ?? ''));
            if ($rel === '' || strpos($rel, "\0") !== false || strpos($rel, '../') !== false || strpos($rel, '/..') !== false) continue;
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true)) continue;
            if (self::isSampleName($rel)) continue;
            $full = rtrim($dir, '/') . '/' . $rel;
            if (!is_file($full)) continue;
            $real = \Vaultcast\Paths::real($full);
            if ($real === false || !self::pathUnder($real, $dir)) continue;
            $videos[] = [
                'path' => $real,
                'name' => $rel,
                'ext' => $ext,
                'length' => (int)($f['length'] ?? (@filesize($real) ?: 0)),
            ];
        }
        if (!$videos) return ['files' => []];

        $big = [];
        foreach ($videos as $v) {
            if ($v['length'] >= 20 * 1024 * 1024) $big[] = $v;
        }
        if ($big) $videos = $big;

        $season = null;
        $episode = null;
        if ($entry && isset($entry['season']) && $entry['season'] !== null && $entry['season'] !== ''
            && isset($entry['episode']) && $entry['episode'] !== null && $entry['episode'] !== '') {
            $season = (int)$entry['season'];
            $episode = (int)$entry['episode'];
        }
        if ($season !== null && $episode !== null) {
            $matched = [];
            foreach ($videos as $v) {
                if (self::episodeCodeInName($v['name'], $season, $episode)) $matched[] = $v;
            }
            if (!$matched) {
                return ['error' => sprintf('No file matched S%02dE%02d', $season, $episode)];
            }
            $best = self::preferContainer($matched, $acq);
            return ['files' => $best ? [$best] : []];
        }

        $groups = [];
        foreach ($videos as $v) {
            $parsed = Library::parseFilename($v['path']);
            if (($parsed['kind'] ?? '') === 'tv' && $parsed['season'] !== null && $parsed['episode'] !== null) {
                $gkey = $parsed['season'] . 'x' . $parsed['episode'];
            } else {
                $gkey = '_single';
            }
            $groups[$gkey][] = $v;
        }
        $picked = [];
        foreach ($groups as $group) {
            $best = self::preferContainer($group, $acq);
            if ($best) $picked[] = $best;
            if (count($picked) >= 24) break;
        }
        return ['files' => $picked];
    }

    private static function preferContainer(array $files, array $acq): ?array {
        $pref = [];
        foreach ($acq['preferred_containers'] ?? ['mp4'] as $c) {
            $c = strtolower(trim((string)$c, " ."));
            if ($c !== '') $pref[] = $c;
        }
        if (!$pref) $pref = ['mp4'];
        $pool = [];
        foreach ($files as $f) {
            if (in_array($f['ext'], $pref, true)) $pool[] = $f;
        }
        if (!$pool) $pool = $files;
        $best = null;
        $bestScore = -1;
        foreach ($pool as $f) {
            $rank = array_search($f['ext'], $pref, true);
            $prefScore = ($rank === false) ? 0 : (100 - (int)$rank);
            if ($f['ext'] === 'mp4' || $f['ext'] === 'm4v') $prefScore += 5;
            $score = ($prefScore * 100000000000) + (int)$f['length'];
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $f;
            }
        }
        return $best;
    }

    private static function metaForFile(string $path, ?array $entry): array {
        $parsed = Library::parseFilename($path);
        if (!$entry) return $parsed;
        $kind = strtolower((string)($entry['kind'] ?? ''));
        $hasEp = isset($entry['season']) && $entry['season'] !== null && $entry['season'] !== ''
            && isset($entry['episode']) && $entry['episode'] !== null && $entry['episode'] !== '';
        if (!empty($entry['show'])) {
            $parsed['show'] = trim((string)$entry['show']);
        }
        if ($hasEp) {
            $parsed['season'] = (int)$entry['season'];
            $parsed['episode'] = (int)$entry['episode'];
            $parsed['kind'] = 'tv';
            if (!empty($parsed['show'])) $parsed['title'] = $parsed['show'];
        }
        if (in_array($kind, ['anime', 'tv', 'episode_hit', 'episode_search'], true) && ($hasEp || ($parsed['kind'] ?? '') === 'tv')) {
            $parsed['kind'] = 'tv';
        }
        if (in_array($kind, ['movie', 'movies', 'disney'], true) && ($parsed['kind'] ?? '') !== 'tv') {
            $parsed['kind'] = 'movie';
        }
        if (($parsed['kind'] ?? '') === 'tv' && (empty($parsed['show']) || $parsed['show'] === 'Unknown Show') && !empty($entry['show'])) {
            $parsed['show'] = trim((string)$entry['show']);
        }
        if (($parsed['kind'] ?? '') !== 'tv') {
            $clean = self::movieTitleFromBasename(basename($path));
            if ($clean['title'] !== '') {
                $parsed['kind'] = 'movie';
                $parsed['title'] = $clean['title'];
                if (!empty($clean['year'])) $parsed['year'] = $clean['year'];
            }
        }
        return $parsed;
    }

    /** Movie title from a release filename. Keeps sequel numbers; drops codec/size tags. */
    private static function movieTitleFromBasename(string $filename): array {
        $base = preg_replace('/\.[^.]+$/', '', $filename);
        $base = str_replace(['.', '_'], ' ', (string)$base);
        $base = preg_replace('/\[.*?\]/', ' ', (string)$base);
        $year = null;
        if (preg_match('/\(((?:19|20)\d{2})\)/', (string)$base, $ym)) {
            $year = (int)$ym[1];
        }
        $base = preg_replace('/\((?:19|20)\d{2}\)/', ' ', (string)$base);
        if ($year === null && preg_match('/(?:^|[\s\-])((?:19|20)\d{2})(?=[\s\-)]|$)/', (string)$base, $m, PREG_OFFSET_CAPTURE)) {
            $year = (int)$m[1][0];
            $base = substr((string)$base, 0, $m[1][1]) . ' ' . substr((string)$base, $m[1][1] + strlen($m[1][0]));
        }
        $tags = '2160p|1080p|720p|480p|360p|4k|uhd|hdr10|hdr|bluray|blu ray|bdrip|brrip|webrip|web dl|webdl|hdtv|hdrip|x264|x265|h264|h265|hevc|avc|xvid|divx|aac|dts|truehd|atmos|eac3|ddp|dd|ac3|10bit|8bit|yify|yts|rarbg|imax|remux|proper|repack|extended|multi|dual|amzn|nf|hmax|dsnp|galaxyrg|rmteam|bone|tgx|ethel|psa|sparks|geckos|readnfo|nfo|sample|internal|6ch|2ch|8ch';
        $base = preg_replace('/\b(?:' . $tags . ')\b/i', ' ', (string)$base);
        $base = preg_replace('/\b\d+(?:\.\d+)?\s*(?:mb|gb)\b/i', ' ', (string)$base);
        $base = preg_replace('/\b(?:dd|dts|aac|ddp|eac3|ac3)?\s*[257]\s+1\b/i', ' ', (string)$base);
        $base = preg_replace('/\b(?:dd|dts|aac|ddp|eac3|ac3)[257]\s+1\b/i', ' ', (string)$base);
        $base = trim((string)preg_replace('/\s+/', ' ', (string)$base), " .-_");
        if ($base !== '' && $base === strtolower($base)) {
            $base = self::titleCaseMedia($base);
        }
        return ['title' => $base, 'year' => $year];
    }

    private static function titleCaseMedia(string $s): string {
        $small = ['a', 'an', 'the', 'of', 'and', 'or', 'in', 'on', 'for', 'to'];
        $parts = preg_split('/\s+/', trim($s));
        if (!is_array($parts)) return $s;
        $out = [];
        $n = count($parts);
        foreach ($parts as $i => $w) {
            $l = strtolower($w);
            if ($i > 0 && $i < $n - 1 && in_array($l, $small, true)) $out[] = $l;
            else $out[] = strtoupper(substr($w, 0, 1)) . substr($l, 1);
        }
        return implode(' ', $out);
    }

    private static function chooseSection(array $meta, ?array $entry): ?string {
        $enabled = [];
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            if (!empty($sec['enabled']) && !empty($sec['id'])) $enabled[$sec['id']] = $sec;
        }
        $isTv = ($meta['kind'] ?? '') === 'tv';
        $explicit = is_array($entry) ? (string)($entry['section_id'] ?? '') : '';
        if ($explicit !== '' && isset($enabled[$explicit])) {
            $type = $enabled[$explicit]['type'] ?? '';
            if ($isTv && in_array($type, ['tv', 'anime'], true)) return $explicit;
            if (!$isTv && $type === 'movies') return $explicit;
        }
        $kind = is_array($entry) ? strtolower((string)($entry['kind'] ?? '')) : '';
        if ($isTv) {
            $hit = self::existingShowSection((string)($meta['show'] ?? ''));
            if ($hit && isset($enabled[$hit])) return $hit;
            if ($kind === 'anime' && isset($enabled['anime'])) return 'anime';
            if (isset($enabled['tv'])) return 'tv';
            if (isset($enabled['anime'])) return 'anime';
            return null;
        }
        $title = trim((string)($meta['title'] ?? ''));
        $year = $meta['year'] ?? null;
        $hit = self::existingMovieSection($title, $year);
        if ($hit && isset($enabled[$hit])) return $hit;
        if ($kind === 'disney' && isset($enabled['disney'])) return 'disney';
        if (isset($enabled['movies'])) return 'movies';
        return null;
    }

    private static function existingShowSection(string $show): ?string {
        $want = self::looseName($show);
        if (strlen($want) < 3) return null;
        foreach (['anime', 'tv'] as $id) {
            $root = self::sectionRoot($id);
            if (!$root || !is_dir($root)) continue;
            $dh = @opendir($root);
            if (!$dh) continue;
            $found = null;
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') continue;
                if (!is_dir($root . '/' . $name)) continue;
                if (self::looseName($name) === $want) { $found = $id; break; }
            }
            closedir($dh);
            if ($found) return $found;
        }
        return null;
    }

    private static function existingMovieSection(string $title, $year): ?string {
        $want = self::looseName($title);
        if (strlen($want) < 3) return null;
        foreach (['disney', 'movies'] as $id) {
            $root = self::sectionRoot($id);
            if (!$root || !is_dir($root)) continue;
            $names = [$title];
            if ($year) $names[] = $title . ' (' . (int)$year . ')';
            foreach ($names as $n) {
                if (is_dir($root . '/' . $n)) return $id;
            }
            if ($id !== 'disney') continue;
            $dh = @opendir($root);
            if (!$dh) continue;
            $found = null;
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') continue;
                if (!is_dir($root . '/' . $name)) continue;
                if (self::looseName($name) === $want) { $found = $id; break; }
            }
            closedir($dh);
            if ($found) return $found;
        }
        return null;
    }

    private static function sectionRoot(string $id): ?string {
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            if (($sec['id'] ?? '') === $id && !empty($sec['path'])) return (string)$sec['path'];
        }
        return null;
    }

    private static function looseName(string $s): string {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '', $s);
        return is_string($s) ? $s : '';
    }

    private static function episodeCodeInName(string $name, int $season, int $episode): bool {
        $pat1 = '/s0*' . $season . 'e0*' . $episode . '(?![0-9])/i';
        $pat2 = '/(?<![0-9])' . $season . '\\s*x\\s*0*' . $episode . '(?![0-9])/i';
        return preg_match($pat1, $name) === 1 || preg_match($pat2, $name) === 1;
    }

    private static function isSampleName(string $name): bool {
        $base = strtolower(basename(str_replace('\\', '/', $name)));
        return preg_match('/(^|[._\\-\\s\\[])(sample|trailer)([._\\-\\s\\]]|$)/', $base) === 1;
    }


    public static function torrentsForIngest(): array {
        $tr = Store::settings()['acquisition']['transmission'] ?? [];
        $torrents=!empty($tr['enabled'])?self::listTorrentsRaw($tr):[];
        return array_merge($torrents,StalkerOffline::torrents());
    }
    public static function ingestQueueEntry(array $tor): ?array {
        if(!empty($tor['stalker_offline']))return null;
        $q = Store::get('acquire_queue', []);
        return self::matchQueueEntry($tor, is_array($q) ? $q : []);
    }
    public static function ingestImportRecord(array $tor): ?array {
        $key = strtolower((string)($tor['hashString'] ?? ''));
        if ($key === '') $key = 'id:' . (int)($tor['id'] ?? 0);
        $r = self::importRecords();
        return isset($r[$key]) && is_array($r[$key]) ? $r[$key] : null;
    }
    /** Large, non-sample video files of a torrent still present in the download folder. */
    public static function ingestVideoFiles(array $tor): array {
        if(!empty($tor['stalker_offline']))return StalkerOffline::files($tor);
        $dir = (string)($tor['downloadDir'] ?? '');
        if ($dir === '' || !is_dir($dir)) return [];
        if (!self::downloadDirAllowsDelete($dir) && !self::dirIsDownloadRoot($dir)) return [];
        $exts = Library::videoExtensions();
        $out = [];
        foreach ($tor['files'] ?? [] as $f) {
            if (!is_array($f)) continue;
            $rel = str_replace('\\', '/', (string)($f['name'] ?? ''));
            if ($rel === '' || strpos($rel, "\0") !== false || strpos($rel, '../') !== false || strpos($rel, '/..') !== false) continue;
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true) || self::isSampleName($rel)) continue;
            $full = rtrim($dir, '/') . '/' . $rel;
            if (!is_file($full)) continue;
            $real = \Vaultcast\Paths::real($full);
            if ($real === false || !self::pathUnder($real, $dir)) continue;
            $len = (int)($f['length'] ?? (@filesize($real) ?: 0));
            if ($len > 0 && $len < 20 * 1024 * 1024) continue;
            $out[] = ['path' => $real, 'rel' => $rel, 'name' => basename($rel), 'ext' => $ext, 'size' => $len];
        }
        usort($out, function ($a, $b) { return strnatcasecmp($a['rel'], $b['rel']); });
        return $out;
    }
    /** Record a reviewed ingest, mark queue done and (when nothing else is left) remove the torrent + leftovers. */
    public static function finishIngest(array $tor, array $dests, bool $removeTorrent = true): array {
        if(!empty($tor['stalker_offline']))return StalkerOffline::imported($tor);
        $name = (string)($tor['name'] ?? '');
        $hash = strtolower((string)($tor['hashString'] ?? ''));
        $tid = (int)($tor['id'] ?? 0);
        $key = $hash !== '' ? $hash : ('id:' . $tid);
        $prev = self::importRecords()[$key] ?? null;
        $all = is_array($prev) && is_array($prev['dests'] ?? null) ? array_values(array_unique(array_merge($prev['dests'], $dests))) : $dests;
        $tr = Store::settings()['acquisition']['transmission'] ?? [];
        $left = self::otherLargeVideosRemain($tor, []);
        $removed = false;
        $msg = 'Imported ' . count($dests) . ' file' . (count($dests) === 1 ? '' : 's');
        if ($left) {
            $msg .= '; ' . count($left) . ' other video file(s) left in the torrent';
        } elseif ($removeTorrent) {
            $existing = array_values(array_filter($all, function ($p) { return is_string($p) && is_file($p); }));
            $rm = self::removeTorrentIfSafe($tor, $tr, $existing);
            $removed = !empty($rm['ok']) && !empty($rm['removed']);
            if (!$removed) $msg .= '; torrent kept (' . ($rm['error'] ?? 'not removed') . ')';
            else $msg .= ' and removed torrent data';
        }
        self::saveImportRecord($key, $name, $tid, $hash, 'imported', $all, $removed);
        self::markQueueDoneForTorrent($tor, $dests);
        self::importLog('INGEST ' . $name . ' — ' . $msg);
        return ['ok' => true, 'removed' => $removed, 'left' => count($left), 'message' => $msg];
    }
    public static function cleanReleaseTitle(string $name): array {
        $name = (string)preg_replace('/^\s*(?:www\.)?[a-z0-9-]+\.(?:org|com|net|to|se|me|cc|io|ws)\s*[-\x{2013}]+\s*/iu', '', $name);
        return self::movieTitleFromBasename($name . '.mkv');
    }

    private static function listTorrentsRaw(array $tr): array {
        if (empty($tr['enabled'])) return [];
        $rpc = self::transmissionRpc($tr, [
            'method' => 'torrent-get',
            'arguments' => [
                'fields' => ['id', 'name', 'hashString', 'downloadDir', 'percentDone', 'isFinished', 'status', 'files', 'errorString',
                    'peersSendingToUs', 'metadataPercentComplete', 'haveValid', 'addedDate'],
            ],
        ]);
        if (empty($rpc['ok']) || !is_array($rpc['arguments']['torrents'] ?? null)) return [];
        return $rpc['arguments']['torrents'];
    }

    private static function queueEntryById(string $id): ?array {
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) return null;
        foreach ($q as $e) {
            if (is_array($e) && (string)($e['id'] ?? '') === $id) return $e;
        }
        return null;
    }

    private static function matchQueueEntry(array $tor, array $queue): ?array {
        foreach ($queue as $e) {
            if (is_array($e) && self::torrentMatchesEntry($tor, $e)) return $e;
        }
        return null;
    }

    private static function torrentMatchesEntry(array $tor, array $entry): bool {
        $eh = strtolower((string)($entry['torrent_hash'] ?? ''));
        $th = strtolower((string)($tor['hashString'] ?? ''));
        // the info-hash is authoritative. Transmission ids restart after a daemon restart, so an old
        // finished entry ("The Rookie S08E08", torrent_id 6) must not claim a new torrent that reuses id 6.
        if ($eh !== '' && $th !== '') return $eh === $th;
        $id = (int)($entry['torrent_id'] ?? 0);
        $closed = in_array((string)($entry['status'] ?? ''), ['done', 'removed', 'cancelled', 'failed'], true);
        if ($id > 0 && !$closed && (int)($tor['id'] ?? 0) === $id) return true;
        $en = self::looseName((string)($entry['title'] ?? ''));
        $tn = self::looseName((string)($tor['name'] ?? ''));
        if ($en !== '' && $tn !== '' && strlen($en) >= 8 && ($en === $tn || (strlen($en) >= 12 && (strpos($tn, $en) !== false || strpos($en, $tn) !== false)))) {
            return true;
        }
        return false;
    }

    private static function markQueueRemovedById(string $id, bool $delete): array {
        $lock = self::acquireLock(true);
        if (!$lock) throw new RuntimeException('Acquisition queue busy; cannot safely update download status');
        try {
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) return [];
        $hit = [];
        foreach ($q as &$e) {
            if (!is_array($e) || (string)($e['id'] ?? '') !== $id) continue;
            $e['status'] = $delete ? 'removed' : 'cancelled';
            $e['message'] = $delete ? 'Removed from queue and Transmission; download data deleted' : 'Removed from queue and Transmission; files kept';
            $e['cancelled_at'] = date('c');
            $hit[] = $id;
        }
        unset($e);
        if ($hit) Store::set('acquire_queue', $q);
        return $hit;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function markQueueRemovedForTorrent(array $tor, bool $delete): array {
        $lock = self::acquireLock(true);
        if (!$lock) throw new RuntimeException('Acquisition queue busy; cannot safely update download status');
        try {
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) return [];
        $hit = [];
        foreach ($q as &$e) {
            if (!is_array($e) || !self::torrentMatchesEntry($tor, $e)) continue;
            if (in_array($e['status'] ?? '', ['done'], true)) continue;
            $e['status'] = $delete ? 'removed' : 'cancelled';
            $e['message'] = $delete ? 'Removed with torrent data deleted' : 'Removed from Transmission; files kept';
            $e['cancelled_at'] = date('c');
            $e['torrent_id'] = $e['torrent_id'] ?? ($tor['id'] ?? null);
            if (!empty($tor['hashString'])) $e['torrent_hash'] = strtolower((string)$tor['hashString']);
            $hit[] = (string)($e['id'] ?? '');
        }
        unset($e);
        if ($hit) Store::set('acquire_queue', $q);
        return $hit;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function markQueueDoneForTorrent(array $tor, array $dests): void {
        $lock = self::acquireLock(true);
        if (!$lock) throw new RuntimeException('Acquisition queue busy; cannot safely update download status');
        try {
        $q = Store::get('acquire_queue', []);
        if (!is_array($q)) return;
        $changed = false;
        foreach ($q as &$e) {
            if (!is_array($e) || !self::torrentMatchesEntry($tor, $e)) continue;
            $e['status'] = 'done';
            $e['message'] = 'Imported to library';
            $e['processed_at'] = date('c');
            $e['dest'] = $dests[0] ?? null;
            $e['torrent_id'] = $e['torrent_id'] ?? ($tor['id'] ?? null);
            if (!empty($tor['hashString'])) $e['torrent_hash'] = strtolower((string)$tor['hashString']);
            $changed = true;
        }
        unset($e);
        if ($changed) Store::set('acquire_queue', $q);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function importRecords(): array {
        $r = Store::get('acquire_imports', []);
        return is_array($r) ? $r : [];
    }

    private static function saveImportRecord(string $key, string $name, int $tid, string $hash, string $status, array $dests, bool $removed): void {
        $all = self::importRecords();
        $all[$key] = [
            'name' => $name,
            'torrent_id' => $tid,
            'hash' => $hash,
            'status' => $status,
            'dests' => array_values($dests),
            'removed' => $removed,
            'at' => date('c'),
        ];
        if (count($all) > 400) {
            $all = array_slice($all, -300, null, true);
        }
        Store::set('acquire_imports', $all);
    }

    private static function allFilesExist(array $paths): bool {
        foreach ($paths as $p) {
            if (!is_string($p) || !is_file($p)) return false;
        }
        return true;
    }

    private static function dirIsDownloadRoot(string $dir): bool {
        // Configured Transmission/download path (e.g. /srv/downloads)
        $dl = Store::settings()['acquisition']['download_path'] ?? '';
        if (is_string($dl) && $dl !== '' && self::pathUnder($dir, $dl)) return true;
        // Also accept library scan roots (legacy / alternate layouts)
        foreach (Library::defaultScanRoots() as $root) {
            if (self::pathUnder($dir, $root)) return true;
        }
        return false;
    }

    private static function downloadDirAllowsDelete(string $dir): bool {
        if ($dir === '') return false;
        $real = self::realPrefix($dir);
        if ($real === null || $real === '/' || dirname($real) === $real) return false;
        foreach (self::blockedDeleteRoots() as $blocked) {
            $br = self::realPrefix($blocked);
            if ($br && ($real === $br || strpos($real, $br . '/') === 0)) return false;
        }
        // Allow delete only under the configured download path (or known download roots)
        return self::dirIsDownloadRoot($dir);
    }

    private static function blockedDeleteRoots(): array {
        $blocked = [STREAM_ROOT, STREAM_DATA];
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            $p = $sec['path'] ?? '';
            if (is_string($p) && $p !== '') $blocked[] = $p;
        }
        return $blocked;
    }

    private static function fileUnderSection(string $path): ?string {
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            $root = $sec['path'] ?? '';
            if (!is_string($root) || $root === '') continue;
            if (self::pathUnder($path, $root)) return (string)($sec['id'] ?? $root);
        }
        return null;
    }

    private static function pathUnder(string $path, string $root): bool {
        $p = self::realPrefix($path);
        $r = self::realPrefix($root);
        if ($p === null || $r === null || $r === '' || $r === '/') return false;
        return $p === $r || strpos($p, $r . '/') === 0;
    }

    private static function realPrefix(string $path): ?string {
        if ($path === '') return null;
        $real = \Vaultcast\Paths::real($path);
        if ($real === false) return null;
        return rtrim($real, '/');
    }

    private static function importLog(string $msg): void {
        $file = STREAM_DATA . '/acquire_import.log';
        @file_put_contents($file, date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        $sz = @filesize($file);
        if ($sz !== false && $sz > 524288) {
            $lines = @file($file);
            if (is_array($lines) && count($lines) > 400) {
                @file_put_contents($file, implode('', array_slice($lines, -300)), LOCK_EX);
            }
        }
    }
}
