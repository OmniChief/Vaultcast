<?php
/** Library folder access for the web server (LibraryAccess from the installer PR, optional). php Application/tests/library_access_test.php */
require __DIR__ . '/../src/Paths.php';
require __DIR__ . '/../src/Services/Migrations.php';
use Vaultcast\Paths;
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$tmp = sys_get_temp_dir() . '/vc-acl-test-' . getmypid();
@mkdir($tmp . '/Films', 0775, true); @mkdir($tmp . '/TV', 0775, true);

$calls = [];
$okGrant = function ($p) use (&$calls) { $calls[] = $p; return ['ok' => true]; };
$r = Paths::grantWebServerRead($tmp . '/Films', $okGrant);
check($r['ok'] && !$r['skipped'] && $calls === [$tmp . '/Films'], 'grant called with the checked path; success passes through');

$bad = function ($p) { return ['ok' => false, 'code' => 'library_acl_failed', 'error' => 'Windows refused to change the folder permissions: Access is denied.']; };
$r = Paths::grantWebServerRead('C:/Users/Public/Films', $bad);
check(!$r['ok'] && $r['code'] === 'library_acl_failed', 'library_acl_failed is reported as a failure');
check(strpos($r['message'], 'nginx') !== false && strpos($r['message'], 'could not be given read access') !== false && strpos($r['message'], 'Access is denied.') !== false && strpos($r['message'], 'C:/Users/Public/Films') !== false, 'message says nginx could not be given read access, names the folder and includes the reason');

$throws = function ($p) { throw new RuntimeException('icacls missing'); };
$r = Paths::grantWebServerRead($tmp . '/Films', $throws);
check(!$r['ok'] && strpos($r['message'], 'icacls missing') !== false, 'an exception from the grant becomes a reasoned failure');

// Without the installer PR's LibraryAccess class the call is a no-op.
$hasClass = is_file(__DIR__ . '/../src/Services/LibraryAccess.php');
$r = Paths::grantWebServerRead($tmp . '/Films');
if ($hasClass) check($r['ok'] && PHP_OS_FAMILY !== 'Windows', 'LibraryAccess present: no-op success off Windows');
else check($r['ok'] && $r['skipped'], 'LibraryAccess absent: skipped, never blocks');

// Existing-library pass: counts, skips missing folders, never throws.
$calls = [];
$out = Migrations::grantLibraryAccess([['path' => $tmp . '/Films'], ['path' => $tmp . '/TV'], ['path' => $tmp . '/Gone'], ['path' => ''], 'junk'], $okGrant);
check($out === ['granted' => 2, 'skipped' => 3, 'failed' => 0] && $calls === [$tmp . '/Films', $tmp . '/TV'], 'pass grants existing library folders and skips missing/empty ones');
$out = Migrations::grantLibraryAccess([['path' => $tmp . '/Films']], $bad);
check($out['failed'] === 1, 'failures are counted and logged, not thrown');
$out = Migrations::grantLibraryAccess([['path' => $tmp . '/Films']], $throws);
check($out['failed'] === 1, 'exceptions inside the pass do not escape');
$calls = [];
Migrations::grantLibraryAccess([['path' => $tmp . '/Films']], $okGrant); Migrations::grantLibraryAccess([['path' => $tmp . '/Films']], $okGrant);
check(count($calls) === 2, 're-running the pass is harmless (idempotent grant)');
check(Migrations::VERSION >= 3, 'migration version bumped so existing installs run the pass once');

// Wiring: setup and Settings call it after the path checks pass.
$inst = file_get_contents(__DIR__ . '/../src/Installer.php');
check(strpos($inst, 'Paths::grantWebServerRead($real)') > strpos($inst, 'Paths::check('), 'setup grants access after the path check');
$set = file_get_contents(__DIR__ . '/../src/Http/routes/admin-settings.php');
check(strpos($set, 'MediaAccel::grant($check[\'path\'])') > strpos($set, 'Paths::check($raw)'), 'Settings grants access for new/changed paths after the check');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
