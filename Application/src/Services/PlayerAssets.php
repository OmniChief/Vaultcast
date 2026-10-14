<?php
declare(strict_types=1);

/** Optional, version-pinned copies of browser player dependencies. */
final class PlayerAssets {
    const EMULATOR = 'https://cdn.emulatorjs.org/4.2.1/data/';
    const HLS = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js';
    const DOCUMENT = 'player_assets_4_2_1_hls_1_5_17';

    private static function directory(): string {
        return dirname(__DIR__, 2) . '/data/players';
    }

    public static function manifest(): array {
        $settings = \Vaultcast\Repository::document('settings', []);
        $installed = \Vaultcast\Repository::document(self::DOCUMENT, []);
        $local = ($settings['player_assets']['source'] ?? 'cdn') === 'local' && !empty($installed['complete']);
        $base = \Vaultcast\Database::basePath() . 'api/player-assets/';
        return [
            'emulatorVersion' => '4.2.1',
            'emulatorData' => $local ? $base . 'emulatorjs/4.2.1/data/' : self::EMULATOR,
            'hls' => $local ? $base . 'hls/1.5.17/hls.min.js' : self::HLS,
            'source' => $local ? 'local' : 'cdn',
        ];
    }

    public static function status(): array {
        $job = \Vaultcast\Repository::document(self::DOCUMENT, []);
        return ['ok'=>true, 'source'=>self::manifest()['source'], 'complete'=>!empty($job['complete']),
            'done'=>(int)($job['done'] ?? 0), 'total'=>count($job['files'] ?? []),
            'bytes'=>(int)($job['bytes'] ?? 0), 'can_download'=>extension_loaded('curl')];
    }

    private static function fetch(string $url): string {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>30, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $body = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($body) || $code !== 200 || strlen($body) > 2 * 1024 * 1024) {
            throw new RuntimeException('Could not read the dependency catalogue. Check the server internet connection.');
        }
        return $body;
    }

    private static function inventory(string $relative = '', int $depth = 0): array {
        if ($depth > 4) throw new RuntimeException('Unexpected CDN directory structure.');
        $html = self::fetch(self::EMULATOR . $relative);
        preg_match_all('~<tr\b[^>]*>(.*?)</tr>~si', $html, $rows);
        $files = [];
        foreach ($rows[1] as $row) {
            if (!preg_match('~href="([A-Za-z0-9_.-]+/?)"~', $row, $match)) continue;
            $name = $match[1];
            if ($name === '../' || $name === './' || $name[0] === '.') continue;
            if (substr($name, -1) === '/') {
                $files = array_merge($files, self::inventory($relative . $name, $depth + 1));
            } elseif (preg_match('/\.(js|css|json|data|wasm|png|svg|woff2?|ttf)$/i', $name)) {
                preg_match('~class="s"[^>]*data-value="(\d+)"~', $row, $size);
                $files[] = ['path'=>'emulatorjs/4.2.1/data/' . $relative . $name,
                    'url'=>self::EMULATOR . $relative . $name, 'size'=>(int)($size[1] ?? 0)];
            }
            if (count($files) > 1500) throw new RuntimeException('Dependency catalogue exceeds the file limit.');
        }
        return $files;
    }

    public static function downloadNext(): array {
        if (!extension_loaded('curl')) throw new RuntimeException('Enable the PHP cURL extension to download player files.');
        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0770, true)) throw new RuntimeException('Cannot create player storage.');
        $lock = fopen($dir . '/download.lock', 'c');
        if (!$lock) throw new RuntimeException('Cannot open player storage.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException('Another download is running. Try again shortly.'); }
        try {
            @set_time_limit(180);
            $job = \Vaultcast\Repository::document(self::DOCUMENT, []);
            if (empty($job['files'])) {
                $files = self::inventory();
                $paths = array_column($files, 'path');
                foreach (['loader.js','emulator.min.js','emulator.min.css','version.json','src/socket.io.min.js'] as $required) {
                    if (!in_array('emulatorjs/4.2.1/data/' . $required, $paths, true)) throw new RuntimeException('The CDN catalogue is incomplete.');
                }
                $files[] = ['path'=>'hls/1.5.17/hls.min.js', 'url'=>self::HLS, 'size'=>0];
                $job = ['files'=>$files, 'done'=>0, 'bytes'=>0, 'complete'=>false];
                \Vaultcast\Repository::putDocument(self::DOCUMENT, $job);
                return self::status();
            }
            if (empty($job['complete'])) {
                $entry = $job['files'][$job['done']];
                $target = $dir . '/' . $entry['path'];
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0770, true)) throw new RuntimeException('Cannot create player directory.');
                $temporary = $target . '.part';
                $stream = fopen($temporary, 'wb');
                if (!$stream) throw new RuntimeException('Cannot write player file.');
                $curl = curl_init($entry['url']);
                $written = 0;
                curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>120,
                    CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_WRITEFUNCTION=>function ($handle, string $chunk) use ($stream, &$written) {
                        $written += strlen($chunk);
                        if ($written > 128 * 1024 * 1024) return 0;
                        return fwrite($stream, $chunk);
                    }]);
                $ok = curl_exec($curl);
                $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                fclose($stream);
                $prefix = (string)file_get_contents($temporary, false, null, 0, 256);
                if (!$ok || $http !== 200 || !$written || ($entry['size'] > 0 && $written !== $entry['size']) || preg_match('/^\s*<(?:!doctype html|html)/i', $prefix)) {
                    @unlink($temporary);
                    throw new RuntimeException('Download interrupted at ' . basename($target) . '. Press Resume to retry.');
                }
                if (!rename($temporary, $target)) throw new RuntimeException('Cannot save player file.');
                $job['done']++;
                $job['bytes'] += $written;
                $job['complete'] = $job['done'] === count($job['files']);
                \Vaultcast\Repository::putDocument(self::DOCUMENT, $job);
            }
            if (!empty($job['complete'])) self::setSource('local');
            return self::status();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function setSource(string $source): array {
        if (!in_array($source, ['cdn','local'], true)) throw new RuntimeException('Choose CDN or server storage.');
        $job = \Vaultcast\Repository::document(self::DOCUMENT, []);
        if ($source === 'local' && empty($job['complete'])) throw new RuntimeException('Download the player files first.');
        \Vaultcast\Database::transaction(function (PDO $pdo) use ($source): void {
            $pdo->query("SELECT name FROM vaultcast_documents WHERE name='settings' FOR UPDATE")->fetch();
            $settings = \Vaultcast\Repository::document('settings', []);
            $settings['player_assets']['source'] = $source;
            \Vaultcast\Repository::putDocument('settings', $settings);
        });
        return ['ok'=>true];
    }

    public static function resetDownload(): array {
        $dir = self::directory();
        if (!is_dir($dir)) return self::status();
        $lock = fopen($dir . '/download.lock', 'c');
        if (!$lock) throw new RuntimeException('Cannot open player storage.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException('A download is running. Wait for it to finish before starting again.'); }
        try {
            self::setSource('cdn');
            \Vaultcast\Repository::putDocument(self::DOCUMENT, []);
            return self::status();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function serve(string $relative): void {
        $job = \Vaultcast\Repository::document(self::DOCUMENT, []);
        if (empty($job['complete']) || !in_array($relative, array_column($job['files'] ?? [], 'path'), true)) json_error('Player file not found', 404);
        $path = self::directory() . '/' . $relative;
        if (!is_file($path)) json_error('Player file missing; download the dependencies again.', 404);
        $types = ['js'=>'application/javascript','css'=>'text/css','json'=>'application/json','wasm'=>'application/wasm','svg'=>'image/svg+xml','png'=>'image/png','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf'];
        header('Content-Type: ' . ($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000, immutable');
        $settings = \Vaultcast\Repository::document('settings', []);
        if (!empty($settings['streaming']['nginx_accel'])) {
            header('X-Accel-Redirect: /_vaultcast_media/' . implode('/', array_map('rawurlencode', explode('/', ltrim(str_replace('\\', '/', $path), '/')))));
        } else {
            header('Content-Length: ' . filesize($path));
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($path);
        }
        exit;
    }
}
