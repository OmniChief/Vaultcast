/* Netplay frame-sync helpers: node Application/tests/netplay_sync_test.js */
var S = require('../assets/js/netplay_sync.js');
var fails = 0;
function check(c, m){ console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) fails++; }
var players = { host:{}, g1:{} };
check(S.hostMayRun(5, players, 'host', { g1: 2 }), 'host keeps running with a 3-frame ack gap (old limit stalled here)');
check(S.hostMayRun(11, players, 'host', { g1: 2 }), 'host runs within the window');
check(!S.hostMayRun(12, players, 'host', { g1: 2 }), 'host waits once a guest is a full window behind');
check(S.hostMayRun(100, { host:{} }, 'host', {}), 'solo host is never gated');
var data = { 3: [{ frame:3, connected_input:[0,4,1] }] };
var b = S.hostBucketsToSend(data, 1, 6);
check(b.map(function(v){ return v.frame; }).join(',') === '2,3,4,5', 'skipped frames are all sent, in order');
check(b[1].connected_input && b[1].connected_input[1] === 4, 'buffered input kept in its frame');
check(S.hostBucketsToSend({}, -1, 1).length === 1 && S.hostBucketsToSend({}, -1, 1)[0].frame === 0, 'frame zero is sent');
check(S.hostBucketsToSend({}, 5, 6).length === 0, 'nothing resent');
check(S.pruneBefore(true, 10, 3) === 2, 'host keeps unsent buckets');
check(S.pruneBefore(false, 10, -1) === 8, 'guest keeps the last two buckets');
if (fails) { console.log(fails + ' failure(s)'); process.exit(1); }
console.log('all passed');
