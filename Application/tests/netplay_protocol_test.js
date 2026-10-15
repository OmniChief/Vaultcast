/* Client and relay must speak the same lockstep protocol: node Application/tests/netplay_protocol_test.js */
var fs = require('fs');
var path = require('path');
var app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
var relay = fs.readFileSync(path.join(__dirname, '../netplay/server.js'), 'utf8');
var fails = 0;
function check(c, m){ console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) fails++; }
function versions(src){ var out = {}; (src.match(/vaultcast-lockstep-\d+/g) || []).forEach(function(v){ out[v] = 1; }); return Object.keys(out); }
var a = versions(app), r = versions(relay);
check(a.length === 1 && r.length === 1, 'one protocol version each in client and relay (' + a.join(',') + ' / ' + r.join(',') + ')');
check(a[0] === r[0] && a[0] === 'vaultcast-lockstep-4', 'client and relay both speak vaultcast-lockstep-4');
check(app.indexOf('frame-gate-3') === -1, 'no stale frame-gate-3 diagnostics');
check(/np\._streamSyncFailure = syncFailed;/.test(app) && app.indexOf('np._streamSyncFailure = syncFailed;') < app.indexOf('lobbyEnsurePostMainLoop(emu); } catch'),
  'sync-failure hook is set before the frame callback is installed');
check(/np\.getUserIndex = function/.test(app), 'np.getUserIndex is defined for the controller snapshot');
check(/input_state: held/.test(app) && /data\.input_state\.some\(/.test(app), 'host sends and guest validates the controller snapshot');
check(/value\.connected_input\[1\] >= 0 && value\.connected_input\[1\] <= 23/.test(relay), 'relay rejects out-of-range button indexes from guests');
// Exercise the relay filter expression on sample inputs.
var m = relay.match(/const inputs = data\['sync-control'\]\.slice\(0, 64\)\.filter\(value =>([\s\S]*?)\)\.map/);
var filt = m && new Function('value', 'return (' + m[1] + ');');
check(!!filt && filt({ connected_input: [0, 5, 1] }) && !filt({ connected_input: [0, 24, 1] }) && !filt({ connected_input: [0, -1, 1] }) && !filt({ connected_input: [0, 2, NaN] }) && !filt({ connected_input: [0, 1.5, 1] }),
  'relay filter accepts 0..23 integers with finite values only');
if (fails) { console.log(fails + ' failure(s)'); process.exit(1); }
console.log('all passed');
