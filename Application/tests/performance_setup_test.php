<?php
require __DIR__ . '/../src/Services/PerformanceSetup.php';
class Maintenance {
    public static $calls = 0;
    public static $fail = false;
    public static function start($task, $actor) { self::$calls++; if (self::$fail) throw new RuntimeException('worker unavailable'); return ['ok'=>true,'job'=>['id'=>'test-job']]; }
}
$dir = sys_get_temp_dir() . '/vc-setup-' . getmypid();
@mkdir($dir,0775,true);
file_put_contents($dir . '/performance-v1.json',json_encode(['job'=>'old-job']));
PerformanceSetup::schedule($dir); PerformanceSetup::schedule($dir);
unlink($dir . '/performance-v1.json');
if (Maintenance::$calls !== 1) throw new RuntimeException('Update queued duplicate metadata jobs');
$state = json_decode(file_get_contents($dir . '/performance-v3.json'), true);
if ($state['job'] !== 'test-job') throw new RuntimeException('Queue result missing');
unlink($dir . '/performance-v3.json'); Maintenance::$fail = true;
PerformanceSetup::schedule($dir); PerformanceSetup::schedule($dir);
if (Maintenance::$calls !== 2) throw new RuntimeException('Failed worker launch retried on every request');
$stamp = $dir . '/performance-v3.json';
file_put_contents($stamp, json_encode(['attempted_at'=>time()-301])); Maintenance::$fail = false;
PerformanceSetup::schedule($dir);
if (Maintenance::$calls !== 3) throw new RuntimeException('Failed launch was never retried');
unlink($dir . '/performance-v3.json'); unlink($dir . '/performance-setup.lock'); rmdir($dir);
echo "all passed\n";
