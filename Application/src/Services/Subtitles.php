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

    /** Text to UTF-8 with LF line ends: UTF-16 (BOM), UTF-8 (with or without BOM) or Windows-1252. */
    public static function decode(string $text): string {
        if (substr($text, 0, 2) === "\xff\xfe") $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
        elseif (substr($text, 0, 2) === "\xfe\xff") $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
        elseif (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        return str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
    }

    public static function toVtt(string $text, string $extension): string {
        $text = self::decode($text);
        if ($extension === 'vtt') {
            if (!preg_match('/^WEBVTT(?:[ \t\n]|$)/', $text)) throw new RuntimeException('Invalid WebVTT subtitle file.');
            return $text;
        }
        if ($extension === 'ass' || $extension === 'ssa') return self::assToVtt($text);
        $text = preg_replace_callback('/^[ \t]*(\d{1,3}):(\d{2}):(\d{2})[,.](\d{1,3})[ \t]*-->[ \t]*(\d{1,3}):(\d{2}):(\d{2})[,.](\d{1,3})[^\n]*$/m', function ($m) {
            return sprintf('%02d:%s:%s.%s --> %02d:%s:%s.%s', (int)$m[1], $m[2], $m[3], str_pad($m[4], 3, '0'), (int)$m[5], $m[6], $m[7], str_pad($m[8], 3, '0'));
        }, $text);
        return "WEBVTT\n\n" . trim($text) . "\n";
    }

    /** Which subtitle format the (decoded) text really is: vtt, srt, ass, or null. Extension is ignored. */
    public static function detectFormat(string $text): ?string {
        $head = substr($text, 0, 65536);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $head)) return null; // binary
        if (preg_match('/^WEBVTT(?:[ \t\n]|$)/', $head) && strpos($head, '-->') !== false) return 'vtt';
        if (preg_match('/^\s*\[Script Info\]/i', $head) || (preg_match('/^\[Events\]/mi', $text) && preg_match('/^Dialogue:/mi', $text))) {
            return preg_match('/^Dialogue:/mi', $text) ? 'ass' : null;
        }
        if (preg_match('/^\s*\d+\s*\n\s*\d{1,3}:\d{2}:\d{2}[,.]\d{1,3}\s*-->\s*\d{1,3}:\d{2}:\d{2}[,.]\d{1,3}/m', $head)) return 'srt';
        return null;
    }

    private static function assTime(string $t): ?int {
        if (!preg_match('/^\s*(\d+):(\d{1,2}):(\d{1,2})(?:[.,](\d{1,3}))?\s*$/', $t, $m)) return null;
        $frac = isset($m[4]) ? (int)str_pad($m[4], 3, '0') : 0; // centiseconds "12" -> 120 ms
        return (((int)$m[1] * 60 + (int)$m[2]) * 60 + (int)$m[3]) * 1000 + $frac;
    }

    private static function vttTime(int $ms): string {
        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
    }

    /** ASS/SSA [Events] to WebVTT: styling/override tags dropped, drawings skipped, \N kept as line breaks. */
    public static function assToVtt(string $text): string {
        $format = ['layer', 'start', 'end', 'style', 'name', 'marginl', 'marginr', 'marginv', 'effect', 'text'];
        $inEvents = false;
        $cues = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';') continue;
            if (preg_match('/^\[(.+)\]$/', $line, $sec)) { $inEvents = strcasecmp($sec[1], 'Events') === 0; continue; }
            if (!$inEvents) continue;
            if (stripos($line, 'Format:') === 0) {
                $format = array_map(function ($f) { return strtolower(trim($f)); }, explode(',', substr($line, 7)));
                continue;
            }
            if (stripos($line, 'Dialogue:') !== 0) continue;
            $parts = explode(',', ltrim(substr($line, 9)), count($format));
            if (count($parts) < count($format)) continue;
            $row = array_combine($format, $parts);
            $start = self::assTime((string)($row['start'] ?? ''));
            $end = self::assTime((string)($row['end'] ?? ''));
            if ($start === null || $end === null || $end <= $start) continue;
            $body = (string)($row['text'] ?? '');
            if (preg_match('/\{[^}]*\\\\p[1-9][^}]*\}/', $body)) continue; // vector drawing, not text
            $body = preg_replace('/\{[^}]*\}/', '', $body);
            $body = str_replace(['\\N', '\\n', '\\h'], ["\n", "\n", ' '], $body);
            $body = trim(htmlspecialchars($body, ENT_NOQUOTES, 'UTF-8'));
            $body = preg_replace("/\n{2,}/", "\n", str_replace('-->', '--&gt;', $body));
            if ($body === '') continue;
            $cues[] = [$start, $end, $body];
        }
        if (!$cues) throw new RuntimeException('The ASS/SSA file has no dialogue lines.');
        usort($cues, function ($a, $b) { return $a[0] <=> $b[0]; });
        $out = "WEBVTT\n";
        foreach ($cues as $c) $out .= "\n" . self::vttTime($c[0]) . ' --> ' . self::vttTime($c[1]) . "\n" . $c[2] . "\n";
        return $out;
    }

    /** May this user add subtitles (upload)? Admins always; others when the admin allows it (same toggle as OpenSubtitles). */
    public static function uploadAllowed(array $user): bool {
        if (($user['role'] ?? '') === 'admin') return true;
        $os = Store::settings()['opensubtitles'] ?? [];
        return is_array($os) && !empty($os['allow_users']);
    }

    /**
     * Validate an uploaded subtitle by content (SRT, VTT, ASS/SSA), convert it to WebVTT and save it
     * like the OpenSubtitles files (<stem>.<lang>.uploaded.vtt next to the video, else data/subtitles).
     * @return array{ok:bool,error?:string,file?:string,format?:string}
     */
    public static function upload(string $media, string $raw, string $language): array {
        if ($raw === '') return ['ok' => false, 'error' => 'The file is empty.'];
        if (strlen($raw) > self::MAX_BYTES) return ['ok' => false, 'error' => 'Subtitle files can be at most 2 MB.'];
        $language = strtolower(trim($language));
        if (!preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/', $language)) return ['ok' => false, 'error' => 'Choose the subtitle language.'];
        $text = self::decode($raw);
        if (!mb_check_encoding($text, 'UTF-8')) return ['ok' => false, 'error' => 'Unsupported text encoding.'];
        $format = self::detectFormat($text);
        if ($format === null) return ['ok' => false, 'error' => 'This does not look like an SRT, WebVTT or ASS/SSA subtitle file.'];
        try { $vtt = self::toVtt($text, $format); } catch (RuntimeException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if (strpos($vtt, '-->') === false) return ['ok' => false, 'error' => 'The file contains no subtitle cues.'];
        $path = self::saveFor($media, $vtt, $language, 'uploaded');
        if ($path === null) return ['ok' => false, 'error' => 'Could not save the subtitle on the server.'];
        return ['ok' => true, 'file' => $path, 'format' => $format];
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
