'use strict';

const fs = require('fs').promises;
const { isAbsolute, join, relative, sep } = require('path');
const { pipeline } = require('stream/promises');
const { promisify } = require('util');
const gzip = promisify(require('zlib').gzip);

const root = join(__dirname, '../..');
const assets = join(root, 'assets');
const maintenance = join(root, 'data/runtime/maintenance.json');
const compressed = new Map();
const inFlight = new Map();
const cacheLimit = 4 * 1024 * 1024;
let cacheSize = 0;

function gzipAsset(key, file) {
  if (compressed.has(key)) return compressed.get(key);
  if (inFlight.has(key)) return inFlight.get(key);
  if (inFlight.size >= 2) return null;

  const operation = file.readFile().then(body => gzip(body, { level: 5 })).then(buffer => {
    if (buffer.length > cacheLimit) return buffer;
    while (cacheSize + buffer.length > cacheLimit) {
      const oldest = compressed.keys().next().value;
      cacheSize -= compressed.get(oldest).length;
      compressed.delete(oldest);
    }
    compressed.set(key, buffer);
    cacheSize += buffer.length;
    return buffer;
  }).finally(() => inFlight.delete(key));
  inFlight.set(key, operation);
  return operation;
}

module.exports = async function webAsset(request, response) {
  const name = request.params[0];
  response.set('Cache-Control', 'no-store');
  if (!/^(?:js\/[A-Za-z0-9_-]+\.js|css\/[A-Za-z0-9_-]+\.css)$/.test(name)) return response.sendStatus(404);

  let file;
  try {
    const update = await fs.readFile(maintenance, 'utf8').then(JSON.parse).catch(() => null);
    if (update && Number(update.since) > Date.now() / 1000 - 900) {
      return response.set('Retry-After', '30').sendStatus(503);
    }

    const target = join(assets, name);
    const [directory, resolved, entry] = await Promise.all([
      fs.realpath(assets), fs.realpath(target), fs.lstat(target),
    ]);
    const inside = relative(directory, resolved);
    if (!entry.isFile() || !inside || inside === '..' || inside.startsWith('..' + sep) || isAbsolute(inside)) {
      return response.sendStatus(404);
    }
    file = await fs.open(resolved, 'r');
    const stat = await file.stat();
    if (!stat.isFile()) return response.sendStatus(404);

    const key = name + ':' + stat.ino + ':' + stat.size + ':' + stat.mtimeMs + ':' + stat.ctimeMs;
    const pack = stat.size >= 1024 && stat.size <= cacheLimit && request.acceptsEncodings('gzip') ? gzipAsset(key, file) : null;
    const packed = pack ? await pack : null;
    const versioned = typeof request.query.v === 'string' && /^[a-f0-9]{16}$/.test(request.query.v);
    response.set({
      'Content-Type': name.endsWith('.css') ? 'text/css; charset=utf-8' : 'application/javascript; charset=utf-8',
      'X-Content-Type-Options': 'nosniff',
      'Cache-Control': versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
      'ETag': '"' + key + (packed ? '-gzip' : '') + '"',
      'Vary': 'Accept-Encoding',
    });
    if (packed) response.set('Content-Encoding', 'gzip');
    if (request.fresh) return response.status(304).end();
    response.set('Content-Length', String(packed ? packed.length : stat.size));
    if (request.method === 'HEAD') return response.end();
    if (packed) return response.end(packed);
    await pipeline(file.createReadStream({ autoClose: false }), response);
  } catch (error) {
    if (response.destroyed) return;
    if (response.headersSent) response.destroy();
    else {
      response.removeHeader('Content-Length');
      response.removeHeader('Content-Encoding');
      response.set('Cache-Control', 'no-store').sendStatus(error.code === 'ENOENT' ? 404 : 503);
    }
  } finally {
    if (file) await file.close().catch(() => {});
  }
};
