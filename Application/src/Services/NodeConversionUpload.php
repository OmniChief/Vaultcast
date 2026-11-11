<?php
declare(strict_types=1);

final class NodeConversionUpload
{
    private static function path(): string {
        return STREAM_DATA . '/runtime/convert-upload-grants.json';
    }

    private static function read(): array {
        $grants = json_decode((string)@file_get_contents(self::path()), true);
        return is_array($grants) ? $grants : [];
    }

    private static function write(array $grants): bool {
        $path = self::path();
        $temporary = $path . '.tmp';
        $body = json_encode($grants);
        return $body !== false && @file_put_contents($temporary, $body, LOCK_EX) === strlen($body)
            && @rename($temporary, $path);
    }

    /** Called before a queue change can revoke or reassign an upload. */
    public static function saveQueue(array $queue): bool {
        $path = self::path();
        clearstatcache(true, $path);
        if (!is_file($path)) return Store::set('convert_queue', $queue);
        $lock = @fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock conversion upload grants.');
        try {
            $existing = self::read();
            $jobs = array_column($queue, null, 'id');
            $active = [];
            foreach ($existing as $id => $grant) {
                $job = $jobs[$id] ?? [];
                if (($grant['expires'] ?? 0) <= time() || !self::matches($job, $grant)) continue;
                $active[$id] = $grant;
            }
            if ($active !== $existing && !self::write($active) && !@unlink($path)) {
                throw new RuntimeException('Could not revoke the conversion upload grant.');
            }
            return Store::set('convert_queue', $queue);
        } finally {
            fclose($lock);
        }
    }

    private static function matches(array $job, array $grant): bool {
        return ($job['status'] ?? '') === 'running'
            && ($job['external_worker_id'] ?? '') === $grant['worker']
            && ($job['lease_token'] ?? '') === $grant['lease']
            && ($job['part'] ?? '') === $grant['part']
            && (int)($job['lease_until'] ?? 0) >= $grant['expires']
            && (int)($job['upload_until'] ?? 0) >= $grant['expires'];
    }

    /** Issue under the queue and upload locks after the worker and item have been authorized. */
    public static function issue(array $job): ?string {
        $id = (string)($job['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/D', $id) || empty($job['part'])) return null;
        $expires = min(time() + 60, (int)($job['lease_until'] ?? 0), (int)($job['upload_until'] ?? 0));
        if ($expires <= time() + 10) return null;
        $path = self::path();
        $lock = @fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) return null;
        try {
            $grants = array_filter(self::read(), static function (array $grant): bool {
                return ($grant['expires'] ?? 0) > time();
            });
            $grant = ['worker'=>$job['external_worker_id'],'lease'=>$job['lease_token'],'part'=>$job['part'],
                'expires'=>$expires,'version'=>STREAM_VERSION,'key'=>bin2hex(random_bytes(16)),'limit'=>max(1073741824,(int)($job['source_stat']['size'] ?? 0)*8)];
            $grants[$id] = $grant;
            if (!self::write($grants)) return null;
            return NodeMedia::writeTicket(['purpose'=>'conversion-upload','id'=>$id,'lease'=>$grant['lease'],'key'=>$grant['key']]);
        } finally {
            fclose($lock);
        }
    }
}
