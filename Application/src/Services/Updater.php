<?php
declare(strict_types=1);

/**
 * In-app updater (admin only), file by file, with no build step. Plain PHP: curl or streams.
 *
 *  1. check():  one GitHub Git Trees API call (recursive) for main on OmniChief/Vaultcast gives
 *               every file's path and git blob SHA. Local files are hashed the same way
 *               (sha1("blob <len>\0" . content)) to list the allowlisted files that changed, were
 *               added, or were removed upstream, plus the remote VERSION. A CRLF copy of an LF file
 *               (git autocrlf, editors) counts as unchanged.
 *  2. apply():  downloads only those files (raw.githubusercontent.com, falling back to the blobs
 *               API) and verifies each download's blob SHA against the tree; that is the integrity
 *               check. Nothing is changed unless every file downloaded and verified. The swap then
 *               backs up each replaced file, writes atomically (temp file + rename, retried for
 *               Windows sharing violations), runs under maintenance mode, and rolls everything back
 *               on any failure. Database migrations run through the idempotent version-keyed startup
 *               pattern (Migrations::runIfNeeded) on the next request.
 *
 * Files are written exactly as they are in the repository (byte for byte; the blob SHA proves it).
 * Never touched: data/ (config, runtime, caches, uploads, Windows runtimes, Transmission),
 * config.php, the database, media folders, netplay/node_modules, and anything off the allowlist.
 */
final class Updater
{
    const REPO = 'OmniChief/Vaultcast';
    const BRANCH = 'main';
    const API = 'https://api.github.com/repos/' . self::REPO;
    const RAW = 'https://raw.githubusercontent.com/' . self::REPO;
    /** Repository folder that holds the deployable app. */
    const APP_PREFIX = 'Application/';
    /** Repository-root files that are installed at the app root. */
    const REPO_ROOT_FILES = ['LICENSE', 'NOTICE'];
    const MAX_FILE_BYTES = 64 * 1024 * 1024;
    /** How long a stored tree may stand in when GitHub's rate limit is exhausted. */
    const STALE_TREE_SECONDS = 6 * 3600;

    /** Core files at the app root that an update may replace. */
    const ROOT_FILES = ['index.php', 'router.php', 'sw.js', 'manifest.json', '.htaccess', 'VERSION', 'LICENSE', 'NOTICE'];
    /** Core directories (recursively) that an update may replace. */
    const ROOT_DIRS = ['src', 'assets', 'docs', 'deploy', 'netplay', 'tests'];
    /** Directories whose files that no longer exist upstream are removed. Not deploy/ (local nginx
     *  copies live there) and not netplay/ (npm state). */
    const PRUNE_DIRS = ['src', 'assets', 'docs', 'tests'];
    /** Updated only where the install already has them (the Linux script installs no tests/). */
    const OPTIONAL_DIRS = ['tests'];
    /** Never written, whatever the repository contains. */
    const DENY = ['#(^|/)node_modules(/|$)#', '#(^|/)\.git(/|$)#', '#^data(/|$)#', '#(^|/)config\.php$#i', '#^\.install-#', '#(^|/)\.user\.ini$#i', '#(^|/)php\.ini$#i', '#\.(vc|rk)-(new|old)-[0-9a-f]+$#'];

    /** @var string|null app root override (tests) */
    public static $root = null;
    /** @var string|null GitHub token override (tests). See token(). */
    public static $token = null;
    /** Last HTTP status and response headers (lower-case names) seen by request(). */
    public static $lastStatus = 0;
    public static $lastHeaders = [];
    /** @var callable|null fn(string $url, array $headers): array{status:int, body:?string, headers?:array}|string|null  (tests) */
    public static $http = null;
    /** @var callable|null fn(string $relative): void, called before each file swap (tests inject failures) */
    public static $beforeReplace = null;
    /** Windows: how often to retry a rename blocked by another process (sharing violation). */
    public static $retries = 25;
    public static $retryDelayUs = 200000;

    // ------------------------------------------------------------------ paths & version

    public static function root(): string { return rtrim(str_replace('\\', '/', self::$root ?? dirname(__DIR__, 2)), '/'); }
    public static function dataDir(): string { return self::root() . '/data'; }
    public static function workDir(): string { return self::dataDir() . '/updates'; }
    public static function maintenanceFile(): string { return self::dataDir() . '/runtime/maintenance.json'; }

    public static function currentVersion(): string
    {
        $v = trim((string)@file_get_contents(self::root() . '/VERSION'));
        return self::parseVersion($v) ? $v : '0.0.0';
    }

    /** Git branch names, never arbitrary refs, URLs or expressions. */
    public static function validBranch(string $branch): bool
    {
        if ($branch === '' || strlen($branch) > 255 || $branch === '@' || $branch[0] === '-'
            || preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $branch)
            || strpos($branch, '..') !== false || strpos($branch, '@{') !== false) return false;
        foreach (explode('/', $branch) as $part) {
            if ($part === '' || $part[0] === '.' || substr($part, -1) === '.' || substr($part, -5) === '.lock') return false;
        }
        return true;
    }

    /** Updated transactionally with the files; never fall back when a pinned branch disappears. */
    public static function currentBranch(): string
    {
        $file = self::workDir() . '/channel.json';
        if (!is_file($file)) return self::BRANCH;
        $state = json_decode((string)@file_get_contents($file), true);
        $branch = (string)($state['branch'] ?? '');
        if (!self::validBranch($branch)) throw new RuntimeException('The saved update branch is invalid. Restore data/updates/channel.json before checking for updates.');
        return $branch;
    }

    /** Discover every branch, with per-page ETags. A failed listing never changes the pin. */
    public static function branches(): array
    {
        $names = [self::BRANCH];
        for ($page = 1; ; $page++) {
            if ($page > 100) return ['branches' => array_values(array_unique($names)), 'branches_error' => 'Too many branches to list completely. Check again later.'];
            $cacheFile = self::workDir() . '/branches-' . $page . '.json';
            $cache = json_decode((string)@file_get_contents($cacheFile), true);
            $headers = ['Accept: application/vnd.github+json'];
            if (is_array($cache) && !empty($cache['etag']) && ($cache['token'] ?? false) === (self::token() !== null)) $headers[] = 'If-None-Match: ' . $cache['etag'];
            $r = self::request(self::API . '/branches?per_page=100&page=' . $page, $headers);
            $body = $r['status'] === 304 ? ($cache['body'] ?? null) : $r['body'];
            $rows = is_string($body) ? json_decode($body, true) : null;
            if (!in_array($r['status'], [200, 304], true) || !is_array($rows) || array_values($rows) !== $rows) {
                return ['branches' => array_values(array_unique($names)), 'branches_error' => 'Could not refresh the branch list from GitHub. The installed branch remains pinned.'];
            }
            if ($r['status'] === 200 && (is_dir(self::workDir()) || @mkdir(self::workDir(), 0770, true))) {
                @file_put_contents($cacheFile, json_encode(['etag' => $r['headers']['etag'] ?? '', 'body' => $body, 'token' => self::token() !== null]), LOCK_EX);
            }
            foreach ($rows as $row) if (is_array($row) && self::validBranch((string)($row['name'] ?? ''))) $names[] = $row['name'];
            if (count($rows) < 100) break;
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);
        $names = array_values(array_diff($names, [self::BRANCH]));
        array_unshift($names, self::BRANCH);
        return ['branches' => $names, 'branches_error' => null];
    }

    /** "v1.2.3" / "1.2.3" -> [1,2,3]; anything else -> null. */
    public static function parseVersion(string $tag): ?array
    {
        return preg_match('/^v?(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})$/', trim($tag), $m) ? [(int)$m[1], (int)$m[2], (int)$m[3]] : null;
    }

    public static function compare(string $a, string $b): int
    {
        $x = self::parseVersion($a) ?? [0, 0, 0]; $y = self::parseVersion($b) ?? [0, 0, 0];
        return $x <=> $y;
    }

    // ------------------------------------------------------------------ hashing

    /** Git blob SHA-1 of $content: sha1("blob <len>\0" . content). */
    public static function blobSha(string $content): string
    {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    /** Does the local file hold the blob $sha? Also accepts a CRLF copy of an LF blob. */
    public static function localMatches(string $file, string $sha): bool
    {
        $c = @file_get_contents($file);
        if ($c === false) return false;
        if (hash_equals($sha, self::blobSha($c))) return true;
        return strpos($c, "\r\n") !== false && hash_equals($sha, self::blobSha(str_replace("\r\n", "\n", $c)));
    }

    // ------------------------------------------------------------------ allowlist

    /** True if a path is safe to write (relative, no traversal, no drive letters). */
    public static function safeEntry(string $path): bool
    {
        $p = str_replace('\\', '/', $path);
        if ($p === '' || $p[0] === '/' || preg_match('#^[A-Za-z]:#', $p) || strpos($p, "\0") !== false) return false;
        foreach (explode('/', $p) as $seg) if ($seg === '..' || $seg === '.' || $seg === '') return false;
        return true;
    }

    /** Is this app-relative path an updatable core file? */
    public static function allowed(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (!self::safeEntry($rel)) return false;
        foreach (self::DENY as $re) if (preg_match($re, $rel)) return false;
        if (in_array($rel, self::ROOT_FILES, true)) return true;
        $top = explode('/', $rel, 2)[0];
        return strpos($rel, '/') !== false && in_array($top, self::ROOT_DIRS, true);
    }

    /** Repository path -> app-relative path, or null when it is not an updatable app file. */
    public static function appPath(string $repoPath): ?string
    {
        if (strpos($repoPath, self::APP_PREFIX) === 0) $rel = substr($repoPath, strlen(self::APP_PREFIX));
        elseif (in_array($repoPath, self::REPO_ROOT_FILES, true)) $rel = $repoPath;
        else return null;
        return self::allowed($rel) ? $rel : null;
    }

    // ------------------------------------------------------------------ check

    /**
     * Remote state of main: [ok, tree, files => [rel => [path, sha, size]], note?, error?].
     * Uses ETag/If-None-Match (a 304 does not count against GitHub's rate limit) and falls back to
     * the last stored tree when the hourly limit is exhausted.
     */
    public static function remoteTree(?string $branch = null): array
    {
        $branch = $branch ?? self::currentBranch();
        if (!self::validBranch($branch)) return ['ok' => false, 'error' => 'Invalid update branch.'];
        $cacheFile = self::workDir() . '/tree-' . hash('sha256', $branch) . '.json';
        $cache = json_decode((string)@file_get_contents($cacheFile), true);
        $cache = is_array($cache) && isset($cache['body'], $cache['fetched']) ? $cache : null;
        $headers = ['Accept: application/vnd.github+json'];
        if ($cache && !empty($cache['etag']) && ($cache['token'] ?? false) === (self::token() !== null)) $headers[] = 'If-None-Match: ' . $cache['etag'];
        // refs/heads prevents a branch called, for example, HEAD from resolving as a special ref.
        $r = self::request(self::API . '/git/trees/' . rawurlencode('refs/heads/' . $branch) . '?recursive=1', $headers);
        $note = null;
        if ($r['status'] === 304 && $cache) {
            $body = (string)$cache['body'];
            $cache['fetched'] = time();
            @file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
        } elseif ($r['status'] === 200 && $r['body'] !== null) {
            $body = $r['body'];
            if (is_dir(self::workDir()) || @mkdir(self::workDir(), 0770, true)) {
                @file_put_contents($cacheFile, json_encode(['etag' => $r['headers']['etag'] ?? '', 'body' => $body, 'fetched' => time(), 'token' => self::token() !== null]), LOCK_EX);
            }
        } else {
            $limited = in_array($r['status'], [403, 429], true) && (($r['headers']['x-ratelimit-remaining'] ?? '') === '0' || $r['status'] === 429);
            if ($limited) {
                $reset = (int)($r['headers']['x-ratelimit-reset'] ?? 0);
                $when = $reset > time() ? ' Try again after ' . date('H:i', $reset) . '.' : ' Try again in an hour.';
                $msg = 'GitHub\'s hourly limit for update checks has been used up (60 an hour without a token, shared by everything on this network).' . $when
                    . ' A read-only token in data/config.php as \'update_token\' raises the limit.';
                if ($cache && time() - (int)$cache['fetched'] < self::STALE_TREE_SECONDS) {
                    $body = (string)$cache['body'];
                    $note = $msg . ' Showing the result from ' . date('H:i', (int)$cache['fetched']) . '.';
                } else {
                    return ['ok' => false, 'error' => $msg, 'rate_limited' => true];
                }
            } elseif ($r['status'] === 404) {
                return ['ok' => false, 'code' => 'branch_unavailable', 'error' => 'GitHub answered 404 for branch "' . $branch . '". It may have been deleted, or the repository is private. The installed branch stays pinned; choose main explicitly to return to it, or check update_token access.'];
            } elseif ($r['status'] === 401 || $r['status'] === 403) {
                return ['ok' => false, 'error' => 'GitHub refused the request (HTTP ' . $r['status'] . '). Check the update_token, or try again later.'];
            } else {
                return ['ok' => false, 'error' => 'Could not reach GitHub to check for updates' . ($r['status'] ? ' (HTTP ' . $r['status'] . ').' : '.')];
            }
        }
        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['tree']) || !is_array($j['tree'])) return ['ok' => false, 'error' => 'GitHub returned an unexpected response.'];
        if (!empty($j['truncated'])) return ['ok' => false, 'error' => 'GitHub truncated the file list, so the update cannot be planned safely.'];
        $files = [];
        foreach ($j['tree'] as $e) {
            if (!is_array($e) || ($e['type'] ?? '') !== 'blob' || !in_array((string)($e['mode'] ?? ''), ['100644', '100755'], true)) continue; // no symlinks/submodules
            $sha = strtolower((string)($e['sha'] ?? ''));
            if (!preg_match('/^[0-9a-f]{40}$/', $sha)) continue;
            $rel = self::appPath((string)($e['path'] ?? ''));
            if ($rel !== null) $files[$rel] = ['path' => (string)$e['path'], 'sha' => $sha, 'size' => (int)($e['size'] ?? 0)];
        }
        if (!isset($files['VERSION'], $files['index.php'], $files['src/bootstrap.php'])) return ['ok' => false, 'error' => 'The repository does not look like a Vaultcast application (VERSION, index.php or src/bootstrap.php missing).'];
        ksort($files);
        return ['ok' => true, 'tree' => (string)($j['sha'] ?? ''), 'files' => $files, 'note' => $note];
    }

    /**
     * Compare local files with main.
     * @return array{ok:bool, current:string, latest?:array, update_available?:bool, error?:string}
     */
    public static function check(?string $branch = null): array
    {
        $current = self::currentVersion();
        try { $installedBranch = self::currentBranch(); }
        catch (Throwable $e) { return ['ok' => false, 'current' => $current, 'error' => $e->getMessage()]; }
        $branch = $branch ?? $installedBranch;
        if (!self::validBranch($branch)) return ['ok' => false, 'current' => $current, 'error' => 'Invalid update branch.'];
        $context = ['current_branch' => $installedBranch, 'selected_branch' => $branch] + self::branches();
        $t = self::remoteTree($branch);
        if (!$t['ok']) return $context + ['ok' => false, 'current' => $current, 'error' => $t['error'], 'code' => $t['code'] ?? null, 'rate_limited' => !empty($t['rate_limited'])];
        $root = self::root();
        $changed = []; $added = []; $bytes = 0;
        foreach ($t['files'] as $rel => $f) {
            $local = $root . '/' . $rel;
            if (in_array(explode('/', $rel, 2)[0], self::OPTIONAL_DIRS, true) && !is_dir($root . '/' . explode('/', $rel, 2)[0])) continue;
            if (!is_file($local)) { $added[] = $rel; $bytes += $f['size']; }
            elseif (!self::localMatches($local, $f['sha'])) { $changed[] = $rel; $bytes += $f['size']; }
        }
        $removed = [];
        foreach (self::PRUNE_DIRS as $dir) {
            foreach (self::localFiles($dir) as $rel) if (!isset($t['files'][$rel]) && self::allowed($rel)) $removed[] = $rel;
        }
        sort($removed);
        // Remote VERSION: free when unchanged; otherwise one raw download (not rate-limited), verified.
        $remoteVersion = $current;
        if (in_array('VERSION', $changed, true) || in_array('VERSION', $added, true)) {
            $v = self::fetchBlob($t['files']['VERSION'], $why, $branch);
            $remoteVersion = $v !== null && self::parseVersion(trim($v)) ? trim($v) : '?';
        }
        $count = count($changed) + count($added) + count($removed);
        return $context + ['ok' => true, 'current' => $current, 'update_available' => $count > 0 || $branch !== $installedBranch, 'branch_change' => $branch !== $installedBranch, 'latest' => [
            'version' => $remoteVersion, 'branch' => $branch, 'tree' => $t['tree'],
            'changed' => $changed, 'added' => $added, 'removed' => $removed, 'count' => $count, 'bytes' => $bytes,
            'url' => 'https://github.com/' . self::REPO . '/commits/' . rawurlencode($branch),
        ], 'note' => $t['note'], '_files' => $t['files']];
    }

    /** App-relative paths of files under one app directory. */
    private static function localFiles(string $dir): array
    {
        $abs = self::root() . '/' . $dir; $out = [];
        if (!is_dir($abs)) return $out;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $out[] = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen(self::root())), '/');
        return $out;
    }

    /** Download one blob and return its bytes only if they hash to the tree's blob SHA. */
    public static function fetchBlob(array $f, ?string &$why = null, ?string $branch = null): ?string
    {
        $why = 'download';
        $branch = $branch ?? self::currentBranch();
        if (!self::validBranch($branch)) return null;
        $raw = self::RAW . '/refs/heads/' . rawurlencode($branch) . '/' . implode('/', array_map('rawurlencode', explode('/', $f['path'])));
        $r = self::request($raw, []);
        if ($r['status'] === 200 && $r['body'] !== null) {
            if (hash_equals($f['sha'], self::blobSha($r['body']))) return $r['body'];
            $why = 'mismatch';
        }
        // raw.githubusercontent.com caches branch paths for a few minutes, so a fresh push can
        // briefly serve the previous version: ask the blobs API for this exact SHA instead.
        $r = self::request(self::API . '/git/blobs/' . $f['sha'], ['Accept: application/vnd.github.raw']);
        if ($r['status'] === 200 && $r['body'] !== null) {
            if (hash_equals($f['sha'], self::blobSha($r['body']))) return $r['body'];
            $why = 'mismatch';
        }
        return null;
    }

    // ------------------------------------------------------------------ apply

    /**
     * @param array|null  $check   a check() result to reuse (the route passes the one it just made)
     * @param string|null $expectTree  refuse if main moved since the admin looked
     * @return array{ok:bool, from?:string, to?:string, replaced?:int, created?:int, removed?:int, error?:string, rolled_back?:bool}
     */
    public static function apply(?array $check = null, ?string $expectTree = null, ?string $branch = null): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $from = self::currentVersion();
        if (!is_dir(self::workDir()) && !@mkdir(self::workDir(), 0770, true)) return ['ok' => false, 'error' => 'Cannot create data/updates.'];
        $lock = @fopen(self::workDir() . '/update.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => false, 'error' => 'Another update is already running.'];
        $staging = null;
        try {
            if (self::recoverInterrupted() !== null) $check = null; // files changed under us: re-plan
            $branch = $branch ?? ($check['latest']['branch'] ?? self::currentBranch());
            if (!self::validBranch($branch)) return ['ok' => false, 'error' => 'Invalid update branch.'];
            $c = $check ?? self::check($branch);
            if (!$c['ok']) return ['ok' => false, 'error' => $c['error'] ?? 'Update check failed.'];
            if (($c['current_branch'] ?? self::BRANCH) !== self::currentBranch()) return ['ok' => false, 'error' => 'The installed branch changed. Check again.', 'code' => 'changed'];
            $l = $c['latest'];
            if (($l['branch'] ?? '') !== $branch) return ['ok' => false, 'error' => 'The checked branch does not match the requested branch. Check again.', 'code' => 'changed'];
            if ($expectTree !== null && $expectTree !== '' && $expectTree !== $l['tree']) return ['ok' => false, 'error' => 'Vaultcast changed on GitHub since you checked. Check again before updating.', 'code' => 'changed'];
            if (empty($c['update_available'])) return ['ok' => false, 'error' => 'Vaultcast ' . $from . ' is already up to date.'];
            $files = $c['_files'];

            // 1. Download every changed/new file into staging and verify its blob SHA. Nothing on
            //    disk outside data/updates changes unless all of them verify.
            $staging = self::workDir() . '/staging-' . bin2hex(random_bytes(4));
            $write = [];
            foreach (array_merge($l['changed'], $l['added']) as $rel) {
                if (!isset($files[$rel]) || !self::allowed($rel)) throw new RuntimeException('Refusing to write ' . $rel . ' (not on the allowlist).');
                $body = self::fetchBlob($files[$rel], $why, $branch);
                if ($body === null) {
                    throw new RuntimeException($why === 'mismatch'
                        ? 'Integrity check failed for ' . $rel . ': the download does not match the blob SHA in the GitHub tree. Nothing was changed.'
                        : 'Could not download ' . $rel . (self::$lastStatus ? ' (HTTP ' . self::$lastStatus . ')' : '') . '. Nothing was changed.');
                }
                $dst = $staging . '/' . $rel;
                if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0770, true)) throw new RuntimeException('Cannot create the staging folder.');
                if (@file_put_contents($dst, $body) !== strlen($body)) throw new RuntimeException('Cannot write the staging copy of ' . $rel . ' (disk full?). Nothing was changed.');
                $write[$rel] = $dst;
            }
            $remove = array_values(array_filter($l['removed'], [self::class, 'allowed']));

            // 2. Can the web server write the app folder? (Script installs may make it read-only.)
            foreach (array_merge(array_keys($write), $remove) as $rel) {
                if (!self::writable(self::root() . '/' . $rel)) {
                    return ['ok' => false, 'error' => 'The web server cannot write ' . $rel . '. Make the application folder writable by the web server user for the update (for example: sudo chown -R ' . self::processUser() . ' ' . self::root() . '), then try again. Nothing was changed.'];
                }
            }

            // 3. Swap with backup + journal + maintenance mode. VERSION last: a half-applied update
            //    never claims the new version.
            if (isset($write['VERSION'])) { $v = $write['VERSION']; unset($write['VERSION']); $write['VERSION'] = $v; }
            // Only this internally generated state may enter data/: remote files remain denylisted.
            $channel = $staging . '/channel.json';
            if (!is_dir($staging) && !@mkdir($staging, 0770, true)) throw new RuntimeException('Cannot create staging folder.');
            $channelBody = json_encode(['branch' => $branch, 'tree' => $l['tree']], JSON_UNESCAPED_SLASHES);
            if (@file_put_contents($channel, $channelBody) !== strlen($channelBody)) throw new RuntimeException('Cannot stage the update branch.');
            $write['data/updates/channel.json'] = $channel;
            $to = $l['version'] !== '?' ? $l['version'] : $from;
            $result = self::swap(['write' => $write, 'remove' => $remove], $from, $to);
            return ['ok' => true, 'from' => $from, 'to' => self::currentVersion(), 'branch' => $branch, 'tree' => $l['tree']] + $result;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'rolled_back' => $e instanceof UpdaterRolledBack];
        } finally {
            if ($staging !== null) self::rmTree($staging);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Name of the user this PHP process runs as (for error hints); PHP 7.4-safe with fallbacks. */
    public static function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['name'])) return (string)$pw['name'];
        }
        if (function_exists('posix_geteuid')) return (string)posix_geteuid(); // numeric uid works with chown
        $u = (string)getenv(PHP_OS_FAMILY === 'Windows' ? 'USERNAME' : 'USER');
        return $u !== '' ? $u : 'the-php-fpm-user';
    }

    /** A file (or, if absent, its nearest existing folder) is writable. */
    private static function writable(string $path): bool
    {
        if (file_exists($path)) return is_writable($path) && is_writable(dirname($path));
        $d = dirname($path);
        while (!is_dir($d) && dirname($d) !== $d) $d = dirname($d);
        return is_writable($d);
    }

    // ------------------------------------------------------------------ swap / rollback

    private static function swap(array $plan, string $from, string $to): array
    {
        $root = self::root();
        $backup = self::workDir() . '/backup-' . $from . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        if (!@mkdir($backup . '/files', 0770, true)) throw new RuntimeException('Cannot create the backup folder.');
        $journal = ['from' => $from, 'to' => $to, 'state' => 'swapping', 'started' => date('c'), 'replaced' => [], 'created' => [], 'removed' => []];
        $save = function () use (&$journal, $backup) { @file_put_contents($backup . '/journal.json', json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX); };
        $save();
        self::maintenance(true, $to, $backup);
        try {
            foreach ($plan['write'] as $rel => $src) {
                if (self::$beforeReplace) (self::$beforeReplace)($rel);
                $dst = $root . '/' . $rel;
                if (is_file($dst)) {
                    if (is_file($src) && hash_file('sha256', $src) === hash_file('sha256', $dst)) continue; // unchanged
                    self::copyTo($dst, $backup . '/files/' . $rel);
                    $journal['replaced'][] = $rel;
                } else {
                    $journal['created'][] = $rel;
                }
                $save();
                self::place($src, $dst);
            }
            foreach ($plan['remove'] as $rel) {
                if (self::$beforeReplace) (self::$beforeReplace)($rel);
                $dst = $root . '/' . $rel;
                self::copyTo($dst, $backup . '/files/' . $rel);
                $journal['removed'][] = $rel; $save();
                if (!self::retry(function () use ($dst) { return @unlink($dst) || !file_exists($dst); })) throw new RuntimeException('Cannot remove ' . $rel . ' (file in use?).');
            }
            $journal['state'] = 'done'; $journal['finished'] = date('c'); $save();
        } catch (Throwable $e) {
            $problems = self::rollback($journal, $backup);
            $journal['state'] = 'rolled_back'; $journal['error'] = $e->getMessage(); $journal['rollback_problems'] = $problems; $save();
            self::maintenance(false);
            throw new UpdaterRolledBack('Update failed and was rolled back: ' . $e->getMessage() . ($problems ? ' (could not restore: ' . implode(', ', $problems) . ')' : ''));
        }
        // Preserve completed migrations and prepared library data. A changed schema version
        // has its own durable stamp and runs only the migrations that upgrade requires.
        if (function_exists('opcache_reset')) @opcache_reset();
        self::maintenance(false);
        self::pruneBackups(3);
        return ['replaced' => count(array_diff($journal['replaced'], ['data/updates/channel.json'])), 'created' => count(array_diff($journal['created'], ['data/updates/channel.json'])), 'removed' => count($journal['removed']),
            'backup' => substr($backup, strlen(self::root()) + 1)];
    }

    /** Undo a journal: restore replaced/removed files from backup, delete files the update created. */
    private static function rollback(array $journal, string $backup): array
    {
        $root = self::root(); $problems = [];
        foreach (array_reverse($journal['created']) as $rel) {
            if (!self::retry(function () use ($root, $rel) { return @unlink($root . '/' . $rel) || !file_exists($root . '/' . $rel); })) $problems[] = $rel;
        }
        foreach (array_merge($journal['replaced'], $journal['removed']) as $rel) {
            try { self::place($backup . '/files/' . $rel, $root . '/' . $rel); } catch (Throwable $e) { $problems[] = $rel; }
        }
        if (function_exists('opcache_reset')) @opcache_reset();
        return $problems;
    }

    /** A swap interrupted by a crash or power loss leaves a 'swapping' journal: roll it back. */
    public static function recoverInterrupted(): ?string
    {
        foreach (glob(self::workDir() . '/backup-*/journal.json') ?: [] as $jf) {
            $j = json_decode((string)@file_get_contents($jf), true);
            if (!is_array($j) || ($j['state'] ?? '') !== 'swapping') continue;
            $problems = self::rollback($j, dirname($jf));
            $j['state'] = 'rolled_back'; $j['error'] = 'Interrupted update rolled back.'; $j['rollback_problems'] = $problems;
            @file_put_contents($jf, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            self::maintenance(false);
            return (string)($j['from'] ?? '');
        }
        return null;
    }

    /**
     * Copy $src to $dst via a temp file + rename (atomic on POSIX). On Windows a file another
     * process holds open makes rename fail with a sharing violation, so retry with a short backoff;
     * as a last resort move the busy file aside (Windows allows renaming most open files) and retry.
     */
    private static function place(string $src, string $dst): void
    {
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create ' . $dir);
        $tmp = $dst . '.vc-new-' . bin2hex(random_bytes(3));
        if (!@copy($src, $tmp)) throw new RuntimeException('Cannot write ' . $dst . ' (permissions or disk full?).');
        $ok = self::retry(function () use ($tmp, $dst) { return @rename($tmp, $dst); });
        if (!$ok && file_exists($dst)) {
            $aside = $dst . '.vc-old-' . bin2hex(random_bytes(3));
            if (@rename($dst, $aside)) {
                $ok = self::retry(function () use ($tmp, $dst) { return @rename($tmp, $dst); });
                if ($ok) @unlink($aside); else @rename($aside, $dst);
            }
        }
        if (!$ok) { @unlink($tmp); throw new RuntimeException('Cannot replace ' . $dst . ' (file in use?).'); }
    }

    private static function copyTo(string $src, string $dst): void
    {
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0770, true);
        if (!@copy($src, $dst)) throw new RuntimeException('Cannot back up ' . $src);
    }

    private static function retry(callable $op): bool
    {
        for ($i = 0; $i <= self::$retries; $i++) {
            if ($op()) return true;
            clearstatcache();
            if ($i < self::$retries) usleep(self::$retryDelayUs);
        }
        return false;
    }

    // ------------------------------------------------------------------ maintenance mode

    public static function maintenance(bool $on, string $to = '', string $backup = ''): void
    {
        $f = self::maintenanceFile();
        if (!$on) { @unlink($f); return; }
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0770, true);
        @file_put_contents($f, json_encode(['since' => time(), 'to' => $to, 'backup' => basename($backup)]), LOCK_EX);
    }

    /** Active maintenance flag (ignored after 15 minutes so a crashed update cannot lock the site out). */
    public static function inMaintenance(?string $file = null): bool
    {
        $file = $file ?? self::maintenanceFile();
        if (!is_file($file)) return false;
        $j = json_decode((string)@file_get_contents($file), true);
        return is_array($j) && (time() - (int)($j['since'] ?? 0)) < 900;
    }

    private static function pruneBackups(int $keep): void
    {
        $dirs = glob(self::workDir() . '/backup-*', GLOB_ONLYDIR) ?: [];
        usort($dirs, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
        foreach (array_slice($dirs, $keep) as $d) self::rmTree($d);
    }

    public static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ HTTP

    /**
     * Optional read-only GitHub token (private repository, or a higher rate limit):
     * env VAULTCAST_UPDATE_TOKEN, or 'update_token' in data/config.php. Never stored in the database.
     */
    public static function token(): ?string
    {
        if (self::$token !== null) return self::$token !== '' ? self::$token : null;
        $t = (string)getenv('VAULTCAST_UPDATE_TOKEN');
        if ($t === '' && class_exists('\\Vaultcast\\Database', false)) $t = (string)(\Vaultcast\Database::config()['update_token'] ?? '');
        return preg_match('/^[A-Za-z0-9_]{20,255}$/', $t) ? $t : null;
    }

    /** GET over HTTPS. @return array{status:int, body:?string, headers:array<string,string>} */
    public static function request(string $url, array $headers): array
    {
        self::$lastStatus = 0; self::$lastHeaders = [];
        if (self::$http) {
            $r = (self::$http)($url, $headers);
            $r = is_array($r) ? $r + ['status' => 200, 'body' => null, 'headers' => []] : ['status' => $r === null ? 404 : 200, 'body' => $r, 'headers' => []];
            self::$lastStatus = (int)$r['status']; self::$lastHeaders = (array)$r['headers'];
            return $r;
        }
        if (!preg_match('#^https://(api\.github\.com|raw\.githubusercontent\.com)/#i', $url)) return ['status' => 0, 'body' => null, 'headers' => []];
        $headers[] = 'User-Agent: Vaultcast-Updater/' . self::currentVersion();
        $token = self::token();
        if ($token) { $headers[] = 'Authorization: Bearer ' . $token; $headers[] = 'X-GitHub-Api-Version: 2022-11-28'; }
        $resp = [];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers,
                // Never follow redirects with a token attached (it must not reach another host).
                CURLOPT_FOLLOWLOCATION => !$token, CURLOPT_MAXREDIRS => 3, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$resp) {
                    if (preg_match('#^HTTP/\S+\s+\d#', $line)) $resp = [];
                    if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $resp[strtolower(trim($k))] = trim($v); }
                    return strlen($line);
                },
                CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => function ($c, $dlTotal, $dl) { return $dl > self::MAX_FILE_BYTES ? 1 : 0; }];
            $ca = self::caBundle(); if ($ca) $opts[CURLOPT_CAINFO] = $ca;
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $body = $body === false ? null : (string)$body;
        } else {
            if (!ini_get('allow_url_fopen')) return ['status' => 0, 'body' => null, 'headers' => []];
            $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'follow_location' => $token ? 0 : 1, 'max_redirects' => 3, 'timeout' => 60, 'ignore_errors' => true],
                'ssl' => array_filter(['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => self::caBundle()])]);
            $in = @fopen($url, 'rb', false, $ctx);
            $status = 0; $body = null;
            foreach ((array)($http_response_header ?? []) as $hl) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hl, $m)) { $status = (int)$m[1]; $resp = []; }
                elseif (strpos($hl, ':') !== false) { [$k, $v] = explode(':', $hl, 2); $resp[strtolower(trim($k))] = trim($v); }
            }
            if ($in) { $b = stream_get_contents($in, self::MAX_FILE_BYTES + 1); fclose($in); $body = $b === false || strlen($b) > self::MAX_FILE_BYTES ? null : $b; }
        }
        self::$lastStatus = $status; self::$lastHeaders = $resp;
        return ['status' => $status, 'body' => $status >= 200 && $status < 300 ? $body : null, 'headers' => $resp];
    }

    /** Bundled CA file on Windows installs (curl.se cacert.pem next to PHP) when PHP has none configured. */
    private static function caBundle(): ?string
    {
        if (ini_get('curl.cainfo') || ini_get('openssl.cafile')) return null;
        foreach ([dirname(PHP_BINARY) . '/extras/ssl/cacert.pem', dirname(PHP_BINARY) . '/cacert.pem', dirname(PHP_BINARY, 2) . '/cacert.pem'] as $f) if (is_file($f)) return $f;
        return null;
    }
}

final class UpdaterRolledBack extends RuntimeException {}
