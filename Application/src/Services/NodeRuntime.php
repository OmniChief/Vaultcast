<?php
declare(strict_types=1);

/** Bring the installer-managed Node relay onto the installed app revision. */
final class NodeRuntime
{
    public static function activate(): void
    {
        $script = STREAM_ROOT . '/netplay/server.js';
        $statePath = STREAM_DATA . '/runtime/node-relay-activation.json';
        $state = json_decode((string)@file_get_contents($statePath), true) ?: [];
        $stamp = [STREAM_VERSION, @filemtime($script), @filesize($script)];
        if (!$stamp[1] || (isset($state['stamp']) && $state['stamp'] === $stamp)
            || (int)($state['retryAfter'] ?? 0) > time() || Updater::inMaintenance()) return;

        if (!is_dir(dirname($statePath))) @mkdir(dirname($statePath), 0770, true);
        $lock = @fopen($statePath . '.lock', 'c');
        if (!$lock) return;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return;
            $state = json_decode((string)@file_get_contents($statePath), true) ?: [];
            if (($state['stamp'] ?? null) === $stamp || (int)($state['retryAfter'] ?? 0) > time()) return;

            $state = ['retryAfter' => time() + 60];
            $health = self::health();
            $revision = @hash_file('sha256', $script);
            if ($revision && ($health['runtimeRevision'] ?? null) === $revision) {
                $state = ['stamp' => $stamp];
            } elseif (($health['ok'] ?? false) && ($health['service'] ?? '') === 'vaultcast-netplay'
                && ($health['rooms'] ?? null) === 0 && empty($health['runtimeRevision'])) {
                // Once the first managed build is running, the Node process handles subsequent
                // activation itself. Bootstrap only needs to replace the legacy relay.
                self::restartLegacyRelay();
            }
            @file_put_contents($statePath, json_encode($state), LOCK_EX);
        } finally {
            fclose($lock);
        }
    }

    public static function mediaReady(): bool
    {
        $health = self::health();
        return ($health['mediaRelay'] ?? false) === true && ($health['mediaApiRevision'] ?? 0) >= 4;
    }

    public static function loopback(string $path): string
    {
        $saved = json_decode((string)@file_get_contents(STREAM_DATA . '/runtime/setup.json'), true) ?: [];
        $port = (int)($saved['netplayPort'] ?? 3100);
        if ($port < 1 || $port > 65535) $port = 3100;
        return 'http://127.0.0.1:' . $port . $path;
    }

    private static function health(): array
    {
        $context = stream_context_create(['http' => ['timeout' => 0.25, 'follow_location' => 0]]);
        $result = @file_get_contents(self::loopback('/health'), false, $context, 0, 65536);
        $health = json_decode((string)$result, true);
        return is_array($health) ? $health : [];
    }

    private static function restartLegacyRelay(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if (!is_file(STREAM_DATA . '/runtime/setup.json')) return;
            Process::powershell('$s = Get-Service -Name VaultcastNetplay -ErrorAction SilentlyContinue; if ($s.Status -eq "Running") { Start-Process powershell.exe -WindowStyle Hidden -ArgumentList "-NoProfile -NonInteractive -Command Restart-Service -Name VaultcastNetplay" }');
            return;
        }
        if (PHP_OS_FAMILY !== 'Linux') return;
        $properties = Process::capture(['systemctl', 'show', 'vaultcast-netplay.service',
            '--property=MainPID,Restart,ActiveState'], 2);
        if (!preg_match('/^MainPID=([1-9][0-9]*)$/m', (string)$properties, $match)
            || !preg_match('/^Restart=(?:on-failure|on-abnormal|always)$/m', (string)$properties)
            || strpos((string)$properties, 'ActiveState=active') === false) return;
        $pid = (int)$match[1];
        $cwd = @readlink('/proc/' . $pid . '/cwd');
        $argv = explode("\0", (string)@file_get_contents('/proc/' . $pid . '/cmdline'));
        $expected = realpath(STREAM_ROOT . '/netplay');
        if ($pid < 2 || !$expected || $cwd !== $expected || ($argv[1] ?? '') !== 'server.js'
            || basename($argv[0] ?? '') !== 'node') return;

        // The existing systemd unit restarts on an abnormal signal. Use the running
        // service's own UID permissions rather than requesting elevated access.
        Process::capture(['kill', '-USR2', (string)$pid], 2);
    }
}
