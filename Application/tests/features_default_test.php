<?php
/** Live channels & IPTV defaults on unless an admin explicitly turned it off: php Application/tests/features_default_test.php */
require __DIR__ . '/../src/Services/Store.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$n = function ($s) { return Store::normalizeFeatures($s)['features']; };
check($n([])['live'] === true, 'fresh settings: live is on');
check($n(['features' => ['live' => false, 'downloads' => false, 'server_tools' => false]])['live'] === true, 'old installer default (live=false, never chosen) is turned on');
check($n(['features' => ['downloads' => true]])['live'] === true && $n(['features' => ['downloads' => true]])['downloads'] === true, 'install that never set live gets it on; other flags kept');
check($n(['features' => ['live' => false], 'features_explicit' => ['live' => true]])['live'] === false, 'admin who turned it off keeps it off');
check($n(['features' => ['live' => true], 'features_explicit' => ['live' => true]])['live'] === true, 'admin who turned it on keeps it on');
check($n([])['downloads'] === false && $n([])['server_tools'] === false, 'downloads and server tools stay off by default');
$src = file_get_contents(__DIR__ . '/../src/Installer.php');
check(strpos($src, "'features' => ['live' => true") !== false, 'installer writes live on for fresh installs');
$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, "'Server administration tools'") !== false && strpos($js, "'server administration tools'") === false, 'feature label renamed to "Server administration tools"');
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
