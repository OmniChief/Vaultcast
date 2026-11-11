<?php
declare(strict_types=1);

/** Use the existing Node relay for coalescing identical provider API requests. */
final class NodeProvider
{
    public static function get(string $url, string $host, string $ip): ?array
    {
        if (!WebFetch::publicIp($ip) || !NodeRuntime::mediaReady()) return null;
        $token = NodeMedia::writeTicket(['purpose'=>'metadata', 'url'=>$url, 'host'=>strtolower($host),
            'ip'=>$ip, 'ua'=>'Vaultcast/' . STREAM_VERSION]);
        if ($token === null) return null;
        $context = stream_context_create(['http'=>['timeout'=>31, 'follow_location'=>0, 'ignore_errors'=>true, 'header'=>'Connection: close']]);
        $stream = null;
        try {
            $stream = @fopen(NodeRuntime::loopback('/runtime/provider/' . $token), 'rb', false, $context);
            if (!$stream) return null;
            $status = 0;
            $statusHeader = 'X-Vaultcast-Upstream-Status: ';
            foreach ((array)(stream_get_meta_data($stream)['wrapper_data'] ?? []) as $line) {
                if (stripos($line, $statusHeader) === 0) $status = (int)substr($line, strlen($statusHeader));
            }
            if ($status < 100 || $status > 599) return null;
            $body = stream_get_contents($stream, 32 * 1024 * 1024 + 1);
            if ($body === false || !feof($stream) || strlen($body) > 32 * 1024 * 1024
                || stream_get_meta_data($stream)['timed_out']) return null;
            if ($status >= 300 && $status < 400 && $body !== '') return [$status, ['location'=>$body], ''];
            return [$status, [], $body];
        } finally {
            if (is_resource($stream)) fclose($stream);
            @unlink(STREAM_DATA . '/runtime/node-media/' . $token . '.json');
        }
    }
}
