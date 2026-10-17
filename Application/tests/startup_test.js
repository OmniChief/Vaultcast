/* Warm home must render without an extra API request, without crossing accounts. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const src = fs.readFileSync(__dirname + '/../assets/js/app.js', 'utf8');
const body = src.slice(src.indexOf('  function loadHome(){'), src.indexOf('  function renderPlayerAssets(){'));
function fixture(initial, user) {
  const ctx = {
    window: { __STREAM_STARTUP__: initial }, state: { user, view:'home' }, calls:0, renders:0, errors:0,
    api: function(){ this.calls++; return Promise.resolve({ok:true, sections:[]}); },
    applyRoute: () => Promise.resolve(),
    render: function(){ this.renders++; }, replaceNav: () => {}, _navSilent:false,
    h: (tag, attrs, kids) => ({tag, attrs, kids}), shell: x => x,
    $: () => ({innerHTML:'', appendChild: () => {ctx.errors++;}})
  };
  ctx.api = ctx.api.bind(ctx); ctx.render = ctx.render.bind(ctx);
  ctx.load = new Function('ctx', 'with(ctx) {' + body + '; return loadHome; }')(ctx);
  return ctx;
}
(async () => {
  const home = {ok:true, sections:[{id:'movies'}]};
  let c = fixture({me:{user:{id:'a'}}, home}, {id:'a'});
  await c.load();
  assert.equal(c.calls,0); assert.equal(c.state.home,home); assert.equal(c.renders,1);
  await c.load(); assert.equal(c.calls,1, 'bootstrap consumed only once');
  c = fixture({me:{user:{id:'a'}}, home}, {id:'b'});
  await c.load(); assert.equal(c.calls,1, 'account switch discards bootstrap');
  c = fixture({me:{user:null}, home}, {id:'b'});
  await c.load(); assert.equal(c.calls,1, 'guest home not used after login');
  c = fixture(null, {id:'a'});
  await c.load(); assert.equal(c.calls,1, 'cold request fetches home');
  c.api = () => Promise.reject(new Error('offline'));
  await c.load(); assert.equal(c.errors,1, 'network failure offers retry');
  c.api = () => Promise.resolve({ok:false, error:'server failure'});
  await c.load(); assert.equal(c.errors,2, 'server failure offers retry');
  c.api = () => Promise.resolve({ok:false, _status:403});
  await c.load(); assert.equal(c.errors,2, 'access gate stays visible');
  console.log('startup: all passed');
})().catch(e => {console.error(e); process.exitCode=1;});
