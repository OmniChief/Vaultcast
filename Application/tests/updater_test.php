<?php
/** File-by-file updater against a fake GitHub tree + fake files. php Application/tests/updater_test.php */
declare(strict_types=1);
require __DIR__ . '/../src/Services/Updater.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$base = sys_get_temp_dir() . '/rk-updater-test-' . getmypid();
@mkdir($base, 0775, true);
Updater::$retries = 1; Updater::$retryDelayUs = 1000; Updater::$token = '';

function put(string $f, string $c): void { if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true); file_put_contents($f, $c); }
/** sha256 of every file under $dir except the updater's own work folder, to prove "nothing changed". */
function tree(string $dir): array {
    $o = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && strpos(str_replace('\\', '/', $f->getPathname()), '/data/updates/') === false) $o[ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($dir)), '/')] = hash_file('sha256', $f->getPathname());
    ksort($o); return $o;
}
/** An installed 1.0.0 app as the Windows installer lays it down (app root = install dir, runtimes under data/). */
function installed(string $root): void {
    Updater::rmTree($root);
    put("$root/VERSION", "1.0.0\n");
    put("$root/index.php", "<?php // old index\n");
    put("$root/sw.js", "// old sw\n");
    put("$root/src/bootstrap.php", "<?php // bootstrap\n");
    put("$root/src/Services/Old.php", "<?php // removed upstream\n");
    put("$root/src/Services/Same.php", "<?php\n// unchanged\n");
    put("$root/src/Services/Crlf.php", "<?php\r\n// same content, CRLF checkout\r\n");
    put("$root/assets/css/app.css", "/* old */\n");
    put("$root/deploy/nginx.conf", "# admin's own copy: never pruned\n");
    put("$root/netplay/node_modules/socket.io/index.js", "// installed dependency\n");
    put("$root/data/config.php", "<?php return ['database' => 'SECRET'];");
    put("$root/data/runtime/transmission.ini", "[transmission]\npassword=keep");
    put("$root/data/runtime/php/php.ini", "extension=curl");
    put("$root/data/cache/migrations-v1-app1.0.0.done", "stamp");
    put("$root/data/posters/a.jpg", "jpeg");
}
/** The fake repository main: repo paths => exact bytes. */
function repo(array $extra = []): array {
    return array_replace([
        'Application/VERSION' => "1.1.0\n", 'Application/index.php' => "<?php // new index\n", 'Application/sw.js' => "// old sw\n",
        'Application/src/bootstrap.php' => "<?php // bootstrap\n", 'Application/src/Services/New.php' => "<?php // added\n",
        'Application/src/Services/Same.php' => "<?php\n// unchanged\n", 'Application/src/Services/Crlf.php' => "<?php\n// same content, CRLF checkout\n",
        'Application/assets/css/app.css' => "/* new */\n", 'Application/assets/img/logo.png' => "\x89PNG\r\n\x1a\n\0binary\r\n",
        'LICENSE' => "PolyForm\n", 'NOTICE' => "Notice\n", 'README.md' => "not part of the app\n",
        // must never be written:
        'Application/data/config.php' => "<?php return ['database' => 'HACKED'];", 'Application/config.php' => "<?php // hostile",
        'Application/netplay/node_modules/socket.io/index.js' => "// hostile dependency", 'Application/extra/tool.php' => "<?php // off the allowlist",
        'Application/data/runtime/transmission.ini' => 'password=HACKED', 'Installers/Windows/NSIS/Vaultcast.nsi' => '; installer',
    ], $extra);
}
/** Fake GitHub: tree API, raw.githubusercontent.com and the blobs API. */
$GH = ['repo' => [], 'raw' => [], 'blob' => [], 'treeStatus' => 200, 'calls' => [], 'treeExtra' => []];
function serve(array $repo, array $rawOverride = [], array $blobOverride = []): void {
    global $GH; $GH['repo'] = $repo; $GH['raw'] = $rawOverride; $GH['blob'] = $blobOverride; $GH['treeStatus'] = 200; $GH['treeExtra'] = [];
}
Updater::$http = function (string $url, array $headers) {
    global $GH; $GH['calls'][] = $url;
    $treeUrl = Updater::API . '/git/trees/main?recursive=1';
    if ($url === $treeUrl) {
        if ($GH['treeStatus'] !== 200) return ['status' => $GH['treeStatus'], 'body' => null, 'headers' => ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string)(time() + 1800)]];
        $t = [];
        foreach ($GH['repo'] as $p => $c) $t[] = ['path' => $p, 'mode' => '100644', 'type' => 'blob', 'sha' => Updater::blobSha($c), 'size' => strlen($c)];
        foreach ($GH['treeExtra'] as $e) $t[] = $e;
        $body = json_encode(['sha' => sha1(serialize($t)), 'tree' => $t, 'truncated' => false]);
        $etag = '"' . md5($body) . '"';
        if (in_array('If-None-Match: ' . $etag, $headers, true)) return ['status' => 304, 'body' => null, 'headers' => ['etag' => $etag]];
        return ['status' => 200, 'body' => $body, 'headers' => ['etag' => $etag]];
    }
    $rawPrefix = Updater::RAW . '/main/';
    if (strpos($url, $rawPrefix) === 0) {
        $p = rawurldecode(substr($url, strlen($rawPrefix)));
        if (array_key_exists($p, $GH['raw'])) return $GH['raw'][$p];
        return $GH['repo'][$p] ?? null;
    }
    if (preg_match('#/git/blobs/([0-9a-f]{40})$#', $url, $m)) {
        if (array_key_exists($m[1], $GH['blob'])) return $GH['blob'][$m[1]];
        foreach ($GH['repo'] as $c) if (Updater::blobSha($c) === $m[1]) return $c;
        return null;
    }
    return null;
};

// ---------------------------------------------------------------- units
check(Updater::blobSha('') === 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', 'blob SHA of empty file matches git');
check(Updater::blobSha("hello\n") === 'ce013625030ba8dba906f756967f9e9ca394464a', 'blob SHA of "hello\\n" matches git hash-object');
check(Updater::appPath('Application/src/X.php') === 'src/X.php' && Updater::appPath('LICENSE') === 'LICENSE', 'repo paths map to app paths');
check(Updater::appPath('README.md') === null && Updater::appPath('Installers/Windows/NSIS/Vaultcast.nsi') === null, 'non-app repo files are ignored');
foreach (['data/config.php', 'config.php', 'netplay/node_modules/x/index.js', 'extra/tool.php', 'data/runtime/transmission.ini', '../index.php', 'src/../../x', 'C:/x', '.user.ini', 'src/.git/config', 'src/a.php.rk-new-abc123'] as $bad) check(!Updater::allowed($bad), "allowlist rejects $bad");
foreach (['index.php', 'VERSION', 'src/Services/A.php', 'assets/js/app.js', 'netplay/server.js', 'LICENSE', 'router.php'] as $good) check(Updater::allowed($good), "allowlist accepts $good");
check(Updater::compare('v1.10.0', '1.9.9') > 0 && Updater::compare('1.0.0', 'v1.0.0') === 0, 'numeric version comparison');
$f = "$base/crlf.txt"; file_put_contents($f, "a\r\nb\r\n");
check(Updater::localMatches($f, Updater::blobSha("a\nb\n")) && !Updater::localMatches($f, Updater::blobSha("a\nc\n")), 'CRLF copy of an LF blob matches, different content does not');

// ---------------------------------------------------------------- check: diff
$root = "$base/app"; Updater::$root = $root;
installed($root); serve(repo());
$c = Updater::check();
$l = $c['latest'] ?? [];
check($c['ok'] && $c['current'] === '1.0.0' && $l['version'] === '1.1.0', 'check reports the remote VERSION: ' . json_encode($c['error'] ?? $l['version'] ?? null));
check($l['changed'] === ['VERSION', 'assets/css/app.css', 'index.php'], 'changed files listed: ' . json_encode($l['changed']));
check($l['added'] === ['LICENSE', 'NOTICE', 'assets/img/logo.png', 'src/Services/New.php'], 'new files listed: ' . json_encode($l['added']));
check($l['removed'] === ['src/Services/Old.php'], 'removed-upstream files listed (not deploy/, not node_modules): ' . json_encode($l['removed']));
check(!in_array('src/Services/Crlf.php', $l['changed'], true), 'CRLF checkout of an unchanged file is not flagged');
check($c['update_available'] && $l['count'] === 8, 'update available with 8 files');
$apiCalls = count(array_filter($GH['calls'], function ($u) { return strpos($u, Updater::API) === 0; }));
check($apiCalls === 1, "check uses one GitHub API call ($apiCalls)");
$GH['calls'] = []; Updater::check();
check(is_file("$root/data/updates/tree-cache.json"), 'tree cached with its ETag (repeat checks get a free 304)');

// ---------------------------------------------------------------- blob SHA mismatch: rejected, nothing changed
installed($root); $before = tree($root);
serve(repo(), ['Application/index.php' => "<?php // TAMPERED\n"], [Updater::blobSha("<?php // new index\n") => "<?php // TAMPERED\n"]);
$r = Updater::apply();
check(!$r['ok'] && strpos($r['error'], 'Integrity check failed for index.php') !== false, 'blob SHA mismatch is refused: ' . ($r['error'] ?? ''));
check(tree($root) === $before, 'mismatch changed nothing on disk');
check(!is_file(Updater::maintenanceFile()), 'maintenance mode never switched on for a refused update');
// raw CDN stale, blobs API right: accepted (the fallback is verified too)
installed($root);
serve(repo(), ['Application/index.php' => "<?php // old index (stale CDN)\n"]);
$r = Updater::apply();
check($r['ok'] && file_get_contents("$root/index.php") === "<?php // new index\n", 'stale raw copy falls back to the verified blobs API');
// missing file
installed($root); $before = tree($root);
serve(repo(), ['Application/src/Services/New.php' => null], [Updater::blobSha("<?php // added\n") => null]);
$r = Updater::apply();
check(!$r['ok'] && strpos($r['error'], 'Could not download src/Services/New.php') !== false && tree($root) === $before, 'failed download refused, nothing changed');

// ---------------------------------------------------------------- rollback mid-swap
installed($root); $before = tree($root); serve(repo());
$n = 0;
Updater::$beforeReplace = function (string $rel) use (&$n) { if (++$n === 4) throw new RuntimeException("simulated failure at $rel"); };
$r = Updater::apply();
Updater::$beforeReplace = null;
check(!$r['ok'] && !empty($r['rolled_back']) && strpos($r['error'], 'simulated failure') !== false, 'failure mid-swap is rolled back: ' . ($r['error'] ?? ''));
check(tree($root) === $before, 'rollback restores every file byte for byte (and deletes created ones)');
check(!is_file(Updater::maintenanceFile()), 'maintenance mode off after rollback');
// failure during removal
installed($root); $before = tree($root);
Updater::$beforeReplace = function (string $rel) { if ($rel === 'src/Services/Old.php') throw new RuntimeException('cannot delete'); };
$r = Updater::apply(); Updater::$beforeReplace = null;
check(!$r['ok'] && !empty($r['rolled_back']) && tree($root) === $before, 'failure while pruning rolls back everything');

// interrupted (crash) journal is recovered before the next update
installed($root);
put("$root/index.php", "<?php // half-written new index\n");
$bk = "$root/data/updates/backup-1.0.0-crash";
put("$bk/files/index.php", "<?php // old index\n");
file_put_contents("$bk/journal.json", json_encode(['from' => '1.0.0', 'to' => '1.1.0', 'state' => 'swapping', 'replaced' => ['index.php'], 'created' => ['src/Services/New.php'], 'removed' => []]));
put("$root/src/Services/New.php", "<?php // created before the crash\n");
check(Updater::recoverInterrupted() === '1.0.0' && file_get_contents("$root/index.php") === "<?php // old index\n" && !is_file("$root/src/Services/New.php"), 'interrupted swap rolled back from its journal');
Updater::rmTree("$root/data/updates");

// ---------------------------------------------------------------- happy path + allowlist
installed($root); serve(repo());
$r = Updater::apply();
check($r['ok'] && $r['from'] === '1.0.0' && $r['to'] === '1.1.0', 'update applied: ' . json_encode($r));
check($r['replaced'] === 3 && $r['created'] === 4 && $r['removed'] === 1, 'counts: 3 replaced, 4 created, 1 removed');
$repo = repo();
foreach (['VERSION', 'index.php', 'assets/css/app.css', 'src/Services/New.php', 'assets/img/logo.png'] as $rel) check(file_get_contents("$root/$rel") === $repo["Application/$rel"], "$rel written exactly as in the repository");
check(file_get_contents("$root/LICENSE") === "PolyForm\n" && file_get_contents("$root/NOTICE") === "Notice\n", 'repo-root LICENSE/NOTICE installed at the app root');
check(!is_file("$root/src/Services/Old.php"), 'file removed upstream deleted');
check(file_get_contents("$root/src/Services/Crlf.php") === "<?php\r\n// same content, CRLF checkout\r\n", 'unchanged CRLF file left alone');
check(file_get_contents("$root/deploy/nginx.conf") === "# admin's own copy: never pruned\n", 'deploy/ copies never pruned');
check(file_get_contents("$root/data/config.php") === "<?php return ['database' => 'SECRET'];", 'data/config.php untouched');
check(file_get_contents("$root/data/runtime/transmission.ini") === "[transmission]\npassword=keep" && is_file("$root/data/runtime/php/php.ini"), 'Transmission config and Windows runtimes untouched');
check(file_get_contents("$root/netplay/node_modules/socket.io/index.js") === "// installed dependency\n", 'node_modules untouched');
check(!is_file("$root/config.php") && !is_dir("$root/extra") && !is_file("$root/README.md") && !is_dir("$root/Installers"), 'off-allowlist repo files never written');
check(is_file("$root/data/posters/a.jpg"), 'media/uploads untouched');
check(!is_file("$root/data/cache/migrations-v1-app1.0.0.done"), 'migration stamps cleared so the version-keyed startup migrations run');
check(!is_file(Updater::maintenanceFile()), 'maintenance mode off afterwards');
$bks = glob("$root/data/updates/backup-1.0.0-*", GLOB_ONLYDIR);
check($bks && file_get_contents($bks[0] . '/files/index.php') === "<?php // old index\n" && json_decode(file_get_contents($bks[0] . '/journal.json'), true)['state'] === 'done', 'replaced files backed up with a done journal');
$c = Updater::check();
check($c['ok'] && !$c['update_available'] && $c['current'] === '1.1.0', 'after the update everything matches main');
$r = Updater::apply();
check(!$r['ok'] && strpos($r['error'], 'up to date') !== false, 'a second update has nothing to do');

// same VERSION but changed files (pushed to main without a bump) still updates
serve(repo(['Application/VERSION' => "1.1.0\n", 'Application/index.php' => "<?php // hotfix\n"]));
$r = Updater::apply();
check($r['ok'] && $r['replaced'] === 1 && file_get_contents("$root/index.php") === "<?php // hotfix\n", 'file changes without a VERSION bump are applied');

// main moved since the admin checked
$c = Updater::check(); serve(repo(['Application/index.php' => "<?php // newer\n"]));
$r = Updater::apply(null, $c['latest']['tree']);
check(!$r['ok'] && ($r['code'] ?? '') === 'changed', 'apply refuses when main moved since the check');

// ---------------------------------------------------------------- hostile tree entries
installed($root); serve(repo());
$GH['treeExtra'] = [
    ['path' => 'Application/src/../../evil.php', 'mode' => '100644', 'type' => 'blob', 'sha' => Updater::blobSha('x'), 'size' => 1],
    ['path' => 'Application/src/link.php', 'mode' => '120000', 'type' => 'blob', 'sha' => Updater::blobSha('/etc/passwd'), 'size' => 11],
    ['path' => 'Application/src/sub', 'mode' => '160000', 'type' => 'commit', 'sha' => sha1('x'), 'size' => 0],
];
$c = Updater::check();
$all = array_merge($c['latest']['changed'], $c['latest']['added']);
check(!array_filter($all, function ($p) { return strpos($p, '..') !== false || $p === 'src/link.php' || $p === 'src/sub'; }), 'traversal, symlink and submodule entries ignored');

// ---------------------------------------------------------------- rate limit / private repo
Updater::rmTree("$root/data/updates");
$GH['treeStatus'] = 403;
$c = Updater::check();
check(!$c['ok'] && !empty($c['rate_limited']) && strpos($c['error'], '60 an hour') !== false && strpos($c['error'], 'update_token') !== false, 'rate limit explained with reset time and token hint');
$GH['treeStatus'] = 200; Updater::check(); $GH['treeStatus'] = 403;
$c = Updater::check();
check($c['ok'] && strpos((string)$c['note'], 'Showing the result from') !== false, 'rate-limited check falls back to the stored tree');
$GH['treeStatus'] = 404; Updater::rmTree("$root/data/updates");
$c = Updater::check();
check(!$c['ok'] && strpos($c['error'], 'private') !== false, '404 explains the private repository and update_token');
$GH['treeStatus'] = 200;

// ---------------------------------------------------------------- unwritable app folder (Linux script installs)
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    installed($root); serve(repo()); $before = tree($root);
    chmod("$root/src/Services", 0555);
    $r = Updater::apply();
    chmod("$root/src/Services", 0775);
    check(!$r['ok'] && strpos($r['error'], 'cannot write') !== false && tree($root) === $before, 'read-only app folder refused before anything changes');
}

// ---------------------------------------------------------------- tests/ only where the install has it
installed($root); serve(repo(['Application/tests/x_test.php' => "<?php // test\n"]));
$c = Updater::check();
check(!in_array('tests/x_test.php', $c['latest']['added'], true), 'tests/ not added to an install without tests/ (Linux script layout)');
mkdir("$root/tests");
$c = Updater::check();
check(in_array('tests/x_test.php', $c['latest']['added'], true), 'tests/ updated where the install has it (Windows layout)');

Updater::rmTree($base);
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
