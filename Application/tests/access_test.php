<?php
/** Users & time-limited access: php Application/tests/access_test.php */
date_default_timezone_set('Europe/London');
require __DIR__ . '/../src/Services/Access.php';
$fails = 0;
function check($c, $m) { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }
$now = strtotime('2026-10-14 12:00:00');

// migration + states
check(Access::state(['role' => 'user'], $now)['state'] === 'unlimited', 'existing user without access record migrates to unlimited');
check(Access::state(['role' => 'admin', 'access' => ['mode' => 'until', 'until' => $now - 10]], $now)['active'], 'admins are never locked out, even with an expired record');
check(Access::state(['role' => 'user', 'access' => ['mode' => 'pending']], $now)['state'] === 'pending', 'new user is awaiting access');
check(Access::state(['role' => 'user', 'access' => ['mode' => 'until', 'until' => $now - 1]], $now)['state'] === 'expired', 'past expiry is expired');
$st = Access::state(['role' => 'user', 'access' => ['mode' => 'until', 'until' => $now + 3600]], $now);
check($st['active'] && $st['remaining'] === 3600, 'future expiry is active with remaining time');

// periods
check(Access::forNewUser(null, null, 'a', $now)['mode'] === 'pending', 'new user defaults to awaiting access');
check(date('Y-m-d', Access::apply(null, '1w', null, 'set', 'a', $now)['until']) === '2026-10-21', '1 week');
check(date('Y-m-d', Access::apply(null, '1m', null, 'set', 'a', $now)['until']) === '2026-11-14', '1 month');
check(date('Y-m-d', Access::apply(null, '3m', null, 'set', 'a', $now)['until']) === '2027-01-14', '3 months');
check(date('Y-m-d', Access::apply(null, '1y', null, 'set', 'a', $now)['until']) === '2027-10-14', '1 year');
check(Access::apply(null, 'unlimited', null, 'set', 'a', $now)['mode'] === 'unlimited', 'unlimited');
check(date('Y-m-d H:i', Access::apply(null, 'custom', '2027-02-03', 'set', 'a', $now)['until']) === '2027-02-03 23:59', 'custom date runs to the end of that day');
$cur = ['mode' => 'until', 'until' => strtotime('2026-11-01 12:00:00')];
check(date('Y-m-d', Access::apply($cur, '1m', null, 'extend', 'a', $now)['until']) === '2026-12-01', 'extend adds to the current expiry');
check(date('Y-m-d', Access::apply(['mode' => 'until', 'until' => $now - 99], '1w', null, 'extend', 'a', $now)['until']) === '2026-10-21', 'extending an expired user counts from now');
check(!Access::state(['role' => 'user', 'access' => Access::apply(null, 'revoke', null, 'set', 'a', $now)], $now)['active'], 'revoke ends access now');
foreach ([['custom', '2020-01-01'], ['custom', 'nope'], ['2d', null]] as $bad) {
    $threw = false; try { Access::apply(null, $bad[0], $bad[1], 'set', 'a', $now); } catch (InvalidArgumentException $e) { $threw = true; }
    check($threw, 'rejects ' . $bad[0] . ' ' . var_export($bad[1], true));
}

// delete rules
$admin = ['id' => 'a1', 'role' => 'admin']; $admin2 = ['id' => 'a2', 'role' => 'admin']; $bob = ['id' => 'u1', 'role' => 'user'];
check(Access::deleteBlocker($admin, $admin, [$admin, $admin2]) !== null, 'admin cannot delete themselves');
check(Access::deleteBlocker($admin, $bob, [$admin, $bob]) === null, 'admin can delete a user');
check(Access::deleteBlocker($admin2, $admin, [$admin, $admin2]) === null, 'admin can delete another admin when one remains');
check(Access::deleteBlocker(['id' => 'x', 'role' => 'admin'], $admin, [$admin, $bob]) !== null, 'last admin cannot be deleted');

// server gate
$users = ['a1' => $admin, 'u1' => $bob + ['access' => ['mode' => 'until', 'until' => $now - 5]], 'u2' => ['id' => 'u2', 'role' => 'user'], 'u3' => ['id' => 'u3', 'role' => 'user', 'access' => ['mode' => 'pending']]];
$find = function ($id) use ($users) { return $users[$id] ?? null; };
$noTok = function () { return null; };
$g = function ($path, $uid, $tok = null, $req = true) use ($find, $noTok, $now) { return Access::gate($path, $uid ? ['user_id' => $uid] : null, $find, $req, $tok ?: $noTok, $now); };
check($g('/home', 'u1')[0] === 403 && $g('/home', 'u1')[1]['code'] === 'access_denied', 'expired user is refused on the API');
check($g('/live/iptv', 'u1')[0] === 403, 'expired user is refused on live/IPTV');
check($g('/home', 'u3')[0] === 403, 'awaiting user is refused');
check($g('/me', 'u1') === null && $g('/logout', 'u1') === null, 'gated user can still reach /me and /logout');
check($g('/admin/users', 'a1') === null && $g('/home', 'u2') === null, 'admin and unlimited users pass');
check($g('/home', 'gone')[0] === 401, 'deleted account session is refused');
$tokU1 = function () { return ['user_id' => 'u1']; };
$tokU2 = function () { return ['user_id' => 'u2']; };
check($g('/stream/play', null, $tokU1)[0] === 403, 'expired user stream token is refused (no cookie needed)');
check($g('/stream/play', null, $tokU2) === null, 'active user stream token plays');
check($g('/stream/play', null, function () { return ['user_id' => 'public']; })[0] === 403, 'guest token refused when login is required');
check($g('/home', null)[0] === 401, 'signed-out API is refused when login is required');
check($g('/home', null, null, false) === null, 'guest browsing still works when enabled');
check($g('/live/m3u', null) === null, 'external IPTV players keep cookie-less live access');


// regression: Relay/TV Studio channel files (api/live/file -> stream/play) use tokens owned by 'live'.
// The gate used to look 'live' up as a user and refuse with "This account no longer exists", so the
// web player errored on every programme and skipped through the schedule.
$users = ['u1' => ['id' => 'u1', 'role' => 'user'], 'ux' => ['id' => 'ux', 'role' => 'user', 'access' => ['mode' => 'until', 'until' => $now - 5]]];
$find = function ($id) use ($users) { return $users[$id] ?? null; };
$liveTok = function () { return ['user_id' => 'live', 'media_kind' => 'video']; };
check(Access::gate('/stream/play', null, $find, true, $liveTok, $now) === null, 'live channel token plays signed out (external M3U players)');
check(Access::gate('/stream/play', ['user_id' => 'u1'], $find, true, $liveTok, $now) === null, 'live channel token plays for a signed-in viewer (web player)');
check((Access::gate('/stream/play', ['user_id' => 'ux'], $find, true, $liveTok, $now)[0] ?? 0) === 403, 'live channel token still refused for an expired signed-in account');
check((Access::gate('/stream/play', null, $find, true, function () { return ['user_id' => 'gone']; }, $now)[1]['code'] ?? '') === 'access_denied', 'tokens of deleted accounts are still refused');
$lib = file_get_contents(__DIR__ . '/../src/Live/library.php');
check(strpos($lib, "Stream::createToken('live',") !== false, 'live file route issues live-owned tokens (matches the gate rule)');
echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
