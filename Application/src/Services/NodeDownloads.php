<?php
declare(strict_types=1);

final class NodeDownloads
{
    public static function playlist(string $url, string $target): bool
    {
        if (!NodeRuntime::playlistReady()) return false;
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http','https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
        $host = strtolower($parts['host']);
        $ips = WebFetch::resolve($host);
        return self::transfer($url, $host, $ips, ['purpose'=>'download','type'=>'playlist',
            'target'=>$target,'limit'=>128*1024*1024,'ua'=>'Vaultcast/' . STREAM_VERSION . ' (+iptv)']);
    }

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
