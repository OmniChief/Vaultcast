<?php
declare(strict_types=1);

final class StalkerWorkerClock
{
    public static function configure(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_file(STREAM_ROOT . '/netplay/server.js')) return;
        $path = STREAM_DATA . '/runtime/node-stalker.json';
        $config = json_decode((string)@file_get_contents($path), true);
        if (($config['version'] ?? '') === STREAM_VERSION) return;
        $lock = @fopen($path . '.lock', 'c');
        if (!$lock) return;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return;
            $config = json_decode((string)@file_get_contents($path), true);
            if (($config['version'] ?? '') === STREAM_VERSION) return;
            $last = (int)stream_get_contents($lock);
            if ($last > time() - 60) return;
            ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)time());
            $php = Process::phpCli();
            if ($php === null) return;
            $data = json_encode(['php'=>$php, 'ini'=>php_ini_loaded_file() ?: '', 'version'=>STREAM_VERSION]);
            if ($data === false) return;
            $temporary = $path . '.part';
            if (@file_put_contents($temporary, $data, LOCK_EX) === strlen($data)) @rename($temporary, $path);
        } finally {
            fclose($lock);
        }
    }

    public static function managed(string $worker): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') return false;
        $path = STREAM_DATA . '/runtime/node-stalker-' . $worker . '.heartbeat';
        clearstatcache(true, $path);
        return (int)@filemtime($path) > time() - 180
            && trim((string)@file_get_contents($path)) === STREAM_VERSION;
    }
}
