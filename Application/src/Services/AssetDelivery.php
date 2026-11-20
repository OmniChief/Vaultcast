<?php
declare(strict_types=1);

/** Text response compression and URLs for the public frontend bundles. */
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

    public static function compress(string $body, string $accept): array {
        if (strlen($body) < 1024 || !self::acceptsGzip($accept)) return ['body'=>$body, 'gzip'=>false];
        $encoded = gzencode($body, 5);
        if (!is_string($encoded) || strlen($encoded) >= strlen($body)) return ['body'=>$body, 'gzip'=>false];
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

    public static function nativeBase(string $base): string {
        // The installer routes /netplay/ directly to the Node service.
        if ($base !== '/' || !preg_match('/^nginx\b/i', (string)($_SERVER['SERVER_SOFTWARE'] ?? ''))) return '';
        return NodeRuntime::webAssetsReady() ? '/netplay/web-assets/' : '';
    }

    public static function url(string $base, string $path, string $version): string {
        $native=self::nativeBase($base);
        return ($native !== '' ? $native . substr($path, strlen('assets/')) : $base . $path)
            . '?v=' . rawurlencode($version);
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
        $version = $_GET['v'] ?? '';
        $version = is_string($version) && preg_match('/^[a-f0-9]{16}$/D', $version) ? '?v=' . $version : '';
        header('Cache-Control: no-store');
        header('Location: ' . $asset . $version, true, 302);
        exit;
    }
}
