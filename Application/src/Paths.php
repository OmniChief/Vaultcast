<?php
declare(strict_types=1);
namespace Vaultcast;

/**
 * Library folder paths: consistent separators for containment checks, normalisation of
 * typed/pasted paths, a reasoned check (missing / access denied / open_basedir) and a
 * folder listing for the admin folder picker. Pure PHP; Windows, Linux and NAS.
 */
final class Paths {
    public static function real(string $path) {
        $resolved = realpath($path);
        return $resolved === false ? false : str_replace('\\', '/', $resolved);
    }

    public static function isWindows(?string $os = null): bool {
        return ($os ?? PHP_OS_FAMILY) === 'Windows';
    }

    /**
     * Clean a typed or pasted folder path: surrounding whitespace and quotes, file:// URLs,
     * %VAR% / $VAR / ${VAR} and ~ expansion, either slash style, duplicate and trailing
     * separators. Returns forward slashes ("C:/Users/Public/Films", "//nas/share/Films").
     * $env overrides getenv() (tests).
     */
    public static function normalize(string $raw, ?array $env = null, ?string $os = null): string {
        $p = trim($raw, " \t\n\r\0\x0B");
        // Strip matching surrounding quotes repeatedly (Explorer "Copy as path", shells, smart quotes).
        for ($i = 0; $i < 3; $i++) {
            $p = preg_replace('/^(?:"|\'|\x{201C}|\x{201D}|\x{2018}|\x{2019})+|(?:"|\'|\x{201C}|\x{201D}|\x{2018}|\x{2019})+$/u', '', $p) ?? $p;
            $p = trim($p);
        }
        if (preg_match('#^file:/*#i', $p)) {
            $p = rawurldecode(preg_replace('#^file:#i', '', $p));
            // file:///C:/x -> C:/x ; file://server/share -> //server/share ; file:///srv -> /srv
            if (preg_match('#^/+([A-Za-z]:)#', $p)) $p = ltrim($p, '/');
            elseif (strpos($p, '///') === 0) $p = '/' . ltrim($p, '/');
        }
        $get = function (string $name) use ($env) {
            if ($env !== null) {
                foreach ($env as $k => $v) if (strcasecmp((string)$k, $name) === 0) return (string)$v;
                return null;
            }
            $v = getenv($name);
            return $v === false ? null : $v;
        };
        $p = preg_replace_callback('/%([A-Za-z_][A-Za-z0-9_()]*)%/', function ($m) use ($get) {
            $v = $get($m[1]); return $v === null ? $m[0] : $v;
        }, $p) ?? $p;
        $p = preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)/', function ($m) use ($get) {
            $name = ($m[1] ?? '') !== '' ? $m[1] : $m[2];
            $v = $get($name); return $v === null ? $m[0] : $v;
        }, $p) ?? $p;
        if ($p === '~' || preg_match('#^~[/\\\\]#', $p)) {
            $home = $get('HOME') ?? $get('USERPROFILE');
            if ($home === null && ($get('HOMEDRIVE') !== null)) $home = $get('HOMEDRIVE') . $get('HOMEPATH');
            if ($home !== null && $home !== '') $p = rtrim($home, '/\\') . substr($p, 1);
        }
        $p = str_replace('\\', '/', $p);
        $unc = strpos($p, '//') === 0;
        $p = preg_replace('#/+#', '/', $p) ?? $p;
        if ($unc) $p = '/' . $p;
        if (preg_match('#^[A-Za-z]:$#', $p)) $p .= '/';
        if (preg_match('#^([a-z]):/#', $p, $m)) $p = strtoupper($m[1]) . substr($p, 1);
        if (strlen($p) > 1 && !preg_match('#^[A-Za-z]:/$#', $p)) $p = rtrim($p, '/');
        if ($p === '') return '';
        return $p;
    }

    /** Is $path permitted by open_basedir? (empty setting = everything). */
    public static function allowedByOpenBasedir(string $path, ?string $setting = null, ?string $os = null): bool {
        $setting = $setting ?? (string)ini_get('open_basedir');
        if (trim($setting) === '') return true;
        $win = self::isWindows($os);
        $p = rtrim(str_replace('\\', '/', $path), '/') . '/';
        foreach (explode($win ? ';' : PATH_SEPARATOR, $setting) as $dir) {
            $dir = trim(str_replace('\\', '/', $dir));
            if ($dir === '') continue;
            if ($dir === '.') continue;
            $d = rtrim($dir, '/') . '/';
            if ($win ? stripos($p, $d) === 0 : strpos($p, $d) === 0) return true;
        }
        return false;
    }

    /** Best description of the account PHP runs as, for error messages. */
    public static function serviceAccount(): string {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['name'])) return (string)$pw['name'];
        }
        $user = getenv('USERNAME') ?: getenv('USER');
        if ($user) {
            $domain = self::isWindows() ? (string)getenv('USERDOMAIN') : '';
            // Windows LocalSystem reports the machine account (NAME$).
            if (substr((string)$user, -1) === '$') return 'LocalSystem (the Windows SYSTEM account)';
            return ($domain !== '' && strcasecmp($domain, (string)$user) !== 0 ? $domain . '\\' : '') . $user;
        }
        $owner = get_current_user();
        return $owner !== '' ? $owner : 'the web server account';
    }

    /**
     * Check a folder for use as a library. Returns
     * ['ok'=>bool, 'path'=>normalised/real path, 'reason'=>ok|empty|open_basedir|missing|not_dir|denied, 'message'=>string].
     */
    public static function check(string $raw, ?array $env = null): array {
        $path = self::normalize($raw, $env);
        $out = function (bool $ok, string $reason, string $message, string $p) {
            return ['ok' => $ok, 'path' => $p, 'reason' => $reason, 'message' => $message];
        };
        if ($path === '') return $out(false, 'empty', 'Enter a folder path, or pick one with Browse.', '');
        $win = self::isWindows();
        if (!$win && preg_match('#^[A-Za-z]:/#', $path)) {
            return $out(false, 'missing', 'This server is not Windows, so "' . $path . '" is not a valid path here. Enter the folder as the server sees it (for example /srv/media/Films).', $path);
        }
        if (!self::allowedByOpenBasedir($path)) {
            return $out(false, 'open_basedir', 'PHP is not allowed to read "' . $path . '": it is outside the open_basedir setting in php.ini (' . ini_get('open_basedir') . '). Add the folder to open_basedir, or clear that setting, then restart the Vaultcast service.', $path);
        }
        clearstatcache();
        $account = self::serviceAccount();
        if (!@file_exists($path)) {
            // Walk up to the nearest existing parent: if it cannot be listed, the folder may exist but be hidden from this account.
            $parent = $path;
            while (($up = self::parent($parent)) !== null && $up !== $parent) {
                $parent = $up;
                if (@is_dir($parent)) break;
            }
            if ($parent !== $path && @is_dir($parent) && @opendir($parent) === false) {
                return $out(false, 'denied', 'Access denied: the Vaultcast service runs as ' . $account . ', which cannot open "' . $parent . '", so it cannot see "' . $path . '". Grant that account read access, or move the media to a shared folder (for example C:\\Users\\Public\\Films).', $path);
            }
            $hint = '';
            if ($win && preg_match('#^([D-Z]):/#', $path)) $hint = ' If this is a mapped network drive, services cannot see drive letters mapped by a signed-in user; use its network path (\\\\server\\share\\folder) instead.';
            if ($win && preg_match('#^[A-Za-z]:/Users/[^/]+/#i', $path)) $hint .= ' Folders inside a user profile (Downloads, Videos, Desktop) may be redirected to OneDrive; check the folder\'s real location in Explorer.';
            return $out(false, 'missing', 'Folder not found: "' . $path . '" does not exist on the server.' . $hint, $path);
        }
        if (!@is_dir($path)) return $out(false, 'not_dir', '"' . $path . '" is a file, not a folder. Choose the folder that contains your media.', $path);
        $real = self::real($path);
        $handle = @opendir($path);
        if ($handle === false || !@is_readable($path)) {
            $hint = ($win && preg_match('#^[A-Za-z]:/Users/[^/]+/#i', $path)) ? ' Folders inside another user\'s profile (such as Downloads) are private to that user by default.' : '';
            return $out(false, 'denied', 'Access denied: "' . $path . '" exists, but the Vaultcast service runs as ' . $account . ' and is not allowed to read it.' . $hint . ' Grant that account read access to the folder, or move the media to a shared folder (for example C:\\Users\\Public\\Films).', $real ?: $path);
        }
        closedir($handle);
        return $out(true, 'ok', '', $real ?: $path);
    }

    /**
     * Let the web server (nginx, which serves media itself) read a library folder, via
     * LibraryAccess::grantServiceRead when that service exists (Windows installer builds).
     * Returns ['ok'=>true, 'skipped'=>bool] or ['ok'=>false, 'code'=>'library_acl_failed', 'message'=>...].
     * $grant overrides the call (tests).
     */
    public static function grantWebServerRead(string $path, ?callable $grant = null): array {
        if ($grant === null) {
            if (!\class_exists('LibraryAccess', true) && is_file(__DIR__ . '/Services/LibraryAccess.php')) require_once __DIR__ . '/Services/LibraryAccess.php';
            if (!\class_exists('LibraryAccess', false) || !\method_exists('LibraryAccess', 'grantServiceRead')) return ['ok' => true, 'skipped' => true];
            $grant = ['LibraryAccess', 'grantServiceRead'];
        }
        try {
            $r = $grant($path);
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'code' => 'library_acl_failed', 'error' => $e->getMessage()];
        }
        if (is_array($r) && !empty($r['ok'])) return ['ok' => true, 'skipped' => false];
        $reason = trim((string)(is_array($r) ? ($r['error'] ?? '') : ''));
        if (is_array($r) && ($r['code'] ?? '') === 'library_acl_failed') {
            return ['ok' => false, 'code' => 'library_acl_failed', 'message' => 'The web server (nginx) could not be given read access to "' . $path . '", so media there would not play. Reason: ' . ($reason !== '' ? $reason : 'unknown error.')];
        }
        return ['ok' => false, 'code' => (string)(is_array($r) ? ($r['code'] ?? 'library_acl_failed') : 'library_acl_failed'), 'message' => 'Could not set read access for the web server on "' . $path . '". Reason: ' . ($reason !== '' ? $reason : 'unknown error.')];
    }


    /** Parent folder, or null at a root ("/", "C:/", "//server/share"). */
    public static function parent(string $path): ?string {
        $path = self::normalize($path, []);
        if ($path === '/' || preg_match('#^[A-Za-z]:/$#', $path) || preg_match('#^//[^/]+(/[^/]+)?$#', $path)) return null;
        $up = dirname($path);
        if (preg_match('#^[A-Za-z]:$#', $up)) $up .= '/';
        $up = str_replace('\\', '/', $up);
        return $up === $path || $up === '.' ? null : $up;
    }

    /** Folder picker roots: drive letters on Windows, "/" plus common media mounts elsewhere. */
    public static function roots(): array {
        $roots = [];
        if (self::isWindows()) {
            foreach (range('A', 'Z') as $l) if (self::allowedByOpenBasedir($l . ':/') && @is_dir($l . ':/')) $roots[] = $l . ':/';
            $pub = getenv('PUBLIC') ?: 'C:/Users/Public';
            $pub = self::normalize($pub, []);
            if (@is_dir($pub)) $roots[] = $pub;
        } else {
            $roots[] = '/';
            foreach (['/mnt', '/media', '/srv', '/volume1', '/share'] as $m) if (self::allowedByOpenBasedir($m) && @is_dir($m)) $roots[] = $m;
        }
        $basedir = trim((string)ini_get('open_basedir'));
        if ($basedir !== '') {
            $roots = array_values(array_filter($roots, function ($r) { return self::allowedByOpenBasedir($r); }));
            foreach (explode(self::isWindows() ? ';' : PATH_SEPARATOR, $basedir) as $d) {
                $d = self::normalize($d, []);
                if ($d !== '' && $d !== '.' && @is_dir($d) && !in_array($d, $roots, true)) $roots[] = $d;
            }
        }
        return $roots;
    }

    /** List the subfolders of $raw for the folder picker (no files). Empty path lists roots. */
    public static function browse(string $raw, int $limit = 1000): array {
        if (trim($raw) === '') {
            return ['ok' => true, 'path' => '', 'parent' => null, 'folders' => array_map(function ($r) { return ['name' => $r, 'path' => $r]; }, self::roots()), 'truncated' => false];
        }
        $c = self::check($raw);
        if (!$c['ok']) return ['ok' => false, 'path' => $c['path'], 'reason' => $c['reason'], 'error' => $c['message'], 'parent' => $c['path'] !== '' ? self::parent($c['path']) : null];
        $dir = $c['path'];
        $folders = [];
        $truncated = false;
        $h = @opendir($dir);
        while ($h && ($name = readdir($h)) !== false) {
            if ($name === '.' || $name === '..' || $name === '$RECYCLE.BIN' || $name === 'System Volume Information') continue;
            $full = rtrim($dir, '/') . '/' . $name;
            if (!@is_dir($full)) continue;
            if (count($folders) >= $limit) { $truncated = true; break; }
            $folders[] = ['name' => $name, 'path' => $full];
        }
        if ($h) closedir($h);
        usort($folders, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        return ['ok' => true, 'path' => $dir, 'parent' => self::parent($dir), 'folders' => $folders, 'truncated' => $truncated];
    }
}
