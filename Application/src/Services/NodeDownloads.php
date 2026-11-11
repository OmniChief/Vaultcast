<?php
declare(strict_types=1);

final class NodeDownloads
{
    public static function playerAsset(string $url, string $target): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['cdn.emulatorjs.org', 'cdn.jsdelivr.net'], true)
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || !NodeRuntime::downloadReady()) return false;
        $ips = WebFetch::resolve($host);
        return self::transfer($url, $host, $ips, [
            'purpose'=>'download', 'target'=>$target, 'limit'=>128*1024*1024, 'type'=>'player',
        ]);
    }

    public static function stalker(string $url, string $target, array $feed, int $limit): bool
    {
        if (!NodeRuntime::downloadReady()) return false;
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http','https'], true) || isset($parts['user'])
            || isset($parts['pass']) || empty($parts['host'])) return false;
        $host = strtolower($parts['host']);
        $jar = $feed['_downloadCookies'] ?? stalker_cookie_path($feed);
        $cookies = NodeMedia::cookies($jar, $host, $parts['path'] ?? '/', $parts['scheme'] === 'https');
        if ($cookies === null) return false;
        try {
            $ips = stalker_public_addresses($url);
        } catch (RuntimeException $error) {
            return false;
        }
        return self::transfer($url, $host, $ips, [
            'purpose'=>'download', 'target'=>$target, 'limit'=>$limit, 'type'=>'stalker',
            'cookies'=>$cookies, 'ref'=>preg_replace('#/(?:server/load|portal)\.php$#','/c/',stalker_endpoint($feed)),
            'ua'=>'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3',
        ]);
    }

    private static function transfer(string $url, string $host, array $ips, array $job): bool
    {
        if (!$ips) return false;
        foreach ($ips as $ip) if (!WebFetch::publicIp($ip)) return false;
        $token = NodeMedia::writeTicket($job + ['url'=>$url, 'host'=>$host, 'ip'=>$ips[0], 'ua'=>'Vaultcast/' . STREAM_VERSION]);
        if ($token === null) return false;
        $context = stream_context_create(['http'=>['timeout'=>35, 'follow_location'=>0,
            'ignore_errors'=>true, 'header'=>'Connection: close']]);
        try {
            $body = @file_get_contents(NodeRuntime::loopback('/runtime/download/' . $token), false, $context, 0, 4096);
            $result = json_decode(trim((string)$body), true);
            if (!is_array($result)) throw new RuntimeException('The download worker disconnected. Partial files are available for retry.');
            if (!empty($result['cancelled'])) throw new RuntimeException('The download was cancelled.');
            clearstatcache(true, $job['target']);
            return !empty($result['ok']) && is_file($job['target']);
        } finally {
            @unlink(STREAM_DATA . '/runtime/node-media/' . $token . '.json');
        }
    }
}
