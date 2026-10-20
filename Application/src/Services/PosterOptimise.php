<?php
declare(strict_types=1);


class PosterOptimise {
    public const THUMB_MAX_W = 40;
    public const FULL_MAX_W = 400;
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
            'medium_quality' => self::THUMB_QUALITY,
            'medium_max_w' => 220,
            'sizes_version' => 3,
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
        if (($stored['sizes_version'] ?? 0) < 3) {
            if (($stored['full_max_w'] ?? 1600) === 1600) $stored['full_max_w'] = 400;
            $stored['medium_max_w'] = $stored['thumb_max_w'] ?? 220;
            $stored['medium_quality'] = $stored['thumb_quality'] ?? 72;
            $stored['thumb_max_w'] = 40;
            $stored['sizes_version'] = 3;
        }
        if (is_array($override)) $stored = array_merge($stored, $override);
        $s = array_merge($s, $stored);
        $s['full_quality'] = max(40, min(100, (int)$s['full_quality']));
        $s['full_max_w'] = max(100, min(700, (int)($s['full_max_w'] ?? self::FULL_MAX_W)));
        $s['thumb_quality'] = max(30, min(95, (int)$s['thumb_quality']));
        $s['thumb_max_w'] = max(16, min(64, (int)$s['thumb_max_w']));
        $s['medium_max_w'] = max(80, min(360, (int)$s['medium_max_w']));
        $s['medium_quality'] = max(30, min(95, (int)$s['medium_quality']));
        $s['sizes_version'] = 3;
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
            // Existing _thumb files are the medium variant; retain them without copying.
            'medium' => $dir . '/' . $stem . '_thumb.webp',
            'thumb' => $dir . '/' . $stem . '_tiny.webp',
        ];
    }

    public static function statusForFile(string $file, bool $overwrite = false, bool $withLabel = true): array {
        $stem = self::stemFromFile($file);
        $p = self::pathsForStem($stem);
        $orig = $p['original'];
        $hasOrig = $orig && is_file($orig);
        $hasWebp = is_file($p['webp']) && filesize($p['webp']) > 40;
        $hasThumb = is_file($p['thumb']) && filesize($p['thumb']) > 40;
        $hasMedium = is_file($p['medium']) && filesize($p['medium']) > 40;
        $needs = $hasOrig && (!$hasWebp || !$hasThumb || !$hasMedium || $overwrite);
        $label = $withLabel ? self::labelForStem($stem, $hasOrig ? basename($orig) : $file) : ['title'=>$file, 'kind'=>'item', 'id'=>$stem, 'poster_url'=>null];
        if (!$hasOrig) $reason = 'missing original';
        elseif ($overwrite && ($hasWebp || $hasThumb)) $reason = 're-optimise (overwrite WebP)';
        elseif (!$hasWebp && !$hasThumb) $reason = 'missing WebP + thumb';
        elseif (!$hasWebp) $reason = 'missing main WebP';
        elseif (!$hasThumb) $reason = 'missing thumb WebP';
        elseif (!$hasMedium) $reason = 'missing medium WebP';
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
            'has_medium' => $hasMedium,
            'medium_bytes' => $hasMedium ? (int)filesize($p['medium']) : 0,
            'webp_bytes' => $hasWebp ? (int)filesize($p['webp']) : 0,
            'thumb_bytes' => $hasThumb ? (int)filesize($p['thumb']) : 0,
            'needs' => $needs,
            'reason' => $reason,
            'poster_url' => $label['poster_url'],
        ];
    }

    /** show key (sha1(section|show) prefix) => show title, built once per request from the indexed items table. */
    private static $showTitles = null;
    private static function showTitle(string $key): ?string {
        if (self::$showTitles === null) {
            self::$showTitles = [];
            try {
                foreach (\Vaultcast\Database::connection()->query("SELECT DISTINCT section_id, show_title FROM vaultcast_items WHERE show_title <> ''") as $row) {
                    self::$showTitles[Catalogue::showKey((string)$row['section_id'], (string)$row['show_title'])] = (string)$row['show_title'];
                }
            } catch (\Throwable $e) { /* fall back to unlinked label */ }
        }
        return self::$showTitles[$key] ?? null;
    }

    private static function labelForStem(string $stem, string $fileName = ''): array {
        $kind = 'item';
        $id = $stem;
        $title = $stem;
        $unlinked = 'Unlinked artwork · ' . ($fileName !== '' ? $fileName : $stem);
        if (preg_match('/^movie_([a-f0-9]+)$/i', $stem, $m)) {
            $kind = 'movie'; $id = $m[1];
            $it = Catalogue::item($id);
            $title = $it['title'] ?? $unlinked;
        } elseif (preg_match('/^game_([a-f0-9]+)$/i', $stem, $m)) {
            $kind = 'game'; $id = $m[1];
            $it = Catalogue::item($id);
            $title = $it['title'] ?? $unlinked;
        } elseif (preg_match('/^show_([a-f0-9]+)$/i', $stem, $m)) {
            $kind = 'show'; $id = $m[1];
            $title = self::showTitle($id) ?? $unlinked;
        } elseif (preg_match('/^season_([a-f0-9]+)_s(\d+)$/i', $stem, $m)) {
            $kind = 'season'; $id = $m[1] . '-s' . $m[2];
            $show = self::showTitle($m[1]);
            $title = $show !== null ? $show . ' · Season ' . (int)$m[2] : $unlinked;
        }
        $poster_url = null;
        if ($kind === 'show') $poster_url = '/api/shows/' . $id . '/poster?fetch=0&variant=medium';
        elseif ($kind === 'season') {
            if (preg_match('/^([a-f0-9]+)-s(\d+)$/i', $id, $sm)) {
                $poster_url = '/api/shows/' . $sm[1] . '/seasons/' . $sm[2] . '/poster?fetch=0&variant=medium';
            }
        } else {
            $poster_url = '/api/items/' . $id . '/poster?fetch=0&variant=medium';
        }
        return ['kind' => $kind, 'id' => $id, 'title' => $title, 'poster_url' => $poster_url];
    }

    /** Paginated find of posters needing WebP variants. */
    public static function find(int $offset = 0, int $limit = 40, string $scope = 'all', bool $overwrite = false): array {
        $cfgOverwrite = $overwrite || !empty(self::settings()['overwrite']);
        $all = ArtworkLibrary::targets();
        $scopes = array_filter(explode(',', $scope));
        if ($scopes && !in_array('all', $scopes, true)) $all = array_filter($all, static function ($target) use ($scopes) { return in_array($target['kind'], $scopes, true); });
        $all = array_values($all);
        $total = count($all);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sliceFiles = array_slice($all, $offset, $limit);
        $items = [];
        $missing = 0; $ready = 0;
        foreach ($sliceFiles as $target) {
            // Paginated local discovery adopts sidecars not previously visited
            // by a browser; no remote work in a Settings HTTP request.
            ArtworkLibrary::prepare($target, false);
            $f = basename($target['path']);
            $st = self::statusForFile($f, $cfgOverwrite, false);
            if (!$st['original']) $missing++;
            elseif (!$st['needs']) $ready++;
            if ($st['needs']) $items[] = self::statusForFile($f, $cfgOverwrite);
        }
        $scanned = min($total, $offset + count($sliceFiles));
        $done = $scanned >= $total;
        return [
            'ok' => true,
            'items' => $items,
            'scanned' => $scanned,
            'total' => $total,
            'needs_total' => count($items),
            'missing_artwork' => $missing,
            'ready' => $ready,
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
            $st = self::statusForFile($f, false, false);
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
            if (!function_exists('imagewebp')) return ['ok' => false, 'error' => 'WebP encoding unavailable: ffmpeg has no libwebp and PHP GD has no WebP support.'];
        }
        $cfg = self::settings($opts);
        $file = $fileOrStem;
        if (!preg_match('/\.(jpe?g|png)$/i', $file)) {
            $p0 = self::pathsForStem($file);
            if (!$p0['original']) return ['ok' => false, 'error' => 'Source image missing (no original JPG/PNG for this artwork).'];
            $file = basename($p0['original']);
        }
        $file = basename($file);
        if (!preg_match('/^(movie_|game_|show_|season_)[a-zA-Z0-9_.-]+\.(jpe?g|png)$/i', $file)) {
            return ['ok' => false, 'error' => 'Unrecognised artwork file name.'];
        }
        $st = self::statusForFile($file, (bool)$cfg['overwrite']);
        if (!$st['original']) return ['ok' => false, 'error' => 'Source image missing (no original JPG/PNG for this artwork).'];
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
            else $errors[] = $r['error'];
        } else {
            $made[] = 'webp-exists';
        }
        if ($overwrite || !is_file($paths['medium']) || filesize($paths['medium']) < 40) {
            $r = self::runFfmpegWebp($orig, $paths['medium'], (int)$cfg['medium_max_w'], (int)$cfg['medium_quality'], (int)$cfg['compression_level']);
            if ($r['ok']) $made[] = 'medium'; else $errors[] = 'Medium: ' . $r['error'];
        }
        if ($needThumb) {
            $r = self::runFfmpegWebp($orig, $paths['thumb'], (int)$cfg['thumb_max_w'], (int)$cfg['thumb_quality'], (int)$cfg['compression_level']);
            if ($r['ok']) $made[] = $overwrite && !empty($st['has_thumb']) ? 'thumb-replaced' : 'thumb';
            else if (!in_array($r['error'], $errors, true)) $errors[] = 'Thumb: ' . $r['error'];
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

    /** Why a source can't be converted, or null when it looks like a readable image. */
    private static function sourceProblem(string $src): ?string {
        if (!is_file($src)) return 'Source image missing.';
        if (!is_readable($src)) return 'Source image unreadable (permission denied).';
        $size = (int)@filesize($src);
        if ($size < 64) return 'Source image is empty or truncated (' . $size . ' bytes).';
        $head = (string)@file_get_contents($src, false, null, 0, 16);
        if ($head === '') return 'Source image unreadable.';
        $isJpeg = substr($head, 0, 3) === "\xFF\xD8\xFF";
        $isPng = substr($head, 0, 8) === "\x89PNG\r\n\x1a\n";
        $isWebp = substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP';
        $isGif = substr($head, 0, 3) === 'GIF';
        $isAvif = substr($head, 4, 4) === 'ftyp';
        if (!$isJpeg && !$isPng && !$isWebp && !$isGif && !$isAvif) {
            return stripos($head, '<') !== false ? 'Not an image (the saved file is an HTML/text error page from the artwork source).' : 'Not an image (unrecognised file content).';
        }
        return null;
    }

    /** Used by the explicit system-artwork maintenance job; never called while serving images. */
    public static function encodeSystemArtwork(string $source, string $destination): array {
        return self::runFfmpegWebp($source, $destination, 768, 82, 4);
    }

    private static function runFfmpegWebp(string $src, string $dest, ?int $maxW, int $quality, int $compression = 4): array {
        $bad = self::sourceProblem($src);
        if ($bad !== null) return ['ok' => false, 'error' => $bad];
        $dir = dirname($dest);
        if (!is_writable($dir)) return ['ok' => false, 'error' => 'Write permission denied on the artwork folder.'];
        if (is_file($dest) && !is_writable($dest)) return ['ok' => false, 'error' => 'Write permission denied: existing WebP cannot be replaced.'];
        $tmp = $dest . '.tmp.' . getmypid() . '.webp';
        $ffErr = null;
        if (self::ffmpegHasWebp()) {
            $arguments = ['ffmpeg','-y','-threads','1','-filter_threads','1','-filter_complex_threads','1','-hide_banner','-loglevel','error','-i',$src];
            // Never upscale; even height keeps every encoder happy.
            if ($maxW) array_push($arguments, '-vf', "scale='min(" . (int)$maxW . ",iw)':-2");
            array_push($arguments, '-frames:v', '1', '-threads','1','-c:v','libwebp','-quality',(string)$quality,'-compression_level',(string)max(0,min(6,$compression)),$tmp);
            $run = self::runWithStderr($arguments, 40);
            if (is_file($tmp) && filesize($tmp) >= 40) return self::finish($tmp, $dest);
            @unlink($tmp);
            $ffErr = $run['timeout'] ? 'Conversion timed out after 40 s.' : self::explainFfmpeg($run['stderr']);
        }
        // Fallback: PHP GD (also covers ffmpeg builds without libwebp)
        if (function_exists('imagewebp') && function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring((string)@file_get_contents($src));
            if ($img) {
                $w = imagesx($img); $hgt = imagesy($img);
                if ($maxW && $w > $maxW) {
                    $nh = max(1, (int)round($hgt * $maxW / $w));
                    $dst = imagecreatetruecolor($maxW, $nh);
                    imagealphablending($dst, false); imagesavealpha($dst, true);
                    imagecopyresampled($dst, $img, 0, 0, 0, 0, $maxW, $nh, $w, $hgt);
                    imagedestroy($img); $img = $dst;
                } elseif (!imageistruecolor($img)) {
                    imagepalettetotruecolor($img);
                }
                $ok = @imagewebp($img, $tmp, max(0, min(100, $quality)));
                imagedestroy($img);
                if ($ok && is_file($tmp) && filesize($tmp) >= 40) return self::finish($tmp, $dest);
                @unlink($tmp);
                return ['ok' => false, 'error' => $ffErr ?: 'GD could not write WebP (disk full or permission denied?).'];
            }
            return ['ok' => false, 'error' => $ffErr ?: 'Source image is corrupt or in a format GD cannot read.'];
        }
        return ['ok' => false, 'error' => $ffErr ?: 'WebP encoding unavailable: ffmpeg has no libwebp and PHP GD has no WebP support.'];
    }

    private static function finish(string $tmp, string $dest): array {
        if (!@rename($tmp, $dest)) {
            if (!@copy($tmp, $dest)) { @unlink($tmp); return ['ok' => false, 'error' => 'Write permission denied: could not replace the WebP file.']; }
            @unlink($tmp);
        }
        @chmod($dest, 0664);
        return ['ok' => true];
    }

    private static function explainFfmpeg(string $err): string {
        $e = strtolower($err);
        if ($e === '') return 'ffmpeg failed without output (not installed or blocked?).';
        if (strpos($e, 'unknown encoder') !== false || strpos($e, "libwebp") !== false && strpos($e, 'not found') !== false) return 'ffmpeg has no WebP encoder (libwebp).';
        if (strpos($e, 'permission denied') !== false) return 'Permission denied reading the source or writing the WebP.';
        if (strpos($e, 'no space left') !== false) return 'Disk full while writing the WebP.';
        if (strpos($e, 'invalid data') !== false || strpos($e, 'could not find codec') !== false || strpos($e, 'decode') !== false) return 'Source image is corrupt or not a supported image.';
        if (strpos($e, 'no such file') !== false) return 'Source image missing.';
        $line = trim((string)strtok(trim($err), "\n"));
        return 'ffmpeg error: ' . (strlen($line) > 160 ? substr($line, 0, 160) . '…' : $line);
    }

    /** proc_open with captured stderr and a hard timeout. */
    private static function runWithStderr(array $arguments, int $timeout): array {
        if (!function_exists('proc_open')) return ['stderr' => 'proc_open disabled', 'timeout' => false];
        $errFile = tempnam(sys_get_temp_dir(), 'pow_');
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $p = @proc_open($arguments, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $errFile ?: $null, 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) { if ($errFile) @unlink($errFile); return ['stderr' => 'could not start ffmpeg', 'timeout' => false]; }
        $deadline = microtime(true) + max(1, $timeout);
        $timedOut = false;
        while (true) {
            $st = proc_get_status($p);
            if (!$st['running']) break;
            if (microtime(true) >= $deadline) { $timedOut = true; proc_terminate($p, 9); break; }
            usleep(50000);
        }
        proc_close($p);
        $err = $errFile ? (string)@file_get_contents($errFile, false, null, 0, 8192) : '';
        if ($errFile) @unlink($errFile);
        return ['stderr' => $err, 'timeout' => $timedOut];
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
                'medium_bytes' => $r['status']['medium_bytes'] ?? 0,
                'has_medium' => $r['status']['has_medium'] ?? false,
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
        if ($variant === 'original' || !$wantWebp || preg_match('/\.webp$/i', $resolvedPath)) return $resolvedPath;
        $dir = dirname($resolvedPath);
        $stem = self::stemFromFile(basename($resolvedPath));
        $candidates = [];
        if ($variant === 'thumb') $candidates[] = $dir . '/' . $stem . '_tiny.webp';
        if ($variant === 'thumb' || $variant === 'medium') $candidates[] = $dir . '/' . $stem . '_thumb.webp';
        $candidates[] = $dir . '/' . $stem . '.webp';
        foreach ($candidates as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate) || filesize($candidate) <= 40) continue;
            $info = @getimagesize($candidate);
            if ($info && ($info['mime'] ?? '') === 'image/webp') return $candidate;
        }
        return $resolvedPath;
    }

    public static function clientWantsWebp(): bool {
        // Explicit query from JS feature-detect takes precedence over Accept.
        if (isset($_GET['webp']) && ($_GET['webp'] === '1' || $_GET['webp'] === 'true')) return true;
        if (isset($_GET['webp']) && ($_GET['webp'] === '0' || $_GET['webp'] === 'false')) return false;
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return strpos($accept, 'image/webp') !== false;
    }
}
