<?php
declare(strict_types=1);

/**
 * Optional Linux host administration for Settings → System.
 * Optional host tools. Service operations require explicitly configured OS permissions.
 */
class SystemAdmin {
    /** Services the UI may restart (allowlist). */
    public static function serviceCatalog(): array {
        $services = Store::settings()['system']['services'] ?? [];
        return array_values(array_filter((array)$services, static function ($service): bool {
            return is_array($service)
                && preg_match('/^[a-zA-Z0-9_-]+$/D', (string)($service['id'] ?? ''))
                && preg_match('/^[a-zA-Z0-9@_.-]+\.service$/D', (string)($service['unit'] ?? ''))
                && is_string($service['label'] ?? null);
        }));
    }

    public static function status(): array {
        $load = self::loadavg();
        $mem = self::memInfo();
        $disk = self::diskInfo(STREAM_ROOT);
        $mediaDisk = self::diskInfo(STREAM_DATA);
        $cpu = self::cpuPercent();
        $temp = self::temperature();
        $uptime = self::uptimeSeconds();
        $ifaces = self::networkIfaces();
        $services = [];
        foreach (self::serviceCatalog() as $svc) {
            $st = self::unitState($svc['unit']);
            if (!empty($svc['optional']) && $st['active'] === 'not-found') continue;
            $services[] = array_merge($svc, $st);
        }
        return [
            'ok' => true,
            'version' => defined('STREAM_VERSION') ? STREAM_VERSION : '',
            'hostname' => gethostname() ?: '',
            'uptime_seconds' => $uptime,
            'uptime_human' => self::humanDuration($uptime),
            'load' => $load,
            'cpu_percent' => $cpu,
            'memory' => $mem,
            'disk' => $disk,
            'media_disk' => $mediaDisk,
            'temperature_c' => $temp,
            'services' => $services,
            'network' => $ifaces,
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'opcache' => function_exists('opcache_get_status') ? (bool)(@opcache_get_status(false)['opcache_statistics'] ?? false || @opcache_get_status(false)) : false,
            ],
            'time' => [
                'unix' => time(),
                'iso' => gmdate('c'),
                'local' => date('c'),
            ],
        ];
    }

    public static function processes(int $limit = 80): array {
        $limit = max(10, min(200, $limit));
        $out = [];
        $cmd = 'ps -eo pid,user,pcpu,pmem,rss,etime,comm --sort=-pcpu 2>/dev/null | head -n ' . ($limit + 1);
        $lines = self::shellLines($cmd);
        if (!$lines) {
            return ['ok' => false, 'error' => 'ps unavailable', 'processes' => []];
        }
        array_shift($lines); // header
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^\s*(\d+)\s+(\S+)\s+([\d.]+)\s+([\d.]+)\s+(\d+)\s+(\S+)\s+(.+)$/', $line, $m)) {
                // fallback split
                $parts = preg_split('/\s+/', $line, 7);
                if (!$parts || count($parts) < 7) continue;
                $m = [0, $parts[0], $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $parts[6]];
            }
            $out[] = [
                'pid' => (int)$m[1],
                'user' => $m[2],
                'cpu' => (float)$m[3],
                'mem' => (float)$m[4],
                'rss_kb' => (int)$m[5],
                'etime' => $m[6],
                'name' => trim($m[7]),
            ];
        }
        return ['ok' => true, 'processes' => $out, 'count' => count($out)];
    }

    public static function killProcess(int $pid, bool $force = false): array {
        if ($pid <= 1) return ['ok' => false, 'error' => 'Refusing to signal PID <= 1'];
        if ($pid === getmypid()) return ['ok' => false, 'error' => 'Refusing to kill self'];
        // Protect critical init / systemd
        $name = trim((string)@file_get_contents('/proc/' . $pid . '/comm'));
        $protected = ['systemd', 'init', 'kthreadd', 'tos'];
        if (in_array($name, $protected, true)) {
            return ['ok' => false, 'error' => 'Protected process: ' . $name];
        }
        $sig = $force ? 'KILL' : 'TERM';
        $r = self::sudoRun(['kill', '-' . $sig, (string)$pid]);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] ?: ('kill failed (' . $sig . ')')];
        return ['ok' => true, 'pid' => $pid, 'signal' => $sig, 'name' => $name];
    }

    public static function restartService(string $id): array {
        $svc = null;
        foreach (self::serviceCatalog() as $s) {
            if ($s['id'] === $id) { $svc = $s; break; }
        }
        if (!$svc) return ['ok' => false, 'error' => 'Unknown service'];
        // Do not thrash onlined — not in catalog. nginx/php ok.
        $r = self::sudoRun(['systemctl', 'restart', $svc['unit']]);
        $st = self::unitState($svc['unit']);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'] ?: 'restart failed', 'service' => array_merge($svc, $st)];
        }
        return ['ok' => true, 'service' => array_merge($svc, $st), 'detail' => $r['out']];
    }

    public static function reboot(bool $confirm = false): array {
        if (!$confirm) return ['ok' => false, 'error' => 'confirm=true required'];
        // Schedule reboot in 5s so the HTTP response can return
        $r = self::sudoRun(['bash', '-c', 'nohup bash -c "sleep 3; /sbin/reboot" >/tmp/vaultcast-reboot.log 2>&1 &']);
        if (!$r['ok']) {
            // Fallback: shutdown -r
            $r = self::sudoRun(['shutdown', '-r', '+0']);
            if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] ?: 'reboot failed'];
        }
        return ['ok' => true, 'message' => 'Reboot scheduled in a few seconds'];
    }

    /**
     * Clear portable app caches. $targets optional list:
     * opcache, shm, probe, convert_logs, tmp, all
     */
    public static function clearCaches(array $targets = []): array {
        if (!$targets) $targets = ['opcache', 'shm', 'probe'];
        $targets = array_values(array_unique(array_map('strval', $targets)));
        if (in_array('all', $targets, true)) {
            $targets = ['opcache', 'shm', 'probe', 'convert_logs', 'tmp'];
        }
        $cleared = [];
        if (array_intersect(['shm', 'probe'], $targets)) {
            $cleared['database'] = \Vaultcast\Repository::cacheClear();
        }
        $bytes = 0;
        $data = STREAM_DATA;
        $shm = STREAM_SHM;

        if (in_array('opcache', $targets, true)) {
            if (function_exists('opcache_reset')) {
                $cleared['opcache'] = @opcache_reset() ? true : false;
            } else {
                $cleared['opcache'] = false;
            }
        }

        if (in_array('shm', $targets, true)) {
            $n = 0;
            $b = 0;
            if (is_dir($shm)) {
                foreach (scandir($shm) ?: [] as $f) {
                    if ($f === '.' || $f === '..') continue;
                    // Keep catalogue.json / settings / queues — only ephemeral caches
                    if (!preg_match('/^(home_cache_|browse_|facets_|showcase_|section_shows_)/', $f)
                        && !preg_match('/\.(shm)$/', $f)) {
                        // also allow hashed cache json that looks like our packs
                        if (!preg_match('/^(home_cache|browse_items|browse_shows|facets_movies|facets_shows|showcase_)/', $f)) {
                            continue;
                        }
                    }
                    $path = $shm . '/' . $f;
                    if (is_file($path)) {
                        $sz = @filesize($path) ?: 0;
                        if (@unlink($path)) { $n++; $b += $sz; }
                    }
                }
            }
            foreach (['catalogue.shm', 'catalogue_cache.json'] as $f) {
                $path = $data . '/' . $f;
                if (is_file($path)) {
                    $sz = @filesize($path) ?: 0;
                    if (@unlink($path)) { $n++; $b += $sz; }
                }
            }
            $cleared['shm'] = ['files' => $n, 'bytes' => $b];
            $bytes += $b;
        }

        if (in_array('probe', $targets, true)) {
            $n = 0; $b = 0;
            $probe = $shm . '/probe_cache';
            if (is_dir($probe)) {
                foreach (scandir($probe) ?: [] as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $path = $probe . '/' . $f;
                    if (is_file($path)) {
                        $sz = @filesize($path) ?: 0;
                        if (@unlink($path)) { $n++; $b += $sz; }
                    }
                }
            }
            $cleared['probe'] = ['files' => $n, 'bytes' => $b];
            $bytes += $b;
        }

        if (in_array('convert_logs', $targets, true)) {
            $n = 0; $b = 0;
            foreach (glob($data . '/convert_*.log') ?: [] as $path) {
                if (!is_file($path)) continue;
                // keep very recent (<2h)
                if ((time() - (@filemtime($path) ?: 0)) < 7200) continue;
                $sz = @filesize($path) ?: 0;
                if (@unlink($path)) { $n++; $b += $sz; }
            }
            $cleared['convert_logs'] = ['files' => $n, 'bytes' => $b];
            $bytes += $b;
        }

        if (in_array('tmp', $targets, true)) {
            $n = 0; $b = 0;
            foreach (glob($shm . '/process_*') ?: [] as $path) {
                if (!is_file($path) || time() - (@filemtime($path) ?: 0) < 86400) continue;
                $sz = @filesize($path) ?: 0;
                if (@unlink($path)) { $n++; $b += $sz; }
            }
            $cleared['tmp'] = ['files' => $n, 'bytes' => $b];
            $bytes += $b;
        }

        return ['ok' => true, 'cleared' => $cleared, 'bytes_freed' => $bytes, 'targets' => $targets];
    }

    /** Sizes / counts for Optimise → System panel. */
    public static function optimiseStatus(): array {
        $data = STREAM_DATA;
        $shm = STREAM_SHM;
        $databaseCache = \Vaultcast\Repository::cacheStats();
        $sum = function (string $dir, ?callable $filter = null): array {
            $n = 0; $b = 0;
            if (!is_dir($dir)) return ['files' => 0, 'bytes' => 0];
            foreach (scandir($dir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                if ($filter && !$filter($f)) continue;
                $path = $dir . '/' . $f;
                if (is_file($path)) { $n++; $b += (@filesize($path) ?: 0); }
            }
            return ['files' => $n, 'bytes' => $b];
        };
        $shmCaches = $sum($shm, function ($f) {
            return (bool)preg_match('/^(home_cache_|browse_|facets_|showcase_|section_shows_)/', $f);
        });
        $probe = $sum($shm . '/probe_cache');
        $posterDir = $data . '/posters';
        $posters = ['originals' => 0, 'webp' => 0, 'thumbs' => 0, 'original_bytes' => 0, 'webp_bytes' => 0, 'thumb_bytes' => 0];
        if (is_dir($posterDir)) {
            foreach (scandir($posterDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                $path = $posterDir . '/' . $f;
                if (!is_file($path)) continue;
                $sz = @filesize($path) ?: 0;
                if (preg_match('/_thumb\.webp$/i', $f)) { $posters['thumbs']++; $posters['thumb_bytes'] += $sz; }
                elseif (preg_match('/\.webp$/i', $f)) { $posters['webp']++; $posters['webp_bytes'] += $sz; }
                elseif (preg_match('/\.(jpe?g|png)$/i', $f)) { $posters['originals']++; $posters['original_bytes'] += $sz; }
            }
        }
        $convertLogs = $sum($data, function ($f) { return (bool)preg_match('/^convert_.*\.log$/', $f); });
        $tmpOld = ['files' => 0, 'bytes' => 0];
        foreach (glob($shm . '/process_*') ?: [] as $path) {
            if (!is_file($path)) continue;
            $tmpOld['files']++;
            $tmpOld['bytes'] += (@filesize($path) ?: 0);
        }
        return [
            'ok' => true,
            'version' => defined('STREAM_VERSION') ? STREAM_VERSION : '',
            'shm' => $shmCaches,
            'probe' => $probe,
            'posters' => $posters,
            'convert_logs' => $convertLogs,
            'temporary_files' => $tmpOld,
            'database_cache' => $databaseCache,
            'opcache' => function_exists('opcache_get_status') ? true : false,
        ];
    }

    public static function recentLogs(int $lines = 40): array {
        $lines = max(10, min(200, $lines));
        $candidates = [
            STREAM_DATA . '/convert.log',
            STREAM_DATA . '/acquisition.log',
            '/var/log/nginx/error.log',
            '/var/log/nginx/access.log',
            '/tmp/vaultcast-reboot.log',
        ];
        $out = [];
        foreach ($candidates as $path) {
            if (!is_readable($path)) continue;
            $cmd = 'tail -n ' . $lines . ' ' . escapeshellarg($path) . ' 2>/dev/null';
            $text = self::shellOut($cmd);
            $out[] = [
                'path' => $path,
                'bytes' => @filesize($path) ?: 0,
                'mtime' => @filemtime($path) ?: 0,
                'tail' => $text,
            ];
            if (count($out) >= 4) break;
        }
        return ['ok' => true, 'logs' => $out];
    }

    /* ---------- internals ---------- */

    private static function unitState(string $unit): array {
        $active = trim(self::shellOut('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null'));
        $enabled = trim(self::shellOut('systemctl is-enabled ' . escapeshellarg($unit) . ' 2>/dev/null'));
        if ($active === '') $active = 'unknown';
        return ['active' => $active, 'enabled' => $enabled, 'running' => $active === 'active'];
    }

    private static function loadavg(): array {
        $raw = @file_get_contents('/proc/loadavg');
        if (!$raw) return ['1' => null, '5' => null, '15' => null];
        $p = preg_split('/\s+/', trim($raw));
        return ['1' => (float)($p[0] ?? 0), '5' => (float)($p[1] ?? 0), '15' => (float)($p[2] ?? 0)];
    }

    private static function memInfo(): array {
        $info = [];
        foreach (file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $info[$m[1]] = (int)$m[2];
        }
        $total = ($info['MemTotal'] ?? 0) * 1024;
        $avail = ($info['MemAvailable'] ?? ($info['MemFree'] ?? 0)) * 1024;
        $used = max(0, $total - $avail);
        return [
            'total' => $total,
            'available' => $avail,
            'used' => $used,
            'percent' => $total > 0 ? round(100 * $used / $total, 1) : null,
            'swap_total' => ($info['SwapTotal'] ?? 0) * 1024,
            'swap_free' => ($info['SwapFree'] ?? 0) * 1024,
        ];
    }

    private static function diskInfo(string $path): array {
        if (!is_dir($path)) return ['path' => $path, 'ok' => false];
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false) return ['path' => $path, 'ok' => false];
        $used = $total - $free;
        return [
            'ok' => true,
            'path' => $path,
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'percent' => $total > 0 ? round(100 * $used / $total, 1) : null,
        ];
    }

    private static function cpuPercent(): ?float {
        $a = self::readProcStat();
        usleep(120000);
        $b = self::readProcStat();
        if (!$a || !$b) return null;
        $idle = $b['idle'] - $a['idle'];
        $total = $b['total'] - $a['total'];
        if ($total <= 0) return null;
        return round(100 * (1 - $idle / $total), 1);
    }

    private static function readProcStat(): ?array {
        $line = @file('/proc/stat')[0] ?? '';
        if (!preg_match('/^cpu\s+(.+)$/', $line, $m)) return null;
        $n = array_map('intval', preg_split('/\s+/', trim($m[1])));
        if (count($n) < 4) return null;
        $idle = $n[3] + ($n[4] ?? 0);
        $total = array_sum($n);
        return ['idle' => $idle, 'total' => $total];
    }

    private static function temperature(): ?float {
        $paths = glob('/sys/class/thermal/thermal_zone*/temp') ?: [];
        $vals = [];
        foreach ($paths as $p) {
            $v = (int)trim((string)@file_get_contents($p));
            if ($v > 1000) $v = (int)round($v / 1000);
            if ($v > 0 && $v < 150) $vals[] = $v;
        }
        if (!$vals) return null;
        return (float)round(array_sum($vals) / count($vals), 1);
    }

    private static function uptimeSeconds(): int {
        $raw = @file_get_contents('/proc/uptime');
        if (!$raw) return 0;
        return (int)floatval(explode(' ', $raw)[0]);
    }

    private static function humanDuration(int $sec): string {
        $d = intdiv($sec, 86400); $sec %= 86400;
        $h = intdiv($sec, 3600); $sec %= 3600;
        $m = intdiv($sec, 60);
        $parts = [];
        if ($d) $parts[] = $d . 'd';
        if ($h || $d) $parts[] = $h . 'h';
        $parts[] = $m . 'm';
        return implode(' ', $parts);
    }

    private static function networkIfaces(): array {
        $out = [];
        $raw = self::shellOut("ip -4 -o addr show 2>/dev/null || ifconfig 2>/dev/null | head -n 80");
        foreach (preg_split('/\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\S+)/', $line, $m)) {
                $out[] = ['iface' => rtrim($m[1], ':'), 'ipv4' => explode('/', $m[2])[0]];
            } elseif (preg_match('/inet (\d+\.\d+\.\d+\.\d+)/', $line, $m) && strpos($line, '127.') !== 0) {
                $out[] = ['iface' => '?', 'ipv4' => $m[1]];
            }
        }
        // Tailscale hint
        foreach ($out as &$row) {
            if (strpos($row['ipv4'], '100.') === 0) $row['note'] = 'tailscale?';
        }
        unset($row);
        return $out;
    }

    private static function shellOut(string $cmd): string {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($cmd, $desc, $pipes, null, null, ['bypass_shell' => false]);
        if (!is_resource($p)) return '';
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
        return (string)$out;
    }

    private static function shellLines(string $cmd): array {
        $out = self::shellOut($cmd);
        if ($out === '') return [];
        return preg_split('/\r?\n/', rtrim($out)) ?: [];
    }

    private static function sudoRun(array $argv): array {
        $cmd = 'sudo -n ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return ['ok' => false, 'error' => 'proc_open failed', 'out' => ''];
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        $text = trim((string)$out . (string)$err);
        if ($code !== 0) return ['ok' => false, 'error' => $text !== '' ? $text : ('exit ' . $code), 'out' => $text, 'code' => $code];
        return ['ok' => true, 'out' => $text, 'code' => 0];
    }
}
