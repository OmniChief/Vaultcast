<?php
/** Request cache and document access backed by MariaDB. */
declare(strict_types=1);
class Store {
    private static $cache = [];
    private static $revisions = [];
    private static $settings;
    public static function get(string $name, $default = null) {
        if (PHP_SAPI !== 'cli' && array_key_exists($name, self::$cache)) return self::$cache[$name];
        $revision = self::revision($name);
        if (array_key_exists($name, self::$cache) && (self::$revisions[$name] ?? -1) === $revision) return self::$cache[$name];
        $value = \Vaultcast\Repository::read($name, $default);
        self::$cache[$name] = $value;
        self::$revisions[$name] = $revision;
        return $value;
    }
    public static function set(string $name, $data): bool {
        $result = \Vaultcast\Repository::write($name, $data);
        self::invalidate($name);
        return $result;
    }
    public static function revision(string $name): int {
        if (PHP_SAPI === 'cli') return \Vaultcast\Repository::revision($name);
        if (isset(self::$revisions[$name])) return self::$revisions[$name];
        return self::$revisions[$name] = \Vaultcast\Repository::revision($name);
    }
    public static function invalidate(string $name = null): void {
        if ($name === null || $name === 'settings') self::$settings = null;
        if ($name === null) { self::$cache = []; self::$revisions = []; }
        else { unset(self::$cache[$name], self::$revisions[$name]); }
    }
    public static function settings(): array {
        if (PHP_SAPI !== 'cli' && self::$settings !== null) return self::$settings;
        $settings = self::get('settings', []);
        return self::$settings = self::managedMediaAccel(self::managedTransmission(self::normalizeFeatures(is_array($settings) ? $settings : [])));
    }
    /**
     * The Windows installer writes the nginx config. From the version that ships the internal
     * /_vaultcast_media/ location it records "mediaAccel": true in data/runtime/setup.json, so
     * nginx serves media via X-Accel-Redirect instead of tying up a php-cgi worker per stream.
     * Older installer configs lack that location, so their saved value (off) is kept.
     */
    public static function managedMediaAccel(array $settings): array {
        if (PHP_OS_FAMILY !== 'Windows') return $settings;
        $file = dirname(__DIR__, 2) . '/data/runtime/setup.json';
        $setup = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($setup) && !empty($setup['mediaAccel'])) {
            $settings['streaming'] = is_array($settings['streaming'] ?? null) ? $settings['streaming'] : [];
            $settings['streaming']['nginx_accel'] = true;
        }
        return $settings;
    }
    /**
     * The Windows installer runs a private Transmission service and records its RPC
     * connection in data/runtime/transmission.ini (readable by administrators and the
     * PHP service only). Use it whenever no Transmission URL has been saved, so
     * downloads work without any manual connection step. Downloads are also on by
     * default then, unless an admin saved the feature off (features_explicit.downloads).
     */
    public static function managedTransmission(array $settings): array {
        $file = dirname(__DIR__, 2) . '/data/runtime/transmission.ini';
        $ini = is_readable($file) ? @parse_ini_file($file, true, INI_SCANNER_RAW) : false;
        $managed = is_array($ini) ? ($ini['transmission'] ?? null) : null;
        if (!is_array($managed) || empty($managed['port']) || empty($managed['username'])) return $settings;
        if (empty($settings['features_explicit']['downloads'])) $settings['features']['downloads'] = true;
        $tr = $settings['acquisition']['transmission'] ?? [];
        if (!is_array($tr)) $tr = [];
        $managedUrl = 'http://127.0.0.1:' . (int)$managed['port'] . '/transmission/rpc';
        $savedUrl = trim((string)($tr['url'] ?? ''));
        $settings['acquisition'] = is_array($settings['acquisition'] ?? null) ? $settings['acquisition'] : [];
        if ($savedUrl !== '') {
            // Saving Settings > Downloads stores the managed connection too. Keep its credentials in step
            // with the installer's file (setup/repair may regenerate them); a different URL is the admin's own.
            if (rtrim($savedUrl, '/') !== $managedUrl && rtrim($savedUrl, '/') !== str_replace('127.0.0.1', 'localhost', $managedUrl)) return $settings;
            $settings['acquisition']['transmission'] = array_replace($tr, [
                'username' => (string)$managed['username'],
                'password' => (string)($managed['password'] ?? ''),
            ]);
            return $settings;
        }
        $settings['acquisition']['transmission'] = array_replace($tr, [
            'enabled' => true,
            'url' => $managedUrl,
            'username' => (string)$managed['username'],
            'password' => (string)($managed['password'] ?? ''),
        ]);
        if (trim((string)($settings['acquisition']['download_path'] ?? '')) === '' && !empty($managed['download_dir'])) {
            $settings['acquisition']['download_path'] = (string)$managed['download_dir'];
        }
        return $settings;
    }
    /**
     * Live channels & IPTV (TV Studio) is on by default. Older installers wrote features.live=false
     * without anyone choosing it, so it only stays off when an admin explicitly saved it off
     * (recorded in features_explicit.live by the settings API).
     */
    public static function normalizeFeatures(array $settings): array {
        $f = isset($settings['features']) && is_array($settings['features']) ? $settings['features'] : [];
        $explicit = !empty($settings['features_explicit']['live']);
        if (!$explicit || !array_key_exists('live', $f)) $f['live'] = true;
        $f += ['downloads' => false, 'server_tools' => false];
        $settings['features'] = $f;
        return $settings;
    }
    public static function saveSettings(array $settings): bool { return self::set('settings', $settings); }
}
