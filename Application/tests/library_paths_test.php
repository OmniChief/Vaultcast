<?php
/** Library folder paths: normalisation, reasoned checks and folder picker. php Application/tests/library_paths_test.php */
require __DIR__ . '/../src/Paths.php';
use Vaultcast\Paths;
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$env = ['USERPROFILE' => 'C:\\Users\\Public', 'HOME' => '/home/media', 'MEDIA' => '/srv/media'];
$n = function ($p) use ($env) { return Paths::normalize($p, $env); };

check($n('C:\\Users\\Public\\Films') === 'C:/Users/Public/Films', 'backslashes -> forward slashes');
check($n('C:/Users/Public/Films/') === 'C:/Users/Public/Films', 'trailing slash removed');
check($n('C:\\Users\\Public\\Films\\\\') === 'C:/Users/Public/Films', 'trailing backslashes removed');
check($n('  "C:\\Users\\Public\\Films"  ') === 'C:/Users/Public/Films', 'pasted quotes and whitespace (Copy as path)');
check($n("'/srv/media/Films'") === '/srv/media/Films', 'single quotes');
check($n("\u{201C}C:\\Users\\Public\\Films\u{201D}") === 'C:/Users/Public/Films', 'smart quotes');
check($n('%USERPROFILE%\\Downloads\\Films') === 'C:/Users/Public/Downloads/Films', '%USERPROFILE% expansion');
check($n('%userprofile%\\Films') === 'C:/Users/Public/Films', 'env var names are case-insensitive');
check($n('%NOPE%\\Films') === '%NOPE%/Films', 'unknown %VAR% left as typed');
check($n('~/Films') === '/home/media/Films', '~ expansion');
check($n('$MEDIA/Films') === '/srv/media/Films' && $n('${MEDIA}/Films') === '/srv/media/Films', '$VAR and ${VAR} expansion');
check($n('c:') === 'C:/' && $n('c:\\') === 'C:/' && $n('d:\\films') === 'D:/films', 'bare drive and drive letter case');
check($n('\\\\nas\\media\\Films\\') === '//nas/media/Films', 'UNC path kept');
check($n('//nas//media///Films') === '//nas/media/Films', 'duplicate separators collapsed, UNC prefix kept');
check($n('/srv/My Films/') === '/srv/My Films', 'spaces preserved');
check($n('file:///C:/Users/Public/My%20Films') === 'C:/Users/Public/My Films', 'file:// URL (Windows)');
check($n('file:///srv/media') === '/srv/media', 'file:// URL (Linux)');
check($n('/') === '/' && $n('') === '' && $n('   ') === '', 'root and empty');

check(Paths::allowedByOpenBasedir('/srv/media/Films', '') === true, 'no open_basedir allows everything');
check(Paths::allowedByOpenBasedir('/srv/media/Films', '/srv/media:/tmp', 'Linux') === true, 'inside open_basedir');
check(Paths::allowedByOpenBasedir('/srv/mediax', '/srv/media', 'Linux') === false, 'prefix lookalike rejected');
check(Paths::allowedByOpenBasedir('c:/users/public/films', 'C:\\Users\\Public;D:\\', 'Windows') === true, 'Windows open_basedir is case-insensitive');
check(Paths::allowedByOpenBasedir('E:/Films', 'C:\\Users\\Public;D:\\', 'Windows') === false, 'outside Windows open_basedir');

check(Paths::parent('C:/Users/Public') === 'C:/Users' && Paths::parent('C:/Users') === 'C:/' && Paths::parent('C:/') === null, 'parent of drive paths');
check(Paths::parent('/srv/media') === '/srv' && Paths::parent('/') === null && Paths::parent('//nas/media') === null, 'parent of POSIX/UNC paths');

$tmp = sys_get_temp_dir() . '/vc-paths-test-' . getmypid();
@mkdir($tmp . '/My Films/Sub B', 0775, true); @mkdir($tmp . '/My Films/sub a', 0775, true);
file_put_contents($tmp . '/My Films/movie.mkv', 'x');
$ok = Paths::check('"' . $tmp . '/My Films/"');
check($ok['ok'] && $ok['reason'] === 'ok' && $ok['path'] === Paths::real($tmp . '/My Films'), 'readable folder with spaces, quotes and trailing slash accepted');
$miss = Paths::check($tmp . '/Nope');
check(!$miss['ok'] && $miss['reason'] === 'missing' && strpos($miss['message'], 'does not exist') !== false, 'missing folder says it does not exist');
$file = Paths::check($tmp . '/My Films/movie.mkv');
check(!$file['ok'] && $file['reason'] === 'not_dir', 'a file is reported as not a folder');
check(Paths::check('  ')['reason'] === 'empty', 'empty path');
if (PHP_OS_FAMILY !== 'Windows') check(Paths::check('C:\\Users\\Public\\Films')['reason'] === 'missing', 'Windows path on a non-Windows server explained');
$isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : false;
if (PHP_OS_FAMILY !== 'Windows' && !$isRoot) {
    @mkdir($tmp . '/locked/inner', 0775, true); chmod($tmp . '/locked/inner', 0000); chmod($tmp . '/locked', 0000);
    $d = Paths::check($tmp . '/locked/inner');
    check(!$d['ok'] && $d['reason'] === 'denied' && strpos($d['message'], Paths::serviceAccount()) !== false, 'unreadable parent: access denied naming the service account (' . Paths::serviceAccount() . ')');
    chmod($tmp . '/locked', 0775);
    $d = Paths::check($tmp . '/locked/inner');
    check(!$d['ok'] && $d['reason'] === 'denied' && strpos($d['message'], 'exists') !== false, 'unreadable folder: exists but access denied');
    chmod($tmp . '/locked/inner', 0775);
} else echo "skip permission checks (running as root or on Windows)\n";
check(Paths::serviceAccount() !== '', 'service account detected');

// open_basedir is enforced by PHP; run a child PHP with it set to check the reason/message.
$php = PHP_BINARY;
$code = 'require ' . var_export(__DIR__ . '/../src/Paths.php', true) . '; echo json_encode(Vaultcast\\Paths::check(' . var_export($tmp . '/My Films', true) . '));';
$out = shell_exec(escapeshellarg($php) . ' -n -d open_basedir=' . escapeshellarg(dirname(__DIR__) . PATH_SEPARATOR . '/nonexistent-vc') . ' -r ' . escapeshellarg($code) . ' 2>&1');
$j = json_decode((string)$out, true);
check(is_array($j) && $j['reason'] === 'open_basedir' && strpos($j['message'], 'open_basedir') !== false, 'open_basedir block reported as such');

$b = Paths::browse($tmp . '/My Films');
check($b['ok'] && array_column($b['folders'], 'name') === ['sub a', 'Sub B'], 'browse lists subfolders only, natural case-insensitive order');
check($b['parent'] === Paths::real($tmp), 'browse reports parent');
$r = Paths::browse('');
check($r['ok'] && count($r['folders']) >= 1, 'browse with no path lists roots/drives');
check(!Paths::browse($tmp . '/Nope')['ok'], 'browse of a missing folder fails with a reason');

// Admin-only: folder routes live in the /admin/ handler group, which calls Auth::requireAdmin().
$routes = file_get_contents(__DIR__ . '/../src/Http/routes.php');
$settings = file_get_contents(__DIR__ . '/../src/Http/routes/admin-settings.php');
check(strpos($settings, "'/admin/folders'") !== false && preg_match("#strpos\(\\\$path, '/admin/'\) === 0\) \{\s*Auth::requireAdmin\(\);#", $routes) === 1, 'folder API is under the admin-gated route group');
$installer = file_get_contents(__DIR__ . '/../src/Views/installer.php');
check(strpos($installer, "!\$completed && isset(\$_GET['setup_folders'])") !== false && strpos($installer, 'HTTP_X_SETUP_TOKEN') !== false, 'setup picker only before install and needs the setup session token');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
