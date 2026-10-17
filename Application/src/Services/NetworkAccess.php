<?php
declare(strict_types=1);

final class NetworkAccess
{
    private static function ip(string $value): string
    {
        $value = trim($value);
        if (stripos($value, '::ffff:') === 0 && filter_var(substr($value, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $value = substr($value, 7);
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    private static function within(string $address, string $network): bool
    {
        $parts = explode('/', trim($network), 2);
        $ip = @inet_pton($address); $base = @inet_pton(self::ip($parts[0]));
        if ($ip === false || $base === false || strlen($ip) !== strlen($base)) return false;
        if (isset($parts[1]) && !ctype_digit($parts[1])) return false;
        $bits = isset($parts[1]) ? (int)$parts[1] : strlen($base) * 8;
        if ($bits < 0 || $bits > strlen($base) * 8) return false;
        $bytes = intdiv($bits, 8); $remainder = $bits % 8;
        if (substr($ip, 0, $bytes) !== substr($base, 0, $bytes)) return false;
        return !$remainder || ((ord($ip[$bytes]) & (255 << (8 - $remainder))) === (ord($base[$bytes]) & (255 << (8 - $remainder))));
    }

    private static function trusted(string $ip, array $proxies): bool
    {
        foreach ($proxies as $proxy) if (is_string($proxy) && self::within($ip, $proxy)) return true;
        return false;
    }

    public static function isLanClient(): bool
    {
        $peer = self::ip((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($peer === '') return false;
        $proxies = Store::settings()['auth']['trusted_proxies'] ?? [];
        if (!is_array($proxies)) $proxies = [];
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $hasForwarding = $forwarded !== '' || !empty($_SERVER['HTTP_FORWARDED']) || !empty($_SERVER['HTTP_X_REAL_IP']) || !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
        if ($hasForwarding || self::trusted($peer, $proxies)) {
            if (!self::trusted($peer, $proxies) || $forwarded === '' || strlen($forwarded) > 2048) return false;
            $chain = array_map('trim', explode(',', $forwarded));
            $client = $peer;
            while ($chain && self::trusted($client, $proxies)) {
                $client = self::ip((string)array_pop($chain));
                if ($client === '') return false;
            }
            $peer = $client;
        }
        // Loopback may be an unidentified local proxy; require its forwarded client IP.
        foreach (['10.0.0.0/8','172.16.0.0/12','192.168.0.0/16','169.254.0.0/16','fc00::/7','fe80::/10'] as $network) if (self::within($peer, $network)) return true;
        return false;
    }

    public static function guestAllowed(): bool
    {
        return (Store::settings()['auth']['lan_guest_access'] ?? true) !== false && self::isLanClient();
    }
}
