/* Watch Live cache invalidation after IPTV writes: node Application/tests/live_cache_test.js */
var fs = require('fs');
var src = fs.readFileSync(__dirname + '/../assets/js/app.js', 'utf8');
var a = src.indexOf('/* live-cache:start'), b = src.indexOf('/* live-cache:end */');
var fails = 0;
function check(c, m){ console.log((c ? 'ok   ' : 'FAIL ') + m); if (!c) fails++; }
check(a > 0 && b > a, 'live cache block found');

var favs = ['bbc'];
var calls = { guide: 0, iptv: 0 };
var stored = {};
var ctx = {
  live: { guide:null, iptv:null, loadedAt:0, rev:0, loadedRev:-1, busyRev:-1, busy:null },
  state: { view: 'settings' },
  LIVE_API: '/api/live/',
  localStorage: { setItem: function(k, v){ stored[k] = v; } },
  liveApi: function(action){
    calls[action]++;
    var snapshot = favs.slice();
    return Promise.resolve(action === 'guide'
      ? { channels: snapshot.map(function(id){ return { id:'iptv:' + id, kind:'iptv' }; }) }
      : { ok:true, favourites: snapshot.map(function(id){ return { id:id }; }) });
  },
  fetch: function(url, opts){
    var body = JSON.parse(opts.body);
    if (/iptv_add$/.test(url)) favs.push(body.id);
    return Promise.resolve({ ok:true, status:200, json: function(){ return Promise.resolve({ ok:true }); } });
  },
  paintLive: function(){ ctx.painted++; }, painted: 0
};
var run = new Function('ctx', 'with (ctx) {' + src.slice(a, b) + '; return { ensureLiveData: ensureLiveData, invalidateLiveData: invalidateLiveData, liveApiPost: liveApiPost }; }');
var L = run(ctx);
function ids(){ return ctx.live.guide.channels.map(function(c){ return c.id; }).join(','); }

(async function(){
  await L.ensureLiveData(false);
  check(ids() === 'iptv:bbc' && calls.guide === 1, 'first load');
  await L.ensureLiveData(false);
  check(calls.guide === 1, 'second load within 90 s served from memory');

  await L.liveApiPost('iptv_add', { id:'news' });
  check(!!stored['vaultcast-live-rev'], 'other tabs notified through localStorage');
  await L.ensureLiveData(false);
  check(ids() === 'iptv:bbc,iptv:news' && calls.guide === 2, 'new favourite shows on the next Watch Live visit (no hard refresh)');

  var before = calls.guide;
  await L.liveApiPost('iptv_probe', { id:'news' });
  await L.ensureLiveData(false);
  check(calls.guide === before, 'stream checks do not invalidate the cache');

  // write lands while a read is in flight -> the stale read is replaced
  var p = L.ensureLiveData(true);
  await L.liveApiPost('iptv_add', { id:'sport' });
  await p;
  await L.ensureLiveData(false);
  check(ids() === 'iptv:bbc,iptv:news,iptv:sport', 'in-flight stale read is redone after a write');

  // another tab added one: storage event -> invalidate(true); Watch Live open repaints
  ctx.state.view = 'live'; ctx.painted = 0; favs.push('film');
  L.invalidateLiveData(true);
  await new Promise(function(r){ setTimeout(r, 10); });
  check(ids().indexOf('iptv:film') >= 0 && ctx.painted === 1, 'open Watch Live repaints after a change in another tab');

  // a failed write does not count
  ctx.fetch = function(){ return Promise.resolve({ ok:false, status:400, json: function(){ return Promise.resolve({ ok:false, error:'x' }); } }); };
  var rev = ctx.live.rev;
  await L.liveApiPost('iptv_add', { id:'bad' });
  check(ctx.live.rev === rev, 'failed writes keep the cache');

  var sw = fs.readFileSync(__dirname + '/../sw.js', 'utf8');
  check(/assets\//.test(sw) && !/api\//.test(sw), 'service worker never caches API responses');
  var liveRoutes = fs.readFileSync(__dirname + '/../src/Http/routes/live.php', 'utf8');
  check(/header\('Cache-Control: no-store'\);\s*require_once STREAM_ROOT \. '\/src\/Live\/store\.php'/.test(liveRoutes), 'Live API answers are no-store');

  console.log(fails ? fails + ' FAILED' : 'all passed');
  process.exit(fails ? 1 : 0);
})().catch(function(e){ console.error(e); process.exit(1); });
