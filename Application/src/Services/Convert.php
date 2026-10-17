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
        return $dir . '/' . $base . '.directplay.' . $container;
    }

    private static function lockQueue() {
        $fh = @fopen(STREAM_DATA . '/convert_queue.lock', 'c');
        if ($fh && !@flock($fh, LOCK_EX | LOCK_NB)) { @fclose($fh); return false; }
        return $fh ?: null;
    }

    private static function unlockQueue($fh): void {
        if (is_resource($fh)) { @flock($fh, LOCK_UN); @fclose($fh); }
    }

    private static function audit(string $msg): void {
        @file_put_contents(STREAM_DATA . '/convert_actions.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function enqueue(string $itemId): array {
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
        $container = self::container();
        $dest = self::destFor($src, $container);
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

    public static function tick(): array {
        $settings = Store::settings();
        $cv = array_merge(self::defaults(), is_array($settings['convert'] ?? null) ? $settings['convert'] : []);
        if (empty($cv['enabled'])) return ['ok' => false, 'reason' => 'Convert disabled'];

        $lock = self::lockQueue();
        if ($lock === false) {
            // Another request is ticking right now; report current state.
            return ['ok' => true, 'queue' => self::queue(), 'busy' => true];
        }
        Store::invalidate('convert_queue');
        $q = self::queue();
        $running = null;
        $completed = [];
        foreach ($q as &$job) {
            if (($job['status'] ?? '') === 'running') {
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
            self::saveQueue($q);
            self::unlockQueue($lock);
            return ['ok' => true, 'running' => $running, 'queue' => $q];
        }

        foreach ($q as &$job) {
            if (($job['status'] ?? '') !== 'queued') continue;
            $started = self::startJob($job, $cv);
            $job = $started;
            break;
        }
        unset($job);
        self::saveQueue($q);
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
                $new = Catalogue::ingestFile($dest, $section);
                if ($new) $job['dest_item_id'] = $new['id'];
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
            if (self::isH264($srcVideo) && self::isAac($srcAudio)) {
                $frag = '-map 0 -c copy' . $faststart . ' -f ' . $muxer;
                return [$frag, 'DirectPlay remux (H.264+AAC copy)', 'remux'];
            }
            if (self::isH264($srcVideo)) {
                $frag = '-map 0:v:0 -map 0:a? -c:v copy -c:a aac -b:a ' . $abit . $threadArg . $faststart . ' -f ' . $muxer;
                return [$frag, 'DirectPlay (video copy + AAC ' . $abit . ')', 'audio_reencode'];
            }
            // Non-H.264 source: must encode for browser DirectPlay
            $crf = 18;
            $preset = 'medium';
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
        $muxer = self::CONTAINERS[$container];
        $faststart = $muxer === 'matroska' ? '' : ' -movflags +faststart';

        list($ffArgs, $label, $strategy) = self::buildFfmpegArgs((string)$job['src'], $cv, $muxer, $faststart);
        $threads = self::sanitizeThreadCount($cv['threads'] ?? 1);
        $job['threads'] = $threads;
        $inputThreads = implode(' ', self::threadOptions($threads, 'copy')['input']);

        // Wrapper: $1=src $2=part $3=dest $4=exit_file $5=progress_file. No -vf scale: preserve resolution.
        $inner = sprintf(
            'nice -n %d ionice -c %d ffmpeg -nostdin -y %s -progress "$5" -nostats -i "$1" %s "$2" && mv -f "$2" "$3"; rc=$?; [ $rc -ne 0 ] && rm -f "$2"; echo $rc > "$4"',
            $nice, $ionice, $inputThreads, $ffArgs
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
    }

    /**
     * Completed job: delete the converted output (bad quality), keep the original.
     * Removes dest from disk + catalogue; marks job resolved as deleted_conversion.
     */
    public static function deleteConverted(string $jobId): array {
        $q = self::queue();
        $i = self::findJob($q, $jobId);
        if ($i < 0) json_error('Job not found', 404);
        $job = $q[$i];
        if (($job['status'] ?? '') !== 'done') json_error('Only completed converts can delete the output', 400);
        if (!empty($job['original_removed'])) {
            json_error('Original was already replaced - cannot delete the only remaining file this way', 409);
        }

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
            $removedIds = Catalogue::removePath($destReal);
        }

        $destItemId = (string)($job['dest_item_id'] ?? '');
        if ($destItemId !== '' && !in_array($destItemId, $removedIds, true)) {
            $removedIds = array_merge($removedIds, Catalogue::removeIds([$destItemId]));
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
    }

    /**
     * Completed job: delete the original source file, keep the converted file
     * (same folder), repoint catalogue / progress / collections, mark done.
     */
    public static function removeOriginal(string $jobId): array {
        $q = self::queue();
        $i = self::findJob($q, $jobId);
        if ($i < 0) json_error('Job not found', 404);
        $job = $q[$i];
        if (($job['status'] ?? '') !== 'done') json_error('Convert has not completed successfully', 400);
        if (!empty($job['original_removed'])) {
            return ['ok' => true, 'job' => $job, 'message' => 'Original already removed'];
        }

        $src = (string)($job['src'] ?? '');
        $dest = (string)($job['dest'] ?? '');
        $destReal = $dest !== '' ? \Vaultcast\Paths::real($dest) : false;
        if ($destReal === false || !is_file($destReal)) json_error('Converted file not found: ' . $dest, 409);
        $destSize = (int)@filesize($destReal);
        if ($destSize < 1048576) json_error('Converted file looks too small (' . $destSize . ' bytes) - original kept', 409);
        if (!self::underLibraryRoot($destReal)) json_error('Converted file is outside the library roots', 400);

        $srcReal = $src !== '' ? \Vaultcast\Paths::real($src) : false;
        $srcExists = $srcReal !== false && is_file($srcReal);
        if ($srcExists) {
            if ($srcReal === $destReal) json_error('Source and converted file are the same path', 400);
            if (dirname($srcReal) !== dirname($destReal)) json_error('Converted file is not beside the original - refusing to delete', 400);
            if (!self::underLibraryRoot($srcReal)) json_error('Original is outside the library roots', 400);
            if (!is_writable(dirname($srcReal))) json_error('Folder not writable: ' . dirname($srcReal), 403);

            // Verify the converted file plays through (duration within 3% / 10 s of the source).
            $dDest = self::probeDuration($destReal);
            if ($dDest === null) json_error('Could not read converted file duration (ffprobe) - original kept', 409);
            $dSrc = self::probeDuration($srcReal);
            if ($dSrc !== null && $dDest < $dSrc - max(10.0, $dSrc * 0.03)) {
                json_error(sprintf('Converted file is shorter than the original (%.0fs vs %.0fs) - original kept', $dDest, $dSrc), 409);
            }
        }

        // Make sure the converted file is in the catalogue before the original disappears.
        $section = (string)($job['section'] ?? '');
        $oldItem = !empty($job['item_id']) ? Catalogue::item((string)$job['item_id']) : null;
        if ($section === '' && $oldItem) $section = (string)($oldItem['section'] ?? '');
        $newItem = $section !== '' ? Catalogue::ingestFile($destReal, $section) : null;
        if (!$newItem) json_error('Could not add converted file to the catalogue - original kept', 500);
        $newId = (string)$newItem['id'];

        if ($srcExists) {
            if (!@unlink($srcReal)) {
                $err = error_get_last();
                json_error('Could not delete original: ' . ($err['message'] ?? 'permission denied'), 500);
            }
            clearstatcache(true, $srcReal);
        }

        $oldIds = Catalogue::removePath($srcExists ? $srcReal : $src);
        $oldId = (string)($job['item_id'] ?? '');
        if ($oldId !== '' && $oldId !== $newId && !in_array($oldId, $oldIds, true)) {
            // catalogue item for the original may carry an un-normalised path
            $old = Catalogue::item($oldId);
            if ($old && (string)($old['path'] ?? '') === $src) $oldIds = array_merge($oldIds, Catalogue::removeIds([$oldId]));
        }
        if ($oldId !== '' && !in_array($oldId, $oldIds, true)) $oldIds[] = $oldId;
        $oldIds = array_values(array_unique(array_filter($oldIds, function ($x) use ($newId) { return $x !== '' && $x !== $newId; })));

        $prog = self::migrateProgress($oldIds, $newId);
        $cols = self::migrateCollections($oldIds, $newId);

        $job['dest_item_id'] = $newId;
        $n = self::resolveLinkedRequests($job, 'Done - converted, original removed', $newId);

        $q = self::queue();
        $i = self::findJob($q, $jobId);
        if ($i >= 0) {
            $q[$i]['dest_item_id'] = $newId;
            $q[$i]['original_removed'] = true;
            $q[$i]['original_removed_at'] = date('c');
            $q[$i]['resolved'] = true;
            $q[$i]['resolved_at'] = date('c');
            $q[$i]['resolution'] = 'original_removed';
            $q[$i]['message'] = $srcExists ? 'Done - original removed' : 'Done - original was already gone; catalogue updated';
            self::saveQueue($q);
            $job = $q[$i];
        }
        self::audit('remove-original job=' . $jobId . ' src=' . $src . ' dest=' . $destReal . ' new=' . $newId
            . ' old=' . implode(',', $oldIds) . ' progress=' . $prog . ' collections=' . $cols . ' requests=' . $n
            . ($srcExists ? '' : ' (src already missing)'));
        return [
            'ok' => true,
            'job' => $job,
            'deleted' => $srcExists ? $srcReal : null,
            'kept' => $destReal,
            'new_item_id' => $newId,
            'removed_item_ids' => $oldIds,
            'progress_migrated' => $prog,
            'collection_links_migrated' => $cols,
            'requests_resolved' => $n,
        ];
    }

    /** Move per-user watch progress from old item ids to the new one. */
    private static function migrateProgress(array $oldIds, string $newId): int {
        return \Vaultcast\Repository::migrateProgress($oldIds, $newId);
    }

    /** Repoint collection part links (auto or manual) from old item ids to the new one. */
    private static function migrateCollections(array $oldIds, string $newId): int {
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
    }

    public static function clearFinished(): array {
        $q = array_values(array_filter(self::queue(), function ($job) {
            return in_array($job['status'] ?? '', ['queued', 'running'], true);
        }));
        self::saveQueue($q);
        return ['ok' => true, 'queue' => $q];
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
