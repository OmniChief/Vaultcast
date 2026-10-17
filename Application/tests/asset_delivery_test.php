<?php
require __DIR__ . '/../src/Services/AssetDelivery.php';
$tmp = sys_get_temp_dir() . '/vc-assets-' . getmypid();
@mkdir($tmp . '/assets/js', 0775, true); @mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/assets/js/app.js', 'public'); file_put_contents($tmp . '/data/config.php', 'private');
function check($yes, $message) { if (!$yes) throw new RuntimeException($message); }
check(AssetDelivery::file($tmp, 'assets/js/app.js') !== null, 'public JS missing');
foreach (['../data/config.php','assets/js/../../data/config.php','assets/js/app.js/extra','data/config.php','assets/js/a.php','assets/js/app.js?x=1','assets\\js\\app.js'] as $bad) check(AssetDelivery::file($tmp, $bad) === null, 'Unsafe path accepted: ' . $bad);
check(AssetDelivery::acceptsGzip('br, gzip;q=0.5'), 'gzip negotiation');
check(!AssetDelivery::acceptsGzip('gzip;q=0, *;q=1'), 'explicit gzip opt-out');
check(!AssetDelivery::acceptsGzip('br, identity'), 'unsupported encoding');
check(AssetDelivery::acceptsGzip('*;q=0.5'), 'wildcard negotiation');
$body = str_repeat('const example = "Vaultcast";\n', 1000);
$first = AssetDelivery::compress($body, 'gzip', $tmp . '/cache');
check($first['gzip'] && gzdecode($first['body']) === $body && strlen($first['body']) < strlen($body), 'gzip round trip');
$cache = $tmp . '/cache/' . hash('sha256', $body) . '.gz';
check(is_file($cache), 'compressed bytes not cached');
check(AssetDelivery::compress($body, 'identity', $tmp . '/cache')['body'] === $body, 'identity gets gzip bytes');
file_put_contents($cache, 'broken');
check(gzdecode(AssetDelivery::compress($body, 'gzip', $tmp . '/cache')['body']) === $body, 'corrupt cache not repaired');
check(!AssetDelivery::compress('small', 'gzip')['gzip'], 'small response compressed');
unlink($cache); rmdir($tmp . '/cache'); unlink($tmp . '/assets/js/app.js'); unlink($tmp . '/data/config.php');
rmdir($tmp . '/assets/js'); rmdir($tmp . '/assets'); rmdir($tmp . '/data'); rmdir($tmp);
echo "all passed\n";
