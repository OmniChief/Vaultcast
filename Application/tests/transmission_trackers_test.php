<?php
/** Trackers survive the trip feed → magnet → Transmission. php Application/tests/transmission_trackers_test.php */
class Store {
    public static $s = array();
    public static $kv = array();
    public static function settings() { return self::$s; }
    public static function get($k, $d = null) { return self::$kv[$k] ?? $d; }
    public static function set($k, $v) { self::$kv[$k] = $v; }
}
class FeedCatalogue { public static function archiveTorrent($u) { return ''; } }
require_once __DIR__ . '/../src/Services/TorrentTrackers.php';
require_once __DIR__ . '/../src/Services/Acquisition.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
function call($name, ...$a) { $m = new ReflectionMethod('Acquisition', $name); $m->setAccessible(true); return $m->invoke(null, ...$a); }

$H = str_repeat('ab', 20);
$T1 = 'udp://tracker.example.local:1337/announce';
$T2 = 'https://tr2.example.local/announce?k=1&x=2';
$mag = 'magnet:?xt=urn:btih:' . $H . '&dn=Show&tr=' . rawurlencode($T1) . '&tr=' . rawurlencode($T2);

// Pure helpers
check(TorrentTrackers::trackersOf($mag) === array($T1, $T2), 'all repeated tr= kept (parse_str would keep only the last)');
check(TorrentTrackers::extractMagnet('<a href="' . htmlspecialchars($mag) . '">get</a>') === $mag, 'HTML magnet: &amp; decoded, cut at quote');
check(TorrentTrackers::extractMagnet(htmlspecialchars(htmlspecialchars($mag))) === $mag, 'double-escaped magnet decoded');
check(TorrentTrackers::withTrackers($mag, array($T1, 'udp://new.example.local:80')) === $mag . '&tr=' . rawurlencode('udp://new.example.local:80'), 'withTrackers adds only missing');
check(TorrentTrackers::clean("udp://a.example.local:1\njavascript:x, ,udp://a.example.local:1") === array('udp://a.example.local:1'), 'tracker list cleaned');
$bare = 'magnet:?xt=urn:btih:' . $H;
check(TorrentTrackers::chooseSource($bare, 'https://feed.example.local/f/1.torrent') === 'https://feed.example.local/f/1.torrent', 'hash-only magnet → .torrent (announce list) preferred');
check(TorrentTrackers::chooseSource($mag, 'https://feed.example.local/f/1.torrent') === $mag, 'magnet with trackers kept');
check(TorrentTrackers::chooseSource($bare, 'https://feed.example.local/view/1') === $bare, 'no .torrent → magnet');

// RSS parsing
$rss = '<?xml version="1.0"?><rss xmlns:nyaa="https://nyaa.si/xmlns/nyaa"><channel>'
  . '<item><title>A</title><link>https://feed.example.local/view/1</link><description><![CDATA[<p><a href="' . htmlspecialchars($mag) . '">Magnet</a></p>]]></description></item>'
  . '<item><title>B</title><link>https://feed.example.local/dl/2.torrent</link><nyaa:infoHash>' . $H . '</nyaa:infoHash></item>'
  . '<item><title>C</title><link>https://feed.example.local/view/3</link><enclosure url="' . htmlspecialchars($mag) . '" type="application/x-bittorrent"/></item>'
  . '</channel></rss>';
$FT = 'udp://feedtracker.example.local:6969/announce';
$items = call('parseRss', $rss, 'https://feed.example.local/rss', array($FT));
check(TorrentTrackers::trackersOf($items[0]['magnet']) === array($T1, $T2, $FT), 'CDATA HTML magnet keeps source trackers (+feed tracker)');
check(TorrentTrackers::trackersOf($items[1]['magnet']) === array($FT), 'hash-only item gets feed trackers');
check($items[1]['enclosure'] === 'https://feed.example.local/dl/2.torrent', '.torrent link retained');
check(TorrentTrackers::trackersOf($items[2]['magnet']) === array($T1, $T2, $FT) && $items[2]['enclosure'] === '', 'magnet enclosure parsed as magnet');
Store::$s = array('acquisition' => array('default_trackers' => array($T1, 'udp://def.example.local:1')));
$items = call('parseRss', $rss, 'https://feed.example.local/rss', array());
check(TorrentTrackers::trackersOf($items[1]['magnet']) === array($T1, 'udp://def.example.local:1'), 'defaults added to hash-only, no duplicates');
$j = call('parseApibayJson', json_encode(array(array('name' => 'X', 'info_hash' => strtoupper($H), 'id' => 1))), 'https://api.example.local/q', array($FT));
check(TorrentTrackers::trackersOf($j[0]['magnet']) === array($FT, $T1, 'udp://def.example.local:1'), 'JSON hash results get feed + default trackers');

// Transmission RPC against a local fake
$dir = sys_get_temp_dir() . '/vc_tr_' . getmypid();
@mkdir($dir);
file_put_contents("$dir/r.php", '<?php $log = __DIR__ . "/log"; if (empty($_SERVER["HTTP_X_TRANSMISSION_SESSION_ID"])) { header("X-Transmission-Session-Id: s1", true, 409); exit; }'
  . ' $b = json_decode(file_get_contents("php://input"), true); if (!$b) { echo "{}"; exit; } file_put_contents($log, json_encode($b) . "\n", FILE_APPEND);'
  . ' $a = array(); if ($b["method"] === "torrent-add") $a = array("torrent-duplicate" => array("id" => 7, "hashString" => "' . $H . '"));'
  . ' if ($b["method"] === "torrent-get") $a = array("torrents" => array(array("id" => 7, "trackers" => array(array("announce" => "' . $T1 . '")))));'
  . ' header("Content-Type: application/json"); echo json_encode(array("result" => "success", "arguments" => $a));');
$port = 18000 + getmypid() % 1000;
$proc = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/r.php"), array(array('pipe', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pp);
usleep(400000);
Store::$s['acquisition']['download_path'] = '/downloads';
$res = Acquisition::addToTransmission($mag, array('url' => "http://127.0.0.1:$port/transmission/rpc"));
$log = array_map(function ($l) { return json_decode($l, true); }, array_filter(explode("\n", (string)@file_get_contents("$dir/log"))));
check($res['ok'] && $log[0]['method'] === 'torrent-add' && $log[0]['arguments']['filename'] === $mag, 'torrent-add sends the magnet with every tr= intact');
$set = null; foreach ($log as $l) if ($l['method'] === 'torrent-set') $set = $l['arguments'];
check($set !== null && $set['ids'] === array(7) && $set['trackerAdd'] === array($T2, 'udp://def.example.local:1'), 'duplicate torrent: trackerAdd only missing trackers');
check($res['trackers_added'] === 2, 'trackers_added reported');
@unlink("$dir/log");
Store::$kv = array();
Store::$s['acquisition']['transmission'] = array('enabled' => true, 'url' => "http://127.0.0.1:$port/transmission/rpc");
Acquisition::enqueue(array('title' => 'B', 'magnet' => $bare, 'enclosure' => 'https://feed.example.local/dl/2.torrent'));
$log = array_map(function ($l) { return json_decode($l, true); }, array_filter(explode("\n", (string)@file_get_contents("$dir/log"))));
check($log[0]['arguments']['filename'] === 'https://feed.example.local/dl/2.torrent', 'enqueue: tracker-less magnet replaced by .torrent URL');
proc_terminate($proc); proc_close($proc);
array_map('unlink', glob("$dir/*")); @rmdir($dir);

echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);
