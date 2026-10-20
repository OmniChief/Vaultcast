<?php
declare(strict_types=1);

final class Maintenance
{
    private static $active;
    private static $checked = 0.0;
    /** Background-only tasks started by the app itself (not listed in the maintenance UI). */
    const INTERNAL = ['ingest' => 'Import reviewed download'];
    const TASKS = ['site-artwork'=>'Optimise site and theme posters (WebP)','segments'=>'Match TV / anime intros and credits, and movie credits','browse'=>'Prepare search and show summaries','search' => 'Prepare live search index', 'views' => 'Prepare browsing pages', 'epg' => 'Refresh programme guide', 'metadata' => 'Prepare artwork and synopses', 'game-metadata' => 'Refresh game posters and synopses', 'scan' => 'Scan library folders', 'collections' => 'Match collections', 'downloads' => 'Import completed downloads', 'durations' => 'Fill Live media durations', 'facts' => 'Probe new media codecs and store show guides'];

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
        return ['ok' => true, 'tasks' => self::TASKS, 'jobs' => $jobs, 'requirements'=>MaintenanceRequirements::status()['required']];
    }

    public static function start(string $task, string $actor, ?string $target = null, ?array $payload = null): array
    {
        if (!isset(self::TASKS[$task]) && !isset(self::INTERNAL[$task])) throw new InvalidArgumentException('Unknown maintenance task.');
        $id = date('YmdHis') . '-' . bin2hex(random_bytes(6));
        $job = ['id' => $id, 'task' => $task, 'target' => $target, 'status' => 'queued', 'requested_by' => $actor, 'created_at' => date('c')];
        $script = STREAM_ROOT . '/src/Console.php';
        $php = Store::settings()['tools']['php_cli'] ?? '';
        if ($php === '' || !is_file($php)) {
            $php = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '/php.exe' : PHP_BINDIR . '/php';
        }
        if (!is_file($php) && PHP_OS_FAMILY !== 'Windows') {
            // Servers whose PHP CLI is not in PHP_BINDIR (e.g. NAS app packages): use php from the service PATH.
            foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
                if ($dir !== '' && is_file($dir . '/php') && is_executable($dir . '/php')) { $php = $dir . '/php'; break; }
            }
        }
        if (!is_file($php)) throw new RuntimeException('Configure the PHP CLI path in Settings before starting maintenance.');
        if ($payload !== null) \Vaultcast\Repository::putDocument($task . '-request:' . $id, $payload);
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
        // Children (including probes and artwork encoders) inherit the lower CPU priority.
        if (PHP_OS_FAMILY !== 'Windows') {
            if (function_exists('proc_nice')) @proc_nice(10);
            // Optional system utility; no installation or administrator access required.
            foreach (['/usr/bin/ionice','/bin/ionice'] as $ionice) if (is_executable($ionice)) {
                Process::capture([$ionice,'-c','3','-p',(string)getmypid()],2);break;
            }
        }
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
                case 'browse': BrowseIndex::warm(); $job['result']=['ok'=>true]; break;
                case 'search': SearchIndex::warm(); $job['result']=['ok'=>true]; break;
                case 'scan':
                    $job['result'] = Catalogue::rescan();
                    if (!isset($job['result']['ok']) || $job['result']['ok']) Catalogue::warmBrowseViews();
                    // Probe only new/changed files afterwards (queued; waits for this lock).
                    if (!isset($job['result']['ok']) || $job['result']['ok']) { try { self::start('facts', 'scan'); self::start('metadata', 'scan'); } catch (Throwable $ignored) {} }
                    break;
                case 'game-metadata':
                    $lastProgress = 0.0;
                    $job['result'] = GameMetadataRefresh::run($job['target'] ?? null, function ($done, $total, $file) use (&$job, $id, &$lastProgress) {
                        if ($done !== $total && microtime(true) - $lastProgress < 1) return;
                        $lastProgress = microtime(true);
                        $job['progress'] = ['phase'=>'Game posters and synopses', 'done'=>$done, 'total'=>$total, 'file'=>$file, 'at'=>date('c')];
                        \Vaultcast\Repository::putDocument('maintenance-job:' . $id, $job);
                    });
                    break;
                case 'metadata':
                    $lastProgress = 0.0;
                    $job['result'] = MetadataWarmup::run(function ($phase, $done, $total, $file) use (&$job, $id, &$lastProgress) {
                        if ($done !== $total && microtime(true) - $lastProgress < 1) return;
                        $lastProgress = microtime(true);
                        $job['progress'] = ['phase'=>$phase, 'done'=>$done, 'total'=>$total, 'file'=>$file, 'at'=>date('c')];
                        \Vaultcast\Repository::putDocument('maintenance-job:' . $id, $job);
                    }, 120, 120, ($job['target'] ?? '') === 'resume' || strpos((string)($job['requested_by'] ?? ''), 'Continuation of ') === 0);
                    self::checkpoint();
                    // Do not let checkpoint throttling miss a cancellation at
                    // the boundary where a fast pass schedules its successor.
                    if (\Vaultcast\Repository::document('maintenance-cancel:' . $id, false)) throw new RuntimeException('Cancelled by administrator.');
                    // Yield the global maintenance lock between bounded passes.
                    // Persisted attempts and existing WebP files make the next pass resume.
                    if ($job['result']['deferred'] || $job['result']['artwork_deferred'] || !empty($job['result']['sidecars']['deferred'])) {
                        $next = self::start('metadata', 'Continuation of ' . $id, 'resume');
                        $job['result']['continuation'] = $next['job']['id'];
                    }
                    break;
                case 'site-artwork':
                    $lastProgress=0.0;
                    $job['result']=SiteArtwork::optimise(function($done,$total,$file)use(&$job,$id,&$lastProgress){
                        if($done!==$total&&microtime(true)-$lastProgress<1)return;$lastProgress=microtime(true);
                        $job['progress']=['phase'=>'Site and theme artwork','done'=>$done,'total'=>$total,'file'=>$file,'at'=>date('c')];
                        \Vaultcast\Repository::putDocument('maintenance-job:'.$id,$job);
                    });
                    break;
                case 'segments':
                    $lastProgress=0.0;
                    $job['result']=SegmentWarmup::run(function($done,$total,$file)use(&$job,$id,&$lastProgress){
                        if($done!==$total&&microtime(true)-$lastProgress<1)return;$lastProgress=microtime(true);
                        $job['progress']=['phase'=>'Intro / credits timings','done'=>$done,'total'=>$total,'file'=>$file,'at'=>date('c')];
                        \Vaultcast\Repository::putDocument('maintenance-job:'.$id,$job);
                    });
                    break;
                case 'facts':
                    $job['result'] = MediaFacts::refresh(function () { self::checkpoint(); });
                    $backup = MetadataSidecars::schedule();
                    if (empty($backup['ok'])) $job['result']['warning'] = $backup['warning'];
                    break;
                case 'views':
                    Catalogue::warmBrowseViews(); Catalogue::homeLibrary(); Collections::publicList();
                    LiveGuideCache::warm(); $job['result']=['ok'=>true]; break;
                case 'epg':
                    if (empty(Store::settings()['features']['live'])) throw new RuntimeException('Live channels are disabled.');
                    require_once STREAM_ROOT . '/src/Live/epg.php';
                    $job['result'] = epg_refresh(false); break;
                case 'collections': $job['result'] = Collections::refresh($job['target'] ?? null); break;
                case 'downloads':
                    if (empty(Store::settings()['features']['downloads'])) throw new RuntimeException('Downloads are disabled.');
                    $job['result'] = Acquisition::processCompletedDownloads(['source' => 'admin', 'limit' => 3]); break;
                case 'ingest':
                    $req = \Vaultcast\Repository::document('ingest-request:' . $id);
                    if (!is_array($req)) throw new RuntimeException('Import request missing.');
                    $job['result'] = Ingest::commit($req, function ($done, $total, $file) use (&$job, $id) {
                        $job['progress'] = ['done' => (int)$done, 'total' => (int)$total, 'file' => (string)$file, 'at' => date('c')];
                        \Vaultcast\Repository::putDocument('maintenance-job:' . $id, $job);
                    });
                    // Per-file errors are reported in the result; the job itself completed
                    if (isset($job['result']['ok']) && !$job['result']['ok'] && !empty($job['result']['results'])) $job['result']['partial'] = true;
                    break;
                case 'durations':
                    if (empty(Store::settings()['features']['live'])) throw new RuntimeException('Live channels are disabled.');
                    require_once STREAM_ROOT . '/src/Live/durations.php'; relay_run_duration_backfill(); break;
                default: throw new RuntimeException('Unknown task.');
            }
            if (isset($job['result']['ok']) && !$job['result']['ok'] && empty($job['result']['partial'])) {
                throw new RuntimeException($job['result']['error'] ?? $job['result']['reason'] ?? 'The task could not finish.');
            }
            $job['status'] = 'complete';
            MaintenanceRequirements::completed($job);
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
