/* Real HTTP/gzip/cache headers, no nginx reconfiguration and no database/bootstrap. */
const fs = require('fs');
const os = require('os');
const path = require('path');
const net = require('net');
const http = require('http');
const zlib = require('zlib');
const assert = require('assert');
const { spawn } = require('child_process');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function request(url, options = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request(url, { ...options, agent: false }, response => {
      const chunks = [];
      response.on('data', chunk => chunks.push(chunk));
      response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks) }));
      response.on('error', reject);
    });
    req.on('error', reject); req.end();
  });
}
(async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vc-asset-http-'));
  const app = path.join(__dirname, '..');
  const files = ['index.php','src/Services/AssetDelivery.php','src/Services/RequestTiming.php','assets/js/app.js','assets/css/app.css'];
  for (const file of files) { fs.mkdirSync(path.dirname(path.join(root, file)), { recursive: true }); fs.copyFileSync(path.join(app, file), path.join(root, file)); }
  fs.writeFileSync(path.join(root, 'text.php'), '<?php require __DIR__ . "/src/Services/AssetDelivery.php"; header("Content-Type: application/json"); echo AssetDelivery::text(json_encode(["text"=>str_repeat("Vaultcast",1000)]));');
  const server = net.createServer();
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  const php = spawn(process.env.VAULTCAST_TEST_PHP || 'php', ['-S', '127.0.0.1:' + port, '-t', root], { stdio: 'ignore', windowsHide: true });
  let launchError;
  php.on('error', error => { launchError = error; });
  const url = 'http://127.0.0.1:' + port + '/index.php?asset=assets/js/app.js&v=0123456789abcdef';
  try {
    let response;
    for (let i = 0; i < 100; i++) {
      if (launchError) throw launchError;
      try { response = await request(url, { headers: { 'Accept-Encoding': 'gzip' } }); break; } catch { await delay(30); }
    }
    assert(response, 'PHP test server did not start');
    assert.strictEqual(response.status, 200);
    assert.strictEqual(response.headers['content-encoding'], 'gzip');
    assert(response.headers['cache-control'].includes('immutable'));
    const raw = fs.readFileSync(path.join(app, 'assets/js/app.js'), 'utf8');
    assert.strictEqual(zlib.gunzipSync(response.body).toString(), raw);
    const compressedBytes = Number(response.headers['content-length']);
    assert.strictEqual(compressedBytes, response.body.length);
    assert(compressedBytes < Buffer.byteLength(raw) / 2);
    const etag = response.headers.etag;
    const cached = await request(url, { headers: { 'Accept-Encoding': 'gzip', 'If-None-Match': etag } });
    assert.strictEqual(cached.status, 304);
    const plain = await request(url, { headers: { 'Accept-Encoding': 'gzip;q=0' } });
    assert.strictEqual(plain.headers['content-encoding'], undefined);
    assert.strictEqual(plain.body.toString(), raw);
    const head = await request(url, { method: 'HEAD', headers: { 'Accept-Encoding': 'gzip' } });
    assert.strictEqual(head.headers['content-length'], String(compressedBytes));
    assert.strictEqual(head.body.length, 0);
    assert.strictEqual((await request('http://127.0.0.1:' + port + '/index.php?asset=data/config.php')).status, 404);
    assert.strictEqual((await request(url, { method: 'POST' })).status, 405);
    const json = await request('http://127.0.0.1:' + port + '/text.php', { headers: { 'Accept-Encoding': 'gzip' } });
    assert.strictEqual(json.headers['content-encoding'], 'gzip');
    assert.strictEqual(JSON.parse(zlib.gunzipSync(json.body).toString()).text, 'Vaultcast'.repeat(1000));
    fs.mkdirSync(path.join(root, 'data/runtime'), { recursive: true });
    fs.writeFileSync(path.join(root, 'data/runtime/maintenance.json'), JSON.stringify({ since: Math.floor(Date.now()/1000) }));
    assert.strictEqual((await request(url)).status, 503, 'maintenance gate runs before new service includes');
    console.log('app.js: ' + Buffer.byteLength(raw) + ' -> ' + compressedBytes + ' bytes; HTTP gzip/identity/HEAD/304/maintenance: all passed');
  } finally {
    if (php.pid) { const closed = new Promise(resolve => php.once('exit', resolve)); php.kill(); await closed; }
    fs.rmSync(root, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
