<?php
declare(strict_types=1);

/** Sidecar captions delivered through the same short-lived token as the video. */
final class Subtitles {
    private const MAX_BYTES = 2097152;

    /** App-side subtitle store for videos whose folder is read-only (data/subtitles/<hash>/). */
    public static function storeDir(string $media): string {
        $real = \Vaultcast\Paths::real($media);
        $root = defined('STREAM_DATA') ? STREAM_DATA : dirname(__DIR__, 2) . '/data';
        return rtrim($root, '/\\') . '/subtitles/' . hash('sha256', $real !== false ? $real : $media);
    }

    /**
     * Save a WebVTT subtitle for $media: next to the video when its folder is writable
     * (<stem>.<lang>.<source>.vtt), otherwise in the app store. Returns the saved path.
     */
    public static function saveFor(string $media, string $vtt, string $language, string $source): ?string {
        $language = preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/', $language) ? $language : 'und';
        $source = preg_replace('/[^a-z0-9]+/', '', strtolower(preg_replace('/-\d+$/', '', $source))) ?: 'download';
        $stem = pathinfo($media, PATHINFO_FILENAME);
        $targets = [];
        $dir = dirname($media);
        if (is_dir($dir) && is_writable($dir)) $targets[] = $dir . DIRECTORY_SEPARATOR . $stem . '.' . $language . '.' . $source . '.vtt';
        $targets[] = self::storeDir($media) . DIRECTORY_SEPARATOR . $language . '.' . $source . '.vtt';
        foreach ($targets as $target) {
            $folder = dirname($target);
            if (!is_dir($folder) && !@mkdir($folder, 0775, true)) continue;
            $tmp = $target . '.tmp' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $vtt) !== strlen($vtt)) { @unlink($tmp); continue; }
            if (is_file($target)) @unlink($target);
            if (!@rename($tmp, $target)) { @unlink($tmp); continue; }
            return $target;
        }
        return null;
    }

    private static function storeFiles(string $media): array {
        $directory = self::storeDir($media);
        if (!is_dir($directory)) return [];
        $files = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'vtt' || $entry->getSize() > self::MAX_BYTES) continue;
            $name = $entry->getBasename('.vtt');
            $parts = explode('.', $name);
            $language = preg_match('/^[a-z]{2,3}$/', $parts[0]) ? $parts[0] : '';
            $files[hash('sha256', 'store/' . $entry->getFilename())] = [
                'path' => $entry->getPathname(), 'extension' => 'vtt',
                'label' => str_replace(['.', '_'], ' ', $name), 'language' => $language,
            ];
        }
        return $files;
    }

    private static function files(string $media): array {
        $store = self::storeFiles($media);
        $directory = \Vaultcast\Paths::real(dirname($media));
        if ($directory === false || !is_dir($directory) || !is_readable($directory)) return $store;
        $stem = pathinfo($media, PATHINFO_FILENAME);
        $files = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if (!$entry->isFile()) continue;
            $extension = strtolower($entry->getExtension());
            if (!in_array($extension, ['vtt', 'srt'], true)) continue;
            $name = $entry->getBasename('.' . $entry->getExtension());
            if (strcasecmp($name, $stem) !== 0 && strncasecmp($name, $stem . '.', strlen($stem) + 1) !== 0) continue;
            $path = \Vaultcast\Paths::real($entry->getPathname());
            if ($path === false || rtrim(dirname($path), '/') !== rtrim($directory, '/') || $entry->getSize() > self::MAX_BYTES) continue;
            $suffix = trim(substr($name, strlen($stem)), '.');
            $parts = preg_split('/[._-]+/', $suffix);
            $language = preg_match('/^[a-z]{2,3}$/i', $parts[0] ?? '') ? strtolower($parts[0]) : '';
            $files[hash('sha256', $entry->getFilename())] = [
                'path' => $path, 'extension' => $extension,
                'label' => $suffix !== '' ? str_replace(['.', '_'], ' ', $suffix) : 'Subtitles',
                'language' => $language,
            ];
            if (count($files) >= 50) break;
        }
        $files += $store;
        uasort($files, function (array $a, array $b): int { return strnatcasecmp($a['label'], $b['label']); });
        return $files;
    }

    public static function tracks(string $media, string $token): array {
        $tracks = [];
        $base = rtrim(\Vaultcast\Database::basePath(), '/');
        foreach (self::files($media) as $id => $file) {
            $tracks[] = ['id' => $id, 'label' => $file['label'], 'language' => $file['language'],
                'url' => $base . '/api/stream/subtitles/' . $token . '/' . $id . '.vtt'];
        }
        return $tracks;
    }

    public static function toVtt(string $text, string $extension): string {
        if (substr($text, 0, 2) === "\xff\xfe") $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
        elseif (substr($text, 0, 2) === "\xfe\xff") $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
        elseif (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        if ($extension === 'vtt') {
            if (!preg_match('/^WEBVTT(?:[ \t\n]|$)/', $text)) throw new RuntimeException('Invalid WebVTT subtitle file.');
            return $text;
        }
        $text = preg_replace('/^(\d{2,}:\d{2}:\d{2}),(\d{3})(\s+-->\s+\d{2,}:\d{2}:\d{2}),(\d{3})[^\n]*$/m', '$1.$2$3.$4', $text);
        return "WEBVTT\n\n" . trim($text) . "\n";
    }

    public static function serve(string $token, string $id): void {
        $payload = \Vaultcast\Repository::playbackToken($token);
        if ($payload === null || ($payload['media_kind'] ?? '') !== 'video') json_error('Playback token expired.', 403);
        $files = self::files((string)$payload['path']);
        if (!isset($files[$id])) json_error('Subtitle not found.', 404);
        $file = $files[$id];
        $text = file_get_contents($file['path'], false, null, 0, self::MAX_BYTES + 1);
        if ($text === false || strlen($text) > self::MAX_BYTES) json_error('Subtitle file is too large or unreadable.', 422);
        try { $body = self::toVtt($text, $file['extension']); }
        catch (RuntimeException $error) { json_error($error->getMessage(), 422); }
        header('Content-Type: text/vtt; charset=utf-8');
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($body));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
        exit;
    }
}
