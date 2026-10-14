<?php
declare(strict_types=1);


class PosterOptimise {
    public const THUMB_MAX_W = 320;
    public const FULL_MAX_W = 1600;
    public const FULL_QUALITY = 90;
    public const THUMB_QUALITY = 72;
    public const COMPRESSION_LEVEL = 4;

    public static function queuePath(): string {
        return STREAM_DATA . '/poster_optimise_queue.json';
    }

    /** Default WebP conversion settings (Settings -> Optimise -> Posters). */
    public static function defaults(): array {
        return [
            'full_quality' => self::FULL_QUALITY,
            'full_max_w' => self::FULL_MAX_W,
            'thumb_quality' => self::THUMB_QUALITY,
            'thumb_max_w' => self::THUMB_MAX_W,
            'compression_level' => self::COMPRESSION_LEVEL,
            'overwrite' => false,
        ];
    }

    /** Merge saved settings with defaults; clamp ranges. */
    public static function settings(?array $override = null): array {
        $s = self::defaults();
        $stored = [];
        if (class_exists('Store')) {
            $all = Store::settings();
            if (is_array($all['poster_optimise'] ?? null)) $stored = $all['poster_optimise'];
        }
        if (is_array($override)) $stored = array_merge($stored, $override);
        $s = array_merge($s, $stored);
        $s['full_quality'] = max(40, min(100, (int)$s['full_quality']));
        $s['full_max_w'] = max(400, min(2560, (int)($s['full_max_w'] ?? self::FULL_MAX_W)));
        $s['thumb_quality'] = max(30, min(95, (int)$s['thumb_quality']));
        $s['thumb_max_w'] = max(120, min(800, (int)$s['thumb_max_w']));
        $s['compression_level'] = max(0, min(6, (int)$s['compression_level']));
        $s['overwrite'] = !empty($s['overwrite']);
        return $s;
    }

    /** List original raster posters under the cache dir (not already webp/thumb). */
    public static function listOriginals(): array {
        $dir = Posters::dir();
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!preg_match('/\.(jpe?g|png)$/i', $f)) continue;
            if (preg_match('/_thumb\./i', $f)) continue;
            $path = $dir . '/' . $f;
            if (!is_file($path) || filesize($path) < 80) continue;
            $out[] = $f;
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public static function stemFromFile(string $file): string {
        return (string)preg_replace('/\.(jpe?g|png)$/i', '', $file);
    }

    public static function pathsForStem(string $stem): array {
        $dir = Posters::dir();
        $origJpg = $dir . '/' . $stem . '.jpg';
        $origJpeg = $dir . '/' . $stem . '.jpeg';
        $origPng = $dir . '/' . $stem . '.png';
        $original = is_file($origJpg) ? $origJpg : (is_file($origJpeg) ? $origJpeg : (is_file($origPng) ? $origPng : null));
        return [
            'stem' => $stem,
            'original' => $original,
            'webp' => $dir . '/' . $stem . '.webp',
            'thumb' => $dir . '/' . $stem . '_thumb.webp',
        ];
    }

    public static function statusForFile(string $file, bool $overwrite = false): array {
        $stem = self::stemFromFile($file);
        $p = self::pathsForStem($stem);
        $orig = $p['original'];
        $hasOrig = $orig && is_file($orig);
        $hasWebp = is_file($p['webp']) && filesize($p['webp']) > 40;
        $hasThumb = is_file($p['thumb']) && filesize($p['thumb']) > 40;
        $needs = $hasOrig && (!$hasWebp || !$hasThumb || $overwrite);
        $label = self::labelForStem($stem);
        if (!$hasOrig) $reason = 'missing original';
        elseif ($overwrite && ($hasWebp || $hasThumb)) $reason = 're-optimise (overwrite WebP)';
        elseif (!$hasWebp && !$hasThumb) $reason = 'missing WebP + thumb';
        elseif (!$hasWebp) $reason = 'missing main WebP';
        elseif (!$hasThumb) $reason = 'missing thumb WebP';
        else $reason = 'ready';
        return [
            'file' => $file,
            'stem' => $stem,
            'label' => $label['title'],
            'kind' => $label['kind'],
            'id' => $label['id'],
            'original' => $hasOrig ? basename($orig) : null,
            'original_bytes' => $hasOrig ? (int)filesize($orig) : 0,
            'has_webp' => $hasWebp,
            'has_thumb' => $hasThumb,
            'webp_bytes' => $hasWebp ? (int)filesize($p['webp']) : 0,
            'thumb_bytes' => $hasThumb ? (int)filesize($p['thumb']) : 0,
            'needs' => $needs,
            'reason' => $reason,
            'poster_url' => $label['poster_url'],
        ];
    }

    private static function labelForStem(string $stem): array {
        $kind = 'item';
        $id = $stem;
        $title = $stem;
        if (preg_match('/^movie_([a-f0-9]+)$/i', $stem, $m)) {
            $kind = 'movie'; $id = $m[1];
            $it = Catalogue::item($id);
            $title = $it['title'] ?? ('Movie ' . substr($id, 0, 8));
        } elseif (preg_match('/^game_([a-f0-9]+)$/i', $stem, $m)) {
            $kind = 'game'; $id = $m[1];
            $it = Catalogue::item($id);
            $title = $it['title'] ?? ('Game ' . substr($id, 0, 8));
        } elseif (preg_match('/^show_([a-f0-9]+)$/i', $stem, $m)) {
            $kind = 'show'; $id = $m[1];
            $title = 'Show ' . substr($id, 0, 8);
            // best-effort: scan home sections is heavy; keep short id label
        } elseif (preg_match('/^season_([a-f0-9]+)_s(\d+)$/i', $stem, $m)) {
            $kind = 'season'; $id = $m[1] . '-s' . $m[2];
            $title = 'Season ' . (int)$m[2] . ' · ' . substr($m[1], 0, 8);
        }
        $poster_url = null;
        if ($kind === 'show') $poster_url = '/api/shows/' . $id . '/poster?fetch=0&variant=thumb';
        elseif ($kind === 'season') {
            if (preg_match('/^([a-f0-9]+)-s(\d+)$/i', $id, $sm)) {
                $poster_url = '/api/shows/' . $sm[1] . '/seasons/' . $sm[2] . '/poster?fetch=0&variant=thumb';
            }
        } else {
            $poster_url = '/api/items/' . $id . '/poster?fetch=0&variant=thumb';
        }
        return ['kind' => $kind, 'id' => $id, 'title' => $title, 'poster_url' => $poster_url];
    }

    /** Paginated find of posters needing WebP variants. */
    public static function find(int $offset = 0, int $limit = 40, string $scope = 'all', bool $overwrite = false): array {
        $cfgOverwrite = $overwrite || !empty(self::settings()['overwrite']);
        $all = self::listOriginals();
        if ($scope !== 'all' && $scope !== '') {
            $prefix = $scope === 'movies' ? 'movie_' : ($scope === 'games' ? 'game_' : ($scope === 'shows' ? 'show_' : ($scope === 'seasons' ? 'season_' : '')));
            if ($prefix !== '') {
                $all = array_values(array_filter($all, function ($f) use ($prefix) {
                    return strpos($f, $prefix) === 0;
                }));
            }
        }
        $total = count($all);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sliceFiles = array_slice($all, $offset, $limit);
        $items = [];
        foreach ($sliceFiles as $f) {
            $st = self::statusForFile($f, $cfgOverwrite);
            if ($st['needs']) $items[] = $st;
        }
        $scanned = min($total, $offset + count($sliceFiles));
        $done = $scanned >= $total;
        return [
            'ok' => true,
            'items' => $items,
            'scanned' => $scanned,
            'total' => $total,
            'needs_total' => count($items),
            'offset' => $offset,
            'next_offset' => $done ? null : $scanned,
            'done' => $done,
            'overwrite' => $cfgOverwrite,
            'settings' => self::settings(),
        ];
    }


    public static function summary(): array {
        $all = self::listOriginals();
        $ready = 0;
        $need = 0;
        $webpBytes = 0;
        $thumbBytes = 0;
        $origBytes = 0;
        foreach ($all as $f) {
            $st = self::statusForFile($f);
            $origBytes += $st['original_bytes'];
            $webpBytes += $st['webp_bytes'];
            $thumbBytes += $st['thumb_bytes'];
            if ($st['needs']) $need++; else $ready++;
        }
        $q = self::readQueue();
        return [
            'ok' => true,
            'total_originals' => count($all),
            'ready' => $ready,
            'needs' => $need,
            'original_bytes' => $origBytes,
            'webp_bytes' => $webpBytes,
            'thumb_bytes' => $thumbBytes,
            'queue' => $q,
            'ffmpeg_webp' => self::ffmpegHasWebp(),
            'settings' => self::settings(),
        ];
    }

    public static function ffmpegHasWebp(): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        $out = Process::capture(['ffmpeg','-hide_banner','-encoders'], 8);
        $ok = is_string($out) && strpos($out, 'libwebp') !== false;
        return $ok;
    }

    public static function readQueue(): array {
        $stored = \Vaultcast\Repository::document('poster_optimise_queue', null);
        if (is_array($stored)) return $stored;
        $p = self::queuePath();
        if (!is_file($p)) return ['jobs' => [], 'running' => null];
        $j = json_decode((string)@file_get_contents($p), true);
        if (!is_array($j)) return ['jobs' => [], 'running' => null];
        $queue = [
            'jobs' => is_array($j['jobs'] ?? null) ? $j['jobs'] : [],
            'running' => $j['running'] ?? null,
            'updated_at' => $j['updated_at'] ?? null,
        ];
        self::writeQueue($queue);
        return $queue;
    }

    private static function writeQueue(array $q): void {
        $q['updated_at'] = date('c');
        \Vaultcast\Repository::putDocument('poster_optimise_queue', $q);
    }

    public static function cancel(): array {
        \Vaultcast\Repository::putDocument('poster_optimise_cancel', true);
        $lock = fopen(STREAM_DATA . '/poster-optimise.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            $queue = self::readQueue();
            self::cancelQueued($queue);
            self::writeQueue($queue);
            flock($lock, LOCK_UN);
        }
        if ($lock) fclose($lock);
        return ['ok' => true, 'message' => 'Cancellation requested. The current poster will finish first.'];
    }

    private static function cancelQueued(array &$queue): void {
        foreach ($queue['jobs'] as &$job) {
            if (in_array($job['status'] ?? '', ['queued','running'], true)) {
                $job['status'] = 'cancelled'; $job['finished_at'] = date('c');
            }
        }
        unset($job);
        $queue['running'] = null;
    }

    /** Optimise one original file (sync). $opts may override quality / overwrite. */
    public static function optimiseOne(string $fileOrStem, array $opts = []): array {
        if (!self::ffmpegHasWebp()) {
            return ['ok' => false, 'error' => 'ffmpeg libwebp not available'];
        }
        $cfg = self::settings($opts);
        $file = $fileOrStem;
        if (!preg_match('/\.(jpe?g|png)$/i', $file)) {
            $p0 = self::pathsForStem($file);
            if (!$p0['original']) return ['ok' => false, 'error' => 'original not found'];
            $file = basename($p0['original']);
        }
        $file = basename($file);
        if (!preg_match('/^(movie_|game_|show_|season_)[a-zA-Z0-9_.-]+\.(jpe?g|png)$/i', $file)) {
            return ['ok' => false, 'error' => 'invalid poster name'];
        }
        $st = self::statusForFile($file, (bool)$cfg['overwrite']);
        if (!$st['original']) return ['ok' => false, 'error' => 'original missing'];
        $stem = $st['stem'];
        $paths = self::pathsForStem($stem);
        $orig = $paths['original'];
        $made = [];
        $errors = [];
        $overwrite = (bool)$cfg['overwrite'];
        $needWebp = $overwrite || !is_file($paths['webp']) || filesize($paths['webp']) < 40;
        $needThumb = $overwrite || !is_file($paths['thumb']) || filesize($paths['thumb']) < 40;
        if ($needWebp) {
            $r = self::runFfmpegWebp($orig, $paths['webp'], (int)$cfg['full_max_w'], (int)$cfg['full_quality'], (int)$cfg['compression_level']);
            if ($r['ok']) $made[] = $overwrite && !empty($st['has_webp']) ? 'webp-replaced' : 'webp';
            else $errors[] = 'webp: ' . $r['error'];
        } else {
            $made[] = 'webp-exists';
        }
        if ($needThumb) {
            $r = self::runFfmpegWebp($orig, $paths['thumb'], (int)$cfg['thumb_max_w'], (int)$cfg['thumb_quality'], (int)$cfg['compression_level']);
            if ($r['ok']) $made[] = $overwrite && !empty($st['has_thumb']) ? 'thumb-replaced' : 'thumb';
            else $errors[] = 'thumb: ' . $r['error'];
        } else {
            $made[] = 'thumb-exists';
        }
        $st2 = self::statusForFile($file, false);
        return [
            'ok' => empty($errors),
            'file' => $file,
            'made' => $made,
            'errors' => $errors,
            'status' => $st2,
            'settings' => $cfg,
            'error' => $errors ? implode('; ', $errors) : null,
        ];
    }

    private static function runFfmpegWebp(string $src, string $dest, ?int $maxW, int $quality, int $compression = 4): array {
        $tmp = $dest . '.tmp.' . getmypid() . '.webp';
        $arguments = ['ffmpeg','-y','-threads','1','-filter_threads','1','-filter_complex_threads','1','-hide_banner','-loglevel','error','-i',$src];
        if ($maxW) array_push($arguments, '-vf', 'scale=' . (int)$maxW . ':-1');
        array_push($arguments, '-c:v','libwebp','-threads','1','-quality',(string)$quality,'-compression_level',(string)max(0,min(6,$compression)),$tmp);
        $result = Process::capture($arguments, 25);
        if ($result === null || !is_file($tmp) || filesize($tmp) < 40) {
            @unlink($tmp);
            return ['ok'=>false,'error'=>'Artwork conversion failed or timed out.'];
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'rename failed'];
        }
        @chmod($dest, 0664);
        return ['ok' => true];
    }

    /** Queue several files and process up to $budget ones synchronously.
     * pass quality/overwrite $opts into optimiseOne; re-queue done/failed for Re-optimise. */
    public static function enqueueAndRun(array $files, int $budget = 3, array $opts = []): array {
        $lock = fopen(STREAM_DATA . '/poster-optimise.lock', 'c');
        if (!$lock) throw new RuntimeException('Cannot open the poster queue lock.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['ok' => false, 'error' => 'Another poster batch is running. Wait for it to finish or cancel it.'];
        }
        try {
            if ($files) \Vaultcast\Repository::putDocument('poster_optimise_cancel', false);
            return self::runBatch($files, $budget, $opts);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function runBatch(array $files, int $budget, array $opts): array {
        $budget = max(1, min(12, $budget));
        $cfg = self::settings($opts);
        $q = self::readQueue();
        $jobs = $q['jobs'] ?? [];
        $byFile = [];
        foreach ($jobs as $i => $j) {
            if (!empty($j['file'])) $byFile[$j['file']] = $i;
        }
        $added = 0;
        foreach ($files as $f) {
            $f = basename((string)$f);
            if ($f === '' || !preg_match('/\.(jpe?g|png)$/i', $f)) continue;
            if (isset($byFile[$f])) {
                $i = $byFile[$f];
                $st = $jobs[$i]['status'] ?? '';
                if ($st === 'queued' || $st === 'running') {
                    $jobs[$i]['opts'] = $cfg;
                    continue;
                }
                // Re-optimise finished/failed/cancelled: reset and apply current settings
                $jobs[$i]['status'] = 'queued';
                $jobs[$i]['progress'] = 0;
                $jobs[$i]['error'] = null;
                $jobs[$i]['opts'] = $cfg;
                $jobs[$i]['added_at'] = date('c');
                unset($jobs[$i]['finished_at'], $jobs[$i]['result']);
                $added++;
                continue;
            }
            $jobs[] = [
                'id' => bin2hex(random_bytes(6)),
                'file' => $f,
                'status' => 'queued',
                'progress' => 0,
                'added_at' => date('c'),
                'opts' => $cfg,
            ];
            $byFile[$f] = count($jobs) - 1;
            $added++;
        }
        $q['jobs'] = $jobs;
        self::writeQueue($q);

        $results = [];
        $processed = 0;
        foreach ($q['jobs'] as &$job) {
            if (\Vaultcast\Repository::document('poster_optimise_cancel', false)) break;
            if ($processed >= $budget) break;
            if (($job['status'] ?? '') !== 'queued' && ($job['status'] ?? '') !== 'running') continue;
            $job['status'] = 'running';
            $job['progress'] = 10;
            $q['running'] = $job['id'];
            self::writeQueue($q);
            $jobOpts = is_array($job['opts'] ?? null) ? $job['opts'] : $cfg;
            $r = self::optimiseOne((string)$job['file'], $jobOpts);
            $job['progress'] = 100;
            $job['status'] = !empty($r['ok']) ? 'done' : 'failed';
            $job['error'] = $r['error'] ?? null;
            $job['finished_at'] = date('c');
            $job['result'] = [
                'made' => $r['made'] ?? [],
                'webp_bytes' => $r['status']['webp_bytes'] ?? 0,
                'thumb_bytes' => $r['status']['thumb_bytes'] ?? 0,
                'original_bytes' => $r['status']['original_bytes'] ?? 0,
                'settings' => $r['settings'] ?? $jobOpts,
            ];
            $results[] = [
                'file' => $job['file'],
                'ok' => !empty($r['ok']),
                'error' => $r['error'] ?? null,
                'made' => $r['made'] ?? [],
                'settings' => $r['settings'] ?? $jobOpts,
            ];
            $processed++;
            $q['running'] = null;
            self::writeQueue($q);
        }
        unset($job);

        if (\Vaultcast\Repository::document('poster_optimise_cancel', false)) self::cancelQueued($q);

        // trim finished older than keep last 80
        $keep = [];
        $doneCount = 0;
        for ($i = count($q['jobs']) - 1; $i >= 0; $i--) {
            $st = $q['jobs'][$i]['status'] ?? '';
            if ($st === 'queued' || $st === 'running') {
                $keep[] = $q['jobs'][$i];
            } else {
                if ($doneCount < 80) { $keep[] = $q['jobs'][$i]; $doneCount++; }
            }
        }
        $q['jobs'] = array_reverse($keep);
        self::writeQueue($q);

        return [
            'ok' => true,
            'added' => $added,
            'processed' => $processed,
            'results' => $results,
            'queue' => self::readQueue(),
            'summary' => self::summary(),
        ];
    }

    public static function clearFinished(): array {
        $q = self::readQueue();
        $q['jobs'] = array_values(array_filter($q['jobs'] ?? [], function ($j) {
            $st = $j['status'] ?? '';
            return $st === 'queued' || $st === 'running';
        }));
        self::writeQueue($q);
        return ['ok' => true, 'queue' => $q];
    }

    /**
     * Pick best file to serve for a resolved original/cache path.
     * $variant: thumb | full | original
     * $wantWebp: client accepts image/webp
     */
    public static function pickServePath(string $resolvedPath, string $variant = 'full', bool $wantWebp = true): string {
        if (!is_file($resolvedPath)) return $resolvedPath;
        $base = basename($resolvedPath);
        $dir = dirname($resolvedPath);
        // If already a webp path, honour it
        if (preg_match('/\.webp$/i', $base)) return $resolvedPath;
        $stem = self::stemFromFile($base);
        // Also handle when resolved path is outside cache with arbitrary name — only variant siblings in same dir with our naming
        $webp = $dir . '/' . $stem . '.webp';
        $thumb = $dir . '/' . $stem . '_thumb.webp';
        $variant = strtolower($variant ?: 'full');
        if ($variant === 'original' || !$wantWebp) {
            return $resolvedPath;
        }
        if ($variant === 'thumb') {
            if (is_file($thumb) && filesize($thumb) > 40) return $thumb;
            if (is_file($webp) && filesize($webp) > 40) return $webp;
            return $resolvedPath;
        }
        // full
        if (is_file($webp) && filesize($webp) > 40) return $webp;
        return $resolvedPath;
    }

    public static function clientWantsWebp(): bool {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && strpos($accept, 'image/webp') !== false) return true;
        // Explicit query from JS feature-detect
        if (isset($_GET['webp']) && ($_GET['webp'] === '1' || $_GET['webp'] === 'true')) return true;
        if (isset($_GET['webp']) && ($_GET['webp'] === '0' || $_GET['webp'] === 'false')) return false;
        // Default: assume modern browsers; JS always sends webp=0/1
        return false;
    }
}
