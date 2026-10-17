/* A thumbnail prepared after first browse must replace an old original in Cache API. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(__dirname + '/../assets/js/app.js', 'utf8');
const code = source.slice(source.indexOf('  function runPosterJob('), source.indexOf('  /* clear load flags'));
async function fixture(cachedAt) {
  const attrs = { 'data-poster-id': 'abc' };
  const el = { getAttribute: key => attrs[key], setAttribute: (key, value) => { attrs[key] = value; } };
  let fetches = 0, stored;
  const headers = cachedAt === null ? {} : { 'X-Vaultcast-Cached-At': String(cachedAt) };
  const context = { Promise, Date, Number, setTimeout, clearTimeout, Response,
    URL: { createObjectURL: () => 'blob:test' }, _posterMem: {}, _posterRequests: {},
    POSTER_CACHE: 'test', POSTER_CACHE_MAX_AGE: 3600000,
    posterInDom: () => true, supportsWebP: () => true,
    posterCacheRequest: () => 'key', posterServerUrl: () => '/art', setPosterBg() {}, markPosterFail() { throw new Error('unexpected failure'); },
    caches: { open: async () => ({ match: async () => new Response('x'.repeat(100), { headers }), put: async (key, value) => { stored = value; } }) },
    fetch: async () => { fetches++; return new Response('x'.repeat(100)); }
  };
  vm.createContext(context); vm.runInContext(code, context);
  await context.runPosterJob({ el, itemId: 'abc', kind: 'item', variant: 'thumb' });
  for (let i = 0; i < 8; i++) await Promise.resolve();
  assert.strictEqual(attrs['data-poster-loaded'], '1');
  return { fetches, stored };
}
(async () => {
  assert.strictEqual((await fixture(Date.now())).fetches, 0, 'fresh artwork stays local');
  for (const stamp of [null, Date.now() - 3600001, Date.now() + 100000]) {
    const result = await fixture(stamp);
    assert.strictEqual(result.fetches, 1, 'missing, expired and invalid timestamps refresh artwork');
    assert(Number(result.stored.headers.get('X-Vaultcast-Cached-At')) > 0);
  }
  console.log('all passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
