<?php
declare(strict_types=1);

final class LinkedMediaTransfer
{
    private static function mediaType(array $headers): string
    {
        $mime = strtolower(trim(explode(';', $headers['content-type'] ?? '', 2)[0]));
        return $mime === 'video/x-m4v' ? 'video/mp4' : $mime;
    }

    /**
     * Inspect the peer response before forwarding or writing a single byte.
     * The connection supplied by LinkedServers never follows redirects.
     */
    private static function transfer($curl, callable $accept, callable $write, int $timeout): bool
    {
        $status = 0;
        $headers = [];
        $accepted = false;
        curl_setopt_array($curl, [
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 45,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$headers, &$accepted, $accept): int {
                if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $match)) {
                    $status = (int)$match[1];
                    $headers = [];
                    $accepted = false;
                } elseif (trim($line) === '' && $status >= 200 && !$accepted) {
                    $accepted = $accept($status, $headers);
                } elseif (strpos($line, ':') !== false && !$accepted) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower($name)] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$accepted, $write): int {
                return $accepted ? $write($chunk) : 0;
            },
        ]);
        try {
            return curl_exec($curl) !== false && $accepted;
        } finally {
            curl_close($curl);
        }
    }

    public static function relay(array $link, string $item, string $method): void
    {
        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && !preg_match('/^bytes=(?:\d{1,18}-\d{0,18}|-\d{1,18})$/D', $range)) {
            json_error('Unsupported video range.', 416);
        }
        $curl = LinkedServers::peerConnection($link, 'media', ['item' => $item]);
        if ($range !== '') curl_setopt($curl, CURLOPT_RANGE, substr($range, 6));
        if ($method === 'HEAD') curl_setopt($curl, CURLOPT_NOBODY, true);
        \Vaultcast\Database::releaseForStreaming();
        set_time_limit(0);
        $started = false;
        self::transfer($curl, static function (int $status, array $headers) use (&$started, $method): bool {
            if ($status === 416 && preg_match('#^bytes \*/\d+$#D', $headers['content-range'] ?? '')) {
                header('Content-Range: ' . $headers['content-range']);
                json_error('Video range is unavailable.', 416);
            }
            $mime = self::mediaType($headers);
            if (!in_array($status, [200, 206], true) || !in_array($mime, LinkedServers::MEDIA_TYPES, true)
                || ($headers['content-encoding'] ?? 'identity') !== 'identity'
                || !ctype_digit($headers['content-length'] ?? '')
                || ($status === 206 && !preg_match('#^bytes \d+-\d+/\d+$#D', $headers['content-range'] ?? ''))) return false;
            $started = true;
            http_response_code($status);
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . $headers['content-length']);
            header('Accept-Ranges: bytes');
            if ($status === 206) header('Content-Range: ' . $headers['content-range']);
            header('X-Accel-Buffering: no');
            if ($method !== 'HEAD') {
                while (ob_get_level() > 0) { if (!@ob_end_flush()) break; }
            }
            return true;
        }, static function (string $chunk): int {
            if (connection_aborted()) return 0;
            echo $chunk;
            flush();
            return strlen($chunk);
        }, 28800);
        if (!$started) json_error('The secondary Vaultcast server could not deliver this video.', 502);
        exit;
    }

    public static function download(array $link, array $job, string $part): void
    {
        clearstatcache(true, $part);
        $offset = is_file($part) ? (int)filesize($part) : 0;
        $size = (int)$job['bytes'];
        if ($offset === $size) return;
        if ($offset > $size) throw new DomainException('The partial copy is too large. Remove it and start again.');
        $available = @disk_free_space(dirname($part));
        if ($available !== false && $available < $size - $offset) throw new DomainException('This install does not have enough free space for the copy.');

        $curl = LinkedServers::peerConnection($link, 'copy', ['item' => $job['item_id'], 'file' => $job['file_key']]);
        if ($offset > 0) curl_setopt($curl, CURLOPT_RANGE, $offset . '-');
        $output = null;
        $received = 0;
        $error = '';
        try {
            $ok = self::transfer($curl, static function (int $status, array $headers) use ($offset, $size, $job, $part, &$output, &$error): bool {
                if ($status === 409) {
                    $error = 'The source file changed. Remove this partial copy and start again.';
                    return false;
                }
                if ($status === 403 || $status === 404) {
                    $error = 'This file or download permission is no longer available from the sharing server.';
                    return false;
                }
                $range = 'bytes ' . $offset . '-' . ($size - 1) . '/' . $size;
                $mime = self::mediaType($headers);
                if (($offset > 0 && ($status !== 206 || ($headers['content-range'] ?? '') !== $range))
                    || ($offset === 0 && $status !== 200)
                    || ($mime !== 'application/octet-stream' && strpos($mime, 'video/') !== 0)
                    || ($headers['content-encoding'] ?? 'identity') !== 'identity'
                    || ($headers['content-length'] ?? '') !== (string)($size - $offset)) return false;
                $output = @fopen($part, $offset > 0 ? 'ab' : 'wb');
                if ($output === false) $error = 'Vaultcast cannot write to this install’s download folder.';
                return is_resource($output);
            }, static function (string $chunk) use (&$output, &$received, $size, $offset): int {
                if ($offset + $received + strlen($chunk) > $size) return 0;
                $written = fwrite($output, $chunk);
                $received += (int)$written;
                return (int)$written;
            }, 28800);
        } finally {
            if (is_resource($output)) fclose($output);
        }
        if ($error !== '') throw new DomainException($error);
        if (!$ok || $offset + $received !== $size) throw new RuntimeException('The transfer was interrupted. Retry to continue from the saved bytes.');
    }
}
