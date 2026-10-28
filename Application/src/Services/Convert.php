<?php
declare(strict_types=1);


class Convert {
    public const DEFAULT_CRF = 18;
    public const DEFAULT_PRESET = 'medium';
    public const DEFAULT_AUDIO_BITRATE = '320k';
    public const DEFAULT_NICE = 19;
    public const DEFAULT_IONICE = 3;
    public const DEFAULT_QUALITY_MODE = 'directplay';

    /** @var list<string> */
    private const PRESETS = ['ultrafast','superfast','veryfast','faster','fast','medium','slow','slower','veryslow'];
    /** @var list<string> */
    private const QUALITY_MODES = ['original','directplay','near_lossless','balanced','efficient','custom'];
    /** @var list<string> */
    private const VIDEO_CODECS = ['libx264','copy'];
    /** @var list<string> */
    private const AUDIO_CODECS = ['aac','copy'];

    public static function queue(): array {
        $q = Store::get('convert_queue', []);
        return is_array($q) ? $q : [];
    }

    public static function saveQueue(array $q): bool {
        return Store::set('convert_queue', $q);
    }

    public static function defaults(): array {
        return [
            'enabled' => true,
            'external_workers_only' => false,
            'one_job_at_a_time' => true,
            'threads' => 1,
            'max_threads' => self::maxCpuThreads(),
            'quality_mode' => self::DEFAULT_QUALITY_MODE,
            'crf' => self::DEFAULT_CRF,
            'preset' => self::DEFAULT_PRESET,
            'audio_bitrate' => self::DEFAULT_AUDIO_BITRATE,
            'nice' => self::DEFAULT_NICE,
            'ionice_class' => self::DEFAULT_IONICE,
            'target_video' => 'libx264',
            'target_audio' => 'aac',
            'target_container' => 'mp4',
            'preserve_resolution' => true,
            'quality_note' => 'DirectPlay-smart: remux/stream-copy when H.264 already; otherwise CRF 18 medium. Original mode = lossless remux (no re-encode).',
        ];
    }

    public static function validateSettings(array $cv): array {
        $choices=['quality_mode'=>self::QUALITY_MODES,'preset'=>self::PRESETS,'audio_bitrate'=>['96k','128k','160k','192k','256k','320k'],
            'target_video'=>self::VIDEO_CODECS,'target_audio'=>self::AUDIO_CODECS,'target_container'=>array_keys(self::CONTAINERS)];
        foreach($choices as $key=>$values)if(array_key_exists($key,$cv)&&!in_array($cv[$key],$values,true))throw new InvalidArgumentException('Choose a supported conversion '.$key.'.');
        foreach(['crf'=>[0,28],'nice'=>[0,19],'ionice_class'=>[1,3]] as $key=>$bounds)if(array_key_exists($key,$cv)&&(!is_int($cv[$key])||$cv[$key]<$bounds[0]||$cv[$key]>$bounds[1]))throw new InvalidArgumentException('Conversion '.$key.' must be between '.implode(' and ',$bounds).'.');
        foreach(['enabled','external_workers_only','prefer_directplay','preserve_resolution','one_job_at_a_time'] as $key)if(array_key_exists($key,$cv)&&!is_bool($cv[$key]))throw new InvalidArgumentException('Choose on or off for conversion '.$key.'.');
        $cv['one_job_at_a_time']=true;
        if(isset($cv['preserve_resolution'])&&!$cv['preserve_resolution'])throw new InvalidArgumentException('Background conversion preserves source resolution. Playback transcoding has a resolution limit.');
        return $cv;
    }

    /** Upper bound for the admin "conversion threads" setting: the server's logical CPU count. */
    public static function maxCpuThreads(): int { return self::cpuCount(); }

    private static $cpuCount = null;

    /** Logical CPUs on Linux (/proc/cpuinfo, nproc) or Windows (NUMBER_OF_PROCESSORS); 1 if unknown. */
    public static function cpuCount(): int {
        if (self::$cpuCount !== null) return self::$cpuCount;
        $n = 0;
        if (PHP_OS_FAMILY === 'Windows') {
            $n = (int)getenv('NUMBER_OF_PROCESSORS');
        } else {
            $info = @file_get_contents('/proc/cpuinfo');
            if (is_string($info)) $n = preg_match_all('/^processor\s*:/m', $info);
            if ($n < 1 && function_exists('shell_exec')) $n = (int)trim((string)@shell_exec('nproc 2>/dev/null'));
        }
        return self::$cpuCount = max(1, min(256, $n));
    }

    /**
     * Validate the thread setting: an integer 1..cpuCount. No "auto": ffmpeg's auto (0) takes
     * every core, which defeats the point of keeping Live/VoD responsive during conversions.
     * Returns null when the value is not a whole number.
     */
    public static function validThreadCount($threads, ?int $max = null): ?int {
        $max = $max ?? self::cpuCount();
        if (is_string($threads)) $threads = trim($threads);
        if (is_int($threads)) $n = $threads;
        elseif (is_string($threads) && preg_match('/^\d{1,4}$/', $threads)) $n = (int)$threads;
        elseif (is_float($threads) && floor($threads) === $threads) $n = (int)$threads;
        else return null;
        return ($n >= 1 && $n <= $max) ? $n : null;
    }

    /** Stored value -> safe thread count (clamped; anything invalid becomes 1). */
    public static function sanitizeThreadCount($threads, ?int $max = null): int {
        $max = $max ?? self::cpuCount();
        $n = self::validThreadCount($threads, 4096);
        if ($n === null) return 1;
        return max(1, min($max, $n));
    }

    /**
     * ffmpeg options for N threads: global decoder/filter threads (before -i) and the encoder
     * option (after the codec). libx264/libx265 also take their own thread pools.
     * @return array{input: string[], encoder: string[]}
     */
    public static function threadOptions(int $threads, string $vcodec): array {
        $t = (string)$threads;
        $input = ['-threads', $t, '-filter_threads', $t, '-filter_complex_threads', $t];
        $encoder = ['-threads', $t];
        if ($vcodec === 'libx264') array_push($encoder, '-x264-params', 'threads=' . $t);
        elseif ($vcodec === 'libx265') array_push($encoder, '-x265-params', 'pools=' . $t . ':frame-threads=' . min($threads, 16));
        return ['input' => $input, 'encoder' => $encoder];
    }
    /** Allowed output containers -> ffmpeg muxer. */
    private const CONTAINERS = ['mp4' => 'mp4', 'm4v' => 'mp4', 'mov' => 'mov', 'mkv' => 'matroska'];

    /** Configured output container (Settings -> Convert -> Container), whitelisted. */
    public static function container(): string {
        $settings = Store::settings();
        $c = strtolower(trim((string)($settings['convert']['target_container'] ?? 'mp4')));
        $c = ltrim($c, '.');
        return isset(self::CONTAINERS[$c]) ? $c : 'mp4';
    }

    /** Converted output path: same directory as the original, "<name>.directplay.<ext>". */
    public static function destFor(string $src, string $container = 'mp4'): string {
        $container = isset(self::CONTAINERS[$container]) ? $container : 'mp4';
        $dir = dirname($src);
        $base = pathinfo($src, PATHINFO_FILENAME);
        $base = (string)preg_replace('/(?:\.directplay)+$/i','',$base);
        return $dir . '/' . $base . '.directplay.' . $container;
    }

    private static $queueLock = null;
    private static $queueLockDepth = 0;
    private static function lockQueue() {
        if (is_resource(self::$queueLock)) { self::$queueLockDepth++; return self::$queueLock; }
        $fh = @fopen(STREAM_DATA . '/convert_queue.lock', 'c');
        if ($fh && !@flock($fh, LOCK_EX | LOCK_NB)) { @fclose($fh); return false; }
        if (!$fh) return false;
        self::$queueLock = $fh; self::$queueLockDepth = 1;
        Store::invalidate('convert_queue');
        return $fh;
    }

    private static function unlockQueue($fh): void {
        if ($fh === self::$queueLock && --self::$queueLockDepth > 0) return;
        if (is_resource($fh)) { @flock($fh, LOCK_UN); @fclose($fh); }
        self::$queueLock = null; self::$queueLockDepth = 0;
    }

    private static function editQueueLock() {
        $lock = false;
        for ($i=0; $i<100 && $lock===false; $i++) { $lock=self::lockQueue(); if ($lock===false) usleep(100000); }
        if ($lock===false) json_error('Conversion queue is busy. Please retry.', 503);
        return $lock;
    }

    private static function audit(string $msg): void {
        @file_put_contents(STREAM_DATA . '/convert_actions.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Apply the batch routing policy before any enqueue can start a local process. */
    public static function withExternalPolicy(array $body, callable $action): array {
        if (array_key_exists('external_workers_only',$body) && !is_bool($body['external_workers_only'])) json_error('Choose on or off for external workers only.',422);
        $lock=self::editQueueLock();
        try {
            if (array_key_exists('external_workers_only',$body)) {
                Store::invalidate('settings');
                $settings=Store::settings();
                $settings['convert']['external_workers_only']=$body['external_workers_only'];
                if (!Store::set('settings',$settings)) json_error('Could not save conversion routing policy.',500);
            }
            return $action();
        } finally { self::unlockQueue($lock); }
    }

    public static function enqueue(string $itemId): array {
        $queueLock = self::editQueueLock();
        try {
        $item = Catalogue::item($itemId);
        if (!$item) json_error('Item not found', 404);
        if (($item['media_kind'] ?? '') !== 'video') json_error('Only video items can convert', 400);

        $q = self::queue();
        foreach ($q as $job) {
            if (($job['item_id'] ?? '') === $itemId && in_array($job['status'] ?? '', ['queued','running'], true)) {
                return ['ok' => true, 'entry' => $job, 'message' => 'Already queued'];
            }
        }
        $src = (string)$item['path'];
        if(preg_match('/\.directplay\.[^.]+$/i',$src))json_error('This is already a DirectPlay copy. Choose its original source.',400);
        $container = self::container();
        $dest = self::destFor($src, $container);
        if(is_file($dest))json_error('A DirectPlay copy already exists. Merge versions or review it in the conversion queue.',409);
        if ($dest === $src) {
            json_error('Source is already the DirectPlay .' . $container . ' output', 400);
        }
        $dir = dirname($src);
        if (!is_dir($dir) || !is_writable($dir)) {
            json_error('Source folder is not writable - converted file must go beside the original: ' . $dir, 400);
        }
        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'item_id' => $itemId,
            'title' => $item['title'],
            'src' => $src,
            'dest' => $dest,
            'status' => 'queued',
            'created_at' => date('c'),
            'started_at' => null,
            'finished_at' => null,
            'message' => '',
            'pid' => null,
            'log' => STREAM_DATA . '/convert_' . substr(sha1($itemId),0,12) . '.log',
            'ext' => (string)($item['ext'] ?? ''),
            'section' => (string)($item['section'] ?? ''),
            'container' => $container,
        ];
        $q[] = $entry;
        self::saveQueue($q);
        self::tick();
        return ['ok' => true, 'entry' => $entry];
        } finally { self::unlockQueue($queueLock); }
    }

    /** Enqueue many item ids; returns per-id results. */
    public static function enqueueMany(array $itemIds): array {
        $out = [];
        $queued = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($itemIds as $id) {
            $id = trim((string)$id);
            if ($id === '') continue;
            $item = Catalogue::item($id);
            if (!$item) {
                $out[] = ['ok' => false, 'item_id' => $id, 'error' => 'Item not found'];
                $failed++;
                continue;
            }
            if (($item['media_kind'] ?? '') !== 'video') {
                $out[] = ['ok' => false, 'item_id' => $id, 'error' => 'Not video'];
                $failed++;
                continue;
            }
            $r = self::enqueue($id);
            $r['item_id'] = $id;
            $out[] = $r;
            if (!empty($r['ok'])) {
                if (($r['message'] ?? '') === 'Already queued') $skipped++;
                else $queued++;
            } else {
                $failed++;
            }
        }
        return ['ok' => true, 'queued' => $queued, 'skipped' => $skipped, 'failed' => $failed, 'results' => $out];
    }

    /**
     * Search catalogue video items for the convert picker.
     * q empty + needs_convert=1 lists items that typically need DirectPlay convert.
     */

    /** Human reason a title needs DirectPlay convert (H.264 + AAC). */
    public static function convertReason(array $it, ?array $probe = null): string {
        $vc = '';
        $ac = '';
        if (is_array($probe)) {
            $vc = strtolower((string)($probe['video_codec'] ?? ''));
            if (!empty($probe['audio_tracks'][0]['codec'])) {
                $ac = strtolower((string)$probe['audio_tracks'][0]['codec']);
            }
        }
        if ($vc === '') $vc = strtolower((string)($it['video_codec'] ?? ''));
        if ($ac === '') $ac = strtolower((string)($it['audio_codec'] ?? ''));
        $h264 = ($vc === 'h264' || $vc === 'avc' || strpos($vc, 'h264') === 0 || strpos($vc, 'avc') === 0);
        $aacOk = ($ac === '' || $ac === 'aac' || $ac === 'mp4a' || strpos($ac, 'aac') === 0);
        if ($vc === '' && $ac === '') {
            $ext = strtoupper((string)($it['ext'] ?? ''));
            return $ext !== ''
                ? ($ext . " — codecs unknown; convert for DirectPlay")
                : 'Codecs unknown — convert for DirectPlay';
        }
        $parts = [];
        if ($vc !== '' && !$h264) {
            $parts[] = strtoupper($vc) . ' video (need H.264)';
        }
        if ($ac !== '' && !$aacOk) {
            $parts[] = strtoupper($ac) . ' audio (need AAC)';
        }
        if (!$parts) {
            $bits = [];
            if ($vc !== '') $bits[] = strtoupper($vc);
            if ($ac !== '') $bits[] = strtoupper($ac);
            return (implode(' + ', $bits) ?: 'Codecs') . ' — not DirectPlay ready';
        }
        return implode(' · ', $parts);
    }

    /**
     * Chunked library scan for DirectPlay gaps using stored codec facts.
     * Uses stored media facts, matching the library. Probing belongs to the
     * background facts task, never a Settings HTTP request.
     */
    public static function findNeedsPage(int $offset = 0, int $limit = 35, array $types = []): array {
        $offset = max(0, $offset);
        $limit = max(1, min(40, $limit));
        $allowed = ['movies', 'tv', 'anime'];
        $typeFilter = [];
        foreach ($types as $t) {
            $t = strtolower(trim((string)$t));
            if ($t === 'movie' || $t === 'disney') $t = 'movies';
            if (in_array($t, $allowed, true)) $typeFilter[$t] = true;
        }
        // Default only when caller sent no types. Invalid-only (e.g. games) => empty scan.
        if (!$typeFilter) {
            $rawEmpty = true;
            foreach ($types as $t) {
                if (trim((string)$t) !== '') { $rawEmpty = false; break; }
            }
            if ($rawEmpty) {
                foreach ($allowed as $t) $typeFilter[$t] = true;
            }
        }

        $cat = Catalogue::get();
        $videos = [];
        foreach ($cat['items'] ?? [] as $it) {
            if (!is_array($it)) continue;
            if (($it['media_kind'] ?? '') !== 'video') continue;
            $type = strtolower((string)($it['type'] ?? ''));
            if ($type === 'movie' || $type === 'disney') $type = 'movies';
            // Explicitly skip games / roms / unknown non-video libraries
            if ($type === 'games' || $type === 'game' || $type === 'rom') continue;
            if (!isset($typeFilter[$type])) continue;
            $videos[] = $it;
        }
        $total = count($videos);
        if ($offset >= $total) {
            return [
                'ok' => true,
                'offset' => $offset,
                'next_offset' => $total,
                'scanned' => $total,
                'total_video' => $total,
                'types' => array_keys($typeFilter),
                'count' => 0,
                'items' => [],
                'done' => true,
            ];
        }
        $slice = array_slice($videos, $offset, $limit);
        MediaFacts::preload($slice);
        $hits = [];
        foreach ($slice as $it) {
            $probe = null;
            $facts = MediaFacts::get($it);
            if ($facts) {
                $probe = ['video_codec'=>$facts['video_codec'] ?? '', 'audio_tracks'=>[['codec'=>$facts['audio_codec'] ?? '']]];
            }

            $needs = false;
            $vc = '';
            $ac = '';
            if (is_array($probe)) {
                $vc = strtolower((string)($probe['video_codec'] ?? ''));
                if (!empty($probe['audio_tracks'][0]['codec'])) {
                    $ac = strtolower((string)$probe['audio_tracks'][0]['codec']);
                }
                if ($vc !== '') {
                    $h264 = ($vc === 'h264' || $vc === 'avc' || strpos($vc, 'h264') === 0 || strpos($vc, 'avc') === 0);
                    $aacOk = ($ac === '' || $ac === 'aac' || $ac === 'mp4a' || strpos($ac, 'aac') === 0);
                    $needs = !($h264 && $aacOk);
                } else {
                    $needs = Catalogue::needsConvert($it, false);
                }
            } else {
                $needs = Catalogue::needsConvert($it, false);
            }
            if (!$needs) continue;

            $pub = Catalogue::publicItem($it, false);
            if (is_array($probe)) {
                $pub['video_codec'] = $probe['video_codec'] ?? null;
                $pub['audio_codec'] = $ac !== '' ? $ac : null;
            } else {
                $pub['video_codec'] = $it['video_codec'] ?? null;
                $pub['audio_codec'] = $it['audio_codec'] ?? null;
            }
            $pub['needs_convert'] = true;
            $pub['directplay_ready'] = false;
            $pub['convert_reason'] = self::convertReason($pub, is_array($probe) ? $probe : null);
            $hits[] = $pub;
        }
        $next = $offset + count($slice);
        return [
            'ok' => true,
            'offset' => $offset,
            'next_offset' => $next,
            'scanned' => $next,
            'total_video' => $total,
            'types' => array_keys($typeFilter),
            'count' => count($hits),
            'items' => $hits,
            'done' => $next >= $total,
        ];
    }

    public static function searchLibrary(string $q, array $opts = []): array {
        $limit = max(1, min(200, (int)($opts['limit'] ?? 60)));
        $onlyNeeds = !empty($opts['needs_convert']);
        $ql = strtolower(trim($q));
        $cat = Catalogue::get();
        $hits = [];
        foreach ($cat['items'] ?? [] as $it) {
            if (!is_array($it)) continue;
            if (($it['media_kind'] ?? '') !== 'video') continue;
            if ($onlyNeeds && !Catalogue::needsConvert($it)) continue;
            if ($ql !== '') {
                $hay = strtolower(
                    (string)($it['title'] ?? '') . ' ' .
                    (string)($it['show'] ?? '') . ' ' .
                    (string)($it['rel'] ?? '') . ' ' .
                    (string)($it['ext'] ?? '')
                );
                if (strpos($hay, $ql) === false) continue;
            }
            $pub = Catalogue::publicItem($it);
            $full = (string)($it['path'] ?? '');
            if ($full !== '' && class_exists('MediaProbe')) {
                $probe = MediaProbe::probeSummary($full, 2, true);
                $pub['video_codec'] = $probe['video_codec'] ?? null;
                $ac = null;
                if (!empty($probe['audio_tracks'][0]['codec'])) {
                    $ac = $probe['audio_tracks'][0]['codec'];
                }
                $pub['audio_codec'] = $ac;
                // Recompute after probe so needs_convert reflects H.264/AAC, not extension.
                $pub['needs_convert'] = Catalogue::needsConvert($it);
                $pub['directplay_ready'] = empty($pub['needs_convert']);
                $pub['convert_reason'] = self::convertReason($pub, $probe);
            } else {
                $pub['video_codec'] = $it['video_codec'] ?? null;
                $pub['audio_codec'] = $it['audio_codec'] ?? null;
                $pub['directplay_ready'] = empty($pub['needs_convert']);
                $pub['convert_reason'] = self::convertReason($pub, null);
            }
            $hits[] = $pub;
            if (count($hits) >= $limit) break;
        }
        usort($hits, function ($a, $b) {
            return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });
        return [
            'ok' => true,
            'query' => $q,
            'needs_convert' => $onlyNeeds,
            'count' => count($hits),
            'items' => $hits,
            'defaults' => self::defaults(),
        ];
    }

    public static function tick(bool $allowLocalStart = true): array {
        $settings = Store::settings();
        $cv = array_merge(self::defaults(), is_array($settings['convert'] ?? null) ? $settings['convert'] : []);
        $lock = self::lockQueue();
        if ($lock === false) {
            // Another request is ticking right now; report current state.
            return ['ok' => true, 'queue' => self::queue(), 'busy' => true];
        }
        Store::invalidate('convert_queue');
        $q = self::queue();
        $beforeTick=$q;
        $running = null;
        $completed = [];
        foreach ($q as &$job) {
            if (($job['status'] ?? '') === 'running') {
                if (!empty($job['external_worker_id'])) {
                    if ((int)($job['lease_until'] ?? 0) < time()) {
                        self::cleanupPartialFiles($job);
                        $job['status']='queued'; $job['progress']=0; $job['message']='Contributor disconnected; waiting for a worker';
                        unset($job['external_worker_id'], $job['lease_token'], $job['lease_until']);
                    } else { $running=$job; }
                    continue;
                }
                $pid = (int)($job['pid'] ?? 0);
                if ($pid > 0 && self::pidAlive($pid)) {
                    $pct = self::readJobProgress($job);
                    if ($pct !== null) {
                        // Hard-cap while running - 100% only after successful exit
                        $job['progress'] = min(99.5, (float)$pct);
                        $job['message'] = self::progressMessage($job, (float)$job['progress']);
                    }
                    $running = $job;
                } else {
                    $exitFile = (string)($job['exit_file'] ?? '');
                    $dest = (string)($job['dest'] ?? '');
                    if ($exitFile !== '') {
                        // wrapper: exit code file + atomic rename of .part -> dest
                        $code = is_file($exitFile) ? trim((string)@file_get_contents($exitFile)) : '';
                        $ok = $code === '0' && $dest !== '' && is_file($dest) && filesize($dest) > 1000;
                        $job['finished_at'] = date('c');
                        if ($ok) {
                            $job['status'] = 'done';
                            $job['progress'] = 100;
                            $job['message'] = 'Completed - saved beside original';
                            if (!empty($job['progress_file'])) @unlink((string)$job['progress_file']);
                            $completed[] = $job['id'];
                        } else {
                            $job['status'] = 'failed';
                            $job['message'] = $code === '' ? 'Process ended without exit status' : ('ffmpeg exit ' . $code);
                            if (!empty($job['part']) && is_file($job['part'])) @unlink($job['part']);
                        }
                        @unlink($exitFile);
                    } elseif ($dest !== '' && is_file($dest) && filesize($dest) > 1000) {
                        // legacy (pre-) job without exit file
                        $job['status'] = 'done';
                        $job['finished_at'] = date('c');
                        $job['message'] = 'Completed';
                        $completed[] = $job['id'];
                    } else {
                        $job['status'] = 'failed';
                        $job['finished_at'] = date('c');
                        $job['message'] = 'Process ended without output';
                    }
                }
            }
        }
        unset($job);

        if ($completed) {
            foreach ($q as &$job) {
                if (!in_array($job['id'] ?? '', $completed, true)) continue;
                $job = self::onComplete($job);
            }
            unset($job);
        }

        if ($running) {
            // Persist progress on the matching queue entry
            foreach ($q as &$rj) {
                if (($rj['id'] ?? '') === ($running['id'] ?? '')) {
                    $rj = $running;
                    break;
                }
            }
            unset($rj);
            if($q!==$beforeTick)self::saveQueue($q);
            self::unlockQueue($lock);
            return ['ok' => true, 'running' => $running, 'queue' => $q];
        }

        if(!$allowLocalStart || empty($cv['enabled']) || !empty($cv['external_workers_only'])) {
            if($q!==$beforeTick)self::saveQueue($q);self::unlockQueue($lock);
            return ['ok'=>true,'enabled'=>false,'queue'=>$q];
        }
        foreach ($q as &$job) {
            if (($job['status'] ?? '') !== 'queued') continue;
            $started = self::startJob($job, $cv);
            $job = $started;
            break;
        }
        unset($job);
        if($q!==$beforeTick)self::saveQueue($q);
        self::unlockQueue($lock);
        return ['ok' => true, 'queue' => $q];
    }

    /** After a successful convert: add the new file to the catalogue (same section). */
    private static function onComplete(array $job): array {
        $dest = (string)($job['dest'] ?? '');
        $section = (string)($job['section'] ?? '');
        if ($section === '' && !empty($job['item_id'])) {
            $it = Catalogue::item((string)$job['item_id']);
            $section = (string)($it['section'] ?? '');
        }
        try {
            if ($dest !== '' && $section !== '') {
                $profiles=Store::get('directplay_profiles',[]);$profile=self::copyProfile($dest);
                $source=(string)($job['src'] ?? $dest);$profile+=['source'=>$source,'source_size'=>(int)@filesize($source),'source_mtime'=>(int)@filemtime($source)];
                if(isset($job['source_stat'])) {
                    if($profile['source_size']!==$job['source_stat']['size'] || $profile['source_mtime']!==$job['source_stat']['mtime'])$profile['ready']=false;
                    $profile['source_size']=$job['source_stat']['size'];$profile['source_mtime']=$job['source_stat']['mtime'];
                }
                $profiles[str_replace('\\','/',$dest)]=$profile;Store::set('directplay_profiles',$profiles);
                // The copy is a variant of the original item, never a library entry of its own,
                // even when the job finishes after a timeout or an app update.
                $itemId=(string)($job['item_id'] ?? '');$destReal=\Vaultcast\Paths::real($dest);
                $new=$itemId!=='' && $destReal ? Catalogue::attachDirectPlay($itemId,$destReal,!empty($profile['ready'])) : null;
                if ($new) $job['dest_item_id'] = $new['id'];
                elseif ($itemId==='') { $new = Catalogue::ingestFile($dest, $section); if ($new) $job['dest_item_id'] = $new['id']; }
                else $job['message'] .= ' (original is not in the library; the copy was not added as a separate title)';
            }
        } catch (\Throwable $e) {
            $job['message'] .= ' (catalogue ingest failed: ' . $e->getMessage() . ')';
        }
        // Note on linked TV requests; they are marked done when the admin resolves the job.
        $reqs = self::requests();
        $changed = false;
        foreach ($reqs as &$r) {
            if (($r['status'] ?? '') !== 'pending') continue;
            if (($r['item_id'] ?? '') !== ($job['item_id'] ?? '') && ($r['convert_job_id'] ?? '') !== ($job['id'] ?? '')) continue;
            $r['message'] = 'Converted - resolve in convert queue (remove original or keep both)';
            $r['updated_at'] = date('c');
            $changed = true;
        }
        unset($r);
        if ($changed) self::saveRequests($reqs);
        self::audit('done job=' . ($job['id'] ?? '') . ' dest=' . $dest);
        return $job;
    }

    private static function copyProfile(string $path): array {
        $probe=MediaProbe::probeSummary($path,8,true);$ready=self::isH264($probe['video_codec'] ?? null) && in_array($probe['pixel_format'] ?? '',['yuv420p','yuvj420p'],true);
        foreach($probe['audio_tracks'] ?? [] as $track)if(!self::isAac($track['codec'] ?? null))$ready=false;
        $ready=$ready && in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['mp4','m4v','mov'],true);
        return ['ready'=>$ready,'size'=>(int)@filesize($path),'mtime'=>(int)@filemtime($path)];
    }
    public static function mergeExistingCopies(): array {
        $paths=[];$profiles=Store::get('directplay_profiles',[]);
        foreach(Catalogue::get()['items'] ?? [] as $it) {
            if(preg_match('/(?:\.directplay)+\.[^.]+$/i',(string)($it['path'] ?? '')))$paths[$it['path']]=true;
            foreach($it['directplay_versions'] ?? [] as $path)$paths[$path]=true;
        }
        foreach(array_keys($paths) as $path) {
            Maintenance::checkpoint();$real=\Vaultcast\Paths::real($path);
            if($real && is_file($real) && self::underLibraryRoot($real))$profiles[str_replace('\\','/',$real)]=array_replace($profiles[str_replace('\\','/',$real)] ?? [],self::copyProfile($real));
        }
        Store::set('directplay_profiles',$profiles);
        $result=Catalogue::mergeDirectPlayVersions();
        // Refresh flags on already grouped titles without reintroducing their hidden copies.
        $cat=Catalogue::get();
        foreach($cat['items'] ?? [] as $id=>$it)if(!empty($it['directplay_path'])) {
            $profile=$profiles[$it['directplay_path']] ?? [];
            $cat['items'][$id]['directplay_ready']=!empty($profile['ready']) && (!isset($profile['source_size']) || ((int)$profile['source_size']===(int)$it['size'] && (int)$profile['source_mtime']===(int)$it['mtime']));
        }
        Catalogue::save($cat);return $result;
    }

    private static function sanitizePreset(string $preset): string {
        $preset = preg_replace('/[^a-z0-9]/', '', strtolower($preset));
        return in_array($preset, self::PRESETS, true) ? $preset : self::DEFAULT_PRESET;
    }

    private static function sanitizeQualityMode(string $mode): string {
        $mode = preg_replace('/[^a-z_]/', '', strtolower($mode));
        return in_array($mode, self::QUALITY_MODES, true) ? $mode : self::DEFAULT_QUALITY_MODE;
    }

    private static function sanitizeVideoCodec(string $codec): string {
        $codec = preg_replace('/[^a-z0-9_]/', '', strtolower($codec));
        return in_array($codec, self::VIDEO_CODECS, true) ? $codec : 'libx264';
    }

    private static function sanitizeAudioCodec(string $codec): string {
        $codec = preg_replace('/[^a-z0-9_]/', '', strtolower($codec));
        return in_array($codec, self::AUDIO_CODECS, true) ? $codec : 'aac';
    }

    /** Apply named quality mode → concrete encode knobs (CRF/preset). Remux modes leave CRF unused. */
    private static function applyQualityMode(array $cv): array {
        $mode = self::sanitizeQualityMode((string)($cv['quality_mode'] ?? self::DEFAULT_QUALITY_MODE));
        $cv['quality_mode'] = $mode;
        if ($mode === 'near_lossless') {
            $cv['crf'] = 18;
            $cv['preset'] = self::sanitizePreset((string)($cv['preset'] ?? 'medium')) ?: 'medium';
            if (!in_array($cv['preset'], ['medium','slow','slower'], true)) $cv['preset'] = 'medium';
            $cv['target_video'] = 'libx264';
            $cv['target_audio'] = 'aac';
        } elseif ($mode === 'balanced') {
            $cv['crf'] = 20;
            $cv['preset'] = 'medium';
            $cv['target_video'] = 'libx264';
            $cv['target_audio'] = 'aac';
        } elseif ($mode === 'efficient') {
            $cv['crf'] = 23;
            $cv['preset'] = 'faster';
            $cv['target_video'] = 'libx264';
            $cv['target_audio'] = 'aac';
        } elseif ($mode === 'original') {
            $cv['target_video'] = 'copy';
            $cv['target_audio'] = 'copy';
        }
        // directplay + custom: keep stored knobs; directplay path may still remux
        return $cv;
    }

    private static function isH264(?string $codec): bool {
        $c = strtolower((string)$codec);
        return $c === 'h264' || $c === 'avc' || strpos($c, 'h264') === 0 || strpos($c, 'avc') === 0;
    }

    private static function isAac(?string $codec): bool {
        $c = strtolower((string)$codec);
        return $c === 'aac' || $c === 'mp4a' || strpos($c, 'aac') === 0;
    }

    /**
     * Build ffmpeg argv fragment after -i (shell-escaped tokens joined by spaces).
     * Returns [fragment, human_label, strategy].
     */
    private static function buildFfmpegArgs(string $src, array $cv, string $muxer, string $faststart): array {
        $mode = self::sanitizeQualityMode((string)($cv['quality_mode'] ?? self::DEFAULT_QUALITY_MODE));
        $crf = (int)($cv['crf'] ?? self::DEFAULT_CRF);
        if ($crf < 0) $crf = 0;
        if ($crf > 28) $crf = 28;
        $preset = self::sanitizePreset((string)($cv['preset'] ?? self::DEFAULT_PRESET));
        $abit = preg_replace('/[^0-9kKmM]/', '', (string)($cv['audio_bitrate'] ?? self::DEFAULT_AUDIO_BITRATE));
        if ($abit === '') $abit = self::DEFAULT_AUDIO_BITRATE;
        $vcodec = self::sanitizeVideoCodec((string)($cv['target_video'] ?? 'libx264'));
        $acodec = self::sanitizeAudioCodec((string)($cv['target_audio'] ?? 'aac'));
        $threads = self::sanitizeThreadCount($cv['threads'] ?? 1);
        $threadArg = ' ' . implode(' ', self::threadOptions($threads, 'copy')['encoder']);
        $x264Arg = ' ' . implode(' ', self::threadOptions($threads, 'libx264')['encoder']);
        $encArg = ' ' . implode(' ', self::threadOptions($threads, $vcodec)['encoder']);

        $probe = class_exists('MediaProbe') ? MediaProbe::probeSummary($src, 4, true) : [];
        $srcVideo = is_array($probe) ? ($probe['video_codec'] ?? null) : null;
        $srcAudio = null;
        if (is_array($probe) && !empty($probe['audio_tracks'][0]['codec'])) {
            $srcAudio = $probe['audio_tracks'][0]['codec'];
        }

        // Original: true remux / stream-copy — never re-encode (same size, tiny CPU).
        if ($mode === 'original' || ($mode === 'custom' && $vcodec === 'copy' && $acodec === 'copy')) {
            $frag = '-map 0 -c copy' . $faststart . ' -f ' . $muxer;
            return [$frag, 'original remux (stream-copy)', 'remux'];
        }

        // DirectPlay-smart: remux when already H.264(+AAC); copy video + AAC audio; else encode.
        if ($mode === 'directplay') {
            $browserH264=self::isH264($srcVideo) && in_array($probe['pixel_format'] ?? '',['yuv420p','yuvj420p'],true);
            $allAac=true;foreach($probe['audio_tracks'] ?? [] as $track)if(!self::isAac($track['codec'] ?? null))$allAac=false;
            if ($browserH264 && $allAac) {
                $frag = '-map 0:v:0 -map 0:a? -c copy' . $faststart . ' -f ' . $muxer;
                return [$frag, 'DirectPlay remux (H.264+AAC copy)', 'remux'];
            }
            if ($browserH264) {
                $frag = '-map 0:v:0 -map 0:a? -c:v copy -c:a aac -b:a ' . $abit . $threadArg . $faststart . ' -f ' . $muxer;
                return [$frag, 'DirectPlay (video copy + AAC ' . $abit . ')', 'audio_reencode'];
            }
            // Non-H.264 source: must encode for browser DirectPlay
            $frag = sprintf(
                '-map 0:v:0 -map 0:a? -c:v libx264 -preset %s -crf %d -c:a aac -b:a %s -pix_fmt yuv420p%s%s -f %s',
                $preset, $crf, $abit, $x264Arg, $faststart, $muxer
            );
            return [$frag, 'DirectPlay encode (source ' . ($srcVideo ?: 'unknown') . ' → H.264 CRF ' . $crf . '/' . $preset . ')', 'encode'];
        }

        // Encode modes (near_lossless / balanced / efficient / custom encode)
        if ($vcodec === 'copy' && $acodec !== 'copy') {
            $frag = '-map 0:v:0 -map 0:a? -c:v copy -c:a ' . $acodec . ' -b:a ' . $abit . $threadArg . $faststart . ' -f ' . $muxer;
            return [$frag, 'video copy + ' . $acodec . ' ' . $abit, 'audio_reencode'];
        }
        if ($vcodec === 'copy' && $acodec === 'copy') {
            $frag = '-map 0 -c copy' . $faststart . ' -f ' . $muxer;
            return [$frag, 'stream-copy', 'remux'];
        }

        $frag = sprintf(
            '-map 0:v:0 -map 0:a? -c:v %s -preset %s -crf %d -c:a %s -b:a %s -pix_fmt yuv420p%s%s -f %s',
            $vcodec, $preset, $crf, $acodec, $abit, $encArg, $faststart, $muxer
        );
        return [$frag, 'encode ' . $vcodec . ' CRF ' . $crf . '/' . $preset, 'encode'];
    }

    private static function startJob(array $job, array $cv): array {
        // Final guard shared by every local queue start, including stale callers.
        Store::invalidate('settings');
        if (!empty(Store::settings()['convert']['external_workers_only'])) return $job;
        $cv = self::applyQualityMode($cv);
        $nice = (int)($cv['nice'] ?? self::DEFAULT_NICE);
        if ($nice < 0) $nice = 0;
        if ($nice > 19) $nice = 19;
        $ionice = (int)($cv['ionice_class'] ?? self::DEFAULT_IONICE);
        if ($ionice < 1) $ionice = 1;
        if ($ionice > 3) $ionice = 3;
        $crf = (int)($cv['crf'] ?? self::DEFAULT_CRF);
        if ($crf < 0) $crf = 0;
        if ($crf > 28) $crf = 28;
        $preset = self::sanitizePreset((string)($cv['preset'] ?? self::DEFAULT_PRESET));
        $mode = self::sanitizeQualityMode((string)($cv['quality_mode'] ?? self::DEFAULT_QUALITY_MODE));

        // Output always beside the original (same directory), regardless of what was stored.
        $container = (string)($job['container'] ?? '');
        if (!isset(self::CONTAINERS[$container])) {
            $container = strtolower((string)pathinfo((string)$job['dest'], PATHINFO_EXTENSION));
            if (!isset(self::CONTAINERS[$container])) $container = 'mp4';
        }
        $job['dest'] = self::destFor((string)$job['src'], $container);
        $job['container'] = $container;
        if(is_file($job['dest'])) {
            $job['status']='failed';$job['finished_at']=date('c');$job['message']='A DirectPlay output already exists. It was not overwritten.';return $job;
        }
        if ($job['dest'] === $job['src']) {
            $job['status'] = 'failed';
            $job['finished_at'] = date('c');
            $job['message'] = 'Source is already the DirectPlay output';
            return $job;
        }
        if (!is_file((string)$job['src'])) {
            $job['status'] = 'failed';
            $job['finished_at'] = date('c');
            $job['message'] = 'Source file missing';
            return $job;
        }
        $job['part'] = dirname($job['dest']) . '/.' . basename($job['dest']) . '.part';
        $job['exit_file'] = STREAM_DATA . '/convert_' . $job['id'] . '.exit';
        $job['progress_file'] = STREAM_DATA . '/convert_' . $job['id'] . '.progress';
        @unlink($job['exit_file']);
        @unlink($job['progress_file']);
        $dur = self::probeDuration((string)$job['src']);
        if ($dur !== null && $dur > 0) $job['duration_s'] = $dur;
        $job['progress'] = 0;
        $job['source_stat']=['size'=>(int)filesize($job['src']),'mtime'=>(int)filemtime($job['src'])];
        $muxer = self::CONTAINERS[$container];
        $faststart = $muxer === 'matroska' ? '' : ' -movflags +faststart';

        list($ffArgs, $label, $strategy) = self::buildFfmpegArgs((string)$job['src'], $cv, $muxer, $faststart);
        $threads = self::sanitizeThreadCount($cv['threads'] ?? 1);
        $job['threads'] = $threads;
        $inputThreads = implode(' ', self::threadOptions($threads, 'copy')['input']);

        // Wrapper: $1=src $2=part $3=dest $4=exit_file $5=progress_file. No -vf scale: preserve resolution.
        $inner = sprintf(
            'nice -n %d ionice -c %d %s -nostdin -y %s -progress "$5" -nostats -i "$1" %s "$2" && mv -n "$2" "$3" && [ ! -e "$2" ]; rc=$?; [ $rc -ne 0 ] && rm -f "$2"; echo $rc > "$4"',
            $nice, $ionice, escapeshellarg(Remux::ffmpegBin()), $inputThreads, $ffArgs
        );
        // setsid -> own process group so cancel can stop ffmpeg as well as the wrapper.
        $cmd = sprintf(
            'nohup setsid sh -c %s sh %s %s %s %s %s > %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($inner),
            escapeshellarg((string)$job['src']),
            escapeshellarg($job['part']),
            escapeshellarg($job['dest']),
            escapeshellarg($job['exit_file']),
            escapeshellarg($job['progress_file']),
            escapeshellarg((string)$job['log'])
        );
        $pid = PHP_OS_FAMILY === 'Windows' ? Process::windowsConversion($job, $ffArgs, $threads) : trim((string)shell_exec($cmd));
        if ((int)$pid <= 0) { $job['status'] = 'failed'; $job['message'] = 'Could not start FFmpeg. Check server process permissions.'; return $job; }
        $job['status'] = 'running';
        $job['started_at'] = date('c');
        $job['pid'] = (int)$pid;
        $job['message'] = 'Running pid ' . $pid . ' · ' . $label . ' · ' . $threads . ($threads === 1 ? ' thread' : ' threads') . ' · full resolution';
        $job['crf'] = $crf;
        $job['preset'] = $preset;
        $job['quality_mode'] = $mode;
        $job['strategy'] = $strategy;
        return $job;
    }

    private static function pidAlive(int $pid): bool {
        if ($pid <= 0) return false;
        if (PHP_OS_FAMILY === 'Windows') return trim((string)Process::powershell('if (Get-Process -Id ' . $pid . ' -ErrorAction SilentlyContinue) { "alive" }')) === 'alive';
        return file_exists("/proc/$pid");
    }

    public static function cancel(string $jobId): array {
        $queueLock = self::editQueueLock();
        try {
        $q = self::queue();
        $cleaned = [];
        foreach ($q as &$job) {
            if (($job['id'] ?? '') !== $jobId) continue;
            if (($job['status'] ?? '') === 'running' && !empty($job['pid'])) {
                $pid = (int)$job['pid'];
                if ($pid > 1) {
                    if (PHP_OS_FAMILY === 'Windows') {
                        Process::capture(['taskkill.exe','/PID',(string)$pid,'/T','/F'], 10);
                    } else {
                    @shell_exec('kill -TERM -- -' . $pid . ' 2>/dev/null; pkill -TERM -P ' . $pid . ' 2>/dev/null; kill -TERM ' . $pid . ' 2>/dev/null');
                    usleep(400000);
                    if (self::pidAlive($pid)) {
                        @shell_exec('kill -KILL -- -' . $pid . ' 2>/dev/null; pkill -KILL -P ' . $pid . ' 2>/dev/null; kill -KILL ' . $pid . ' 2>/dev/null');
                        usleep(200000);
                    }
                    }
                }
            }
            $cleaned = self::cleanupPartialFiles($job);
            if (!in_array($job['status'] ?? '', ['queued', 'running'], true)) continue;
            $job['status'] = 'cancelled';
            $job['finished_at'] = date('c');
            $job['progress'] = 0;
            $job['message'] = 'Cancelled' . ($cleaned ? (' · cleaned ' . implode(', ', $cleaned)) : '');
        }
        unset($job);
        self::saveQueue($q);
        return ['ok' => true, 'cleaned' => $cleaned];
        } finally { self::unlockQueue($queueLock); }
    }

    /** Remove .part / progress / exit leftovers for a job. */
    private static function cleanupPartialFiles(array $job): array {
        $cleaned = [];
        $paths = [];
        foreach (['part', 'progress_file', 'exit_file'] as $k) {
            $p = (string)($job[$k] ?? '');
            if ($p !== '') $paths[] = $p;
        }
        $dest = (string)($job['dest'] ?? '');
        if ($dest !== '') {
            $paths[] = dirname($dest) . '/' . '.' . basename($dest) . '.part';
        }
        foreach (array_unique($paths) as $p) {
            if ($p !== '' && is_file($p)) {
                if (@unlink($p)) $cleaned[] = basename($p);
            }
        }
        return $cleaned;
    }

    private static function findJob(array $q, string $jobId): int {
        foreach ($q as $i => $job) {
            if (($job['id'] ?? '') === $jobId) return (int)$i;
        }
        return -1;
    }

    /** True when $path is inside one of the configured library section roots. */
    private static function underLibraryRoot(string $path): bool {
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            $root = (string)($sec['path'] ?? '');
            if ($root === '') continue;
            $rr = \Vaultcast\Paths::real($root);
            if ($rr === false) continue;
            if (strpos($path, $rr . '/') === 0) return true;
        }
        return false;
    }

    /** ffprobe container duration in seconds (null when unknown). */
    private static function probeDuration(string $path): ?float {
        return MediaProbe::duration($path, 8);
    }

    /** Mark TV convert requests linked to this job/item as done. Returns count. */
    private static function resolveLinkedRequests(array $job, string $message, ?string $newItemId = null): int {
        $reqs = self::requests();
        $n = 0;
        $itemIds = array_filter([(string)($job['item_id'] ?? ''), (string)($job['dest_item_id'] ?? '')]);
        foreach ($reqs as &$r) {
            if (($r['status'] ?? '') !== 'pending') continue;
            $match = in_array((string)($r['item_id'] ?? ''), $itemIds, true)
                || (($r['convert_job_id'] ?? '') !== '' && ($r['convert_job_id'] ?? '') === ($job['id'] ?? ''));
            if (!$match) continue;
            $r['status'] = 'done';
            $r['message'] = $message;
            $r['updated_at'] = date('c');
            if ($newItemId) $r['resolved_item_id'] = $newItemId;
            $n++;
        }
        unset($r);
        if ($n) self::saveRequests($reqs);
        return $n;
    }

    /**
     * Completed job: keep both files, just mark the job + linked requests done.
     */
    public static function resolveJob(string $jobId): array {
        $queueLock = self::editQueueLock();
        try {
        $q = self::queue();
        $i = self::findJob($q, $jobId);
        if ($i < 0) json_error('Job not found', 404);
        $job = $q[$i];
        if (($job['status'] ?? '') !== 'done') json_error('Only completed jobs can be marked done', 400);
        $n = self::resolveLinkedRequests($job, 'Done - converted (original kept)', $job['dest_item_id'] ?? null);
        $q[$i]['resolved'] = true;
        $q[$i]['resolved_at'] = date('c');
        $q[$i]['resolution'] = 'kept_original';
        $q[$i]['message'] = 'Done - original kept';
        self::saveQueue($q);
        self::audit('resolve keep job=' . $jobId . ' requests=' . $n);
        return ['ok' => true, 'job' => $q[$i], 'requests_resolved' => $n];
        } finally { self::unlockQueue($queueLock); }
    }

    public static function automaticPlaybackBlocked(string $itemId): bool {
        foreach(array_reverse(self::queue()) as $job) {
            if(($job['item_id'] ?? '')!==$itemId)continue;
            if(!empty($job['dest_deleted']))return true;
            if(($job['status'] ?? '')==='done')return false;
        }
        return false;
    }
    /** The item's queued or running library conversion (same cached queue read as automaticPlaybackBlocked). */
    public static function activeLibraryConversion(string $itemId): ?array {
        foreach(self::queue() as $job) {
            if(($job['item_id'] ?? '')===$itemId && in_array($job['status'] ?? '',['queued','running'],true))
                return ['status'=>(string)$job['status'],'progress'=>max(0,min(100,(float)($job['progress'] ?? 0)))];
        }
        return null;
    }
    public static function preview(string $jobId, bool $original = false): array {
        $queue=self::queue();$index=self::findJob($queue,$jobId);
        if($index<0)throw new RuntimeException('Conversion not found.');
        $job=$queue[$index];
        if($original) {
            // Compare with the source: the original file, served directly (no transcode).
            $path=(string)($job['src'] ?? '');
            if(!empty($job['original_removed'])||$path===''||!is_file($path))throw new RuntimeException('The original file is no longer available.');
            $real=\Vaultcast\Paths::real($path);
            if(!$real||!self::underLibraryRoot($real))throw new RuntimeException('The original file is outside the library.');
            $user=Auth::requireAdmin();
            $token=Stream::createToken((string)$user['id'],(string)$job['item_id'],$real,'video','library');
            return ['ok'=>true,'title'=>$job['title'] ?? 'Original','url'=>\Vaultcast\Database::basePath().'api/stream/play?t='.$token,'original'=>true];
        }
        $path=(string)($job['dest'] ?? '');
        if(($job['status'] ?? '')!=='done'||!empty($job['dest_deleted'])||$path===''||!is_file($path))throw new RuntimeException('The converted file is unavailable. The original will not be used for this preview.');
        $real=\Vaultcast\Paths::real($path);
        if(!$real||!self::underLibraryRoot($real))throw new RuntimeException('The converted file is outside the library.');
        $user=Auth::requireAdmin();
        $token=Stream::createToken((string)$user['id'],(string)$job['item_id'],$real,'video','library');
        return ['ok'=>true,'title'=>$job['title'] ?? 'Converted preview','url'=>\Vaultcast\Database::basePath().'api/stream/play?t='.$token];
    }

    /**
     * Completed job: delete the converted output (bad quality), keep the original.
     * Removes dest from disk + catalogue; marks job resolved as deleted_conversion.
     */
    public static function deleteConverted(string $jobId): array {
        $queueLock = self::editQueueLock();
        try {
        $q = self::queue();
        $i = self::findJob($q, $jobId);
        if ($i < 0) json_error('Job not found', 404);
        $job = $q[$i];
        if (($job['status'] ?? '') !== 'done') json_error('Only completed converts can delete the output', 400);
        if (!empty($job['original_removed'])) {
            json_error('Original was already replaced - cannot delete the only remaining file this way', 409);
        }
        if(!is_file((string)($job['src'] ?? '')))json_error('The original is unavailable. Keep the remaining converted copy.',409);

        $dest = (string)($job['dest'] ?? '');
        $destReal = $dest !== '' ? \Vaultcast\Paths::real($dest) : false;
        $deletedPath = null;
        $removedIds = [];

        if ($destReal !== false && is_file($destReal)) {
            if (!self::underLibraryRoot($destReal)) json_error('Converted file is outside the library roots', 400);
            if (!@unlink($destReal)) {
                $err = error_get_last();
                json_error('Could not delete converted file: ' . ($err['message'] ?? 'permission denied'), 500);
            }
            clearstatcache(true, $destReal);
            $deletedPath = $destReal;
        }
        // Also clear stale catalogue links when the copy was already deleted on disk.
        // Targeted updates only: the original item's link and a stray copy entry, never a full catalogue save.
        if($dest!=='') {
            $profiles=Store::get('directplay_profiles',[]);
            unset($profiles[str_replace('\\','/',$dest)]);
            if($destReal!==false)unset($profiles[str_replace('\\','/',$destReal)]);
            Store::set('directplay_profiles',$profiles);
            Catalogue::detachDirectPlay((string)($job['item_id'] ?? ''),[$dest,$destReal!==false?$destReal:'',$deletedPath ?? '']);
        }

        $destItemId = (string)($job['dest_item_id'] ?? '');
        if ($destItemId !== '' && $destItemId !== (string)($job['item_id'] ?? '') && !in_array($destItemId, $removedIds, true)) {
            foreach([$dest,$destReal!==false?$destReal:'',$deletedPath ?? ''] as $p)if($p!==''&&\Vaultcast\Repository::deleteItemForPath($destItemId,$p)){$removedIds[]=$destItemId;break;}
        }
        $removedIds = array_values(array_unique(array_filter($removedIds)));

        self::cleanupPartialFiles($job);

        $n = self::resolveLinkedRequests($job, 'Convert discarded - original kept', null);

        $q = self::queue();
        $i = self::findJob($q, $jobId);
        if ($i >= 0) {
            $q[$i]['resolved'] = true;
            $q[$i]['resolved_at'] = date('c');
            $q[$i]['resolution'] = 'deleted_conversion';
            $q[$i]['dest_deleted'] = true;
            $q[$i]['dest_item_id'] = null;
            $q[$i]['message'] = 'Deleted conversion - original kept';
            self::saveQueue($q);
            $job = $q[$i];
        }
        self::audit('delete-converted job=' . $jobId . ' dest=' . ($deletedPath ?: $dest) . ' removed_ids=' . implode(',', $removedIds) . ' requests=' . $n);
        return [
            'ok' => true,
            'job' => $job,
            'deleted' => $deletedPath,
            'removed_item_ids' => $removedIds,
            'requests_resolved' => $n,
        ];
        } finally { self::unlockQueue($queueLock); }
    }

    /** Replace a source with its converted bytes while retaining the library identity. */
    public static function removeOriginal(string $jobId): array {
        $queueLock=self::editQueueLock();
        try {
            $q=self::queue(); $i=self::findJob($q,$jobId);
            if ($i<0) json_error('Job not found',404);
            $job=$q[$i];
            if (($job['status'] ?? '')!=='done') json_error('Convert has not completed successfully',400);
            $src=str_replace('\\','/',(string)($job['src'] ?? ''));
            $dest=str_replace('\\','/',(string)($job['dest'] ?? ''));
            if (!empty($job['original_removed']) && !preg_match('/\.directplay\.[^.]+$/i',$dest)) return ['ok'=>true,'job'=>$job,'message'=>'Original already replaced'];
            $destReal=$dest!=='' ? \Vaultcast\Paths::real($dest) : false;
            if (!$destReal || !is_file($destReal)) json_error('Converted file not found: '.$dest,409);
            if (!self::underLibraryRoot($destReal)) json_error('Converted file is outside the library roots',400);
            if (filesize($destReal)<1000) json_error('Converted file looks incomplete; original kept',409);
            $parent=\Vaultcast\Paths::real(dirname($src));
            if (!$parent || $parent!==dirname($destReal)) json_error('Converted file must be beside the original',409);
            $extension=strtolower(pathinfo($destReal,PATHINFO_EXTENSION));
            if (!isset(self::CONTAINERS[$extension])) json_error('Unsupported converted container',409);
            $target=$parent.'/'.pathinfo($src,PATHINFO_FILENAME).'.'.$extension;
            $srcReal=\Vaultcast\Paths::real($src); $srcExists=$srcReal!==false && is_file($srcReal);
            if ($srcExists && $srcReal===$destReal) json_error('Source and converted file are the same path',409);
            if ($srcExists && isset($job['source_stat']) && ((int)filesize($srcReal)!==$job['source_stat']['size'] || (int)filemtime($srcReal)!==$job['source_stat']['mtime'])) json_error('The original changed after conversion; it was kept',409);
            if (file_exists($target) && (!$srcExists || \Vaultcast\Paths::real($target)!==$srcReal)) json_error('Replacement filename already exists; no files were changed: '.$target,409);
            if (!is_writable($parent)) json_error('Folder is not writable: '.$parent,403);
            $dDest=self::probeDuration($destReal);
            if ($dDest===null) json_error('Could not verify converted duration; original kept',409);
            $dSrc=$srcExists ? self::probeDuration($srcReal) : (float)($job['duration_s'] ?? 0);
            if ($dSrc && $dDest<$dSrc-max(10.0,$dSrc*0.03)) json_error('Converted file is shorter than the original; original kept',409);

            Store::invalidate('catalogue');
            $cat=Catalogue::get(); $oldId=(string)($job['item_id'] ?? '');
            $oldItem=$cat['items'][$oldId] ?? null;
            $normal=static function(string $path): string { $path=str_replace('\\','/',$path); return PHP_OS_FAMILY==='Windows' ? strtolower($path) : $path; };
            $paths=array_map($normal,array_values(array_unique([$src,$dest,$destReal,$target])));
            if ($oldItem && !in_array($normal((string)($oldItem['path'] ?? '')),$paths,true)) json_error('The library item has changed; no files were replaced',409);
            if (!$oldItem) foreach ($cat['items'] ?? [] as $item) if (in_array($normal((string)($item['path'] ?? '')),$paths,true)) { $oldItem=$item; break; }
            if (!$oldItem || $oldId==='') json_error('Library item not found; no files were replaced',409);
            $section=(string)($oldItem['section'] ?? $job['section'] ?? ''); $root=false;
            foreach (Store::settings()['sections'] ?? [] as $sec) if (($sec['id'] ?? '')===$section) { $root=\Vaultcast\Paths::real((string)$sec['path']); break; }
            if (!$root || strpos($target,$root.'/')!==0) json_error('Replacement is outside its library section',409);
            $profile=self::copyProfile($destReal);
            $replacement=$oldItem;
            $replacement['id']=$oldId; $replacement['path']=$target;
            $replacement['rel']=substr($target,strlen($root)+1); $replacement['ext']=$extension;
            $replacement['size']=(int)filesize($destReal); $replacement['mtime']=(int)filemtime($destReal);
            $replacement['duration']=$dDest; $replacement['caption_source']=$src;
            unset($replacement['directplay_path'],$replacement['directplay_versions'],$replacement['directplay_ready'],$replacement['prepared_copy'],$replacement['video_codec'],$replacement['audio_codec']);
            $oldIds=[];
            foreach ($cat['items'] ?? [] as $id=>$item) if ((string)$id!==$oldId && in_array($normal((string)($item['path'] ?? '')),$paths,true)) { unset($cat['items'][$id]); $oldIds[]=(string)$id; }
            $cat['items'][$oldId]=$replacement;
            $counts=[]; $shows=[];
            foreach ($cat['items'] as $item) { $sid=(string)$item['section']; $counts[$sid]=($counts[$sid] ?? 0)+1; if (!empty($item['show'])) $shows[$sid][$item['show']]=true; }
            foreach ($cat['sections'] as &$sec) { $sid=(string)$sec['id']; $sec['count']=$counts[$sid] ?? 0; if (in_array($sec['type'] ?? '',['tv','anime'],true)) $sec['show_count']=count($shows[$sid] ?? []); }
            unset($sec); $cat['item_count']=count($cat['items']); $cat['updated_at']=date('c');
            $profiles=Store::get('directplay_profiles',[]); unset($profiles[$destReal]);
            $profiles[$target]=array_replace($profile,['canonical_id'=>$oldId,'original_removed'=>true,'source'=>$src]);
            $job['dest']=$target; $job['dest_item_id']=$oldId; $job['original_removed']=true;
            $job['original_removed_at']=date('c'); $job['resolved']=true; $job['resolved_at']=date('c');
            $job['resolution']='original_removed'; $job['message']='Done - original replaced by '.basename($target);
            $q[$i]=$job;

            // Preserve rollback bytes until the database changes have committed.
            $backup=$parent.'/.vaultcast-replace-'.$jobId.'-'.bin2hex(random_bytes(4)).'.bak';
            if ($srcExists && !@rename($srcReal,$backup)) json_error('Could not move the original aside; no files were replaced',500);
            if (!@rename($destReal,$target)) {
                if ($srcExists && !@rename($backup,$srcReal)) throw new RuntimeException('Replacement failed; original remains at '.$backup);
                json_error('Could not rename the converted file; original restored',500);
            }
            try {
                $result=\Vaultcast\Database::transaction(static function () use ($cat,$profiles,$oldIds,$oldId,$job,$q) {
                    if (!Store::set('directplay_profiles',$profiles)) throw new RuntimeException('Could not save replacement identity');
                    if (!Catalogue::save($cat,false)) throw new RuntimeException('Could not save replacement catalogue entry');
                    $saved=Catalogue::item($oldId);
                    if (!$saved || ($saved['path'] ?? '')!==$job['dest']) throw new RuntimeException('Replacement library entry was not retained');
                    $prog=self::migrateProgress($oldIds,$oldId); $cols=self::migrateCollections($oldIds,$oldId);
                    $n=self::resolveLinkedRequests($job,'Done - original replaced',$oldId);
                    if (!self::saveQueue($q)) throw new RuntimeException('Could not save replacement job');
                    return ['progress_migrated'=>$prog,'collection_links_migrated'=>$cols,'requests_resolved'=>$n];
                });
            } catch (\Throwable $error) {
                $restored=@rename($target,$destReal);
                if ($srcExists) $restored=@rename($backup,$srcReal) && $restored;
                Store::invalidate();
                if (!$restored) throw new RuntimeException('Database update failed; check replacement files at '.$target.' and '.$backup,0,$error);
                throw $error;
            }
            Store::invalidate('catalogue');
            $warning=null;
            if ($srcExists && !@unlink($backup)) $warning='Replaced successfully; the old file could not be removed from '.$backup;
            self::audit('replace job='.$jobId.' src='.$src.' target='.$target.' id='.$oldId.' aliases='.implode(',',$oldIds));
            return ['ok'=>true,'job'=>$job,'deleted'=>$srcExists ? $src : null,'kept'=>$target,'new_item_id'=>$oldId,'removed_item_ids'=>$oldIds,'warning'=>$warning]+$result;
        } finally { self::unlockQueue($queueLock); }
    }

    /** Move per-user watch progress from old item ids to the new one. */
    private static function migrateProgress(array $oldIds, string $newId): int {
        return \Vaultcast\Repository::migrateProgress($oldIds, $newId);
    }

    /** Repoint collection part links (auto or manual) from old item ids to the new one. */
    public static function migrateCollections(array $oldIds, string $newId): int {
        if (!$oldIds || !class_exists('Collections')) return 0;
        $list = Collections::all();
        $n = 0;
        foreach ($list as &$c) {
            if (!isset($c['parts']) || !is_array($c['parts'])) continue;
            foreach ($c['parts'] as &$part) {
                if (is_array($part) && in_array((string)($part['item_id'] ?? ''), $oldIds, true)) {
                    $part['item_id'] = $newId;
                    $n++;
                }
            }
            unset($part);
        }
        unset($c);
        if ($n) Collections::save($list);
        return $n;
    }

    /** A full watch-and-prepare encode joins the existing review/replacement workflow. */
    public static function adoptCompleted(string $itemId,string $src,string $dest,string $section,string $mode='both'): array {
        // Runs in the CLI worker: wait for a busy queue rather than leaving a published copy out of the review list.
        $lock=false;for($try=0;$try<60&&$lock===false;$try++){$lock=self::lockQueue();if($lock===false)usleep(500000);}
        if($lock===false)throw new RuntimeException('Conversion queue is busy. The saved copy is safe beside the original; rescan to import it.');
        try {
            Store::invalidate('convert_queue');$q=self::queue();
            $job=['id'=>bin2hex(random_bytes(8)),'item_id'=>$itemId,'title'=>Catalogue::item($itemId)['title'] ?? basename($src),
                'src'=>$src,'dest'=>$dest,'section'=>$section,'status'=>'done','progress'=>100,'created_at'=>date('c'),
                'finished_at'=>date('c'),'message'=>'Watch & save copy complete ('.(Transcode::SAVE_MODE_LABELS[$mode] ?? 'Video and audio').' converted); selected audio track saved. Original retained.','container'=>'mp4',
                'origin'=>'watch_save','save_mode'=>in_array($mode,Transcode::SAVE_MODES,true)?$mode:'both',
                'source_stat'=>['size'=>(int)filesize($src),'mtime'=>(int)filemtime($src)]];
            $job=self::onComplete($job);$q[]=$job;self::saveQueue($q);return ['job_id'=>$job['id']];
        } finally {self::unlockQueue($lock);}
    }


    /**
     * Parse ffmpeg -progress file (and part-size fallback) into 0..99.5 percent.
     * Never returns 100 while the job is still running - complete only on process exit.
     *
     * Note: ffmpeg's out_time_ms is microseconds despite the name (legacy quirk).
     * Prefer out_time_us / out_time (HH:MM:SS) when present.
     */
    private static function readJobProgress(array $job): ?float {
        $dur = isset($job['duration_s']) ? (float)$job['duration_s'] : 0.0;
        $pf = (string)($job['progress_file'] ?? '');
        $secs = null;
        if ($pf !== '' && is_file($pf)) {
            $txt = (string)@file_get_contents($pf);
            if ($txt !== '') {
                if (preg_match_all('/^out_time=(\d+):(\d+):(\d+(?:\.\d+)?)$/m', $txt, $mm2) && !empty($mm2[1])) {
                    $i = count($mm2[1]) - 1;
                    $secs = ((int)$mm2[1][$i]) * 3600 + ((int)$mm2[2][$i]) * 60 + (float)$mm2[3][$i];
                }
                if ($secs === null && preg_match_all('/^out_time_us=(\d+)$/m', $txt, $mmu) && !empty($mmu[1])) {
                    $secs = ((float)end($mmu[1])) / 1000000.0;
                }
                if ($secs === null && preg_match_all('/^out_time_ms=(\d+)$/m', $txt, $mm) && !empty($mm[1])) {
                    $raw = (float)end($mm[1]);
                    $secs = $raw / 1000000.0;
                }
            }
        }
        if ($secs !== null && $dur > 0) {
            return min(99.5, round(($secs / $dur) * 100.0, 1));
        }
        $part = (string)($job['part'] ?? '');
        $srcPath = (string)($job['src'] ?? '');
        if ($part !== '' && is_file($part) && $srcPath !== '' && is_file($srcPath)) {
            $ss = (float)@filesize($srcPath);
            $ps = (float)@filesize($part);
            if ($ss > 0) return min(95.0, round($ps / $ss * 100.0, 1));
        }
        $prev = isset($job['progress']) ? (float)$job['progress'] : null;
        if ($prev === null) return null;
        return min(99.5, $prev);
    }

    private static function progressMessage(array $job, float $pct): string {
        $base = (string)($job['message'] ?? '');
        // Strip previous " · N%" suffix
        $base = preg_replace('/\s*·\s*\d+(?:\.\d+)?%\s*$/', '', $base) ?? $base;
        $label = ($job['strategy'] ?? '') !== '' ? (string)$job['strategy'] : 'converting';
        if ($base === '' || strpos($base, 'Running') === 0) {
            return 'Running' . (!empty($job['pid']) ? (' pid ' . $job['pid']) : '') . ' · ' . $label . ' · ' . $pct . '%';
        }
        return rtrim($base) . ' · ' . $pct . '%';
    }

    /** Remove a cancelled/failed/resolved job from the queue and clean partials. */
    public static function discard(string $jobId): array {
        $queueLock = self::editQueueLock();
        try {
        $q = self::queue();
        $cleaned = [];
        $kept = [];
        $found = false;
        foreach ($q as $job) {
            if (($job['id'] ?? '') !== $jobId) {
                $kept[] = $job;
                continue;
            }
            $found = true;
            $st = (string)($job['status'] ?? '');
            if (in_array($st, ['queued', 'running'], true)) {
                return ['ok' => false, 'error' => 'Cancel the job before removing it from the queue'];
            }
            $cleaned = self::cleanupPartialFiles($job);
            // Also drop leftover incomplete dest if cancel left one
            $dest = (string)($job['dest'] ?? '');
            if ($st === 'cancelled' && $dest !== '' && is_file($dest)) {
                $sz = (int)@filesize($dest);
                // only remove tiny/incomplete leftovers; never touch a finished conversion
                if ($sz >= 0 && $sz < 65536) {
                    if (@unlink($dest)) $cleaned[] = basename($dest);
                }
            }
        }
        if (!$found) {
            return ['ok' => false, 'error' => 'Job not found'];
        }
        self::saveQueue(array_values($kept));
        return ['ok' => true, 'cleaned' => $cleaned, 'queue' => array_values($kept)];
        } finally { self::unlockQueue($queueLock); }
    }

    public static function clearFinished(): array {
        $queueLock = self::editQueueLock();
        try {
        $q = array_values(array_filter(self::queue(), function ($job) {
            return in_array($job['status'] ?? '', ['queued', 'running'], true);
        }));
        self::saveQueue($q);
        return ['ok' => true, 'queue' => $q];
        } finally { self::unlockQueue($queueLock); }
    }

    public static function externalClaim(array $worker): array {
        $lock=self::editQueueLock();
        try {
            $cv=array_merge(self::defaults(), Store::settings()['convert'] ?? []);
            if (empty($cv['enabled'])) return ['ok'=>true,'job'=>null];
            // Reap expired leases and collect any completed local process before claiming.
            self::tick(false);
            $q=self::queue();
            foreach ($q as $job) if (($job['status'] ?? '')==='running' && ($job['external_worker_id'] ?? '')===$worker['id']) return ['ok'=>true,'job'=>null];
            foreach ($q as &$job) {
                if (($job['status'] ?? '')!=='queued') continue;
                $src=\Vaultcast\Paths::real((string)$job['src']);
                if (!$src || !self::underLibraryRoot($src) || !is_file($src)) {
                    $job['status']='failed'; $job['message']='Source file is unavailable in the library'; continue;
                }
                $dest=self::destFor($src,'mp4');
                if (is_file($dest)) { $job['status']='failed'; $job['message']='A DirectPlay output already exists; it was not overwritten'; continue; }
                $quality=self::applyQualityMode($cv);
                $job['dest']=$dest; $job['container']='mp4'; $job['pid']=null;
                $job['part']=dirname($dest).'/.'.basename($dest).'.worker-'.$job['id'].'.part';
                @unlink($job['part']);
                $job['status']='running'; $job['external_worker_id']=$worker['id'];
                $job['lease_token']=bin2hex(random_bytes(16)); $job['lease_until']=time()+300;
                $job['started_at']=date('c'); $job['finished_at']=null; $job['progress']=0;
                $job['message']='Contributor '.$worker['name'].' · downloading source';
                $job['source_stat']=['size'=>(int)filesize($src),'mtime'=>(int)filemtime($src)];
                $sourceProbe=MediaProbe::probeSummary($src,8,true);
                $job['duration_s']=$sourceProbe['duration'];
                $job['source_audio_count']=!empty($sourceProbe['probed']) ? (int)$sourceProbe['audio_count'] : null;
                $job['quality_mode']=$quality['quality_mode'];
                self::saveQueue($q);
                return ['ok'=>true,'job'=>['id'=>$job['id'],'title'=>$job['title'],'lease'=>$job['lease_token'],
                    'bytes'=>$job['source_stat']['size'],'duration'=>$job['duration_s'],
                    'upload_chunk_bytes'=>4194304,
                    'crf'=>max(0,min(28,(int)($quality['crf'] ?? 18))),
                    'preset'=>self::sanitizePreset((string)($quality['preset'] ?? 'medium')),
                    'audio_bitrate'=>in_array($quality['audio_bitrate'] ?? '',['96k','128k','160k','192k','256k','320k'],true) ? $quality['audio_bitrate'] : '320k',
                    'smart'=>in_array($quality['quality_mode'],['directplay','original'],true)]];
            }
            unset($job); self::saveQueue($q);
            return ['ok'=>true,'job'=>null];
        } finally { self::unlockQueue($lock); }
    }

    private static function externalJob(array $q, array $worker, string $id, string $lease): int {
        $i=self::findJob($q,$id); $job=$i>=0 ? $q[$i] : [];
        if (($job['status'] ?? '')!=='running' || ($job['external_worker_id'] ?? '')!==$worker['id'] ||
            $lease==='' || !hash_equals((string)($job['lease_token'] ?? ''),$lease) || (int)($job['lease_until'] ?? 0)<time())
            json_error('This conversion is no longer assigned to this worker.',409);
        return $i;
    }

    public static function externalSource(array $worker, string $id, string $lease): void {
        $lock=self::editQueueLock();
        try {
            $q=self::queue(); $i=self::externalJob($q,$worker,$id,$lease); $job=$q[$i];
            $src=\Vaultcast\Paths::real((string)$job['src']);
            if (!$src || !self::underLibraryRoot($src) || !is_file($src)) json_error('Source is unavailable.',404);
            $q[$i]['lease_until']=time()+300; self::saveQueue($q);
        } finally { self::unlockQueue($lock); }
        @set_time_limit(0);
        header('Cache-Control: no-store');
        Stream::sendFile($src,'application/octet-stream'); exit;
    }

    public static function externalUpdate(array $worker, string $id, string $lease, string $action, array $body=[]): array {
        $lock=self::editQueueLock();
        try {
            $q=self::queue();
            $done=self::findJob($q,$id);
            if ($action==='complete' && $done>=0 && ($q[$done]['status'] ?? '')==='done' &&
                ($q[$done]['external_worker_id'] ?? '')===$worker['id'] && $lease!=='' && hash_equals((string)($q[$done]['lease_token'] ?? ''),$lease)) return ['ok'=>true];
            $i=self::externalJob($q,$worker,$id,$lease); $job=&$q[$i];
            $renewLease=(int)($job['lease_until'] ?? 0)<time()+240;
            $job['lease_until']=time()+300;
            if ($action==='progress') {
                $job['progress']=max(0,min(99.5,(float)($body['progress'] ?? 0)));
                $phase=(string)($body['phase'] ?? '');
                if (in_array($phase,['download','encode','hash','upload','verify'],true)) {
                    $job['phase']=$phase;
                    $job['phase_progress']=max(0,min(100,(float)($body['phase_progress'] ?? 0)));
                    $job['transfer_bytes']=max(0,(int)($body['bytes_done'] ?? 0));
                    $job['transfer_total']=max(0,(int)($body['bytes_total'] ?? 0));
                    $job['transfer_rate']=max(0,(float)($body['rate_bps'] ?? 0));
                    $job['transfer_eta']=max(0,min(604800,(int)($body['eta_seconds'] ?? 0)));
                    $job['progress_updated_at']=time();
                }
                $job['message']='Contributor '.$worker['name'].' · '.mb_substr((string)($body['stage'] ?? 'encoding'),0,80).' · '.$job['progress'].'%';
            } elseif ($action==='chunk') {
                // Raw bounded chunks bypass PHP multipart limits and keep RAM independent of film size.
                $offset=filter_var($_SERVER['HTTP_X_UPLOAD_OFFSET'] ?? '',FILTER_VALIDATE_INT);
                if ($offset===false || $offset<0) json_error('Invalid upload offset.',400);
                $input=fopen('php://input','rb'); $chunk=stream_get_contents($input,4194305); fclose($input);
                if ($chunk===false || strlen($chunk)<1 || strlen($chunk)>4194304) json_error('Send a chunk of at most 4 MiB.',413);
                $part=(string)$job['part']; clearstatcache(true,$part); $size=is_file($part) ? (int)filesize($part) : 0;
                if ($offset!==$size) {
                    if ($offset+strlen($chunk)!==$size) json_error('Upload offset does not match.',409);
                    $file=fopen($part,'rb'); fseek($file,$offset); $previous=fread($file,strlen($chunk)); fclose($file);
                    if (!hash_equals(hash('sha256',$previous),hash('sha256',$chunk))) json_error('Upload chunk does not match.',409);
                } else {
                    $max=max(1073741824,(int)($job['source_stat']['size'] ?? 0)*8);
                    if ($size+strlen($chunk)>$max) json_error('Converted file exceeds this job upload limit.',413);
                    $free=@disk_free_space(dirname($part));
                    if ($free!==false && $free<strlen($chunk)+16777216) json_error('Server output disk is full.',507);
                    if (file_put_contents($part,$chunk,FILE_APPEND)!==strlen($chunk)) throw new RuntimeException('Could not save output chunk.');
                    $size+=strlen($chunk);
                }
                // Progress heartbeats persist the display separately; avoid a full queue write per chunk.
                if ($renewLease) self::saveQueue($q);
                return ['ok'=>true,'received'=>$size];
            } elseif ($action==='complete') {
                @set_time_limit(0);
                $part=(string)$job['part']; clearstatcache(true,$part);
                $bytes=(int)($body['bytes'] ?? 0); $sha=(string)($body['sha256'] ?? '');
                if (!is_file($part) || $bytes<1000 || filesize($part)!==$bytes || !preg_match('/^[a-f0-9]{64}$/D',$sha) || !hash_equals($sha,hash_file('sha256',$part))) json_error('Converted upload is incomplete or corrupt.',422);
                $probe=MediaProbe::probeSummary($part,20,false);
                $file=fopen($part,'rb'); $signature=fread($file,12); fclose($file);
                if (substr($signature,4,4)!=='ftyp') json_error('Output must use the MP4 container.',422);
                if (empty($probe['probed']) || ($probe['video_codec'] ?? '')!=='h264' || ($probe['pixel_format'] ?? '')!=='yuv420p') json_error('Output must contain H.264 8-bit video.',422);
                foreach ($probe['audio_tracks'] ?? [] as $track) if (($track['codec'] ?? '')!=='aac') json_error('Output audio must be AAC.',422);
                if (isset($job['source_audio_count']) && (int)$probe['audio_count']!==$job['source_audio_count']) json_error('Converted audio tracks do not match the source.',422);
                $duration=(float)($job['duration_s'] ?? 0); $actual=(float)($probe['duration'] ?? 0);
                if ($actual<=0 || ($duration>0 && abs($actual-$duration)>max(3,$duration*0.01))) json_error('Converted video duration does not match the source.',422);
                $src=(string)$job['src']; clearstatcache(true,$src);
                if (!is_file($src) || filesize($src)!==$job['source_stat']['size'] || filemtime($src)!==$job['source_stat']['mtime']) json_error('The original changed; it was kept. Cancel and queue a fresh conversion.',409);
                if (is_file($job['dest'])) json_error('Output already exists; it was not overwritten.',409);
                if (!rename($part,$job['dest'])) throw new RuntimeException('Could not publish converted file.');
                $job['status']='done'; $job['progress']=100; $job['finished_at']=date('c');
                $job['message']='Completed by '.$worker['name'].' — saved beside original';
                $job=self::onComplete($job);
            } elseif ($action==='fail') {
                self::cleanupPartialFiles($job);
                $job['status']='failed'; $job['finished_at']=date('c');
                $job['message']='Contributor: '.mb_substr((string)($body['error'] ?? 'Conversion failed'),0,300);
            } else json_error('Unknown conversion action.',404);
            \Vaultcast\Database::transaction(static function () use ($q,$action,$worker,$id) {
                if ($action==='complete') ContributionPoints::rewardConversion((string)$worker['user_id'],$id);
                if (!self::saveQueue($q)) throw new RuntimeException('Could not save conversion update.');
            });
            return ['ok'=>true];
        } finally { self::unlockQueue($lock); }
    }

    /* ---- Convert requests (TV / non-admin notify admin) ---- */

    public static function requests(): array {
        $q = Store::get('convert_requests', []);
        return is_array($q) ? $q : [];
    }

    public static function saveRequests(array $q): bool {
        return Store::set('convert_requests', $q);
    }

    public static function createRequest(string $itemId, array $opts = []): array {
        $itemId = trim($itemId);
        if ($itemId === '') json_error('item_id required');
        $item = Catalogue::item($itemId);
        if (!$item) json_error('Item not found', 404);

        $requests = self::requests();
        foreach ($requests as $r) {
            if (($r['item_id'] ?? '') === $itemId && ($r['status'] ?? '') === 'pending') {
                return [
                    'ok' => true,
                    'entry' => $r,
                    'duplicate' => true,
                    'message' => 'Already requested ? admin will convert when ready',
                ];
            }
        }

        $linkedJob = null;
        foreach (self::queue() as $job) {
            if (($job['item_id'] ?? '') === $itemId && in_array($job['status'] ?? '', ['queued', 'running'], true)) {
                $linkedJob = $job['id'] ?? null;
                break;
            }
        }

        $reason = trim((string)($opts['reason'] ?? 'playback_failed'));
        if ($reason === '') $reason = 'playback_failed';
        $device = trim((string)($opts['device'] ?? 'TV'));
        if ($device === '') $device = 'TV';
        $by = trim((string)($opts['requested_by'] ?? 'guest'));

        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'item_id' => $itemId,
            'title' => (string)($item['title'] ?? ''),
            'path' => (string)($item['path'] ?? ''),
            'ext' => (string)($item['ext'] ?? ''),
            'format' => (string)($opts['format'] ?? ($item['ext'] ?? '')),
            'reason' => $reason,
            'device' => $device,
            'requested_by' => $by,
            'status' => 'pending',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'convert_job_id' => $linkedJob,
            'message' => '',
        ];
        $requests[] = $entry;
        self::saveRequests($requests);
        return [
            'ok' => true,
            'entry' => $entry,
            'duplicate' => false,
            'message' => $linkedJob
                ? 'Request noted ? convert already queued'
                : 'Convert request sent to admin',
        ];
    }

    public static function pendingRequests(): array {
        return array_values(array_filter(self::requests(), function ($r) {
            return ($r['status'] ?? '') === 'pending';
        }));
    }

    public static function resolveRequest(string $requestId, string $action): array {
        $requestId = trim($requestId);
        $action = strtolower(trim($action));
        if ($action === 'accept' || $action === 'confirm') $action = 'start';
        if (!in_array($action, ['done', 'dismiss', 'start', 'delete'], true)) {
            json_error('action must be done|dismiss|start|accept|confirm|delete');
        }
        $requests = self::requests();
        $found = null;
        $idx = -1;
        foreach ($requests as $i => $r) {
            if (($r['id'] ?? '') === $requestId) {
                $found = $r;
                $idx = $i;
                break;
            }
        }
        if ($idx < 0) json_error('Request not found', 404);

        if ($action === 'delete') {
            array_splice($requests, $idx, 1);
            self::saveRequests($requests);
            return ['ok' => true, 'deleted' => $requestId];
        }

        if ($action === 'start') {
            $enq = self::enqueue((string)($found['item_id'] ?? ''));
            $jobId = $enq['entry']['id'] ?? ($found['convert_job_id'] ?? null);
            $requests[$idx]['convert_job_id'] = $jobId;
            $requests[$idx]['updated_at'] = date('c');
            $requests[$idx]['message'] = $enq['message'] ?? 'Accepted - convert queued';
            if (($requests[$idx]['status'] ?? '') !== 'pending') {
                $requests[$idx]['status'] = 'pending';
            }
            self::saveRequests($requests);
            return ['ok' => true, 'entry' => $requests[$idx], 'convert' => $enq];
        }

        $requests[$idx]['status'] = $action === 'done' ? 'done' : 'dismissed';
        $requests[$idx]['updated_at'] = date('c');
        $requests[$idx]['message'] = $action === 'done' ? 'Marked done' : 'Dismissed';
        self::saveRequests($requests);
        return ['ok' => true, 'entry' => $requests[$idx]];
    }
}
