<?php
declare(strict_types=1);

/** Updater-delivered compression: works with existing nginx configs and no OS privileges. */
final class AssetDelivery {
    public static function acceptsGzip(string $accept): bool {
        if (!function_exists('gzencode')) return false;
        $wildcard = false;
        foreach (explode(',', strtolower($accept)) as $part) {
            $pieces = explode(';', trim($part));
            $coding = trim(array_shift($pieces)); $quality = 1.0;
            foreach ($pieces as $parameter) if (preg_match('/^q\s*=\s*([0-9.]+)$/', trim($parameter), $m)) $quality = (float)$m[1];
            if ($coding === 'gzip') return $quality > 0;
            if ($coding === '*') $wildcard = $quality > 0;
        }
        return $wildcard;
    }

    public static function compress(string $body, string $accept, ?string $cacheDir = null): array {
        if (strlen($body) < 1024 || !self::acceptsGzip($accept)) return ['body'=>$body, 'gzip'=>false];
        $cache = $cacheDir !== null ? $cacheDir . '/' . hash('sha256', $body) . '.gz' : null;
        $encoded = $cache && is_file($cache) ? @file_get_contents($cache) : false;
        if (is_string($encoded) && @gzdecode($encoded) !== $body) $encoded = false;
        if ($encoded === false) {
            $encoded = gzencode($body, 5);
            if (!is_string($encoded) || strlen($encoded) >= strlen($body)) return ['body'=>$body, 'gzip'=>false];
            if ($cache && (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true))) {
                $tmp = $cache . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, $encoded) !== false) { if (!@rename($tmp, $cache)) @unlink($tmp); }
            }
        }
        return ['body'=>$encoded, 'gzip'=>true];
    }

    /** JSON and HTML only; do not change image/media/range response bodies. */
    public static function text(string $body): string {
        header('Vary: Accept-Encoding', false);
        $result = self::compress($body, (string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
        if ($result['gzip']) {
            @ini_set('zlib.output_compression', '0');
            header('Content-Encoding: gzip');
        }
        header('Content-Length: ' . strlen($result['body']));
        return $result['body'];
    }

    /** Only shipped public CSS/JS. Resolve before auth/database/bootstrap. */
    public static function file(string $root, string $asset): ?string {
        if (!preg_match('#^assets/(js/[A-Za-z0-9_-]+\.js|css/[A-Za-z0-9_-]+\.css)$#D', $asset)) return null;
        $base = realpath($root . '/assets');
        $path = realpath($root . '/' . $asset);
        if ($base === false || $path === false || is_link($root . '/' . $asset) || !is_file($path)) return null;
        $base = str_replace('\\', '/', $base) . '/'; $normalized = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows') { $base = strtolower($base); $normalized = strtolower($normalized); }
        return strpos($normalized, $base) === 0 ? $path : null;
    }

    public static function serve(string $root): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET','HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); exit; }
        $asset = $_GET['asset'] ?? null;
        $path = is_string($asset) ? self::file($root, $asset) : null;
        if ($path === null) { http_response_code(404); header('Cache-Control: no-store'); exit; }
        $body = @file_get_contents($path);
        if ($body === false) { http_response_code(404); exit; }
        $result = self::compress($body, (string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), $root . '/data/cache/assets');
        $etag = '"' . hash('sha256', $body) . ($result['gzip'] ? '-gzip' : '') . '"';
        @ini_set('zlib.output_compression', '0');
        header('Content-Type: ' . (substr($path, -4) === '.css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Vary: Accept-Encoding');
        header('Cache-Control: ' . (is_string($_GET['v'] ?? null) && preg_match('/^[a-f0-9]{16}$/D', $_GET['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=3600'));
        header('ETag: ' . $etag);
        if ($result['gzip']) header('Content-Encoding: gzip');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
        header('Content-Length: ' . strlen($result['body']));
        if ($method !== 'HEAD') echo $result['body'];
        exit;
    }
}
