const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const listeners = {};
const scope = 'https://example.test/vault/';
const context = { URL, Response, self: { location: { href: scope + 'sw.js?v=test' }, registration: { scope },
  addEventListener: (name, fn) => { listeners[name] = fn; } },
  caches: { open: async () => ({ match: async () => null, put() {} }) }, fetch: async () => new Response('public script') };
vm.createContext(context); vm.runInContext(fs.readFileSync(__dirname + '/../sw.js', 'utf8'), context);
function intercepted(relative) {
  let response;
  listeners.fetch({ request: { method: 'GET', url: scope + relative }, respondWith(promise) { response = promise; } });
  return response;
}
(async () => {
  assert(await intercepted('index.php?asset=assets/js/app.js&v=123'));
  assert(await intercepted('assets/css/app.css?v=123'));
  for (const relative of ['index.php','api/home','api/items/abc/poster','index.php?asset=data/config.php','index.php?asset=assets/js/../../data/config.php']) {
    assert.strictEqual(intercepted(relative), undefined, 'private/invalid response cached: ' + relative);
  }
  console.log('all passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
