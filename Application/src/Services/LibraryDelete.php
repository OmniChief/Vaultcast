<?php
declare(strict_types=1);

/**
 * Admin file delete from the Media Manager file browser.
 * Safety: the target must resolve (realpath) to a regular file strictly inside one of the
 * configured library roots (also realpath'd); symlinks, "..", NUL bytes and anything that
 * resolves outside a root are rejected, and a root itself is never deleted. Afterwards the
 * file's sidecars go (subtitles, .nfo, <stem>-poster/-fanart art, the app subtitle store),
 * folder artwork goes when no media is left beside it, empty parent folders are removed
 * walking up to (never including) the root, and the catalogue + caches are updated.
 */
final class LibraryDelete {
    /** @var array|null root paths override (tests) */
    public static $roots = null;

    const SIDECAR_EXT = ['srt', 'vtt', 'ass', 'ssa', 'sub', 'idx', 'sup', 'nfo'];
    const ART_EXT = ['jpg', 'jpeg', 'png', 'webp', 'tbn'];
    const ART_SUFFIX = ['poster', 'fanart', 'thumb', 'landscape', 'banner', 'clearlogo', 'clearart', 'disc', 'backdrop'];
    const FOLDER_LEFTOVERS = ['poster', 'folder', 'cover', 'fanart', 'backdrop', 'banner', 'landscape', 'logo', 'clearlogo', 'clearart', 'disc', 'thumb', 'movie'];
    const JUNK = ['thumbs.db', '.ds_store', 'desktop.ini'];

    private static function win(): bool { return DIRECTORY_SEPARATOR === '\\'; }

    private static function norm(string $p): string {
        $p = str_replace('\\', '/', $p);
        $p = $p === '/' ? $p : rtrim($p, '/');
        return self::win() ? strtolower($p) : $p;
    }

    /** realpath'd library roots. */
    public static function roots(): array {
        $raw = self::$roots;
        if ($raw === null) {
            $raw = [];
            foreach (MediaManager::allowedRoots() as $r) $raw[] = (string)$r['path'];
        }
        $out = [];
        foreach ($raw as $r) {
            $real = @realpath($r);
            if ($real !== false && is_dir($real)) $out[] = $real;
        }
        return $out;
    }

    /** Root that strictly contains $real, or null. */
    public static function rootFor(string $real): ?string {
        $n = self::norm($real);
        $best = null;
        foreach (self::roots() as $root) {
            $r = self::norm($root);
            if ($n !== $r && strpos($n, ($r === '/' ? '' : $r) . '/') === 0 && ($best === null || strlen($root) > strlen($best))) $best = $root;
        }
        return $best;
    }

    /** Validate a delete target. @return array{ok:bool,error?:string,path?:string,root?:string} */
    public static function resolve(string $raw): array {
        if ($raw === '' || strpos($raw, "\0") !== false) return ['ok' => false, 'error' => 'Invalid path.'];
        foreach (preg_split('#[\\\\/]+#', $raw) as $seg) if ($seg === '..') return ['ok' => false, 'error' => 'Path traversal is not allowed.'];
        if (@is_link($raw)) return ['ok' => false, 'error' => 'Symbolic links cannot be deleted here.'];
        $real = @realpath($raw);
        if ($real === false) return ['ok' => false, 'error' => 'File not found.'];
        foreach (self::roots() as $root) if (self::norm($root) === self::norm($real)) return ['ok' => false, 'error' => 'A library folder itself cannot be deleted.'];
        $root = self::rootFor($real);
        if ($root === null) return ['ok' => false, 'error' => 'Only files inside a library folder can be deleted.'];
        if (!is_file($real)) return ['ok' => false, 'error' => 'Only files can be deleted.'];
        // every component between root and file must be a real directory (no symlinked folders)
        $rel = substr(str_replace('\\', '/', $real), strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1);
        $cur = rtrim($root, '/\\');
        foreach (explode('/', $rel) as $seg) {
            $cur .= DIRECTORY_SEPARATOR . $seg;
            if (@is_link($cur)) return ['ok' => false, 'error' => 'Symbolic links cannot be deleted here.'];
        }
        return ['ok' => true, 'path' => $real, 'root' => $root];
    }

    private static function isMedia(string $name): bool {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $video = class_exists('Library') ? Library::videoExtensions() : ['mkv', 'mp4', 'm4v', 'avi', 'mov', 'wmv', 'ts', 'm2ts', 'webm', 'mpg', 'mpeg', 'flv'];
        return in_array($ext, $video, true) || in_array($ext, ['mp3', 'flac', 'm4a', 'ogg', 'opus', 'wav', 'iso'], true);
    }

    /** Sidecars of $file in its folder: <stem>.srt, <stem>.en.srt, <stem>.nfo, <stem>-poster.jpg ... */
    public static function sidecars(string $file): array {
        $dir = dirname($file);
        $stem = pathinfo($file, PATHINFO_FILENAME);
        $out = [];
        foreach (@scandir($dir) ?: [] as $name) {
            $full = $dir . DIRECTORY_SEPARATOR . $name;
            if ($name === '.' || $name === '..' || $full === $file || !is_file($full) || is_link($full)) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $base = pathinfo($name, PATHINFO_FILENAME);
            $sameStem = strcasecmp($base, $stem) === 0 || stripos($base, $stem . '.') === 0;
            if ($sameStem && in_array($ext, self::SIDECAR_EXT, true)) { $out[] = $full; continue; }
            if (in_array($ext, self::ART_EXT, true)) {
                if (strcasecmp($base, $stem) === 0) { $out[] = $full; continue; }
                foreach (self::ART_SUFFIX as $sfx) if (strcasecmp($base, $stem . '-' . $sfx) === 0 || strcasecmp($base, $stem . '.' . $sfx) === 0) { $out[] = $full; break; }
            }
        }
        return $out;
    }

    /** Folder art/metadata to drop when no media is left in the folder. */
    private static function folderLeftovers(string $dir): array {
        $left = [];
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($full)) return [];
            if (is_link($full) || self::isMedia($name)) return [];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $base = strtolower(pathinfo($name, PATHINFO_FILENAME));
            $ok = in_array(strtolower($name), self::JUNK, true)
                || (in_array($ext, self::ART_EXT, true) && in_array(preg_replace('/\d+$/', '', $base), self::FOLDER_LEFTOVERS, true))
                || (in_array($ext, self::ART_EXT, true) && preg_match('/^season(?:\d+|-specials)-(?:poster|thumb|banner|fanart)$/i', $base))
                || ($ext === 'nfo' && in_array($base, ['movie', 'tvshow', 'season'], true))
                || in_array($ext, self::SIDECAR_EXT, true);
            if (!$ok) return [];
            $left[] = $full;
        }
        return $left;
    }

    /** Remove empty dirs from dirname($file) upwards, stopping before $root. */
    public static function pruneEmpty(string $startDir, string $root): array {
        $removed = [];
        $dir = $startDir;
        while (self::rootFor($dir) !== null && self::norm($dir) !== self::norm($root)) {
            // Only metadata left (tvshow.nfo, folder art, Thumbs.db...)? clear it so the folder can go.
            foreach (self::folderLeftovers($dir) as $f) @unlink($f);
            if (array_diff(@scandir($dir) ?: ['x'], ['.', '..'])) break;
            if (!@rmdir($dir)) break;
            $removed[] = $dir;
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return $removed;
    }

    /** Delete $raw (see class doc). */
    public static function delete(string $raw): array {
        $v = self::resolve($raw);
        if (!$v['ok']) return $v;
        $file = $v['path'];
        $root = $v['root'];
        $isVideo = self::isMedia(basename($file));
        $sidecars = $isVideo ? self::sidecars($file) : [];
        if (!@unlink($file)) return ['ok' => false, 'error' => 'The file could not be deleted (permissions?).'];
        $removedFiles = [$file];
        foreach ($sidecars as $s) if (@unlink($s)) $removedFiles[] = $s;
        if ($isVideo && class_exists('Subtitles')) {
            $store = Subtitles::storeDir($file);
            foreach (glob($store . '/*') ?: [] as $f) if (is_file($f) && @unlink($f)) $removedFiles[] = $f;
            if (is_dir($store)) @rmdir($store);
        }
        $dir = dirname($file);
        if ($isVideo && self::norm($dir) !== self::norm($root)) {
            foreach (self::folderLeftovers($dir) as $f) if (@unlink($f)) $removedFiles[] = $f;
        }
        $removedDirs = self::pruneEmpty($dir, $root);
        $items = self::forgetItems($file);
        return ['ok' => true, 'path' => $file, 'removed_files' => $removedFiles, 'removed_dirs' => $removedDirs, 'removed_items' => $items];
    }

    /** Drop catalogue items for $file (and its cached poster), then clear derived caches. */
    public static function forgetItems(string $file): array {
        $cat = Catalogue::get();
        $items = is_array($cat['items'] ?? null) ? $cat['items'] : [];
        $gone = [];
        $target = self::norm($file);
        foreach ($items as $k => $it) {
            $p = (string)($it['path'] ?? '');
            if ($p === '') continue;
            $real = @realpath($p);
            if (self::norm($p) === $target || ($real !== false && self::norm($real) === $target) || ($real === false && self::norm($p) === $target)) {
                $gone[] = (string)($it['id'] ?? $k);
                unset($items[$k]);
            }
        }
        if (!$gone) return [];
        $wasList = array_keys((array)$cat['items']) === range(0, count((array)$cat['items']) - 1);
        $cat['items'] = $wasList ? array_values($items) : $items;
        if (isset($cat['item_count'])) $cat['item_count'] = count($items);
        Catalogue::save($cat); // also clears browse/home/facet caches
        if (class_exists('Posters')) foreach ($gone as $id) {
            $p = Posters::movieCachePath($id);
            if (is_file($p)) @unlink($p);
        }
        if (class_exists('Store')) Store::invalidate();
        return $gone;
    }
}
