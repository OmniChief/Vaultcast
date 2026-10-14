<?php
declare(strict_types=1);

final class Maintenance
{
    private static $active;
    private static $checked = 0.0;
    const TASKS = ['scan' => 'Scan library folders', 'collections' => 'Match collections', 'downloads' => 'Import completed downloads', 'durations' => 'Fill Live media durations'];

    public static function runCommand(string $task, ?string $target = null): array
    {
        if (PHP_SAPI !== 'cli' || !isset(self::TASKS[$task])) throw new RuntimeException('Invalid maintenance command.');
        $id = date('YmdHis') . '-' . bin2hex(random_bytes(6));
        \Vaultcast\Repository::putDocument('maintenance-job:' . $id, ['id' => $id, 'task' => $task, 'target' => $target, 'status' => 'queued', 'requested_by' => 'Administrator CLI / OS schedule', 'created_at' => date('c')]);
        return self::run($id);
    }

    public static function status(): array
    {
        $jobs = \Vaultcast\Repository::rows("SELECT payload FROM vaultcast_documents WHERE name LIKE 'maintenance-job:%' ORDER BY name DESC LIMIT 50");
        return ['ok' => true, 'tasks' => self::TASKS, 'jobs' => $jobs];
    }

    public static function start(string $task, string $actor, ?string $target = null): array
    {
        if (!isset(self::TASKS[$task])) throw new InvalidArgumentException('Unknown maintenance task.');
        $id = date('YmdHis') . '-' . bin2hex(random_bytes(6));
        $job = ['id' => $id, 'task' => $task, 'target' => $target, 'status' => 'queued', 'requested_by' => $actor, 'created_at' => date('c')];
        $script = STREAM_ROOT . '/src/Console.php';
        $php = Store::settings()['tools']['php_cli'] ?? '';
        if ($php === '' || !is_file($php)) {
            $php = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '/php.exe' : PHP_BINDIR . '/php';
        }
        if (!is_file($php)) throw new RuntimeException('Configure the PHP CLI path in Settings before starting maintenance.');
        \Vaultcast\Repository::putDocument('maintenance-job:' . $id, $job);
        $log = STREAM_DATA . '/maintenance.log';
        if (PHP_OS_FAMILY === 'Windows') {
            $quote = function ($value) { return "'" . str_replace("'", "''", $value) . "'"; };
            $args = [];
            if (php_ini_loaded_file()) { $args[] = $quote('-c'); $args[] = $quote('"' . php_ini_loaded_file() . '"'); }
            $args[] = $quote('"' . $script . '"'); $args[] = $quote('maintenance'); $args[] = $quote($id);
            $result = Process::powershell('$p=Start-Process -WindowStyle Hidden -FilePath ' . $quote($php) . ' -ArgumentList @(' . implode(',', $args) . ') -PassThru; $p.Id');
        } else {
            $result = shell_exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' maintenance ' . escapeshellarg($id) . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!');
        }
        if (!(int)$result) {
            $job['status'] = 'failed'; $job['error'] = 'Could not start the PHP maintenance worker.';
            \Vaultcast\Repository::putDocument('maintenance-job:' . $id, $job);
            throw new RuntimeException($job['error']);
        }
        return ['ok' => true, 'job' => $job];
    }

    public static function cancel(string $id): array
    {
        if (!preg_match('/^\d{14}-[a-f0-9]{12}$/D', $id)) throw new InvalidArgumentException('Invalid task ID.');
        \Vaultcast\Repository::putDocument('maintenance-cancel:' . $id, true);
        return ['ok' => true, 'message' => 'Cancellation requested. The current file or database transaction will finish first.'];
    }

    public static function checkpoint(): void
    {
        if (!self::$active || microtime(true) - self::$checked < 0.5) return;
        self::$checked = microtime(true);
        if (\Vaultcast\Repository::document('maintenance-cancel:' . self::$active, false)) throw new RuntimeException('Cancelled by administrator.');
    }

    public static function run(string $id): array
    {
        if (PHP_SAPI !== 'cli' || !preg_match('/^\d{14}-[a-f0-9]{12}$/D', $id)) throw new RuntimeException('Invalid maintenance worker.');
        $job = \Vaultcast\Repository::document('maintenance-job:' . $id);
        if (!$job || $job['status'] !== 'queued') throw new RuntimeException('Task is no longer queued.');
        $lock = fopen(STREAM_DATA . '/maintenance.lock', 'c');
        if (!$lock) throw new RuntimeException('Cannot open maintenance lock.');
        self::$active = $id;
        register_shutdown_function(function () use ($id) {
            if (self::$active !== $id) return;
            try {
                $interrupted = \Vaultcast\Repository::document('maintenance-job:' . $id);
                $interrupted['status'] = 'failed';
                $interrupted['error'] = 'The maintenance worker stopped unexpectedly. Check the PHP server log.';
                $interrupted['finished_at'] = date('c');
                \Vaultcast\Repository::putDocument('maintenance-job:' . $id, $interrupted);
            } catch (Throwable $ignored) {}
        });
        try {
            while (!flock($lock, LOCK_EX | LOCK_NB)) { self::checkpoint(); usleep(250000); }
            self::checkpoint();
            $job['status'] = 'running'; $job['started_at'] = date('c');
            \Vaultcast\Repository::putDocument('maintenance-job:' . $id, $job);
            switch ($job['task']) {
                case 'scan': $job['result'] = Catalogue::rescan(); break;
                case 'collections': $job['result'] = Collections::rematch($job['target'] ?? null); break;
                case 'downloads':
                    if (empty(Store::settings()['features']['downloads'])) throw new RuntimeException('Downloads are disabled.');
                    $job['result'] = Acquisition::processCompletedDownloads(['source' => 'admin', 'limit' => 3]); break;
                case 'durations':
                    if (empty(Store::settings()['features']['live'])) throw new RuntimeException('Live channels are disabled.');
                    require_once STREAM_ROOT . '/src/Live/durations.php'; relay_run_duration_backfill(); break;
                default: throw new RuntimeException('Unknown task.');
            }
            if (isset($job['result']['ok']) && !$job['result']['ok']) {
                throw new RuntimeException($job['result']['error'] ?? $job['result']['reason'] ?? 'The task could not finish.');
            }
            $job['status'] = 'complete';
        } catch (Throwable $error) {
            $job['status'] = \Vaultcast\Repository::document('maintenance-cancel:' . $id, false) ? 'cancelled' : 'failed';
            $job['error'] = $error->getMessage();
        } finally {
            $job['finished_at'] = date('c');
            \Vaultcast\Repository::putDocument('maintenance-job:' . $id, $job);
            self::$active = null; flock($lock, LOCK_UN); fclose($lock);
        }
        return ['ok' => $job['status'] === 'complete', 'job' => $job];
    }
}
