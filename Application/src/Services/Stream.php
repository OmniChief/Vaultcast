<?php
declare(strict_types=1);

class Stream {
    public static function playbackItem(array $item): array {
        if(!empty($item['directplay_path']) && !is_file($item['directplay_path'])) {
            unset($item['directplay_path'],$item['directplay_ready'],$item['prepared_copy']);
            $item['directplay_versions']=[];
        }
        if ((Store::settings()['convert']['prefer_directplay'] ?? true) && !empty($item['directplay_ready']) && !empty($item['directplay_path']) && is_file($item['directplay_path'])) {
            $item['path']=$item['directplay_path'];$item['ext']=strtolower(pathinfo($item['path'],PATHINFO_EXTENSION));
            $item['prepared_copy']=true;
        }
        return $item;
    }
    /** Map library roots to internal nginx aliases */
    public static function accelMap(): array {
        $settings = Store::settings();
        $map = [];
        foreach ($settings['sections'] ?? [] as $sec) {
            if (empty($sec['path'])) continue;
            $real = \Vaultcast\Paths::real($sec['path']);
            if ($real) $map[$sec['id']] = $real;
        }
        return $map;
    }

    /** Shared token storage for library playback and Live channel playback. */
    public static function createToken(string $userId, string $itemId, string $path, string $kind, ?string $area = null, ?string $captionSource = null): string {
        $ttl = max(3600, min(86400, (int)(Store::settings()['streaming']['token_ttl_seconds'] ?? 28800)));
        $token = bin2hex(random_bytes(24));
        $payload = [
            'token' => $token, 'item_id' => $itemId, 'user_id' => $userId,
            'path' => $path, 'expires' => time() + $ttl, 'media_kind' => $kind,
            'area' => $area ?? ($kind === 'rom' ? 'games' : 'library'),
            'caption_source'=>$captionSource ?? $path,
        ];
        if ($kind === 'rom') {
            $payload['rom_dir'] = dirname($path);
            $payload['rom_primary'] = basename($path);
        }
        \Vaultcast\Repository::savePlaybackToken($token, $payload);
        return $token;
    }
    public static function issueToken(array $user, string $itemId, array $opts = []): array {
        $item = Catalogue::item($itemId);
        if (!$item) json_error('Not found', 404);
        $denied = AreaAccess::denied($user, AreaAccess::itemArea($item));
        if ($denied) json_out($denied[1], $denied[0]);
        $libraryItem = $item;
        if(empty($opts['original']))$item = self::playbackItem($item);
        $path = $item['path'] ?? '';
        if (!is_file($path)) json_error('File missing', 404);

        if(!empty($opts['external'])){
            $kind=(string)($item['media_kind']??'video');
            if(!in_array($kind,['video','audio'],true))json_error('Choose a video or audio file.',422);
            $token=self::createToken((string)$user['id'],$itemId,$path,$kind);
            return ['ok'=>true,'url'=>rtrim(\Vaultcast\Database::basePath(),'/').'/api/stream/play?t='.$token.'&direct=1'];
        }

        // EmulatorJS only needs .bin for PS1 — ignore .cue; remount sibling .bin for play.
        if (($item['media_kind'] ?? '') === 'rom') {
            $ext0 = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            if ($ext0 === 'cue') {
                $binPath = preg_replace('/\.cue$/i', '.bin', $path);
                if (is_string($binPath) && $binPath !== '' && is_file($binPath)) {
                    $path = $binPath;
                    $item['path'] = $binPath;
                    $item['ext'] = 'bin';
                    $rel0 = (string)($item['rel'] ?? '');
                    if ($rel0 !== '') {
                        $item['rel'] = preg_replace('/\.cue$/i', '.bin', $rel0) ?: $rel0;
                    }
                }
            }
            $relForCore = (string)($item['rel'] ?? basename($path));
            $extForCore = strtolower((string)($item['ext'] ?? pathinfo($path, PATHINFO_EXTENSION)));
            $core = Catalogue::guessEmuCore($extForCore, $relForCore, $path);
            if ($core) $item['emu_core'] = $core;
        }

        $captionSource=(string)($libraryItem['caption_source'] ?? $libraryItem['path'] ?? $path);
        $token = self::createToken((string)$user['id'], $itemId, $path, (string)($item['media_kind'] ?? 'video'),null,$captionSource);

        $base = rtrim(\Vaultcast\Database::basePath(), '/');
        // Issue playback tokens without waiting for optional media analysis.
        // Clients that need multi-audio call GET /items/{id}/probe separately.
        $audioTracks = [];
        $videoCodec = null;
        $wantProbe = !empty($opts['probe']) || (isset($_GET['probe']) && $_GET['probe'] !== '0');
        if ($wantProbe) {
            $probe = MediaProbe::probeSummary($path, 3, true);
            $audioTracks = $probe['audio_tracks'] ?? [];
            $videoCodec = $probe['video_codec'] ?? null;
        }
        $pref = null;
        if (array_key_exists('audio', $opts) && $opts['audio'] !== '' && $opts['audio'] !== null) {
            $pref = (int)$opts['audio'];
        } elseif (isset($_GET['audio'])) {
            $pref = (int)$_GET['audio'];
        }
        $ext = strtolower((string)($item['ext'] ?? ''));
        $needsConvertHint = in_array($ext, ['mkv','avi','wmv','mov','ts','m2ts','mpg','mpeg','flv','divx','xvid'], true);
        $directPlayAudioSelect = count($audioTracks) > 1;
        /* saved resume position for this user/item so the player seeks on start */
        $progress = null;
        $uid = (string)($user['id'] ?? '');
        $pp = $uid !== '' ? \Vaultcast\Repository::progressItem($uid, $itemId) : null;
        if (is_array($pp)) {
            $pos = (float)($pp['position'] ?? 0);
            $dur = (float)($pp['duration'] ?? 0);
            if ($pos >= 5 && !($dur > 0 && $pos > $dur - 10)) {
                $progress = ['position' => $pos, 'duration' => $dur, 'updated_at' => $pp['updated_at'] ?? null];
            }
        }
        $isPsx = (($item['emu_core'] ?? '') === 'psx');
        $biosUrl = $isPsx ? self::preferredPsxBiosUrl() : null;
        $romUrl = (($item['media_kind'] ?? '') === 'rom')
            ? ($base . '/api/stream/rom/' . $token . '/' . rawurlencode(basename($path)))
            : ($base . '/api/stream/play?t=' . $token);
        // PS1 launches .bin directly (no cue/sidecars). Keep sidecar fetch only for leftover cue/ccd.
        $sidecars = [];
        if (($item['media_kind'] ?? '') === 'rom' && in_array($ext, ['cue','ccd','toc','m3u'], true)) {
            $sidecars = self::romSidecarFiles($path, $token);
        }
        $automaticBlocked=empty($item['prepared_copy']) && ($item['media_kind'] ?? '')==='video' && Convert::automaticPlaybackBlocked($itemId);
        $saveAllowed=Transcode::allowed() && Transcode::eligible($libraryItem) && empty($item['directplay_path']) && !preg_match('/\.directplay\.[^.]+$/i',(string)$libraryItem['path']) && !empty(Transcode::settings()['allow_save']) && Permissions::allowed($user,'save_copy');
        $libraryConversion=empty($item['prepared_copy']) && ($item['media_kind'] ?? '')==='video' ? Convert::activeLibraryConversion($itemId) : null;
        return [
            'transcode_allowed'=>Transcode::allowed() && Transcode::eligible($libraryItem),
            'transcode_auto_blocked'=>$automaticBlocked,
            'transcode_mode'=>'manual', // never automatic; kept for cached older clients
            'playback_hint'=>$saveAllowed ? null : (($item['media_kind'] ?? '')==='video' && (Transcode::allowed() || Remux::enabled()) && Transcode::eligible($libraryItem) ? Transcode::defaultSaveMode($libraryItem) : null),
            'active_conversion'=>Transcode::allowed() && Transcode::eligible($libraryItem)?Transcode::activeFor((string)$libraryItem['id'],(string)$user['id']):null,
            'transcode_save_allowed'=>$saveAllowed,
            'save_copy_default'=>$saveAllowed?Transcode::defaultSaveMode($libraryItem):null,
            'prepared_copy'=>!empty($item['prepared_copy']),
            'library_conversion'=>$libraryConversion,
            'progress' => $progress,
            'resume_position' => $progress ? $progress['position'] : 0,
            'ok' => true,
            'token' => $token,
            'url' => $romUrl,
            'media_kind' => $item['media_kind'],
            'ext' => $item['ext'],
            'title' => $item['title'],
            'emu_core' => $item['emu_core'] ?? null,
            'bios_url' => $biosUrl,
            'bios_required' => $isPsx,
            'bios_missing' => $isPsx && !$biosUrl,
            'bios_hint' => $isPsx
                ? 'Place your PS1 BIOS in this installation’s data/bios/psx/ directory. Any supported scph*.bin or PSXONPSP*.bin is auto-detected.'
                : null,
            'rom_sidecars' => $sidecars,
            'mime' => self::mimeFor($item['ext'], $item['media_kind']),
            'audio_tracks' => $audioTracks,
            'subtitles' => ($item['media_kind'] ?? '') === 'video' ? Subtitles::tracks($path, $token, $captionSource) : [],
            'subtitle_search' => ($item['media_kind'] ?? '') === 'video' && OpenSubtitles::allowed($user),
            'subtitle_upload' => ($item['media_kind'] ?? '') === 'video' && Subtitles::uploadAllowed($user),
            'audio_count' => count($audioTracks),
            'preferred_audio' => $pref,
            'directplay_audio_select' => $directPlayAudioSelect,
            'remux_url' => ($item['media_kind'] ?? '') === 'video' ? ($base . '/api/stream/remux?t=' . $token) : null,
            'remux_allowed' => Remux::enabled(),
            'duration' => isset($item['duration']) ? (float)$item['duration'] : null,
            'video_codec' => $videoCodec,
            'needs_convert_hint' => $needsConvertHint,
            'audio_note' => count($audioTracks) > 1
                ? 'Multiple audio tracks detected. The player switches tracks with the browser when it can, otherwise through /stream/remux at the current position.'
                : null,
            'file_missing' => !is_file($path),
        ];
    }

    public static function mimeFor(string $ext, string $kind): string {
        $map = [
            'mp4'=>'video/mp4','m4v'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg',
            'mkv'=>'video/x-matroska','avi'=>'video/x-msvideo','mov'=>'video/quicktime',
            'mp3'=>'audio/mpeg','flac'=>'audio/flac','aac'=>'audio/aac','m4a'=>'audio/mp4',
            'wav'=>'audio/wav','opus'=>'audio/opus',
        ];
        if (isset($map[$ext])) return $map[$ext];
        return $kind === 'audio' ? 'application/octet-stream' : 'application/octet-stream';
    }

    public static function play(): void {
        $token = $_GET['t'] ?? '';
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        $payload = \Vaultcast\Repository::playbackToken($token);
        if ($payload === null) { http_response_code(403); echo 'Expired'; exit; }
        /* Each Range/play request extends token expiry for long movies */
        $settings = Store::settings();
        $ttl = (int)($settings['streaming']['token_ttl_seconds'] ?? 28800);
        if ($ttl < 3600) $ttl = 3600;
        if ($ttl > 86400) $ttl = 86400;
        if ($payload['expires'] - time() < $ttl / 2) {
            $payload['expires'] = time() + $ttl;
            \Vaultcast\Repository::savePlaybackToken($token, $payload);
        }
        $path = $payload['path'] ?? '';
        $real = \Vaultcast\Paths::real($path);
        if ($real === false || !is_file($real)) {
            http_response_code(404);
            echo 'Missing';
            exit;
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = self::mimeFor($ext, $payload['media_kind'] ?? 'video');
        $isHead = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');

        /* HEAD: headers only (no X-Accel body). Some clients probe before Range GET. */
        if ($isHead) {
            $size = filesize($real);
            header('Content-Type: ' . $mime);
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . $size);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Expose-Headers: Content-Type, Content-Length, Content-Range, Accept-Ranges');
            header('Cache-Control: private, no-store');
            exit;
        }

        self::emitAccelOrSend($real, $mime);
        return;
    }


    /**
     * Path-style ROM stream so EmulatorJS can resolve FILE "Game.bin" next to Game.cue.
     * URL: /api/stream/rom/{token}/{filename}
     */
    public static function playRom(string $token, string $filename): void {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        $filename = rawurldecode($filename);
        $filename = basename($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            http_response_code(400);
            echo 'Bad file';
            exit;
        }
        $payload = \Vaultcast\Repository::playbackToken($token);
        if ($payload === null) { http_response_code(403); echo 'Expired'; exit; }
        $settings = Store::settings();
        $ttl = (int)($settings['streaming']['token_ttl_seconds'] ?? 28800);
        if ($ttl < 3600) $ttl = 3600;
        if ($ttl > 86400) $ttl = 86400;
        if ($payload['expires'] - time() < $ttl / 2) {
            $payload['expires'] = time() + $ttl;
            \Vaultcast\Repository::savePlaybackToken($token, $payload);
        }

        $primary = (string)($payload['path'] ?? '');
        $dir = (string)($payload['rom_dir'] ?? dirname($primary));
        $dirReal = \Vaultcast\Paths::real($dir);
        if ($dirReal === false || !is_dir($dirReal)) {
            http_response_code(404);
            echo 'Missing';
            exit;
        }
        $candidate = $dirReal . '/' . $filename;
        $real = \Vaultcast\Paths::real($candidate);
        $primaryReal = $primary !== '' ? \Vaultcast\Paths::real($primary) : false;
        if ($real === false || !is_file($real)) {
            http_response_code(404);
            echo 'Missing';
            exit;
        }
        if (strpos($real, rtrim($dirReal, '/') . '/') !== 0 && $real !== $primaryReal) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = self::mimeFor($ext, $payload['media_kind'] ?? 'rom');
        $isHead = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        if ($isHead) {
            $size = filesize($real);
            header('Content-Type: ' . $mime);
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . $size);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Expose-Headers: Content-Type, Content-Length, Content-Range, Accept-Ranges');
            header('Cache-Control: private, no-store');
            exit;
        }
        self::emitAccelOrSend($real, $mime);
        return;
    }

    /** serve PS1 BIOS from data/bios/psx (auto-detect any scph*.bin / PSXONPSP*.bin). */
    public static function playBios(string $filename): void {
        $filename = basename(rawurldecode($filename));
        if (!self::isAllowedPsxBiosName($filename)) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }
        self::ensurePsxBiosWired();
        $dir = STREAM_DATA . '/bios/psx';
        $path = $dir . '/' . $filename;
        if (!is_file($path) && is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                if (strcasecmp($f, $filename) === 0) { $path = $dir . '/' . $f; break; }
            }
        }
        if (!is_file($path)) {
            http_response_code(404);
            echo 'BIOS missing';
            exit;
        }
        self::sendFile($path, 'application/octet-stream');
    }

    /** True for EmulatorJS-accepted PS1 BIOS filenames (case-insensitive). */
    public static function isAllowedPsxBiosName(string $filename): bool {
        $base = basename($filename);
        if ($base === '' || $base === '.' || $base === '..') return false;
        if (preg_match('/^scph\d+\.bin$/i', $base)) return true;
        if (preg_match('/^psxonpsp\d*\.bin$/i', $base)) return true;
        $known = [
            'scph5500.bin','scph5501.bin','scph5502.bin','scph1001.bin','scph101.bin',
            'scph7001.bin','psxonpsp660.bin','PSXONPSP660.bin',
        ];
        foreach ($known as $a) {
            if (strcasecmp($a, $base) === 0) return true;
        }
        return false;
    }

    /**
     * Scan configured BIOS folders for SCPH*.bin / PSXONPSP*.bin and copy into data/bios/psx.
     * Never downloads BIOS — only wires dumps already on the server.
     */
    public static function ensurePsxBiosWired(): void {
        $dest = STREAM_DATA . '/bios/psx';
        if (!is_dir($dest)) @mkdir($dest, 0775, true);
        // Already have a usable BIOS?
        if (self::listPsxBiosFiles($dest)) return;

        $roots = [$dest];
        $settings = Store::settings();
        foreach ((array)($settings['emu']['psx_bios_dirs'] ?? []) as $extra) {
            if (is_string($extra) && $extra !== '') $roots[] = $extra;
        }
        $seen = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            $real = \Vaultcast\Paths::real($root) ?: $root;
            if (isset($seen[$real])) continue;
            $seen[$real] = true;
            foreach (scandir($root) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                $full = $root . '/' . $f;
                if (!is_file($full)) continue;
                if (!self::isAllowedPsxBiosName($f)) continue;
                $size = filesize($full);
                // Real PS1 BIOS is typically 512KB (524288); allow 256KB–2MB
                if ($size !== false && ($size < 200000 || $size > 2500000)) continue;
                $target = $dest . '/' . $f;
                if (is_file($target)) continue;
                @copy($full, $target);
            }
        }
    }

    /** @return array<string,string> lower=>actual filename */
    public static function listPsxBiosFiles(string $dir): array {
        $files = [];
        if (!is_dir($dir)) return $files;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!is_file($dir . '/' . $f)) continue;
            if (!self::isAllowedPsxBiosName($f)) continue;
            $files[strtolower($f)] = $f;
        }
        return $files;
    }


    /**
     * EmulatorJS 4.2.1 downloads ONLY gameUrl — it does NOT parse cue FILE lines
     * or auto-fetch companion .bin/.img. Return sidecar URLs so the client can set EJS_externalFiles.
     * @return list<array{name:string,url:string}>
     */
    public static function romSidecarFiles(string $primaryPath, string $token): array {
        $dir = dirname($primaryPath);
        $primary = basename($primaryPath);
        $ext = strtolower(pathinfo($primary, PATHINFO_EXTENSION));
        $names = [];
        if (in_array($ext, ['cue', 'ccd', 'toc', 'm3u'], true) && is_file($primaryPath)) {
            $text = (string)@file_get_contents($primaryPath);
            // FILE "name.bin" BINARY  |  FILE name.bin BINARY
            if (preg_match_all('/^\s*FILE\s+(?:"([^"]+)"|(\S+))\s+/mi', $text, $m, PREG_SET_ORDER)) {
                foreach ($m as $row) {
                    $n = $row[1] !== '' ? $row[1] : $row[2];
                    $n = basename(str_replace('\\', '/', $n));
                    if ($n !== '' && $n !== $primary) $names[$n] = true;
                }
            }
            // Same-basename fallback (linuxserver-style GAME.cue → GAME.bin)
            $stem = pathinfo($primary, PATHINFO_FILENAME);
            foreach (['bin', 'img', 'iso', 'raw'] as $sx) {
                $cand = $stem . '.' . $sx;
                if (is_file($dir . '/' . $cand)) $names[$cand] = true;
            }
        }
        $out = [];
        $base = \Vaultcast\Database::basePath() . 'api/stream/rom/' . $token . '/';
        foreach (array_keys($names) as $n) {
            $full = $dir . '/' . $n;
            if (!is_file($full)) continue;
            // Skip tiny placeholders; real PS1 track bins are large
            $sz = filesize($full);
            if ($sz !== false && $sz < 1024) continue;
            $out[] = [
                'name' => $n,
                'url' => $base . rawurlencode($n),
                'size' => $sz !== false ? (int)$sz : null,
            ];
        }
        return $out;
    }

    /** Prefer US then EU then JP BIOS when files exist under data/bios/psx (auto-wire first). */
    public static function preferredPsxBiosUrl(): ?string {
        self::ensurePsxBiosWired();
        $dir = STREAM_DATA . '/bios/psx';
        $files = self::listPsxBiosFiles($dir);
        if (!$files) return null;
        $prefer = ['scph5501.bin','scph5502.bin','scph5500.bin','scph1001.bin','scph101.bin','scph7001.bin','PSXONPSP660.bin','psxonpsp660.bin'];
        foreach ($prefer as $name) {
            $k = strtolower($name);
            if (isset($files[$k])) {
                return \Vaultcast\Database::basePath() . 'api/stream/bios/psx/' . rawurlencode($files[$k]);
            }
        }
        $any = reset($files);
        return \Vaultcast\Database::basePath() . 'api/stream/bios/psx/' . rawurlencode($any);
    }

    /**
     * Internal nginx URI for X-Accel-Redirect. When enabled, PHP exits after the
     * header and nginx serves byte ranges — the php-cgi worker is free for API calls.
     * Windows: requires the installer to ship location ^~ /_vaultcast_media/ (see
     * docs/windows-php-workers.md). Paths use forward slashes.
     */
    public static function toAccelUri(string $realPath): ?string {
        if (empty(Store::settings()['streaming']['nginx_accel'])) return null;
        $realPath = str_replace('\\', '/', $realPath);
        $direct = !empty($_GET['direct']);
        $unc = DIRECTORY_SEPARATOR === '\\' && strpos($realPath, '//') === 0;
        // Older nginx installations only have the drive-letter location.
        if ($unc && empty(Store::settings()['streaming']['nginx_unc_accel'])) return null;
        if (DIRECTORY_SEPARATOR === '\\' && !$unc && !preg_match('#^[A-Za-z]:/#', $realPath)) return null;
        foreach (self::accelMap() as $id => $root) {
            $root = rtrim(str_replace('\\', '/', (string)$root), '/');
            $win = DIRECTORY_SEPARATOR === '\\';
            $under = $win ? (stripos($realPath, $root . '/') === 0 || strcasecmp($realPath, $root) === 0)
                          : (strpos($realPath, $root . '/') === 0 || $realPath === $root);
            if ($under) {
                // Player asked for PHP delivery (nginx path failed): remember, and serve via PHP.
                if ($direct) { MediaAccel::markFailed($root); return null; }
                // nginx needs read access (Windows ACL); otherwise PHP streams it.
                if (!MediaAccel::rootUsable($root)) return null;
                // Keep drive letters (C:/...) so Windows nginx can alias them.
                $rel = ltrim($realPath, '/');
                return ($unc ? '/_vaultcast_unc/' : '/_vaultcast_media/') . implode('/', array_map('rawurlencode', explode('/', $rel)));
            }
        }
        return null;
    }

    /** Emit X-Accel-Redirect or fall back to PHP sendFile. Releases locks first. */
    public static function emitAccelOrSend(string $real, string $mime): void {
        if (function_exists('release_request_locks')) release_request_locks();
        $accel = self::toAccelUri($real);
        if ($accel === null) {
            self::sendFile($real, $mime);
            return;
        }
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Expose-Headers: Content-Type, Content-Length, Content-Range, Accept-Ranges');
        header('X-Accel-Redirect: ' . $accel);
        header('Cache-Control: private, no-store');
        exit;
    }

    public static function sendFile(string $path, string $mime): void {
        // Must not hold locks while we stream for minutes (single php-cgi on Windows).
        if (function_exists('release_request_locks')) release_request_locks();
        @ignore_user_abort(true);
        @set_time_limit(0);
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        if (isset($_SERVER['HTTP_RANGE'])) {
            $valid = preg_match('/^bytes=(\d*)-(\d*)$/D', trim($_SERVER['HTTP_RANGE']), $m);
            if ($valid && ($m[1] !== '' || $m[2] !== '')) {
                if ($m[1] === '') $start = max(0, $size - (int)$m[2]);
                else {
                    $start = (int)$m[1];
                    if ($m[2] !== '') $end = min($size - 1, (int)$m[2]);
                }
            } else $valid = false;
            if (!$valid || $size === 0 || $start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
            header('Content-Length: ' . ($end - $start + 1));
        } else {
            header('Content-Length: ' . $size);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        $fp = fopen($path, 'rb');
        if (!$fp) { http_response_code(404); exit; }
        fseek($fp, $start);
        \Vaultcast\Database::releaseForStreaming();
        // Avoid accumulating a movie in PHP's output buffers and stop abandoned transfers.
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { if (!@ob_end_flush()) break; }
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($fp)) {
            if (connection_aborted()) break;
            $chunk = fread($fp, min(262144, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
            @flush();
            if (connection_aborted()) break;
        }
        fclose($fp);
        exit;
    }

    public static function servePosterFile(string $path): void {

        if (class_exists('PosterOptimise')) {
            $variant = strtolower((string)($_GET['variant'] ?? 'full'));
            if ($variant !== 'medium' && $variant !== 'thumb' && $variant !== 'full' && $variant !== 'original') $variant = 'full';
            $wantWebp = PosterOptimise::clientWantsWebp();
            $path = PosterOptimise::pickServePath($path, $variant, $wantWebp);
        }
        if (!is_file($path)) self::posterMiss();
        $info = @getimagesize($path);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/webp','image/png','image/jpeg','image/gif'], true)) self::posterMiss();
        header('Content-Type: ' . $mime);
        // Variant files can appear after background preparation; revalidate mutable URLs.
        header('Cache-Control: public, max-age=3600');
        header('Vary: Accept');
        header('Content-Length: ' . filesize($path));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        readfile($path);
        exit;
    }

    /** Resolve the requested cached variant before requiring an original JPEG. */
    private static function serveCachedPoster(string $original): void {
        $path = $original;
        if (class_exists('PosterOptimise')) {
            $variant = strtolower((string)($_GET['variant'] ?? 'full'));
            if (!in_array($variant, ['thumb', 'medium', 'full', 'original'], true)) $variant = 'full';
            $path = PosterOptimise::pickServePath($original, $variant, PosterOptimise::clientWantsWebp());
        }
        $minimum = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'webp' ? 40 : 100;
        if (is_file($path) && is_readable($path) && filesize($path) > $minimum) {
            self::servePosterFile($path);
        }
    }

    private static function wantsRemotePoster(): bool {
        return isset($_GET['fetch']) && $_GET['fetch'] !== '0' && $_GET['fetch'] !== '';
    }

    private static function posterMiss(): void {
        http_response_code(404);
        header('Cache-Control: ' . (self::wantsRemotePoster() ? 'no-store' : 'private, max-age=15'));
        header('Content-Length: 0');
        exit;
    }

    /** Item poster: disk cache, local sidecar; remote (TVMaze/Wikipedia/libretro) when ?fetch=1. */
    public static function poster(string $itemId): void {
        $safe = preg_replace('/[^a-f0-9]/', '', $itemId);
        if ($safe === '') self::posterMiss();
        $movieCache = Posters::movieCachePath($safe);
        self::serveCachedPoster($movieCache);
        $gameCache = Posters::gameCachePath($safe);
        self::serveCachedPoster($gameCache);
        $item = Catalogue::item($safe);
        if (!$item) self::posterMiss();
        // Fill season/episode + local art when catalogue scan omitted them
        if (method_exists('Catalogue', 'enrichItemArt')) {
            $item = Catalogue::enrichItemArt($item);
        }
        $fetch = self::wantsRemotePoster();
        try {
            // Windows ships a single php-cgi worker: a long Wikipedia fetch here
            // freezes every later library click. Cap remote work at ~2.5s.
            if ($fetch) Posters::beginRemoteBudget(2.5);
            $path = Posters::resolveItem($item, $fetch);
        } catch (Throwable $e) {
            $path = null;
        } finally {
            if ($fetch) Posters::endRemoteBudget();
        }
        if (!$path || !is_file($path)) self::posterMiss();
        $type = $item['type'] ?? '';
        $kind = $item['media_kind'] ?? '';
        if ($type === 'movies' || $type === 'disney' || ($kind === 'video' && empty($item['show']))) {
            $adopted = Posters::adoptFile($movieCache, $path);
            if ($adopted) $path = $adopted;
        } elseif ($type === 'roms' || $kind === 'rom') {
            $gameCache = Posters::gameCachePath($safe);
            $adopted = Posters::adoptFile($gameCache, $path);
            if ($adopted) $path = $adopted;
        } elseif (($type === 'tv' || $type === 'anime') && !empty($item['show'])) {
            // Keep season/show caches distinct. Never replace resolved season art
            // with an existing show cache (adoptFile returns dest if present).
            $key = Catalogue::showKey((string)($item['section'] ?? ''), (string)$item['show']);
            $season = array_key_exists('season', $item) && $item['season'] !== null && $item['season'] !== ''
                ? (int)$item['season'] : null;
            $seasonCache = ($season !== null) ? Posters::seasonCachePath($key, $season) : null;
            $showCache = Posters::showCachePath($key);
            $real = \Vaultcast\Paths::real($path) ?: $path;
            $base = strtolower(basename((string)$path));
            $isSeasonFile = (bool)preg_match('/^season(\d+|[-]?specials?)-poster\./', $base)
                || strpos($base, 'season_') === 0;
            if ($seasonCache && is_file($seasonCache) && (\Vaultcast\Paths::real($seasonCache) === $real || $path === $seasonCache)) {
                $path = $seasonCache;
            } elseif ($season !== null && $seasonCache && ($isSeasonFile || strpos($base, 'show_') !== 0)) {
                // Copy into season cache when missing; if adoptFile returns a stale
                // unrelated dest, keep serving the resolved $path.
                $before = is_file($seasonCache) ? (\Vaultcast\Paths::real($seasonCache) ?: $seasonCache) : null;
                $adopted = Posters::adoptFile($seasonCache, $path);
                if ($adopted && is_file($adopted)) {
                    $ar = \Vaultcast\Paths::real($adopted) ?: $adopted;
                    if ($ar === $real || $before === null || $ar === (\Vaultcast\Paths::real($seasonCache) ?: $seasonCache)) {
                        // Prefer season cache only when it is our source or a fresh adopt
                        if ($ar === $real || @filesize($adopted) === @filesize($path)) {
                            $path = $adopted;
                        }
                    }
                }
            } elseif (is_file($showCache) && (\Vaultcast\Paths::real($showCache) === $real || $path === $showCache)) {
                $path = $showCache;
            } else {
                $adopted = Posters::adoptFile($showCache, $path);
                if ($adopted && is_file($adopted)) {
                    $ar = \Vaultcast\Paths::real($adopted) ?: $adopted;
                    if ($ar === $real || @filesize($adopted) === @filesize($path)) {
                        $path = $adopted;
                    }
                }
            }
        }
        self::servePosterFile($path);
    }

    /**
     * Show poster by showKey.
     * A cached poster variant is served immediately. Catalogue::show() is not used here:
     * that builds the episode guide and may call TVMaze, which pinned the five
     * php-fpm workers and turned poster grids into 502s.
     */
    public static function showPoster(string $showKey): void {
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        if ($safe === '') self::posterMiss();
        $cached = Posters::showCachePath($safe);
        self::serveCachedPoster($cached);
        $hint = Catalogue::showPosterHint($safe);
        $local = ($hint && !empty($hint['local'])) ? (string)$hint['local'] : null;
        if ((!$local || !is_file($local)) && $hint && !empty($hint['show_dir'])) {
            $local = Posters::findLocalShowArt((string)$hint['show_dir']);
        }
        if ($local && is_file($local)) {
            $adopted = Posters::adoptFile($cached, $local);
            if ($adopted && is_file($adopted)) self::servePosterFile($adopted);
            self::servePosterFile($local);
        }
        if (self::wantsRemotePoster() && $hint && !empty($hint['title'])) {
            try {
                Posters::beginRemoteBudget(2.5);
                $path = Posters::fetchTvMazeShow($safe, (string)$hint['title']);
            } finally {
                Posters::endRemoteBudget();
            }
            if ($path && is_file($path)) self::servePosterFile($path);
        }
        self::posterMiss();
    }

    /**
     * Season poster by showKey + season number.
     * Cached poster variant served immediately; ?fetch=1 pulls TVMaze relative season art.
     * Falls back to show poster when season art is unavailable.
     */
    public static function seasonPoster(string $showKey, int $season): void {
        $safe = preg_replace('/[^a-f0-9]/', '', $showKey);
        if ($safe === '' || $season < 0) self::posterMiss();
        $cached = Posters::seasonCachePath($safe, $season);
        self::serveCachedPoster($cached);
        $hint = Catalogue::showPosterHint($safe);
        $title = $hint ? (string)($hint['title'] ?? '') : '';
        $showDir = ($hint && !empty($hint['show_dir'])) ? (string)$hint['show_dir'] : null;
        // Local Kodi-style season art BEFORE remote fetch
        $localSeason = Posters::findLocalSeasonArt($showDir, $season);
        if ($localSeason && is_file($localSeason)) {
            $adopted = Posters::adoptFile($cached, $localSeason);
            if ($adopted && is_file($adopted)) self::servePosterFile($adopted);
            self::servePosterFile($localSeason);
        }
        $fetch = self::wantsRemotePoster();
        if ($fetch && $title !== '') {
            try {
                Posters::beginRemoteBudget(2.5);
                $path = Posters::fetchTvMazeSeason($safe, $title, $season);
            } finally {
                Posters::endRemoteBudget();
            }
            if ($path && is_file($path)) self::servePosterFile($path);
        }
        // Fall back to show poster (cache / local / optional remote)
        $showCached = Posters::showCachePath($safe);
        self::serveCachedPoster($showCached);
        $localShow = ($hint && !empty($hint['local'])) ? (string)$hint['local'] : null;
        if ((!$localShow || !is_file($localShow)) && $showDir) {
            $localShow = Posters::findLocalShowArt($showDir);
        }
        if ($localShow && is_file($localShow)) {
            $adopted = Posters::adoptFile($showCached, $localShow);
            if ($adopted && is_file($adopted)) self::servePosterFile($adopted);
            self::servePosterFile($localShow);
        }
        if ($fetch && $title !== '') {
            try {
                Posters::beginRemoteBudget(2.5);
                $path = Posters::fetchTvMazeShow($safe, $title);
            } finally {
                Posters::endRemoteBudget();
            }
            if ($path && is_file($path)) self::servePosterFile($path);
        }
        self::posterMiss();
    }
}
