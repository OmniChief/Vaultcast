<?php
declare(strict_types=1);

/** Queue lightweight browse/search preparation after this updater-delivered change. */
final class PerformanceSetup {
    public static function schedule(?string $runtime = null): void {
        $runtime = $runtime ?? STREAM_DATA . '/runtime';
        if (!is_dir($runtime) && !@mkdir($runtime, 0775, true)) return;
        $stamp = $runtime . '/performance-v7.json';
        $read = function () use ($stamp) {
            $state = json_decode((string)@file_get_contents($stamp), true);
            return is_array($state) && (!empty($state['job']) || time() - (int)($state['attempted_at'] ?? 0) < 300);
        };
        if ($read()) return;
        $lock = @fopen($runtime . '/performance-setup.lock', 'c');
        if (!$lock) return;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB) || $read()) return;
            $state = ['attempted_at'=>time()];
            try {
                $views = Maintenance::start('views', 'Prepared browsing update');
                $state['views'] = $views['job']['id'] ?? null;
                if (empty($views['ok']) || empty($views['job']['id'])) throw new RuntimeException('Could not queue browse preparation.');
                $state['job'] = $views['job']['id'];
                // Codec preparation belongs to its previous one-time rollout, not this index upgrade.
                if (!is_file($runtime.'/performance-v6.json')) Maintenance::start('facts','Home runtime preparation');
            } catch (Throwable $e) {
                $state['error'] = $e->getMessage();
                error_log('Vaultcast performance preparation: ' . $e->getMessage());
            }
            @file_put_contents($stamp, json_encode($state), LOCK_EX);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
