<?php
declare(strict_types=1);

class Diagnostics {
    /** Registry of individually runnable tests. */
    public static function catalog(): array {
        return [
            ['id' => 'php_version', 'name' => 'PHP version', 'group' => 'runtime'],
            ['id' => 'data_writable', 'name' => 'Data directory writable', 'group' => 'storage'],
            ['id' => 'shm', 'name' => 'Shared memory cache', 'group' => 'storage'],
            ['id' => 'ffmpeg', 'name' => 'ffmpeg available', 'group' => 'runtime'],
            ['id' => 'catalogue', 'name' => 'Catalogue loaded', 'group' => 'library'],
            ['id' => 'sections', 'name' => 'Section paths', 'group' => 'library'],
            ['id' => 'transmission', 'name' => 'Transmission RPC', 'group' => 'acquisition'],
            ['id' => 'session', 'name' => 'Auth session', 'group' => 'auth'],
            ['id' => 'https', 'name' => 'HTTPS detected', 'group' => 'network'],
            ['id' => 'acquisition_feeds', 'name' => 'Acquisition search feeds', 'group' => 'acquisition'],
        ];
    }

    public static function listTests(array $user): array {
        $settings = Store::settings();
        if (isset($settings['diagnostics']['enable_tests']) && !$settings['diagnostics']['enable_tests']) {
            return ['ok' => false, 'error' => 'Diagnostics disabled', 'tests' => []];
        }
        return ['ok' => true, 'tests' => self::catalog(), 'version' => STREAM_VERSION];
    }

    public static function runOne(string $id, array $user): array {
        $settings = Store::settings();
        if (isset($settings['diagnostics']['enable_tests']) && !$settings['diagnostics']['enable_tests']) {
            return ['ok' => false, 'error' => 'Diagnostics disabled'];
        }
        $map = [];
        foreach (self::catalog() as $t) $map[$t['id']] = $t;
        if (!isset($map[$id])) {
            return ['ok' => false, 'error' => 'Unknown test: ' . $id];
        }
        $result = self::execute($id, $user);
        return [
            'ok' => true,
            'test' => [
                'id' => $id,
                'name' => $map[$id]['name'],
                'group' => $map[$id]['group'],
                'ok' => !empty($result['ok']),
                'detail' => $result['detail'] ?? '',
            ],
        ];
    }

    public static function run(array $user): array {
        $tests = [];
        foreach (self::catalog() as $meta) {
            $r = self::execute($meta['id'], $user);
            $tests[] = [
                'id' => $meta['id'],
                'name' => $meta['name'],
                'group' => $meta['group'],
                'ok' => !empty($r['ok']),
                'detail' => $r['detail'] ?? '',
            ];
        }
        // Expand section paths as individual rows for the bulk runner (compat)
        $extra = [];
        foreach ($tests as $i => $t) {
            if ($t['id'] !== 'sections') continue;
            $settings = Store::settings();
            foreach ($settings['sections'] ?? [] as $sec) {
                $path = $sec['path'] ?? '';
                $ok = $path !== '' && is_dir($path);
                $extra[] = [
                    'id' => 'section_' . ($sec['id'] ?? 'x'),
                    'name' => 'Section path: ' . ($sec['label'] ?? $sec['id'] ?? ''),
                    'group' => 'library',
                    'ok' => $ok,
                    'detail' => $path . ($ok ? '' : ' (missing)'),
                ];
            }
        }
        if ($extra) {
            $tests = array_values(array_filter($tests, function ($t) { return $t['id'] !== 'sections'; }));
            $tests = array_merge($tests, $extra);
        }
        $pass = count(array_filter($tests, function ($t) { return !empty($t['ok']); }));
        return ['ok' => true, 'passed' => $pass, 'total' => count($tests), 'tests' => $tests, 'version' => STREAM_VERSION];
    }

    private static function execute(string $id, array $user): array {
        try {
            switch ($id) {
                case 'php_version':
                    return ['ok' => version_compare(PHP_VERSION, '7.4.0', '>='), 'detail' => PHP_VERSION];
                case 'data_writable':
                    return ['ok' => is_writable(STREAM_DATA), 'detail' => STREAM_DATA];
                case 'shm':
                    $ok = is_dir(STREAM_SHM) && is_writable(STREAM_SHM);
                    return ['ok' => $ok, 'detail' => STREAM_SHM];
                case 'ffmpeg':
                    $v = trim((string)@shell_exec('command -v ffmpeg && ffmpeg -version 2>/dev/null | head -1'));
                    return ['ok' => $v !== '', 'detail' => $v ?: 'not found'];
                case 'catalogue':
                    $c = Catalogue::get();
                    $n = (int)($c['item_count'] ?? count($c['items'] ?? []));
                    return ['ok' => true, 'detail' => $n . ' items, scanned ' . ($c['scanned_at'] ?? 'never')];
                case 'sections':
                    $settings = Store::settings();
                    $secs = $settings['sections'] ?? [];
                    $ok = 0; $bad = 0; $bits = [];
                    foreach ($secs as $sec) {
                        $path = $sec['path'] ?? '';
                        if ($path !== '' && is_dir($path)) { $ok++; $bits[] = ($sec['id'] ?? '?') . ':ok'; }
                        else { $bad++; $bits[] = ($sec['id'] ?? '?') . ':missing'; }
                    }
                    return ['ok' => $bad === 0 && $ok > 0, 'detail' => implode(', ', $bits) ?: 'no sections'];
                case 'transmission':
                    // Authenticated RPC call (session-id handshake + credentials), not just "port answers":
                    // a 401 from wrong or missing credentials used to count as reachable.
                    $tr = Store::settings()['acquisition']['transmission'] ?? [];
                    if (trim((string)($tr['url'] ?? '')) === '') return ['ok' => false, 'detail' => 'No Transmission connection configured. Run the Vaultcast installer (Linux: Installers/Linux/setup.sh) again to connect it automatically, or enter it in Settings > Downloads.'];
                    $r = Acquisition::checkConnection($tr);
                    return ['ok' => !empty($r['ok']), 'detail' => $tr['url'] . ' - ' . ($r['message'] ?? $r['error'] ?? '')];
                case 'session':
                    return ['ok' => !empty($user['id']), 'detail' => ($user['username'] ?? '') . ' (' . ($user['role'] ?? '') . ')'];
                case 'https':
                    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
                    return ['ok' => true, 'detail' => $https ? 'yes' : 'no (LAN HTTP ok)'];
                case 'acquisition_feeds':
                    $acq = Store::settings()['acquisition'] ?? [];
                    $feeds = FeedCatalogue::active($acq['feeds'] ?? []);
                    $search = 0;
                    foreach ($feeds as $feed) {
                        $url = is_array($feed) ? ($feed['url'] ?? '') : (string)$feed;
                        if ($url === '') continue;
                        if ((is_array($feed) && (($feed['type'] ?? '') === 'search'))
                            || strpos($url, '{query}') !== false
                            || strpos($url, '{q}') !== false) $search++;
                    }
                    $filters = Acquisition::searchFilters($acq);
                    return [
                        'ok' => true,
                        'detail' => count($feeds) . ' feeds, ' . $search . ' search · rss_fallback='
                            . (!empty($filters['rss_fallback_search']) ? 'on' : 'off')
                            . ' · min_seeds=' . $filters['min_seeds'],
                    ];
                default:
                    return ['ok' => false, 'detail' => 'Unknown test'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
