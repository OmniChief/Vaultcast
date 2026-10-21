<?php
declare(strict_types=1);

/**
 * Small outbound HTTP GET for widgets and quiz sources. Public URLs only (private, loopback and
 * link-local targets are refused unless the caller allows them), short timeouts, size limits.
 */
final class WebFetch {
    /** True only for globally routable unicast addresses (no private, loopback, link-local, CGNAT, multicast or mapped forms). */
    public static function publicIp(string $ip): bool {
        $ip = trim($ip, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip); if ($bin === false) return false;
            // IPv4-mapped / -compatible (::ffff:a.b.c.d, ::a.b.c.d), NAT64 (64:ff9b::/96) and 6to4 (2002::/16) embed an IPv4 address: check that instead.
            if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || (substr($bin, 0, 12) === str_repeat("\0", 12) && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1")) return self::publicIp((string)inet_ntop(substr($bin, 12)));
            if (substr($bin, 0, 12) === "\0\x64\xff\x9b" . str_repeat("\0", 8)) return self::publicIp((string)inet_ntop(substr($bin, 12)));
            if (substr($bin, 0, 2) === "\x20\x02") return self::publicIp((string)inet_ntop(substr($bin, 2, 4)));
            $first = ord($bin[0]);
            if ($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15) . "\1") return false;      // :: and ::1
            if (($first & 0xfe) === 0xfc) return false;                                                 // fc00::/7 unique local
            if ($first === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) return false;                         // fe80::/10 link-local
            if ($first === 0xfe && (ord($bin[1]) & 0xc0) === 0xc0) return false;                         // fec0::/10 site-local (deprecated)
            if ($first === 0xff) return false;                                                          // multicast
            if (substr($bin, 0, 4) === "\x20\x01\x0d\xb8") return false;                               // documentation
            if (substr($bin, 0, 4) === "\x20\x01\0\0") return false;                                   // Teredo
            return true;
        }
        $n = (int)sprintf('%u', ip2long($ip));
        foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as $r) {
            $base = (int)sprintf('%u', ip2long($r[0])); $mask = $r[1] === 0 ? 0 : (0xFFFFFFFF << (32 - $r[1])) & 0xFFFFFFFF;
            if (($n & $mask) === ($base & $mask)) return false;
        }
        return true;
    }
    private static function target(string $url, bool $allowPrivate): array {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) throw new DomainException('Use an http or https address.');
        if (isset($p['user']) || isset($p['pass'])) throw new DomainException('Addresses with passwords are not supported.');
        $host = trim((string)$p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if (!$ips) throw new DomainException('That address could not be found.');
        if (!$allowPrivate) foreach ($ips as $ip) if (!self::publicIp($ip)) throw new DomainException('Only public internet addresses can be used here.');
        $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        return [$host, $ips[0], $port];
    }
    /** Every A and AAAA address of $host (all are checked, the first is pinned for the connection). */
    public static function resolve(string $host): array {
        $ips = @gethostbynamel($host) ?: [];
        if (function_exists('dns_get_record')) { $aaaa = @dns_get_record($host, DNS_AAAA); foreach ((array)$aaaa as $r) if (!empty($r['ipv6'])) $ips[] = (string)$r['ipv6']; }
        return array_values(array_unique(array_filter(array_map('strval', $ips), function($v){ return $v !== ''; })));
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
                // No cURL: connect to the checked address so a second DNS answer cannot point somewhere private.
                $pu = parse_url($url); $https = strtolower((string)$pu['scheme']) === 'https';
                $pinned = $pu['scheme'] . '://' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip) . (isset($pu['port']) ? ':' . $pu['port'] : '') . ($pu['path'] ?? '/') . (isset($pu['query']) ? '?' . $pu['query'] : '');
                $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true, 'header' => implode("\r\n", array_merge(['User-Agent: Vaultcast/1', 'Host: ' . $host . (isset($pu['port']) ? ':' . $pu['port'] : '')], $headers))],
                    'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
                $fh = @fopen($pinned, 'rb', false, $ctx); if (!$fh) throw new DomainException('That address did not respond.');
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
