<?php
declare(strict_types=1);

/**
 * Small outbound HTTP GET for widgets and quiz sources. Public URLs only (private, loopback and
 * link-local targets are refused unless the caller allows them), short timeouts, size limits.
 */
final class WebFetch {
    public static function publicIp(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    private static function target(string $url, bool $allowPrivate): array {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) throw new DomainException('Use an http or https address.');
        if (isset($p['user']) || isset($p['pass'])) throw new DomainException('Addresses with passwords are not supported.');
        $host = trim((string)$p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array)@gethostbynamel($host);
        if (!$ips) throw new DomainException('That address could not be found.');
        if (!$allowPrivate) foreach ($ips as $ip) if (!self::publicIp($ip)) throw new DomainException('Only public internet addresses can be used here.');
        $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        return [$host, $ips[0], $port];
    }
    /** @return array{status:int, body:string, type:string} */
    public static function get(string $url, int $timeout = 6, int $maxBytes = 2000000, bool $allowPrivate = false, array $headers = []): array {
        for ($hop = 0; $hop < 4; $hop++) {
            list($host, $ip, $port) = self::target($url, $allowPrivate);
            if (function_exists('curl_init')) {
                $ch = curl_init($url); $body = ''; $type = ''; $location = '';
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => min(4, $timeout), CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)], CURLOPT_USERAGENT => 'Vaultcast/1 (+home media server)',
                    CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$type, &$location) {
                        if (stripos($line, 'content-type:') === 0) $type = trim(substr($line, 13));
                        if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) { $body .= $chunk; return strlen($body) > $maxBytes ? 0 : strlen($chunk); }]);
                $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
                if ($ok === false && strlen($body) > $maxBytes) throw new DomainException('That response is too large.');
                if ($ok === false && $status === 0) throw new DomainException('That address did not respond.');
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true, 'header' => implode("\r\n", array_merge(['User-Agent: Vaultcast/1'], $headers))]]);
                $fh = @fopen($url, 'rb', false, $ctx); if (!$fh) throw new DomainException('That address did not respond.');
                $body = (string)stream_get_contents($fh, $maxBytes + 1); $meta = stream_get_meta_data($fh); fclose($fh);
                if (strlen($body) > $maxBytes) throw new DomainException('That response is too large.');
                $status = 0; $type = ''; $location = '';
                foreach ((array)($meta['wrapper_data'] ?? []) as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) $status = (int)$m[1];
                    if (stripos($line, 'content-type:') === 0) $type = trim(substr($line, 13));
                    if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                }
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                if (!preg_match('#^https?://#i', $location)) { $p = parse_url($url); $location = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($location[0] === '/' ? '' : '/') . $location; }
                $url = $location; continue;
            }
            return ['status' => $status, 'body' => $body, 'type' => $type];
        }
        throw new DomainException('Too many redirects.');
    }
    public static function json(string $url, int $timeout = 6, bool $allowPrivate = false): ?array {
        $r = self::get($url, $timeout, 3000000, $allowPrivate, ['Accept: application/json']);
        if ($r['status'] !== 200) return null;
        $j = json_decode($r['body'], true); return is_array($j) ? $j : null;
    }
}
