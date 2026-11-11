'use strict';

const fs = require('fs');
const { dirname, join, resolve, sep } = require('path');
const { Transform } = require('stream');
const { pipeline } = require('stream/promises');
const { providerResponse, takeTicket } = require('./media-relay');
const data = resolve(__dirname, '../../data');
let active = 0;

async function download(ticket, signal) {
  const asset = ticket.type === 'player';
  if (!asset && ticket.type !== 'stalker') throw new Error('Unknown download type');
  const allowed = join(data, asset ? 'players' : 'stalker-offline') + sep;
  const target = resolve(ticket.target);
  if (!target.startsWith(allowed)) throw new Error('Invalid download path');
  const url = new URL(ticket.url);
  if (!['http:', 'https:'].includes(url.protocol) || url.hostname !== ticket.host
    || url.username || url.password) throw new Error('Invalid upstream address');

  const directory = dirname(target);
  const cancellation = join(directory, 'cancel');
  const partial = target + (asset ? '.node' : '.part');
  const upstream = await providerResponse(ticket, url, 'GET', signal);
  let monitor;
  let writing = false;
  try {
    const length = Number(upstream.headers['content-length'] || 0);
    if (upstream.statusCode !== 200 || upstream.headers['set-cookie'] || length > ticket.limit
      || (upstream.headers['content-encoding'] && upstream.headers['content-encoding'] !== 'identity')) return false;
    const checkStorage = async () => {
      if (!asset && await fs.promises.stat(cancellation).catch(() => null)) throw new Error('cancelled');
      const space = await fs.promises.statfs(directory);
      if (space.bavail * space.bsize < 100 * 1024 * 1024) throw new Error('Storage limit reached');
    };
    await checkStorage();
    if (asset) {
      // The PHP lock covers the single outstanding asset request and its previous attempt.
      await fs.promises.unlink(partial).catch(() => {});
    } else if (await fs.promises.stat(partial).catch(() => null)) {
      return false;
    } else {
      const metadata = { validator: upstream.headers.etag || upstream.headers['last-modified'] || '',
        total_bytes: length, downloaded_bytes: 0 };
      await fs.promises.writeFile(partial + '.json', JSON.stringify(metadata), { mode: 0o600 });
    }
    writing = true;
    let written = 0;
    const cap = new Transform({
      transform(chunk, encoding, done) {
        written += chunk.length;
        if (written > ticket.limit) done(new Error('Download exceeds size limit'));
        else done(null, chunk);
      },
    });
    let checking = false;
    let reason;
    monitor = setInterval(() => {
      if (checking) return;
      checking = true;
      checkStorage().catch(error => {
        reason = error;
        upstream.destroy(error);
      }).finally(() => { checking = false; });
    }, 2000);
    try {
      await pipeline(upstream, cap, fs.createWriteStream(partial, { flags: 'wx', mode: 0o600 }), { signal });
    } catch (error) {
      throw reason || error;
    }
    if (!written || (length && written !== length)) return false;
    await checkStorage();
    await fs.promises.rename(partial, target);
    return true;
  } finally {
    clearInterval(monitor);
    upstream.destroy();
    if (asset && writing) await fs.promises.unlink(partial).catch(() => {});
  }
}

module.exports = async function serveDownload(request, response) {
  if (request.method !== 'GET') return response.sendStatus(405);
  const ticket = await takeTicket(request.params.token, 'download').catch(() => null);
  if (!ticket) return response.json({ ok: false });
  if (response.destroyed) return;
  if (active >= 2) return response.json({ ok: false });
  active++;
  response.set({ 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
  response.flushHeaders();
  const controller = new AbortController();
  response.once('close', () => controller.abort());
  const timeout = setTimeout(() => controller.abort(), ticket.type === 'player' ? 120000 : 240000);
  const heartbeat = setInterval(() => response.write(' '), 15000);
  try {
    const ok = await download(ticket, controller.signal);
    if (!response.destroyed) response.end(JSON.stringify({ ok }));
  } catch (error) {
    if (!response.destroyed) response.end(JSON.stringify({ ok: false, cancelled: error.message === 'cancelled' }));
  } finally {
    clearTimeout(timeout);
    clearInterval(heartbeat);
    active--;
  }
};

module.exports.active = () => active > 0;
