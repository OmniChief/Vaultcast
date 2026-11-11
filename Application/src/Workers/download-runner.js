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
  const metadataPath = partial + '.json';
  const previous = asset ? null : await fs.promises.stat(partial).catch(() => null);
  const offset = previous?.size || 0;
  if (offset >= ticket.limit) return false;
  let previousMetadata;
  if (offset) {
    try { previousMetadata = JSON.parse(await fs.promises.readFile(metadataPath, 'utf8')); } catch (error) {}
    ticket.range = 'bytes=' + offset + '-';
    ticket.ifRange = previousMetadata?.validator;
  }
  const upstream = await providerResponse(ticket, url, 'GET', signal);
  let monitor;
  let writing = false;
  try {
    const length = Number(upstream.headers['content-length'] || 0);
    if (upstream.statusCode !== (offset ? 206 : 200) || upstream.headers['set-cookie'] || length + offset > ticket.limit
      || (upstream.headers['content-encoding'] && upstream.headers['content-encoding'] !== 'identity')) return false;
    let total = length;
    if (offset) {
      const range = /^bytes (\d+)-(\d+)\/(\d+)$/.exec(upstream.headers['content-range'] || '');
      if (!range || Number(range[1]) !== offset || Number(range[2]) + 1 !== Number(range[3])
        || Number(range[3]) > ticket.limit) return false;
      total = Number(range[3]);
    }
    const checkStorage = async () => {
      if (!asset && await fs.promises.stat(cancellation).catch(() => null)) throw new Error('cancelled');
      const space = await fs.promises.statfs(directory);
      if (space.bavail * space.bsize < 100 * 1024 * 1024) throw new Error('Storage limit reached');
    };
    await checkStorage();
    if (asset) {
      // The PHP lock covers the single outstanding asset request and its previous attempt.
      await fs.promises.unlink(partial).catch(() => {});
    }
    writing = true;
    let written = 0;
    let sampleAt = Date.now();
    let sampleBytes = offset;
    async function progress(initial = false) {
      if (asset || (!initial && !target.endsWith(sep + 'source'))) return;
      const now = Date.now();
      const current = offset + written;
      const metadata = {
        validator: upstream.headers.etag || upstream.headers['last-modified'] || previousMetadata?.validator || '',
        total_bytes: total, downloaded_bytes: current, http_status: upstream.statusCode,
        speed_bytes_per_second: Math.round(Math.max(0, current - sampleBytes) * 1000 / Math.max(1, now - sampleAt)),
        progress_sample_at: now / 1000, progress_at: Math.floor(now / 1000),
      };
      sampleAt = now;
      sampleBytes = current;
      await fs.promises.writeFile(metadataPath + '.tmp', JSON.stringify(metadata), { mode: 0o600 });
      await fs.promises.rename(metadataPath + '.tmp', metadataPath);
    }
    await progress(true);
    const cap = new Transform({
      transform(chunk, encoding, done) {
        written += chunk.length;
        if (offset + written > ticket.limit) done(new Error('Download exceeds size limit'));
        else done(null, chunk);
      },
    });
    let checking = false;
    let lastCheck = Promise.resolve();
    let reason;
    monitor = setInterval(() => {
      if (checking) return;
      checking = true;
      lastCheck = checkStorage().then(progress).catch(error => {
        reason = error;
        upstream.destroy(error);
      }).finally(() => { checking = false; });
    }, 2000);
    try {
      await pipeline(upstream, cap, fs.createWriteStream(partial, { flags: offset ? 'a' : 'w', mode: 0o600 }), { signal });
    } catch (error) {
      clearInterval(monitor);
      await lastCheck;
      throw reason || error;
    }
    clearInterval(monitor);
    await lastCheck;
    if (reason) throw reason;
    if (!written || (length && written !== length) || (total && written + offset !== total)) return false;
    await checkStorage();
    await progress();
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
  const timeout = ticket.type === 'player' ? setTimeout(() => controller.abort(), 120000) : null;
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
