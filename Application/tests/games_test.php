<?php
/** Games: core detection for zipped ROMs, ROM delivery fallback, game routes wiring. php Application/tests/games_test.php */
$tmp = sys_get_temp_dir() . '/vc-games-test-' . getmypid();
@mkdir($tmp, 0775, true);
require __DIR__ . '/../src/Services/Catalogue.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

/** Minimal stored (uncompressed) zip, pure PHP. */
function make_zip(string $file, array $entries): void {
    $data = ''; $cd = ''; $off = 0;
    foreach ($entries as $name => $body) {
        $crc = crc32($body); $len = strlen($body); $nl = strlen($name);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, $nl, 0) . $name . $body;
        $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len, $nl, 0, 0, 0, 0, 0, $off) . $name;
        $data .= $local; $off += strlen($local);
    }
    $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($cd), $off, 0);
    file_put_contents($file, $data . $cd . $eocd);
}
make_zip($tmp . '/sonic.zip', ['Sonic The Hedgehog 2 (World).md' => str_repeat("\0", 64)]);
make_zip($tmp . '/mario.zip', ['readme.txt' => 'x', 'Super Mario World.sfc' => 'y']);
make_zip($tmp . '/dir.zip', ['folder/' => '', 'folder/game.gba' => 'z']);
file_put_contents($tmp . '/broken.zip', 'not a zip');

check(Catalogue::zipEntryNames($tmp . '/sonic.zip') === ['Sonic The Hedgehog 2 (World).md'], 'zip central directory read without extensions');
check(Catalogue::guessEmuCore('zip', 'Sega/sonic.zip', $tmp . '/sonic.zip') === 'segaMD', 'zipped Mega Drive ROM in a generic folder -> segaMD (was nes)');
check(Catalogue::guessEmuCore('zip', 'Misc/mario.zip', $tmp . '/mario.zip') === 'snes', 'skips non-ROM files inside the zip');
check(Catalogue::guessEmuCore('zip', 'x/dir.zip', $tmp . '/dir.zip') === 'gba', 'ROM inside a folder in the zip');
check(Catalogue::guessEmuCore('zip', 'Genesis/mario.zip', $tmp . '/mario.zip') === 'segaMD', 'console folder name still wins');
check(Catalogue::guessEmuCore('zip', 'x/broken.zip', $tmp . '/broken.zip') === 'nes' && Catalogue::guessEmuCore('zip', 'x/a.zip') === 'nes', 'unreadable zip keeps the old default');
check(Catalogue::guessEmuCore('md', 'x/a.md') === 'segaMD' && Catalogue::guessEmuCore('gen', 'x/a.gen') === 'segaMD', 'Mega Drive extensions -> segaMD (genesis_plus_gx)');

$stream = file_get_contents(__DIR__ . '/../src/Services/Stream.php');
check(strpos($stream, 'Catalogue::guessEmuCore($extForCore, $relForCore, $path)') !== false, 'play re-detects the core from the zip, so existing libraries are fixed without a rescan');
check(preg_match('/function playRom.*?emitAccelOrSend/s', $stream) === 1 && strpos($stream, "\$direct = !empty(\$_GET['direct'])") !== false, 'ROMs use the same nginx/PHP delivery, and honour direct=1');

$js = file_get_contents(__DIR__ . '/../assets/js/app.js');
check(strpos($js, 'probeRom(gameUrl)') !== false && strpos($js, 'R.withDirect(gameUrl)') !== false, 'emulator checks the ROM download and falls back to PHP before starting');
check(strpos($js, 'state.gamePath = item.game_path') !== false && strpos($js, "'Copy link to this game'") !== false, 'game URL and share button while playing');
check(strpos($js, "navUrl({ live: lp.ch.id })") !== false, 'changing live channel updates the URL');
$cat = file_get_contents(__DIR__ . '/../src/Services/Catalogue.php');
check(strpos($cat, "'game_path' =>") !== false, 'items carry their game path');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
