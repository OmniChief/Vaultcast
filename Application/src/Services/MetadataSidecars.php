<?php
declare(strict_types=1);

/** Portable library backups. Runs only in background; existing user sidecars win. */
final class MetadataSidecars
{
    private static $guides = [];
    private static $roots = null;
    private static $manifests = [];
    private static function roots(): array {
        if (self::$roots !== null) return self::$roots;
        self::$roots = [];
        foreach (Store::settings()['sections'] ?? [] as $section) {
            $root = realpath((string)($section['path'] ?? ''));
            if ($root) self::$roots[(string)($section['id'] ?? '')] = $root;
        }
        return self::$roots;
    }
    private static function lock(string $dir)
    {
        $runtime = STREAM_DATA . '/runtime';
        if (!is_dir($runtime)) @mkdir($runtime, 0775, true);
        $lock = @fopen($runtime . '/sidecar-' . sha1($dir) . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) fclose($lock);
            throw new RuntimeException('Cannot lock sidecar export.');
        }
        return $lock;
    }

    private static function showDirectory(array $it): ?string
    {
        $dir = self::directory($it);
        $show = Catalogue::showDirFromItem($it);
        $show = $show ? realpath($show) : false;
        if (!$dir || !$show || !is_dir($show) || !self::inside($dir, $show)) return null;
        $root = self::roots()[(string)($it['section'] ?? '')] ?? null;
        if ($root && self::inside($show, $root)) return $show;
        return null;
    }

    /** Remove only unchanged exports so a rematch cannot re-import its old art. */
    private static function invalidate(string $dir, string $pattern): void
    {
        $lock = self::lock($dir);
        try {
            $key = 'sidecar-export:' . sha1(str_replace('\\', '/', $dir));
            $owned = \Vaultcast\Repository::document($key, []);
            $owned = is_array($owned) ? $owned : [];
            foreach ($owned as $name => $hash) {
                if (!preg_match($pattern, $name)) continue;
                $path = $dir . '/' . $name;
                if (!is_link($path) && is_file($path) && @hash_file('sha256', $path) === $hash && @unlink($path)) unset($owned[$name], $owned['_source:' . $name]);
            }
            \Vaultcast\Repository::putDocument($key, $owned);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function invalidateShow(string $key): void
    {
        foreach (ArtworkLibrary::targets() as $target) {
            if (($target['kind'] ?? '') !== 'shows' || ($target['key'] ?? '') !== $key) continue;
            $dir = self::showDirectory($target['item']);
            if ($dir) self::invalidate($dir, '/^(?:poster|thumb|banner|fanart|season\d+-(?:poster|thumb))\.(?:jpe?g|png|webp)$/D');
        }
    }

    public static function invalidateMovie(array $it): void
    {
        $dir = self::directory($it);
        if (!$dir) return;
        $stem = preg_quote(pathinfo((string)realpath((string)$it['path']), PATHINFO_FILENAME), '/');
        self::invalidate($dir, '/^' . $stem . '-(?:poster|thumb|banner|fanart)\.(?:jpe?g|png|webp)$/D');
    }
    public static function schedule(): array
    {
        $dir = STREAM_DATA . '/runtime';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $lock = @fopen($dir . '/sidecar-schedule.lock', 'c');
        if (!$lock) return ['ok'=>false, 'warning'=>'Could not queue metadata backup; check library/server permissions.'];
        try {
            if (!flock($lock, LOCK_EX)) return ['ok'=>false, 'warning'=>'Could not lock metadata backup scheduling.'];
            $pending = \Vaultcast\Repository::document('sidecar-preparation-job', []);
            $job = !empty($pending['id']) ? \Vaultcast\Repository::document('maintenance-job:' . $pending['id'], []) : [];
            // Coalesce repeated matches into one waiting pass. If one is already
            // running, queue a successor so changes after its snapshot are covered.
            if (($job['status'] ?? '') === 'queued') return ['ok'=>true, 'job'=>$job];
            $result = Maintenance::start('metadata', 'Metadata/artwork changed');
            \Vaultcast\Repository::putDocument('sidecar-preparation-job', ['id'=>$result['job']['id']]);
            return $result;
        } catch (Throwable $e) {
            error_log('Vaultcast sidecar scheduling: ' . $e->getMessage());
            return ['ok'=>false, 'warning'=>'Metadata saved, but the background backup could not start. Run Prepare artwork and synopses after checking the PHP CLI setting.'];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function inside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    /** Only real files inside their configured library; never follow sidecar symlinks. */
    private static function directory(array $it): ?string
    {
        $file = realpath((string)($it['path'] ?? ''));
        if (!$file || !is_file($file)) return null;
        $root = self::roots()[(string)($it['section'] ?? '')] ?? null;
        if ($root && self::inside($file, $root)) return dirname($file);
        return null;
    }

    private static function xml(string $root, array $fields): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<" . $root . ">\n";
        foreach ($fields as $tag => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                if ($value === null || $value === '' || is_array($value)) continue;
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value);
                $xml .= '  <' . $tag . '>' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</' . $tag . ">\n";
            }
        }
        return $xml . '</' . $root . ">\n";
    }

    /** Atomic replacement only of files whose content still matches our last export. */
    private static function write(string $path, string $body, array &$owned, array &$out): void
    {
        $name = basename($path);
        if (is_link($path)) { $out['preserved']++; return; }
        $hash = hash('sha256', $body);
        $old = is_file($path) ? @hash_file('sha256', $path) : null;
        if ($old === $hash) { $out['unchanged']++; return; }
        if (file_exists($path) && (!$old || ($owned[$name] ?? null) !== $old)) { $out['preserved']++; return; }
        $tmp = @tempnam(dirname($path), '.vaultcast-');
        if (!$tmp) { $out['failed']++; return; }
        if (realpath(dirname($tmp)) !== realpath(dirname($path))) { @unlink($tmp); $out['failed']++; return; }
        if (@file_put_contents($tmp, $body) !== strlen($body) || is_link($path)
            || ($old !== null && @hash_file('sha256', $path) !== $old)
            || ($old === null && file_exists($path)) || !@rename($tmp, $path)) {
            @unlink($tmp); $out['failed']++; return;
        }
        @chmod($path, 0664);
        $owned[$name] = $hash; $out['written']++;
    }

    private static function picture(string $source, string $destStem, array &$owned, array &$out): void
    {
        if (!is_file($source)) return;
        $size = @filesize($source);
        if (!$size || $size > 32 * 1024 * 1024) { $out['failed']++; return; }
        $info = @getimagesize($source);
        $ext = ['image/jpeg'=>'.jpg', 'image/png'=>'.png', 'image/webp'=>'.webp'][$info['mime'] ?? ''] ?? null;
        if (!$ext) { $out['failed']++; return; }
        $dest = $destStem . $ext;
        $sourceKey = '_source:' . basename($dest);
        $signature = sha1($source . ':' . $size . ':' . filemtime($source));
        // Unchanged sources cannot require a new backup. Avoid rereading and
        // hashing megabytes over a NAS on every continuation; edits to the
        // destination remain protected by write() when the source next changes.
        if (($owned[$sourceKey] ?? null) === $signature && is_file($dest) && !is_link($dest)) { $out['unchanged']++; return; }
        $body = @file_get_contents($source);
        if ($body === false) { $out['failed']++; return; }
        $failed = $out['failed'];
        self::write($dest, $body, $owned, $out);
        if ($out['failed'] === $failed) $owned[$sourceKey] = $signature;
    }

    private static function export(array $it, ?array $target, array &$out): void
    {
        $dir = self::directory($it);
        if (!$dir) { $out['skipped']++; return; }
        $stem = pathinfo((string)realpath((string)$it['path']), PATHINFO_FILENAME);
        $kind = $target['kind'] ?? 'episode';
        $fields = ['title'=>$it['title'] ?? $stem, 'year'=>$it['year'] ?? null, 'plot'=>$it['summary'] ?? null, 'genre'=>$it['genres'] ?? [], 'id'=>$it['imdb_id'] ?? null];
        $metadata = array_intersect_key($it, array_flip(['title','year','show','season','episode','episode_title','genres','imdb_id','tmdb_id','tvmaze_id','duration','runtime','media_kind']));
        $metadata['media_facts'] = MediaFacts::get($it);
        if ($kind === 'shows' || $kind === 'seasons') {
            $showDir = self::showDirectory($it);
            if (!$showDir) { $out['skipped']++; return; }
            $dir = $showDir;
            $fields['title'] = $it['show'];
            $fields['plot'] = Posters::showSynopsisCached($target['key']);
            $metadata['match'] = MetadataPins::get('show_meta', $target['key']);
            $stem = $kind === 'shows' ? 'tvshow' : sprintf('season%02d', $target['season']);
            if ($kind === 'seasons') $fields['seasonnumber'] = $target['season'];
        } elseif ($kind === 'movies') {
            $synopsis = Posters::movieSynopsisCached((string)$it['id']);
            $fields['plot'] = $synopsis['summary'] ?? $fields['plot'];
            $metadata['synopsis'] = $synopsis;
            $metadata['poster_match'] = MetadataPins::get('movie_poster', (string)$it['id']);
        } elseif ($kind === 'episode') {
            $fields['title'] = $it['episode_title'] ?? $it['title'] ?? $stem;
            $fields['showtitle'] = $it['show'] ?? null;
            $fields['season'] = $it['season'] ?? null; $fields['episode'] = $it['episode'] ?? null;
        }
        if (!empty($it['show'])) {
            $key = $target['key'] ?? Catalogue::showKey((string)$it['section'], (string)$it['show']);
            if (!array_key_exists($key, self::$guides)) {
                $guide = MediaFacts::guide($key) ?? [];
                $episodes = [];
                foreach ($guide['episodes'] ?? [] as $episode) $episodes[($episode['season'] ?? '') . ':' . ($episode['number'] ?? '')] = $episode;
                unset($guide['episodes']);
                self::$guides[$key] = ['show'=>$guide, 'episodes'=>$episodes];
            }
            $guide = self::$guides[$key]['show'];
            $metadata['show_metadata'] = $guide;
            $fields['genre'] = $guide['genres'] ?? $fields['genre'];
            if ($kind === 'episode') {
                $episode = self::$guides[$key]['episodes'][($it['season'] ?? '') . ':' . ($it['episode'] ?? '')] ?? [];
                $metadata['episode_metadata'] = $episode;
                if (empty($it['episode_title']) && !empty($episode['name'])) $fields['title'] = $episode['name'];
                $fields['aired'] = $episode['airdate'] ?? null; $fields['runtime'] = $episode['runtime'] ?? null;
                $fields['plot'] = $episode['summary'] ?? $fields['plot'];
            }
        }
        // Keep technical/provider details portable too, without exporting
        // playback history, users, credentials or absolute library paths.
        $fields['vaultcastmetadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $lock = self::lock($dir);
        try {
            $manifestKey = 'sidecar-export:' . sha1(str_replace('\\', '/', $dir));
            $owned = self::$manifests[$manifestKey] ?? \Vaultcast\Repository::document($manifestKey, []);
            $owned = is_array($owned) ? $owned : [];
            $before = $owned;
            $nfo = self::xml($kind === 'shows' ? 'tvshow' : ($kind === 'seasons' ? 'season' : ($kind === 'episode' ? 'episodedetails' : 'movie')), $fields);
            $preserved = $out['preserved'];
            self::write($dir . '/' . $stem . '.nfo', $nfo, $owned, $out);
            // Keep the fetched backup even when another media manager owns the NFO.
            if ($out['preserved'] > $preserved) self::write($dir . '/' . $stem . '.vaultcast.nfo', $nfo, $owned, $out);
            if ($target) {
                $posterStem = $kind === 'shows' ? 'poster' : ($kind === 'seasons' ? sprintf('season%02d-poster', $target['season']) : $stem . '-poster');
                self::picture($target['path'], $dir . '/' . $posterStem, $owned, $out);
                $cacheStem = preg_replace('/\.[^.]+$/', '', $target['path']);
                self::picture($cacheStem . '.webp', $dir . '/' . $posterStem, $owned, $out);
                $thumbStem = $kind === 'shows' ? 'thumb' : ($kind === 'seasons' ? sprintf('season%02d-thumb', $target['season']) : $stem . '-thumb');
                self::picture($cacheStem . '_thumb.webp', $dir . '/' . $thumbStem, $owned, $out);
                foreach (['banner', 'fanart'] as $art) {
                    $source = $it[$art] ?? null;
                    if (is_string($source) && is_file($source)) self::picture($source, $dir . '/' . ($kind === 'shows' ? $art : $stem . '-' . $art), $owned, $out);
                }
            }
            if ($owned !== $before) {
                // Merge just this export's changes under the folder lock. A
                // rematch may have invalidated other files since our cached read.
                $fresh = \Vaultcast\Repository::document($manifestKey, []);
                $fresh = is_array($fresh) ? $fresh : [];
                foreach ($owned as $name => $value) if (($before[$name] ?? null) !== $value) $fresh[$name] = $value;
                \Vaultcast\Repository::putDocument($manifestKey, $fresh);
                $owned = $fresh;
            }
            self::$manifests[$manifestKey] = $owned;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function run(?callable $progress = null, float $budget = 120, bool $resume = false): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Sidecar export requires the maintenance worker.');
        $revision = \Vaultcast\Repository::revision('catalogue');
        $tasks = [];
        self::$guides = []; self::$roots = null; self::$manifests = [];
        foreach (ArtworkLibrary::targets() as $target) if ($target['kind'] !== 'games') $tasks[] = [$target['item'], $target];
        $items = Catalogue::get()['items'] ?? [];
        MediaFacts::preload(array_filter($items, static function ($it) { return ($it['media_kind'] ?? '') === 'video'; }));
        foreach ($items as $it) if (in_array($it['type'] ?? '', ['tv','anime'], true) && !empty($it['show'])) $tasks[] = [$it, null];
        $state = \Vaultcast\Repository::document('sidecar-export-cursor', []);
        $offset = ($state['revision'] ?? null) === $revision && ($resume || empty($state['done'])) ? (int)($state['offset'] ?? 0) : 0;
        $offset = max(0, min(count($tasks), $offset));
        $out = ['written'=>0, 'unchanged'=>0, 'preserved'=>0, 'failed'=>0, 'skipped'=>0, 'deferred'=>0];
        $start = microtime(true); $lastSave = $start;
        try {
        for ($i = $offset; $i < count($tasks); $i++) {
            Maintenance::checkpoint();
            if (microtime(true) - $start >= max(0, min(120, $budget))) break;
            if ($progress) $progress('Backing up metadata beside media', $i, count($tasks), (string)($tasks[$i][0]['title'] ?? ''));
            try { self::export($tasks[$i][0], $tasks[$i][1], $out); }
            catch (Throwable $e) { $out['failed']++; error_log('Vaultcast sidecar: ' . $e->getMessage()); }
            if (microtime(true) - $lastSave >= 5) {
                \Vaultcast\Repository::putDocument('sidecar-export-cursor', ['revision'=>$revision, 'offset'=>$i+1]);
                $lastSave = microtime(true);
            }
        }
        } finally { \Vaultcast\Repository::putDocument('sidecar-export-cursor', ['revision'=>$revision, 'offset'=>$i, 'done'=>$i >= count($tasks)]); }
        $out['deferred'] = count($tasks) - $i;
        return $out;
    }
}
